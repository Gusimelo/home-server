<?php
require_once 'config.php';

// --- HTML para feedback visual ---
echo '<!DOCTYPE html><html lang="pt-PT"><head><meta charset="UTF-8"><title>Atualização da Base de Dados</title>';
echo '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">';
echo '<style>body { font-family: "Inter", sans-serif; background-color: #f3f4f6; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; } .container { background: white; padding: 2rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0,0,0,0.1); text-align: left; max-width: 600px; } h1 { text-align: center; margin-bottom: 1.5rem; color: #111827; } p { margin: 0.5rem 0; font-size: 0.9rem; } .success { color: #16a34a; font-weight: 600; } .error { color: #dc2626; font-weight: 600; } .info { color: #3b82f6; }</style>';
echo '</head><body><div class="container"><h1>Atualização da Estrutura de Categorias</h1>';

// --- LIGAÇÃO À BASE DE DADOS ---
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("<p class='error'>Falha na ligação: " . $conn->connect_error . "</p></div></body></html>");
}
$conn->set_charset("utf8mb4");

function check_and_run($conn, $check_sql, $run_sql, $success_message, $exists_message) {
    $result = $conn->query($check_sql);
    if ($result && $result->num_rows > 0) {
        echo "<p class='info'>$exists_message</p>";
        return true;
    }

    if ($conn->query($run_sql) === TRUE) {
        echo "<p class='success'>SUCESSO: $success_message</p>";
    } else {
        echo "<p class='error'>ERRO ao executar: " . $conn->error . "</p>";
        // Se a query falhar porque o objeto já existe, consideramos como "sucesso" para o fluxo
        if ($conn->errno == 1060 || $conn->errno == 1061 || $conn->errno == 1826) { // Duplicate column, key, or foreign key
             echo "<p class='info'>Ignorando o erro pois o elemento provavelmente já existe.</p>";
             return true;
        }
        return false;
    }
    return true;
}

// 1. Garantir que a coluna `parent_id` existe
check_and_run($conn, 
    "SHOW COLUMNS FROM `subcategories` LIKE 'parent_id'",
    "ALTER TABLE `subcategories` ADD `parent_id` INT(11) NULL DEFAULT NULL AFTER `cost_center_id`, ADD CONSTRAINT `fk_parent_subcategory` FOREIGN KEY (`parent_id`) REFERENCES `subcategories`(`id`) ON DELETE SET NULL;",
    "Coluna 'parent_id' e chave estrangeira adicionadas a 'subcategories'.",
    "A coluna 'parent_id' já existe."
);

// 2. Garantir que a coluna `sub_subcategory` existe
check_and_run($conn,
    "SHOW COLUMNS FROM `transactions` LIKE 'sub_subcategory'",
    "ALTER TABLE `transactions` ADD `sub_subcategory` VARCHAR(255) NULL DEFAULT NULL AFTER `subcategory`;",
    "Coluna 'sub_subcategory' adicionada a 'transactions'.",
    "A coluna 'sub_subcategory' já existe."
);

// 3. Remover a antiga chave `UNIQUE` na coluna `name` se existir
$index_info = $conn->query("SHOW INDEX FROM subcategories WHERE Key_name != 'PRIMARY' AND Column_name = 'name' AND Non_unique = 0");
if ($index_info && $index_info->num_rows > 0) {
    $index = $index_info->fetch_assoc();
    $key_name = $index['Key_name'];
     if ($conn->query("ALTER TABLE subcategories DROP INDEX `$key_name`") === TRUE) {
        echo "<p class='success'>SUCESSO: Antiga chave UNIQUE ('$key_name') removida da coluna 'name'.</p>";
    } else {
        echo "<p class='error'>ERRO ao remover a antiga chave UNIQUE: " . $conn->error . "</p>";
    }
} else {
    echo "<p class='info'>Nenhuma chave UNIQUE apenas para a coluna 'name' encontrada.</p>";
}

// 4. Adicionar a nova chave `UNIQUE` composta
check_and_run($conn,
    "SHOW INDEX FROM subcategories WHERE Key_name = 'uq_category_path'",
    "ALTER TABLE `subcategories` ADD UNIQUE KEY `uq_category_path` (`cost_center_id`, `parent_id`, `name`);",
    "Nova chave UNIQUE composta adicionada para (cost_center_id, parent_id, name).",
    "A chave UNIQUE composta 'uq_category_path' já existe."
);

echo "<h2>Processo de atualização concluído.</h2>";
$conn->close();
echo '</div></body></html>';
?>
