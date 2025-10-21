<?php
// Ficheiro: importador_omie_historico.php
// Responsável por importar preços dinâmicos de um intervalo de datas passado.

require_once 'config.php';
require_once 'calculos.php'; // Para usar obterPeriodoTarifario e constantes

// --- Validação dos Argumentos da Linha de Comandos ---
if ($argc < 3) {
    die("ERRO: É necessário fornecer a data de início e de fim.\nUso: php " . basename(__FILE__) . " <data_inicio YYYY-MM-DD> <data_fim YYYY-MM-DD>\n");
}

try {
    $data_inicio = new DateTime($argv[1], new DateTimeZone('Europe/Lisbon'));
    $data_fim = new DateTime($argv[2], new DateTimeZone('Europe/Lisbon'));
    $data_fim->setTime(23, 59, 59); // Garante que o último dia é incluído
} catch (Exception $e) {
    die("ERRO: Formato de data inválido. Use YYYY-MM-DD.\n");
}

if ($data_inicio > $data_fim) {
    die("ERRO: A data de início não pode ser posterior à data de fim.\n");
}

echo "--- Início do Importador de Histórico OMIE ---
";
echo "Período de importação: " . $data_inicio->format('Y-m-d') . " a " . $data_fim->format('Y-m-d') . "\n\n";

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_error) {
    die("ERRO: Falha na ligação à base de dados: " . $mysqli->connect_error . "\n");
}
$mysqli->set_charset('utf8mb4');

/**
 * Função para processar os preços de um dia específico.
 * Adaptada de coletor_precos_dinamicos.php com correção de fuso horário.
 */
function processarPrecosParaDia(mysqli $db_conn, DateTime $data_alvo): bool
{
    echo "--- A processar preços para o dia: " . $data_alvo->format('Y-m-d') . " ---
";

    // --- PASSO 1: Descarregar e ler o ficheiro de preços OMIE ---
    $data_param_omie = $data_alvo->format('d/m/Y');
    $nome_ficheiro_omie = "marginalpdbc_" . $data_alvo->format('dmY') . "_" . $data_alvo->format('dmY') . ".csv";
    
    // Remove ficheiro antigo se existir
    if (file_exists($nome_ficheiro_omie)) {
        unlink($nome_ficheiro_omie);
    }

    echo "A executar comando para obter preços OMIE para $data_param_omie...
";
    $comando_omie = "python3 scripts/omie.py -d " . escapeshellarg($data_param_omie);
    shell_exec($comando_omie);

    if (!file_exists($nome_ficheiro_omie)) {
        echo "AVISO: O ficheiro de preços OMIE não foi encontrado para {" . $data_alvo->format('Y-m-d') . "}. A saltar este dia.\n\n";
        return true; // Retorna sucesso para não abortar o loop
    }

    echo "A processar o ficheiro de preços OMIE: $nome_ficheiro_omie
";
    $precos_omie = [];
    $handle = fopen($nome_ficheiro_omie, "r");
    if (!$handle) {
        echo "ERRO: Não foi possível abrir o ficheiro de preços OMIE $nome_ficheiro_omie.
";
        return false;
    }

    while (($line = fgetcsv($handle, 1000, ";")) !== FALSE) {
        if (count($line) < 6) continue;
        $ano = (int)$line[0]; $mes = (int)$line[1]; $dia = (int)$line[2];
        $bloco_15min = (int)$line[3];
        $preco_mwh = (float)str_replace(',', '.', $line[5]);

        // OMIE está em Europe/Madrid
        $minutos_desde_meia_noite = ($bloco_15min - 1) * 15;
        $data_espanha = new DateTime("$ano-$mes-$dia 00:00:00", new DateTimeZone('Europe/Madrid'));
        $data_espanha->modify("+$minutos_desde_meia_noite minutes");
        
        // Chave será o timestamp em hora de Portugal Continental
        $data_portugal = (clone $data_espanha)->setTimezone(new DateTimeZone('Europe/Lisbon'));
        $chave_data_hora_15min = $data_portugal->format('Y-m-d H:i:s');

        $precos_omie[$chave_data_hora_15min] = $preco_mwh / 1000; // Preço em €/kWh
    }
    fclose($handle);
    unlink($nome_ficheiro_omie); // Apagar o ficheiro após o processamento

    if (empty($precos_omie)) {
        echo "AVISO: Nenhum preço OMIE encontrado no ficheiro para {" . $data_alvo->format('Y-m-d') . "}.
";
        return true;
    }

    $db_conn->begin_transaction();
    try {
        // --- Constantes do Tarifário Dinâmico (Coopérnico) ---
        if (!defined('K_MARGEM')) define('K_MARGEM', 0.009);
        if (!defined('GO_GARANTIAS')) define('GO_GARANTIAS', 0.001);

        // --- PASSO 2: Obter Fatores de Perda (FP) ---
        $data_alvo_sql = $data_alvo->format('Y-m-d');
        $data_anterior_sql = (clone $data_alvo)->modify('-1 day')->format('Y-m-d');
        $ano_alvo = $data_alvo->format('Y');
        $ano_anterior = (clone $data_alvo)->modify('-1 day')->format('Y');

        echo "A obter fatores de perda da base de dados para $data_anterior_sql e $data_alvo_sql...
";
        $sql_perdas = ($ano_alvo === $ano_anterior)
            ? "SELECT data_hora, BT FROM perdas_erse_{$ano_alvo} WHERE DATE(data_hora) IN (?, ?)"
            : "(SELECT data_hora, BT FROM perdas_erse_{$ano_anterior} WHERE DATE(data_hora) = ?) UNION ALL (SELECT data_hora, BT FROM perdas_erse_{$ano_alvo} WHERE DATE(data_hora) = ?)";
        
        $stmt_perdas = $db_conn->prepare($sql_perdas);
        if (!$stmt_perdas) throw new Exception("Falha ao preparar a consulta de perdas: " . $db_conn->error);
        
        $stmt_perdas->bind_param("ss", $data_anterior_sql, $data_alvo_sql);
        $stmt_perdas->execute();
        $result_perdas = $stmt_perdas->get_result();
        $fatores_perda = [];
        while ($row = $result_perdas->fetch_assoc()) {
            $fatores_perda[$row['data_hora']] = (float)$row['BT']; // Chave é o timestamp UTC
        }
        $stmt_perdas->close();

        // --- PASSO 3: Obter Tarifas de Acesso às Redes (TAR) ---
        $sql_tar = "SELECT * FROM tarifas_acesso_redes WHERE data_inicio <= ? AND (data_fim IS NULL OR data_fim >= ?)";
        $stmt_tar = $db_conn->prepare($sql_tar);
        if (!$stmt_tar) throw new Exception("Falha ao preparar a consulta de TAR: " . $db_conn->error);
        $stmt_tar->bind_param("ss", $data_alvo_sql, $data_alvo_sql);
        $stmt_tar->execute();
        $tar_aplicavel = $stmt_tar->get_result()->fetch_assoc();
        $stmt_tar->close();
        if (!$tar_aplicavel) throw new Exception("Nenhuma Tarifa de Acesso à Rede encontrada para a data $data_alvo_sql.");

        // --- PASSO 4: Calcular e agrupar preços ---
        echo "A calcular os preços finais...
";
        $precos_finais_por_hora = [];
        foreach ($precos_omie as $data_hora_pt_str => $preco_omie) {
            // Converter a hora de Portugal para UTC para encontrar o fator de perda correspondente
            $data_hora_pt = new DateTime($data_hora_pt_str, new DateTimeZone('Europe/Lisbon'));
            $data_hora_utc_str = (clone $data_hora_pt)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

            if (isset($fatores_perda[$data_hora_utc_str])) {
                $fator_perda = $fatores_perda[$data_hora_utc_str];
                
                $preco_base_15min = ($preco_omie + K_MARGEM + GO_GARANTIAS) * (1 + $fator_perda);
                
                $periodo_tarifario = obterPeriodoTarifario($data_hora_pt);

                $tar_aplicada = 0;
                if ($periodo_tarifario === 'Vazio') $tar_aplicada = $tar_aplicavel['preco_vazio'];
                elseif ($periodo_tarifario === 'Cheia') $tar_aplicada = $tar_aplicavel['preco_cheia'];
                elseif ($periodo_tarifario === 'Ponta') $tar_aplicada = $tar_aplicavel['preco_ponta'];

                $preco_final_15min = $preco_base_15min + $tar_aplicada;
                
                // Agrupar por hora de Portugal
                $chave_hora = $data_hora_pt->format('Y-m-d H');
                $precos_finais_por_hora[$chave_hora][] = $preco_final_15min;
            } else {
                echo "Aviso: Fator de perda não encontrado para o timestamp $data_hora_pt_str (UTC: $data_hora_utc_str). A saltar este bloco.
";
            }
        }

        // --- PASSO 5: Inserir na BD ---
        if (empty($precos_finais_por_hora)) {
            throw new Exception("Nenhum preço pôde ser calculado por falta de fatores de perda.");
        }

        echo "A inserir os preços médios horários na base de dados...
";
        $stmt_insert = $db_conn->prepare("INSERT INTO precos_dinamicos (data_hora, preco_kwh) VALUES (?, ?) ON DUPLICATE KEY UPDATE preco_kwh = VALUES(preco_kwh)");
        if (!$stmt_insert) throw new Exception("Falha ao preparar a consulta de inserção de preços: " . $db_conn->error);
        
        $count = 0;
        foreach ($precos_finais_por_hora as $chave_hora => $precos) {
            $preco_medio_hora = array_sum($precos) / count($precos);
            $data_hora_sql = "$chave_hora:00:00";
            $stmt_insert->bind_param("sd", $data_hora_sql, $preco_medio_hora);
            if (!$stmt_insert->execute()) {
                throw new Exception("Falha ao inserir/atualizar preço para $data_hora_sql: " . $stmt_insert->error);
            }
            $count++;
        }
        $stmt_insert->close();

        $db_conn->commit();
        echo "SUCESSO: $count preços horários foram calculados e guardados para o dia " . $data_alvo->format('Y-m-d') . ".\n\n";
        return true;

    } catch (Exception $e) {
        $db_conn->rollback();
        echo "ERRO: A importação para o dia " . $data_alvo->format('Y-m-d') . " falhou. Nenhuma alteração foi guardada. Detalhe: " . $e->getMessage() . "\n\n";
        return false;
    }
}

// --- LÓGICA PRINCIPAL ---
$intervalo = new DateInterval('P1D');
$periodo = new DatePeriod($data_inicio, $intervalo, $data_fim);

foreach ($periodo as $data) {
    processarPrecosParaDia($mysqli, $data);
}

$mysqli->close();
echo "--- Fim do Script de Importação ---
";
?>