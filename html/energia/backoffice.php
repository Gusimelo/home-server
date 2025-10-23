<?php
require_once 'config.php';

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_error) { die('Erro de ligação.'); }
$mysqli->set_charset('utf8mb4');

// --- Lógica de Datas para o Calendário ---
$ano_selecionado = isset($_GET['ano']) ? (int)$_GET['ano'] : date('Y');
$mes_selecionado = isset($_GET['mes']) ? (int)$_GET['mes'] : date('m');

$data_base = new DateTime("$ano_selecionado-$mes_selecionado-01");
$titulo_pagina = "Backoffice - " . $data_base->format('F Y');

// Obter anos e meses com dados para a navegação
$periodos_disponiveis = [];
$result_periodos = $mysqli->query("SELECT DISTINCT YEAR(data_hora) as ano, MONTH(data_hora) as mes FROM precos_energia_dinamicos ORDER BY ano DESC, mes DESC");
while($p = $result_periodos->fetch_assoc()) {
    $periodos_disponiveis[$p['ano']][] = $p['mes'];
}

// Obter dias com dados para o mês selecionado
$dias_com_dados = [];
$stmt = $mysqli->prepare("SELECT DISTINCT DAY(data_hora) as dia FROM precos_energia_dinamicos WHERE YEAR(data_hora) = ? AND MONTH(data_hora) = ?");
$stmt->bind_param("ii", $ano_selecionado, $mes_selecionado);
$stmt->execute();
$result_dias = $stmt->get_result();
while($d = $result_dias->fetch_assoc()) {
    $dias_com_dados[] = $d['dia'];
}
$stmt->close();
$mysqli->close();

$dias_no_mes = (int)$data_base->format('t');
$primeiro_dia_semana = (int)$data_base->format('N'); // 1 (Seg) a 7 (Dom)

?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Backoffice - Gestão de Energia</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="style.css">
</head>
<body class="bg-gray-100 font-sans flex h-screen">
    <?php 
        $active_page = 'backoffice';
        // A sidebar não precisa do histórico de consumo, então passamos um resultado nulo
        $periodos_historicos_result = null;
        require_once 'sidebar.php'; 
    ?>

    <main id="main-content" class="flex-1 p-8 overflow-y-auto">
        <div class="max-w-7xl mx-auto">
            <h1 class="text-3xl font-bold text-gray-800">Painel de Backoffice</h1>
            <p class="text-gray-600 mb-8">Ferramentas para gestão e manutenção de dados.</p>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- Coluna da Ferramenta de Importação -->
                <div class="bg-white shadow-md rounded-lg p-6">
                    <h3 class="text-xl font-bold mb-4">Importador de Preços OMIE</h3>
                    <form id="import-form" class="space-y-4">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label for="start-date" class="block text-sm font-medium text-gray-700">Data de Início</label>
                                <input type="date" id="start-date" name="start_date" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                            </div>
                            <div>
                                <label for="end-date" class="block text-sm font-medium text-gray-700">Data de Fim</label>
                                <input type="date" id="end-date" name="end_date" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg shadow-md">
                                Importar Preços
                            </button>
                        </div>
                    </form>
                    <div id="import-output-container" class="mt-6 hidden">
                        <h4 class="font-semibold text-gray-700">Resultado da Importação:</h4>
                        <pre id="import-output" class="mt-2 bg-gray-900 text-white text-sm p-4 rounded-md h-64 overflow-y-auto whitespace-pre-wrap"></pre>
                    </div>
                </div>

                <!-- Coluna do Calendário -->
                <div class="bg-white shadow-md rounded-lg p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-xl font-bold">Dados de Preços na BD</h3>
                        <div class="flex items-center gap-2">
                            <select id="month-select" class="text-sm rounded-md border-gray-300 shadow-sm">
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?php echo $m; ?>" <?php if ($m == $mes_selecionado) echo 'selected'; ?>><?php echo DateTime::createFromFormat('!m', $m)->format('F'); ?></option>
                                <?php endfor; ?>
                            </select>
                            <select id="year-select" class="text-sm rounded-md border-gray-300 shadow-sm">
                                <?php foreach (array_keys($periodos_disponiveis) as $ano): ?>
                                    <option value="<?php echo $ano; ?>" <?php if ($ano == $ano_selecionado) echo 'selected'; ?>><?php echo $ano; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="grid grid-cols-7 gap-1 text-center text-sm">
                        <div class="font-semibold">Seg</div>
                        <div class="font-semibold">Ter</div>
                        <div class="font-semibold">Qua</div>
                        <div class="font-semibold">Qui</div>
                        <div class="font-semibold">Sex</div>
                        <div class="font-semibold">Sáb</div>
                        <div class="font-semibold">Dom</div>
                        <?php
                            // Espaços em branco para o início do mês
                            for ($i = 1; $i < $primeiro_dia_semana; $i++) {
                                echo '<div></div>';
                            }
                            // Dias do mês
                            for ($dia = 1; $dia <= $dias_no_mes; $dia++) {
                                $has_data = in_array($dia, $dias_com_dados);
                                $class = $has_data ? 'bg-green-500 text-white' : 'bg-gray-200 text-gray-600';
                                echo "<div class=\"w-10 h-10 flex items-center justify-center rounded-full $class\">$dia</div>";
                            }
                        ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Navegação do calendário
        const monthSelect = document.getElementById('month-select');
        const yearSelect = document.getElementById('year-select');

        function navigate() {
            const year = yearSelect.value;
            const month = monthSelect.value;
            window.location.href = `?ano=${year}&mes=${month}`;
        }

        monthSelect.addEventListener('change', navigate);
        yearSelect.addEventListener('change', navigate);

        // Lógica do formulário de importação
        const importForm = document.getElementById('import-form');
        const outputContainer = document.getElementById('import-output-container');
        const outputEl = document.getElementById('import-output');

        importForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            const startDate = document.getElementById('start-date').value;
            const endDate = document.getElementById('end-date').value;

            if (!startDate || !endDate) {
                alert('Por favor, selecione as datas de início e fim.');
                return;
            }

            outputContainer.classList.remove('hidden');
            outputEl.textContent = 'A iniciar importação... Por favor, aguarde.\n';
            outputEl.scrollTop = outputEl.scrollHeight;

            try {
                const response = await fetch(`api_backoffice.php?action=import_omie&start=${startDate}&end=${endDate}`);
                const reader = response.body.getReader();
                const decoder = new TextDecoder();

                while (true) {
                    const { value, done } = await reader.read();
                    if (done) break;
                    const chunk = decoder.decode(value, { stream: true });
                    outputEl.textContent += chunk;
                    outputEl.scrollTop = outputEl.scrollHeight; // Auto-scroll
                }
                outputEl.textContent += '\n\n--- Processo Concluído ---';

            } catch (error) {
                outputEl.textContent += `\n\nERRO DE REDE: ${error.message}`;
            }
        });
    });
    </script>
    <script src="main.js"></script>
</body>
</html>