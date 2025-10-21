<?php
// Ficheiro: coletor.php (v2.0)
require_once 'config.php';
require_once 'calculos.php'; // Incluído para usar obterPeriodoTarifario

function get_ha_state($url, $token, $entity_id) {
    $ch = curl_init($url . '/api/states/' . $entity_id);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Aumentado para 10 segundos

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        echo "ERRO cURL ao contactar Home Assistant para a entidade '$entity_id': $curl_error\n";
        return null;
    }

    if ($http_code !== 200) {
        echo "ERRO HTTP $http_code ao obter estado para '$entity_id'. Resposta: $response\n";
        if ($http_code === 401) {
            echo "   -> O erro 401 (Unauthorized) indica que o seu TOKEN de acesso (HA_TOKEN) é inválido ou expirou. Verifique o ficheiro config.php e gere um novo token no Home Assistant se necessário.\n";
        }
        return null;
    }

    $data = json_decode($response, true);
    if (!isset($data['state']) || !is_numeric($data['state'])) {
        echo "Aviso: Resposta inválida ou não numérica do Home Assistant para a entidade '$entity_id'.\n";
        return null;
    }
    return (float) $data['state'];
}

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_error) { die('Erro de ligação: ' . $mysqli->connect_error); }

// 2. OBTER LEITURAS ATUAIS (ACUMULADAS) DA API
$atual_vazio = get_ha_state(HA_URL, HA_TOKEN, HA_ENTITY_VAZIO);
$atual_cheia = get_ha_state(HA_URL, HA_TOKEN, HA_ENTITY_CHEIA);
$atual_ponta = get_ha_state(HA_URL, HA_TOKEN, HA_ENTITY_PONTA);

if ($atual_vazio === null || $atual_cheia === null || $atual_ponta === null) { die("Erro ao obter dados de consumo do Home Assistant."); }

// 3. OBTER ÚLTIMAS LEITURAS (ACUMULADAS) DA BD E ATUALIZAR
$result = $mysqli->query("SELECT ultimo_vazio_acumulado, ultimo_cheia_acumulado, ultimo_ponta_acumulado FROM estado_anterior WHERE id = 1");
$anterior = $result->fetch_assoc();
$result->free();

$is_first_run = ($anterior['ultimo_vazio_acumulado'] == 0 && $anterior['ultimo_cheia_acumulado'] == 0 && $anterior['ultimo_ponta_acumulado'] == 0);
$stmt_update = $mysqli->prepare("UPDATE estado_anterior SET ultimo_vazio_acumulado = ?, ultimo_cheia_acumulado = ?, ultimo_ponta_acumulado = ?, data_atualizacao = NOW() WHERE id = 1");
$stmt_update->bind_param("ddd", $atual_vazio, $atual_cheia, $atual_ponta);
$stmt_update->execute();
$stmt_update->close();
if ($is_first_run) { $mysqli->close(); echo "Primeira execução. Estado inicial guardado."; exit(); }

// 4. CALCULAR O CONSUMO DO INTERVALO
$consumo_vazio = max(0, $atual_vazio - $anterior['ultimo_vazio_acumulado']);
$consumo_cheia = max(0, $atual_cheia - $anterior['ultimo_cheia_acumulado']);
$consumo_ponta = max(0, $atual_ponta - $anterior['ultimo_ponta_acumulado']);
$consumo_total_intervalo = $consumo_vazio + $consumo_cheia + $consumo_ponta;

// 5. INSERIR O REGISTO DE CONSUMO
$stmt_leitura = $mysqli->prepare("INSERT INTO leituras_energia (consumo_vazio, consumo_cheia, consumo_ponta) VALUES (?, ?, ?)");
$stmt_leitura->bind_param("ddd", $consumo_vazio, $consumo_cheia, $consumo_ponta);
$stmt_leitura->execute();
if ($stmt_leitura->error) { die("Erro ao inserir registo de leitura: " . $stmt_leitura->error); }
$leitura_id = $mysqli->insert_id;
$stmt_leitura->close();

// 6. INSERIR OS VALORES ACUMULADOS NA NOVA TABELA
$data_agora_sql = date('Y-m-d H:i:s');
$stmt_acumulado = $mysqli->prepare("INSERT INTO leituras_acumuladas (leitura_id, data_hora, acumulado_vazio, acumulado_cheia, acumulado_ponta) VALUES (?, ?, ?, ?, ?)");
$stmt_acumulado->bind_param("isddd", $leitura_id, $data_agora_sql, $atual_vazio, $atual_cheia, $atual_ponta);
$stmt_acumulado->execute();
if ($stmt_acumulado->error) {
    // Se falhar, não é crítico para o resto do script, mas regista o erro.
    echo "Aviso: Erro ao inserir registo de leitura acumulada: " . $stmt_acumulado->error;
}
$stmt_acumulado->close();

$mysqli->close();
echo "Registo de consumo #$leitura_id inserido com sucesso.";
?>
