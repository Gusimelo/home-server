<?php
require_once 'config.php';

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_error) { die('Erro de ligação.'); }
$mysqli->set_charset('utf8mb4');

$mensagem = '';

// --- LÓGICA DE BACKEND PARA GERIR FÓRMULAS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $formula_id = isset($_POST['formula_id']) ? (int)$_POST['formula_id'] : 0;

    if ($action === 'delete' && $formula_id > 0) {
        // Adicionar verificação se a fórmula está em uso
        $check_stmt = $mysqli->prepare("SELECT COUNT(*) as count FROM tarifas WHERE formula_id = ?");
        $check_stmt->bind_param("i", $formula_id);
        $check_stmt->execute();
        $count = $check_stmt->get_result()->fetch_assoc()['count'];
        if ($count > 0) {
            $mensagem = "ERRO: Não pode apagar uma fórmula que está a ser usada por $count tarifa(s).";
        } else {
            $stmt = $mysqli->prepare("DELETE FROM formulas_dinamicas WHERE id = ?");
            $stmt->bind_param("i", $formula_id);
            $stmt->execute();
            $mensagem = "Fórmula #$formula_id removida com sucesso.";
        }
    }

    if ($action === 'save') {
        $nome_formula = $_POST['nome_formula'];
        $expressao_calculo = $_POST['expressao_calculo'];
        $descricao = $_POST['descricao'];
        $data_inicio = $_POST['data_inicio'];
        $data_fim = empty($_POST['data_fim']) ? null : $_POST['data_fim'];

        if ($formula_id > 0) { // Editar
            $stmt = $mysqli->prepare("UPDATE formulas_dinamicas SET nome_formula=?, expressao_calculo=?, descricao=?, data_inicio=?, data_fim=? WHERE id=?");
            $stmt->bind_param("sssssi", $nome_formula, $expressao_calculo, $descricao, $data_inicio, $data_fim, $formula_id);
            $mensagem = "Fórmula atualizada com sucesso.";
        } else { // Adicionar
            $stmt = $mysqli->prepare("INSERT INTO formulas_dinamicas (nome_formula, expressao_calculo, descricao, data_inicio, data_fim) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss", $nome_formula, $expressao_calculo, $descricao, $data_inicio, $data_fim);
            $mensagem = "Nova fórmula adicionada com sucesso.";
        }
        $stmt->execute();
    }
    
    if ($mensagem) {
        header("Location: formulas.php?msg=" . urlencode($mensagem));
        exit();
    }
}

$formulas_result = $mysqli->query("SELECT * FROM formulas_dinamicas ORDER BY data_inicio DESC, nome_formula ASC");

?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Gestão de Fórmulas Dinâmicas</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="style.css">
</head>
<body class="bg-gray-100 font-sans flex h-screen">    
    <?php 
        $active_page = 'formulas'; // Defina esta variável para o sidebar saber qual página está ativa
        require_once 'sidebar.php'; 
    ?>

    <main id="main-content" class="flex-1 p-8 overflow-y-auto">
        <div class="container mx-auto">
            <h1 class="text-3xl font-bold mb-2">Gestão de Fórmulas Dinâmicas</h1>
            <p class="text-gray-600 mb-6">Crie e edite as "receitas" de cálculo para os tarifários dinâmicos de cada comercializador.</p>

            <?php if (isset($_GET['msg'])): ?>
                <p class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4"><?php echo htmlspecialchars($_GET['msg']); ?></p>
            <?php endif; ?>

            <div class="bg-white shadow-md rounded-lg p-6">
                <h3 class="text-xl font-bold mb-4">Fórmulas Disponíveis</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full bg-white">
                        <thead class="bg-gray-800 text-white">
                        <tr>
                            <th class="py-3 px-4 text-left">ID</th>
                            <th class="py-3 px-4 text-left">Nome da Fórmula</th>
                            <th class="py-3 px-4 text-left">Data Início</th>
                            <th class="py-3 px-4 text-left">Data Fim</th>
                            <th class="py-3 px-4 text-left">Expressão de Cálculo</th>
                            <th class="py-3 px-4 text-left">Descrição</th>
                            <th class="py-3 px-4 text-left">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-700">
                        <?php while ($formula = $formulas_result->fetch_assoc()): ?>
                            <tr class="border-b border-gray-200 hover:bg-gray-100">
                                <td class="py-3 px-4"><?php echo $formula['id']; ?></td>
                                <td class="py-3 px-4 font-semibold"><?php echo htmlspecialchars($formula['nome_formula']); ?></td>
                                <td class="py-3 px-4"><?php echo $formula['data_inicio']; ?></td>
                                <td class="py-3 px-4"><?php echo $formula['data_fim'] ?? 'Em vigor'; ?></td>
                                <td class="py-3 px-4 font-mono text-sm bg-gray-50"><?php echo htmlspecialchars($formula['expressao_calculo']); ?></td>
                                <td class="py-3 px-4 text-sm text-gray-600"><?php echo htmlspecialchars($formula['descricao'] ?? ''); ?></td>
                                <td class="py-3 px-4 whitespace-nowrap">
                                    <button type="button" class="bg-yellow-500 hover:bg-yellow-600 text-white text-xs font-bold py-1 px-2 rounded mr-1" title="Editar esta fórmula" 
                                            data-formula='<?php echo htmlspecialchars(json_encode($formula), ENT_QUOTES, 'UTF-8'); ?>'>Editar</button>
                                    <form method="POST" action="formulas.php" onsubmit="return confirm('Tem a certeza que quer apagar esta fórmula?');" class="inline-block">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="formula_id" value="<?php echo $formula['id']; ?>">
                                        <button type="submit" class="bg-red-500 hover:bg-red-600 text-white text-xs font-bold py-1 px-2 rounded" title="Apagar fórmula">Apagar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
                </div>
            </div>

            <div class="mt-8 text-right">
                <button id="add-formula-btn" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded-lg shadow">
                    Adicionar Nova Fórmula
                </button>
            </div>

        </div>
    </main>

    <!-- Modal para Adicionar/Editar Fórmula -->
    <div id="formula-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center p-4 z-50">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-lg max-h-[90vh] flex flex-col">
            <div class="p-6 border-b flex justify-between items-center">
                <h3 id="form-title" class="text-xl font-bold">Adicionar Nova Fórmula</h3>
                <button id="close-modal-btn" class="text-gray-400 hover:text-gray-800 text-3xl leading-none font-bold">&times;</button>
            </div>
            <form id="formula-form" method="POST" action="formulas.php" class="p-6 overflow-y-auto">
                <input type="hidden" name="action" value="save">
                <input type="hidden" id="form-formula-id" name="formula_id" value="0">
                
                <div class="mb-4">
                    <label for="form-nome" class="block text-sm font-medium text-gray-700">Nome da Fórmula (Ex: Coopérnico 2024)</label>
                    <input type="text" id="form-nome" name="nome_formula" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                </div>
                <div class="mb-4">
                    <label for="form-expressao" class="block text-sm font-medium text-gray-700">Expressão de Cálculo</label>
                    <input type="text" id="form-expressao" name="expressao_calculo" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm font-mono" placeholder="(OMIE * (1 + PERDAS)) + 0.01">
                    <p class="mt-2 text-xs text-gray-500">Variáveis disponíveis: <strong>OMIE</strong> (preço €/kWh) e <strong>PERDAS</strong> (fator de perdas, ex: 0.05).</p>
                </div>
                <div class="mb-4">
                    <label for="form-descricao" class="block text-sm font-medium text-gray-700">Descrição (opcional)</label>
                    <textarea id="form-descricao" name="descricao" rows="2" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></textarea>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                    <div>
                        <label for="form-data-inicio" class="block text-sm font-medium text-gray-700">Data de Início</label>
                        <input type="date" id="form-data-inicio" name="data_inicio" required value="<?php echo date('Y-m-d'); ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    </div>
                    <div>
                        <label for="form-data-fim" class="block text-sm font-medium text-gray-700">Data de Fim (opcional)</label>
                        <input type="date" id="form-data-fim" name="data_fim" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    </div>
                </div>

                <div class="p-6 bg-gray-50 rounded-b-lg flex justify-end gap-4">
                    <button type="button" id="form-cancel-btn" class="bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50">Cancelar</button>
                    <button type="submit" id="form-submit-btn" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">Adicionar Fórmula</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const modal = document.getElementById('formula-modal');
        const form = document.getElementById('formula-form');
        const openModal = () => modal.classList.remove('hidden');
        const closeModal = () => modal.classList.add('hidden');

        document.getElementById('add-formula-btn').addEventListener('click', () => {
            form.reset();
            form['formula_id'].value = 0;
            document.getElementById('form-title').textContent = 'Adicionar Nova Fórmula';
            document.getElementById('form-submit-btn').textContent = 'Adicionar Fórmula';
            openModal();
        });

        document.querySelectorAll('button[data-formula]').forEach(button => {
            button.addEventListener('click', function() {
                const formula = JSON.parse(this.getAttribute('data-formula'));
                form['formula_id'].value = formula.id;
                form['nome_formula'].value = formula.nome_formula;
                form['expressao_calculo'].value = formula.expressao_calculo;
                form['descricao'].value = formula.descricao;
                form['data_inicio'].value = formula.data_inicio;
                form['data_fim'].value = formula.data_fim;
                document.getElementById('form-title').textContent = `A Editar Fórmula #${formula.id}`;
                document.getElementById('form-submit-btn').textContent = 'Atualizar Fórmula';
                openModal();
            });
        });

        document.getElementById('form-cancel-btn').addEventListener('click', closeModal);
        document.getElementById('close-modal-btn').addEventListener('click', closeModal);
        modal.addEventListener('click', (e) => e.target === modal && closeModal());
    });
    </script>
    <script src="main.js"></script>
</body>
</html>