<?php
// Ficheiro: api.php (v3.4)
header('Content-Type: application/json');

require_once 'config.php';
require_once 'calculos.php';

// Constantes movidas para calculos.php ou config.php se apropriado, ou mantidas aqui se específicas da API.

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_error) {
    echo json_encode(['error' => 'DB connection failed']);
    exit();
}
$mysqli->set_charset('utf8mb4');

$sensor_pedido = $_GET['sensor'] ?? ($_GET['data'] ?? '');
$output = [];

// --- Lógica de cálculo de datas (agora global para o script) ---
$dia_inicio_ciclo = 16; // Idealmente, viria de uma configuração
if (isset($_GET['ano']) && is_numeric($_GET['ano']) && isset($_GET['mes']) && is_numeric($_GET['mes'])) {
    $ano = (int)$_GET['ano'];
    $mes = (int)$_GET['mes'];
    $data_fim_periodo = new DateTime("$ano-$mes-" . ($dia_inicio_ciclo - 1));
    $data_inicio_periodo = (new DateTime("$ano-$mes-" . $dia_inicio_ciclo))->modify('-1 month');
    $is_current_period = false;
} else {
    $dia_hoje = (int)date('j');
    if ($dia_hoje >= $dia_inicio_ciclo) {
        $data_inicio_periodo = new DateTime(date('Y-m-') . $dia_inicio_ciclo);
        $data_fim_periodo = (new DateTime(date('Y-m-') . ($dia_inicio_ciclo - 1)))->modify('+1 month');
    } else {
        $data_inicio_periodo = (new DateTime(date('Y-m-') . $dia_inicio_ciclo))->modify('-1 month');
        $data_fim_periodo = new DateTime(date('Y-m-') . ($dia_inicio_ciclo - 1));
    }
    $is_current_period = true;
}
$data_inicio_str = $data_inicio_periodo->format('Y-m-d');
// Para queries, usamos a data de hoje se for o período atual, senão a data de fim do ciclo.
$data_fim_query = $is_current_period ? date('Y-m-d') : $data_fim_periodo->format('Y-m-d');


if ($sensor_pedido === 'heatmap') {
    $sql = "SELECT
                WEEKDAY(data_hora) as dia_semana,
                HOUR(data_hora) as hora,
                SUM(consumo_vazio + consumo_cheia + consumo_ponta) / COUNT(DISTINCT DATE(data_hora)) as consumo_medio,
                MAX(data_hora) as data_exemplo
            FROM leituras_energia
            WHERE DATE(data_hora) BETWEEN ? AND ?
            GROUP BY dia_semana, hora
            ORDER BY dia_semana, hora ASC";
    
    $stmt = $mysqli->prepare($sql);
    // Usar $data_fim_query que reflete o dia de hoje para o período atual
    $stmt->bind_param("ss", $data_inicio_str, $data_fim_query);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $dias_semana_map = [0 => 'Segunda', 1 => 'Terça', 2 => 'Quarta', 3 => 'Quinta', 4 => 'Sexta', 5 => 'Sábado', 6 => 'Domingo'];
    $series = [];
    foreach ($dias_semana_map as $dia) {
        $series[$dia] = ['name' => $dia, 'data' => []];
    }

    while ($row = $result->fetch_assoc()) {
        $data_exemplo = new DateTime($row['data_exemplo'], new DateTimeZone('UTC'));
        $periodo_tarifario = obterPeriodoTarifario($data_exemplo);
        // WEEKDAY() do MySQL: 0=Segunda, 1=Terça, ..., 6=Domingo
        $dia_nome = $dias_semana_map[$row['dia_semana']] ?? 'Desconhecido';
        
        $series[$dia_nome]['data'][] = [
            'x' => sprintf('%02d:00', $row['hora']),
            'y' => round($row['consumo_medio'], 2),
            'periodo' => $periodo_tarifario
        ];
    }
        // Inverte a ordem dos dias da semana para corresponder à expectativa do frontend
    $all_series = array_values($series);
    $weekday_series = array_slice($all_series, 0, 5); // Segunda a Sexta
    $weekend_series = array_slice($all_series, 5);    // Sábado e Domingo
    $output = array_merge(array_reverse($weekday_series), $weekend_series);

} else {
    // O switch agora pode usar as datas calculadas acima
    switch ($sensor_pedido) {
        case 'consumo_diario':
            $sql = "SELECT
                        DATE(DATE_SUB(data_hora, INTERVAL 15 DAY)) as dia_ciclo,
                        MIN(DATE(data_hora)) as dia_real,
                        SUM(consumo_vazio) as consumo_vazio,
                        SUM(consumo_cheia) as consumo_cheia,
                        SUM(consumo_ponta) as consumo_ponta
                    FROM leituras_energia
                    WHERE DATE(data_hora) BETWEEN ? AND ?
                    GROUP BY dia_ciclo
                    ORDER BY dia_ciclo ASC";
            
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("ss", $data_inicio_str, $data_fim_query);
            $stmt->execute();
            $result = $stmt->get_result();

            $series = [
                ['name' => 'Vazio', 'data' => []],
                ['name' => 'Cheia', 'data' => []],
                ['name' => 'Ponta', 'data' => []]
            ];
            $categories = [];

            while ($row = $result->fetch_assoc()) {
                $categories[] = (new DateTime($row['dia_real']))->format('d/m');
                $series[0]['data'][] = round($row['consumo_vazio'], 1);
                $series[1]['data'][] = round($row['consumo_cheia'], 1);
                $series[2]['data'][] = round($row['consumo_ponta'], 1);
            }

            $output = ['series' => $series, 'categories' => $categories];
            break;

        // Os outros casos não precisam de datas, mas podem ser adaptados se necessário
        case 'fatura_projetada':
        case 'fatura_atual':
        case 'consumo_kwh_total':
        case 'tendencia_periodo':
            $dados = obterDadosDashboard($mysqli, $_GET);

            // Encontra o ID da tarifa contratada para saber que valor mostrar
            $id_tarifa_contratada = null;
            foreach ($dados['tarifas'] as $id => $tarifa) {
                if ($tarifa['is_contratada']) {
                    $id_tarifa_contratada = $id;
                    break;
                }
            }
            
            if ($sensor_pedido === 'fatura_projetada') {
                $fatura = $dados['faturas_projetadas'][$id_tarifa_contratada] ?? [];
                $output['value'] = round($fatura['total_fatura'] ?? 0, 2);
                $output['unit_of_measurement'] = 'EUR';
            } elseif ($sensor_pedido === 'fatura_atual') {
                $fatura = $dados['faturas_atuais'][$id_tarifa_contratada] ?? [];
                $output['value'] = round($fatura['total_fatura'] ?? 0, 2);
                $output['unit_of_measurement'] = 'EUR';
            } elseif ($sensor_pedido === 'consumo_kwh_total') {
                $output['value'] = round($dados['dados_consumo_atual']['total_kwh'] ?? 0, 1);
                $output['unit_of_measurement'] = 'kWh';
            } elseif ($sensor_pedido === 'tendencia_periodo') {
                $tendencia = 'Estável';
                $dias_registados = $dados['dias_registados_atual'] ?? 0;                
                $custo_consumo_atual = $dados['dados_consumo_atual']['custos_energia'][$id_tarifa_contratada] ?? 0;

                if ($dias_registados > 1) {
                    $media_diaria_consumo = $custo_consumo_atual / $dias_registados;
                    // Obtém os dados de ontem para a mesma tarifa
                    $dados_ontem = obterDadosPeriodo($mysqli, date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day')), $dados['tarifas']);
                    $custo_consumo_ontem = $dados_ontem['totais']['custos_energia'][$id_tarifa_contratada] ?? 0;
                    
                    if ($custo_consumo_ontem > $media_diaria_consumo) {
                        $tendencia = 'A aumentar';
                    } elseif ($custo_consumo_ontem < $media_diaria_consumo) {
                        $tendencia = 'A diminuir';
                    }
                }
                $output['value'] = $tendencia;
                switch ($tendencia) {
                    case 'A aumentar': $output['icon'] = 'mdi:arrow-top-right-thick'; break;
                    case 'A diminuir': $output['icon'] = 'mdi:arrow-bottom-right-thick'; break;
                    default: $output['icon'] = 'mdi:arrow-right-thick'; break;
                }
            }
            break;
        
        case 'monthly_history':
            $result = $mysqli->query("
                SELECT 
                    YEAR(DATE_SUB(data_hora, INTERVAL 15 DAY)) as ano_ciclo, 
                    MONTH(DATE_SUB(data_hora, INTERVAL 15 DAY)) as mes_ciclo,
                    SUM(consumo_vazio) as consumo_vazio,
                    SUM(consumo_cheia + consumo_ponta) as consumo_fora_vazio
                FROM leituras_energia 
                GROUP BY ano_ciclo, mes_ciclo 
                ORDER BY ano_ciclo DESC, mes_ciclo DESC 
                LIMIT 12
            ");

            $data = [];
            while ($row = $result->fetch_assoc()) { $data[] = $row; }
            $data = array_reverse($data); // Inverter para mostrar do mais antigo para o mais recente

            $categories = [];
            $series_vazio = [];
            $series_fora_vazio = [];
            $meses_abrev = ["", "Jan", "Fev", "Mar", "Abr", "Mai", "Jun", "Jul", "Ago", "Set", "Out", "Nov", "Dez"];

            foreach ($data as $row) {
                $categories[] = $meses_abrev[$row['mes_ciclo']] . '/' . substr($row['ano_ciclo'], -2);
                $series_vazio[] = round($row['consumo_vazio'] ?? 0);
                $series_fora_vazio[] = round($row['consumo_fora_vazio'] ?? 0);
            }

            $output = ['categories' => $categories, 'series' => [['name' => 'Fora de Vazio', 'data' => $series_fora_vazio], ['name' => 'Vazio', 'data' => $series_vazio]]];
            break;

        
        default:
            $output['error'] = 'Sensor desconhecido: ' . htmlspecialchars($sensor_pedido);
            http_response_code(400);
            break;
    }
}

$mysqli->close();
echo json_encode($output);
