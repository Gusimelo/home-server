<?php
// Incluir ficheiros de configuração e de lógica
require_once 'config.php';
require_once 'calculos.php';


// UMA ÚNICA CHAMADA FAZ TODO O TRABALHO
$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_error) { die('Erro de ligação.'); }
$mysqli->set_charset('utf8mb4');
$dados = obterDadosDashboard($mysqli, $_GET);
$mysqli->close();

extract($dados);
$fuso_horario_local = new DateTimeZone('Europe/Lisbon');
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Energético v4.0</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
</head>
<body class="bg-gray-100 font-sans flex h-screen">    
    <?php 
        $active_page = 'index';
        require_once 'sidebar.php'; 
    ?>

    <main class="flex-1 p-8 overflow-y-auto">
        <div class="max-w-7xl mx-auto">
            <h1 class="text-3xl font-bold text-gray-800">Dashboard Energético</h1>
            <h2 class="text-xl text-gray-600 mb-6"><?php echo $titulo_pagina; ?></h2>
            
            <div class="bg-white rounded-lg shadow-md overflow-hidden mb-8">
                <div class="p-6">
                    <h3 class="text-xl font-bold mb-4">Comparativo de Fatura (€)</h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-100">
                            <?php
                                // Encontra o ID da tarifa projetada mais barata
                                $id_tarifa_mais_barata_proj = null;
                                if ($is_current_period && !empty($faturas_projetadas)) {
                                    $min_fatura_proj = PHP_FLOAT_MAX;
                                    foreach ($faturas_projetadas as $id => $fatura) {
                                        if (isset($fatura['total_fatura']) && $fatura['total_fatura'] < $min_fatura_proj) {
                                            $min_fatura_proj = $fatura['total_fatura'];
                                            $id_tarifa_mais_barata_proj = $id;
                                        }
                                    }
                                }
                            ?>
                            <tr>
                                <th class="py-2 px-3 text-left font-semibold text-gray-600 w-1/4">Componente</th>                                
                                <?php foreach ($tarifas as $tarifa): ?>
                                    <th class="py-2 px-3 text-right font-semibold text-gray-600"><?php echo htmlspecialchars($tarifa['nome_tarifa']); ?></th>
                                <?php endforeach; ?>

                                <?php if ($is_current_period): foreach ($tarifas as $id => $tarifa): ?>
                                    <th class="py-2 px-3 text-right font-semibold text-blue-600"><?php echo htmlspecialchars($tarifa['nome_tarifa']); ?> (Proj.) <?php if ($id === $id_tarifa_mais_barata_proj): ?><span class="text-yellow-400" title="Opção mais económica (projetado)">⭐</span><?php endif; ?></th>
                                <?php endforeach; endif; ?>
                            </tr>
                        </thead>
                        <tbody class="text-gray-800">
                            <?php 
                                $componentes = [
                                    'energia_custo_base' => 'Energia (Consumo)',
                                    'potencia_custo_base' => 'Potência Contratada',
                                    'taxas_custo_base' => 'Taxas (IECE, TS)',
                                    'cav_custo_base' => 'Audiovisual',
                                    'base_iva_normal' => 'Base IVA (23%)',
                                    'base_iva_reduzido' => 'Base IVA (6%)',
                                    'total_iva_normal' => 'IVA (23%)',
                                    'total_iva_reduzido' => 'IVA (6%)',
                                    'total_fatura' => 'Total'
                                ];
                                
                                foreach ($componentes as $key => $label):
                                    $is_total = ($key === 'total_fatura');
                                    $row_class = $is_total ? 'bg-gray-50' : '';
                                    $text_class = $is_total ? 'font-bold text-base' : 'font-medium';
                            ?>
                            <tr class="<?php echo $row_class; ?>">
                                <td class="py-3 px-3 <?php echo $text_class; ?> text-gray-800"><?php echo $label; ?></td>
                                <?php foreach ($tarifas as $id => $tarifa): 
                                    $fatura = $faturas_atuais[$id] ?? [];
                                    $valor = ($key === 'taxas_custo_base') 
                                        ? ($fatura['iece_custo_base'] ?? 0) + ($fatura['ts_custo_base'] ?? 0)
                                        : ($fatura[$key] ?? 0);
                                    $cell_class = $tarifa['is_contratada'] ? 'font-bold' : '';
                                ?>
                                    <td class="py-3 px-3 text-right tabular-nums <?php echo $cell_class; ?>">&euro; <?php echo number_format($valor, 2, ',', '.'); ?></td>
                                <?php endforeach; ?>

                                <?php if ($is_current_period): foreach ($tarifas as $id => $tarifa):
                                    $fatura_proj = $faturas_projetadas[$id] ?? [];
                                    $valor_proj = ($key === 'taxas_custo_base') 
                                        ? ($fatura_proj['iece_custo_base'] ?? 0) + ($fatura_proj['ts_custo_base'] ?? 0)
                                        : ($fatura_proj[$key] ?? 0);
                                    $cell_class_proj = $tarifa['is_contratada'] ? 'font-bold' : '';
                                ?>
                                    <td class="py-3 px-3 text-right text-blue-600 tabular-nums <?php echo $cell_class_proj; ?>">&euro; <?php echo number_format($valor_proj, 2, ',', '.'); ?></td>
                                <?php endforeach; endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-8">
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h3 class="text-xl font-bold mb-4">Resumo de Consumo (kWh)</h3>
                    <table class="min-w-full text-sm">
                        <thead class="border-b-2 border-gray-200"><tr><th class="py-2 px-3 text-left font-semibold text-gray-600">Tarifa</th><th class="py-2 px-3 text-right font-semibold text-gray-600">Atual</th><?php if ($is_current_period): ?><th class="py-2 px-3 text-right font-semibold text-blue-600">Projetado</th><?php endif; ?></tr></thead>
                        <tbody class="text-gray-800">
                            <tr>
                                <td class="py-3 px-3 font-medium">Vazio</td>
                                <td class="py-3 px-3 text-right tabular-nums"><?php echo number_format($dados_consumo_atual['consumo_vazio'] ?? 0, 1, ',', '.'); ?></td>
                                <?php if ($is_current_period): ?>
                                    <td class="py-3 px-3 text-right text-blue-600 tabular-nums"><?php echo number_format(($dados_consumo_atual['consumo_vazio'] ?? 0) + ($consumo_projetado['consumo_vazio'] ?? 0), 1, ',', '.'); ?></td>
                                <?php endif; ?>
                            </tr>
                            <tr>
                                <td class="py-3 px-3 font-medium">Cheia</td>
                                <td class="py-3 px-3 text-right tabular-nums"><?php echo number_format($dados_consumo_atual['consumo_cheia'] ?? 0, 1, ',', '.'); ?></td>
                                <?php if ($is_current_period): ?>
                                    <td class="py-3 px-3 text-right text-blue-600 tabular-nums"><?php echo number_format(($dados_consumo_atual['consumo_cheia'] ?? 0) + ($consumo_projetado['consumo_cheia'] ?? 0), 1, ',', '.'); ?></td>
                                <?php endif; ?>
                            </tr>
                            <tr>
                                <td class="py-3 px-3 font-medium">Ponta</td>
                                <td class="py-3 px-3 text-right tabular-nums"><?php echo number_format($dados_consumo_atual['consumo_ponta'] ?? 0, 1, ',', '.'); ?></td>
                                <?php if ($is_current_period): ?>
                                    <td class="py-3 px-3 text-right text-blue-600 tabular-nums"><?php echo number_format(($dados_consumo_atual['consumo_ponta'] ?? 0) + ($consumo_projetado['consumo_ponta'] ?? 0), 1, ',', '.'); ?></td>
                                <?php endif; ?>
                            </tr>
                            <tr class="bg-gray-50">
                                <td class="py-3 px-3 font-bold text-base">Total</td>
                                <td class="py-3 px-3 text-right font-bold text-base tabular-nums"><?php echo number_format($dados_consumo_atual['total_kwh'] ?? 0, 1, ',', '.'); ?></td>
                                <?php if ($is_current_period): ?>
                                    <?php
                                        // Calcula o total projetado somando as projeções parciais para garantir consistência.
                                        $total_atual = $dados_consumo_atual['total_kwh'] ?? 0;
                                        $total_futuro = ($consumo_projetado['consumo_vazio'] ?? 0) + ($consumo_projetado['consumo_cheia'] ?? 0) + ($consumo_projetado['consumo_ponta'] ?? 0);
                                    ?>
                                    <td class="py-3 px-3 text-right font-bold text-base text-blue-600 tabular-nums"><?php echo number_format($total_atual + $total_futuro, 1, ',', '.'); ?></td>
                                <?php endif; ?>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="bg-white rounded-lg shadow-md p-6 flex flex-col justify-center items-center">
                    <h3 class="text-xl font-bold mb-4">Ações Rápidas</h3>
                    <button id="openModalButton" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded-lg shadow-md">
                        Ver Registos Detalhados do Período
                    </button>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6 mb-8">
                <h3 class="text-xl font-bold mb-4">Consumo Diário (kWh)</h3>
                <div id="daily-consumption-chart"></div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <h3 class="text-xl font-bold mb-4">Padrões de Consumo (kWh por Hora/Dia)</h3>
                
                <div class="flex justify-end gap-6 mb-[-15px] pr-5 relative z-10">
                    <div class="flex items-center gap-2 text-xs">
                        <span class="w-4 h-4 rounded-sm bg-blue-300"></span>
                        <span>Período Vazio</span>
                    </div>
                    <div class="flex items-center gap-2 text-xs">
                        <span class="w-4 h-4 rounded-sm bg-red-300"></span>
                        <span>Período Fora de Vazio</span>
                    </div>
                </div>

                <div id="heatmap-weekday" style="margin-top: 20px;"></div>
                <div id="heatmap-saturday" style="margin-top: 20px;"></div>
                <div id="heatmap-sunday" style="margin-top: 20px;"></div>
            </div>
        </div>
    </main>
    
    <div id="modal-registos" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center p-4 z-50">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-6xl max-h-[90vh] flex flex-col">
            <div class="p-6 border-b flex justify-between items-center">
                <h2 class="text-xl font-bold">Registos do Período</h2>
                <button id="closeModalButton" class="text-gray-400 hover:text-gray-800 text-3xl leading-none font-bold">&times;</button>
            </div>
            <div class="p-6 flex-grow overflow-y-auto">
                <button id="toggleZeroButton" class="mb-4 bg-gray-200 hover:bg-gray-300 text-gray-800 font-bold py-2 px-4 rounded">Mostrar Registos a Zero</button>
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-800 text-white sticky top-0">
                        <tr>
                            <th class="py-2 px-3 text-left">Data e Hora (Local)</th>
                            <th class="py-2 px-3 text-right">Consumo (kWh)</th>
                            <?php foreach ($tarifas as $tarifa): ?>
                                <th class="py-2 px-3 text-right">Custo <?php echo htmlspecialchars($tarifa['nome_tarifa']); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody class="text-gray-800">
                        <?php
                            if (isset($ultimos_registos_result)) {
                                $ultimos_registos_result->data_seek(0); // Reset pointer
                                while ($registo = $ultimos_registos_result->fetch_assoc()):

                                $consumo_intervalo = $registo['consumo_vazio'] + $registo['consumo_cheia'] + $registo['consumo_ponta'];
                                $classe_linha = ($consumo_intervalo < 0.00001) ? 'linha-zero linha-escondida' : '';
                        ?>
                                <tr class="border-b border-gray-200 hover:bg-gray-100 <?php echo $classe_linha; ?>">
                                    <td class="py-2 px-3"><?php
                                        $data_obj = new DateTime($registo['data_hora'], new DateTimeZone('UTC'));
                                        $data_obj->setTimezone($fuso_horario_local);
                                        echo $data_obj->format('d/m/Y H:i:s');
                                    ?></td>
                                    <td class="py-2 px-3 text-right tabular-nums"><?php echo number_format($consumo_intervalo, 5, ',', '.'); ?></td>
                                    <?php foreach ($tarifas as $id => $tarifa): ?>
                                        <?php
                                            $custo_leitura = 0;
                                            if ($tarifa['modalidade'] === 'simples') {
                                                $custo_leitura = $consumo_intervalo * $tarifa['preco_simples'];
                                            } elseif ($tarifa['modalidade'] !== 'simples' && $tarifa['modalidade'] !== 'dinamico') {
                                                $custo_leitura = ($registo['consumo_vazio'] * $tarifa['preco_vazio']) + ($registo['consumo_cheia'] * $tarifa['preco_cheia']) + ($registo['consumo_ponta'] * $tarifa['preco_ponta']);
                                            }
                                        ?>
                                        <td class="py-2 px-3 text-right tabular-nums"><?php echo number_format($custo_leitura, 5, ',', '.'); ?></td>
                                    <?php endforeach; ?>
                                </tr>
                        <?php
                                endwhile;
                            }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Lógica do Modal e Botão de Filtro
        const modal = document.getElementById('modal-registos');
        const openBtn = document.getElementById('openModalButton'); // ok
        const closeBtn = document.getElementById('closeModalButton'); // ok
        if(openBtn) { openBtn.onclick = function() { modal.classList.remove('hidden'); modal.classList.add('flex'); } }
        if(closeBtn) { closeBtn.onclick = function() { modal.classList.add('hidden'); modal.classList.remove('flex'); } }
        window.onclick = function(event) { if (event.target == modal) { modal.classList.add('hidden'); modal.classList.remove('flex'); } }
        const toggleButton = document.getElementById('toggleZeroButton');
        if (toggleButton) {
            let zerosEstaoVisiveis = false;
            toggleButton.addEventListener('click', function() {
                zerosEstaoVisiveis = !zerosEstaoVisiveis;
                document.querySelectorAll('tr.linha-zero').forEach(row => row.classList.toggle('hidden'));
                toggleButton.textContent = zerosEstaoVisiveis ? 'Ocultar Registos a Zero' : 'Mostrar Registos a Zero';
            });
        }

        const urlParams = new URLSearchParams(window.location.search);
        const ano = urlParams.get('ano');
        const mes = urlParams.get('mes');

        // Lógica dos Gráficos Heatmap
        let apiUrl = 'api.php?data=heatmap';
        if (ano && mes) { apiUrl += `&ano=${ano}&mes=${mes}`; }

        fetch(apiUrl)
            .then(response => response.json())
            .then(seriesData => {
                const weekdaySeries = seriesData.filter(s => ['Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta'].includes(s.name));
                const saturdaySeries = seriesData.filter(s => s.name === 'Sábado');
                const sundaySeries = seriesData.filter(s => s.name === 'Domingo');

                const hours = Array.from({length: 24}, (_, i) => `${String(i).padStart(2, '0')}:00`);

                const baseOptions = {
                    chart: { type: 'heatmap', toolbar: { show: false }, margin: { top: 30, bottom: 40 } },
                    plotOptions: { 
                        heatmap: { 
                            shadeIntensity: 0.7, 
                            radius: 0, 
                            useFillColorAsStroke: false, 
                            reverseNegativeShade: true, 
                            enableShades: true,
                            colorScale: {
                                ranges: [{
                                    from: 0.0,
                                    to: 0.5,
                                    name: 'baixo',
                                color: '#aed6f1' // Azul Pastel mais claro
                                }, {
                                    from: 0.5,
                                    to: 5.0, // Um valor alto para abranger picos
                                    name: 'alto',
                                color: '#f5b7b1' // Vermelho Pastel
                                }]
                            }
                        } 
                    },
                    dataLabels: { enabled: false },
                    stroke: { width: 1, colors: ['#fff'] },
                    xaxis: { type: 'category', categories: hours, labels: { show: true, rotate: -90, rotateAlways: true, style: { fontSize: '10px' } }, tickPlacement: 'on' },
                    tooltip: { y: { formatter: (value, { series, seriesIndex, dataPointIndex, w }) => {
                        const point = w.globals.initialSeries[seriesIndex].data[dataPointIndex];
                        return point && point.periodo ? `${value.toFixed(2)} kWh (${point.periodo})` : `${value.toFixed(2)} kWh`;
                    }}}
                };

                function isSummerTime(date) {
                    const year = date.getFullYear();
                    const summerStart = new Date(year, 2, 31);
                    summerStart.setDate(31 - summerStart.getDay());
                    const summerEnd = new Date(year, 9, 31);
                    summerEnd.setDate(31 - summerEnd.getDay());
                    return date >= summerStart && date < summerEnd;
                }
                const hoje = new Date();
                const horarioVerao = isSummerTime(hoje);

                // --- ESTILOS PARA AS ANOTAÇÕES (v3.3) ---
                const estiloLinha = { borderWidth: 1, opacity: 0.9, strokeDashArray: 4 };
                const estiloLabelBase = { orientation: 'horizontal', style: { fontSize: '11px', padding: { left: 5, right: 5, top: 2, bottom: 2 } } };
                
                const estiloCheia = {
                    linha: { ...estiloLinha, borderColor: '#f5b7b1' }, // Vermelho Pastel
                    label: { ...estiloLabelBase, borderColor: '#f5b7b1', style: { ...estiloLabelBase.style, color: '#fff', background: '#f5b7b1' } }
                };

                const estiloVazio = {
                    linha: { ...estiloLinha, borderColor: '#aed6f1' }, // Azul Pastel
                    label: { ...estiloLabelBase, borderColor: '#aed6f1', style: { ...estiloLabelBase.style, color: '#fff', background: '#aed6f1' } }
                };

                // --- GRÁFICO 1: DIAS DE SEMANA ---
                const weekdayChartEl = document.querySelector("#heatmap-weekday");
                if (weekdayChartEl && weekdaySeries.some(s => s.data.length > 0)) {
                    const weekdayAnnotations = { xaxis: [
                        { x: '00:00', ...estiloVazio.linha, label: { ...estiloVazio.label, position: 'top', offsetY: -12, text: '$ →' } },
                        { x: '07:00', ...estiloCheia.linha, label: { ...estiloCheia.label, position: 'top', offsetY: -12, text: '$$ →' } }
                    ]};
                    const weekdayOptions = { ...baseOptions, series: weekdaySeries, chart: { ...baseOptions.chart, height: 250 }, annotations: weekdayAnnotations, title: { text: 'Segunda a Sexta', align: 'left', style: { fontSize: '16px' } } };
                    new ApexCharts(weekdayChartEl, weekdayOptions).render();
                } else if(weekdayChartEl) { weekdayChartEl.innerHTML = "<p style='text-align:center; padding: 20px;'>Sem dados.</p>"; }

                // --- GRÁFICO 2: SÁBADO ---
                const saturdayChartEl = document.querySelector("#heatmap-saturday");
                if (saturdayChartEl && saturdaySeries.some(s => s.data.length > 0)) {
                    let saturdayAnnotations = { xaxis: [
                        { x: '00:00', ...estiloVazio.linha, label: { ...estiloVazio.label, position: 'top', offsetY: -12, text: '$ →' } }
                    ] };
                    if (horarioVerao) {
                        saturdayAnnotations.xaxis.push({ x: '09:00', ...estiloCheia.linha, label: { ...estiloCheia.label, position: 'top', offsetY: -12, text: '$$ →' } });
                        saturdayAnnotations.xaxis.push({ x: '14:00', ...estiloVazio.linha, label: { ...estiloVazio.label, position: 'top', offsetY: -12, text: '$ →' } });
                        saturdayAnnotations.xaxis.push({ x: '20:00', ...estiloCheia.linha, label: { ...estiloCheia.label, position: 'top', offsetY: -12, text: '$$ →' } });
                        saturdayAnnotations.xaxis.push({ x: '22:00', ...estiloVazio.linha, label: { ...estiloVazio.label, position: 'top', offsetY: -12, text: '$ →' } });
                    } else { // Horário de Inverno
                        saturdayAnnotations.xaxis.push({ x: '09:30', ...estiloCheia.linha, label: { ...estiloCheia.label, position: 'top', offsetY: -12, text: '$$ →' } });
                        saturdayAnnotations.xaxis.push({ x: '13:00', ...estiloVazio.linha, label: { ...estiloVazio.label, position: 'top', offsetY: -12, text: '$ →' } });
                        saturdayAnnotations.xaxis.push({ x: '18:30', ...estiloCheia.linha, label: { ...estiloCheia.label, position: 'top', offsetY: -12, text: '$$ →' } });
                        saturdayAnnotations.xaxis.push({ x: '22:00', ...estiloVazio.linha, label: { ...estiloVazio.label, position: 'top', offsetY: -12, text: '$ →' } });
                    }
                    const saturdayOptions = { ...baseOptions, series: saturdaySeries, chart: { ...baseOptions.chart, height: 120 }, annotations: saturdayAnnotations, title: { text: 'Sábado', align: 'left', style: { fontSize: '16px' } } };
                    new ApexCharts(saturdayChartEl, saturdayOptions).render();
                } else if(saturdayChartEl) { saturdayChartEl.innerHTML = "<p style='text-align:center; padding: 20px;'>Sem dados para Sábado.</p>"; }

                // --- GRÁFICO 3: DOMINGO ---
                const sundayChartEl = document.querySelector("#heatmap-sunday");
                if (sundayChartEl && sundaySeries.some(s => s.data.length > 0)) {
                    const sundayAnnotations = { xaxis: [
                        { x: '00:00', ...estiloVazio.linha, label: { ...estiloVazio.label, position: 'top', offsetY: -12, text: '$ →' } }
                    ]};
                    const sundayOptions = { ...baseOptions, series: sundaySeries, chart: { ...baseOptions.chart, height: 120 }, annotations: sundayAnnotations, title: { text: 'Domingo (100% Vazio)', align: 'left', style: { fontSize: '16px' } } };
                    new ApexCharts(sundayChartEl, sundayOptions).render();
                } else if(sundayChartEl) { sundayChartEl.innerHTML = "<p style='text-align:center; padding: 20px;'>Sem dados para Domingo.</p>"; }
            })
            .catch(error => { console.error('Erro ao carregar dados para os heatmaps:', error); });

        // Gráfico de Consumo Diário
        let dailyApiUrl = 'api.php?sensor=consumo_diario';
        if (ano && mes) { dailyApiUrl += `&ano=${ano}&mes=${mes}`; }

        fetch(dailyApiUrl)
            .then(response => response.json())
            .then(data => {
                const dailyChartEl = document.querySelector("#daily-consumption-chart");
                if (dailyChartEl && data.series && data.categories && data.series.some(s => s.data.length > 0)) {
                    var options = {
                        series: data.series,
                        chart: {
                            type: 'bar',
                            height: 350,
                            stacked: true,
                            toolbar: { show: false }
                        },
                        plotOptions: { bar: { horizontal: false, } },
                        xaxis: {
                            categories: data.categories,
                            labels: { rotate: -45, style: { fontSize: '10px' } }
                        },
                        yaxis: { title: { text: 'Consumo (kWh)' } },
                        colors: ['#85C1E9', '#F1C40F', '#E74C3C'], // Azul (Vazio), Amarelo (Cheia), Vermelho (Ponta)
                        legend: { position: 'top', horizontalAlign: 'left' },
                        fill: { opacity: 1 },
                        tooltip: { y: { formatter: (val) => val.toFixed(1) + " kWh" } }
                    };
                    new ApexCharts(dailyChartEl, options).render();
                } else if(dailyChartEl) {
                    dailyChartEl.innerHTML = "<p style='text-align:center; padding: 20px;'>Sem dados para o gráfico de consumo diário.</p>";
                }
            })
            .catch(error => {
                console.error('Erro ao carregar dados para o gráfico de consumo diário:', error);
                const dailyChartEl = document.querySelector("#daily-consumption-chart");
                if(dailyChartEl) dailyChartEl.innerHTML = "<p style='text-align:center; padding: 20px;'>Erro ao carregar dados.</p>";
            });
    });
    </script>
</body>
</html>