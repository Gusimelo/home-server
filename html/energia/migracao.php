<?php
// Ficheiro: migracao.php
// ATENÇÃO: Este é um script de execução única para migrar dados da estrutura antiga para a nova.
// Execute-o apenas uma vez.

require_once 'config.php';

echo "--- Início do Script de Migração ---\n";

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_error) {
    die("ERRO: Falha na ligação à base de dados: " . $mysqli->connect_error . "\n");
}
$mysqli->set_charset('utf8mb4');

// Verifica se a nova tabela já existe
$check_table = $mysqli->query("SHOW TABLES LIKE 'custos_leituras'");
if ($check_table->num_rows == 0) {
    die("ERRO: A nova tabela 'custos_leituras' não foi encontrada. Por favor, execute primeiro os comandos SQL para criar a nova estrutura.\n");
}

// Inicia uma transação para garantir a integridade dos dados
$mysqli->begin_transaction();

try {
    echo "1. A ler todos os registos da tabela 'leituras_energia'...\n";
    
    // Seleciona apenas as colunas necessárias para a migração
    $result = $mysqli->query("SELECT id, tarifa_id, custo_vazio, custo_cheia, custo_ponta, custo_simples FROM leituras_energia");
    
    if (!$result) {
        throw new Exception("Falha ao ler da tabela 'leituras_energia': " . $mysqli->error);
    }
    
    $total_registos = $result->num_rows;
    echo "   -> Encontrados $total_registos registos para migrar.\n";

    $stmt_insert = $mysqli->prepare("INSERT INTO custos_leituras (leitura_id, tarifa_id, custo_energia) VALUES (?, ?, ?)");
    if (!$stmt_insert) {
        throw new Exception("Falha ao preparar a query de inserção: " . $mysqli->error);
    }

    $count_sucesso = 0;
    while ($row = $result->fetch_assoc()) {
        $leitura_id = $row['id'];
        $tarifa_id = $row['tarifa_id'];

        // Se um registo antigo não tiver tarifa associada, não podemos migrá-lo.
        if (empty($tarifa_id)) {
            echo "Aviso: A saltar leitura ID #$leitura_id por não ter tarifa_id associado.\n";
            continue;
        }

        // 1. Migrar o custo Bi-Horário
        $custo_bihorario = (float)$row['custo_vazio'] + (float)$row['custo_cheia'] + (float)$row['custo_ponta'];
        $stmt_insert->bind_param("iid", $leitura_id, $tarifa_id, $custo_bihorario);
        $stmt_insert->execute();

        // 2. Migrar o custo Simples (usando a convenção de ID negativo)
        $custo_simples = (float)$row['custo_simples'];
        $tarifa_id_simples = -$tarifa_id;
        $stmt_insert->bind_param("iid", $leitura_id, $tarifa_id_simples, $custo_simples);
        $stmt_insert->execute();
        
        $count_sucesso++;
        if ($count_sucesso % 500 == 0) {
            echo "   ... $count_sucesso de $total_registos registos migrados.\n";
        }
    }

    $result->free();
    $stmt_insert->close();

    // Se tudo correu bem, confirma as alterações
    $mysqli->commit();
    echo "\nSUCESSO: Migração concluída com sucesso para $count_sucesso registos!\n";
    echo "Pode agora apagar as colunas antigas da tabela 'leituras_energia'.\n";

} catch (Exception $e) {
    // Se algo falhou, desfaz todas as alterações
    $mysqli->rollback();
    die("\nERRO: A migração falhou. Nenhuma alteração foi guardada. Detalhe: " . $e->getMessage() . "\n");
}

$mysqli->close();
echo "--- Fim do Script de Migração ---\n";
?>
