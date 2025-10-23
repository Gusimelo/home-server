<?php
// --- CONFIGURAÇÃO BÁSICA E GESTÃO DE ERROS ---
ini_set('display_errors', 1);
error_reporting(E_ALL);

// --- CONFIGURAÇÃO DA BASE DE DADOS ---
$servername = "localhost"; 
$username = "hass";      
$password = "fcfheetl";  
$dbname = "expenses";        

// --- LIGAÇÃO À BASE DE DADOS ---
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Falha na ligação à base de dados: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

// --- LÓGICA DE DATAS E ANOS ---
$available_years = [];
$year_result = $conn->query("SELECT DISTINCT YEAR(transaction_date) as year FROM transactions ORDER BY year DESC");
if ($year_result && $year_result->num_rows > 0) {
    while($row = $year_result->fetch_assoc()) {
        $available_years[] = $row['year'];
    }
}
if (empty($available_years)) {
    $available_years[] = date('Y');
}
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : ($available_years[0] ?? date('Y'));

// --- CONSULTA E PROCESSAMENTO DE DADOS (LÓGICA ATUALIZADA PARA PARCELAS) ---
$data_by_person = [];
$data_by_category = [];
$person_totals = [];
$category_totals = [];
$garage_totals_by_category = [];
$pie_chart_totals = [];
$grand_total = 0;

// 1. Obter todas as transações de despesa do ano
$stmt = $conn->prepare("
    SELECT id, MONTH(transaction_date) as month, description as category, amount
    FROM transactions 
    WHERE 
        type = 'Expense' AND 
        YEAR(transaction_date) = ? AND
        status = 'paid'
");
$stmt->bind_param("i", $selected_year);
$stmt->execute();
$transactions_result = $stmt->get_result();
$transactions = $transactions_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 2. Processar cada transação e as suas parcelas
foreach ($transactions as $t) {
    $grand_total += $t['amount'];
    $month_idx = $t['month'] - 1;
    $category = $t['category'];

    // Obter as parcelas de pagamento para esta transação
    $parcel_stmt = $conn->prepare("SELECT person, amount FROM payment_parcels WHERE transaction_id = ?");
    $parcel_stmt->bind_param("i", $t['id']);
    $parcel_stmt->execute();
    $parcels = $parcel_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $parcel_stmt->close();

    // Distribuir os custos pelas pessoas/entidades que pagaram
    foreach ($parcels as $p) {
        $person = $p['person'];
        $parcel_amount = (float)$p['amount'];

        if (!isset($pie_chart_totals[$person])) $pie_chart_totals[$person] = 0;
        $pie_chart_totals[$person] += $parcel_amount;

        if ($person === 'Caixa da Garagem') {
            if (!isset($garage_totals_by_category[$category])) $garage_totals_by_category[$category] = 0;
            $garage_totals_by_category[$category] += $parcel_amount;
        } else {
            if (!isset($data_by_person[$person])) {
                $data_by_person[$person] = array_fill(0, 12, 0);
                $person_totals[$person] = 0;
            }
            $data_by_person[$person][$month_idx] += $parcel_amount;
            $person_totals[$person] += $parcel_amount;
        }
    }

    // O total por categoria continua a ser o valor total da transação
    if (!isset($data_by_category[$category])) {
        $data_by_category[$category] = array_fill(0, 12, 0);
        $category_totals[$category] = 0;
    }
    $data_by_category[$category][$month_idx] += (float)$t['amount'];
    $category_totals[$category] += (float)$t['amount'];
}
$conn->close();

// Define a ordem fixa para as cores do gráfico de barras
$person_order_for_chart = ['Mãe', 'Gustavo', 'Mariana', 'Diogo'];
$cost_sharers = ['Gustavo', 'Mariana', 'Diogo'];

$sharers_grand_total = 0;
foreach ($cost_sharers as $sharer) {
    if (isset($person_totals[$sharer])) {
        $sharers_grand_total += $person_totals[$sharer];
    }
}

// Ordena os totais para exibição nas tabelas
arsort($person_totals);
arsort($category_totals);
arsort($garage_totals_by_category);
arsort($pie_chart_totals);


// --- PREPARAR DADOS PARA CHART.JS ---
$person_datasets = [];
$person_colors = ['#a78bfa', '#4f46e5', '#34d399', '#f59e0b', '#ef4444', '#6b7280'];
$color_idx = 0;
foreach($person_order_for_chart as $person) {
    if (isset($data_by_person[$person])) {
        $person_datasets[] = [
            'label' => $person,
            'data' => $data_by_person[$person],
            'backgroundColor' => $person_colors[$color_idx % count($person_colors)]
        ];
        $color_idx++;
    }
}

function generate_color($index, $total) {
    $hue = ($index * (360 / (max($total, 1)))) % 360;
    return "hsl($hue, 70%, 60%)";
}

$category_datasets = [];
$color_idx = 0;
foreach(array_keys($category_totals) as $category) {
    $category_datasets[] = [
        'label' => $category,
        'data' => $data_by_category[$category],
        'backgroundColor' => generate_color($color_idx, count($category_totals))
    ];
    $color_idx++;
}

// Prepara as cores para o gráfico de pizza aqui no PHP
$pie_chart_colors = [];
$color_idx = 0;
foreach(array_keys($pie_chart_totals) as $label) {
    $pie_chart_colors[] = generate_color($color_idx, count($pie_chart_totals));
    $color_idx++;
}


$months_labels = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resumo Anual de Despesas</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f3f4f6; }
        .card { background-color: white; border-radius: 0.75rem; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1); padding: 1.5rem; }
    </style>
</head>
<body class="p-4 sm:p-6 md:p-8">
    <div class="max-w-7xl mx-auto">
        <header class="mb-8">
            <div class="flex flex-col sm:flex-row justify-between sm:items-center gap-4">
                <div>
                    <h1 class="text-3xl sm:text-4xl font-bold text-gray-800">Resumo Anual de Despesas</h1>
                    <p class="text-gray-600 mt-2">Análise de gastos pagos para o ano de <?php echo $selected_year; ?>.</p>
                </div>
                <div class="flex items-center gap-4">
                     <form method="GET" action="summary.php" class="flex items-center gap-2">
                        <label for="year-select" class="text-sm font-medium text-gray-700">Ano:</label>
                        <select name="year" id="year-select" onchange="this.form.submit()" class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <?php foreach($available_years as $year): ?>
                                <option value="<?php echo $year; ?>" <?php echo ($year == $selected_year) ? 'selected' : ''; ?>>
                                    <?php echo $year; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <a href="index.php" class="text-sm font-medium text-indigo-600 hover:text-indigo-800 whitespace-nowrap">&larr; Voltar</a>
                </div>
            </div>
        </header>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <div class="lg:col-span-2 space-y-8">
                <div class="card">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Despesas Mensais por Pessoa</h2>
                    <div class="h-96"> <canvas id="expensesByPersonChart"></canvas> </div>
                </div>
                <div class="card">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Despesas Mensais por Categoria</h2>
                    <div class="h-96"> <canvas id="expensesByCategoryChart"></canvas> </div>
                </div>
            </div>
            <div class="lg:col-span-1 space-y-8">
                <div class="card">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Gastos Totais por Pagador</h2>
                    <div class="h-72 w-full"><canvas id="totalExpensesPieChart"></canvas></div>
                </div>
                <div class="card">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Totais Anuais por Pessoa</h2>
                    <div class="overflow-x-auto">
                        <table class="min-w-full">
                            <tbody class="divide-y divide-gray-200">
                                <?php if(empty($person_totals)): ?>
                                    <tr><td class="py-2 text-sm text-gray-500">Sem despesas pagas para este ano.</td></tr>
                                <?php else: ?>
                                    <?php foreach($person_totals as $person => $total): ?>
                                        <tr class="flex justify-between items-center">
                                            <td class="py-2 text-sm font-medium text-gray-700"><?php echo htmlspecialchars($person); ?></td>
                                            <td class="py-2 text-sm font-semibold text-gray-900"><?php echo number_format($total, 2, ',', ' ') . ' €'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <?php if(!empty($person_totals)): ?>
                            <tfoot class="border-t-2 border-gray-300 mt-2 pt-2">
                                <tr class="flex justify-between items-center">
                                    <td class="py-2 text-sm font-bold text-gray-800">Total Irmãos</td>
                                    <td class="py-2 text-sm font-bold text-gray-900"><?php echo number_format($sharers_grand_total, 2, ',', ' ') . ' €'; ?></td>
                                </tr>
                            </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>
                <div class="card">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Totais Pagos pela Garagem</h2>
                    <div class="overflow-x-auto">
                        <table class="min-w-full">
                            <tbody class="divide-y divide-gray-200">
                                <?php if(empty($garage_totals_by_category)): ?>
                                     <tr><td class="py-2 text-sm text-gray-500">Nenhuma despesa paga pela garagem.</td></tr>
                                <?php else: ?>
                                    <?php foreach($garage_totals_by_category as $category => $total): ?>
                                    <tr class="flex justify-between items-center">
                                        <td class="py-2 text-sm font-medium text-gray-700"><?php echo htmlspecialchars($category); ?></td>
                                        <td class="py-2 text-sm font-semibold text-gray-900"><?php echo number_format($total, 2, ',', ' ') . ' €'; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <?php if(!empty($garage_totals_by_category)): ?>
                            <tfoot class="border-t-2 border-gray-300 mt-2 pt-2">
                                <tr class="flex justify-between items-center">
                                    <td class="py-2 text-sm font-bold text-gray-800">Total Garagem</td>
                                    <td class="py-2 text-sm font-bold text-gray-900"><?php echo number_format(array_sum($garage_totals_by_category), 2, ',', ' ') . ' €'; ?></td>
                                </tr>
                            </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>
                 <div class="card">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Totais Anuais por Categoria</h2>
                     <div class="overflow-x-auto">
                        <table class="min-w-full">
                            <tbody class="divide-y divide-gray-200">
                                <?php if(empty($category_totals)): ?>
                                     <tr><td class="py-2 text-sm text-gray-500">Sem despesas pagas para este ano.</td></tr>
                                <?php else: ?>
                                    <?php foreach($category_totals as $category => $total): ?>
                                    <tr class="flex justify-between items-center">
                                        <td class="py-2 text-sm font-medium text-gray-700"><?php echo htmlspecialchars($category); ?></td>
                                        <td class="py-2 text-sm font-semibold text-gray-900"><?php echo number_format($total, 2, ',', ' ') . ' €'; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <?php if(!empty($category_totals)): ?>
                            <tfoot class="border-t-2 border-gray-300 mt-2 pt-2">
                                <tr class="flex justify-between items-center">
                                    <td class="py-2 text-sm font-bold text-gray-800">Total Geral</td>
                                    <td class="py-2 text-sm font-bold text-gray-900"><?php echo number_format($grand_total, 2, ',', ' ') . ' €'; ?></td>
                                </tr>
                            </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const formatCurrency = (value) => {
            const val = parseFloat(value) || 0;
            const formatted = val.toFixed(2);
            let parts = formatted.split('.');
            parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
            return parts.join(',') + ' €';
        };

        const months = <?php echo json_encode($months_labels); ?>;

        const barChartOptions = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) { label += ': '; }
                            if (context.parsed.y !== null) { label += formatCurrency(context.parsed.y); }
                            return label;
                        }
                    }
                }
            },
            scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, ticks: { callback: (value) => formatCurrency(value) } } }
        };

        const personDatasets = <?php echo json_encode($person_datasets); ?>;
        if (personDatasets.length > 0) {
            const ctxPerson = document.getElementById('expensesByPersonChart').getContext('2d');
            new Chart(ctxPerson, { type: 'bar', data: { labels: months, datasets: personDatasets }, options: barChartOptions });
        }

        const categoryDatasets = <?php echo json_encode($category_datasets); ?>;
        if (categoryDatasets.length > 0) {
            const ctxCategory = document.getElementById('expensesByCategoryChart').getContext('2d');
            new Chart(ctxCategory, { type: 'bar', data: { labels: months, datasets: categoryDatasets }, options: barChartOptions });
        }
        
        const pieChartData = <?php echo json_encode(array_values($pie_chart_totals)); ?>;
        const pieChartLabels = <?php echo json_encode(array_keys($pie_chart_totals)); ?>;
        const pieColors = <?php echo json_encode($pie_chart_colors); ?>;
        if (pieChartData.length > 0) {
            const ctxPie = document.getElementById('totalExpensesPieChart').getContext('2d');
            new Chart(ctxPie, {
                type: 'pie',
                data: {
                    labels: pieChartLabels,
                    datasets: [{
                        label: 'Total Gasto',
                        data: pieChartData,
                        backgroundColor: pieColors,
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: true, position: 'top' },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.label || '';
                                    if (label) { label += ': '; }
                                    if (context.parsed !== null) {
                                        label += formatCurrency(context.parsed);
                                    }
                                    return label;
                                }
                            }
                        }
                    }
                }
            });
        }
    </script>
</body>
</html>