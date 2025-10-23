<?php
// Forçar o PHP a não fazer cache do ficheiro da API
header("Cache-Control: no-cache, must-revalidate");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestor de Despesas Familiares</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f3f4f6; }
        .modal { display: none; }
        .modal.is-open { display: flex; }
        .tabular-nums { font-variant-numeric: tabular-nums; }
        .filter-link.active {
            background-color: #4f46e5;
            color: white;
        }
    </style>
</head>
<body class="p-4 sm:p-6 md:p-8">
    <div class="max-w-7xl mx-auto">
        <header class="text-center mb-8">
            <h1 class="text-3xl sm:text-4xl font-bold text-gray-800">Gestor de Despesas Familiares</h1>
            <p class="text-gray-600 mt-2">Gustavo, Mariana & Diogo</p>
        </header>

        <div class="mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4 text-center md:text-left">Saldos Finais</h2>
            <div id="final-balances-container" class="grid grid-cols-1 md:grid-cols-3 gap-4"></div>
        </div>

        <main class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <div class="lg:col-span-1 space-y-8">
                <div class="grid grid-cols-2 gap-4">
                    <div class="bg-indigo-50 border-2 border-indigo-200 rounded-lg p-4 text-center">
                        <h3 class="text-sm font-medium text-indigo-700">Saldo da Garagem</h3>
                        <p id="garage-balance" class="text-2xl font-bold mt-1 tabular-nums">0,00 €</p>
                    </div>
                    <div class="bg-white rounded-lg p-4 text-center shadow">
                        <h3 class="text-sm font-medium text-gray-500">Total Rendimentos</h3>
                        <p id="total-income" class="text-2xl font-bold mt-1 tabular-nums">0,00 €</p>
                    </div>
                    <div class="bg-white rounded-lg p-4 text-center shadow">
                        <h3 class="text-sm font-medium text-gray-500">Total Despesas</h3>
                        <p id="total-expenses" class="text-2xl font-bold mt-1 tabular-nums">0,00 €</p>
                    </div>
                    <div class="bg-white rounded-lg p-4 text-center shadow">
                        <h3 class="text-sm font-medium text-gray-500">Total a Dividir</h3>
                        <p id="net-profit" class="text-2xl font-bold mt-1 tabular-nums">0,00 €</p>
                    </div>
                    <div class="bg-white rounded-lg p-4 text-center shadow col-span-2">
                        <h3 class="text-sm font-medium text-gray-500">Quota-parte por Irmão</h3>
                        <p id="share-per-person" class="text-2xl font-bold mt-1 tabular-nums">0,00 €</p>
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row lg:flex-col gap-4">
                    <button id="add-transaction-btn" class="w-full bg-indigo-600 text-white font-semibold py-2 px-4 rounded-lg shadow hover:bg-indigo-700">Adicionar Movimento</button>
                    <button id="add-settlement-btn" class="w-full bg-green-600 text-white font-semibold py-2 px-4 rounded-lg shadow hover:bg-green-700">Registar Acerto</button>
                </div>
            </div>

            <div class="lg:col-span-2 space-y-8">
                <div id="filters-container" class="bg-white rounded-lg shadow p-4 space-y-3">
                    <div class="flex justify-between items-center">
                        <button id="prev-year-btn" class="p-2 rounded-md hover:bg-gray-100">&larr;</button>
                        <h3 id="current-year-display" class="text-lg font-bold"></h3>
                        <button id="next-year-btn" class="p-2 rounded-md hover:bg-gray-100">&rarr;</button>
                    </div>
                    <div id="month-filters" class="flex flex-wrap justify-center gap-2">
                    </div>
                </div>


                <div id="pending-container" class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="p-4 sm:p-6 border-b border-gray-200">
                        <h2 class="text-xl font-bold text-gray-800">Contas a Pagar</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Data</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Descrição</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Valor</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Ações</th>
                                </tr>
                            </thead>
                            <tbody id="pending-history" class="bg-white divide-y divide-gray-200"></tbody>
                        </table>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="p-4 sm:p-6 border-b border-gray-200 flex justify-between items-center">
                        <h2 class="text-xl font-bold text-gray-800">Histórico de Movimentos Pagos</h2>
                        <a href="summary.php" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Ver resumo gráfico &rarr;</a>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Data</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Descrição</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Valor</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Pessoa / Origem</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Ações</th>
                                </tr>
                            </thead>
                            <tbody id="transactions-history" class="bg-white divide-y divide-gray-200"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <div id="transaction-modal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 items-center justify-center p-4 overflow-y-auto">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-md my-8">
            <form id="transaction-form" class="p-6">
                <h2 id="modal-title" class="text-xl font-bold text-gray-800 mb-4">Novo Movimento</h2>
                <input type="hidden" id="transaction-id" name="id">
                <div class="space-y-4">
                    <div>
                        <label for="transaction-type" class="block text-sm font-medium text-gray-700">Tipo</label>
                        <select id="transaction-type" name="type" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                            <option value="Expense">Despesa</option>
                            <option value="Income">Rendimento</option>
                        </select>
                    </div>
                    <div>
                        <label for="transaction-date" class="block text-sm font-medium text-gray-700">Data</label>
                        <input type="date" id="transaction-date" name="date" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    </div>
                    <div>
                        <label for="transaction-description" class="block text-sm font-medium text-gray-700">Descrição</label>
                        <input type="text" id="transaction-description" name="description" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    </div>
                     <div id="cost-center-wrapper">
                        <label for="transaction-cost-center" class="block text-sm font-medium text-gray-700">Centro de Custo</label>
                        <select id="transaction-cost-center" name="cost_center" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></select>
                    </div>
                    <div>
                        <label for="transaction-amount" class="block text-sm font-medium text-gray-700">Valor Total (€)</label>
                        <input type="number" id="transaction-amount" name="amount" step="0.01" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    </div>
                    
                    <div id="income-person-wrapper">
                        <label for="transaction-person" class="block text-sm font-medium text-gray-700">Recebido Por</label>
                        <select id="transaction-person" name="person" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></select>
                    </div>

                    <div id="expense-parcels-wrapper" class="space-y-3 pt-2 border-t">
                        <label class="block text-sm font-medium text-gray-700">Pagadores (Parcelas)</label>
                        <div id="parcels-container" class="space-y-2"></div>
                        <div class="flex justify-between items-center text-sm">
                            <button type="button" id="add-parcel-btn" class="text-indigo-600 hover:text-indigo-800 font-semibold">+ Adicionar Pagador</button>
                            <div class="text-right">
                                <div>Total: <span id="parcels-total" class="font-bold">0,00 €</span></div>
                                <div>Falta: <span id="parcels-remaining" class="font-bold">0,00 €</span></div>
                            </div>
                        </div>
                    </div>

                    <div id="status-container">
                        <label for="transaction-status" class="block text-sm font-medium text-gray-700">Estado</label>
                        <select id="transaction-status" name="status" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                            <option value="paid">Pago</option>
                            <option value="pending">A pagar</option>
                        </select>
                    </div>
                    <div>
                        <label for="transaction-attachment" class="block text-sm font-medium text-gray-700">Anexo (PDF)</label>
                        <input type="file" id="transaction-attachment" name="attachment" accept="application/pdf" class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
                        <div id="attachment-info" class="mt-2 text-sm"></div>
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-4">
                    <button type="button" class="cancel-btn bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50">Cancelar</button>
                    <button type="submit" class="bg-indigo-600 text-white font-semibold py-2 px-4 rounded-lg shadow hover:bg-indigo-700">Guardar</button>
                </div>
            </form>
        </div>
    </div>

    <div id="settlement-modal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 items-center justify-center p-4">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
            <form id="settlement-form" class="p-6">
                <h2 class="text-xl font-bold text-gray-800 mb-4">Registar Acerto de Contas</h2>
                <div class="space-y-4">
                    <div>
                        <label for="settlement-date" class="block text-sm font-medium text-gray-700">Data do Acerto</label>
                        <input type="date" id="settlement-date" name="date" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    </div>
                    <div>
                        <label for="settlement-amount" class="block text-sm font-medium text-gray-700">Valor (€)</label>
                        <input type="number" id="settlement-amount" name="amount" step="0.01" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" placeholder="Valor a acertar">
                    </div>
                    <div>
                        <label for="settlement-person-from" class="block text-sm font-medium text-gray-700">Quem Pagou</label>
                        <select id="settlement-person-from" name="person_from" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></select>
                    </div>
                    <div>
                        <label for="settlement-person-to" class="block text-sm font-medium text-gray-700">Quem Recebeu</label>
                        <select id="settlement-person-to" name="person_to" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></select>
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-4">
                    <button type="button" class="cancel-btn bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50">Cancelar</button>
                    <button type="submit" class="bg-green-600 text-white font-semibold py-2 px-4 rounded-lg shadow hover:bg-green-700">Guardar Acerto</button>
                </div>
            </form>
        </div>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const UPLOAD_PATH = 'uploads/';
    const costSharers = ['Gustavo', 'Mariana', 'Diogo'];
    const allPeople = ['Mãe', 'Gustavo', 'Mariana', 'Diogo'];
    const garageFundName = 'Caixa da Garagem';
    const allPayersAndFunds = [...allPeople, garageFundName];
    const costCenters = ['Casa', 'Alimentação', 'Transportes', 'Saúde', 'Lazer', 'Outros'];
    const monthNames = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
    let allMovements = [];
    
    let state = {
        selectedYear: new Date().getFullYear(),
        selectedMonth: new Date().getMonth() + 1,
    };

    const transactionModal = document.getElementById('transaction-modal');
    const settlementModal = document.getElementById('settlement-modal');
    const parcelsContainer = document.getElementById('parcels-container');
    const addParcelBtn = document.getElementById('add-parcel-btn');
    const monthFiltersContainer = document.getElementById('month-filters');
    const currentYearDisplay = document.getElementById('current-year-display');
    const prevYearBtn = document.getElementById('prev-year-btn');
    const nextYearBtn = document.getElementById('next-year-btn');

    const formatCurrency = (value) => {
        const val = parseFloat(value) || 0;
        const formatted = val.toFixed(2);
        let parts = formatted.split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
        return parts.join(',') + ' €';
    };

    const calculateBalances = (movements) => {
        const paidMovements = movements.filter(m => m.status === 'paid');
        const balances = costSharers.reduce((acc, name) => ({ ...acc, [name]: { paid: 0, received: 0, settled_paid: 0, settled_received: 0, total_paid: 0 } }), {});

        let garageBalance = 0;
        
        paidMovements.forEach(t => {
            if (t.type === 'Income') {
                if (t.person === garageFundName) garageBalance += t.amount;
                else if (costSharers.includes(t.person)) balances[t.person].received += t.amount;
            } else if (t.type === 'Expense') {
                (t.parcels || []).forEach(p => {
                    if (p.person === garageFundName) {
                        garageBalance -= p.amount;
                    } else if (costSharers.includes(p.person)) {
                        balances[p.person].paid += p.amount;
                        balances[p.person].total_paid += p.amount;
                    }
                });
            } else if (t.type === 'Acerto') {
                if (t.person === garageFundName) garageBalance -= t.amount;
                else if (costSharers.includes(t.person)) balances[t.person].settled_paid += t.amount;
                if (costSharers.includes(t.person_to)) balances[t.person_to].settled_received += t.amount;
            }
        });
        
        const totalSharedExpenses = Object.values(balances).reduce((sum, b) => sum + b.paid, 0) + (garageBalance < 0 ? -garageBalance : 0);
        const sharePerPerson = totalSharedExpenses > 0 ? totalSharedExpenses / costSharers.length : 0;

        const finalBalances = {};
        costSharers.forEach(name => {
            const personalNetPaid = balances[name].paid - balances[name].received;
            const settlementNet = balances[name].settled_received - balances[name].settled_paid;
            
            finalBalances[name] = {
                balance: (personalNetPaid - sharePerPerson) - settlementNet,
                total_paid: balances[name].total_paid
            };
        });
        
        const totalIncomeDisplay = paidMovements.filter(t => t.type === 'Income').reduce((sum, t) => sum + t.amount, 0);
        const totalExpensesDisplay = paidMovements.filter(t => t.type === 'Expense').reduce((sum, t) => sum + t.amount, 0);
        
        const garageExpenses = paidMovements
            .filter(t => t.type === 'Expense')
            .flatMap(t => t.parcels || [])
            .filter(p => p.person === garageFundName)
            .reduce((sum, p) => sum + p.amount, 0);
        
        const netProfit = totalExpensesDisplay - garageExpenses;

        return { garageBalance, totalIncome: totalIncomeDisplay, totalExpenses: totalExpensesDisplay, netProfit, sharePerPerson, finalBalances };
    };
    
    const createAttachmentLink = (filename) => {
        if (!filename) return '';
        return `
            <div class="text-xs text-gray-500 mt-1">
                <a href="${UPLOAD_PATH}${filename}" target="_blank" class="hover:text-indigo-600 hover:underline">
                    Ver Anexo (PDF)
                </a>
            </div>`;
    };

    const setSummaryValue = (elementId, value) => {
        const element = document.getElementById(elementId);
        if (element) {
            element.textContent = formatCurrency(value);
            element.classList.remove('text-gray-900', 'text-red-600', 'text-indigo-900', 'text-green-600');
            let color = 'text-gray-900';
            if (elementId === 'net-profit' || elementId === 'total-expenses') {
                 color = 'text-red-600';
            }
            if(elementId === 'total-income') {
                color = 'text-green-600';
            }
            if (elementId === 'garage-balance') {
                 color = value >= 0 ? 'text-indigo-900' : 'text-red-600';
            }
            
            element.classList.add(color);
        }
    };

    const renderFilters = () => {
        currentYearDisplay.textContent = state.selectedYear;
        monthFiltersContainer.innerHTML = '';
        
        const allYearLink = document.createElement('a');
        allYearLink.href = '#';
        allYearLink.textContent = 'Ano Todo';
        allYearLink.className = 'filter-link cursor-pointer p-2 rounded-md hover:bg-gray-100 text-sm flex-grow text-center';
        if (state.selectedMonth === 'all') {
            allYearLink.classList.add('active');
        }
        allYearLink.onclick = (e) => {
            e.preventDefault();
            state.selectedMonth = 'all';
            renderUI();
        };
        monthFiltersContainer.appendChild(allYearLink);

        monthNames.forEach((name, index) => {
            const month = index + 1;
            const link = document.createElement('a');
            link.href = '#';
            link.textContent = name;
            link.className = 'filter-link cursor-pointer p-2 rounded-md hover:bg-gray-100 text-sm flex-grow text-center';
            if (month === state.selectedMonth) {
                link.classList.add('active');
            }
            link.onclick = (e) => {
                e.preventDefault();
                state.selectedMonth = month;
                renderUI();
            };
            monthFiltersContainer.appendChild(link);
        });
    };

    const renderUI = () => {
        const { garageBalance, totalIncome, totalExpenses, netProfit, sharePerPerson, finalBalances } = calculateBalances(allMovements);

        setSummaryValue('garage-balance', garageBalance);
        setSummaryValue('total-income', totalIncome);
        setSummaryValue('total-expenses', totalExpenses);
        setSummaryValue('net-profit', netProfit);
        setSummaryValue('share-per-person', sharePerPerson);
        
        const balancesContainer = document.getElementById('final-balances-container');
        balancesContainer.innerHTML = '';
        costSharers.forEach(name => {
            const personData = finalBalances[name];
            const balance = personData.balance;
            const totalPaid = personData.total_paid;

            const balanceColorClass = balance >= 0 ? 'text-green-600' : 'text-red-600';
            const balanceText = balance >= 0 ? `Recebe ${formatCurrency(balance)}` : `Paga ${formatCurrency(Math.abs(balance))}`;
            
            balancesContainer.innerHTML += `
                <div class="bg-white rounded-lg p-4 shadow text-center space-y-2">
                    <h3 class="text-md font-medium text-gray-800">${name}</h3>
                    <div>
                        <p class="text-xs text-gray-500">Total Pago</p>
                        <p class="text-xl font-bold text-black tabular-nums">${formatCurrency(totalPaid)}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">Saldo Final</p>
                        <p class="text-xl font-bold ${balanceColorClass} tabular-nums">${balanceText}</p>
                    </div>
                </div>`;
        });

        renderFilters();

        const filteredMovements = allMovements.filter(m => {
            const date = new Date(m.transaction_date + 'T00:00:00');
            const yearMatch = date.getFullYear() === state.selectedYear;
            const monthMatch = state.selectedMonth === 'all' || (date.getMonth() + 1) === state.selectedMonth;
            return yearMatch && monthMatch;
        });

        // --- ALTERAÇÃO 1: Nova função para gerar a célula "Pessoa/Origem" ---
        const generatePersonCell = (t) => {
            if (t.type === 'Acerto') {
                return `<span class="font-semibold">${t.person}</span> &rarr; <span class="font-semibold">${t.person_to}</span>`;
            }
            
            if (t.type === 'Income') {
                return t.person || '';
            }

            if (t.type === 'Expense' && t.parcels && t.parcels.length > 0) {
                if (t.parcels.length === 1) {
                    return t.parcels[0].person;
                }
                return t.parcels.map(p => `<div>${formatCurrency(p.amount)} - ${p.person}</div>`).join('');
            }

            return t.person || ''; // Fallback
        };
        
        // --- ALTERAÇÃO 2: A função generateDescriptionCell já não mostra os pagadores ---
        const generateDescriptionCell = (t) => {
            let costCenterHTML = '';
            if (t.type === 'Expense' && t.cost_center) {
                costCenterHTML = `<div class="text-xs text-gray-500">${t.cost_center}</div>`;
            }
            
            let mainDescription = t.description;
            let attachmentHTML = createAttachmentLink(t.attachment);

            return `
                <div>
                    ${costCenterHTML}
                    <div>${mainDescription}</div>
                    ${attachmentHTML}
                </div>`;
        };

        const paidMovements = filteredMovements.filter(m => m.status === 'paid').sort((a, b) => new Date(b.transaction_date) - new Date(a.transaction_date));
        const historyTableBody = document.getElementById('transactions-history');
        historyTableBody.innerHTML = paidMovements.map(t => {
            const amountColor = t.type === 'Income' ? 'text-green-600' : (t.type === 'Expense' ? 'text-red-600' : 'text-blue-600');
            const sign = t.type === 'Income' ? '+' : '-';
            
            const descriptionCell = generateDescriptionCell(t);
            const personCell = generatePersonCell(t); // Usar a nova função
            
            const editButton = t.type !== 'Acerto'
                ? `<button class="text-gray-600 hover:text-gray-900" onclick="editMovement(${t.id})" title="Editar movimento">Editar</button>`
                : '';
            
            return `
                <tr>
                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">${new Date(t.transaction_date + 'T00:00:00').toLocaleDateString('pt-PT')}</td>
                    <td class="px-4 py-3 text-sm font-medium text-gray-800">${descriptionCell}</td>
                    <td class="px-4 py-3 whitespace-nowrap text-sm font-semibold text-right ${amountColor}">${sign} ${formatCurrency(t.amount)}</td>
                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">${personCell}</td>
                    <td class="px-4 py-3 whitespace-nowrap text-right text-sm font-medium space-x-2">
                        ${editButton}
                        <button class="text-blue-600 hover:text-blue-900" onclick="duplicateMovement(${t.id})" title="Duplicar movimento">Duplicar</button>
                        <button class="text-red-600 hover:text-red-900" onclick="deleteMovement(${t.id})">Apagar</button>
                    </td>
                </tr>`;
        }).join('');
        if (paidMovements.length === 0) {
             historyTableBody.innerHTML = '<tr><td colspan="5" class="px-4 py-4 text-center text-sm text-gray-500">Nenhum movimento pago para este período.</td></tr>';
        }

        const pendingMovements = filteredMovements.filter(m => m.status === 'pending').sort((a, b) => new Date(a.transaction_date) - new Date(b.transaction_date));
        const pendingTableBody = document.getElementById('pending-history');
        // --- ALTERAÇÃO 3: Na tabela de pendentes, a info do pagador já não aparece ---
        pendingTableBody.innerHTML = pendingMovements.map(t => {
            const descriptionCell = generateDescriptionCell(t); // Esta função já não mostra pagadores
            return `
                <tr>
                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">${new Date(t.transaction_date + 'T00:00:00').toLocaleDateString('pt-PT')}</td>
                    <td class="px-4 py-3 text-sm font-medium text-gray-800">${descriptionCell}</td>
                    <td class="px-4 py-3 whitespace-nowrap text-sm font-semibold text-right text-red-600">${formatCurrency(t.amount)}</td>
                    <td class="px-4 py-3 whitespace-nowrap text-right text-sm font-medium space-x-2">
                        <button class="text-green-600 hover:text-green-900" onclick="markAsPaid(${t.id})">Pagar</button>
                        <button class="text-gray-600 hover:text-gray-900" onclick="editMovement(${t.id})">Editar</button>
                        <button class="text-blue-600 hover:text-blue-900" onclick="duplicateMovement(${t.id})">Duplicar</button>
                        <button class="text-red-600 hover:text-red-900" onclick="deleteMovement(${t.id})">Apagar</button>
                    </td>
                </tr>`;
        }).join('');

        if (pendingMovements.length === 0) {
            pendingTableBody.innerHTML = '<tr><td colspan="4" class="px-4 py-4 text-center text-sm text-gray-500">Nenhuma conta pendente para este período.</td></tr>';
        }
    };
    
    const populateDropdowns = () => {
        const personDropdown = document.getElementById('transaction-person');
        const settlementFromDropdown = document.getElementById('settlement-person-from');
        const settlementToDropdown = document.getElementById('settlement-person-to');
        const costCenterSelect = document.getElementById('transaction-cost-center');
        
        personDropdown.innerHTML = allPayersAndFunds.map(p => `<option value="${p}">${p}</option>`).join('');
        settlementFromDropdown.innerHTML = allPayersAndFunds.map(p => `<option value="${p}">${p}</option>`).join('');
        settlementToDropdown.innerHTML = costSharers.map(p => `<option value="${p}">${p}</option>`).join('');
        costCenterSelect.innerHTML = costCenters.map(c => `<option value="${c}">${c}</option>`).join('');
    };

    const fetchData = async () => {
        try {
            const response = await fetch('api.php?action=get');
            if (!response.ok) throw new Error('Erro de ligação ao servidor.');
            const result = await response.json();
            if (result.success && Array.isArray(result.data)) {
                allMovements = result.data;
                renderUI();
            } else {
                throw new Error(result.error || 'A resposta do servidor não é válida.');
            }
        } catch (error) { alert(error.message); }
    };

    const handleApiResponse = async (response, successCallback) => {
        if (!response.ok) {
            alert(`Erro do Servidor: ${response.status} ${response.statusText}`);
            return;
        }
        const result = await response.json();
        if (result.success) {
            successCallback(result);
        } else {
            alert('Erro: ' + (result.error || 'Ocorreu um problema no servidor.'));
        }
    };

    const createParcelRow = (parcel = {}) => {
        const row = document.createElement('div');
        row.className = 'flex items-center gap-2 parcel-row';
        
        let optionsHTML = allPayersAndFunds.map(p => `<option value="${p}" ${p === parcel.person ? 'selected' : ''}>${p}</option>`).join('');

        row.innerHTML = `
            <select class="parcel-person block w-2/3 rounded-md border-gray-300 shadow-sm text-sm">${optionsHTML}</select>
            <input type="number" step="0.01" placeholder="Valor" value="${parcel.amount || ''}" class="parcel-amount block w-1/3 rounded-md border-gray-300 shadow-sm text-sm">
            <button type="button" class="remove-parcel-btn text-red-500 hover:text-red-700 font-bold">&times;</button>
        `;
        parcelsContainer.appendChild(row);
    };

    const updateParcelsTotal = () => {
        const totalAmountInput = document.getElementById('transaction-amount');
        const parcelsTotalEl = document.getElementById('parcels-total');
        const parcelsRemainingEl = document.getElementById('parcels-remaining');
        
        const totalAmount = parseFloat(totalAmountInput.value) || 0;
        let parcelsTotal = 0;
        document.querySelectorAll('.parcel-row').forEach(row => {
            const amountInput = row.querySelector('.parcel-amount');
            parcelsTotal += parseFloat(amountInput.value) || 0;
        });
        
        const remaining = totalAmount - parcelsTotal;
        parcelsTotalEl.textContent = formatCurrency(parcelsTotal);
        parcelsRemainingEl.textContent = formatCurrency(remaining);
        parcelsRemainingEl.className = Math.abs(remaining) > 0.01 ? 'font-bold text-red-600' : 'font-bold text-green-600';
    };

    addParcelBtn.addEventListener('click', () => createParcelRow());

    parcelsContainer.addEventListener('click', (e) => {
        if (e.target.classList.contains('remove-parcel-btn')) {
            e.target.parentElement.remove();
            updateParcelsTotal();
        }
    });
    
    transactionModal.addEventListener('input', (e) => {
        if (e.target.classList.contains('parcel-amount') || e.target.id === 'transaction-amount') {
            updateParcelsTotal();
        }
    });

    window.editMovement = (id) => {
        const movement = allMovements.find(m => m.id === id);
        if (!movement) {
            alert('Movimento não encontrado!');
            return;
        }
        
        const transactionForm = document.getElementById('transaction-form');
        const modalTitle = document.getElementById('modal-title');
        
        transactionForm.reset();
        modalTitle.textContent = 'Editar Movimento';

        document.getElementById('transaction-id').value = movement.id;
        document.getElementById('transaction-date').value = movement.transaction_date;
        document.getElementById('transaction-type').value = movement.type;
        document.getElementById('transaction-description').value = movement.description;
        document.getElementById('transaction-amount').value = movement.amount;
        document.getElementById('transaction-status').value = movement.status;
        
        document.getElementById('transaction-type').dispatchEvent(new Event('change'));
        
        if(movement.cost_center) {
            document.getElementById('transaction-cost-center').value = movement.cost_center;
        }

        parcelsContainer.innerHTML = '';
        if (movement.type === 'Expense') {
            if(movement.parcels && movement.parcels.length > 0) {
                movement.parcels.forEach(p => createParcelRow(p));
            } else {
                createParcelRow();
            }
        } else if (movement.type === 'Income') {
             document.getElementById('transaction-person').value = movement.person;
        }
        updateParcelsTotal();
        
        const attachmentInfo = document.getElementById('attachment-info');
        if (movement.attachment) {
            attachmentInfo.innerHTML = `
                Anexo atual: 
                <a href="${UPLOAD_PATH}${movement.attachment}" target="_blank" class="text-indigo-600 hover:underline">${movement.attachment}</a>
                <button type="button" onclick="deleteAttachment(${movement.id})" class="ml-2 text-red-500 hover:text-red-700 font-semibold" title="Remover anexo">(&times;)</button>
                <br><span class="text-xs text-gray-500">Para substituir, escolha um novo ficheiro.</span>`;
        } else {
            attachmentInfo.innerHTML = 'Nenhum anexo associado.';
        }

        transactionModal.classList.add('is-open');
    };

    window.duplicateMovement = async (id) => {
        if (confirm('Deseja duplicar este movimento? Será criado um novo registo com a data de hoje. O anexo não será copiado.')) {
            try {
                const response = await fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'duplicate', id: id }) });
                await handleApiResponse(response, fetchData);
            } catch (error) { alert(error.message); }
        }
    };

    window.deleteMovement = async (id) => {
        if (confirm('Tem a certeza que quer apagar este movimento? O anexo associado também será apagado.')) {
            try {
                const response = await fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'delete', id: id }) });
                await handleApiResponse(response, fetchData);
            } catch (error) { alert(error.message); }
        }
    };

    window.markAsPaid = async (id) => {
         if (confirm('Marcar esta despesa como paga?')) {
            try {
                const response = await fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'update_status', id: id, status: 'paid' }) });
                await handleApiResponse(response, fetchData);
            } catch (error) { alert(error.message); }
        }
    };

    document.getElementById('add-transaction-btn').addEventListener('click', () => {
        const transactionForm = document.getElementById('transaction-form');
        const modalTitle = document.getElementById('modal-title');
        const attachmentInfo = document.getElementById('attachment-info');
        
        transactionForm.reset();
        modalTitle.textContent = 'Novo Movimento';
        attachmentInfo.innerHTML = '';
        document.getElementById('transaction-date').valueAsDate = new Date();
        document.getElementById('transaction-type').dispatchEvent(new Event('change'));
        
        parcelsContainer.innerHTML = '';
        createParcelRow();
        updateParcelsTotal();
        
        transactionModal.classList.add('is-open');
    });

    document.getElementById('add-settlement-btn').addEventListener('click', () => {
        const settlementForm = document.getElementById('settlement-form');
        settlementForm.reset();
        document.getElementById('settlement-date').valueAsDate = new Date();
        settlementModal.classList.add('is-open');
    });

    document.querySelectorAll('.cancel-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            transactionModal.classList.remove('is-open');
            settlementModal.classList.remove('is-open');
        });
    });

    prevYearBtn.addEventListener('click', () => {
        state.selectedYear--;
        renderUI();
    });

    nextYearBtn.addEventListener('click', () => {
        state.selectedYear++;
        renderUI();
    });

    document.getElementById('transaction-type').addEventListener('change', (e) => {
        const isExpense = e.target.value === 'Expense';
        document.getElementById('status-container').style.display = isExpense ? 'block' : 'none';
        document.getElementById('cost-center-wrapper').style.display = isExpense ? 'block' : 'none';
        document.getElementById('expense-parcels-wrapper').style.display = isExpense ? 'block' : 'none';
        document.getElementById('income-person-wrapper').style.display = isExpense ? 'none' : 'block';
    });

    document.getElementById('transaction-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        
        const formData = new FormData(e.target);
        const type = formData.get('type');
        
        if(type === 'Expense') {
            const parcels = [];
            document.querySelectorAll('.parcel-row').forEach(row => {
                const person = row.querySelector('.parcel-person').value;
                const amount = parseFloat(row.querySelector('.parcel-amount').value);
                if (person && amount > 0) {
                    parcels.push({ person, amount });
                }
            });

            const totalAmount = parseFloat(formData.get('amount')) || 0;
            const parcelsTotal = parcels.reduce((sum, p) => sum + p.amount, 0);

            if (Math.abs(totalAmount - parcelsTotal) > 0.01) {
                alert('O valor total do movimento não corresponde à soma das parcelas!');
                return;
            }
            formData.append('parcels', JSON.stringify(parcels));
        }
        
        const action = formData.get('id') ? 'update' : 'add';
        formData.append('action', action);
        
        if (formData.get('type') === 'Income') {
            formData.set('cost_center', '');
        }

        try {
            const response = await fetch('api.php', {
                method: 'POST',
                body: formData
            });
            await handleApiResponse(response, () => {
                transactionModal.classList.remove('is-open');
                fetchData();
            });
        } catch(error) { alert(error.message); }
    });
    
    document.getElementById('settlement-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const formData = new FormData(e.target);
        const data = Object.fromEntries(formData.entries());
        if (data.person_from === data.person_to) {
            alert('Não pode fazer um acerto para a mesma pessoa.');
            return;
        }
        data.action = 'add_settlement';
        
        try {
            const response = await fetch('api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            await handleApiResponse(response, () => {
                settlementModal.classList.remove('is-open');
                fetchData();
            });
        } catch(error) { alert(error.message); }
    });

    populateDropdowns();
    fetchData();
});
</script>
</body>
</html>