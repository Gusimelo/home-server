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
    <link rel="stylesheet" href="style.css">
    <style>
        .status-contratada { background-color: #28a745; color: white; padding: 3px 8px; border-radius: 12px; font-size: 12px; }
        .status-comparacao { background-color: #007bff; color: white; padding: 3px 8px; border-radius: 12px; font-size: 12px; }
        .status-inativa { background-color: #6c757d; color: white; padding: 3px 8px; border-radius: 12px; font-size: 12px; }
        .action-buttons form { display: inline-block; margin-right: 5px; }
        .action-buttons .edit-btn { background-color: #ffc107; border: none; }
        .action-buttons button { padding: 5px 10px; font-size: 12px; cursor: pointer; }
        .msg { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; padding: 10px; border-radius: 5px; margin-bottom: 20px; }
    </style>
</head>
<body>
    <nav class="sidebar">
        <h3>Navegação</h3>
        <ul>
            <li><a href="index.php">Dashboard</a></li>
            <li><a href="fatura_detalhada.php">Fatura Detalhada</a></li>
            <li><a href="ofertas.php">Comparar Ofertas</a></li>
            <li><a href="tarifas.php" class="active">Gerir Tarifas</a></li>
        </ul>
    </nav>

    <main class="main-content">
        <div class="container">
            <h1>Gestão de Tarifas</h1>
            <p>Defina qual a sua tarifa contratada e quais as tarifas que pretende usar para comparação no dashboard.</p>

            <?php if (isset($_GET['msg'])): ?>
                <p class="msg"><?php echo htmlspecialchars($_GET['msg']); ?></p>
            <?php endif; ?>

            <div class="summary-box">
                <h3>Tarifas Disponíveis</h3>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nome</th>
                            <th>Modalidade</th>
                            <th>Estado</th>
                            <th>Data Início</th>
                            <th>Data Fim</th>
                            <th class="numeric">Potência (€/dia)</th>
                            <th class="numeric">Preço Simples</th>
                            <th class="numeric">Preço Vazio</th>
                            <th class="numeric">Preço Cheia</th>
                            <th class="numeric">Preço Ponta</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($tarifa = $tarifas_result->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo $tarifa['id']; ?></td>
                                <td><?php echo htmlspecialchars($tarifa['nome_tarifa']); ?></td>
                                <td><?php echo htmlspecialchars($tarifa['modalidade']); ?></td>
                                <td>
                                    <?php if ($tarifa['is_contratada']): ?>
                                        <span class="status-contratada">Contratada</span>
                                    <?php elseif ($tarifa['is_comparacao']): ?>
                                        <span class="status-comparacao">Comparação</span>
                                    <?php else: ?>
                                        <span class="status-inativa">Inativa</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $tarifa['data_inicio']; ?></td>
                                <td><?php echo $tarifa['data_fim'] ?? 'Em vigor'; ?></td>
                                <td class="numeric"><?php echo number_format($tarifa['custo_potencia_diario'], 5, ',', '.'); ?></td>
                                <td class="numeric"><?php echo $tarifa['modalidade'] === 'simples' ? number_format($tarifa['preco_simples'], 5, ',', '.') : '-'; ?></td>
                                <td class="numeric"><?php echo $tarifa['modalidade'] !== 'simples' ? number_format($tarifa['preco_vazio'], 5, ',', '.') : '-'; ?></td>
                                <td class="numeric"><?php echo $tarifa['modalidade'] !== 'simples' ? number_format($tarifa['preco_cheia'], 5, ',', '.') : '-'; ?></td>
                                <td class="numeric"><?php echo $tarifa['modalidade'] !== 'simples' ? number_format($tarifa['preco_ponta'], 5, ',', '.') : '-'; ?></td>
                                <td class="action-buttons">
                                    <button type="button" class="edit-btn" title="Editar esta tarifa" 
                                            data-tarifa='<?php echo htmlspecialchars(json_encode($tarifa), ENT_QUOTES, 'UTF-8'); ?>'>Editar</button>

                                    <form method="POST" action="tarifas.php">
                                        <input type="hidden" name="action" value="set_contratada">
                                        <input type="hidden" name="tarifa_id" value="<?php echo $tarifa['id']; ?>">
                                        <button type="submit" title="Definir como tarifa principal">Principal</button>
                                    </form>
                                    <form method="POST" action="tarifas.php">
                                        <input type="hidden" name="action" value="toggle_comparacao">
                                        <input type="hidden" name="tarifa_id" value="<?php echo $tarifa['id']; ?>">
                                        <button type="submit" title="Adicionar/Remover da comparação">Comparar</button>
                                    </form>
                                    <form method="POST" action="tarifas.php" onsubmit="return confirm('Tem a certeza que quer apagar esta tarifa?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="tarifa_id" value="<?php echo $tarifa['id']; ?>">
                                        <button type="submit" title="Apagar tarifa">Apagar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <div class="summary-box" style="margin-top: 30px;">
                <h3 id="form-title">Adicionar Nova Tarifa</h3>
                <form id="tarifa-form" method="POST" action="tarifas.php">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" id="form-tarifa-id" name="tarifa_id" value="0">
                    
                    <p><strong>Nome da Tarifa:</strong><br><input type="text" id="form-nome" name="nome_tarifa" required style="width: 100%;"></p>
                    <p><strong>Modalidade:</strong><br>
                        <select id="form-modalidade" name="modalidade" required style="width: 100%;">
                            <option value="simples">Simples</option>
                            <option value="bi-horario">Bi-Horário</option>
                            <option value="dinamico">Dinâmico</option>
                        </select>
                    </p>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <p><strong>Data de Início:</strong><br><input type="date" id="form-data-inicio" name="data_inicio" required value="<?php echo date('Y-m-d'); ?>"></p>
                        <p><strong>Data de Fim:</strong><br><input type="date" id="form-data-fim" name="data_fim"></p>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div>
                            <p><strong>Custo Potência (€/dia):</strong><br><input type="text" id="form-potencia" name="custo_potencia_diario" required value="0.0"></p>
                            <p><strong>Preço Simples (€/kWh):</strong><br><input type="text" id="form-simples" name="preco_simples" value="0.0"></p>
                        </div>
                        <div>
                            <p><strong>Preço Vazio (€/kWh):</strong><br><input type="text" id="form-vazio" name="preco_vazio" value="0.0"></p>
                            <p><strong>Preço Cheia (€/kWh):</strong><br><input type="text" id="form-cheia" name="preco_cheia" value="0.0"></p>
                            <p><strong>Preço Ponta (€/kWh):</strong><br><input type="text" id="form-ponta" name="preco_ponta" value="0.0"></p>
                        </div>
                    </div>

                    <p>
                        <button type="submit" id="form-submit-btn" class="main-button">Adicionar Tarifa</button>
                        <button type="button" id="form-cancel-btn" class="filtro-btn" style="display: none;">Cancelar Edição</button>
                    </p>
                </form>
                <p><small>Nota: Para tarifas dinâmicas, os preços de energia (kWh) são ignorados pois vêm do Home Assistant, mas o custo de potência é utilizado.</small></p>
            </div>

        </div>
    </main>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('tarifa-form');
        const formTitle = document.getElementById('form-title');
        const submitBtn = document.getElementById('form-submit-btn');
        const cancelBtn = document.getElementById('form-cancel-btn');
        const defaultFormTitle = 'Adicionar Nova Tarifa';
        const defaultSubmitText = 'Adicionar Tarifa';

        document.querySelectorAll('.edit-btn').forEach(button => {
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
                cancelBtn.style.display = 'inline-block';
                form.scrollIntoView({ behavior: 'smooth' });
            });
        });

        cancelBtn.addEventListener('click', function() {
            form.reset();
            form['tarifa_id'].value = 0;
            form['data_fim'].value = ''; // Limpar o campo data_fim
            formTitle.textContent = defaultFormTitle;
            submitBtn.textContent = defaultSubmitText;
            this.style.display = 'none';
        });
    });
    </script>
</body>
</html>