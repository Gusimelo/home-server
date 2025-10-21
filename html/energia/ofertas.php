<?php
session_start();
require_once 'config.php';
require_once 'calculos.php';

// Função para calcular o custo real da fatura para uma dada oferta
function calcularCustoFinalOferta(array $oferta, float $consumo_total, float $consumo_vazio, float $consumo_cheia, float $consumo_ponta, int $dias_periodo): array {
    $custo_potencia_diario = $oferta['TermoFixoDiario'] ?? 0;

    $custo_energia_total = 0;
    if (strtolower($oferta['TipoTarifa']) == 'simples') {
        $custo_energia_total = $consumo_total * ($oferta['PrecoForaVazio'] ?? 0);
    } else {
        $custo_energia_total = ($consumo_cheia * ($oferta['PrecoForaVazio'] ?? 0)) + ($consumo_ponta * ($oferta['PrecoForaVazio'] ?? 0)) + ($consumo_vazio * ($oferta['PrecoVazio'] ?? 0));
    }

    // Reutiliza a função de cálculo de fatura detalhada
    // Constrói o array esperado pela função, mesmo que de forma simplificada para as ofertas
    $dados_fatura = [
        'custo_energia' => $custo_energia_total,
        'total_kwh' => $consumo_total,
        // A lógica de IVA para ofertas externas continuará a ser por média,
        // pois não temos o histórico de consumo para fazer o cálculo incremental.
        // Apenas as tarifas internas no dashboard principal têm o cálculo preciso.
    ];
    $detalhes_fatura = calcularFaturaDetalhada($dados_fatura, $dias_periodo, $custo_potencia_diario);

    // Condição especial para tarifas Goldenergy ACP
    $custo_fatura_periodo = $detalhes_fatura['total_fatura'] ?? 0;
    $is_goldenergy_acp = (stripos($oferta['Comercializador'], 'gold') !== false) && (stripos($oferta['NomeProposta'], 'acp') !== false);
    if ($is_goldenergy_acp) {
        $custo_mensal_acp = 4.65;
        // Adiciona o custo proporcional ao número de dias do período
        $custo_fatura_periodo += $custo_mensal_acp * ($dias_periodo / 30);
    }

    return [
        'custo_periodo' => $custo_fatura_periodo,
        'custo_anual' => $custo_fatura_periodo * (365 / $dias_periodo)
    ];
}


// Conectar à BD e obter os dados de consumo
$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_error) { die('Erro de ligação.'); }
$mysqli->set_charset('utf8mb4');
$dados = obterDadosDashboard($mysqli, $_GET);
$mysqli->close();

extract($dados);

// --- Lógica para chamar o script Python ---
$potencia_contratada = 5.75; // Potência em kVA, conforme solicitado
$segmentos = $_GET['segmentos'] ?? ['Dom', 'Tod'];

// Escolher os dados de consumo: projetado para o período atual, real para os passados
if ($is_current_period && !empty($consumo_projetado)) {
    $consumo_vazio = ($dados_consumo_atual['consumo_vazio'] ?? 0) + ($consumo_projetado['consumo_vazio'] ?? 0);
    $consumo_cheia = ($dados_consumo_atual['consumo_cheia'] ?? 0) + ($consumo_projetado['consumo_cheia'] ?? 0);
    $consumo_ponta = ($dados_consumo_atual['consumo_ponta'] ?? 0) + ($consumo_projetado['consumo_ponta'] ?? 0);
} else {
    $consumo_vazio = $dados_consumo_atual['consumo_vazio'] ?? 0;
    $consumo_cheia = $dados_consumo_atual['consumo_cheia'] ?? 0;
    $consumo_ponta = $dados_consumo_atual['consumo_ponta'] ?? 0;
}

// Normaliza o consumo para uma base de 30 dias para passar ao script Python, que espera um valor mensal.
$fator_normalizacao = ($total_dias_periodo > 0) ? (30 / $total_dias_periodo) : 1;
$consumo_mensal_vazio = $consumo_vazio * $fator_normalizacao;
$consumo_mensal_cheia = $consumo_cheia * $fator_normalizacao;
$consumo_mensal_ponta = $consumo_ponta * $fator_normalizacao;

$segmento_str = '';
if (!empty($segmentos)) {
    $segmento_str = '--segmentos ' . implode(' ', array_map('escapeshellarg', $segmentos));
}


// --- Lógica de Cache com Cookies e Sessão ---
$ofertas_simples = [];
$ofertas_bihorario = [];
$comando_simples = ''; // Inicializa a variável
$comando_bihorario = ''; // Inicializa a variável

// Criar uma chave de cache baseada nos segmentos para garantir que o filtro é aplicado
$segmentos_key_part = implode('_', $segmentos);
$cache_key_simples = 'cached_ofertas_simples_' . $segmentos_key_part;
$cache_key_bihorario = 'cached_ofertas_bihorario_' . $segmentos_key_part;

$last_checked = $_COOKIE['ofertas_last_checked'] ?? 0;
$current_time = time();
$force_refresh = isset($_GET['force_refresh']);

if ($force_refresh || ($current_time - $last_checked) > 3600 || !isset($_SESSION[$cache_key_simples])) { // 1 hora, forçar atualização, ou cache de sessão vazia para estes segmentos
    // --- Chamada para Tarifa Simples ---
    $consumo_total_mensal = $consumo_mensal_vazio + $consumo_mensal_cheia + $consumo_mensal_ponta;
    $comando_simples = sprintf(
        'python3 scripts/descarregar_ofertas.py --potencia %s --consumo-fora-vazio %s %s --quiet',
        escapeshellarg($potencia_contratada),
        escapeshellarg($consumo_total_mensal),
        $segmento_str
    );
    $json_simples = shell_exec($comando_simples);
    $ofertas_simples = $json_simples ? json_decode($json_simples, true) : [];

    // --- Chamada para Tarifa Bi-Horária ---
    if ($consumo_mensal_vazio > 0) {
        $comando_bihorario = sprintf(
            'python3 scripts/descarregar_ofertas.py --potencia %s --consumo-fora-vazio %s --consumo-vazio %s %s --quiet',
            escapeshellarg($potencia_contratada),
            escapeshellarg($consumo_mensal_cheia + $consumo_mensal_ponta),
            escapeshellarg($consumo_mensal_vazio),
            $segmento_str
        );
        $json_bihorario = shell_exec($comando_bihorario);
        $ofertas_bihorario = $json_bihorario ? json_decode($json_bihorario, true) : [];
    }

    // Guardar em sessão para cache
    $_SESSION[$cache_key_simples] = $ofertas_simples;
    $_SESSION[$cache_key_bihorario] = $ofertas_bihorario;

    // Definir o cookie para controlar o tempo
    setcookie('ofertas_last_checked', $current_time, $current_time + 3600, "/");

} else {
    // Usar dados da cache da sessão
    $ofertas_simples = $_SESSION[$cache_key_simples] ?? [];
    $ofertas_bihorario = $_SESSION[$cache_key_bihorario] ?? [];
}

// --- Combinar, Paginar e Ordenar Ofertas ---
$ofertas = array_merge($ofertas_simples, $ofertas_bihorario);
$orderby = $_GET['orderby'] ?? 'CustoPeriodoCalculado'; // Default to CustoPeriodoCalculado
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 30;
if ($per_page == 0) $per_page = count($ofertas);

$total_ofertas = count($ofertas);
$total_pages = ceil($total_ofertas / $per_page);
$offset = ($page - 1) * $per_page;

// Adicionar o custo mensal calculado a cada oferta
foreach ($ofertas as &$oferta) {
    $custos_calculados = calcularCustoFinalOferta(
        $oferta, 
        $consumo_vazio + $consumo_cheia + $consumo_ponta, // Para o cálculo da fatura, usamos o consumo real do período
        $consumo_vazio,
        $consumo_cheia,
        $consumo_ponta,
        $total_dias_periodo
    );
    $oferta['CustoPeriodoCalculado'] = $custos_calculados['custo_periodo'];
    $oferta['CustoAnualCalculado'] = $custos_calculados['custo_anual'];
}
unset($oferta); // quebrar a referência

if (!empty($ofertas)) {
    usort($ofertas, function($a, $b) use ($orderby) {
        return $a[$orderby] <=> $b[$orderby];
    });
}

$ofertas_paginadas = array_slice($ofertas, $offset, $per_page);

?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Comparador de Ofertas de Energia</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="bg-gray-100 font-sans flex">    
    <?php 
        $active_page = 'ofertas';
        require_once 'sidebar.php'; 
    ?>

    <main class="flex-1 p-8">
        <div class="container mx-auto">
            <h1 class="text-3xl font-bold mb-2">Comparador de Ofertas ERSE</h1>
            <h2 class="text-xl text-gray-600 mb-6"><?php echo $titulo_pagina; ?></h2>

            <div class="bg-white shadow-md rounded-lg p-6">
                <h3 class="text-xl font-bold mb-4">Melhores Ofertas Para o Seu Perfil</h3>
                <form method="get" action="ofertas.php" class="mb-6 flex items-center space-x-4">
                    <!-- Campos ocultos para manter o estado da paginação e ordenação -->
                    <input type="hidden" name="page" value="<?php echo htmlspecialchars($page); ?>">
                    <input type="hidden" name="per_page" value="<?php echo htmlspecialchars($per_page); ?>">
                    <input type="hidden" name="orderby" value="<?php echo htmlspecialchars($orderby); ?>">
                    <?php if (isset($_GET['ano']) && isset($_GET['mes'])): ?>
                        <input type="hidden" name="ano" value="<?php echo htmlspecialchars($_GET['ano']); ?>">
                        <input type="hidden" name="mes" value="<?php echo htmlspecialchars($_GET['mes']); ?>">
                    <?php endif; ?>


                    <label class="font-semibold">Segmento de Mercado:</label>
                    <div class="flex items-center space-x-2">
                        <input type="checkbox" name="segmentos[]" value="Dom" id="seg_dom" <?php if (in_array('Dom', $segmentos)) echo 'checked'; ?> class="h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                        <label for="seg_dom">Doméstico</label>
                    </div>
                    <div class="flex items-center space-x-2">
                        <input type="checkbox" name="segmentos[]" value="Tod" id="seg_tod" <?php if (in_array('Tod', $segmentos)) echo 'checked'; ?> class="h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                        <label for="seg_tod">Todos</label>
                    </div>
                    <div class="flex items-center space-x-2">
                        <input type="checkbox" name="segmentos[]" value="Neg" id="seg_neg" <?php if (in_array('Neg', $segmentos)) echo 'checked'; ?> class="h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                        <label for="seg_neg">Negócios</label>
                    </div>
                    <button type="submit" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">Filtrar</button>
                </form>

                <p class="text-gray-700 mb-4">Com base num consumo de <strong class="font-semibold"><?php echo number_format($consumo_cheia + $consumo_ponta, 0, ',', '.'); ?> kWh</strong> (fora de vazio) e <strong class="font-semibold"><?php echo number_format($consumo_vazio, 0, ',', '.'); ?> kWh</strong> (vazio) para uma potência de <strong class="font-semibold"><?php echo $potencia_contratada; ?> kVA</strong>.</p>
                    
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2 mb-4 text-sm">
                    <span class="flex items-center gap-2"><span class="inline-flex items-center justify-center w-5 h-5 bg-blue-200 rounded-full text-xs font-bold text-blue-800">S</span> Tarifa Simples</span>
                    <span class="flex items-center gap-2"><span class="inline-flex items-center justify-center w-5 h-5 bg-green-200 rounded-full text-xs font-bold text-green-800">B</span> Tarifa Bi-Horária</span>
                    <span class="flex items-center gap-2"><span class="inline-flex items-center justify-center w-5 h-5 bg-yellow-200 rounded-full text-xs font-bold text-yellow-800">I</span> Tarifa Indexada</span>
                    <span class="flex items-center gap-2"><span class="inline-block w-4 h-4 bg-gray-200 rounded-full"></span> A sua oferta atual</span>
                </div>

                <div class="flex justify-between items-center mb-4">
                    <form method="get" action="ofertas.php">
                        <input type="hidden" name="orderby" value="<?php echo $orderby; ?>">
                        <label for="per_page">Ofertas por página:</label>
                        <select name="per_page" id="per_page" onchange="this.form.submit()" class="border-gray-300 rounded-md shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                            <option value="10" <?php if ($per_page == 10) echo 'selected'; ?>>10</option>
                            <option value="20" <?php if ($per_page == 20) echo 'selected'; ?>>20</option>
                            <option value="30" <?php if ($per_page == 30) echo 'selected'; ?>>30</option>
                            <option value="50" <?php if ($per_page == 50) echo 'selected'; ?>>50</option>
                            <option value="0" <?php if ($per_page == count($ofertas)) echo 'selected'; ?>>Todas</option>
                        </select>
                    </form>
                </div>

                <?php if (!empty($ofertas)):
                ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full bg-white">
                            <thead class="bg-gray-800 text-white">
                                <tr>
                                    <th class="py-3 px-4 text-left whitespace-nowrap"></th>
                                    <th class="py-3 px-4 text-left">Comercializador</th>
                                    <th class="py-3 px-4 text-left">Oferta</th>
                                    <th class="py-3 px-4 text-right"><a href="?orderby=CustoPeriodoCalculado&per_page=<?php echo $per_page; ?>" class="hover:text-blue-300">Custo no Período</a></th>
                                    <th class="py-3 px-4 text-right"><a href="?orderby=CustoAnualCalculado&per_page=<?php echo $per_page; ?>" class="hover:text-blue-300">Custo Anual Calculado</a></th>
                                    <th class="py-3 px-4 text-right">Termo Fixo (€/dia)</th>
                                    <th class="py-3 px-4 text-right">Preço Simples (€/kWh)</th>
                                    <th class="py-3 px-4 text-right">Preço Fora Vazio (€/kWh)</th>
                                    <th class="py-3 px-4 text-right">Preço Vazio (€/kWh)</th>
                                    <th class="py-3 px-4 text-left whitespace-nowrap">Ações</th>
                                </tr>
                            </thead>
                            <tbody class="text-gray-700">
                                <?php foreach ($ofertas_paginadas as $oferta): 
                                    $is_oferta_atual = (
                                        trim(strtolower($oferta['Comercializador'])) == trim(strtolower(MEU_COMERCIALIZADOR)) && 
                                        trim(strtolower($oferta['NomeProposta'])) == trim(strtolower(MINHA_OFERTA)) &&
                                        trim(strtolower($oferta['TipoTarifa'])) == trim(strtolower(MEU_TIPO_TARIFA))
                                    );
                                    $row_class = $is_oferta_atual ? 'bg-gray-200 font-bold' : 'hover:bg-gray-100';

                                    $icon_html = '';
                                    $tipo_tarifa = strtolower($oferta['TipoTarifa'] ?? '');

                                    if ($tipo_tarifa === 'simples') {
                                        $icon_html .= '<span class="inline-flex items-center justify-center w-5 h-5 bg-blue-200 rounded-full text-xs font-bold text-blue-800" title="Tarifa Simples">S</span>';
                                    } elseif ($tipo_tarifa === 'bi-horario') {
                                        $icon_html .= '<span class="inline-flex items-center justify-center w-5 h-5 bg-green-200 rounded-full text-xs font-bold text-green-800" title="Tarifa Bi-Horária">B</span>';
                                    }

                                    if (isset($oferta['TipoPreco']) && strtolower($oferta['TipoPreco']) === 'indexado') {
                                        $icon_html .= '<span class="inline-flex items-center justify-center w-5 h-5 bg-yellow-200 rounded-full ml-1 text-xs font-bold text-yellow-800" title="Tarifa Indexada">I</span>';
                                    }

                                ?>
                                    <tr class="border-b border-gray-200 <?php echo $row_class; ?>">
                                        <td class="py-3 px-4 whitespace-nowrap"><?php echo $icon_html; ?></td>
                                        <td class="py-3 px-4"><?php echo htmlspecialchars($oferta['Comercializador'] ?? ''); ?></td>
                                        <td class="py-3 px-4"><?php echo htmlspecialchars($oferta['NomeProposta'] ?? ''); ?></td>
                                        <td class="py-3 px-4 text-right">&euro; <?php echo number_format($oferta['CustoPeriodoCalculado'], 2, ',', '.'); ?></td>
                                        <td class="py-3 px-4 text-right">&euro; <?php echo number_format($oferta['CustoAnualCalculado'], 2, ',', '.'); ?></td>
                                        <td class="py-3 px-4 text-right"><?php echo number_format($oferta['TermoFixoDiario'] ?? 0, 4, ',', '.'); ?></td>
                                        
                                        <?php if (strtolower($oferta['TipoTarifa'] ?? '') == 'simples'): ?>
                                            <td class="py-3 px-4 text-right"><?php echo number_format($oferta['PrecoForaVazio'] ?? 0, 4, ',', '.'); ?></td>
                                            <td class="py-3 px-4 text-right">-</td>
                                            <td class="py-3 px-4 text-right">-</td>
                                        <?php else: ?>
                                            <td class="py-3 px-4 text-right">-</td>
                                            <td class="py-3 px-4 text-right"><?php echo number_format($oferta['PrecoForaVazio'] ?? 0, 4, ',', '.'); ?></td>
                                            <td class="py-3 px-4 text-right"><?php echo number_format($oferta['PrecoVazio'] ?? 0, 4, ',', '.'); ?></td>
                                        <?php endif; ?>
                                        <td class="py-3 px-4 whitespace-nowrap">
                                            <?php if (!empty($oferta['LinkOfertaCom'])): ?>
                                                <a href="<?php echo htmlspecialchars($oferta['LinkOfertaCom']); ?>" target="_blank" class="text-blue-500 hover:text-blue-700 mr-2"><i class="fas fa-external-link-alt"></i></a>
                                            <?php endif; ?>
                                            <?php if (!empty($oferta['LinkFichaPadrao'])): ?>
                                                <a href="<?php echo htmlspecialchars($oferta['LinkFichaPadrao']); ?>" target="_blank" class="text-blue-500 hover:text-blue-700"><i class="fas fa-file-alt"></i></a>
                                            <?php endif; ?>
                                            <?php
                                                // Preparar dados para o link de adição
                                                $dados_tarifa_link = [
                                                    'nome' => ($oferta['Comercializador'] ?? '') . ' - ' . ($oferta['NomeProposta'] ?? ''),
                                                    'modalidade' => strtolower($oferta['TipoTarifa'] ?? ''),
                                                    'potencia' => $oferta['TermoFixoDiario'] ?? 0,
                                                    'simples' => (strtolower($oferta['TipoTarifa'] ?? '') == 'simples') ? ($oferta['PrecoForaVazio'] ?? 0) : 0,
                                                    'vazio' => (strtolower($oferta['TipoTarifa'] ?? '') == 'bi-horario') ? ($oferta['PrecoVazio'] ?? 0) : 0,
                                                    'cheia' => (strtolower($oferta['TipoTarifa'] ?? '') == 'bi-horario') ? ($oferta['PrecoForaVazio'] ?? 0) : 0,
                                                    'ponta' => 0 // ERSE não distingue ponta de cheia
                                                ];
                                                $query_string = http_build_query(['add_from_offer' => $dados_tarifa_link]);
                                            ?>
                                            <a href="tarifas.php?<?php echo $query_string; ?>" title="Adicionar esta oferta às minhas tarifas para comparação"
                                               class="text-green-500 hover:text-green-700 ml-2"><i class="fas fa-plus-circle"></i></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-4 flex justify-center">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <?php
                                $query_params = http_build_query(['page' => $i, 'orderby' => $orderby, 'per_page' => $per_page, 'segmentos' => $segmentos]);
                            ?>
                            <a href="?<?php echo $query_params; ?>" class="<?php echo $i == $page ? 'bg-blue-500 text-white' : 'bg-white text-blue-500'; ?> py-2 px-4 border border-blue-500 hover:bg-blue-500 hover:text-white"><?php echo $i; ?></a>
                        <?php endfor; ?>
                    </div>
                <?php else: ?>
                    <p class="text-red-500">Não foi possível obter as ofertas. Verifique se o script Python tem permissões de execução e se os caminhos estão corretos.</p>
                    <?php if(!empty($comando_simples)) { echo "<p class=\"mt-4 text-sm text-gray-500\">Comando Tarifa Simples: <code class=\"bg-gray-200 p-1 rounded\">".htmlspecialchars($comando_simples)."</code></p>"; } ?>
                    <?php if(!empty($comando_bihorario)) { echo "<p class=\"mt-2 text-sm text-gray-500\">Comando Tarifa Bi-Horária: <code class=\"bg-gray-200 p-1 rounded\">".htmlspecialchars($comando_bihorario)."</code></p>"; } ?>
                <?php endif; ?>
            </div>
        </div>
    </main>
</body>
</html>
