<?php
// Ficheiro: coletor_precos_dinamicos.php (v2.0 - Arquitetura Multi-Fórmula)
// Responsável por calcular e armazenar os preços de energia (sem TAR) para múltiplas fórmulas dinâmicas.
// Deve ser executado diariamente por um cron job.

require_once 'config.php';
require_once 'vendor/autoload.php'; // Carrega o autoloader do Composer

use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

require_once 'calculos.php';

echo "--- Início do Coletor de Preços Dinâmicos (Multi-Fórmula) ---
";

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_error) {
    die("ERRO: Falha na ligação à base de dados: " . $mysqli->connect_error . "\n");
}
$mysqli->set_charset('utf8mb4');

// --- Inicializar o avaliador de expressões ---
$expressionLanguage = new ExpressionLanguage();

/**
 * Processa os preços de um dia específico para todas as fórmulas ativas.
 */
function processarPrecosParaDia(mysqli $db_conn, DateTime $data_alvo, string $nome_ficheiro_omie, ExpressionLanguage $expressionLanguage): bool
{
    echo "--- A processar preços para o dia: " . $data_alvo->format('Y-m-d') . " ---
";
    $data_alvo_sql = $data_alvo->format('Y-m-d');

    // --- PASSO 1: Obter todas as fórmulas de cálculo ativas para este dia ---
    $stmt_formulas = $db_conn->prepare("SELECT * FROM formulas_dinamicas WHERE data_inicio <= ? AND (data_fim IS NULL OR data_fim >= ?)");
    if (!$stmt_formulas) {
        echo "ERRO: Falha ao preparar a consulta de fórmulas.\n"; return false;
    }
    $stmt_formulas->bind_param("ss", $data_alvo_sql, $data_alvo_sql);
    $stmt_formulas->execute();
    $result_formulas = $stmt_formulas->get_result();
    $formulas_ativas = [];
    while ($row = $result_formulas->fetch_assoc()) {
        $formulas_ativas[] = $row;
    }
    $stmt_formulas->close();

    if (empty($formulas_ativas)) {
        echo "AVISO: Nenhuma fórmula de cálculo ativa encontrada para {$data_alvo_sql}.\n";
        return true;
    }
    echo count($formulas_ativas) . " fórmulas ativas encontradas.\n";

    // --- PASSO 2: Ler o ficheiro de preços OMIE ---
    echo "A processar o ficheiro de preços OMIE: $nome_ficheiro_omie\n";
    $precos_omie = [];
    $handle = fopen($nome_ficheiro_omie, "r");
    if (!$handle) {
        echo "ERRO: Não foi possível abrir o ficheiro OMIE $nome_ficheiro_omie.\n"; return false;
    }
    while (($line = fgetcsv($handle, 1000, ";")) !== FALSE) {
        if (count($line) < 6) continue;
        // CORREÇÃO: A coluna 3 contém a hora (1-24). Devemos definir a hora, não adicionar minutos.
        $hora_omie = (int)$line[3] - 1; // Converter de 1-24 para 0-23
        $data_espanha = new DateTime("{$line[0]}-{$line[1]}-{$line[2]}", new DateTimeZone('Europe/Madrid'));
        $data_espanha->setTime($hora_omie, 0, 0);
        $data_portugal = (clone $data_espanha)->setTimezone(new DateTimeZone('Europe/Lisbon'));
        $precos_omie[$data_portugal->format('Y-m-d H:i:s')] = (float)str_replace(',', '.', $line[5]) / 1000;
    }
    fclose($handle);

    if (empty($precos_omie)) {
        echo "AVISO: Nenhum preço OMIE encontrado no ficheiro.\n"; return true;
    }

    $db_conn->begin_transaction();
    try {
        // --- PASSO 3: Obter Fatores de Perda (FP) para o dia alvo e o dia anterior ---
        $data_anterior_sql = (clone $data_alvo)->modify('-1 day')->format('Y-m-d');
        $ano_alvo = $data_alvo->format('Y');
        $ano_anterior = (clone $data_alvo)->modify('-1 day')->format('Y');
        $fatores_perda = [];

        if ($ano_alvo === $ano_anterior) {
            // Caso normal: ambos os dias estão no mesmo ano
            $sql_perdas = "SELECT data_hora, BT FROM perdas_erse_{$ano_alvo} WHERE DATE(data_hora) IN (?, ?)";
            $stmt_perdas = $db_conn->prepare($sql_perdas);
            if (!$stmt_perdas) throw new Exception("Falha ao preparar a consulta de perdas (mesmo ano): " . $db_conn->error);
            $stmt_perdas->bind_param("ss", $data_anterior_sql, $data_alvo_sql);
            $stmt_perdas->execute();
            $result_perdas = $stmt_perdas->get_result();
            while ($row = $result_perdas->fetch_assoc()) { $fatores_perda[$row['data_hora']] = (float)$row['BT']; }
            $stmt_perdas->close();
        } else {
            // Caso de transição de ano: duas consultas separadas
            foreach ([$ano_anterior => $data_anterior_sql, $ano_alvo => $data_alvo_sql] as $ano => $data_sql) {
                $sql_perdas = "SELECT data_hora, BT FROM perdas_erse_{$ano} WHERE DATE(data_hora) = ?";
                $stmt_perdas = $db_conn->prepare($sql_perdas);
                if (!$stmt_perdas) throw new Exception("Falha ao preparar a consulta de perdas (ano $ano): " . $db_conn->error);
                $stmt_perdas->bind_param("s", $data_sql);
                $stmt_perdas->execute();
                $result_perdas = $stmt_perdas->get_result();
                while ($row = $result_perdas->fetch_assoc()) { $fatores_perda[$row['data_hora']] = (float)$row['BT']; }
                $stmt_perdas->close();
            }
        }

        // --- PASSO 4: Calcular e agrupar preços de energia para cada fórmula ---
        echo "A calcular os preços de energia (sem TAR)...
";
        $precos_finais_por_hora = [];
        foreach ($precos_omie as $data_hora_pt_str => $preco_omie) {
            $data_hora_pt = new DateTime($data_hora_pt_str, new DateTimeZone('Europe/Lisbon'));

            // GARANTE QUE SÓ PROCESSAMOS PREÇOS DO DIA CORRETO
            if ($data_hora_pt->format('Y-m-d') !== $data_alvo_sql) continue;

            $data_hora_pt = new DateTime($data_hora_pt_str, new DateTimeZone('Europe/Lisbon'));
            // CORREÇÃO: Os fatores de perda estão guardados com o timestamp do FIM do intervalo de 15min.
            // Adicionamos 15 minutos ao timestamp do preço OMIE (que é o início do intervalo) para encontrar a correspondência.
            $data_hora_utc_str = (clone $data_hora_pt)->modify('+15 minutes')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

            if (isset($fatores_perda[$data_hora_utc_str])) {
                $fator_perda = $fatores_perda[$data_hora_utc_str];
                $chave_hora = $data_hora_pt->format('Y-m-d H');

                foreach ($formulas_ativas as $formula) {
                    try {
                        $preco_energia_15min = $expressionLanguage->evaluate(
                            $formula['expressao_calculo'],
                            [
                                'OMIE' => $preco_omie,    // Preço OMIE em €/kWh
                                'PERDAS' => $fator_perda, // Fator de perdas (ex: 0.05)
                            ]
                        );
                        $precos_finais_por_hora[$chave_hora][$formula['id']][] = $preco_energia_15min;
                    } catch (\Exception $e) {
                        echo "ERRO ao avaliar expressão para fórmula ID {$formula['id']}: " . $e->getMessage() . "\n";
                    }
                }
            } else {
                echo "Aviso: Fator de perda não encontrado para $data_hora_pt_str (UTC: $data_hora_utc_str).\n";
            }
        }

        // --- PASSO 5: Inserir na BD ---
        if (empty($precos_finais_por_hora)) throw new Exception("Nenhum preço pôde ser calculado por falta de fatores de perda.");

        echo "A inserir os preços médios horários na base de dados...
";
        $stmt_insert = $db_conn->prepare("INSERT INTO precos_energia_dinamicos (data_hora, formula_id, preco_energia) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE preco_energia = VALUES(preco_energia)");
        if (!$stmt_insert) throw new Exception("Falha ao preparar a consulta de inserção: " . $db_conn->error);
        
        $count = 0;
        foreach ($precos_finais_por_hora as $chave_hora => $formulas_hora) {
            $data_hora_sql = "{$chave_hora}:00:00";
            foreach ($formulas_hora as $formula_id => $precos) {
                $preco_medio_hora = array_sum($precos) / count($precos);
                $stmt_insert->bind_param("sid", $data_hora_sql, $formula_id, $preco_medio_hora);
                if (!$stmt_insert->execute()) throw new Exception("Falha ao inserir preço para $data_hora_sql, Formula ID $formula_id: " . $stmt_insert->error);
                $count++;
            }
        }
        $stmt_insert->close();

        $db_conn->commit();
        echo "SUCESSO: $count registos de preços de energia foram calculados e guardados para o dia {$data_alvo_sql}.\n";
        return true;

    } catch (Exception $e) {
        $db_conn->rollback();
        echo "ERRO: A importação para o dia {$data_alvo_sql} falhou. Detalhe: " . $e->getMessage() . "\n";
        return false;
    }
}

// --- LÓGICA PRINCIPAL ---
$hoje = new DateTime('now', new DateTimeZone('Europe/Lisbon'));
$dia_ciclo = defined('DIA_INICIO_CICLO') ? DIA_INICIO_CICLO : 16;
$dia_hoje = (int) $hoje->format('j');
$data_inicio_periodo = ($dia_hoje >= $dia_ciclo)
    ? new DateTime($hoje->format('Y-m-') . $dia_ciclo)
    : (new DateTime($hoje->format('Y-m-') . $dia_ciclo))->modify('-1 month');

// Descarrega todos os ficheiros OMIE necessários de uma só vez.
$fim_periodo_verificacao = (new DateTime('now', new DateTimeZone('Europe/Lisbon')))->modify('+2 days')->setTime(0, 0, 0);
$periodo_descarregamento = new DatePeriod($data_inicio_periodo, new DateInterval('P1D'), $fim_periodo_verificacao);

echo "\n--- A descarregar ficheiros OMIE necessários ---
";
foreach ($periodo_descarregamento as $data) {
    $data_param_omie = $data->format('d/m/Y');
    $nome_ficheiro_omie = "marginalpdbc_" . $data->format('dmY') . "_" . $data->format('dmY') . ".csv";
    if (!file_exists($nome_ficheiro_omie)) {
        echo "A executar comando para obter preços OMIE para $data_param_omie...
";
        shell_exec("python3 " . __DIR__ . "/scripts/omie.py -d " . escapeshellarg($data_param_omie));
    }
}

// Itera desde o início do período até amanhã e processa os dias em falta.
$periodo_a_verificar = new DatePeriod($data_inicio_periodo, new DateInterval('P1D'), $fim_periodo_verificacao);

foreach ($periodo_a_verificar as $data) {
    $dia_str = $data->format('Y-m-d');
    $nome_ficheiro_omie = "marginalpdbc_" . $data->format('dmY') . "_" . $data->format('dmY') . ".csv";

    // Verifica se o dia precisa de ser processado
    $stmt_check = $mysqli->prepare("SELECT COUNT(*) as num_formulas FROM formulas_dinamicas WHERE data_inicio <= ? AND (data_fim IS NULL OR data_fim >= ?)");
    $stmt_check->bind_param("ss", $dia_str, $dia_str);
    $stmt_check->execute();
    $num_formulas_ativas = $stmt_check->get_result()->fetch_assoc()['num_formulas'];
    $stmt_check->close();

    $registos_necessarios = 24 * $num_formulas_ativas;

    $stmt_count = $mysqli->prepare("SELECT COUNT(*) as num_registos FROM precos_energia_dinamicos WHERE DATE(data_hora) = ?");
    $stmt_count->bind_param("s", $dia_str);
    $stmt_count->execute();
    $registos_existentes = $stmt_count->get_result()->fetch_assoc()['num_registos'];
    $stmt_count->close();

    if (file_exists($nome_ficheiro_omie) && $registos_existentes < $registos_necessarios) {
        processarPrecosParaDia($mysqli, $data, $nome_ficheiro_omie, $expressionLanguage);
    } else {
        if (!file_exists($nome_ficheiro_omie)) {
             echo "\nFicheiro OMIE para o dia $dia_str não existe. A saltar.\n";
        } else {
             echo "\nPreços para o dia $dia_str já existem ($registos_existentes / $registos_necessarios registos). A saltar.\n";
        }
    }
}

$mysqli->close();
echo "\n--- Verificação de Preços Dinâmicos Concluída ---
";
?>
