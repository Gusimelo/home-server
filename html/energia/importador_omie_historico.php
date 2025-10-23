<?php
// Ficheiro: importador_omie_historico.php (v2.0 - Arquitetura Multi-Fórmula)
// Responsável por importar preços de energia (sem TAR) para múltiplas fórmulas dinâmicas.

require_once 'config.php';
require_once 'vendor/autoload.php'; // Carrega o autoloader do Composer

use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
require_once 'calculos.php';

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

echo "--- Início do Importador de Histórico OMIE (Multi-Fórmula) ---\
";
echo "Período de importação: " . $data_inicio->format('Y-m-d') . " a " . $data_fim->format('Y-m-d') . "\n\n";

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
function processarPrecosParaDia(mysqli $db_conn, DateTime $data_alvo, ExpressionLanguage $expressionLanguage): bool
{
    echo "--- A processar preços para o dia: " . $data_alvo->format('Y-m-d') . " ---\
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
        echo "AVISO: Nenhuma fórmula de cálculo ativa encontrada para {$data_alvo_sql}. A saltar este dia.\n\n";
        return true;
    }
    echo count($formulas_ativas) . " fórmulas ativas encontradas para este dia.\n";

    // --- PASSO 2: Descarregar e ler o ficheiro de preços OMIE ---
    $data_param_omie = $data_alvo->format('d/m/Y');
    $nome_ficheiro_omie = "marginalpdbc_" . $data_alvo->format('dmY') . "_" . $data_alvo->format('dmY') . ".csv";
    
    if (file_exists($nome_ficheiro_omie)) unlink($nome_ficheiro_omie);

    echo "A executar comando para obter preços OMIE para $data_param_omie...\n";
    shell_exec("python3 " . __DIR__ . "/scripts/omie.py -d " . escapeshellarg($data_param_omie));

    if (!file_exists($nome_ficheiro_omie)) {
        echo "AVISO: O ficheiro de preços OMIE não foi encontrado. A saltar este dia.\n\n";
        return true;
    }

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
    unlink($nome_ficheiro_omie);

    if (empty($precos_omie)) {
        echo "AVISO: Nenhum preço OMIE encontrado no ficheiro.\n\n"; return true;
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
        echo "A calcular os preços de energia (sem TAR)...";
        $precos_finais_por_hora = [];
        foreach ($precos_omie as $data_hora_pt_str => $preco_omie) {
            $data_hora_pt = new DateTime($data_hora_pt_str, new DateTimeZone('Europe/Lisbon'));
            
            // GARANTE QUE SÓ PROCESSAMOS PREÇOS DO DIA CORRETO
            if ($data_hora_pt->format('Y-m-d') !== $data_alvo_sql) continue;

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
                echo "Aviso: Fator de perda não encontrado para $data_hora_pt_str (UTC: $data_hora_utc_str). Bloco ignorado.\n";
            }
        }

        // --- PASSO 5: Inserir na BD ---
        if (empty($precos_finais_por_hora)) throw new Exception("Nenhum preço pôde ser calculado por falta de fatores de perda.");

        echo "A inserir os preços médios horários na base de dados...";
        $stmt_insert = $db_conn->prepare("INSERT INTO precos_energia_dinamicos (data_hora, formula_id, preco_energia) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE preco_energia = VALUES(preco_energia)");
        if (!$stmt_insert) throw new Exception("Falha ao preparar a consulta de inserção: " . $db_conn->error);
        
        $count = 0;
        foreach ($precos_finais_por_hora as $chave_hora => $formulas_hora) {
            $data_hora_sql = "$chave_hora:00:00";
            foreach ($formulas_hora as $formula_id => $precos) {
                $preco_medio_hora = array_sum($precos) / count($precos);
                $stmt_insert->bind_param("sid", $data_hora_sql, $formula_id, $preco_medio_hora);
                if (!$stmt_insert->execute()) throw new Exception("Falha ao inserir preço para $data_hora_sql, Formula ID $formula_id: " . $stmt_insert->error);
                $count++;
            }
        }
        $stmt_insert->close();

        $db_conn->commit();
        echo "SUCESSO: $count registos de preços de energia foram calculados e guardados para o dia {$data_alvo_sql}.\n\n";
        return true;

    } catch (Exception $e) {
        $db_conn->rollback();
        echo "ERRO: A importação para o dia {$data_alvo_sql} falhou. Detalhe: " . $e->getMessage() . "\n\n";
        return false;
    }
}

// --- LÓGICA PRINCIPAL ---
$intervalo = new DateInterval('P1D');
$periodo = new DatePeriod($data_inicio, $intervalo, $data_fim);

foreach ($periodo as $data) {
    processarPrecosParaDia($mysqli, $data, $expressionLanguage);
}

$mysqli->close();
echo "--- Fim do Script de Importação ---\
";
?>
