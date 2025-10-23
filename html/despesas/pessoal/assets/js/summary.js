// Ficheiro JS para summary.php
document.addEventListener('DOMContentLoaded', function () {
    const { 
        all_transactions, 
        selected_year, 
        months_labels,
        person_datasets,
        cc_datasets,
        pie_chart_data_person,
        pie_chart_labels_person,
        pie_chart_colors_person,
        subcategory_pie_chart_data,
        subcategory_pie_chart_labels,
        subcategory_pie_chart_colors
    } = window.pageData;

    let categoryChart = null;

    const formatCurrency = (value) => {
        const val = typeof value === 'number' ? value : 0;
        return new Intl.NumberFormat('pt-PT', { style: 'currency', currency: 'EUR' }).format(val).replace(/\./g, ' ');
    };

    const barChartOptions = { 
        responsive: true, 
        maintainAspectRatio: false, 
        plugins: { 
            tooltip: { 
                callbacks: { 
                    label: (c) => `${c.dataset.label || ''}: ${formatCurrency(c.parsed.y)}` 
                } 
            } 
        }, 
        scales: { 
            x: { stacked: true }, 
            y: { stacked: true, beginAtZero: true, ticks: { callback: (v) => formatCurrency(v) } } 
        } 
    };

    if (person_datasets.length > 0) {
        new Chart(document.getElementById('expensesByPersonChart'), { 
            type: 'bar', 
            data: { labels: months_labels, datasets: person_datasets }, 
            options: barChartOptions 
        });
    }
    
    if (cc_datasets.length > 0) {
        new Chart(document.getElementById('costCenterChart'), { 
            type: 'bar', 
            data: { labels: months_labels, datasets: cc_datasets }, 
            options: barChartOptions 
        });
    }

    if (pie_chart_data_person.length > 0) {
        new Chart(document.getElementById('totalExpensesPieChart'), { 
            type: 'pie', 
            data: { 
                labels: pie_chart_labels_person, 
                datasets: [{ 
                    label: 'Total Gasto', 
                    data: pie_chart_data_person, 
                    backgroundColor: pie_chart_colors_person, 
                    hoverOffset: 4 
                }] 
            },
            options: { 
                responsive: true, 
                maintainAspectRatio: false, 
                plugins: { 
                    legend: { display: true, position: 'top' }, 
                    tooltip: { callbacks: { label: (c) => `${c.label || ''}: ${formatCurrency(c.parsed)}` } } 
                } 
            }
        });
    }
    
    if (subcategory_pie_chart_data.length > 0) {
        new Chart(document.getElementById('subcategoryPieChart'), {
            type: 'pie',
            data: {
                labels: subcategory_pie_chart_labels,
                datasets: [{
                    label: 'Total Gasto',
                    data: subcategory_pie_chart_data,
                    backgroundColor: subcategory_pie_chart_colors,
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: true, position: 'top' },
                    tooltip: { callbacks: { label: (c) => `${c.label || ''}: ${formatCurrency(c.parsed)}` } }
                }
            }
        });
    }

    const chartModal = document.getElementById('chart-modal');
    const closeChartModalBtn = document.getElementById('close-chart-modal-btn');
    closeChartModalBtn.addEventListener('click', () => chartModal.classList.remove('is-open'));

    window.showChart = (context) => {
        event.stopPropagation();
        let title = '';
        
        let filteredMovements = all_transactions;
        if (context.cost_center) {
            title = context.cost_center;
            filteredMovements = filteredMovements.filter(m => m.cost_center === context.cost_center);
        }
        if (context.subcategory) {
             title += ` > ${context.subcategory}`;
            filteredMovements = filteredMovements.filter(m => m.subcategory === context.subcategory);
        }
        if (context.sub_subcategory) {
            title += ` > ${context.sub_subcategory}`;
            filteredMovements = filteredMovements.filter(m => m.sub_subcategory === context.sub_subcategory);
        }

        document.getElementById('chart-modal-title').textContent = `Evolução Mensal para: ${title}`;

        const data = Array(12).fill(0);
        filteredMovements.forEach(m => {
            const month = new Date(m.transaction_date).getMonth();
            data[month] += parseFloat(m.amount);
        });
        
        const chartCanvas = document.getElementById('category-chart-canvas');
        if (categoryChart) categoryChart.destroy();

        categoryChart = new Chart(chartCanvas, {
            type: 'bar',
            data: {
                labels: months_labels,
                datasets: [{
                    label: `Gasto em ${selected_year}`,
                    data: data,
                    backgroundColor: '#4f46e5'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: { y: { beginAtZero: true, ticks: { callback: (v) => formatCurrency(v) } } },
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => `Total: ${formatCurrency(c.parsed.y)}` } } }
            }
        });
        
        chartModal.classList.add('is-open');
    };
});