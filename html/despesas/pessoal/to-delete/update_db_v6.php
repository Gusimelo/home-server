<?php
require_once 'config.php';

// --- HTML para feedback visual ---
echo '<!DOCTYPE html><html lang="pt-PT"><head><meta charset="UTF-8"><title>Atualização da Base de Dados v6</title>';
echo '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">';
echo '<style>body { font-family: "Inter", sans-serif; background-color: #f3f4f6; display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 2rem; } .container { background: white; padding: 2rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0,0,0,0.1); text-align: left; max-width: 800px; } h1 { text-align: center; margin-bottom: 1.5rem; color: #111827; } p { margin: 0.5rem 0; font-size: 0.9rem; line-height: 1.5; } .success { color: #16a34a; font-weight: 600; } .error { color: #dc2626; font-weight: 600; } .info { color: #3b82f6; } .code { background-color: #f3f4f6; padding: 0.2rem 0.4rem; border-radius: 0.25rem; font-family: monospace; }</style>';
echo '</head><body><div class="container"><h1>Atualização para Movimentos Divididos</h1>';

// --- LIGAÇÃO À BASE DE DADOS ---
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("<p class='error'>Falha na ligação: " . $conn->connect_error . "</p></div></body></html>");
}
$conn->set_charset("utf8mb4");

function execute_query($conn, $sql, $message) {
    if ($conn->query($sql) === TRUE) {
        echo "<p class='success'>SUCESSO: $message</p>";
        return true;
    }
    echo "<p class='error'>ERRO ao executar a query '$message': " . $conn->error . "</p>";
    return false;
}

$conn->begin_transaction();
$all_ok = true;

// 1. Criar a tabela `transaction_items`
$sql_create_items = "
CREATE TABLE IF NOT EXISTS `transaction_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_id` int(11) NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `cost_center` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subcategory` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sub_subcategory` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `transaction_id` (`transaction_id`),
  CONSTRAINT `fk_transaction_items_transaction` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
if (execute_query($conn, $sql_create_items, "Tabela 'transaction_items' criada ou já existente.")) {
    echo "<p class='info'>Esta tabela irá guardar as parcelas individuais de cada movimento.</p>";
} else { $all_ok = false; }


// 2. Criar a tabela `attachments`
$sql_create_attachments = "
CREATE TABLE IF NOT EXISTS `attachments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_id` int(11) NOT NULL,
  `file_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  KEY `transaction_id` (`transaction_id`),
  CONSTRAINT `fk_attachments_transaction` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
if (execute_query($conn, $sql_create_attachments, "Tabela 'attachments' criada ou já existente.")) {
     echo "<p class='info'>Esta tabela irá permitir múltiplos anexos por movimento.</p>";
} else { $all_ok = false; }


// 3. Migrar dados existentes para a nova estrutura
if ($all_ok) {
    echo "<h2>A iniciar migração de dados...</h2>";
    $result = $conn->query("SELECT * FROM transactions WHERE id NOT IN (SELECT DISTINCT transaction_id FROM transaction_items)");
    
    if ($result === false) {
        echo "<p class='error'>Erro ao selecionar transações para migração: " . $conn->error . "</p>";
        $all_ok = false;
    } else if ($result->num_rows > 0) {
        $migrated_count = 0;
        while($row = $result->fetch_assoc()) {
            // Migrar para transaction_items
            if ($row['type'] === 'Expense') {
                $stmt_item = $conn->prepare("INSERT INTO transaction_items (transaction_id, description, amount, cost_center, subcategory, sub_subcategory) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt_item->bind_param("isdsss", $row['id'], $row['description'], $row['amount'], $row['cost_center'], $row['subcategory'], $row['sub_subcategory']);
                if (!$stmt_item->execute()) {
                    echo "<p class='error'>Erro ao migrar item para transação ID {$row['id']}: " . $stmt_item->error . "</p>";
                    $all_ok = false; break;
                }
            }
            
            // Migrar para attachments
            if (!empty($row['file_path'])) {
                 $stmt_att = $conn->prepare("INSERT INTO attachments (transaction_id, file_path) VALUES (?, ?)");
                 $stmt_att->bind_param("is", $row['id'], $row['file_path']);
                 if (!$stmt_att->execute()) {
                    echo "<p class='error'>Erro ao migrar anexo para transação ID {$row['id']}: " . $stmt_att->error . "</p>";
                    $all_ok = false; break;
                }
            }
            $migrated_count++;
        }
        if ($all_ok) {
            echo "<p class='success'>$migrated_count transações existentes foram migradas com sucesso para a nova estrutura.</p>";
        }
    } else {
        echo "<p class='info'>Nenhuma transação nova para migrar.</p>";
    }
}

// 4. Renomear colunas antigas (opcional, mas recomendado)
if ($all_ok) {
    echo "<h2>A finalizar estrutura...</h2>";
    $conn->query("ALTER TABLE `transactions` CHANGE `file_path` `file_path_old` VARCHAR(255) NULL DEFAULT NULL;");
    echo "<p class='info'>A coluna <span class='code'>file_path</span> foi renomeada para <span class='code'>file_path_old</span> para evitar conflitos. Pode ser removida no futuro.</p>";
}


if ($all_ok) {
    $conn->commit();
    echo "<h1>Atualização Concluída com Sucesso!</h1>";
    echo "<p>Pode agora apagar este ficheiro (`update_schema_v6.php`) do seu servidor.</p>";
} else {
    $conn->rollback();
    echo "<h1 class='error'>Ocorreram erros. A atualização foi revertida.</h1>";
    echo "<p>Por favor, verifique as mensagens de erro acima e tente novamente.</p>";
}

$conn->close();
echo '</div></body></html>';
?>
