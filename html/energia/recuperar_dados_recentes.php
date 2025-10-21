<?php
// Ficheiro: recuperar_dados_recentes.php
// Responsável por recuperar os dados do ciclo de faturação atual a partir de uma base de dados de backup.

require_once 'config.php';

echo "--- Início do Script de Recuperação de Dados Recentes ---\n\n";

// --- Configuração ---
$db_backup_name = 'energia_rescue'; // Nome da sua base de dados de backup

// --- Ligação à Base de Dados Principal (energia) ---
$mysqli_prod = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli_prod->connect_error) {
    die("ERRO: Falha na ligação à base de dados principal ('" . DB_NAME . "'): " . $mysqli_prod->connect_error . "\n");
}
$mysqli_prod->set_charset('utf8mb4');
echo "1. Ligação à base de dados principal '" . DB_NAME . "' estabelecida.\n";

// --- Ligação à Base de Dados de Backup (energia_rescue) ---
$mysqli_rescue = new mysqli(DB_HOST, DB_USER, DB_PASS, $db_backup_name);
if ($mysqli_rescue->connect_error) {
    die("ERRO: Falha na ligação à base de dados de backup ('$db_backup_name'): " . $mysqli_rescue->connect_error . "\n");
}
$mysqli_rescue->set_charset('utf8mb4');
echo "2. Ligação à base de dados de backup '$db_backup_name' estabelecida.\n";


// --- PASSO 3: Ler os dados recentes do backup ---
$dados_a_recuperar = [];
echo "3. A ler os dados do ciclo atual (desde o dia " . DIA_INICIO_CICLO . ") da base de dados de backup...\n";
 
$data_inicio_sql = '2025-10-16';

$sql_leitura = "
    SELECT le.data_hora, le.consumo_vazio, le.consumo_cheia, le.consumo_ponta, 
           la.acumulado_vazio, la.acumulado_cheia, la.acumulado_ponta
    FROM leituras_energia le
    JOIN leituras_acumuladas la ON le.id = la.leitura_id
    WHERE le.data_hora >= ?
    ORDER BY le.data_hora ASC
";
$stmt_leitura = $mysqli_rescue->prepare($sql_leitura);
$stmt_leitura->bind_param("s", $data_inicio_sql);
$stmt_leitura->execute();
$result_leitura = $stmt_leitura->get_result();
while($row = $result_leitura->fetch_assoc()) {
    $dados_a_recuperar[] = $row;
}
$stmt_leitura->close();
$mysqli_rescue->close();

if (empty($dados_a_recuperar)) {
    die("   -> Nenhum dado recente encontrado no backup para o período atual. Operação abortada.\n");
}
echo "   -> " . count($dados_a_recuperar) . " registos recentes encontrados e prontos para serem inseridos.\n\n";


// --- PASSO 4: Inserir os dados recuperados na base de dados principal ---
echo "4. A inserir os dados recuperados na base de dados principal...\n";

$mysqli_prod->begin_transaction();
try {
    $stmt_insert_leitura = $mysqli_prod->prepare("INSERT INTO leituras_energia (data_hora, consumo_vazio, consumo_cheia, consumo_ponta) VALUES (?, ?, ?, ?)");
    $stmt_insert_acumulado = $mysqli_prod->prepare("INSERT INTO leituras_acumuladas (leitura_id, data_hora, acumulado_vazio, acumulado_cheia, acumulado_ponta) VALUES (?, ?, ?, ?, ?)");

    foreach ($dados_a_recuperar as $registo) {
        // Inserir na tabela de consumo
        $stmt_insert_leitura->bind_param("sddd", $registo['data_hora'], $registo['consumo_vazio'], $registo['consumo_cheia'], $registo['consumo_ponta']);
        $stmt_insert_leitura->execute();
        $leitura_id = $mysqli_prod->insert_id;

        if ($leitura_id > 0) {
            // Inserir na tabela de acumulados
            $stmt_insert_acumulado->bind_param("isddd", $leitura_id, $registo['data_hora'], $registo['acumulado_vazio'], $registo['acumulado_cheia'], $registo['acumulado_ponta']);
            $stmt_insert_acumulado->execute();
        } else {
            throw new Exception("Falha ao obter o ID da última leitura inserida.");
        }
    }
    $stmt_insert_leitura->close();
    $stmt_insert_acumulado->close();

    // Atualizar o estado_anterior com a leitura mais recente de todas
    $ultimo_registo = end($dados_a_recuperar);
    $stmt_update = $mysqli_prod->prepare("UPDATE estado_anterior SET ultimo_vazio_acumulado = ?, ultimo_cheia_acumulado = ?, ultimo_ponta_acumulado = ?, data_atualizacao = NOW() WHERE id = 1");
    $stmt_update->bind_param("ddd", $ultimo_registo['acumulado_vazio'], $ultimo_registo['acumulado_cheia'], $ultimo_registo['acumulado_ponta']);
    $stmt_update->execute();
    $stmt_update->close();
    echo "5. A atualizar o estado final na tabela 'estado_anterior'.\n";

    $mysqli_prod->commit();
    echo "\nSUCESSO: Recuperação concluída! Os dados recentes foram re-inseridos.\n";

} catch (Exception $e) {
    $mysqli_prod->rollback();
    die("\nERRO: A recuperação falhou. Nenhuma alteração foi guardada. Detalhe: " . $e->getMessage() . "\n");
}

$mysqli_prod->close();
echo "--- Fim do Script de Recuperação ---\n";
?>