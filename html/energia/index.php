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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
</head>
<body>
    <nav class="sidebar">
        <h3>Navegação</h3>
        <ul>
            <li><a href="index.php" class="active">Dashboard</a></li>
            <li><a href="fatura_detalhada.php">Fatura Detalhada</a></li>
            <li><a href="ofertas.php">Comparar Ofertas</a></li>
            <li><a href="tarifas.php">Gerir Tarifas</a></li>
        </ul>
        <h3>Histórico</h3>
        <ul>
            <li><a href="index.php" class="<?php echo $is_current_period ? 'active' : '' ?>">Período Atual</a></li>
        </ul>
        <?php
            $current_year = null;
            $meses = ["", "Janeiro", "Fevereiro", "Março", "Abril", "Maio", "Junho", "Julho", "Agosto", "Setembro", "Outubro", "Novembro", "Dezembro"];
            if (isset($periodos_historicos_result)) {
                while ($periodo = $periodos_historicos_result->fetch_assoc()) {
                    if ($periodo['ano'] != $current_year) {
                        if ($current_year !== null) echo '</ul>';
                        $current_year = $periodo['ano'];
                        echo "<h3>{$current_year}</h3><ul>";
                    }
                    $is_active = (!$is_current_period && isset($ano_selecionado) && $periodo['ano'] == $ano_selecionado && $periodo['mes'] == $mes_selecionado);
                    echo '<li><a href="?ano='.$periodo['ano'].'&mes='.$periodo['mes'].'" class="'.($is_active ? 'active' : '').'">'.$meses[$periodo['mes']].'</a></li>';
                }
                if ($current_year !== null) echo '</ul>';
            }
        ?>
    </nav>

    <main class="main-content">
        <div class="container">
            <h1>Dashboard Energético</h1>
            <h2><?php echo $titulo_pagina; ?></h2>
            
            <div class="summary-grid-full">
                <div class="summary-box">
                    <h3>Comparativo de Fatura (€)</h3>
                    <table>
                        <thead>
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
                                <th class="label-col">Componente</th>                                
                                <?php foreach ($tarifas as $tarifa): ?>
                                    <th class="numeric"><?php echo htmlspecialchars($tarifa['nome_tarifa']); ?></th>
                                <?php endforeach; ?>

                                <?php if ($is_current_period): foreach ($tarifas as $id => $tarifa): ?>
                                    <th class="numeric proj-col"><?php echo htmlspecialchars($tarifa['nome_tarifa']); ?> (Proj.) <?php if ($id === $id_tarifa_mais_barata_proj): ?><span class="best-deal-icon" title="Opção mais económica (projetado)">⭐</span><?php endif; ?></th>
                                <?php endforeach; endif; ?>
                            </tr>
                        </thead>
                        <tbody>
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
                                    $row_class = $is_total ? 'total-row' : '';
                            ?>
                            <tr class="<?php echo $row_class; ?>">
                                <td class="label-col"><?php echo $label; ?></td>
                                <?php foreach ($tarifas as $id => $tarifa): 
                                    $fatura = $faturas_atuais[$id] ?? [];
                                    $valor = ($key === 'taxas_custo_base') 
                                        ? ($fatura['iece_custo_base'] ?? 0) + ($fatura['ts_custo_base'] ?? 0)
                                        : ($fatura[$key] ?? 0);
                                    $cell_class = $tarifa['is_contratada'] ? ' bold-text' : '';
                                ?>
                                    <td class="numeric<?php echo $cell_class; ?>">&euro; <?php echo number_format($valor, 2, ',', '.'); ?></td>
                                <?php endforeach; ?>

                                <?php if ($is_current_period): foreach ($tarifas as $id => $tarifa):
                                    $fatura_proj = $faturas_projetadas[$id] ?? [];
                                    $valor_proj = ($key === 'taxas_custo_base') 
                                        ? ($fatura_proj['iece_custo_base'] ?? 0) + ($fatura_proj['ts_custo_base'] ?? 0)
                                        : ($fatura_proj[$key] ?? 0);
                                    $cell_class_proj = $tarifa['is_contratada'] ? ' bold-text' : '';
                                ?>
                                    <td class="numeric proj-col<?php echo $cell_class_proj; ?>">&euro; <?php echo number_format($valor_proj, 2, ',', '.'); ?></td>
                                <?php endforeach; endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="summary-grid">
                <div class="summary-box">
                    <h3>Resumo de Consumo (kWh)</h3>
                    <table>
                        <thead><tr><th class="label-col">Tarifa</th><th class="numeric">Atual</th><?php if ($is_current_period): ?><th class="numeric">Projetado</th><?php endif; ?></tr></thead>
                        <tbody>
                            <tr>
                                <td class="label-col">Vazio</td>
                                <td class="numeric"><?php echo number_format($dados_consumo_atual['consumo_vazio'] ?? 0, 1, ',', '.'); ?></td>
                                <?php if ($is_current_period): ?>
                                    <td class="numeric proj-col"><?php echo number_format(($dados_consumo_atual['consumo_vazio'] ?? 0) + ($consumo_projetado['consumo_vazio'] ?? 0), 1, ',', '.'); ?></td>
                                <?php endif; ?>
                            </tr>
                            <tr>
                                <td class="label-col">Cheia</td>
                                <td class="numeric"><?php echo number_format($dados_consumo_atual['consumo_cheia'] ?? 0, 1, ',', '.'); ?></td>
                                <?php if ($is_current_period): ?>
                                    <td class="numeric proj-col"><?php echo number_format(($dados_consumo_atual['consumo_cheia'] ?? 0) + ($consumo_projetado['consumo_cheia'] ?? 0), 1, ',', '.'); ?></td>
                                <?php endif; ?>
                            </tr>
                            <tr>
                                <td class="label-col">Ponta</td>
                                <td class="numeric"><?php echo number_format($dados_consumo_atual['consumo_ponta'] ?? 0, 1, ',', '.'); ?></td>
                                <?php if ($is_current_period): ?>
                                    <td class="numeric proj-col"><?php echo number_format(($dados_consumo_atual['consumo_ponta'] ?? 0) + ($consumo_projetado['consumo_ponta'] ?? 0), 1, ',', '.'); ?></td>
                                <?php endif; ?>
                            </tr>
                            <tr class="total-row">
                                <td class="label-col">Total</td>
                                <td class="numeric"><?php echo number_format($dados_consumo_atual['total_kwh'] ?? 0, 1, ',', '.'); ?></td>
                                <?php if ($is_current_period): ?>
                                    <?php
                                        // Calcula o total projetado somando as projeções parciais para garantir consistência.
                                        $total_atual = $dados_consumo_atual['total_kwh'] ?? 0;
                                        $total_futuro = ($consumo_projetado['consumo_vazio'] ?? 0) + ($consumo_projetado['consumo_cheia'] ?? 0) + ($consumo_projetado['consumo_ponta'] ?? 0);
                                    ?>
                                    <td class="numeric proj-col"><?php echo number_format($total_atual + $total_futuro, 1, ',', '.'); ?></td>
                                <?php endif; ?>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <button id="openModalButton" class="main-button">Ver Registos Detalhados do Período</button>

            <div class="summary-box" style="margin-top: 20px;">
                <h3>Consumo Diário (kWh)</h3>
                <div id="daily-consumption-chart"></div>
            </div>

            <div class="summary-box" style="margin-top: 20px;">
                <h3>Padrões de Consumo (kWh por Hora/Dia)</h3>
                
                <div class="chart-legend">
                    <div class="legend-item">
                        <span class="legend-swatch legend-vazio"></span>
                        <span>Período Vazio</span>
                    </div>
                    <div class="legend-item">
                        <span class="legend-swatch legend-cheia"></span>
                        <span>Período Fora de Vazio</span>
                    </div>
                </div>

                <div id="heatmap-weekday" style="margin-top: 20px;"></div>
                <div id="heatmap-saturday" style="margin-top: 20px;"></div>
                <div id="heatmap-sunday" style="margin-top: 20px;"></div>
            </div>
        </div>
    </main>
    
    <div id="modal-registos" class="modal">
        <div class="modal-content">
            <span id="closeModalButton" class="close-button">&times;</span>
            <h2>Registos do Período</h2>
            <button id="toggleZeroButton" class="filtro-btn">Mostrar Registos a Zero</button>
            <div class="modal-table-container">
                <table class="registos-table">
                    <thead>
                        <tr>
                            <th>Data e Hora (Local)</th>
                            <th class="numeric">Consumo (kWh)</th>
                            <?php foreach ($tarifas as $tarifa): ?>
                                <th class="numeric">Custo <?php echo htmlspecialchars($tarifa['nome_tarifa']); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                            if (isset($ultimos_registos_result)) {
                                $ultimos_registos_result->data_seek(0); // Reset pointer
                                while ($registo = $ultimos_registos_result->fetch_assoc()):

                                $consumo_intervalo = $registo['consumo_vazio'] + $registo['consumo_cheia'] + $registo['consumo_ponta'];
                                $classe_linha = ($consumo_intervalo < 0.00001) ? 'linha-zero linha-escondida' : '';
                        ?>
                                <tr class="<?php echo $classe_linha; ?>">
                                    <td><?php
                                        $data_obj = new DateTime($registo['data_hora'], new DateTimeZone('UTC'));
                                        $data_obj->setTimezone($fuso_horario_local);
                                        echo $data_obj->format('d/m/Y H:i:s');
                                    ?></td>
                                    <td class="numeric"><?php echo number_format($consumo_intervalo, 5, ',', '.'); ?></td>
                                    <?php foreach ($tarifas as $id => $tarifa): ?>
                                        <?php
                                            $custo_leitura = 0;
                                            if ($tarifa['modalidade'] === 'simples') {
                                                $custo_leitura = $consumo_intervalo * $tarifa['preco_simples'];
                                            } elseif ($tarifa['modalidade'] === 'bi-horario') {
                                                $custo_leitura = ($registo['consumo_vazio'] * $tarifa['preco_vazio']) + ($registo['consumo_cheia'] * $tarifa['preco_cheia']) + ($registo['consumo_ponta'] * $tarifa['preco_ponta']);
                                            }
                                        ?>
                                        <td class="numeric"><?php echo number_format($custo_leitura, 5, ',', '.'); ?></td>
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
        const openBtn = document.getElementById('openModalButton');
        const closeBtn = document.getElementById('closeModalButton');
        if(openBtn) { openBtn.onclick = function() { modal.style.display = "block"; } }
        if(closeBtn) { closeBtn.onclick = function() { modal.style.display = "none"; } }
        window.onclick = function(event) { if (event.target == modal) { modal.style.display = "none"; } }
        const toggleButton = document.getElementById('toggleZeroButton');
        if (toggleButton) {
            let zerosEstaoVisiveis = false;
            toggleButton.addEventListener('click', function() {
                zerosEstaoVisiveis = !zerosEstaoVisiveis;
                document.querySelectorAll('tr.linha-zero').forEach(row => row.classList.toggle('linha-escondida'));
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
                                    color: '#85C1E9' // Azul Pastel
                                }, {
                                    from: 0.5,
                                    to: 5.0, // Um valor alto para abranger picos
                                    name: 'alto',
                                    color: '#E74C3C' // Vermelho
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
                    linha: { ...estiloLinha, borderColor: '#F1948A' }, // Vermelho Pastel
                    label: { ...estiloLabelBase, borderColor: '#F1948A', style: { ...estiloLabelBase.style, color: '#fff', background: '#F1948A' } }
                };

                const estiloVazio = {
                    linha: { ...estiloLinha, borderColor: '#85C1E9' }, // Azul Pastel
                    label: { ...estiloLabelBase, borderColor: '#85C1E9', style: { ...estiloLabelBase.style, color: '#fff', background: '#85C1E9' } }
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