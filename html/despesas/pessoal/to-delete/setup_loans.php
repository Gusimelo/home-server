<?php
require_once 'config.php'; // Inclui as definições centrais

// --- CONFIGURAÇÃO BÁSICA E GESTÃO DE ERROS ---
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<!DOCTYPE html><html lang='pt-PT'><head><meta charset='UTF-8'><title>Configuração de Empréstimos</title>";
echo "<style>body { font-family: sans-serif; padding: 2em; line-height: 1.6; } .success { color: green; } .error { color: red; }</style>";
echo "</head><body><h1>Configuração da Base de Dados para Empréstimos</h1>";

// --- LIGAÇÃO À BASE DE DADOS ---
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("<p class='error'>Falha na ligação: " . $conn->connect_error . "</p></body></html>");
}
$conn->set_charset("utf8mb4");

// 1. CRIAR A TABELA 'loans'
$sql_create_loans = "
CREATE TABLE IF NOT EXISTS `loans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `monthly_payment` decimal(10,2) NOT NULL,
  `start_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

echo "<p>A tentar criar a tabela <strong>loans</strong>...</p>";
if ($conn->query($sql_create_loans) === TRUE) {
    echo "<p class='success'>Tabela 'loans' criada com sucesso ou já existente.</p>";
} else {
    echo "<p class='error'>Erro ao criar a tabela 'loans': " . $conn->error . "</p>";
}

// 2. ADICIONAR A COLUNA 'loan_id' À TABELA 'transactions'
$sql_check_column = "SHOW COLUMNS FROM `transactions` LIKE 'loan_id'";
$result = $conn->query($sql_check_column);

if ($result->num_rows == 0) {
    $sql_alter_transactions = "ALTER TABLE `transactions` ADD `loan_id` INT(11) NULL DEFAULT NULL AFTER `file_path`, ADD INDEX `loan_id` (`loan_id`)";
    echo "<p>A tentar adicionar a coluna <strong>loan_id</strong> à tabela <strong>transactions</strong>...</p>";
    if ($conn->query($sql_alter_transactions) === TRUE) {
        echo "<p class='success'>Coluna 'loan_id' adicionada com sucesso.</p>";
    } else {
        echo "<p class='error'>Erro ao adicionar a coluna 'loan_id': " . $conn->error . "</p>";
    }
} else {
    echo "<p class='success'>Coluna 'loan_id' já existe na tabela 'transactions'.</p>";
}

echo "<h2>Configuração concluída!</h2>";
echo "<p>Pode apagar este ficheiro (`setup_loans.php`) agora.</p>";
echo "</body></html>";

$conn->close();
?>
