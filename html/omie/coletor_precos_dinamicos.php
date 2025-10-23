<?php
// Ficheiro: coletor_precos_dinamicos.php
// Responsável por calcular e armazenar os preços horários do tarifário dinâmico.
// Deve ser executado diariamente por um cron job (ex: às 13:00).

require_once 'config.php';

echo "--- Início do Coletor de Preços Dinâmicos ---\n";

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_error) {
    die("ERRO: Falha na ligação à base de dados: " . $mysqli->connect_error . "\n");
}
$mysqli->set_charset('utf8mb4');

// --- Constantes do Tarifário Dinâmico (Coopérnico) ---
const K_MARGEM = 0.009; // Margem da Coopérnico em €/kWh
const GO_GARANTIAS = 0.001; // Garantias de Origem em €/kWh

// 1. Obter preços OMIE para o dia seguinte
$data_amanha = (new DateTime('now', new DateTimeZone('Europe/Lisbon')))->modify('+1 day')->format('d/m/Y');
$comando_omie = "python3 scripts/omie.py -d " . escapeshellarg($data_amanha);

echo "A executar comando para obter preços OMIE para $data_amanha...\n";
$output_omie = shell_exec($comando_omie);

// O script omie.py cria um ficheiro CSV. Precisamos de encontrar o nome do ficheiro.
$nome_ficheiro_omie = "marginalpdbc_" . str_replace('/', '', $data_amanha) . "_" . str_replace('/', '', $data_amanha) . ".csv";

if (!file_exists($nome_ficheiro_omie)) {
    die("ERRO: O ficheiro de preços OMIE '$nome_ficheiro_omie' não foi encontrado. Output do script: $output_omie\n");
}

echo "A processar o ficheiro de preços OMIE: $nome_ficheiro_omie\n";
$precos_omie = [];
$handle = fopen($nome_ficheiro_omie, "r");
if ($handle) {
    while (($line = fgetcsv($handle, 1000, ";")) !== FALSE) {
        // Formato: YYYY;MM;DD;Hour;Price;Price;
        $hora = (int)$line[3] - 1; // OMIE usa 1-24, nós usamos 0-23
        $preco_mwh = (float)str_replace(',', '.', $line[4]);
        $precos_omie[$hora] = $preco_mwh / 1000; // Converter para €/kWh
    }
    fclose($handle);
    unlink($nome_ficheiro_omie); // Apaga o ficheiro CSV após o processamento
} else {
    die("ERRO: Não foi possível abrir o ficheiro de preços OMIE.\n");
}

if (empty($precos_omie)) {
    die("ERRO: Nenhum preço OMIE foi carregado.\n");
}

// 2. Obter Fatores de Perda (FP) para o dia seguinte
echo "A obter fatores de perda da base de dados para $data_amanha...\n";
$data_amanha_sql = (new DateTime('now', new DateTimeZone('Europe/Lisbon')))->modify('+1 day')->format('Y-m-d');
$sql_perdas = "SELECT HOUR(data_hora) as hora, AVG(BT) as media_fp FROM perdas_erse_2024 WHERE DATE(data_hora) = ? GROUP BY hora";
$stmt_perdas = $mysqli->prepare($sql_perdas);
$stmt_perdas->bind_param("s", $data_amanha_sql);
$stmt_perdas->execute();
$result_perdas = $stmt_perdas->get_result();

$fatores_perda = [];
while ($row = $result_perdas->fetch_assoc()) {
    $fatores_perda[$row['hora']] = (float)$row['media_fp'];
}
$stmt_perdas->close();

if (empty($fatores_perda)) {
    die("ERRO: Nenhum fator de perda foi encontrado para a data $data_amanha_sql.\n");
}

// 3. Calcular e Inserir o preço final na BD
echo "A calcular e inserir os preços finais na base de dados...\n";
$stmt_insert = $mysqli->prepare("INSERT INTO precos_dinamicos (data_hora, preco_kwh) VALUES (?, ?) ON DUPLICATE KEY UPDATE preco_kwh = VALUES(preco_kwh)");

$count = 0;
for ($hora = 0; $hora < 24; $hora++) {
    if (isset($precos_omie[$hora]) && isset($fatores_perda[$hora])) {
        $preco_omie = $precos_omie[$hora];
        $fator_perda = $fatores_perda[$hora];

        // Preço Energia (€/kWh) = (OMIE + k + GO) x (1 + FP)
        $preco_final_kwh = ($preco_omie + K_MARGEM + GO_GARANTIAS) * (1 + $fator_perda);

        $data_hora_sql = "$data_amanha_sql " . str_pad($hora, 2, '0', STR_PAD_LEFT) . ":00:00";
        $stmt_insert->bind_param("sd", $data_hora_sql, $preco_final_kwh);
        $stmt_insert->execute();
        $count++;
    }
}
$stmt_insert->close();
$mysqli->close();

echo "SUCESSO: $count preços horários para o tarifário dinâmico foram calculados e guardados para o dia $data_amanha.\n";
echo "--- Fim do Coletor de Preços ---\n";
?>