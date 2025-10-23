<?php
require_once 'config.php';
require_once 'calculos.php';

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_error) { die('Erro de ligação.'); }
$mysqli->set_charset('utf8mb4');

// --- Lógica de Datas (reutilizada de outras páginas) ---
$dia_inicio_ciclo = defined('DIA_INICIO_CICLO') ? DIA_INICIO_CICLO : 16;
if (isset($_GET['ano']) && is_numeric($_GET['ano']) && isset($_GET['mes']) && is_numeric($_GET['mes'])) {
    $ano_selecionado = (int)$_GET['ano'];
    $mes_selecionado = (int)$_GET['mes'];
    $data_fim_periodo = new DateTime("$ano_selecionado-$mes_selecionado-" . ($dia_inicio_ciclo - 1));
    $data_inicio_periodo = (new DateTime("$ano_selecionado-$mes_selecionado-" . $dia_inicio_ciclo))->modify('-1 month');
    $titulo_pagina = "Período de " . $data_inicio_periodo->format('d/m/Y') . " a " . $data_fim_periodo->format('d/m/Y');
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
    $titulo_pagina = "Período Atual";
    $is_current_period = true;
}
$data_inicio_str = $data_inicio_periodo->format('Y-m-d');
$data_fim_str = $data_fim_periodo->format('Y-m-d');

// --- Lógica para o dia selecionado ---
$dia_selecionado_str = $_GET['dia'] ?? null;
if (!$dia_selecionado_str) {
    // Se nenhum dia for especificado, encontra o mais recente com dados no período
    $data_fim_query = $is_current_period ? date('Y-m-d') : $data_fim_str;
    $stmt_latest = $mysqli->prepare("SELECT MAX(DATE(data_hora)) as ultimo_dia FROM precos_energia_dinamicos WHERE DATE(data_hora) BETWEEN ? AND ?");
    $stmt_latest->bind_param("ss", $data_inicio_str, $data_fim_query);
    $stmt_latest->execute();
    $ultimo_dia_db = $stmt_latest->get_result()->fetch_assoc()['ultimo_dia'];
    $stmt_latest->close();
    $dia_selecionado_str = $ultimo_dia_db ?? ($is_current_period ? date('Y-m-d') : $data_fim_str);
}
$dia_selecionado = new DateTime($dia_selecionado_str);


// --- Obter Dados ---
$formulas = [];
$stmt_formulas = $mysqli->prepare("SELECT id, nome_formula FROM formulas_dinamicas WHERE data_inicio <= ? AND (data_fim IS NULL OR data_fim >= ?) ORDER BY nome_formula");
$stmt_formulas->bind_param("ss", $dia_selecionado_str, $dia_selecionado_str);
$stmt_formulas->execute();
$result_formulas = $stmt_formulas->get_result();
while ($row = $result_formulas->fetch_assoc()) {
    $formulas[$row['id']] = $row['nome_formula'];
}
$stmt_formulas->close();

$tarifas_acesso = [];
$stmt_tar = $mysqli->prepare("SELECT * FROM tarifas_acesso_redes WHERE data_inicio <= ? AND (data_fim IS NULL OR data_fim >= ?) ORDER BY data_inicio DESC");
$stmt_tar->bind_param("ss", $dia_selecionado_str, $dia_selecionado_str);
$stmt_tar->execute();
$result_tar = $stmt_tar->get_result();
while ($row = $result_tar->fetch_assoc()) {
    $tarifas_acesso[] = $row;
}
$stmt_tar->close();
// Seleciona a TAR mais relevante para o dia (a que começou mais recentemente)
$tar_aplicavel_dia = !empty($tarifas_acesso) ? $tarifas_acesso[0] : null;

$precos = [];
$stmt_precos = $mysqli->prepare("SELECT data_hora, formula_id, preco_energia FROM precos_energia_dinamicos WHERE DATE(data_hora) = ? ORDER BY data_hora ASC");
$stmt_precos->bind_param("s", $dia_selecionado_str);
$stmt_precos->execute();
$result_precos = $stmt_precos->get_result();
while ($row = $result_precos->fetch_assoc()) {
    $precos[$row['data_hora']][$row['formula_id']] = $row['preco_energia'];
}
$stmt_precos->close();

// Histórico para a sidebar
$periodos_historicos_result = $mysqli->query("SELECT DISTINCT YEAR(data_hora) as ano, MONTH(data_hora) as mes FROM leituras_energia ORDER BY ano DESC, mes DESC");

// --- Obter dias com dados para a navegação ---
$dias_com_dados = [];
$stmt_dias = $mysqli->prepare("SELECT DISTINCT DATE_FORMAT(data_hora, '%Y-%m-%d') as dia FROM precos_energia_dinamicos WHERE DATE(data_hora) BETWEEN ? AND ? ORDER BY dia ASC");
$stmt_dias->bind_param("ss", $data_inicio_str, $data_fim_str);
$stmt_dias->execute();
$result_dias = $stmt_dias->get_result();
while ($row = $result_dias->fetch_assoc()) {
    $dias_com_dados[] = $row['dia'];
}
$stmt_dias->close();

// --- Preparar dados para a tabela e gráfico (por hora) ---
$dados_horarios = [];
$chart_data = [];

for ($hora = 0; $hora < 24; $hora++) {
    $hora_str = str_pad($hora, 2, '0', STR_PAD_LEFT);
    $data_hora_str = "{$dia_selecionado_str} {$hora_str}:00:00";
    $data_obj = new DateTime($data_hora_str, new DateTimeZone('Europe/Lisbon'));
    $periodo_tarifario = obterPeriodoTarifario($data_obj);

    $dados_horarios[$hora]['periodo'] = $periodo_tarifario;

    // Calcular preços TAR para esta hora
    $tar_simples = 0.0;
    $tar_bihorario = 0.0;
    if ($tar_aplicavel_dia) {
        $tar_simples = $tar_aplicavel_dia['preco_simples'] ?? 0.0;
        if ($periodo_tarifario === 'Vazio') $tar_bihorario = $tar_aplicavel_dia['preco_vazio'] ?? 0.0;
        elseif ($periodo_tarifario === 'Cheia') $tar_bihorario = $tar_aplicavel_dia['preco_cheia'] ?? 0.0;
        elseif ($periodo_tarifario === 'Ponta') $tar_bihorario = $tar_aplicavel_dia['preco_ponta'] ?? 0.0;
    }
    $dados_horarios[$hora]['tar_simples'] = $tar_simples;
    $dados_horarios[$hora]['tar_bihorario'] = $tar_bihorario;

    // Preencher preços de energia e finais para cada fórmula
    $precos_hora = $precos[$data_hora_str] ?? [];
    foreach ($formulas as $formula_id => $nome_formula) {
        $preco_energia = $precos_hora[$formula_id] ?? null;
        $dados_horarios[$hora]['formulas'][$formula_id]['energia'] = $preco_energia;
        $dados_horarios[$hora]['formulas'][$formula_id]['final_simples'] = is_null($preco_energia) ? null : $preco_energia + $tar_simples;
        $dados_horarios[$hora]['formulas'][$formula_id]['final_bihorario'] = is_null($preco_energia) ? null : $preco_energia + $tar_bihorario;
    }
}

// Preparar dados para o gráfico (inicialmente com a primeira fórmula e TAR bi-horário)
if (!empty($formulas)) {
    $formula_id_grafico = array_key_first($formulas);
    $chart_data = [
        'formulas' => $formulas,
        'tar_options' => ['simples' => 'TAR Simples', 'bihorario' => 'TAR Bi-Horário'],
        'initial_formula_id' => $formula_id_grafico,
        'initial_tar_option' => 'bihorario',
        'hourly_data' => $dados_horarios
    ];
}

$mysqli->close();
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Preços Dinâmicos Detalhados</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <link rel="stylesheet" href="style.css">
</head>
<body class="bg-gray-100 font-sans flex h-screen">
    <?php 
        $active_page = 'precos_dinamicos';
        require_once 'sidebar.php'; 
    ?>

    <main id="main-content" class="flex-1 p-8 overflow-y-auto">
        <div class="max-w-full mx-auto">
            <h1 class="text-3xl font-bold text-gray-800">Análise de Preços Dinâmicos</h1>
            <h2 class="text-xl text-gray-600 mb-6"><?php echo $titulo_pagina; ?></h2>

            <!-- Navegador de Períodos e Dias -->
            <div class="mb-6 p-4 bg-white rounded-lg shadow-md space-y-4">
                <div class="flex items-center gap-x-4 gap-y-2 flex-wrap">
                    <span class="font-semibold text-gray-700">Ano:</span>
                    <div class="flex items-center gap-2 flex-wrap">
                        <?php
                            $anos_disponiveis = [];
                            $periodos_historicos_result->data_seek(0);
                            while($p = $periodos_historicos_result->fetch_assoc()) { $anos_disponiveis[$p['ano']] = true; }
                            krsort($anos_disponiveis);
                            foreach (array_keys($anos_disponiveis) as $ano):
                        ?>
                            <a href="?ano=<?php echo $ano; ?>" class="<?php echo (!$is_current_period && $ano == $ano_selecionado && !isset($_GET['mes'])) ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-blue-500 hover:text-white'; ?> px-3 py-1 rounded-md text-sm font-semibold transition"><?php echo $ano; ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php if (!$is_current_period && isset($ano_selecionado)): ?>
                <div class="flex items-center gap-x-4 gap-y-2 flex-wrap border-t border-gray-200 pt-4">
                    <span class="font-semibold text-gray-700">Período:</span>
                    <div class="flex items-center gap-2 flex-wrap">
                        <?php 
                            $meses_map = ["", "Jan", "Fev", "Mar", "Abr", "Mai", "Jun", "Jul", "Ago", "Set", "Out", "Nov", "Dez"];
                            for ($m = 1; $m <= 12; $m++):
                                $mes_anterior = ($m === 1) ? 12 : $m - 1;
                                $label_periodo = $meses_map[$mes_anterior] . '-' . $meses_map[$m];
                                $is_active = (isset($mes_selecionado) && $m == $mes_selecionado);
                                $class = $is_active ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-blue-500 hover:text-white';
                        ?>
                            <a href="?ano=<?php echo $ano_selecionado; ?>&mes=<?php echo $m; ?>" class="<?php echo $class; ?> px-3 py-1 rounded-md text-sm font-semibold transition"><?php echo $label_periodo; ?></a>
                        <?php endfor; ?>
                    </div>
                </div>
                <?php endif; ?>
                <div class="flex items-center gap-x-4 gap-y-2 flex-wrap border-t border-gray-200 pt-4">
                    <span class="font-semibold text-gray-700">Dia:</span>
                    <div class="flex items-center gap-1 flex-wrap">
                        <?php
                            foreach ($dias_com_dados as $dia_str_loop):
                                $dia_obj = new DateTime($dia_str_loop);
                                $params = $_GET;
                                $params['dia'] = $dia_str_loop;
                                $query_string = http_build_query($params);
                                $is_active = ($dia_str_loop === $dia_selecionado_str);
                        ?>
                            <a href="?<?php echo $query_string; ?>" class="<?php echo $is_active ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-blue-500 hover:text-white'; ?> w-8 h-8 flex items-center justify-center rounded-md text-sm font-semibold transition"><?php echo $dia_obj->format('d'); ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Gráfico de Preços -->
            <?php if (!empty($chart_data)): ?>
            <div class="bg-white rounded-lg shadow-md p-6 mb-8">
                <div class="flex justify-between items-center mb-4">
                    <h3 id="chart-title" class="text-xl font-bold text-gray-800">Composição do Preço Horário</h3>
                    <div class="flex items-center gap-4">
                        <div>
                            <label for="chart-formula-select" class="text-sm font-medium text-gray-700">Fórmula:</label>
                            <select id="chart-formula-select" class="text-sm rounded-md border-gray-300 shadow-sm">
                                <?php foreach($formulas as $id => $nome): ?>
                                    <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($nome); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="chart-tar-select" class="text-sm font-medium text-gray-700">TAR:</label>
                            <select id="chart-tar-select" class="text-sm rounded-md border-gray-300 shadow-sm">
                                <option value="simples">Simples</option>
                                <option value="bihorario" selected>Bi-Horário</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div id="price-chart"></div>
            </div>
            <?php endif; ?>

            <div class="bg-white shadow-md rounded-lg overflow-hidden">
                <div class="p-6 border-b">
                    <h3 class="text-xl font-bold text-gray-800">Tabela de Preços para <?php echo $dia_selecionado->format('d/m/Y'); ?> (€/kWh)</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full bg-white">
                        <thead class="bg-gray-800 text-white sticky top-0 z-10">
                            <tr>
                                <th class="py-3 px-4 text-left">Hora</th>
                                <th class="py-3 px-4 text-left">Período</th>
                                <?php foreach ($formulas as $nome_formula): ?>
                                    <th class="py-3 px-4 text-right"><?php echo htmlspecialchars($nome_formula); ?> (Energia)</th>
                                <?php endforeach; ?>
                                <th class="py-3 px-4 text-right">TAR Simples</th>
                                <th class="py-3 px-4 text-right">TAR Bi-Horário</th>
                                <?php foreach ($formulas as $nome_formula): ?>
                                    <th class="py-3 px-4 text-right"><?php echo htmlspecialchars($nome_formula); ?> (Final Simples)</th>
                                    <th class="py-3 px-4 text-right"><?php echo htmlspecialchars($nome_formula); ?> (Final Bi-Horário)</th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody class="text-gray-700">
                            <?php if (empty($dados_horarios)): ?>
                                <tr><td colspan="<?php echo 4 + (count($formulas) * 3); ?>" class="text-center py-10 text-gray-500">Sem dados de preços dinâmicos para este dia.</td></tr>
                            <?php else: ?>
                                <?php foreach ($dados_horarios as $hora => $dados):
                                    $periodo_class = '';
                                    if ($dados['periodo'] === 'Ponta') $periodo_class = 'bg-red-100';
                                    elseif ($dados['periodo'] === 'Cheia') $periodo_class = 'bg-yellow-100';
                                    elseif ($dados['periodo'] === 'Vazio') $periodo_class = 'bg-blue-100';
                                ?>
                                <tr class="border-b border-gray-200 hover:bg-gray-50">
                                    <td class="py-2 px-4 font-semibold"><?php echo str_pad($hora, 2, '0', STR_PAD_LEFT) . ':00'; ?></td>
                                    <td class="py-2 px-4 font-semibold <?php echo $periodo_class; ?>"><?php echo $dados['periodo']; ?></td>
                                    <?php foreach ($formulas as $formula_id => $nome): ?>
                                        <td class="py-2 px-4 text-right tabular-nums"><?php echo is_null($dados['formulas'][$formula_id]['energia']) ? '-' : number_format($dados['formulas'][$formula_id]['energia'], 5, ',', '.'); ?></td>
                                    <?php endforeach; ?>
                                    <td class="py-2 px-4 text-right tabular-nums bg-gray-100"><?php echo number_format($dados['tar_simples'], 5, ',', '.'); ?></td>
                                    <td class="py-2 px-4 text-right tabular-nums bg-gray-100"><?php echo number_format($dados['tar_bihorario'], 5, ',', '.'); ?></td>
                                    <?php foreach ($formulas as $formula_id => $nome): ?>
                                        <td class="py-2 px-4 text-right tabular-nums font-bold"><?php echo is_null($dados['formulas'][$formula_id]['final_simples']) ? '-' : number_format($dados['formulas'][$formula_id]['final_simples'], 5, ',', '.'); ?></td>
                                        <td class="py-2 px-4 text-right tabular-nums font-bold"><?php echo is_null($dados['formulas'][$formula_id]['final_bihorario']) ? '-' : number_format($dados['formulas'][$formula_id]['final_bihorario'], 5, ',', '.'); ?></td>
                                    <?php endforeach; ?>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const pageData = <?php echo json_encode($chart_data); ?>;
        let chart;

        function renderChart(formulaId, tarOption) {
            if (!pageData || !pageData.hourly_data) return;

            const formulaName = pageData.formulas[formulaId] || 'Desconhecida';
            const tarName = pageData.tar_options[tarOption] || 'Desconhecida';
            const title = `Composição do Preço (${formulaName} + ${tarName})`;

            const categories = Object.keys(pageData.hourly_data).map(h => String(h).padStart(2, '0') + ':00');
            const seriesEnergia = [];
            const seriesTar = [];

            for (let hora = 0; hora < 24; hora++) {
                const horaData = pageData.hourly_data[hora];
                if (horaData) {
                    seriesEnergia.push(horaData.formulas[formulaId]?.energia || 0);
                    seriesTar.push(horaData['tar_' + tarOption] || 0);
                } else {
                    seriesEnergia.push(0);
                    seriesTar.push(0);
                }
            }

            const options = {
                series: [
                    { name: 'Energia (OMIE + Margem)', data: seriesEnergia },
                    { name: 'Tarifa de Acesso (TAR)', data: seriesTar }
                ],
                chart: {
                    type: 'bar',
                    height: 450,
                    stacked: true,
                    toolbar: { show: true }
                },
                title: { text: title, align: 'left', style: { fontSize: '16px', fontWeight: 'bold' } },
                xaxis: {
                    categories: categories,
                    labels: { rotate: -45, style: { fontSize: '10px' } }
                },
                yaxis: {
                    title: { text: 'Preço (€/kWh)' },
                    labels: { formatter: (val) => val.toFixed(5) },
                    max: 0.35
                },
                colors: ['#3B82F6', '#F59E0B'],
                legend: { position: 'top', horizontalAlign: 'right' },
                fill: { opacity: 1 },
                tooltip: { y: { formatter: (val) => "€ " + val.toFixed(5) } },
                dataLabels: { enabled: false }
            };

            if (chart) {
                chart.updateOptions(options);
            } else {
                chart = new ApexCharts(document.querySelector("#price-chart"), options);
                chart.render();
            }
        }

        // Initial render
        if (pageData && pageData.initial_formula_id) {
            renderChart(pageData.initial_formula_id, pageData.initial_tar_option);
        }

        // Event Listeners
        document.getElementById('chart-formula-select')?.addEventListener('change', (e) => {
            const tarOption = document.getElementById('chart-tar-select').value;
            renderChart(e.target.value, tarOption);
        });

        document.getElementById('chart-tar-select')?.addEventListener('change', (e) => {
            const formulaId = document.getElementById('chart-formula-select').value;
            renderChart(formulaId, e.target.value);
        });
    });
    </script>
    <script src="main.js"></script>
</body>
</html>