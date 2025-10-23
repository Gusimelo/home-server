<?php
require_once 'config.php';
$current_year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Análise e Filtragem - Gestão de Despesas</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f3f4f6; }
        .table-fixed th, .table-fixed td { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .view-mode-btn.active {
            background-color: #4f46e5;
            color: white;
            border-color: #4f46e5;
        }
    </style>
</head>
<body class="p-4 sm:p-6 md:p-8">
    <div class="max-w-7xl mx-auto">
        <header class="mb-8">
            <div class="flex flex-col sm:flex-row justify-between sm:items-center gap-4">
                <div>
                    <h1 class="text-3xl sm:text-4xl font-bold text-gray-800">Análise e Filtragem</h1>
                    <p class="text-gray-600 mt-2">Consulte os seus movimentos com filtros avançados.</p>
                </div>
                <a href="index.php?year=<?php echo $current_year; ?>" class="text-sm font-medium text-indigo-600 hover:text-indigo-800 whitespace-nowrap">&larr; Voltar à Página Principal</a>
            </div>
        </header>

        <main class="space-y-8">
            <!-- Secção de Filtros -->
            <div class="bg-white p-6 rounded-lg shadow">
                <form id="filter-form">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                        <!-- Filtros de Categoria -->
                        <div class="space-y-4">
                            <div>
                                <label for="filter-type" class="block text-sm font-medium text-gray-700">Tipo</label>
                                <select id="filter-type" name="type" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                                    <option value="all">Todos</option>
                                    <option value="Expense">Saídas (Despesas)</option>
                                    <option value="Income">Entradas</option>
                                </select>
                            </div>
                            <div>
                                <label for="filter-person" class="block text-sm font-medium text-gray-700">Pessoa</label>
                                <select id="filter-person" name="person" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                                    <option value="all">Todos</option>
                                    <?php foreach($cost_sharers as $person): ?>
                                        <option value="<?php echo htmlspecialchars($person); ?>"><?php echo htmlspecialchars($person); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                         <div class="space-y-4">
                            <div>
                                <label for="filter-cost-center" class="block text-sm font-medium text-gray-700">Centro de Custo</label>
                                <select id="filter-cost-center" name="cost_center" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                                    <option value="">Todos</option>
                                </select>
                            </div>
                             <div>
                                <label for="filter-subcategory" class="block text-sm font-medium text-gray-700">Subcategoria</label>
                                <select id="filter-subcategory" name="subcategory" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" disabled>
                                     <option value="">Todas</option>
                                </select>
                            </div>
                            <div>
                                <label for="filter-sub-subcategory" class="block text-sm font-medium text-gray-700">Sub-Subcategoria</label>
                                <select id="filter-sub-subcategory" name="sub_subcategory" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" disabled>
                                     <option value="">Todas</option>
                                </select>
                            </div>
                        </div>
                        <!-- Filtros de Data -->
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Período</label>
                                <div class="mt-1 flex rounded-md shadow-sm">
                                    <button type="button" id="period-month-btn" class="px-4 py-2 border border-gray-300 text-sm font-medium rounded-l-md bg-indigo-600 text-white">Mês/Ano</button>
                                    <button type="button" id="period-range-btn" class="px-4 py-2 border-t border-b border-r border-gray-300 text-sm font-medium rounded-r-md bg-white text-gray-700 hover:bg-gray-50">Intervalo</button>
                                </div>
                            </div>
                            <div id="month-year-filter">
                                <div>
                                    <label for="filter-year" class="block text-sm font-medium text-gray-700">Ano</label>
                                    <select id="filter-year" name="year" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></select>
                                </div>
                                <div>
                                    <label for="filter-month" class="block text-sm font-medium text-gray-700">Mês</label>
                                    <select id="filter-month" name="month" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></select>
                                </div>
                            </div>
                             <div id="date-range-filter" class="hidden">
                                <div>
                                    <label for="filter-start-date" class="block text-sm font-medium text-gray-700">Data Início</label>
                                    <input type="date" id="filter-start-date" name="start_date" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                                </div>
                                <div>
                                    <label for="filter-end-date" class="block text-sm font-medium text-gray-700">Data Fim</label>
                                    <input type="date" id="filter-end-date" name="end_date" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                                </div>
                            </div>
                        </div>
                        <!-- View Mode -->
                        <div class="space-y-4 md:col-span-2 lg:col-span-1">
                             <label class="block text-sm font-medium text-gray-700">Modo de Vista</label>
                             <div class="mt-1 flex rounded-md shadow-sm">
                                <button type="button" id="view-grouped-btn" class="view-mode-btn active w-full px-4 py-2 border border-gray-300 text-sm font-medium rounded-l-md">Agrupada</button>
                                <button type="button" id="view-detailed-btn" class="view-mode-btn w-full px-4 py-2 border-t border-b border-r border-gray-300 text-sm font-medium rounded-r-md bg-white text-gray-700 hover:bg-gray-50">Detalhada</button>
                            </div>
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end">
                        <button type="submit" class="bg-indigo-600 text-white font-semibold py-2 px-4 rounded-lg shadow hover:bg-indigo-700">Aplicar Filtros</button>
                    </div>
                </form>
            </div>

            <!-- Secção de Resultados -->
            <div id="results-container" class="bg-white rounded-lg shadow hidden">
                <div class="p-6">
                    <h2 class="text-2xl font-bold text-gray-800">Resultados</h2>
                    <div id="summary-boxes" class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-4 text-center">
                        <!-- Totais gerados via JS -->
                    </div>
                </div>
                 <div class="overflow-x-auto">
                    <table class="w-full table-fixed divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr id="results-table-header">
                                <!-- Cabeçalho gerado via JS -->
                            </tr>
                        </thead>
                        <tbody id="results-table" class="bg-white divide-y divide-gray-200">
                            <!-- Resultados da filtragem -->
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <script>
        window.pageConfig = {
            person_avatar_colors: <?php echo json_encode($person_avatar_colors ?? []); ?>,
            current_year: <?php echo $current_year; ?>
        };
    </script>
    <script src="assets/js/filter.js" defer></script>
</body>
</html>

