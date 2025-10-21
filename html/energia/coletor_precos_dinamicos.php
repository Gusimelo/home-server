<?php
// Ficheiro: coletor_precos_dinamicos.php
// Responsável por calcular e armazenar os preços horários do tarifário dinâmico.
// Deve ser executado diariamente por um cron job (ex: a partir das 13:00).

require_once 'config.php';
require_once 'calculos.php'; // Para usar obterPeriodoTarifario e constantes

echo "--- Início do Coletor de Preços Dinâmicos ---\n";

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_error) {
    die("ERRO: Falha na ligação à base de dados: " . $mysqli->connect_error . "\n");
}
$mysqli->set_charset('utf8mb4');

/**
 * Função principal para calcular e armazenar os preços para um dia específico.
 */
function processarPrecosParaDia(mysqli $db_conn, DateTime $data_alvo): bool
{
    // A transação pode voltar para o início da função, pois não há mais queries cross-database.
    $db_conn->begin_transaction();

    echo "--- A processar preços para o dia: " . $data_alvo->format('Y-m-d') . " ---\n";

    // --- Constantes do Tarifário Dinâmico (Coopérnico) ---
    if (!defined('K_MARGEM')) define('K_MARGEM', 0.009);
    if (!defined('GO_GARANTIAS')) define('GO_GARANTIAS', 0.001);

    // --- PASSO 1: Ler o ficheiro de preços OMIE para o dia alvo ---
    $nome_ficheiro_omie = "marginalpdbc_" . $data_alvo->format('dmY') . "_" . $data_alvo->format('dmY') . ".csv";

    echo "A processar o ficheiro de preços OMIE: $nome_ficheiro_omie\n";
    $precos_omie = [];
    $handle = fopen($nome_ficheiro_omie, "r");
    if ($handle) {
        while (($line = fgetcsv($handle, 1000, ";")) !== FALSE) {
            $ano = (int)$line[0]; $mes = (int)$line[1]; $dia = (int)$line[2];
            $bloco_15min = (int)$line[3];
            $minutos_desde_meia_noite = ($bloco_15min - 1) * 15;
            $data_espanha = new DateTime("$ano-$mes-$dia 00:00:00", new DateTimeZone('Europe/Madrid'));
            $data_espanha->modify("+$minutos_desde_meia_noite minutes");
            $data_portugal = clone $data_espanha;
            $data_portugal->setTimezone(new DateTimeZone('Europe/Lisbon'));
            $chave_data_hora_15min = $data_portugal->format('Y-m-d H:i:s');
            $preco_mwh = (float)str_replace(',', '.', $line[5]);

            $precos_omie[$chave_data_hora_15min] = $preco_mwh / 1000;
        }
        fclose($handle);
        // unlink($nome_ficheiro_omie); // Mantido para depuração
    } else {
        echo "ERRO: Não foi possível abrir o ficheiro de preços OMIE.\n";
        $db_conn->rollback(); 
        return false;
    }

    // --- PASSO 2: Obter Fatores de Perda (FP) ---
    $data_alvo_sql = $data_alvo->format('Y-m-d');
    $data_anterior_sql = (clone $data_alvo)->modify('-1 day')->format('Y-m-d');
    $ano_alvo = $data_alvo->format('Y');
    $ano_anterior = (clone $data_alvo)->modify('-1 day')->format('Y');

    echo "A obter fatores de perda da base de dados para $data_alvo_sql...\n";
    // Abrange o final do dia anterior e o dia alvo para lidar com a conversão de fuso horário.
    // Se o ano for o mesmo, a query é simplificada para evitar uma união desnecessária.
    $sql_perdas = ($ano_alvo === $ano_anterior)
        ? "SELECT data_hora, BT FROM perdas_erse_{$ano_alvo} WHERE DATE(data_hora) IN (?, ?)"
        : "SELECT data_hora, BT FROM perdas_erse_{$ano_anterior} WHERE DATE(data_hora) = ?
                   UNION ALL
                   SELECT data_hora, BT FROM perdas_erse_{$ano_alvo} WHERE DATE(data_hora) = ?";
    $stmt_perdas = $db_conn->prepare($sql_perdas);
    if (!$stmt_perdas) {
        echo "ERRO: Falha ao preparar a consulta de perdas: " . $db_conn->error . "\n";
        $db_conn->rollback();
        return false;
    }
    if ($ano_alvo === $ano_anterior) {
        $stmt_perdas->bind_param("ss", $data_anterior_sql, $data_alvo_sql);
    } else {
        $stmt_perdas->bind_param("ss", $data_anterior_sql, $data_alvo_sql);
    }

    $stmt_perdas->execute();
    $result_perdas = $stmt_perdas->get_result();
    $fatores_perda = [];
    while ($row = $result_perdas->fetch_assoc()) {
        $fatores_perda[$row['data_hora']] = (float)$row['BT'];
    }
    $stmt_perdas->close();

    // --- PASSO 3: Obter Tarifas de Acesso às Redes (TAR) ---
    $sql_tar = "SELECT * FROM tarifas_acesso_redes WHERE data_inicio <= ? AND (data_fim IS NULL OR data_fim >= ?)";
    $stmt_tar = $db_conn->prepare($sql_tar);
    $stmt_tar->bind_param("ss", $data_alvo_sql, $data_alvo_sql);
    $stmt_tar->execute();
    $tar_aplicavel = $stmt_tar->get_result()->fetch_assoc();
    $stmt_tar->close();
    if (!$tar_aplicavel) {
        echo "Aviso: Nenhuma Tarifa de Acesso à Rede encontrada para a data $data_alvo_sql.\n";
        $db_conn->rollback(); 
        return false;
    }

    // --- PASSO 4: Calcular e agrupar preços ---
    echo "A calcular os preços finais...\n";
    $precos_finais_por_hora = []; // Agora vai guardar um único preço por hora
    foreach ($precos_omie as $data_hora_15min => $preco_omie) {
        if (isset($fatores_perda[$data_hora_15min])) {
            $fator_perda = $fatores_perda[$data_hora_15min];
            $data_obj = new DateTime($data_hora_15min, new DateTimeZone('UTC'));

            // Ignora dados que não pertencem ao dia alvo ou à última hora do dia anterior
            if ($data_obj->format('Y-m-d') !== $data_alvo->format('Y-m-d') && $data_obj->format('Y-m-d') !== $data_anterior_sql) {
                continue;
            }
            // Fórmula base da Coopérnico
            $preco_base_15min = ($preco_omie + K_MARGEM + GO_GARANTIAS) * (1 + $fator_perda);
            
            $periodo_tarifario = obterPeriodoTarifario($data_obj);

            $tar_aplicada = 0;
            if ($periodo_tarifario === 'Vazio') {
                $tar_aplicada = $tar_aplicavel['preco_vazio'];
            } elseif ($periodo_tarifario === 'Cheia') {
                $tar_aplicada = $tar_aplicavel['preco_cheia'];
            } elseif ($periodo_tarifario === 'Ponta') {
                $tar_aplicada = $tar_aplicavel['preco_ponta'];
            }

            $preco_final_15min = $preco_base_15min + $tar_aplicada;
            $chave_hora = $data_obj->format('Y-m-d H');
            $precos_finais_por_hora[$chave_hora][] = $preco_final_15min;
        } else {
            echo "Aviso: Fator de perda não encontrado para o timestamp $data_hora_15min. A saltar este bloco.\n";
        }
    }

    // --- PASSO 5: Inserir na BD ---
    echo "A inserir os preços médios horários na base de dados...\n";
        
    $stmt_insert = $db_conn->prepare("INSERT INTO precos_dinamicos (data_hora, preco_kwh) VALUES (?, ?) ON DUPLICATE KEY UPDATE preco_kwh = VALUES(preco_kwh)");
    if (!$stmt_insert) {
        echo "ERRO: Falha ao preparar a consulta de inserção de preços: " . $db_conn->error . "\n";
        $db_conn->rollback();
        return false;
    }
    $count = 0;
    foreach ($precos_finais_por_hora as $chave_hora => $precos) {
        $preco_medio_hora = array_sum($precos) / count($precos);
        $data_hora_sql = "$chave_hora:00:00";
        $stmt_insert->bind_param("sd", $data_hora_sql, $preco_medio_hora);
        if (!$stmt_insert->execute()) {
            echo "ERRO: Falha ao inserir/atualizar preço para $data_hora_sql: " . $stmt_insert->error . "\n";
            $db_conn->rollback();
            return false; // Aborta o processamento para este dia
        }
        $count++;
    }
    $stmt_insert->close();

    $db_conn->commit(); // Confirma as inserções para este dia
    echo "SUCESSO: $count preços horários foram calculados e guardados para o dia " . $data_alvo->format('Y-m-d') . ".\n";
    return true;
}

// --- LÓGICA PRINCIPAL ---

// 1. Determinar o período de faturação atual
$hoje = new DateTime('now', new DateTimeZone('Europe/Lisbon'));
$dia_ciclo = defined('DIA_INICIO_CICLO') ? DIA_INICIO_CICLO : 16;
$dia_hoje = (int) $hoje->format('j');
$data_inicio_periodo = ($dia_hoje >= $dia_ciclo)
    ? new DateTime($hoje->format('Y-m-') . $dia_ciclo)
    : (new DateTime($hoje->format('Y-m-') . $dia_ciclo))->modify('-1 month');

// 2. Obter os dias para os quais já temos preços no período atual
$dias_com_precos = [];
$sql_check = "SELECT DISTINCT DATE(data_hora) as dia FROM precos_dinamicos WHERE data_hora >= ?";
$stmt_check = $mysqli->prepare($sql_check);
$data_inicio_sql = $data_inicio_periodo->format('Y-m-d');
$stmt_check->bind_param("s", $data_inicio_sql);
$stmt_check->execute();
$result_check = $stmt_check->get_result();
while ($row = $result_check->fetch_assoc()) {
    $dias_com_precos[] = $row['dia'];
}
$stmt_check->close();

// 3. Iterar desde o início do período até amanhã e processar os dias em falta
// O fim do DatePeriod é exclusivo. Para incluir o dia de amanhã na verificação,
// o limite tem de ser o dia a seguir a amanhã.
$fim_periodo_verificacao = (new DateTime('now', new DateTimeZone('Europe/Lisbon')))->modify('+2 days')->setTime(0, 0, 0);
$periodo_a_verificar = new DatePeriod($data_inicio_periodo, new DateInterval('P1D'), $fim_periodo_verificacao);

// Antes de iterar, descarrega todos os ficheiros OMIE necessários de uma só vez.
// O ciclo vai até ao dia seguinte ao último dia a processar para garantir que temos os dados para a hora 23.
echo "\n--- A descarregar ficheiros OMIE necessários ---\n";
$fim_descarregamento = (clone $fim_periodo_verificacao);
$periodo_descarregamento = new DatePeriod($data_inicio_periodo, new DateInterval('P1D'), $fim_descarregamento);

foreach ($periodo_descarregamento as $data) {
    $data_param_omie = $data->format('d/m/Y');
    $nome_ficheiro_omie = "marginalpdbc_" . $data->format('dmY') . "_" . $data->format('dmY') . ".csv";
    $comando_omie = "python3 scripts/omie.py -d " . escapeshellarg($data_param_omie);
    echo "A executar comando para obter preços OMIE para $data_param_omie...\n";
    shell_exec($comando_omie);
}

foreach ($periodo_a_verificar as $data) {
    $dia_str = $data->format('Y-m-d');
    $nome_ficheiro_omie = "marginalpdbc_" . $data->format('dmY') . "_" . $data->format('dmY') . ".csv";

    // Processa o dia se o ficheiro OMIE existir e (o dia não estiver na BD ou se tiver menos de 24 registos)
    // A verificação de < 24 registos força o reprocessamento para completar a hora 23.
    if (file_exists($nome_ficheiro_omie) && (!in_array($dia_str, $dias_com_precos) || count(array_filter($dias_com_precos, fn($d) => $d === $dia_str)) < 24)) {
        processarPrecosParaDia($mysqli, $data);
    } else {
        echo "\nPreços para o dia $dia_str já existem. A saltar.\n";
    }
}

$mysqli->close();
echo "\n--- Verificação de Preços Dinâmicos Concluída ---\n";
?>