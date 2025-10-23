// Ficheiro JS para index.php
document.addEventListener('DOMContentLoaded', function () {
    // --- CONFIGURAÇÃO (Injetada pelo PHP via window.appConfig) ---
    const { costSharers, personColors, personAvatarColors, initialYear } = window.appConfig;
    const API_ENDPOINT = 'api.php';
    const MONTH_NAMES = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
    
    let allMovements = [];
    let allLoans = [];
    let costCenters = {};
    let flatSubcategories = {};
    let availableYears = [];
    let currentUser = null;
    let subcategoryChart = null;
    let annualSummaryChart = null; 
    let currentChartContext = { type: 'expense', costCenter: null, subcategory: null, subSubcategory: null, description: null, person: null, view: { mode: 'period', value: 12 } };
    let combineMode = { active: false, sourceId: null, sourceDescription: '' };
    let currentViewMode = 'grouped';

    let selectedYear = initialYear;
    let selectedMonth = new Date().getMonth();
    
    // --- ELEMENTOS DO DOM ---
    const appContainer = document.getElementById('app-container');
    const userSelectionModal = document.getElementById('user-selection-modal');
    const userSelectionButtons = document.getElementById('user-selection-buttons');
    const userDisplay = document.getElementById('user-display');
    const transactionModal = document.getElementById('transaction-modal');
    const transactionForm = document.getElementById('transaction-form');
    const viewTransactionModal = document.getElementById('view-transaction-modal');
    const loanModal = document.getElementById('loan-modal');
    const loanForm = document.getElementById('loan-form');
    const incomeContainer = document.getElementById('income-history');
    const historyContainer = document.getElementById('transactions-history');
    const pendingContainer = document.getElementById('pending-history');
    const expensesHeader = document.getElementById('expenses-header');
    const pendingHeader = document.getElementById('pending-header');
    const combineModeBanner = document.getElementById('combine-mode-banner');
    const pendingReminderModal = document.getElementById('pending-reminder-modal');
    const pendingReminderList = document.getElementById('pending-reminder-list');
    const markAllPendingPaidBtn = document.getElementById('mark-all-pending-paid-btn');
    const loanCostCenterSelect = document.getElementById('loan-cost-center');
    const loanSubcategorySelect = document.getElementById('loan-subcategory');
    const loanSubSubcategoryWrapper = document.getElementById('loan-sub-subcategory-wrapper');
    const loanSubSubcategorySelect = document.getElementById('loan-sub-subcategory');
    const modalTitle = document.getElementById('modal-title');
    const transactionIdInput = document.getElementById('transaction-id');
    const yearSelect = document.getElementById('year-select');
    const monthTabsContainer = document.getElementById('month-tabs');
    const currentFilesWrapper = document.getElementById('current-files-wrapper');
    const chartModal = document.getElementById('chart-modal');
    const closeChartModalBtn = document.getElementById('close-chart-modal-btn');
    const loanMonthlyPaymentInput = document.getElementById('loan-monthly-payment');
    const loanStartDateInput = document.getElementById('loan-start-date');
    const loanEndDateInput = document.getElementById('loan-end-date');
    const loanCalculatedTotalEl = document.getElementById('loan-calculated-total');
    const chartModePeriodBtn = document.getElementById('chart-mode-period');
    const chartModeYearBtn = document.getElementById('chart-mode-year');
    const chartModeComparisonBtn = document.getElementById('chart-mode-comparison');
    const chartPeriodSelector = document.getElementById('chart-period-selector');
    const chartYearSelector = document.getElementById('chart-year-selector');
    const menuBtn = document.getElementById('menu-btn');
    const mainMenu = document.getElementById('main-menu');
    const transactionItemsContainer = document.getElementById('transaction-items-container');
    const totalAmountDisplay = document.getElementById('total-amount-display');
    const viewGroupedBtn = document.getElementById('view-grouped-btn');
    const viewDetailedBtn = document.getElementById('view-detailed-btn');
    const isRecurringCheckbox = document.getElementById('is-recurring');
    const endDateWrapper = document.getElementById('end-date-wrapper');
    const transactionEndDate = document.getElementById('transaction-end-date');


    // --- FUNÇÕES DE GESTÃO DE UTILIZADOR ---
    window.selectUser = (name) => {
        localStorage.setItem('currentUser', name);
        currentUser = name;
        userSelectionModal.classList.remove('is-open');
        appContainer.classList.remove('blurred');
        initializeAppView();
    };

    const promptUserSelection = () => {
        userSelectionButtons.innerHTML = costSharers.map(name => 
            `<button class="w-full bg-indigo-600 text-white font-semibold py-3 px-4 rounded-lg shadow hover:bg-indigo-700 text-lg" onclick="selectUser('${name}')">${name}</button>`
        ).join('');
        userSelectionModal.classList.add('is-open');
        appContainer.classList.add('blurred');
    };

    window.logout = () => {
        localStorage.removeItem('currentUser');
        currentUser = null;
        location.reload();
    };
    
    const initializeAppView = () => {
        userDisplay.innerHTML = `
            <span>A usar como: <strong class="font-semibold text-gray-800">${currentUser}</strong></span>
            <button onclick="logout()" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">(Mudar)</button>
        `;
        fetchData();
    };


    // --- FUNÇÕES PRINCIPAIS ---
    const formatCurrency = (value) => {
        const val = parseFloat(value) || 0;
        const formatted = val.toFixed(2);
        let parts = formatted.split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
        return parts.join(',') + ' €';
    };   
    
    const calculateLoanTotal = () => {
        const monthlyPayment = parseFloat(loanMonthlyPaymentInput.value);
        const startDate = loanStartDateInput.value;
        const endDate = loanEndDateInput.value;
        if (isNaN(monthlyPayment) || monthlyPayment <= 0 || !startDate || !endDate) {
            loanCalculatedTotalEl.textContent = formatCurrency(0);
            return;
        }
        const start = new Date(startDate);
        const end = new Date(endDate);
        if (start > end) {
            loanCalculatedTotalEl.textContent = 'Data inválida';
            return;
        }
        const months = (end.getFullYear() - start.getFullYear()) * 12 + (end.getMonth() - start.getMonth()) + 1;
        if (months <= 0) {
            loanCalculatedTotalEl.textContent = formatCurrency(0);
            return;
        }
        const totalAmount = months * monthlyPayment;
        loanCalculatedTotalEl.textContent = formatCurrency(totalAmount);
    };
    
    const updateLoanSubSubcategories = (selectedSubCat, selectedSubSubCat = null) => {
        const parent = flatSubcategories[selectedSubCat];
        if(parent && parent.children) {
            loanSubSubcategorySelect.innerHTML = '<option value="">-- Opcional --</option>' + Object.values(parent.children).map(ssc => `<option value="${ssc.name}">${ssc.name}</option>`).join('');
            if (selectedSubSubCat) loanSubSubcategorySelect.value = selectedSubSubCat;
            loanSubSubcategoryWrapper.classList.remove('hidden');
        } else {
            loanSubSubcategoryWrapper.classList.add('hidden');
            loanSubSubcategorySelect.innerHTML = '';
        }
    };

    const updateLoanSubcategories = (selectedCostCenter, selectedSubCat = null, selectedSubSubCat = null) => {
        const costCenter = Object.values(costCenters).find(cc => cc.name === selectedCostCenter);
        loanSubcategorySelect.innerHTML = '<option value="">-- Selecione --</option>';
        if (costCenter && costCenter.subcategories) {
             loanSubcategorySelect.innerHTML += Object.values(costCenter.subcategories).map(sc => `<option value="${sc.id}">${sc.name}</option>`).join('');
             if (selectedSubCat) {
                const subCatId = Object.keys(flatSubcategories).find(key => flatSubcategories[key].name === selectedSubCat);
                if (subCatId) {
                    loanSubcategorySelect.value = subCatId;
                }
            }
        }
        updateLoanSubSubcategories(loanSubcategorySelect.value, selectedSubSubCat);
    };

    const populateDropdowns = () => {
        const personOptions = costSharers.map(p => `<option value="${p}">${p}</option>`).join('');
        document.getElementById('transaction-person').innerHTML = personOptions;
        document.getElementById('loan-person').innerHTML = personOptions;
        const costCenterOptions = '<option value="">-- Selecione --</option>' + Object.values(costCenters).map(c => `<option value="${c.name}">${c.name}</option>`).join('');
        loanCostCenterSelect.innerHTML = costCenterOptions;
        updateLoanSubcategories(loanCostCenterSelect.value);
    };

    const calculateAnnualTotals = (movements) => {
        const paidMovements = movements.filter(m => m.status === 'paid' && new Date(m.transaction_date).getFullYear() == selectedYear);
        const personTotals = costSharers.reduce((acc, name) => ({ ...acc, [name]: { income: 0, expenses: 0 } }), {});
        let totalIncome = 0;
        let totalExpenses = 0;
        paidMovements.forEach(t => {
            if (t.type === 'Income') {
                totalIncome += t.amount;
                if (personTotals.hasOwnProperty(t.person)) {
                    personTotals[t.person].income += t.amount;
                }
            }
            if (t.type === 'Expense' && t.items) {
                t.items.forEach(item => {
                    const itemAmount = parseFloat(item.amount) || 0;
                    const payer = item.person || t.person;
                    totalExpenses += itemAmount;
                    if (personTotals.hasOwnProperty(payer)) {
                        personTotals[payer].expenses += itemAmount;
                    }
                });
            }
        });
        return { totalIncome, totalExpenses, personTotals };
    };

    const setSummaryValue = (elementId, value) => {
        const element = document.getElementById(elementId);
        if(element) {
            element.textContent = formatCurrency(value);
            element.classList.toggle('text-red-600', value < 0);
        }
    };
    
    const renderUI = () => {
        const { totalIncome, totalExpenses, personTotals } = calculateAnnualTotals(allMovements);
        setSummaryValue('total-income', totalIncome);
        setSummaryValue('total-expenses', totalExpenses);
        for(const person in personTotals) {
             const formattedName = person.toLowerCase();
             setSummaryValue(`total-${formattedName}`, personTotals[person].expenses);
             const effortRateEl = document.getElementById(`effort-rate-${formattedName}-annual`);
             if (effortRateEl) {
                 if (personTotals[person].income > 0) {
                     const rate = (personTotals[person].expenses / personTotals[person].income) * 100;
                     effortRateEl.textContent = `Taxa de Esforço: ${rate.toFixed(1)}%`;
                 } else {
                     effortRateEl.textContent = 'Taxa de Esforço: N/A';
                 }
             }
        }
        const loansContainer = document.getElementById('loans-container-body');
        if (loansContainer) {
            if (allLoans.length === 0) {
                loansContainer.innerHTML = '<tr><td colspan="3" class="py-2 text-sm text-gray-500 text-center">Nenhum empréstimo registado.</td></tr>';
            } else {
                loansContainer.innerHTML = allLoans.map(loan => {
                    const remaining = parseFloat(loan.total_amount) - parseFloat(loan.amount_paid);
                    return `<tr>
                        <td class="py-2 font-medium text-gray-700">${loan.name}</td>
                        <td class="py-2 font-semibold text-gray-900 tabular-nums text-right">${formatCurrency(remaining)}</td>
                        <td class="py-2 font-medium text-right">
                            <div class="flex items-center justify-end space-x-0">
                                <button title="Editar" class="p-1 text-indigo-600 hover:text-indigo-900 hover:bg-indigo-100 rounded-full" onclick="editLoan(${loan.id})"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M17.414 2.586a2 2 0 00-2.828 0L7 10.172V13h2.828l7.586-7.586a2 2 0 000-2.828z" /><path fill-rule="evenodd" d="M2 6a2 2 0 012-2h4a1 1 0 010 2H4v10h10v-4a1 1 0 112 0v4a2 2 0 01-2 2H4a2 2 0 01-2-2V6z" clip-rule="evenodd" /></svg></button>
                                <button title="Apagar" class="p-1 text-red-600 hover:text-red-900 hover:bg-red-100 rounded-full" onclick="deleteLoan(${loan.id})"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" /></svg></button>
                            </div>
                        </td>
                    </tr>`;
                }).join('');
            }
        }
        renderAnnualSummaryChart();
        renderMonthlyHistory();
    };

    const renderMonthlyHistory = () => {
        const yearMovements = allMovements.filter(m => new Date(m.transaction_date).getFullYear() == selectedYear);
        const isYearView = selectedMonth === -1; 
        const monthlySummaryContainer = document.getElementById('monthly-summary-container');
        const subcategoryTotalsTitle = document.querySelector('#subcategory-totals-body').closest('.bg-white.rounded-lg.shadow').querySelector('h2');
        if (isYearView) {
            monthlySummaryContainer.classList.remove('hidden');
            subcategoryTotalsTitle.textContent = 'Totais do Ano por Subcategoria';
        } else {
            monthlySummaryContainer.classList.remove('hidden');
            subcategoryTotalsTitle.textContent = 'Totais do Mês por Subcategoria';
        }
        const monthMovements = isYearView ? yearMovements : yearMovements.filter(m => new Date(m.transaction_date).getMonth() == selectedMonth);
        const monthPaidMovements = monthMovements.filter(m => m.status === 'paid');
        const monthPendingMovements = monthMovements.filter(m => m.status === 'pending');
        const summaryMovements = isYearView ? yearMovements.filter(m => m.status === 'paid') : monthPaidMovements;
        const personSummaryMetrics = costSharers.reduce((acc, name) => ({ ...acc, [name]: { income: 0, expenses: 0 } }), {});
        summaryMovements.forEach(t => {
            if (t.type === 'Income') {
                if (personSummaryMetrics.hasOwnProperty(t.person)) {
                    personSummaryMetrics[t.person].income += t.amount;
                }
            } else if (t.type === 'Expense' && t.items) {
                t.items.forEach(item => {
                    const payer = item.person || t.person;
                    if (personSummaryMetrics.hasOwnProperty(payer)) {
                        personSummaryMetrics[payer].expenses += parseFloat(item.amount) || 0;
                    }
                });
            }
        });
        if (monthlySummaryContainer) {
            let summaryHTML = '';
            const periodLabel = isYearView ? 'Ano' : 'Mês';
            costSharers.forEach(person => {
                const metrics = personSummaryMetrics[person];
                summaryHTML += `
                    <div class="bg-green-50 rounded-lg p-3 text-center border border-green-200">
                        <h4 class="text-sm font-medium text-green-800">Entradas (${periodLabel}) ${person}</h4>
                        <p class="text-xl font-bold text-green-600 mt-1 tabular-nums">${formatCurrency(metrics.income)}</p>
                    </div>
                    <div class="bg-red-50 rounded-lg p-3 text-center border border-red-200">
                        <h4 class="text-sm font-medium text-red-800">Saídas (${periodLabel}) ${person}</h4>
                        <p class="text-xl font-bold text-red-600 mt-1 tabular-nums">${formatCurrency(metrics.expenses)}</p>
                    </div>
                `;
            });
            monthlySummaryContainer.innerHTML = summaryHTML;
        }
        const monthIncomeMovements = monthPaidMovements.filter(t => t.type === 'Income');
        const totalIncomeMonth = monthIncomeMovements.reduce((sum, item) => sum + item.amount, 0);
        document.getElementById('income-total').textContent = formatCurrency(totalIncomeMonth);
        incomeContainer.innerHTML = monthIncomeMovements.map(t => {
                const rowColor = personColors[t.person] || '';
                const avatarColor = personAvatarColors[t.person] || 'bg-gray-400 text-white';
                const descriptionHTML = `<span class="cursor-pointer hover:underline income-chart-trigger" data-id="${t.id}">${t.description}</span>`;
                return `<tr id="transaction-row-${t.id}" class="${rowColor}">
                    <td class="px-4 py-3"><div title="${t.person}" class="mx-auto h-6 w-6 rounded-full flex items-center justify-center text-xs font-bold ${avatarColor}">${t.person.charAt(0)}</div></td>
                    <td class="px-4 py-3 text-sm text-gray-500">${new Date(t.transaction_date + 'T00:00:00').toLocaleDateString('pt-PT')}</td>
                    <td class="px-4 py-3 text-sm font-medium text-gray-800">${descriptionHTML}</td>
                    <td class="px-4 py-3 text-sm font-semibold text-right text-green-600">+ ${formatCurrency(t.amount)}</td>
                    <td class="px-4 py-3 text-right text-sm font-medium">${renderActionButtons(t)}</td>
                </tr>`;
            }).join('');
        if(monthIncomeMovements.length === 0) incomeContainer.innerHTML = '<tr><td colspan="5" class="px-4 py-4 text-center text-gray-500">Nenhuma entrada este mês.</td></tr>';
        const monthExpensePaid = monthPaidMovements.filter(t => t.type === 'Expense');
        const monthExpensePending = monthPendingMovements.filter(t => t.type === 'Expense');
        if (currentViewMode === 'grouped') {
            renderGroupedHistory(monthExpensePaid, monthExpensePending);
        } else {
            renderDetailedHistory(monthExpensePaid, monthExpensePending);
        }
        const subcategoryTotalsContainer = document.getElementById('subcategory-totals-body');
        const totals = {};
        monthExpensePaid.forEach(t => {
            if (t.items && t.items.length > 0) {
                t.items.forEach(item => {
                    if (!item.cost_center || !item.subcategory) return;
                    const key = `${item.cost_center}|${item.subcategory}`;
                    if (!totals[key]) {
                        totals[key] = { total: 0, displayName: item.subcategory, cost_center: item.cost_center, subcategory: item.subcategory };
                    }
                    totals[key].total += parseFloat(item.amount);
                });
            }
        });
        const sortedTotals = Object.values(totals).sort((a, b) => b.total - a.total);
        if (subcategoryTotalsContainer) {
            if (sortedTotals.length === 0) {
                subcategoryTotalsContainer.innerHTML = `<tr><td colspan="2" class="py-2 text-sm text-gray-500 text-center">Sem despesas ${isYearView ? 'este ano' : 'este mês'}.</td></tr>`;
            } else {
                subcategoryTotalsContainer.innerHTML = sortedTotals.map(item => `
                    <tr class="flex justify-between items-center">
                        <td class="py-2 text-sm font-medium text-gray-700">
                            <span class="cursor-pointer hover:underline subcategory-chart-trigger" 
                                  data-cc="${item.cost_center.replace(/"/g, '&quot;')}" 
                                  data-sc="${item.subcategory.replace(/"/g, '&quot;')}" 
                                  data-ssc="null">
                                ${item.displayName}
                            </span>
                        </td>
                        <td class="py-2 text-sm font-semibold text-gray-900">${formatCurrency(item.total)}</td>
                    </tr>`).join('');
            }
        }
        document.querySelectorAll('.subcategory-chart-trigger').forEach(trigger => {
            trigger.addEventListener('click', (event) => {
                const { cc, sc, ssc } = event.currentTarget.dataset;
                showSubcategoryChart(cc, sc, ssc === 'null' ? null : ssc);
            });
        });
        document.querySelectorAll('.income-chart-trigger').forEach(trigger => {
            trigger.addEventListener('click', (event) => {
                const id = event.currentTarget.dataset.id;
                showIncomeChart(id);
            });
        });
        monthTabsContainer.innerHTML = `
            <button data-month="-1" class="month-tab text-sm font-semibold py-1 px-3 rounded-full ${isYearView ? 'active' : ''}">Ano Inteiro</button>
            ${MONTH_NAMES.map((name, index) => `<button data-month="${index}" class="month-tab text-sm font-semibold py-1 px-3 rounded-full ${index === selectedMonth ? 'active' : ''}">${name}</button>`).join('')}
        `;
        document.querySelectorAll('.month-tab').forEach(tab => tab.addEventListener('click', () => {
            selectedMonth = parseInt(tab.dataset.month);
            renderMonthlyHistory();
        }));
    };

    const renderGroupedHistory = (paidMovements, pendingMovements) => {
        const headerHTML = `
            <th class="w-[10%] px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Pessoa</th>
            <th class="w-[15%] px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Data</th>
            <th class="w-[40%] px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Descrição</th>
            <th class="w-[20%] px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Valor</th>
            <th class="w-[15%] px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Ações</th>`;
        expensesHeader.innerHTML = headerHTML;
        pendingHeader.innerHTML = headerHTML;
        const totalExpensesMonth = paidMovements.reduce((sum, item) => sum + item.amount, 0);
        document.getElementById('expenses-total').textContent = formatCurrency(totalExpensesMonth);
        historyContainer.innerHTML = paidMovements.map(t => renderGroupedRow(t)).join('');
        if (paidMovements.length === 0) historyContainer.innerHTML = '<tr><td colspan="5" class="px-4 py-4 text-center text-gray-500">Nenhuma despesa paga este mês.</td></tr>';
        const totalPending = pendingMovements.reduce((sum, item) => sum + item.amount, 0);
        document.getElementById('pending-total').textContent = formatCurrency(totalPending);
        pendingContainer.innerHTML = pendingMovements.map(t => renderGroupedRow(t)).join('');
        if (pendingMovements.length === 0) pendingContainer.innerHTML = '<tr><td colspan="5" class="px-4 py-3 text-center text-sm text-gray-500">Nenhuma conta pendente este mês.</td></tr>';
    };

    const renderDetailedHistory = (paidMovements, pendingMovements) => {
        const headerHTML = `
            <th class="w-[10%] px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Pessoa</th>
            <th class="w-[15%] px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Data</th>
            <th class="w-[45%] px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Descrição</th>
            <th class="w-[15%] px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Valor</th>
            <th class="w-[15%] px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Ações</th>`;
        expensesHeader.innerHTML = headerHTML;
        pendingHeader.innerHTML = headerHTML;
        const totalExpensesMonth = paidMovements.reduce((sum, item) => sum + item.amount, 0);
        document.getElementById('expenses-total').textContent = formatCurrency(totalExpensesMonth);
        historyContainer.innerHTML = paidMovements.flatMap(t => t.items.map(item => renderDetailedRow(t, item))).join('');
        if (paidMovements.length === 0) historyContainer.innerHTML = '<tr><td colspan="5" class="px-4 py-4 text-center text-gray-500">Nenhuma despesa paga este mês.</td></tr>';
        const totalPending = pendingMovements.reduce((sum, item) => sum + item.amount, 0);
        document.getElementById('pending-total').textContent = formatCurrency(totalPending);
        pendingContainer.innerHTML = pendingMovements.flatMap(t => t.items.map(item => renderDetailedRow(t, item))).join('');
        if (pendingMovements.length === 0) pendingContainer.innerHTML = '<tr><td colspan="5" class="px-4 py-3 text-center text-sm text-gray-500">Nenhuma conta pendente este mês.</td></tr>';
    };

    const renderGroupedRow = (t) => {
        let rowClasses = personColors[t.person] || '';
        let rowOnclick = '';
        if (combineMode.active && t.type === 'Expense') {
            if (t.id === combineMode.sourceId) { rowClasses += ' bg-yellow-200 ring-2 ring-yellow-500'; } 
            else {
                rowClasses += ' cursor-pointer hover:bg-blue-100 transition-colors';
                rowOnclick = `onclick="combineTransactions(${combineMode.sourceId}, ${t.id})"`;
            }
        }
        const ddBadge = t.is_direct_debit == 1 ? `<span title="Débito Direto" class="inline-flex items-center ml-2 px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700">DD</span>` : '';
        let descHTML = `<div class="flex items-center"><p class="font-semibold cursor-pointer hover:underline" onclick="viewMovement(${t.id})">${t.description}</p>${ddBadge}</div>`;
        const payers = {};
        if (t.items) {
            t.items.forEach(item => {
                const payer = item.person || t.person;
                if (!payers[payer]) payers[payer] = 0;
                payers[payer] += parseFloat(item.amount) || 0;
            });
        }
        const uniquePayers = Object.keys(payers);
        let avatarHTML = '';
        if (uniquePayers.length === 1) {
            const singlePayer = uniquePayers[0];
            const avatarColor = personAvatarColors[singlePayer] || 'bg-gray-400 text-white';
            avatarHTML = `<div title="${singlePayer}" class="mx-auto h-6 w-6 rounded-full flex items-center justify-center text-xs font-bold ${avatarColor}">${singlePayer.charAt(0)}</div>`;
        } else {
            avatarHTML = `<div title="Múltiplos pagadores" class="mx-auto h-6 w-6 rounded-full flex items-center justify-center text-xs font-bold bg-gray-500 text-white">M</div>`;
            descHTML += uniquePayers.map(p => {
                const avatarColor = personAvatarColors[p] || 'bg-gray-400 text-white';
                return `<div class="text-xs text-gray-600 flex items-center gap-1 mt-1"><div class="h-3 w-3 rounded-full flex items-center justify-center text-white ${avatarColor}" style="font-size: 8px;">${p.charAt(0)}</div> ${p}: ${formatCurrency(payers[p])}</div>`;
            }).join('');
        }
        if (t.attachments && t.attachments.length > 0) descHTML += '<div class="mt-1">' + t.attachments.map(att => ` <a href="${att.file_path}" target="_blank" class="text-indigo-500 text-xs">[PDF]</a>`).join('') + '</div>';
        return `<tr id="transaction-row-${t.id}" class="${rowClasses}" ${rowOnclick}>
            <td class="px-4 py-3">${avatarHTML}</td>
            <td class="px-4 py-3 text-sm text-gray-500 align-top">${new Date(t.transaction_date + 'T00:00:00').toLocaleDateString('pt-PT')}</td>
            <td class="px-4 py-3 text-sm font-medium text-gray-800 align-top">${descHTML}</td>
            <td class="px-4 py-3 text-sm font-semibold text-right text-red-600 align-top">- ${formatCurrency(t.amount)}</td>
            <td class="px-4 py-3 text-right text-sm font-medium align-top">${renderActionButtons(t)}</td>
        </tr>`;
    };

    const renderDetailedRow = (t, item) => {
        const payer = item.person || t.person;
        const avatarColor = personAvatarColors[payer] || 'bg-gray-400 text-white';
        let categoryPath = [item.cost_center, item.subcategory, item.sub_subcategory].filter(Boolean).join(' &gt; ');
        const ddBadge = t.is_direct_debit == 1 ? `<span title="Débito Direto" class="inline-flex items-center ml-2 px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700">DD</span>` : '';
        return `<tr>
            <td class="px-4 py-3 align-top"><div title="${payer}" class="mx-auto h-6 w-6 rounded-full flex items-center justify-center text-xs font-bold ${avatarColor}">${payer.charAt(0)}</div></td>
            <td class="px-4 py-3 text-sm text-gray-500 align-top">${new Date(t.transaction_date + 'T00:00:00').toLocaleDateString('pt-PT')}</td>
            <td class="px-4 py-3 text-sm font-medium text-gray-800 align-top">
                <div class="flex items-center"><p>${item.description || t.description}</p>${ddBadge}</div>
                <div class="text-xs text-gray-500">${categoryPath || '<i>Sem categoria</i>'}</div>
            </td>
            <td class="px-4 py-3 text-sm font-semibold text-right align-top text-red-600">${formatCurrency(parseFloat(item.amount))}</td>
            <td class="px-4 py-3 text-right text-sm font-medium align-top">${renderActionButtons(t)}</td>
        </tr>`;
    };

    const renderActionButtons = (t) => {
        const isPending = t.status === 'pending';
        const payButton = isPending ? `<button title="Pagar" class="p-1 text-green-600 hover:text-green-900 hover:bg-green-100 rounded-full" onclick="event.stopPropagation(); markAsPaid(${t.id})"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg></button>` : '';
        const combineButton = t.type === 'Expense' ? `<button title="Combinar Movimento" class="p-1 text-yellow-600 hover:text-yellow-900 hover:bg-yellow-100 rounded-full" onclick="event.stopPropagation(); startCombineMode(${t.id})"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M5 4a1 1 0 00-2 0v7.268a2 2 0 000 3.464V16a1 1 0 102 0v-1.268a2 2 0 000-3.464V4zM11 4a1 1 0 10-2 0v1.268a2 2 0 000 3.464V16a1 1 0 102 0V8.732a2 2 0 000-3.464V4zM16 3a1 1 0 011 1v7.268a2 2 0 010 3.464V16a1 1 0 11-2 0v-1.268a2 2 0 010-3.464V4a1 1 0 011-1z" /></svg></button>` : '';
        return `<div class="flex items-center justify-end space-x-0 whitespace-nowrap">
            ${payButton} ${combineButton}
            <button title="Duplicar" class="p-1 text-blue-600 hover:text-blue-900 hover:bg-blue-100 rounded-full" onclick="event.stopPropagation(); duplicateMovement(${t.id})"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg></button>
            <button title="Editar" class="p-1 text-indigo-600 hover:text-indigo-900 hover:bg-indigo-100 rounded-full" onclick="event.stopPropagation(); editMovement(${t.id})"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M17.414 2.586a2 2 0 00-2.828 0L7 10.172V13h2.828l7.586-7.586a2 2 0 000-2.828z" /><path fill-rule="evenodd" d="M2 6a2 2 0 012-2h4a1 1 0 010 2H4v10h10v-4a1 1 0 112 0v4a2 2 0 01-2 2H4a2 2 0 01-2-2V6z" clip-rule="evenodd" /></svg></button>
            <button title="Apagar" class="p-1 text-red-600 hover:text-red-900 hover:bg-red-100 rounded-full" onclick="event.stopPropagation(); deleteMovement(${t.id})"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" /></svg></button>
        </div>`;
    };

    const renderAnnualSummaryChart = () => {
        const ctx = document.getElementById('annual-summary-chart').getContext('2d');
        const monthlyTotals = Array(12).fill(0);
        const yearMovements = allMovements.filter(m => 
            new Date(m.transaction_date).getFullYear() == selectedYear && 
            m.type === 'Expense' && 
            m.status === 'paid'
        );
        yearMovements.forEach(m => {
            const month = new Date(m.transaction_date).getMonth();
            if (m.items && m.items.length > 0) {
                m.items.forEach(item => {
                    monthlyTotals[month] += parseFloat(item.amount) || 0;
                });
            }
        });
        if (annualSummaryChart) {
            annualSummaryChart.destroy();
        }
        annualSummaryChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: MONTH_NAMES,
                datasets: [{
                    label: `Despesas em ${selectedYear}`,
                    data: monthlyTotals,
                    backgroundColor: '#4f46e5',
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: (context) => `Total: ${formatCurrency(context.parsed.y)}` } }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { callback: (value) => formatCurrency(value) }
                    }
                }
            }
        });
    };

    const updateChartModalUI = () => {
        const { mode, value } = currentChartContext.view;
        chartModePeriodBtn.classList.toggle('active', mode === 'period');
        chartModeYearBtn.classList.toggle('active', mode === 'year');
        chartModeComparisonBtn.classList.toggle('active', mode === 'comparison');
        chartPeriodSelector.classList.toggle('hidden', mode !== 'period');
        chartYearSelector.classList.toggle('hidden', mode === 'period' || mode === 'comparison');
        if (mode === 'period') {
            document.querySelectorAll('.period-btn').forEach(btn => {
                const isSelected = parseInt(btn.dataset.months) === value;
                btn.classList.toggle('bg-indigo-600', isSelected);
                btn.classList.toggle('text-white', isSelected);
                btn.classList.toggle('bg-gray-200', !isSelected);
                btn.classList.toggle('text-gray-700', !isSelected);
            });
        } else if (mode === 'year') {
            document.querySelectorAll('.year-btn').forEach(btn => {
                const isSelected = parseInt(btn.dataset.year) === value;
                btn.classList.toggle('bg-indigo-600', isSelected);
                btn.classList.toggle('text-white', isSelected);
                btn.classList.toggle('bg-gray-200', !isSelected);
                btn.classList.toggle('text-gray-700', !isSelected);
            });
        }
    };

    const showChart = () => {
        const { type, costCenter, subcategory, subSubcategory, description, person, view } = currentChartContext;
        updateChartModalUI();
        const isIncome = type === 'income';
        
        document.getElementById('chart-modal-title').textContent = isIncome 
            ? `Histórico para: ${description} (${person})`
            : `Histórico para: ${subSubcategory || subcategory}`;

        let datasets = [];
        let labels = [];
        const chartOptions = {
            responsive: true, maintainAspectRatio: false,
            scales: { y: { beginAtZero: true, ticks: { callback: (value) => formatCurrency(value) } } },
            plugins: { legend: { display: true }, tooltip: { callbacks: { label: (context) => `${context.dataset.label}: ${formatCurrency(context.parsed.y)}` } } }
        };

        if(isIncome || subSubcategory !== null) { // Simple Bar Chart
            chartOptions.plugins.legend.display = false;
            chartOptions.scales.x = { stacked: false };
            chartOptions.scales.y = { stacked: false };

            const data = [];
            if (view.mode === 'period') {
                const monthsToShow = view.value;
                const now = new Date();
                for (let i = monthsToShow - 1; i >= 0; i--) {
                    const date = new Date(now.getFullYear(), now.getMonth() - i, 1);
                    const month = date.getMonth();
                    const year = date.getFullYear();
                    labels.push(`${MONTH_NAMES[month]}/${year.toString().slice(-2)}`);
                    let monthlyTotal = 0;
                    allMovements.forEach(m => {
                        if(new Date(m.transaction_date).getFullYear() === year && new Date(m.transaction_date).getMonth() === month && m.status === 'paid') {
                            if (isIncome) {
                                if(m.type === 'Income' && m.description === description && m.person === person) monthlyTotal += m.amount;
                            } else {
                                m.items.forEach(item => {
                                    if(item.cost_center === costCenter && item.subcategory === subcategory && item.sub_subcategory === subSubcategory) {
                                        monthlyTotal += parseFloat(item.amount);
                                    }
                                });
                            }
                        }
                    });
                    data.push(monthlyTotal);
                }
            } else if (view.mode === 'year') {
                const yearToShow = view.value;
                labels.push(...MONTH_NAMES);
                for (let month = 0; month < 12; month++) {
                    let monthlyTotal = 0;
                    allMovements.forEach(m => {
                        if(new Date(m.transaction_date).getFullYear() === yearToShow && new Date(m.transaction_date).getMonth() === month && m.status === 'paid') {
                            if (isIncome) {
                                if(m.type === 'Income' && m.description === description && m.person === person) monthlyTotal += m.amount;
                            } else {
                                m.items.forEach(item => {
                                    if(item.cost_center === costCenter && item.subcategory === subcategory && item.sub_subcategory === subSubcategory) {
                                        monthlyTotal += parseFloat(item.amount);
                                    }
                                });
                            }
                        }
                    });
                    data.push(monthlyTotal);
                }
            } else { // 'comparison'
                const yearlyTotals = {};
                allMovements.forEach(m => {
                    if(m.status === 'paid') {
                        const year = new Date(m.transaction_date).getFullYear();
                        if (!yearlyTotals[year]) yearlyTotals[year] = 0;
                        if (isIncome) {
                            if(m.type === 'Income' && m.description === description && m.person === person) yearlyTotals[year] += m.amount;
                        } else {
                            m.items.forEach(item => {
                                if(item.cost_center === costCenter && item.subcategory === subcategory && item.sub_subcategory === subSubcategory) {
                                    yearlyTotals[year] += parseFloat(item.amount);
                                }
                            });
                        }
                    }
                });
                const sortedYearsWithData = Object.keys(yearlyTotals).filter(year => yearlyTotals[year] > 0).sort();
                sortedYearsWithData.forEach(year => {
                    labels.push(year);
                    data.push(yearlyTotals[year]);
                });
            }
            datasets.push({
                label: isIncome ? `Rendimento de ${description}` : `Gasto em ${subSubcategory || subcategory}`,
                data: data,
                backgroundColor: isIncome ? '#10b981' : '#4f46e5',
            });

        } else { // Stacked Bar Chart for Level 2 Subcategory
            chartOptions.scales.x = { stacked: true };
            chartOptions.scales.y = { stacked: true };
            
            const dataBySubSubcat = {};
            if (view.mode === 'period') {
                const monthsToShow = view.value;
                const now = new Date();
                for (let i = monthsToShow - 1; i >= 0; i--) {
                    const date = new Date(now.getFullYear(), now.getMonth() - i, 1);
                    const month = date.getMonth();
                    const year = date.getFullYear();
                    const label = `${MONTH_NAMES[month]}/${year.toString().slice(-2)}`;
                    labels.push(label);

                    allMovements.forEach(m => {
                        if(new Date(m.transaction_date).getFullYear() === year && new Date(m.transaction_date).getMonth() === month && m.status === 'paid') {
                            m.items.forEach(item => {
                                if(item.cost_center === costCenter && item.subcategory === subcategory) {
                                    const key = item.sub_subcategory || '(Outros)';
                                    if (!dataBySubSubcat[key]) dataBySubSubcat[key] = {};
                                    if (!dataBySubSubcat[key][label]) dataBySubSubcat[key][label] = 0;
                                    dataBySubSubcat[key][label] += parseFloat(item.amount);
                                }
                            });
                        }
                    });
                }
            } else if (view.mode === 'year') {
                const yearToShow = view.value;
                labels.push(...MONTH_NAMES);
                labels.forEach(label => {
                    const monthIndex = MONTH_NAMES.indexOf(label);
                    allMovements.forEach(m => {
                        if(new Date(m.transaction_date).getFullYear() === yearToShow && new Date(m.transaction_date).getMonth() === monthIndex && m.status === 'paid') {
                            m.items.forEach(item => {
                                if(item.cost_center === costCenter && item.subcategory === subcategory) {
                                    const key = item.sub_subcategory || '(Outros)';
                                    if (!dataBySubSubcat[key]) dataBySubSubcat[key] = {};
                                    if (!dataBySubSubcat[key][label]) dataBySubSubcat[key][label] = 0;
                                    dataBySubSubcat[key][label] += parseFloat(item.amount);
                                }
                            });
                        }
                    });
                });
            } else { // 'comparison'
                const allYearsInData = [...new Set(allMovements.map(m => new Date(m.transaction_date).getFullYear()))].sort();
                labels.push(...allYearsInData);
                labels.forEach(label => {
                    const year = parseInt(label);
                    allMovements.forEach(m => {
                        if(new Date(m.transaction_date).getFullYear() === year && m.status === 'paid') {
                            m.items.forEach(item => {
                                if(item.cost_center === costCenter && item.subcategory === subcategory) {
                                    const key = item.sub_subcategory || '(Outros)';
                                    if (!dataBySubSubcat[key]) dataBySubSubcat[key] = {};
                                    if (!dataBySubSubcat[key][label]) dataBySubSubcat[key][label] = 0;
                                    dataBySubSubcat[key][label] += parseFloat(item.amount);
                                }
                            });
                        }
                    });
                });
            }

            const colors = ['#4f46e5', '#34d399', '#f59e0b', '#ef4444', '#6366f1', '#22c55e', '#fbbf24', '#f87171', '#818cf8', '#4ade80', '#fcd34d', '#fb7185'];
            let colorIndex = 0;
            for (const subSubcatName in dataBySubSubcat) {
                datasets.push({
                    label: subSubcatName,
                    data: labels.map(l => dataBySubSubcat[subSubcatName][l] || 0),
                    backgroundColor: colors[colorIndex % colors.length]
                });
                colorIndex++;
            }
        }
        
        const chartCanvas = document.getElementById('subcategory-chart-canvas');
        if (subcategoryChart) subcategoryChart.destroy();
        
        subcategoryChart = new Chart(chartCanvas, {
            type: 'bar',
            data: { labels, datasets },
            options: chartOptions
        });
        chartModal.classList.add('is-open');
    };
    
    window.showSubcategoryChart = (costCenter, subcategory, subSubcategory, view) => {
        currentChartContext = { type: 'expense', costCenter, subcategory, subSubcategory, description: null, person: null, view: view || { mode: 'period', value: 12 } };
        showChart();
    };

    window.showIncomeChart = (id, view) => {
        const movement = allMovements.find(m => m.id == id);
        if (!movement) return;

        const { description, person } = movement;
        currentChartContext = { type: 'income', costCenter: null, subcategory: null, subSubcategory: null, description, person, view: view || { mode: 'period', value: 12 } };
        showChart();
    };

    const fetchData = async () => {
        try {
            const response = await fetch(API_ENDPOINT + '?action=get');
            const result = await response.json();
            if (result.success && result.data) {
                // CORREÇÃO APLICADA AQUI: Garantir que todos os valores são números
                allMovements = result.data.transactions.map(t => {
                    const items = t.items ? t.items.map(item => ({...item, amount: parseFloat(item.amount)})) : [];
                    return {...t, amount: parseFloat(t.amount), items: items};
                });

                allLoans = result.data.loans;
                costCenters = result.data.cost_centers;
                flatSubcategories = {};
                Object.values(costCenters).forEach(cc => {
                    const flatten = (sc) => {
                        flatSubcategories[sc.id] = sc;
                        if(sc.children) Object.values(sc.children).forEach(flatten);
                    };
                    Object.values(cc.subcategories).forEach(flatten);
                });
                const years = [...new Set(allMovements.map(m => new Date(m.transaction_date).getFullYear()).filter(y => !isNaN(y)))].sort((a,b) => b-a);
                availableYears = years.length > 0 ? years : [new Date().getFullYear()];
                if (!availableYears.includes(selectedYear)) {
                    selectedYear = availableYears[0] || new Date().getFullYear();
                }
                yearSelect.innerHTML = availableYears.map(y => `<option value="${y}" ${y == selectedYear ? 'selected' : ''}>${y}</option>`).join('');
                chartYearSelector.innerHTML = availableYears.map(y => `<button data-year="${y}" class="year-btn bg-gray-200 text-gray-700 text-sm font-semibold py-1 px-3 rounded-full">${y}</button>`).join('');
                document.querySelectorAll('.year-btn').forEach(btn => {
                    btn.addEventListener('click', () => {
                        const year = parseInt(btn.dataset.year);
                        currentChartContext.view = { mode: 'year', value: year };
                        showChart();
                    });
                });
                document.getElementById('summary-link').href = `summary.php?year=${selectedYear}`;
                document.getElementById('menu-categories-link').href = `categories.php?year=${selectedYear}`;
                document.getElementById('menu-filter-link').href = `filter.php?year=${selectedYear}`;
                populateDropdowns();
                renderUI();
                checkOverduePayments();
            } else { 
                console.error("API response indica falha ou dados malformados:", result);
                throw new Error(result.error || 'A resposta do servidor não é válida ou os dados estão em falta.'); 
            }
        } catch (error) { 
            console.error("Ocorreu um erro ao carregar os dados:", error);
            alert("Ocorreu um erro ao carregar os dados. Verifique a consola do navegador (F12) para mais detalhes.");
            yearSelect.innerHTML = `<option value="${new Date().getFullYear()}">${new Date().getFullYear()}</option>`;
        }
    };
    
    const handleApiResponse = async (response, modalToClose = null) => {
        const resultText = await response.text();
        try {
            const result = JSON.parse(resultText);
            if (result.success) {
                if (modalToClose) modalToClose.classList.remove('is-open');
                if (result.data && result.data.affected_rows) {
                    alert(`${result.data.affected_rows} movimentos foram atualizados com sucesso!`);
                }
                fetchData();
            } else {
                alert('Erro: ' + (result.error || 'Ocorreu um problema no servidor.'));
            }
        } catch (e) {
             alert('Ocorreu um erro inesperado. Verifique a consola (F12) para a resposta do servidor.');
             console.error("Falha ao analisar JSON:", e);
             console.error("Resposta do Servidor:", resultText);
        }
    };

    window.deleteMovement = async (id) => {
        if (confirm('Tem a certeza que quer apagar este movimento?')) {
            try {
                const response = await fetch(API_ENDPOINT, { method: 'POST', body: JSON.stringify({ action: 'delete', id }), headers: { 'Content-Type': 'application/json' } });
                await handleApiResponse(response);
            } catch (error) { alert(error.message); }
        }
    };

    window.deleteLoan = async (id) => {
        if (confirm('Tem a certeza? Isto irá apagar todas as prestações futuras por pagar deste empréstimo. O histórico de pagamentos será mantido.')) {
            try {
                const response = await fetch(API_ENDPOINT, { method: 'POST', body: JSON.stringify({ action: 'delete_loan', id }), headers: { 'Content-Type': 'application/json' } });
                await handleApiResponse(response);
            } catch (error) { alert(error.message); }
        }
    };

    window.editLoan = (id) => {
        const loan = allLoans.find(l => l.id == id);
        if(!loan) return;

        loanForm.reset();
        document.getElementById('loan-modal-title').textContent = 'Editar Empréstimo';
        document.getElementById('loan-submit-btn').textContent = 'Atualizar Empréstimo';

        document.getElementById('loan-id').value = loan.id;
        document.getElementById('loan-name').value = loan.name;
        document.getElementById('loan-cost-center').value = loan.cost_center;
        
        updateLoanSubcategories(loan.cost_center, loan.subcategory, loan.sub_subcategory);

        document.getElementById('loan-monthly-payment').value = loan.monthly_payment;
        document.getElementById('loan-start-date').value = loan.start_date;
        document.getElementById('loan-end-date').value = loan.end_date;
        document.getElementById('loan-person').value = loan.person;

        calculateLoanTotal();
        loanModal.classList.add('is-open');
    };

    window.markAsPaid = async (id) => {
         if (confirm('Marcar como paga?')) {
            try {
                const response = await fetch(API_ENDPOINT, { method: 'POST', body: JSON.stringify({ action: 'update_status', id, status: 'paid' }), headers: { 'Content-Type': 'application/json' } });
                await handleApiResponse(response);
            } catch (error) { alert(error.message); }
        }
    };
    
    window.editMovement = (id) => {
        const movement = allMovements.find(m => m.id == id);
        if (!movement) return;
        
        transactionForm.reset();
        modalTitle.textContent = 'Editar Movimento';
        transactionIdInput.value = movement.id;
        document.getElementById('transaction-date').value = movement.transaction_date;
        document.getElementById('transaction-type').value = movement.type;
        document.getElementById('transaction-description').value = movement.description;
        document.getElementById('transaction-person').value = movement.person;
        document.getElementById('transaction-status').value = movement.status;
        document.getElementById('is-direct-debit').checked = movement.is_direct_debit == 1;
        document.getElementById('recurring-wrapper').classList.add('hidden');
        endDateWrapper.classList.add('hidden');
        
        transactionItemsContainer.innerHTML = '';
        currentFilesWrapper.innerHTML = '';

        if (movement.type === 'Expense') {
            if (movement.items && movement.items.length > 0) {
                movement.items.forEach(item => addItem(item));
            }
        } else {
            document.getElementById('transaction-amount').value = movement.amount;
        }

        if (movement.attachments && movement.attachments.length > 0) {
            currentFilesWrapper.innerHTML = '<p class="text-xs font-semibold text-gray-600 mb-1">Ficheiros Anexados:</p>';
            movement.attachments.forEach(att => {
                const fileElement = document.createElement('div');
                fileElement.id = `attachment-${att.id}`;
                fileElement.className = 'flex items-center justify-between text-sm';
                fileElement.innerHTML = `
                    <a href="${att.file_path}" target="_blank" class="text-indigo-600 hover:underline">${att.file_path.split('/').pop()}</a>
                    <button type="button" class="ml-2 text-red-500 hover:text-red-700 font-semibold" onclick="removeAttachment(${att.id})">&times;</button>
                `;
                currentFilesWrapper.appendChild(fileElement);
            });
        }

        document.getElementById('transaction-type').dispatchEvent(new Event('change'));
        transactionModal.classList.add('is-open');
    };

    window.removeAttachment = (attachmentId) => {
        if (confirm('Tem a certeza que quer remover este anexo? A alteração só será guardada ao submeter o formulário.')) {
            const attachmentElement = document.getElementById(`attachment-${attachmentId}`);
            if (attachmentElement) {
                attachmentElement.style.textDecoration = 'line-through';
                attachmentElement.style.opacity = '0.5';
            }
            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'remove_attachments[]';
            hiddenInput.value = attachmentId;
            transactionForm.appendChild(hiddenInput);
        }
    };

    window.duplicateMovement = (id) => {
        const movement = allMovements.find(m => m.id == id);
        if (!movement) return;

        transactionForm.reset();
        modalTitle.textContent = 'Duplicar Movimento';
        transactionIdInput.value = '';
        document.getElementById('transaction-date').value = new Date().toISOString().split('T')[0];
        document.getElementById('transaction-type').value = movement.type;
        document.getElementById('transaction-description').value = movement.description;
        document.getElementById('transaction-person').value = movement.person;
        document.getElementById('transaction-status').value = movement.status;
        document.getElementById('is-direct-debit').checked = movement.is_direct_debit == 1;
        document.getElementById('recurring-wrapper').classList.remove('hidden');
        
        transactionItemsContainer.innerHTML = '';
        currentFilesWrapper.innerHTML = '';

        if (movement.type === 'Expense') {
            if (movement.items && movement.items.length > 0) {
                movement.items.forEach(item => addItem(item));
            }
        } else {
            document.getElementById('transaction-amount').value = movement.amount;
        }

        document.getElementById('transaction-type').dispatchEvent(new Event('change'));
        transactionModal.classList.add('is-open');
    };
    
    yearSelect.addEventListener('change', (e) => {
        selectedYear = parseInt(e.target.value);
        document.getElementById('summary-link').href = `summary.php?year=${selectedYear}`;
        document.getElementById('menu-categories-link').href = `categories.php?year=${selectedYear}`;
        document.getElementById('menu-filter-link').href = `filter.php?year=${selectedYear}`;
        renderUI();
    });

    document.getElementById('add-transaction-btn').addEventListener('click', () => {
        transactionForm.reset();
        modalTitle.textContent = 'Novo Movimento';
        transactionIdInput.value = '';
        transactionItemsContainer.innerHTML = '';
        currentFilesWrapper.innerHTML = '';
        document.getElementById('transaction-date').valueAsDate = new Date();
        document.getElementById('transaction-person').value = currentUser;
        document.getElementById('transaction-type').dispatchEvent(new Event('change'));
        document.getElementById('recurring-wrapper').classList.remove('hidden');
        isRecurringCheckbox.checked = false;
        endDateWrapper.classList.add('hidden');
        transactionEndDate.value = '';
        addItem();
        transactionModal.classList.add('is-open');
    });

    document.getElementById('add-loan-btn').addEventListener('click', () => {
        loanForm.reset();
        document.getElementById('loan-modal-title').textContent = 'Novo Empréstimo';
        document.getElementById('loan-submit-btn').textContent = 'Guardar e Gerar Prestações';
        document.getElementById('loan-id').value = '';
        document.getElementById('loan-start-date').valueAsDate = new Date();
        document.getElementById('loan-person').value = currentUser; // Pré-selecionar utilizador
        updateLoanSubcategories(loanCostCenterSelect.value);
        calculateLoanTotal();
        loanModal.classList.add('is-open');
    });

    closeChartModalBtn.addEventListener('click', () => {
        chartModal.classList.remove('is-open');
    });

    document.querySelectorAll('.cancel-btn').forEach(btn => btn.addEventListener('click', (e) => {
        e.target.closest('.modal').classList.remove('is-open');
    }));
    
    document.getElementById('transaction-type').addEventListener('change', (e) => {
        const isExpense = e.target.value === 'Expense';
        document.getElementById('status-container').style.display = 'block';
        document.getElementById('expense-items-wrapper').style.display = isExpense ? 'block' : 'none';
        document.getElementById('income-amount-wrapper').style.display = isExpense ? 'none' : 'block';
    });

    loanCostCenterSelect.addEventListener('change', (e) => updateLoanSubcategories(e.target.value));
    loanSubcategorySelect.addEventListener('change', (e) => updateLoanSubSubcategories(e.target.value));

    loanMonthlyPaymentInput.addEventListener('input', calculateLoanTotal);
    loanStartDateInput.addEventListener('change', calculateLoanTotal);
    loanEndDateInput.addEventListener('change', calculateLoanTotal);
    
    transactionForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const formData = new FormData(transactionForm);
         if (!isRecurringCheckbox.checked) {
            formData.delete('end_date');
        }
        
        const items = [];
        document.querySelectorAll('.transaction-item').forEach(itemEl => {
            const subCatId = itemEl.querySelector('.item-subcategory').value;
            items.push({
                description: itemEl.querySelector('.item-description').value,
                amount: itemEl.querySelector('.item-amount').value,
                person: itemEl.querySelector('.item-person').value,
                cost_center: itemEl.querySelector('.item-cost-center').value,
                subcategory: subCatId && flatSubcategories[subCatId] ? flatSubcategories[subCatId].name : '',
                sub_subcategory: itemEl.querySelector('.item-sub-subcategory').value
            });
        });
        formData.append('items_json', JSON.stringify(items));
        formData.append('action', formData.get('id') ? 'update' : 'add');
        
        try {
            const response = await fetch(API_ENDPOINT, { method: 'POST', body: formData });
            await handleApiResponse(response, transactionModal);
        } catch (error) { alert(error.message); }
    });
    
    loanForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const formData = new FormData(loanForm);
        const subCatId = formData.get('subcategory');
        if (subCatId && flatSubcategories[subCatId]) {
            formData.set('subcategory', flatSubcategories[subCatId].name);
        }
        formData.append('action', formData.get('id') ? 'update_loan' : 'add_loan');
        
        try {
            const response = await fetch(API_ENDPOINT, { method: 'POST', body: new URLSearchParams(formData) });
            await handleApiResponse(response, loanModal);
        } catch (error) { alert(error.message); }
    });
    
    const updateChartView = (mode, value) => {
        const { type, costCenter, subcategory, subSubcategory, description, person } = currentChartContext;
         if (type === 'income') {
            const incomeMovement = allMovements.find(m => m.description === description && m.person === person);
            if(incomeMovement) showIncomeChart(incomeMovement.id, { mode, value });
        } else {
            showSubcategoryChart(costCenter, subcategory, subSubcategory, { mode, value });
        }
    };

    chartModePeriodBtn.addEventListener('click', () => updateChartView('period', 12));
    chartModeYearBtn.addEventListener('click', () => updateChartView('year', selectedYear));
    chartModeComparisonBtn.addEventListener('click', () => updateChartView('comparison', null));

    document.querySelectorAll('.period-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const months = parseInt(btn.dataset.months);
            updateChartView('period', months);
        });
    });

    menuBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        mainMenu.classList.toggle('hidden');
    });

    document.addEventListener('click', (e) => {
        if (!mainMenu.contains(e.target) && !menuBtn.contains(e.target)) {
            mainMenu.classList.add('hidden');
        }
    });
    
    document.getElementById('add-item-btn').addEventListener('click', () => addItem());
    
    const calculateTotalAmount = () => {
        let total = 0;
        document.querySelectorAll('.item-amount').forEach(input => {
            total += parseFloat(input.value) || 0;
        });
        totalAmountDisplay.textContent = formatCurrency(total);
    };

const addItem = (item = {}) => {
        const template = document.getElementById('transaction-item-template');
        const newItem = template.content.cloneNode(true);
        const itemElement = newItem.querySelector('.transaction-item');
        const mainPayer = document.getElementById('transaction-person').value;

        // Seleciona todos os elementos da nova parcela
        const itemPersonSelect = itemElement.querySelector('.item-person');
        const itemCostCenter = itemElement.querySelector('.item-cost-center');
        const itemSubcategory = itemElement.querySelector('.item-subcategory');
        const itemSubSubcategory = itemElement.querySelector('.item-sub-subcategory');
        const subSubWrapper = itemSubSubcategory.closest('.grid > div');

        // Esconde o campo Sub-Subcategoria por defeito
        subSubWrapper.classList.add('hidden');

        // Popula o dropdown "Pago Por"
        itemPersonSelect.innerHTML = `<option value="">-- Padrão (${mainPayer}) --</option>`;
        itemPersonSelect.innerHTML += costSharers.map(p => `<option value="${p}">${p}</option>`).join('');
        if (item.person) {
            itemPersonSelect.value = item.person;
        }

        // Popula Centro de Custo
        itemCostCenter.innerHTML = '<option value="">-- Selecione --</option>' + Object.values(costCenters).map(c => `<option value="${c.name}">${c.name}</option>`).join('');

        // Funções auxiliares para atualizar os dropdowns seguintes
        const updateItemSubSubcategories = (selectedSubCatId) => {
            const parent = flatSubcategories[selectedSubCatId];
            itemSubSubcategory.innerHTML = '<option value="">-- Opcional --</option>';
            subSubWrapper.classList.add('hidden'); // Garante que está escondido antes de verificar
            if (parent && parent.children) {
                itemSubSubcategory.innerHTML += Object.values(parent.children).map(ssc => `<option value="${ssc.name}">${ssc.name}</option>`).join('');
                subSubWrapper.classList.remove('hidden'); // Mostra se houver opções
            }
        };
        
        const updateItemSubcategories = (selectedCostCenterName) => {
            const costCenter = Object.values(costCenters).find(cc => cc.name === selectedCostCenterName);
            itemSubcategory.innerHTML = '<option value="">-- Selecione --</option>';
            if (costCenter && costCenter.subcategories) {
                itemSubcategory.innerHTML += Object.values(costCenter.subcategories).map(sc => `<option value="${sc.id}">${sc.name}</option>`).join('');
            }
            updateItemSubSubcategories(''); // Reseta o campo seguinte
        };

        // Adiciona os event listeners para mudanças
        itemCostCenter.addEventListener('change', () => updateItemSubcategories(itemCostCenter.value));
        itemSubcategory.addEventListener('change', () => updateItemSubSubcategories(itemSubcategory.value));
        
        // Se estiver a editar (o objeto 'item' tem dados), preenche e seleciona os valores
        if (item.cost_center) {
            itemCostCenter.value = item.cost_center;
            updateItemSubcategories(item.cost_center);
            if (item.subcategory) {
               const subCatId = Object.keys(flatSubcategories).find(key => flatSubcategories[key].name === item.subcategory);
               if(subCatId) {
                   itemSubcategory.value = subCatId;
                   // Esta chamada agora irá popular e mostrar o campo Sub-Subcategoria corretamente
                   updateItemSubSubcategories(subCatId); 
                   if(item.sub_subcategory) {
                       itemSubSubcategory.value = item.sub_subcategory;
                   }
               }
            }
        }

        itemElement.querySelector('.item-amount').value = item.amount || '';
        itemElement.querySelector('.item-description').value = item.description || '';
        
        itemElement.querySelector('.remove-item-btn').addEventListener('click', () => {
            itemElement.remove();
            calculateTotalAmount();
        });
        itemElement.querySelector('.item-amount').addEventListener('input', calculateTotalAmount);

        transactionItemsContainer.appendChild(newItem);
        calculateTotalAmount();
    };

    // --- Novas Funções (Visualizar e Combinar) ---
window.viewMovement = (id) => {
        const movement = allMovements.find(m => m.id == id);
        if (!movement) return;

        document.getElementById('view-modal-title').textContent = movement.description;
        const subtitle = `Pago por ${movement.person} em ${new Date(movement.transaction_date + 'T00:00:00').toLocaleDateString('pt-PT')}`;
        document.getElementById('view-modal-subtitle').textContent = subtitle;
        document.getElementById('view-modal-total').textContent = formatCurrency(movement.amount);
        
        // --- INÍCIO DA CORREÇÃO ---

        // 1. Verificar se existem múltiplos pagadores nas parcelas
        const payers = new Set();
        if (movement.items && movement.items.length > 0) {
            movement.items.forEach(item => {
                const payer = item.person || movement.person; // Usa o pagador da parcela ou o principal
                payers.add(payer);
            });
        }
        const showPayerColumn = payers.size > 1;

        // 2. Ajustar o cabeçalho da tabela dinamicamente
        const tableHeaderRow = document.querySelector('#view-transaction-modal thead tr');
        if (showPayerColumn) {
            tableHeaderRow.innerHTML = `
                <th class="py-2 font-medium w-1/3">Descrição</th>
                <th class="py-2 font-medium w-1/4">Pago Por</th>
                <th class="py-2 font-medium w-1/3">Categoria</th>
                <th class="py-2 font-medium text-right">Valor</th>
            `;
        } else {
            tableHeaderRow.innerHTML = `
                <th class="py-2 font-medium w-2/5">Descrição</th>
                <th class="py-2 font-medium w-2/5">Categoria</th>
                <th class="py-2 font-medium text-right w-1/5">Valor</th>
            `;
        }

        // 3. Construir as linhas da tabela, incluindo a coluna do pagador se necessário
        const itemsBody = document.getElementById('view-modal-items-body');
        itemsBody.innerHTML = '';
        if (movement.items && movement.items.length > 0) {
            movement.items.forEach(item => {
                let categoryPath = [item.cost_center, item.subcategory, item.sub_subcategory].filter(Boolean).join(' &gt; ') || '<i>Sem categoria</i>';
                
                let rowHTML = '<tr>';
                rowHTML += `<td class="py-2">${item.description || '<i>Sem descrição</i>'}</td>`;
                
                if (showPayerColumn) {
                    const payer = item.person || movement.person;
                    const avatarColor = personAvatarColors[payer] || 'bg-gray-400 text-white';
                    rowHTML += `
                        <td class="py-2">
                            <div class="flex items-center gap-2">
                                <div title="${payer}" class="h-5 w-5 rounded-full flex-shrink-0 flex items-center justify-center text-xs font-bold ${avatarColor}">${payer.charAt(0)}</div>
                                <span class="text-sm">${payer}</span>
                            </div>
                        </td>
                    `;
                }

                rowHTML += `<td class="py-2 text-gray-600">${categoryPath}</td>`;
                rowHTML += `<td class="py-2 text-right font-semibold">${formatCurrency(item.amount)}</td>`;
                rowHTML += '</tr>';

                itemsBody.innerHTML += rowHTML;
            });
        } else {
             // Ajusta o colspan da mensagem para o número correto de colunas
             itemsBody.innerHTML = `<tr><td colspan="${showPayerColumn ? 4 : 3}" class="py-4 text-center text-gray-500">Este movimento não tem parcelas detalhadas.</td></tr>`;
        }
        
        // --- FIM DA CORREÇÃO ---

        const attachmentsWrapper = document.getElementById('view-modal-attachments-wrapper');
        const attachmentsList = document.getElementById('view-modal-attachments-list');
        if (movement.attachments && movement.attachments.length > 0) {
            attachmentsList.innerHTML = movement.attachments.map(att => `
                <a href="${att.file_path}" target="_blank" class="block bg-gray-100 p-2 rounded-md hover:bg-indigo-100 text-indigo-700">
                    ${att.file_path.split('/').pop()}
                </a>
            `).join('');
            attachmentsWrapper.classList.remove('hidden');
        } else {
             attachmentsWrapper.classList.add('hidden');
        }

        viewTransactionModal.classList.add('is-open');
    };

    window.startCombineMode = (id) => {
        event.stopPropagation();
        const movement = allMovements.find(m => m.id == id);
        if (!movement) return;

        combineMode.active = true;
        combineMode.sourceId = id;
        combineMode.sourceDescription = movement.description;

        document.getElementById('combine-mode-text').textContent = `Selecione o movimento de destino para onde quer mover "${movement.description}".`;
        combineModeBanner.classList.remove('hidden');
        renderMonthlyHistory();
    };
    
    window.cancelCombineMode = () => {
        combineMode.active = false;
        combineMode.sourceId = null;
        combineMode.sourceDescription = '';
        combineModeBanner.classList.add('hidden');
        renderMonthlyHistory();
    };
    
    window.combineTransactions = async (sourceId, destinationId) => {
        event.stopPropagation();
        const sourceMovement = allMovements.find(m => m.id == sourceId);
        const destMovement = allMovements.find(m => m.id == destinationId);

        if (!sourceMovement || !destMovement) return;

        if (confirm(`Tem a certeza que quer mover "${sourceMovement.description}" para dentro de "${destMovement.description}"?\n\nEsta ação é irreversível.`)) {
            try {
                const response = await fetch(API_ENDPOINT, { 
                    method: 'POST', 
                    body: JSON.stringify({ action: 'combine_transactions', source_id: sourceId, destination_id: destinationId }), 
                    headers: { 'Content-Type': 'application/json' } 
                });
                await handleApiResponse(response);
            } catch (error) { 
                alert(error.message); 
            } finally {
                cancelCombineMode();
            }
        }
    };

    viewGroupedBtn.addEventListener('click', () => {
        currentViewMode = 'grouped';
        viewGroupedBtn.classList.add('active');
        viewDetailedBtn.classList.remove('active');
        renderMonthlyHistory();
    });

    viewDetailedBtn.addEventListener('click', () => {
        currentViewMode = 'detailed';
        viewDetailedBtn.classList.add('active');
        viewGroupedBtn.classList.remove('active');
        renderMonthlyHistory();
    });

    isRecurringCheckbox.addEventListener('change', () => {
        endDateWrapper.classList.toggle('hidden', !isRecurringCheckbox.checked);
        if (!isRecurringCheckbox.checked) {
            transactionEndDate.value = '';
        }
    });

    const setCookie = (name, value, days) => {
        let expires = "";
        if (days) {
            const date = new Date();
            date.setTime(date.getTime() + (days*24*60*60*1000));
            expires = "; expires=" + date.toUTCString();
        }
        document.cookie = name + "=" + (value || "")  + expires + "; path=/";
    }
    const getCookie = (name) => {
        const nameEQ = name + "=";
        const ca = document.cookie.split(';');
        for(let i=0; i < ca.length; i++) {
            let c = ca[i];
            while (c.charAt(0)==' ') c = c.substring(1,c.length);
            if (c.indexOf(nameEQ) == 0) return c.substring(nameEQ.length,c.length);
        }
        return null;
    }

    // --- Overdue Payments Reminder ---
    const checkOverduePayments = () => {
        const today = new Date().toISOString().split('T')[0];
        if (getCookie('paymentReminderShownToday') === today) {
            return; // Already shown today
        }

        const overdueMovements = allMovements.filter(m => 
            m.status === 'pending' && 
            m.transaction_date <= today
        );

        if (overdueMovements.length > 0) {
            pendingReminderList.innerHTML = overdueMovements.map(t => `
                <div id="reminder-item-${t.id}" class="flex justify-between items-center p-3 border rounded-lg bg-gray-50">
                    <div>
                        <p class="font-semibold">${t.description}</p>
                        <p class="text-sm text-gray-600">${new Date(t.transaction_date + 'T00:00:00').toLocaleDateString('pt-PT')} - ${formatCurrency(t.amount)}</p>
                    </div>
                    <button class="bg-green-100 text-green-800 text-xs font-semibold py-1 px-3 rounded-full hover:bg-green-200" onclick="markSingleAsPaidFromModal(${t.id})">Marcar como Pago</button>
                </div>
            `).join('');
            pendingReminderModal.classList.add('is-open');
            setCookie('paymentReminderShownToday', today, 1);
        }
    };
    
    window.markSingleAsPaidFromModal = async (id) => {
        try {
            const response = await fetch(API_ENDPOINT, { method: 'POST', body: JSON.stringify({ action: 'update_status', id, status: 'paid' }), headers: { 'Content-Type': 'application/json' } });
            const resultText = await response.text();
            const result = JSON.parse(resultText);
            if (result.success) {
                document.getElementById(`reminder-item-${id}`).remove();
                if (pendingReminderList.children.length === 0) {
                    pendingReminderModal.classList.remove('is-open');
                }
                fetchData(); // Refresh main UI
            } else {
                alert('Erro: ' + (result.error || 'Ocorreu um problema no servidor.'));
            }
        } catch (error) { alert(error.message); }
    };

    markAllPendingPaidBtn.addEventListener('click', async () => {
        const idsToUpdate = Array.from(pendingReminderList.children).map(div => parseInt(div.id.replace('reminder-item-', '')));
        if (idsToUpdate.length === 0) {
            pendingReminderModal.classList.remove('is-open');
            return;
        }

        try {
            const response = await fetch(API_ENDPOINT, { 
                method: 'POST', 
                body: JSON.stringify({ action: 'update_multiple_status', ids: idsToUpdate, status: 'paid' }), 
                headers: { 'Content-Type': 'application/json' } 
            });
            await handleApiResponse(response, pendingReminderModal);
        } catch (error) {
            alert(error.message);
        }
    });

    // --- Inicialização da Aplicação ---
    currentUser = localStorage.getItem('currentUser');
    if (currentUser && costSharers.includes(currentUser)) {
        initializeAppView();
    } else {
        promptUserSelection();
    }
});