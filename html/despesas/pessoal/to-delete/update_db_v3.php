<?php
require_once 'config.php';

echo '<!DOCTYPE html><html lang="pt-PT"><head><meta charset="UTF-8"><title>Atualização da Base de Dados</title>';
echo '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">';
echo '<style>body { font-family: "Inter", sans-serif; background-color: #f3f4f6; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; } .container { background: white; padding: 2rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0,0,0,0.1); text-align: center; } .success { color: #16a34a; } .error { color: #dc2626; }</style>';
echo '</head><body><div class="container">';

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("<h2 class='error'>Falha na ligação: " . $conn->connect_error . "</h2></div></body></html>");
}

$sql_check_column = "SHOW COLUMNS FROM `transactions` LIKE 'sub_subcategory'";
$result = $conn->query($sql_check_column);

if ($result->num_rows > 0) {
    echo "<h2>A coluna 'sub_subcategory' já existe na tabela 'transactions'. Nenhuma ação foi tomada.</h2>";
} else {
    $sql_add_column = "ALTER TABLE `transactions` ADD `sub_subcategory` VARCHAR(255) NULL DEFAULT NULL AFTER `subcategory`;";
    if ($conn->query($sql_add_column) === TRUE) {
        echo "<h2 class='success'>Tabela 'transactions' atualizada com sucesso! A coluna 'sub_subcategory' foi adicionada.</h2>";
    } else {
        echo "<h2 class='error'>Erro ao atualizar a tabela 'transactions': " . $conn->error . "</h2>";
    }
}

$conn->close();
echo '</div></body></html>';
?>