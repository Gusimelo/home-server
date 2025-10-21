<?php
// Ficheiro: importador_historico.php
// Responsável por importar dados de consumo históricos a partir de um ficheiro CSV.
// v3.0 - Lógica de importação robusta para lidar com dados em falta ou inválidos.

require_once 'config.php';

echo "--- Início do Importador de Histórico de Consumo ---\n\n";

// --- Validação dos Argumentos da Linha de Comandos ---
if ($argc < 2) {
    die("ERRO: É necessário fornecer o caminho para o ficheiro CSV.\nUso: php importador_historico.php <caminho_para_o_ficheiro.csv> [--dry-run]\n");
}

$caminho_ficheiro = $argv[1];
$is_dry_run = in_array('--dry-run', $argv);

if (!file_exists($caminho_ficheiro)) {
    die("ERRO: O ficheiro '$caminho_ficheiro' não foi encontrado.\n");
}

if ($is_dry_run) {
    echo "AVISO: A executar em modo de simulação (dry-run). Nenhuma alteração será guardada na base de dados.\n\n";
}

// --- PASSO 1: Ler e Processar o Ficheiro CSV ---
echo "1. A ler e processar o ficheiro CSV: '$caminho_ficheiro'...\n";

$leituras = [];
$handle = fopen($caminho_ficheiro, "r");
if (!$handle) {
    die("ERRO: Não foi possível abrir o ficheiro CSV.\n");
}

// Mapeamento dos entity_id para as chaves que usamos
$mapa_entidades = [
    'sensor.contador_total_vazio' => 'vazio',
    'sensor.contador_total_cheia' => 'cheia',
    'sensor.contador_total_ponta' => 'ponta',
];

fgetcsv($handle); // Ignorar a primeira linha (cabeçalho)

while (($linha = fgetcsv($handle, 1000, ",")) !== FALSE) {
    if (count($linha) < 3 || !is_numeric($linha[1])) continue; // Ignora imediatamente linhas com valores não numéricos.

    $entity_id = $linha[0];
    $state = (float)$linha[1];
    $last_changed = $linha[2];

    if (!isset($mapa_entidades[$entity_id])) continue;

    $periodo = $mapa_entidades[$entity_id];

    if (!isset($leituras[$last_changed])) {
        $leituras[$last_changed] = ['vazio' => null, 'cheia' => null, 'ponta' => null];
    }
    $leituras[$last_changed][$periodo] = $state;
}
fclose($handle);

// Ordenar as leituras por data/hora
ksort($leituras);

echo "   -> Encontrados " . count($leituras) . " registos de tempo únicos.\n";

// --- PASSO 2: Consolidar os Dados e Preencher Lacunas ---
echo "2. A consolidar os dados e a preencher valores em falta...\n";

$leituras_consolidadas = [];
$ultimo_estado = ['vazio' => null, 'cheia' => null, 'ponta' => null]; // Inicializa com null para garantir que o primeiro valor é apanhado corretamente.

foreach ($leituras as $timestamp => $valores) {
    // Preenche valores nulos com o último valor conhecido, mas apenas se já tivermos um valor inicial.
    // Isto evita que o estado inicial seja 0.0 e cause um pico de consumo.
    $ultimo_estado['vazio'] = $valores['vazio'] ?? $ultimo_estado['vazio'];
    $ultimo_estado['cheia'] = $valores['cheia'] ?? $ultimo_estado['cheia'];
    $ultimo_estado['ponta'] = $valores['ponta'] ?? $ultimo_estado['ponta'];

    // Adiciona o snapshot consolidado
    $leituras_consolidadas[] = [
        'timestamp' => $timestamp,
        'vazio' => $ultimo_estado['vazio'],
        'cheia' => $ultimo_estado['cheia'],
        'ponta' => $ultimo_estado['ponta'],
    ];
}

if (empty($leituras_consolidadas)) {
    die("Nenhum dado válido encontrado para importar.\n");
}

echo "   -> Consolidação concluída.\n";

// --- PASSO 3: Inserir na Base de Dados ---
echo "3. A preparar para inserir os dados na base de dados...\n";

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_error) {
    die("ERRO: Falha na ligação à base de dados: " . $mysqli->connect_error . "\n");
}
$mysqli->set_charset('utf8mb4');

if (!$is_dry_run) {
    $mysqli->begin_transaction();
}

$total_a_inserir = count($leituras_consolidadas) - 1;
echo "   -> Serão inseridos $total_a_inserir registos de consumo.\n";

try {
    $stmt_leitura = $mysqli->prepare("INSERT INTO leituras_energia (data_hora, consumo_vazio, consumo_cheia, consumo_ponta) VALUES (?, ?, ?, ?)");
    $stmt_acumulado = $mysqli->prepare("INSERT INTO leituras_acumuladas (leitura_id, data_hora, acumulado_vazio, acumulado_cheia, acumulado_ponta) VALUES (?, ?, ?, ?, ?)");

    if (!$stmt_leitura || !$stmt_acumulado) {
        throw new Exception("Falha ao preparar as queries: " . $mysqli->error);
    }

    $anterior = $leituras_consolidadas[0];
    $count_sucesso = 0;

    for ($i = 1; $i < count($leituras_consolidadas); $i++) {
        $atual = $leituras_consolidadas[$i];

        // Calcular o consumo do intervalo (delta)
        $consumo_vazio = max(0, $atual['vazio'] - $anterior['vazio']);
        $consumo_cheia = max(0, $atual['cheia'] - $anterior['cheia']);
        $consumo_ponta = max(0, $atual['ponta'] - $anterior['ponta']);

        // Se algum dos valores anteriores era nulo, significa que estamos a recomeçar após uma falha.
        // Ignoramos este primeiro cálculo para evitar picos de consumo falsos.
        if ($anterior['vazio'] === null || $anterior['cheia'] === null || $anterior['ponta'] === null) {
            $anterior = $atual;
            continue;
        }

        // Formatar a data para o formato SQL DATETIME
        $data_sql = (new DateTime($atual['timestamp']))->format('Y-m-d H:i:s');

        if ($is_dry_run) {
            if ($i < 5 || $i > $total_a_inserir - 5) { // Mostra alguns exemplos
                echo "   [DRY-RUN] Inseriria consumo em $data_sql: Vazio=$consumo_vazio, Cheia=$consumo_cheia, Ponta=$consumo_ponta\n";
            }
        } else {
            // Inserir o consumo na tabela principal
            $stmt_leitura->bind_param("sddd", $data_sql, $consumo_vazio, $consumo_cheia, $consumo_ponta);
            $stmt_leitura->execute();
            $leitura_id = $mysqli->insert_id;

            if ($leitura_id > 0) {
                // Inserir os valores acumulados na tabela de histórico
                $stmt_acumulado->bind_param("isddd", $leitura_id, $data_sql, $atual['vazio'], $atual['cheia'], $atual['ponta']);
                $stmt_acumulado->execute();
            } else {
                throw new Exception("Falha ao obter o ID da última leitura inserida.");
            }
        }

        $anterior = $atual; // Atualiza o valor anterior para a próxima iteração
        $count_sucesso++;

        if ($count_sucesso % 1000 == 0) {
            echo "   ... $count_sucesso de $total_a_inserir registos processados.\n";
        }
    }

    $stmt_leitura->close();
    $stmt_acumulado->close();

    if (!$is_dry_run) {
        // Atualizar o estado_anterior com a última leitura do ficheiro
        $ultimo_registo = end($leituras_consolidadas);
        $stmt_update = $mysqli->prepare("UPDATE estado_anterior SET ultimo_vazio_acumulado = ?, ultimo_cheia_acumulado = ?, ultimo_ponta_acumulado = ?, data_atualizacao = NOW() WHERE id = 1");
        $stmt_update->bind_param("ddd", $ultimo_registo['vazio'], $ultimo_registo['cheia'], $ultimo_registo['ponta']);
        $stmt_update->execute();
        $stmt_update->close();
        echo "4. A atualizar o estado final na tabela 'estado_anterior'.\n";

        $mysqli->commit();
    }

    echo "\nSUCESSO: Importação concluída! Foram processados $count_sucesso registos.\n";

} catch (Exception $e) {
    if (!$is_dry_run) {
        $mysqli->rollback();
    }
    die("\nERRO: A importação falhou. Nenhuma alteração foi guardada. Detalhe: " . $e->getMessage() . "\n");
}

$mysqli->close();
echo "--- Fim do Script de Importação ---\n";
?>