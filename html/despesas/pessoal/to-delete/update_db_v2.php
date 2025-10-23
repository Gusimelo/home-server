<?php
require_once 'config.php';

echo "<!DOCTYPE html><html lang='pt-PT'><head><meta charset='UTF-8'><title>Atualização da Base de Dados</title>";
echo "<style>body { font-family: sans-serif; background-color: #f4f4f4; color: #333; padding: 20px; } .container { max-width: 600px; margin: auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); } .success { color: #28a745; } .error { color: #dc3545; } .info { color: #17a2b8; }</style>";
echo "</head><body><div class='container'>";
echo "<h1>Atualizador de Esquema da Base de Dados (v2)</h1>";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("<p class='error'>Falha na ligação: " . $conn->connect_error . "</p></div></body></html>");
}

$columns_to_add = [
    'interest_rate' => "ADD `interest_rate` DECIMAL(5, 2) DEFAULT 0.00 AFTER `monthly_payment`",
    'end_date' => "ADD `end_date` DATE NULL AFTER `start_date`"
];

$all_successful = true;

foreach ($columns_to_add as $column => $sql_alter) {
    $sql_check = "SHOW COLUMNS FROM `loans` LIKE '{$column}'";
    $result = $conn->query($sql_check);
    
    if ($result->num_rows > 0) {
        echo "<p class='info'>A coluna `{$column}` já existe. Nenhuma ação necessária.</p>";
    } else {
        $sql = "ALTER TABLE `loans` {$sql_alter};";
        echo "<p>A tentar adicionar a coluna `{$column}`...</p>";

        if ($conn->query($sql) === TRUE) {
            echo "<p class='success'>Coluna `{$column}` adicionada com sucesso!</p>";
        } else {
            echo "<p class='error'>Erro ao adicionar a coluna `{$column}`: " . $conn->error . "</p>";
            $all_successful = false;
        }
    }
}

if ($all_successful) {
    echo "<hr><p><b>Base de dados atualizada com sucesso.</b> Por segurança, pode agora apagar este ficheiro (`update_schema_v2.php`).</p>";
}

$conn->close();
echo "</div></body></html>";
?>
