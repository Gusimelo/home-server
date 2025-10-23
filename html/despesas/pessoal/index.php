<?php
require_once 'config.php'; // Inclui as definições centrais

// Forçar o PHP a não fazer cache
header("Cache-Control: no-cache, must-revalidate");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestão de Despesas</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f3f4f6; }
        .modal { display: none; }
        .modal.is-open { display: flex; }
        .tabular-nums { font-variant-numeric: tabular-nums; }
        .month-tab.active { background-color: #4f46e5; color: white; }
        .month-tab { background-color: #e0e7ff; color: #4338ca; }
        #app-container.blurred { filter: blur(5px); pointer-events: none; }
        .chart-mode-btn.active { border-bottom-width: 2px; border-color: #4f46e5; color: #4f46e5; }
        .view-mode-btn.active {
            background-color: #4f46e5;
            color: white;
            border-color: #4f46e5;
        }
    </style>
</head>
<body class="p-4 sm:p-6 md:p-8">

    <div id="user-selection-modal" class="modal fixed inset-0 bg-gray-900 bg-opacity-70 items-center justify-center p-4 z-50">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-sm text-center p-8">
            <h2 class="text-2xl font-bold text-gray-800 mb-6">Quem está a usar?</h2>
            <div id="user-selection-buttons" class="space-y-4">
                </div>
        </div>
    </div>

    <div id="app-container">
        <div class="max-w-screen-2xl mx-auto">
            <header class="mb-8">
                <div class="flex flex-col sm:flex-row justify-between sm:items-center gap-4">
                     <div class="flex items-center gap-4">
                        <div class="relative">
                            <button id="menu-btn" class="p-2 rounded-full hover:bg-gray-200 transition-colors">
                                <svg class="h-6 w-6 text-gray-700" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                                </svg>
                            </button>
                            <div id="main-menu" class="hidden absolute left-0 mt-2 w-56 bg-white rounded-lg shadow-lg z-50 border border-gray-200">
                                <a id="menu-filter-link" href="filter.php" class="block px-4 py-3 text-sm text-gray-700 hover:bg-gray-100">Análise e Filtragem</a>
                                <a id="menu-categories-link" href="categories.php" class="block px-4 py-3 text-sm text-gray-700 hover:bg-gray-100">Gerir Categorias</a>
                            </div>
                        </div>
                        <div>
                            <h1 class="text-3xl sm:text-4xl font-bold text-gray-800">Gestão de Despesas</h1>
                            <div id="user-display" class="flex items-center gap-2 mt-2 text-gray-600"></div>
                        </div>
                    </div>
                     <div class="flex items-center gap-4">
                        <label for="year-select" class="text-sm font-medium text-gray-700">Ano:</label>
                        <select id="year-select" class="rounded-md border-gray-300 shadow-sm">
                            </select>
                    </div>
                </div>
            </header>
            
            <div id="combine-mode-banner" class="hidden bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4 mb-6 rounded-lg shadow" role="alert">
                <div class="flex justify-between items-center">
                    <div>
                        <p class="font-bold">Modo de Combinação Ativo</p>
                        <p id="combine-mode-text" class="text-sm">Selecione o movimento de destino para onde quer mover a despesa.</p>
                    </div>
                    <button onclick="cancelCombineMode()" class="bg-yellow-200 hover:bg-yellow-300 text-yellow-800 font-bold py-1 px-3 rounded">Cancelar</button>
                </div>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
                <div class="bg-white rounded-lg p-4 text-center shadow"><h3 class="text-sm font-medium text-gray-500">Total Rendimentos</h3><p id="total-income" class="text-2xl font-bold mt-1 tabular-nums">0,00 €</p></div>
                <div class="bg-white rounded-lg p-4 text-center shadow"><h3 class="text-sm font-medium text-gray-500">Total Despesas</h3><p id="total-expenses" class="text-2xl font-bold mt-1 tabular-nums">0,00 €</p></div>
                <div class="bg-white rounded-lg p-4 text-center shadow">
                    <h3 class="text-sm font-medium text-gray-500">Total Pago (Gustavo)</h3>
                    <p id="total-gustavo" class="text-2xl font-bold mt-1 tabular-nums">0,00 €</p>
                    <p id="effort-rate-gustavo-annual" class="text-xs font-semibold text-gray-500 mt-1"></p>
                </div>
                <div class="bg-white rounded-lg p-4 text-center shadow">
                    <h3 class="text-sm font-medium text-gray-500">Total Pago (Filipa)</h3>
                    <p id="total-filipa" class="text-2xl font-bold mt-1 tabular-nums">0,00 €</p>
                    <p id="effort-rate-filipa-annual" class="text-xs font-semibold text-gray-500 mt-1"></p>
                </div>
            </div>


            <main class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <div class="lg:col-span-1 space-y-8">
                    <div class="flex flex-col sm:flex-row lg:flex-col gap-4">
                        <button id="add-transaction-btn" class="w-full bg-indigo-600 text-white font-semibold py-2 px-4 rounded-lg shadow hover:bg-indigo-700">Adicionar Movimento</button>
                        <button id="add-loan-btn" class="w-full bg-green-600 text-white font-semibold py-2 px-4 rounded-lg shadow hover:bg-green-700">Adicionar Empréstimo</button>
                    </div>

                    <div class="bg-white rounded-lg shadow">
                        <div class="p-4 sm:p-6 border-b border-gray-200">
                            <h2 class="text-xl font-bold text-gray-800">Resumo Anual</h2>
                        </div>
                        <div class="p-4 sm:p-6">
                            <div class="h-64">
                                <canvas id="annual-summary-chart"></canvas>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg shadow">
                        <div class="p-4 sm:p-6 border-b border-gray-200">
                            <h2 class="text-xl font-bold text-gray-800">Totais do Mês por Subcategoria</h2>
                        </div>
                        <div class="p-4 sm:p-6">
                            <table class="min-w-full">
                                <tbody id="subcategory-totals-body" class="divide-y divide-gray-200">
                                    </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-lg shadow">
                    <div class="p-4 sm:p-6 border-b border-gray-200"><h2 class="text-xl font-bold text-gray-800">Empréstimos</h2></div>
                    <div class="p-4 sm:p-6"><table class="w-full text-sm"><thead class="border-b-2 border-gray-200"><tr class="text-left text-gray-600 font-medium">
                        <th class="py-2 font-medium">Descrição</th>
                        <th class="py-2 font-medium text-right">Restante</th>
                        <th class="py-2 font-medium text-right">Ações</th>
                    </tr></thead><tbody id="loans-container-body" class="divide-y divide-gray-200"></tbody></table></div>
                </div>
                </div>

                <div class="lg:col-span-2">
                    <div class="bg-white rounded-lg shadow overflow-hidden">
                        <div class="p-4 sm:p-6 border-b border-gray-200">
                            <div class="flex flex-col sm:flex-row justify-between sm:items-center gap-4">
                                 <h2 class="text-xl font-bold text-gray-800">Movimentos Mensais</h2>
                                 <div class="flex items-center gap-4">
                                     <div class="flex rounded-md shadow-sm">
                                        <button type="button" id="view-grouped-btn" class="view-mode-btn active relative inline-flex items-center px-3 py-1.5 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">Agrupada</button>
                                        <button type="button" id="view-detailed-btn" class="view-mode-btn -ml-px relative inline-flex items-center px-3 py-1.5 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">Detalhada</button>
                                    </div>
                                    <a id="summary-link" href="summary.php" class="text-sm font-medium text-indigo-600 hover:text-indigo-800 whitespace-nowrap">Ver resumo anual &rarr;</a>
                                 </div>
                            </div>
                            <div id="month-tabs" class="mt-4 flex flex-wrap gap-2"></div>
                        </div>

                        <div class="px-4 sm:px-6 py-4">
                            <div id="monthly-summary-container" class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                                </div>
                        </div>
                        
                        <div class="flex justify-between items-center px-4 sm:px-6 pt-4">
                            <h3 class="text-lg font-semibold text-gray-700">Contas a Pagar</h3>
                            <span id="pending-total" class="text-lg font-bold text-red-600 tabular-nums"></span>
                        </div>
                        <div class="overflow-x-auto"><table class="w-full table-fixed divide-y divide-gray-200"><thead class="bg-gray-50"><tr id="pending-header"></tr></thead><tbody id="pending-history" class="bg-white divide-y divide-gray-200"></tbody></table></div>

                        <div class="flex justify-between items-center px-4 sm:px-6 pt-4">
                            <h3 class="text-lg font-semibold text-gray-700">Entradas</h3>
                            <span id="income-total" class="text-lg font-bold text-green-600 tabular-nums"></span>
                        </div>
                        <div class="overflow-x-auto"><table class="w-full table-fixed divide-y divide-gray-200"><thead class="bg-gray-50"><tr><th class="w-[10%] px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Pessoa</th><th class="w-[15%] px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Data</th><th class="w-[45%] px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Descrição</th><th class="w-[15%] px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Valor</th><th class="w-[15%] px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Ações</th></tr></thead><tbody id="income-history" class="bg-white divide-y divide-gray-200"></tbody></table></div>

                        <div class="flex justify-between items-center px-4 sm:px-6 pt-4">                            <h3 class="text-lg font-semibold text-gray-700">Saídas (Despesas Pagas)</h3>
                            <span id="expenses-total" class="text-lg font-bold text-red-600 tabular-nums"></span>
                        </div>
                        <div class="overflow-x-auto"><table class="w-full table-fixed divide-y divide-gray-200"><thead class="bg-gray-50"><tr id="expenses-header"></tr></thead><tbody id="transactions-history" class="bg-white divide-y divide-gray-200"></tbody></table></div>
                    </div>
                </div>
            </main>
        </div>
    </div>


    <div id="chart-modal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 items-center justify-center p-4 z-40">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-3xl">
            <div class="p-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 id="chart-modal-title" class="text-xl font-bold text-gray-800">Histórico</h2>
                    <button id="close-chart-modal-btn" class="text-gray-400 hover:text-gray-800 text-3xl leading-none font-bold">&times;</button>
                </div>
                
                <div class="flex justify-center border-b border-gray-200 mb-4">
                    <button id="chart-mode-period" class="chart-mode-btn px-4 py-2 text-sm font-semibold">Período</button>
                    <button id="chart-mode-year" class="chart-mode-btn px-4 py-2 text-sm font-semibold">Anual</button>
                    <button id="chart-mode-comparison" class="chart-mode-btn px-4 py-2 text-sm font-semibold">Comparativo</button>
                </div>

                <div id="chart-period-selector" class="flex justify-center flex-wrap gap-2 mb-4">
                    <button data-months="6" class="period-btn bg-gray-200 text-gray-700 text-sm font-semibold py-1 px-3 rounded-full">6M</button>
                    <button data-months="12" class="period-btn bg-indigo-600 text-white text-sm font-semibold py-1 px-3 rounded-full">12M</button>
                    <button data-months="18" class="period-btn bg-gray-200 text-gray-700 text-sm font-semibold py-1 px-3 rounded-full">18M</button>
                    <button data-months="24" class="period-btn bg-gray-200 text-gray-700 text-sm font-semibold py-1 px-3 rounded-full">24M</button>
                    <button data-months="36" class="period-btn bg-gray-200 text-gray-700 text-sm font-semibold py-1 px-3 rounded-full">36M</button>
                    <button data-months="48" class="period-btn bg-gray-200 text-gray-700 text-sm font-semibold py-1 px-3 rounded-full">48M</button>
                </div>

                <div id="chart-year-selector" class="hidden flex justify-center flex-wrap gap-2 mb-4">
                    </div>

                <div class="h-96">
                    <canvas id="subcategory-chart-canvas"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div id="transaction-modal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 items-center justify-center p-4 z-40">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-5xl"><form id="transaction-form" class="p-6" enctype="multipart/form-data">
            <input type="hidden" id="transaction-id" name="id">
            <h2 id="modal-title" class="text-xl font-bold text-gray-800 mb-4">Novo Movimento</h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="space-y-4">
                    <div><label for="transaction-date" class="block text-sm font-medium text-gray-700">Data</label><input type="date" id="transaction-date" name="date" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></div>
                    <div><label for="transaction-description" class="block text-sm font-medium text-gray-700">Descrição Principal</label><input type="text" id="transaction-description" name="description" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" placeholder="Ex: Compras Mensais"></div>
                    <div><label for="transaction-person" class="block text-sm font-medium text-gray-700">Pago/Recebido Por</label><select id="transaction-person" name="person" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></select></div>
                    <div><label for="transaction-type" class="block text-sm font-medium text-gray-700">Tipo</label><select id="transaction-type" name="type" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"><option value="Expense">Despesa (dividida)</option><option value="Income">Rendimento (único)</option></select></div>
                     <div id="income-amount-wrapper" class="hidden"><label for="transaction-amount" class="block text-sm font-medium text-gray-700">Valor (€)</label><input type="number" id="transaction-amount" name="amount" step="0.01" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" placeholder="Ex: 1200.00"></div>
                     <div id="status-container"><label for="transaction-status" class="block text-sm font-medium text-gray-700">Estado</label><select id="transaction-status" name="status" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"><option value="paid">Pago</option><option value="pending">A pagar</option></select></div>
                     <div class="pt-2">
                        <div class="relative flex items-start">
                            <div class="flex items-center h-5">
                                <input id="is-direct-debit" name="is_direct_debit" type="checkbox" class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded">
                            </div>
                            <div class="ml-3 text-sm">
                                <label for="is-direct-debit" class="font-medium text-gray-700">Débito Direto</label>
                            </div>
                        </div>
                    </div>
                     <div id="recurring-wrapper" class="pt-2">
                        <div class="relative flex items-start">
                            <div class="flex items-center h-5">
                                <input id="is-recurring" type="checkbox" class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded">
                            </div>
                            <div class="ml-3 text-sm">
                                <label for="is-recurring" class="font-medium text-gray-700">Criar movimentos recorrentes</label>
                            </div>
                        </div>
                    </div>
                    <div id="end-date-wrapper" class="hidden">
                        <label for="transaction-end-date" class="block text-sm font-medium text-gray-700">Repetir até (data fim)</label>
                        <input type="date" id="transaction-end-date" name="end_date" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    </div>
                     <div><label for="transaction-files" class="block text-sm font-medium text-gray-700">Anexar Faturas (PDF, múltiplos)</label><input type="file" id="transaction-files" name="attachments[]" accept=".pdf" multiple class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100"></div>
                     <div id="current-files-wrapper" class="mt-2 text-sm"></div>
                </div>

                <div id="expense-items-wrapper" class="space-y-4">
                    <div class="flex justify-between items-center">
                        <h3 class="font-semibold text-gray-700">Parcelas da Despesa</h3>
                        <button type="button" id="add-item-btn" class="bg-blue-500 text-white text-sm font-semibold py-1 px-3 rounded-lg shadow hover:bg-blue-600">Adicionar Parcela</button>
                    </div>
                    <div id="transaction-items-container" class="space-y-3 max-h-96 overflow-y-auto pr-2">
                        </div>
                    <div class="mt-4 pt-4 border-t-2 border-gray-200 flex justify-between items-center font-bold text-lg">
                        <span>Total:</span>
                        <span id="total-amount-display">0,00 €</span>
                    </div>
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-4"><button type="button" class="cancel-btn bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50">Cancelar</button><button type="submit" class="bg-indigo-600 text-white font-semibold py-2 px-4 rounded-lg shadow hover:bg-indigo-700">Guardar</button></div>
        </form></div>
    </div>

    <div id="view-transaction-modal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 items-center justify-center p-4 z-40">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[90vh] flex flex-col">
            <div class="p-6 border-b">
                <div class="flex justify-between items-start">
                    <div>
                        <h2 id="view-modal-title" class="text-xl font-bold text-gray-800">Detalhes do Movimento</h2>
                        <p id="view-modal-subtitle" class="text-sm text-gray-500 mt-1"></p>
                    </div>
                    <button type="button" class="cancel-btn text-gray-400 hover:text-gray-800 text-3xl leading-none font-bold">&times;</button>
                </div>
            </div>
            <div class="p-6 overflow-y-auto">
                <h3 class="font-semibold text-gray-700 mb-2">Parcelas</h3>
                <table class="w-full text-sm">
                    <thead class="border-b-2 border-gray-200">
                        <tr class="text-left text-gray-600 font-medium">
                            <th class="py-2 font-medium w-2/5">Descrição</th>
                            <th class="py-2 font-medium w-2/5">Categoria</th>
                            <th class="py-2 font-medium text-right w-1/5">Valor</th>
                        </tr>
                    </thead>
                    <tbody id="view-modal-items-body" class="divide-y divide-gray-200">
                        </tbody>
                </table>
                <div id="view-modal-attachments-wrapper" class="mt-6 hidden">
                    <h3 class="font-semibold text-gray-700 mb-2">Anexos</h3>
                    <div id="view-modal-attachments-list" class="space-y-2">
                        </div>
                </div>
            </div>
            <div class="p-6 border-t bg-gray-50 rounded-b-lg flex justify-end items-center">
                 <span class="text-lg font-bold">Total:</span>
                 <span id="view-modal-total" class="text-lg font-bold ml-2">0,00 €</span>
            </div>
        </div>
    </div>
    
    <div id="pending-reminder-modal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 items-center justify-center p-4 z-50">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[90vh] flex flex-col">
            <div class="p-6 border-b">
                <h2 class="text-xl font-bold text-gray-800">Lembrete de Pagamentos Pendentes</h2>
                <p class="text-sm text-gray-500 mt-1">Foram encontrados os seguintes movimentos por pagar que estão vencidos ou vencem hoje.</p>
            </div>
            <div id="pending-reminder-list" class="p-6 overflow-y-auto space-y-3">
                </div>
            <div class="p-6 border-t bg-gray-50 rounded-b-lg flex justify-end items-center gap-4">
                 <button type="button" class="cancel-btn bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50">Fechar</button>
                 <button id="mark-all-pending-paid-btn" type="button" class="bg-green-600 text-white font-semibold py-2 px-4 rounded-lg shadow hover:bg-green-700">Marcar Todos como Pagos</button>
            </div>
        </div>
    </div>

 <template id="transaction-item-template">
        <div class="p-3 border rounded-lg bg-gray-50 transaction-item">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-600">Valor (€)</label>
                    <input type="number" step="0.01" class="item-amount mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm" required>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600">Pago Por</label>
                    <select class="item-person mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm"></select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600">Descrição da Parcela</label>
                    <input type="text" class="item-description mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600">Centro de Custo</label>
                    <select class="item-cost-center mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm"></select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600">Subcategoria</label>
                    <select class="item-subcategory mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm"></select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600">Sub-Subcategoria</label>
                    <select class="item-sub-subcategory mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm"></select>
                </div>
            </div>
            <button type="button" class="remove-item-btn mt-3 text-red-500 hover:text-red-700 text-xs font-semibold">Remover Parcela</button>
        </div>
    </template>
    
    <div id="loan-modal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 items-center justify-center p-4 z-40">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-md"><form id="loan-form" class="p-6"><input type="hidden" id="loan-id" name="id"><h2 id="loan-modal-title" class="text-xl font-bold text-gray-800 mb-4">Novo Empréstimo</h2><div class="space-y-4"><div><label for="loan-name" class="block text-sm font-medium text-gray-700">Descrição</label><input type="text" id="loan-name" name="name" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" placeholder="Ex: Obras Condomínio"></div><div id="loan-cost-center-wrapper"><label for="loan-cost-center" class="block text-sm font-medium text-gray-700">Centro de Custo</label><select id="loan-cost-center" name="cost_center" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></select></div><div id="loan-subcategory-wrapper"><label for="loan-subcategory" class="block text-sm font-medium text-gray-700">Subcategoria</label><select id="loan-subcategory" name="subcategory" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></select></div><div id="loan-sub-subcategory-wrapper" class="hidden"><label for="loan-sub-subcategory" class="block text-sm font-medium text-gray-700">Sub-Subcategoria</label><select id="loan-sub-subcategory" name="sub_subcategory" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></select></div><div><label for="loan-monthly-payment" class="block text-sm font-medium text-gray-700">Prestação Mensal (€)</label><input type="number" id="loan-monthly-payment" name="monthly_payment" step="0.01" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></div><div><label for="loan-start-date" class="block text-sm font-medium text-gray-700">Data da Primeira Prestação</label><input type="date" id="loan-start-date" name="start_date" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></div><div><label for="loan-end-date" class="block text-sm font-medium text-gray-700">Data da Última Prestação</label><input type="date" id="loan-end-date" name="end_date" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></div><div class="bg-gray-100 p-3 rounded-md text-center"><p class="text-sm font-medium text-gray-600">Valor Total Calculado</p><p id="loan-calculated-total" class="text-xl font-bold text-gray-800 tabular-nums">0,00 €</p></div><div><label for="loan-person" class="block text-sm font-medium text-gray-700">Pago Por</label><select id="loan-person" name="person" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></select></div></div><div class="mt-6 flex justify-end gap-4"><button type="button" class="cancel-btn bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50">Cancelar</button><button type="submit" id="loan-submit-btn" class="bg-green-600 text-white font-semibold py-2 px-4 rounded-lg shadow hover:bg-green-700">Guardar e Gerar Prestações</button></div></form></div>
    </div>

    <?php
        $initial_year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
    ?>
    <script>
        // Objeto de configuração para passar dados do PHP para o JS
        window.appConfig = {
            costSharers: <?php echo json_encode($cost_sharers); ?>,
            personColors: <?php echo json_encode($person_colors); ?>,
            personAvatarColors: <?php echo json_encode($person_avatar_colors); ?>,
            initialYear: <?php echo $initial_year; ?>
        };
    </script>
    <script src="assets/js/index.js" defer charset="UTF-8"></script>
</body>
</html>