<?php
require_once 'config.php'; // Inclui as definições centrais

// --- CONFIGURAÇÃO BÁSICA E GESTÃO DE ERROS ---
ini_set('display_errors', 1);
error_reporting(E_ALL);

// --- LIGAÇÃO À BASE DE DADOS (usa variáveis do config.php) ---
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) { die("Falha na ligação: " . $conn->connect_error); }
$conn->set_charset("utf8mb4");

// --- LÓGICA DE DATAS E ANOS ---
$available_years = [];
$year_result = $conn->query("SELECT DISTINCT YEAR(transaction_date) as year FROM transactions ORDER BY year DESC");
if ($year_result && $year_result->num_rows > 0) {
    while($row = $year_result->fetch_assoc()) { $available_years[] = $row['year']; }
}
if (empty($available_years)) { $available_years[] = date('Y'); }
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : ($available_years[0] ?? date('Y'));


// --- CONSULTA E PROCESSAMENTO DE DADOS ---
$all_transactions = [];
$data_by_person = [];
$person_totals = [];
$pie_chart_totals_person = [];
$grand_total = 0;

$cost_center_totals = [];
$subcategory_totals = [];
$sub_subcategory_totals = [];
$data_by_cost_center = [];

// --- INÍCIO DA CORREÇÃO ---
// A query foi alterada para usar COALESCE.
// COALESCE(ti.person, t.person) seleciona o pagador da parcela (ti.person).
// Se este for nulo, seleciona o pagador da transação principal (t.person) como fallback.
$stmt = $conn->prepare("
    SELECT 
        COALESCE(ti.person, t.person) AS payer,
        t.transaction_date,
        ti.amount,
        ti.cost_center,
        ti.subcategory,
        ti.sub_subcategory
    FROM 
        transactions t
    JOIN 
        transaction_items ti ON t.id = ti.transaction_id
    WHERE 
        t.type = 'Expense' AND YEAR(t.transaction_date) = ? AND t.status = 'paid'
");
// --- FIM DA CORREÇÃO ---

$stmt->bind_param("i", $selected_year);
$stmt->execute();
$result = $stmt->get_result();

if ($result) {
    while($row = $result->fetch_assoc()) {
        $all_transactions[] = $row;
        $month_idx = (int)date('m', strtotime($row['transaction_date'])) - 1;

        // --- INÍCIO DA CORREÇÃO ---
        // A variável $person agora usa o campo "payer" que vem da query corrigida.
        $person = $row['payer'];
        // --- FIM DA CORREÇÃO ---

        $cost_center = $row['cost_center'] ?: 'Não atribuído';
        $subcategory = $row['subcategory'] ?: 'Não atribuído';
        $sub_subcategory = $row['sub_subcategory'];
        $item_amount = (float)$row['amount'];

        $grand_total += $item_amount;

        if (!isset($pie_chart_totals_person[$person])) $pie_chart_totals_person[$person] = 0;
        $pie_chart_totals_person[$person] += $item_amount;

        if (!isset($data_by_person[$person])) {
            $data_by_person[$person] = array_fill(0, 12, 0);
            $person_totals[$person] = 0;
        }
        $data_by_person[$person][$month_idx] += $item_amount;
        $person_totals[$person] += $item_amount;

        // Data for stacked cost center chart
        if (!isset($data_by_cost_center[$cost_center])) $data_by_cost_center[$cost_center] = array_fill(0, 12, 0);
        $data_by_cost_center[$cost_center][$month_idx] += $item_amount;


        // Hierarchical totals for the list
        if (!isset($cost_center_totals[$cost_center])) $cost_center_totals[$cost_center] = 0;
        $cost_center_totals[$cost_center] += $item_amount;

        if ($subcategory !== 'Não atribuído') {
            if (!isset($subcategory_totals[$cost_center][$subcategory])) $subcategory_totals[$cost_center][$subcategory] = 0;
            $subcategory_totals[$cost_center][$subcategory] += $item_amount;
        }
        
        if ($sub_subcategory) {
            if (!isset($sub_subcategory_totals[$cost_center][$subcategory][$sub_subcategory])) $sub_subcategory_totals[$cost_center][$subcategory][$sub_subcategory] = 0;
            $sub_subcategory_totals[$cost_center][$subcategory][$sub_subcategory] += $item_amount;
        }
    }
}
$stmt->close();
$conn->close();

arsort($person_totals);
arsort($cost_center_totals);

foreach ($subcategory_totals as &$subs) {
    arsort($subs);
}
unset($subs);

foreach ($sub_subcategory_totals as &$sub_subs) {
    foreach($sub_subs as &$items) {
      arsort($items);
    }
    unset($items);
}
unset($sub_subs);


arsort($pie_chart_totals_person);

// Totals for the new subcategory pie chart
$first_level_subcategory_totals = [];
foreach ($subcategory_totals as $subcategories) {
    foreach ($subcategories as $subcategory_name => $total) {
        if (!isset($first_level_subcategory_totals[$subcategory_name])) {
            $first_level_subcategory_totals[$subcategory_name] = 0;
        }
        $first_level_subcategory_totals[$subcategory_name] += $total;
    }
}
arsort($first_level_subcategory_totals);


// --- PREPARAR DADOS PARA CHART.JS ---
function generate_color($index, $total) {
    $hue = ($index * (360 / (max($total, 1)))) % 360;
    return "hsl($hue, 70%, 60%)";
}

$person_datasets = [];
$person_colors_config = ['#4f46e5', '#34d399', '#f59e0b', '#ef4444'];
$color_idx = 0;
foreach($cost_sharers as $person) {
    if (isset($data_by_person[$person])) {
        $person_datasets[] = [ 'label' => $person, 'data' => $data_by_person[$person], 'backgroundColor' => $person_colors_config[$color_idx % count($person_colors_config)] ];
        $color_idx++;
    }
}

$pie_chart_colors_person = [];
$color_idx = 0;
foreach(array_keys($pie_chart_totals_person) as $label) {
    $pie_chart_colors_person[] = generate_color($color_idx, count($pie_chart_totals_person));
    $color_idx++;
}

// Data for Cost Center Chart
$cc_datasets = [];
$color_idx = 0;
$chart_colors = ['#4f46e5', '#34d399', '#f59e0b', '#ef4444', '#6366f1', '#22c55e', '#fbbf24', '#f87171', '#818cf8', '#4ade80', '#fcd34d', '#fb7185', '#38bdf8', '#a78bfa'];
foreach ($data_by_cost_center as $cc_name => $monthly_data) {
    $cc_datasets[] = [
        'label' => $cc_name,
        'data' => $monthly_data,
        'backgroundColor' => $chart_colors[$color_idx % count($chart_colors)],
    ];
    $color_idx++;
}
$cc_datasets_json = json_encode($cc_datasets);

// Data for new Subcategory Pie Chart
$subcategory_pie_chart_labels = json_encode(array_keys($first_level_subcategory_totals));
$subcategory_pie_chart_data = json_encode(array_values($first_level_subcategory_totals));
$subcategory_pie_chart_colors = [];
$color_idx = 0;
foreach(array_keys($first_level_subcategory_totals) as $label) {
    $subcategory_pie_chart_colors[] = generate_color($color_idx, count($first_level_subcategory_totals));
    $color_idx++;
}
$subcategory_pie_chart_colors_json = json_encode($subcategory_pie_chart_colors);


$months_labels = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resumo Anual - Gestão de Despesas</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f3f4f6; }
        .card { background-color: white; border-radius: 0.75rem; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1); padding: 1.5rem; }
        .modal { display: none; }
        .modal.is-open { display: flex; }
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
                        <select name="year" id="year-select" onchange="this.form.submit()" class="rounded-md border-gray-300 shadow-sm">
                            <?php foreach($available_years as $year): ?>
                                <option value="<?php echo $year; ?>" <?php echo ($year == $selected_year) ? 'selected' : ''; ?>><?php echo $year; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <a href="index.php?year=<?php echo $selected_year; ?>" class="text-sm font-medium text-indigo-600 hover:text-indigo-800 whitespace-nowrap">&larr; Voltar</a>
                </div>
            </div>
        </header>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <div class="space-y-8">
                <div class="card"><h2 class="text-xl font-bold text-gray-800 mb-4">Despesas Mensais por Pessoa</h2><div class="h-96"><canvas id="expensesByPersonChart"></canvas></div></div>
                <div class="card"><h2 class="text-xl font-bold text-gray-800 mb-4">Despesas Anuais por Centro de Custo</h2><div class="h-96"><canvas id="costCenterChart"></canvas></div></div>
            </div>
            <div class="space-y-8">
                <div class="card"><h2 class="text-xl font-bold text-gray-800 mb-4">Gastos Totais por Pagador</h2><div class="h-72 w-full"><canvas id="totalExpensesPieChart"></canvas></div></div>
                <div class="card"><h2 class="text-xl font-bold text-gray-800 mb-4">Gastos Totais por Subcategoria</h2><div class="h-72 w-full"><canvas id="subcategoryPieChart"></canvas></div></div>
                <div class="card">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Totais Anuais por Pessoa</h2>
                    <table class="min-w-full"><tbody class="divide-y divide-gray-200">
                        <?php if(empty($person_totals)): ?><tr><td class="py-2 text-sm text-gray-500">Sem dados.</td></tr>
                        <?php else: foreach($person_totals as $person => $total): ?>
                            <tr class="flex justify-between items-center"><td class="py-2 text-sm font-medium text-gray-700"><?php echo htmlspecialchars($person); ?></td><td class="py-2 text-sm font-semibold text-gray-900"><?php echo number_format($total, 2, ',', ' ') . ' €'; ?></td></tr>
                        <?php endforeach; endif; ?>
                    </tbody></table>
                </div>
                 <div class="card">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Totais por Categoria</h2>
                     <div class="divide-y divide-gray-200">
                        <?php if(empty($cost_center_totals)): ?>
                            <p class="py-2 text-sm text-gray-500">Sem dados.</p>
                        <?php else: foreach($cost_center_totals as $cc => $total): ?>
                            <div class="py-2">
                                <div class="flex justify-between items-center">
                                    <div class="flex items-center gap-2">
                                        <button class="p-1 text-gray-400 hover:text-indigo-600" onclick='showChart({level: "cost_center", cost_center: "<?php echo htmlspecialchars($cc, ENT_QUOTES); ?>"})'>
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M2 11a1 1 0 011-1h2a1 1 0 011 1v5a1 1 0 01-1 1H3a1 1 0 01-1-1v-5zM8 7a1 1 0 011-1h2a1 1 0 011 1v9a1 1 0 01-1 1H9a1 1 0 01-1-1V7zM14 4a1 1 0 011-1h2a1 1 0 011 1v12a1 1 0 01-1 1h-2a1 1 0 01-1-1V4z" /></svg>
                                        </button>
                                        <span class="font-bold text-gray-800"><?php echo htmlspecialchars($cc); ?></span>
                                    </div>
                                    <span class="font-bold text-gray-900"><?php echo number_format($total, 2, ',', ' ') . ' €'; ?></span>
                                </div>
                                <?php if(isset($subcategory_totals[$cc])): ?>
                                <ul class="ml-4 mt-2 space-y-1">
                                    <?php foreach($subcategory_totals[$cc] as $sc => $sc_total): ?>
                                    <li>
                                        <div class="flex justify-between items-center text-sm">
                                            <div class="flex items-center gap-2">
                                                <button class="p-1 text-gray-400 hover:text-indigo-600" onclick='showChart({level: "subcategory", cost_center: "<?php echo htmlspecialchars($cc, ENT_QUOTES); ?>", subcategory: "<?php echo htmlspecialchars($sc, ENT_QUOTES); ?>"})'>
                                                     <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M2 11a1 1 0 011-1h2a1 1 0 011 1v5a1 1 0 01-1 1H3a1 1 0 01-1-1v-5zM8 7a1 1 0 011-1h2a1 1 0 011 1v9a1 1 0 01-1 1H9a1 1 0 01-1-1V7zM14 4a1 1 0 011-1h2a1 1 0 011 1v12a1 1 0 01-1 1h-2a1 1 0 01-1-1V4z" /></svg>
                                                </button>
                                                <span class="text-gray-700"><?php echo htmlspecialchars($sc); ?></span>
                                            </div>
                                            <span class="font-semibold text-gray-800"><?php echo number_format($sc_total, 2, ',', ' ') . ' €'; ?></span>
                                        </div>
                                        <?php if(isset($sub_subcategory_totals[$cc][$sc])): ?>
                                        <ul class="ml-8 mt-1 space-y-1">
                                            <?php foreach($sub_subcategory_totals[$cc][$sc] as $ssc => $ssc_total): ?>
                                            <li>
                                                 <div class="flex justify-between items-center text-xs">
                                                    <div class="flex items-center gap-2">
                                                        <button class="p-1 text-gray-400 hover:text-indigo-600" onclick='showChart({level: "sub_subcategory", cost_center: "<?php echo htmlspecialchars($cc, ENT_QUOTES); ?>", subcategory: "<?php echo htmlspecialchars($sc, ENT_QUOTES); ?>", sub_subcategory: "<?php echo htmlspecialchars($ssc, ENT_QUOTES); ?>"})'>
                                                             <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor"><path d="M2 11a1 1 0 011-1h2a1 1 0 011 1v5a1 1 0 01-1 1H3a1 1 0 01-1-1v-5zM8 7a1 1 0 011-1h2a1 1 0 011 1v9a1 1 0 01-1 1H9a1 1 0 01-1-1V7zM14 4a1 1 0 011-1h2a1 1 0 011 1v12a1 1 0 01-1 1h-2a1 1 0 01-1-1V4z" /></svg>
                                                        </button>
                                                        <span class="text-gray-600"><?php echo htmlspecialchars($ssc); ?></span>
                                                    </div>
                                                    <span class="font-medium text-gray-700"><?php echo number_format($ssc_total, 2, ',', ' ') . ' €'; ?></span>
                                                </div>
                                            </li>
                                            <?php endforeach; ?>
                                        </ul>
                                        <?php endif; ?>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; endif; ?>
                     </div>
                     <?php if(!empty($cost_center_totals)): ?>
                     <div class="border-t-2 border-gray-300 mt-2 pt-2 flex justify-between items-center">
                         <span class="text-sm font-bold text-gray-800">Total Geral</span>
                         <span class="text-sm font-bold text-gray-900"><?php echo number_format($grand_total, 2, ',', ' ') . ' €'; ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div id="chart-modal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 items-center justify-center p-4 z-40">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-3xl">
            <div class="p-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 id="chart-modal-title" class="text-xl font-bold text-gray-800">Histórico</h2>
                    <button id="close-chart-modal-btn" class="text-gray-400 hover:text-gray-800 text-3xl leading-none font-bold">&times;</button>
                </div>
                <div class="h-96">
                    <canvas id="category-chart-canvas"></canvas>
                </div>
            </div>
        </div>
    </div>
    <script>
        window.pageData = {
            all_transactions: <?php echo json_encode($all_transactions); ?>,
            selected_year: <?php echo $selected_year; ?>,
            months_labels: <?php echo json_encode($months_labels); ?>,
            person_datasets: <?php echo json_encode($person_datasets); ?>,
            cc_datasets: <?php echo $cc_datasets_json; ?>,
            pie_chart_data_person: <?php echo json_encode(array_values($pie_chart_totals_person)); ?>,
            pie_chart_labels_person: <?php echo json_encode(array_keys($pie_chart_totals_person)); ?>,
            pie_chart_colors_person: <?php echo json_encode($pie_chart_colors_person); ?>,
            subcategory_pie_chart_data: <?php echo $subcategory_pie_chart_data; ?>,
            subcategory_pie_chart_labels: <?php echo $subcategory_pie_chart_labels; ?>,
            subcategory_pie_chart_colors: <?php echo $subcategory_pie_chart_colors_json; ?>
        };
    </script>
    <script src="assets/js/summary.js" defer></script>
</body>
</html>