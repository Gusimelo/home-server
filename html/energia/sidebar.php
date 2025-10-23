<?php
// sidebar.php

// Garante que as variáveis necessárias para o histórico existem, mesmo que nulas.
$is_current_period = $is_current_period ?? true;
$ano_selecionado = $ano_selecionado ?? null;
$mes_selecionado = $mes_selecionado ?? null;
$periodos_historicos_result = $periodos_historicos_result ?? null;

// Determina a página base para os links do histórico
$page_base_url = basename($_SERVER['PHP_SELF']);
?>
<!-- Wrapper para a sidebar e o botão de toggle -->
<div class="relative flex-shrink-0 h-full">
    <!-- Botão para colapsar/expandir -->
    <button id="sidebar-toggle" class="absolute -right-3 top-8 bg-white border-2 border-gray-300 rounded-full w-7 h-7 flex items-center justify-center text-gray-600 hover:bg-gray-100 z-20">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="icon-arrow">
            <path d="M15 18l-6-6 6-6"/>
        </svg>
    </button>
    
    <nav id="sidebar" class="w-64 bg-white shadow-md p-4 overflow-y-auto transition-all duration-300 h-full">
        <!-- Conteúdo da Sidebar -->
        <div class="mb-6">
            <a href="/index.html" class="flex items-center gap-2 text-gray-600 hover:text-indigo-600 font-semibold">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>
                <span>Dashboard Principal</span>
            </a>
        </div>
        <h3 class="text-lg font-bold text-gray-800 border-b border-gray-200 pb-2 mb-4">Navegação</h3>
        <ul>
            <li class="mb-2"><a href="index.php" class="<?php echo ($active_page === 'index') ? 'block py-2 px-3 rounded text-blue-500 font-bold' : 'block py-2 px-3 rounded text-gray-700 hover:text-blue-500'; ?>">Dashboard</a></li>
            <li class="mb-2"><a href="fatura_detalhada.php" class="<?php echo ($active_page === 'fatura_detalhada') ? 'block py-2 px-3 rounded text-blue-500 font-bold' : 'block py-2 px-3 rounded text-gray-700 hover:text-blue-500'; ?>">Fatura Detalhada</a></li>
            <li class="mb-2"><a href="ofertas.php" class="<?php echo ($active_page === 'ofertas') ? 'block py-2 px-3 rounded text-blue-500 font-bold' : 'block py-2 px-3 rounded text-gray-700 hover:text-blue-500'; ?>">Comparar Ofertas</a></li>
            <li class="mb-2"><a href="tarifas.php" class="<?php echo ($active_page === 'tarifas') ? 'block py-2 px-3 rounded text-blue-500 font-bold' : 'block py-2 px-3 rounded text-gray-700 hover:text-blue-500'; ?>">Gerir Tarifas</a></li>
        </ul>
    
        <?php if (isset($periodos_historicos_result)): ?>
            <h3 class="text-lg font-bold text-gray-800 border-b border-gray-200 pb-2 my-4">Histórico</h3>
            <ul>
                <li class="mb-2"><a href="<?php echo $page_base_url; ?>" class="<?php echo $is_current_period ? 'block py-2 px-3 rounded text-blue-500 font-bold' : 'block py-2 px-3 rounded text-gray-700 hover:text-blue-500' ?>">Período Atual</a></li>
            </ul>
            <?php
                $current_year = null;
                $meses = ["", "Janeiro", "Fevereiro", "Março", "Abril", "Maio", "Junho", "Julho", "Agosto", "Setembro", "Outubro", "Novembro", "Dezembro"];
                while ($periodo = $periodos_historicos_result->fetch_assoc()) {
                    if ($periodo['ano'] !== $current_year) {
                        if ($current_year !== null) echo '</ul>';
                        $current_year = $periodo['ano'];
                        echo "<h3 class=\"text-lg font-bold text-gray-800 border-b border-gray-200 pb-2 my-4\">{$current_year}</h3><ul>";
                    }
                    $is_active = (!$is_current_period && $periodo['ano'] == $ano_selecionado && $periodo['mes'] == $mes_selecionado);
                    echo '<li class="mb-2"><a href="?ano='.$periodo['ano'].'&mes='.$periodo['mes'].'" class="'.($is_active ? 'block py-2 px-3 rounded text-blue-500 font-bold' : 'block py-2 px-3 rounded text-gray-700 hover:text-blue-500').'">'.$meses[$periodo['mes']].'</a></li>';
                }
                if ($current_year !== null) echo '</ul>';
            ?>
        <?php endif; ?>
    
    </nav>
</div>