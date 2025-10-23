<?php
// fatura_detalhada.php

require_once 'config.php';
require_once 'calculos.php';

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_error) { die('Erro de ligação.'); }
$mysqli->set_charset('utf8mb4');

$dados_detalhados = obterDadosFaturaDetalhada($mysqli, $_GET);

$mysqli->close();

extract($dados_detalhados);

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

?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Fatura Detalhada - Energia</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="style.css">
</head>
<body class="bg-gray-100 font-sans flex h-screen">
    <?php 
        $active_page = 'fatura_detalhada';
        require_once 'sidebar.php'; 
    ?>

    <!-- Conteúdo Principal -->
    <main id="main-content" class="flex-grow p-8 overflow-y-auto">
        <div class="max-w-7xl mx-auto">
            <h1 class="text-3xl font-bold text-gray-800">Fatura Detalhada - <?php echo htmlspecialchars($nome_tarifa_contratada); ?></h1>
            <h2 class="text-xl text-gray-600 mb-6"><?php echo $titulo_pagina; ?></h2>

            <div class="bg-white rounded-lg shadow-md overflow-hidden mb-8">
                <div class="p-6">
                    <h3 class="text-xl font-bold mb-4">Resumo da Fatura</h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full">
                            <thead class="bg-gray-800 text-white">
                            <tr>
                                <th class="py-2 px-3 text-left font-semibold">Componente</th>
                                <th class="py-2 px-3 text-right font-semibold">Valor Atual</th>
                                <?php if ($is_current_period): ?>
                                <th class="py-2 px-3 text-right font-semibold">Valor Projetado</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($componentes as $key => $label):
                                $is_total = ($key === 'total_fatura');
                                $row_class = $is_total ? 'total-row' : '';
                                $text_class = $is_total ? 'font-bold text-base' : 'font-medium';
                            ?>
                            <tr class="border-b border-gray-200 last:border-b-0 <?php if($is_total) echo 'bg-gray-50'; ?>">
                                <td class="py-3 px-3 <?php echo $text_class; ?> text-gray-800"><?php echo $label; ?></td>
                                <td class="py-3 px-3 text-right <?php echo $text_class; ?> text-gray-900 tabular-nums">&euro; <?php echo number_format($totais_reais[$key] ?? 0, 2, ',', '.'); ?></td>
                                <?php if ($is_current_period): ?>
                                <td class="py-3 px-3 text-right <?php echo $text_class; ?> text-blue-600 tabular-nums">&euro; <?php echo number_format($totais_projetados[$key] ?? 0, 2, ',', '.'); ?></td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <div class="p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-xl font-bold">Valores Reais e Projetados (Dia a Dia)</h3>
                        <div class="flex items-center space-x-4 text-sm text-gray-600">
                            <div class="flex items-center"><span class="w-4 h-4 rounded-sm bg-green-100 mr-2 border border-green-200"></span> Valores Reais</div>
                            <div class="flex items-center"><span class="w-4 h-4 rounded-sm bg-yellow-100 mr-2 border border-yellow-200"></span> Dia Atual</div>
                            <div class="flex items-center"><span class="w-4 h-4 rounded-sm bg-blue-100 mr-2 border border-blue-200"></span> Valores Projetados</div>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full">
                            <thead class="bg-gray-800 text-white">
                                <tr class="text-left font-semibold">
                                    <th rowspan="2" class="py-2 px-2 border-b-2 border-gray-200">Dia</th>
                                    <th colspan="6" class="py-2 px-2 text-center border-b-2 border-gray-200">Consumo (kWh)</th>
                                    <th colspan="6" class="py-2 px-2 text-center border-b-2 border-gray-200">Custo (€)</th>
                                </tr>
                                <tr class="text-right font-semibold">
                                    <th class="py-2 px-2 border-b-2 border-gray-200">Vazio</th>
                                    <th class="py-2 px-2 border-b-2 border-gray-200">Fora Vazio</th>
                                    <th class="py-2 px-2 border-b-2 border-gray-200">Total</th>
                                    <th class="py-2 px-2 border-b-2 border-gray-200">Vazio Acum.</th>
                                    <th class="py-2 px-2 border-b-2 border-gray-200">Fora Vazio Acum.</th>
                                    <th class="py-2 px-2 border-b-2 border-gray-200">Consumo Acum.</th>
                                    <th class="py-2 px-2 border-b-2 border-gray-200">Vazio</th>
                                    <th class="py-2 px-2 border-b-2 border-gray-200">Fora Vazio</th>
                                    <th class="py-2 px-2 border-b-2 border-gray-200">Base IVA Red.</th>
                                    <th class="py-2 px-2 border-b-2 border-gray-200">Base IVA Norm.</th>
                                    <th class="py-2 px-2 border-b-2 border-gray-200">Total Dia</th>
                                    <th class="py-2 px-2 border-b-2 border-gray-200">Total Acum.</th>
                                </tr>
                            </thead>
                            <tbody class="text-gray-800">
                                <?php if (empty($dados_reais_dia_a_dia) && empty($dados_projetados_dia_a_dia)): ?>
                                    <tr><td colspan="13" class="text-center py-4 text-gray-500">Sem dados para o período.</td></tr>
                                <?php else: ?>
                                    <?php 
                                    $hoje_dm = (new DateTime('now', new DateTimeZone('Europe/Lisbon')))->format('d/m');
                                    foreach ($dados_reais_dia_a_dia as $dia_dados): 
                                        $is_today = ($dia_dados['dia'] === $hoje_dm);
                                        $row_class = $is_today ? 'bg-yellow-100' : 'bg-green-100';
                                    ?>
                                        <tr class="<?php echo $row_class; ?> border-b border-white">
                                            <td class="py-2 px-2 font-medium"><?php echo $dia_dados['dia']; ?></td>
                                            <td class="py-2 px-2 text-right tabular-nums"><?php echo number_format($dia_dados['consumo_vazio_dia'], 2, ',', '.'); ?></td>
                                            <td class="py-2 px-2 text-right tabular-nums"><?php echo number_format($dia_dados['consumo_fora_vazio_dia'], 2, ',', '.'); ?></td>
                                            <td class="py-2 px-2 text-right tabular-nums font-semibold"><?php echo number_format($dia_dados['consumo_dia'], 2, ',', '.'); ?></td>
                                            <td class="py-2 px-2 text-right tabular-nums text-gray-500"><?php echo number_format($dia_dados['consumo_vazio_acumulado'], 2, ',', '.'); ?></td>
                                            <td class="py-2 px-2 text-right tabular-nums text-gray-500"><?php echo number_format($dia_dados['consumo_fora_vazio_acumulado'], 2, ',', '.'); ?></td>
                                            <td class="py-2 px-2 text-right tabular-nums text-gray-500"><?php echo number_format($dia_dados['consumo_acumulado'], 2, ',', '.'); ?></td>
                                            <td class="py-2 px-2 text-right tabular-nums">&euro; <?php echo number_format($dia_dados['custo_vazio_dia'], 2, ',', '.'); ?></td>
                                            <td class="py-2 px-2 text-right tabular-nums">&euro; <?php echo number_format($dia_dados['custo_fora_vazio_dia'], 2, ',', '.'); ?></td>
                                            <td class="py-2 px-2 text-right tabular-nums">&euro; <?php echo number_format($dia_dados['base_iva_reduzido_dia'], 2, ',', '.'); ?></td>
                                            <td class="py-2 px-2 text-right tabular-nums">&euro; <?php echo number_format($dia_dados['base_iva_normal_dia'], 2, ',', '.'); ?></td>
                                            <td class="py-2 px-2 text-right tabular-nums font-semibold">&euro; <?php echo number_format($dia_dados['total_dia'], 2, ',', '.'); ?></td>
                                            <td class="py-2 px-2 text-right tabular-nums font-bold text-blue-700">&euro; <?php echo number_format($dia_dados['total_acumulado'], 2, ',', '.'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if ($is_current_period && !empty($dados_projetados_dia_a_dia)): ?>
                                        <?php foreach ($dados_projetados_dia_a_dia as $dia_dados): ?>
                                            <tr class="bg-blue-100 border-b border-white">
                                                <td class="py-2 px-2 font-medium"><?php echo $dia_dados['dia']; ?></td>
                                                <td class="py-2 px-2 text-right tabular-nums"><?php echo number_format($dia_dados['consumo_vazio_dia'], 2, ',', '.'); ?></td>
                                                <td class="py-2 px-2 text-right tabular-nums"><?php echo number_format($dia_dados['consumo_fora_vazio_dia'], 2, ',', '.'); ?></td>
                                                <td class="py-2 px-2 text-right tabular-nums font-semibold"><?php echo number_format($dia_dados['consumo_dia'], 2, ',', '.'); ?></td>
                                                <td class="py-2 px-2 text-right tabular-nums text-gray-500"><?php echo number_format($dia_dados['consumo_vazio_acumulado'], 2, ',', '.'); ?></td>
                                                <td class="py-2 px-2 text-right tabular-nums text-gray-500"><?php echo number_format($dia_dados['consumo_fora_vazio_acumulado'], 2, ',', '.'); ?></td>
                                                <td class="py-2 px-2 text-right tabular-nums text-gray-500"><?php echo number_format($dia_dados['consumo_acumulado'], 2, ',', '.'); ?></td>
                                                <td class="py-2 px-2 text-right tabular-nums">&euro; <?php echo number_format($dia_dados['custo_vazio_dia'], 2, ',', '.'); ?></td>
                                                <td class="py-2 px-2 text-right tabular-nums">&euro; <?php echo number_format($dia_dados['custo_fora_vazio_dia'], 2, ',', '.'); ?></td>
                                                <td class="py-2 px-2 text-right tabular-nums">&euro; <?php echo number_format($dia_dados['base_iva_reduzido_dia'], 2, ',', '.'); ?></td>
                                                <td class="py-2 px-2 text-right tabular-nums">&euro; <?php echo number_format($dia_dados['base_iva_normal_dia'], 2, ',', '.'); ?></td>
                                                <td class="py-2 px-2 text-right tabular-nums font-semibold">&euro; <?php echo number_format($dia_dados['total_dia'], 2, ',', '.'); ?></td>
                                                <td class="py-2 px-2 text-right tabular-nums font-bold text-blue-700">&euro; <?php echo number_format($dia_dados['total_acumulado'], 2, ',', '.'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>
    <script src="main.js"></script>
</body>
</html>