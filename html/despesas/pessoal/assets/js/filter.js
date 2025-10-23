// Ficheiro JS para filter.php
document.addEventListener('DOMContentLoaded', function () {
    const { person_avatar_colors, current_year } = window.pageConfig;
    const API_ENDPOINT = 'api.php';
    
    let costCenters = {};
    let flatSubcategories = {};
    let currentViewMode = 'grouped'; // 'grouped' or 'detailed'

    // --- Elementos do DOM ---
    const filterForm = document.getElementById('filter-form');
    const costCenterSelect = document.getElementById('filter-cost-center');
    const subcategorySelect = document.getElementById('filter-subcategory');
    const subSubcategorySelect = document.getElementById('filter-sub-subcategory');
    const yearSelect = document.getElementById('filter-year');
    const monthSelect = document.getElementById('filter-month');
    const resultsContainer = document.getElementById('results-container');
    const resultsTableHeader = document.getElementById('results-table-header');
    const resultsTable = document.getElementById('results-table');
    const summaryBoxes = document.getElementById('summary-boxes');
    const viewGroupedBtn = document.getElementById('view-grouped-btn');
    const viewDetailedBtn = document.getElementById('view-detailed-btn');
    
    // --- Funções de UI ---
    const updateSubSubcategories = (selectedSubCatId) => {
        const parent = flatSubcategories[selectedSubCatId];
        subSubcategorySelect.innerHTML = '<option value="">Todas</option>';
        if (parent && parent.children) {
            subSubcategorySelect.disabled = false;
            Object.values(parent.children).forEach(ssc => {
                subSubcategorySelect.innerHTML += `<option value="${ssc.name}">${ssc.name}</option>`;
            });
        } else {
            subSubcategorySelect.disabled = true;
        }
    };

    const updateSubcategories = (selectedCostCenterName) => {
        const costCenter = Object.values(costCenters).find(cc => cc.name === selectedCostCenterName);
        subcategorySelect.innerHTML = '<option value="">Todas</option>';
        if (costCenter && costCenter.subcategories) {
            subcategorySelect.disabled = false;
            Object.values(costCenter.subcategories).forEach(sc => {
                subcategorySelect.innerHTML += `<option value="${sc.id}">${sc.name}</option>`;
            });
        } else {
            subcategorySelect.disabled = true;
        }
        updateSubSubcategories(subcategorySelect.value);
    };

    const populateInitialFilters = (allYears) => {
        // Popula anos
        yearSelect.innerHTML = allYears.map(y => `<option value="${y}" ${y == current_year ? 'selected' : ''}>${y}</option>`).join('');
        
        // Popula meses
        const monthNames = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
        monthSelect.innerHTML = '<option value="">Todos</option>' + monthNames.map((name, index) => `<option value="${index + 1}">${name}</option>`).join('');

        // Popula Centros de Custo
        costCenterSelect.innerHTML = '<option value="">Todos</option>' + Object.values(costCenters).map(c => `<option value="${c.name}">${c.name}</option>`).join('');
    };

    const formatCurrency = (value) => {
        const val = parseFloat(value) || 0;
        const parts = new Intl.NumberFormat('pt-PT', { style: 'currency', currency: 'EUR' }).formatToParts(val);
        return parts.map(p => p.type === 'group' ? ' ' : p.value).join('');
    };

    // --- Lógica de Filtragem e Renderização ---
    const fetchData = async () => {
        try {
            const response = await fetch(`${API_ENDPOINT}?action=get`);
            const result = await response.json();
            if (result.success) {
                costCenters = result.data.cost_centers;
                flatSubcategories = {};
                Object.values(costCenters).forEach(cc => {
                    const flatten = (sc) => {
                        flatSubcategories[sc.id] = sc;
                        if(sc.children) Object.values(sc.children).forEach(flatten);
                    };
                    Object.values(cc.subcategories).forEach(flatten);
                });
                
                const years = [...new Set(result.data.transactions.map(m => new Date(m.transaction_date).getFullYear()).filter(y => !isNaN(y)))].sort((a,b) => b-a);
                populateInitialFilters(years.length > 0 ? years : [new Date().getFullYear()]);

            } else { throw new Error(result.error); }
        } catch (error) {
            alert('Erro ao carregar dados iniciais: ' + error.message);
        }
    };

    filterForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const formData = new FormData(filterForm);
        const subCatId = formData.get('subcategory');
        if (subCatId && flatSubcategories[subCatId]) {
            formData.set('subcategory', flatSubcategories[subCatId].name);
        } else {
            formData.set('subcategory', ''); // Garante que envia vazio se não houver seleção
        }
        
        formData.append('view_mode', currentViewMode);
        const params = new URLSearchParams(formData);
        
        try {
            const response = await fetch(`${API_ENDPOINT}?action=get_filtered&${params.toString()}`);
            const result = await response.json();
            if (result.success) {
                if (currentViewMode === 'grouped') {
                    renderGroupedResults(result.data);
                } else {
                    renderDetailedResults(result.data);
                }
            } else {
                throw new Error(result.error);
            }
        } catch (error) {
            alert("Erro ao aplicar filtros: " + error.message);
        }
    });

    const renderGroupedResults = (data) => {
        resultsTableHeader.innerHTML = `
            <th class="w-[10%] px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Pessoa</th>
            <th class="w-[15%] px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Data</th>
            <th class="w-[50%] px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Descrição</th>
            <th class="w-[25%] px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Valor</th>
        `;
        
        let totalIncome = 0;
        let totalExpenses = 0;

        if (data.length === 0) {
            resultsTable.innerHTML = '<tr><td colspan="4" class="px-4 py-10 text-center text-gray-500">Nenhum movimento encontrado.</td></tr>';
        } else {
             resultsTable.innerHTML = data.map(t => {
                const isIncome = t.type === 'Income';
                if (isIncome) totalIncome += parseFloat(t.amount);
                else totalExpenses += parseFloat(t.amount);

                const avatarColor = person_avatar_colors[t.person] || 'bg-gray-400 text-white';

                let descHTML = `<p class="font-semibold">${t.description}</p>`;
                 if (t.items.length === 1) {
                    const item = t.items[0];
                    let categoryPath = [item.cost_center, item.subcategory, item.sub_subcategory].filter(Boolean).join(' &gt; ');
                    if (categoryPath) {
                        descHTML += `<div class="text-xs text-gray-500 font-semibold">${categoryPath}</div>`;
                    }
                } else if (t.items.length > 1) {
                     descHTML += t.items.map(item => {
                        let categoryPath = [item.cost_center, item.subcategory, item.sub_subcategory].filter(Boolean).join(' &gt; ');
                        if (!categoryPath) categoryPath = '<i>Sem categoria</i>';
                        return `<div class="text-xs text-gray-500"><span class="font-semibold">${categoryPath}</span> (${formatCurrency(item.amount)})</div>`;
                     }).join('');
                }

                return `
                    <tr>
                        <td class="px-4 py-3 align-top"><div title="${t.person}" class="mx-auto h-6 w-6 rounded-full flex items-center justify-center text-xs font-bold ${avatarColor}">${t.person.charAt(0)}</div></td>
                        <td class="px-4 py-3 text-sm text-gray-500 align-top">${new Date(t.transaction_date + 'T00:00:00').toLocaleDateString('pt-PT')}</td>
                        <td class="px-4 py-3 text-sm font-medium text-gray-800 align-top">${descHTML}</td>
                        <td class="px-4 py-3 text-sm font-semibold text-right align-top ${isIncome ? 'text-green-600' : 'text-red-600'}">${isIncome ? '+' : '-'} ${formatCurrency(t.amount)}</td>
                    </tr>
                `;
            }).join('');
        }
        updateSummaryBoxes(totalIncome, totalExpenses);
    };

    const renderDetailedResults = (data) => {
        resultsTableHeader.innerHTML = `
            <th class="w-[10%] px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Pessoa</th>
            <th class="w-[15%] px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Data</th>
            <th class="w-[40%] px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Descrição</th>
            <th class="w-[20%] px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Valor Parcela</th>
            <th class="w-[15%] px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total Mov.</th>
        `;

        let totalIncome = 0;
        let totalExpenses = 0;
        const processedIncomes = {}; // Para somar rendimentos apenas uma vez

        // Calcular os totais CORRETAMENTE
        data.forEach(t => {
            if (t.type === 'Income') {
                if (!processedIncomes[t.id]) {
                    totalIncome += parseFloat(t.amount);
                    processedIncomes[t.id] = true;
                }
            } else {
                totalExpenses += parseFloat(t.item_amount) || 0;
            }
        });

        if (data.length === 0) {
            resultsTable.innerHTML = '<tr><td colspan="5" class="px-4 py-10 text-center text-gray-500">Nenhum movimento encontrado.</td></tr>';
        } else {
            resultsTable.innerHTML = data.map(t => {
                let categoryPath = [t.cost_center, t.subcategory, t.sub_subcategory].filter(Boolean).join(' &gt; ');
                const avatarColor = person_avatar_colors[t.person] || 'bg-gray-400 text-white';

                return `
                    <tr>
                         <td class="px-4 py-3 align-top"><div title="${t.person}" class="mx-auto h-6 w-6 rounded-full flex items-center justify-center text-xs font-bold ${avatarColor}">${t.person.charAt(0)}</div></td>
                        <td class="px-4 py-3 text-sm text-gray-500 align-top">${new Date(t.transaction_date + 'T00:00:00').toLocaleDateString('pt-PT')}</td>
                        <td class="px-4 py-3 text-sm font-medium text-gray-800 align-top">
                            ${t.item_description || t.description}
                            ${categoryPath ? `<br><span class="text-xs text-gray-500 font-semibold">${categoryPath}</span>` : ''}
                        </td>
                        <td class="px-4 py-3 text-sm font-semibold text-right align-top ${t.type === 'Income' ? 'text-green-600' : 'text-red-600'}">${formatCurrency(t.item_amount || t.amount)}</td>
                        <td class="px-4 py-3 text-sm text-gray-500 text-right align-top">${formatCurrency(t.amount)}</td>
                    </tr>
                `;
            }).join('');
        }
        updateSummaryBoxes(totalIncome, totalExpenses);
    };

    const updateSummaryBoxes = (totalIncome, totalExpenses) => {
        summaryBoxes.innerHTML = `
            <div class="bg-green-50 p-4 rounded-lg border border-green-200"><h3 class="text-sm font-medium text-green-800">Total Entradas</h3><p class="text-2xl font-bold text-green-600 mt-1">${formatCurrency(totalIncome)}</p></div>
            <div class="bg-red-50 p-4 rounded-lg border border-red-200"><h3 class="text-sm font-medium text-red-800">Total Saídas</h3><p class="text-2xl font-bold text-red-600 mt-1">${formatCurrency(totalExpenses)}</p></div>
            <div class="bg-gray-50 p-4 rounded-lg border border-gray-200"><h3 class="text-sm font-medium text-gray-800">Saldo</h3><p class="text-2xl font-bold ${totalIncome - totalExpenses >= 0 ? 'text-gray-900' : 'text-red-600'} mt-1">${formatCurrency(totalIncome - totalExpenses)}</p></div>
        `;
        resultsContainer.classList.remove('hidden');
    };
    
    // --- Listeners para UI ---
    costCenterSelect.addEventListener('change', (e) => updateSubcategories(e.target.value));
    subcategorySelect.addEventListener('change', (e) => updateSubSubcategories(e.target.value));

    const monthYearFilter = document.getElementById('month-year-filter');
    const dateRangeFilter = document.getElementById('date-range-filter');
    const periodMonthBtn = document.getElementById('period-month-btn');
    const periodRangeBtn = document.getElementById('period-range-btn');
    
    periodMonthBtn.addEventListener('click', () => {
        monthYearFilter.classList.remove('hidden');
        dateRangeFilter.classList.add('hidden');
        periodMonthBtn.classList.add('bg-indigo-600', 'text-white');
        periodMonthBtn.classList.remove('bg-white', 'text-gray-700');
        periodRangeBtn.classList.add('bg-white', 'text-gray-700');
        periodRangeBtn.classList.remove('bg-indigo-600', 'text-white');
    });

    periodRangeBtn.addEventListener('click', () => {
        monthYearFilter.classList.add('hidden');
        dateRangeFilter.classList.remove('hidden');
        periodRangeBtn.classList.add('bg-indigo-600', 'text-white');
        periodRangeBtn.classList.remove('bg-white', 'text-gray-700');
        periodMonthBtn.classList.add('bg-white', 'text-gray-700');
        periodMonthBtn.classList.remove('bg-indigo-600', 'text-white');
    });

    viewGroupedBtn.addEventListener('click', () => {
        currentViewMode = 'grouped';
        viewGroupedBtn.classList.add('active');
        viewDetailedBtn.classList.remove('active');
    });

    viewDetailedBtn.addEventListener('click', () => {
        currentViewMode = 'detailed';
        viewDetailedBtn.classList.add('active');
        viewGroupedBtn.classList.remove('active');
    });

    fetchData();
});