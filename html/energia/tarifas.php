<?php
require_once 'config.php';

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_error) { die('Erro de ligação.'); }
$mysqli->set_charset('utf8mb4');

$mensagem = '';

// --- LÓGICA DE BACKEND PARA GERIR TARIFAS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $tarifa_id = isset($_POST['tarifa_id']) ? (int)$_POST['tarifa_id'] : 0;

    if ($action === 'set_contratada' && $tarifa_id > 0) {
        $mysqli->begin_transaction();
        $mysqli->query("UPDATE tarifas SET is_contratada = FALSE");
        $mysqli->query("UPDATE tarifas SET is_contratada = TRUE, is_comparacao = FALSE WHERE id = $tarifa_id");
        $mysqli->commit();
        $mensagem = "Tarifa #$tarifa_id definida como a principal contratada.";
    }

    if ($action === 'toggle_comparacao' && $tarifa_id > 0) {
        // Verifica se já existem 2 tarifas de comparação
        $result = $mysqli->query("SELECT COUNT(*) as count FROM tarifas WHERE is_comparacao = TRUE AND id != $tarifa_id");
        $count = $result->fetch_assoc()['count'];

        // Verifica o estado atual da tarifa clicada
        $result_current = $mysqli->query("SELECT is_comparacao FROM tarifas WHERE id = $tarifa_id");
        $is_currently_comparacao = $result_current->fetch_assoc()['is_comparacao'];

        if ($is_currently_comparacao) {
            // Se já está em comparação, desativa-a
            $mysqli->query("UPDATE tarifas SET is_comparacao = FALSE WHERE id = $tarifa_id");
            $mensagem = "Tarifa #$tarifa_id removida da comparação.";
        } elseif ($count < 2) {
            // Se não está em comparação e há espaço, ativa-a
            $mysqli->query("UPDATE tarifas SET is_comparacao = TRUE, is_contratada = FALSE WHERE id = $tarifa_id");
            $mensagem = "Tarifa #$tarifa_id adicionada para comparação.";
        } else {
            // Se não há espaço, mostra erro
            $mensagem = "ERRO: Já existem 2 tarifas em comparação. Desative uma primeiro.";
        }
    }

    if ($action === 'delete' && $tarifa_id > 0) {
        $stmt = $mysqli->prepare("DELETE FROM tarifas WHERE id = ?");
        $stmt->bind_param("i", $tarifa_id);
        $stmt->execute();
        $mensagem = "Tarifa #$tarifa_id removida com sucesso.";
    }

    if ($action === 'save') {
        $nome = $_POST['nome_tarifa'];
        $modalidade = $_POST['modalidade'];
        $potencia = (float)$_POST['custo_potencia_diario'];
        $p_vazio = (float)$_POST['preco_vazio'];
        $p_cheia = (float)$_POST['preco_cheia'];
        $p_ponta = (float)$_POST['preco_ponta'];
        $p_simples = (float)$_POST['preco_simples'];
        $data_inicio = $_POST['data_inicio'];
        $data_fim = empty($_POST['data_fim']) ? null : $_POST['data_fim'];

        if ($tarifa_id > 0) { // Editar
            $stmt = $mysqli->prepare("UPDATE tarifas SET nome_tarifa=?, modalidade=?, preco_vazio=?, preco_cheia=?, preco_ponta=?, preco_simples=?, custo_potencia_diario=?, data_inicio=?, data_fim=? WHERE id=?");
            $stmt->bind_param("ssdddddssi", $nome, $modalidade, $p_vazio, $p_cheia, $p_ponta, $p_simples, $potencia, $data_inicio, $data_fim, $tarifa_id);
            $mensagem = "Tarifa atualizada com sucesso.";
        } else { // Adicionar
            $stmt = $mysqli->prepare("INSERT INTO tarifas (nome_tarifa, modalidade, preco_vazio, preco_cheia, preco_ponta, preco_simples, custo_potencia_diario, data_inicio, data_fim) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssdddddss", $nome, $modalidade, $p_vazio, $p_cheia, $p_ponta, $p_simples, $potencia, $data_inicio, $data_fim);
            $mensagem = "Nova tarifa adicionada com sucesso.";
        }
        $stmt->execute();
    }
    
    if ($mensagem) {
        header("Location: tarifas.php?msg=" . urlencode($mensagem));
        exit();
    }
}

$tarifas_result = $mysqli->query("SELECT * FROM tarifas ORDER BY data_inicio DESC, nome_tarifa ASC");

?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Gestão de Tarifas</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 font-sans flex">    
    <?php 
        $active_page = 'tarifas';
        require_once 'sidebar.php'; 
    ?>

    <main class="flex-1 p-8">
        <div class="container mx-auto">
            <h1 class="text-3xl font-bold mb-2">Gestão de Tarifas</h1>
            <p class="text-gray-600 mb-6">Defina qual a sua tarifa contratada e quais as tarifas que pretende usar para comparação no dashboard.</p>

            <?php if (isset($_GET['msg'])): ?>
                <p class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4"><?php echo htmlspecialchars($_GET['msg']); ?></p>
            <?php endif; ?>

            <div class="bg-white shadow-md rounded-lg p-6">
                <h3 class="text-xl font-bold mb-4">Tarifas Disponíveis</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full bg-white">
                        <thead class="bg-gray-800 text-white">
                        <tr>
                            <th class="py-3 px-4 text-left">ID</th>
                            <th class="py-3 px-4 text-left">Nome</th>
                            <th class="py-3 px-4 text-left">Modalidade</th>
                            <th class="py-3 px-4 text-left">Estado</th>
                            <th class="py-3 px-4 text-left">Data Início</th>
                            <th class="py-3 px-4 text-left">Data Fim</th>
                            <th class="py-3 px-4 text-right">Potência (€/dia)</th>
                            <th class="py-3 px-4 text-right">Preço Simples</th>
                            <th class="py-3 px-4 text-right">Preço Vazio</th>
                            <th class="py-3 px-4 text-right">Preço Cheia</th>
                            <th class="py-3 px-4 text-right">Preço Ponta</th>
                            <th class="py-3 px-4 text-left">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-700">
                        <?php while ($tarifa = $tarifas_result->fetch_assoc()): ?>
                            <tr class="border-b border-gray-200 hover:bg-gray-100">
                                <td class="py-3 px-4"><?php echo $tarifa['id']; ?></td>
                                <td class="py-3 px-4"><?php echo htmlspecialchars($tarifa['nome_tarifa']); ?></td>
                                <td class="py-3 px-4"><?php echo htmlspecialchars($tarifa['modalidade']); ?></td>
                                <td class="py-3 px-4">
                                    <?php if ($tarifa['is_contratada']): ?>
                                        <span class="bg-green-500 text-white text-xs font-semibold mr-2 px-2.5 py-0.5 rounded-full">Contratada</span>
                                    <?php elseif ($tarifa['is_comparacao']): ?>
                                        <span class="bg-blue-500 text-white text-xs font-semibold mr-2 px-2.5 py-0.5 rounded-full">Comparação</span>
                                    <?php else: ?>
                                        <span class="bg-gray-500 text-white text-xs font-semibold mr-2 px-2.5 py-0.5 rounded-full">Inativa</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-4"><?php echo $tarifa['data_inicio']; ?></td>
                                <td class="py-3 px-4"><?php echo $tarifa['data_fim'] ?? 'Em vigor'; ?></td>
                                <td class="py-3 px-4 text-right"><?php echo number_format($tarifa['custo_potencia_diario'], 5, ',', '.'); ?></td>
                                <td class="py-3 px-4 text-right"><?php echo $tarifa['modalidade'] === 'simples' ? number_format($tarifa['preco_simples'], 5, ',', '.') : '-'; ?></td>
                                <td class="py-3 px-4 text-right"><?php echo $tarifa['modalidade'] !== 'simples' ? number_format($tarifa['preco_vazio'], 5, ',', '.') : '-'; ?></td>
                                <td class="py-3 px-4 text-right"><?php echo $tarifa['modalidade'] !== 'simples' ? number_format($tarifa['preco_cheia'], 5, ',', '.') : '-'; ?></td>
                                <td class="py-3 px-4 text-right"><?php echo $tarifa['modalidade'] !== 'simples' ? number_format($tarifa['preco_ponta'], 5, ',', '.') : '-'; ?></td>
                                <td class="py-3 px-4 whitespace-nowrap">
                                    <button type="button" class="bg-yellow-500 hover:bg-yellow-600 text-white text-xs font-bold py-1 px-2 rounded mr-1" title="Editar esta tarifa" 
                                            data-tarifa='<?php echo htmlspecialchars(json_encode($tarifa), ENT_QUOTES, 'UTF-8'); ?>'>Editar</button>

                                    <form method="POST" action="tarifas.php" class="inline-block">
                                        <input type="hidden" name="action" value="set_contratada">
                                        <input type="hidden" name="tarifa_id" value="<?php echo $tarifa['id']; ?>">
                                        <button type="submit" class="bg-green-500 hover:bg-green-600 text-white text-xs font-bold py-1 px-2 rounded mr-1" title="Definir como tarifa principal">Principal</button>
                                    </form>
                                    <form method="POST" action="tarifas.php" class="inline-block">
                                        <input type="hidden" name="action" value="toggle_comparacao">
                                        <input type="hidden" name="tarifa_id" value="<?php echo $tarifa['id']; ?>">
                                        <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white text-xs font-bold py-1 px-2 rounded mr-1" title="Adicionar/Remover da comparação">Comparar</button>
                                    </form>
                                    <form method="POST" action="tarifas.php" onsubmit="return confirm('Tem a certeza que quer apagar esta tarifa?');" class="inline-block">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="tarifa_id" value="<?php echo $tarifa['id']; ?>">
                                        <button type="submit" class="bg-red-500 hover:bg-red-600 text-white text-xs font-bold py-1 px-2 rounded" title="Apagar tarifa">Apagar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
                </div>
            </div>

            <div class="mt-8 text-right">
                <button id="add-tarifa-btn" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded-lg shadow">
                    Adicionar Nova Tarifa
                </button>
            </div>

        </div>
    </main>

    <!-- Modal para Adicionar/Editar Tarifa -->
    <div id="tarifa-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center p-4 z-50">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[90vh] flex flex-col">
            <div class="p-6 border-b flex justify-between items-center">
                <h3 id="form-title" class="text-xl font-bold">Adicionar Nova Tarifa</h3>
                <button id="close-modal-btn" class="text-gray-400 hover:text-gray-800 text-3xl leading-none font-bold">&times;</button>
            </div>
            <form id="tarifa-form" method="POST" action="tarifas.php" class="p-6 overflow-y-auto">
                <input type="hidden" name="action" value="save">
                <input type="hidden" id="form-tarifa-id" name="tarifa_id" value="0">
                
                <div class="mb-4">
                    <label for="form-nome" class="block text-sm font-medium text-gray-700">Nome da Tarifa</label>
                    <input type="text" id="form-nome" name="nome_tarifa" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                </div>
                <div class="mb-4">
                    <label for="form-modalidade" class="block text-sm font-medium text-gray-700">Modalidade</label>
                    <select id="form-modalidade" name="modalidade" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                        <option value="simples">Simples</option>
                        <option value="bi-horario">Bi-Horário</option>
                        <option value="dinamico">Dinâmico</option>
                    </select>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                    <div>
                        <label for="form-data-inicio" class="block text-sm font-medium text-gray-700">Data de Início</label>
                        <input type="date" id="form-data-inicio" name="data_inicio" required value="<?php echo date('Y-m-d'); ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                    </div>
                    <div>
                        <label for="form-data-fim" class="block text-sm font-medium text-gray-700">Data de Fim (opcional)</label>
                        <input type="date" id="form-data-fim" name="data_fim" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                    <div>
                        <div class="mb-4">
                            <label for="form-potencia" class="block text-sm font-medium text-gray-700">Custo Potência (€/dia)</label>
                            <input type="text" id="form-potencia" name="custo_potencia_diario" required value="0.0" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                        </div>
                        <div>
                            <label for="form-simples" class="block text-sm font-medium text-gray-700">Preço Simples (€/kWh)</label>
                            <input type="text" id="form-simples" name="preco_simples" value="0.0" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                        </div>
                    </div>
                    <div>
                        <div class="mb-4">
                            <label for="form-vazio" class="block text-sm font-medium text-gray-700">Preço Vazio (€/kWh)</label>
                            <input type="text" id="form-vazio" name="preco_vazio" value="0.0" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                        </div>
                        <div class="mb-4">
                            <label for="form-cheia" class="block text-sm font-medium text-gray-700">Preço Cheia (€/kWh)</label>
                            <input type="text" id="form-cheia" name="preco_cheia" value="0.0" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                        </div>
                        <div>
                            <label for="form-ponta" class="block text-sm font-medium text-gray-700">Preço Ponta (€/kWh)</label>
                            <input type="text" id="form-ponta" name="preco_ponta" value="0.0" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                        </div>
                    </div>
                </div>
                <p class="px-6 text-sm text-gray-600">Nota: Para tarifas dinâmicas, os preços de energia (kWh) são ignorados pois vêm do Home Assistant, mas o custo de potência é utilizado.</p>

                <div class="p-6 bg-gray-50 rounded-b-lg flex justify-end gap-4">
                    <button type="button" id="form-cancel-btn" class="bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50">Cancelar</button>
                    <button type="submit" id="form-submit-btn" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">Adicionar Tarifa</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const modal = document.getElementById('tarifa-modal');
        const form = document.getElementById('tarifa-form');
        const formTitle = document.getElementById('form-title');
        const submitBtn = document.getElementById('form-submit-btn');
        const addBtn = document.getElementById('add-tarifa-btn');
        const cancelBtn = document.getElementById('form-cancel-btn');
        const closeModalBtn = document.getElementById('close-modal-btn');

        const defaultFormTitle = 'Adicionar Nova Tarifa';
        const defaultSubmitText = 'Adicionar Tarifa';

        const openModal = () => {
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        };

        const closeModal = () => {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        };

        const resetForm = () => {
            form.reset();
            form['tarifa_id'].value = 0;
            form['data_fim'].value = ''; // Limpar o campo data_fim
            formTitle.textContent = defaultFormTitle;
            submitBtn.textContent = defaultSubmitText;
        };

        addBtn.addEventListener('click', () => {
            resetForm();
            openModal();
        });

        document.querySelectorAll('button[data-tarifa]').forEach(button => {
            button.addEventListener('click', function() {
                const tarifa = JSON.parse(this.getAttribute('data-tarifa'));
                
                form['tarifa_id'].value = tarifa.id;
                form['nome_tarifa'].value = tarifa.nome_tarifa;
                form['modalidade'].value = tarifa.modalidade;
                form['data_inicio'].value = tarifa.data_inicio;
                form['data_fim'].value = tarifa.data_fim;
                form['custo_potencia_diario'].value = tarifa.custo_potencia_diario;
                form['preco_simples'].value = tarifa.preco_simples;
                form['preco_vazio'].value = tarifa.preco_vazio;
                form['preco_cheia'].value = tarifa.preco_cheia;
                form['preco_ponta'].value = tarifa.preco_ponta;

                formTitle.textContent = `A Editar Tarifa #${tarifa.id}`;
                submitBtn.textContent = 'Atualizar Tarifa';
                openModal();
            });
        });

        cancelBtn.addEventListener('click', function() {
            closeModal();
        });

        closeModalBtn.addEventListener('click', closeModal);

        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                closeModal();
            }
        });

        // Verifica se há dados de uma oferta na URL para pré-preencher o formulário
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('add_from_offer[nome]')) {
            resetForm(); // Garante que o formulário está limpo

            form['nome_tarifa'].value = urlParams.get('add_from_offer[nome]');
            form['modalidade'].value = urlParams.get('add_from_offer[modalidade]');
            form['custo_potencia_diario'].value = urlParams.get('add_from_offer[potencia]');
            form['preco_simples'].value = urlParams.get('add_from_offer[simples]');
            form['preco_vazio'].value = urlParams.get('add_from_offer[vazio]');
            form['preco_cheia'].value = urlParams.get('add_from_offer[cheia]');
            form['preco_ponta'].value = urlParams.get('add_from_offer[ponta]');
            
            openModal();
        }
    });
    </script>
</body>
</html>