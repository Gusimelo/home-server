<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// --- CONFIGURAÇÃO DA BASE DE DADOS ---
// !!! IMPORTANTE: Substitua com os seus dados de acesso à MariaDB !!!
$servername = "localhost";
$username = "hass";
$password = "fcfheetl";
$dbname = "expenses";          // o nome da sua base de dados

echo "<!DOCTYPE html><html><head><title>Importar Dados</title>";
echo "<style>body { font-family: monospace; line-height: 1.6; } .success { color: green; } .error { color: red; }</style>";
echo "</head><body><h1>Relatório de Importação</h1>";

// --- DADOS A IMPORTAR ---
$data_string = "02-01-2025Água16.56Gustavo28-01-2025Luz91.77Gustavo02-12-2024NOS61Diogo02-01-2025NOS64.69Diogo03-02-2024NOS61Diogo28-02-2025Luz90.97Gustavo28-03-2025Luz86.31Mariana28-04-2025Luz87.98Gustavo03-03-2025NOS61Diogo30-05-2025Luz69.42Gustavo30-05-2025Luz67.92Mariana01-04-2025NOS61.45Diogo02-05-2025NOS67.06Diogo02-06-2025NOS62.46Diogo23-06-2025Água94.73Mariana16-07-2025Luz52.7Mariana31-08-2025Luz57.17Gustavo";

// --- LIGAÇÃO À BASE DE DADOS ---
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("<p class='error'>Falha na ligação: " . $conn->connect_error . "</p></body></html>");
}
echo "<p class='success'>Ligação à base de dados bem-sucedida.</p><hr>";

// --- PROCESSAMENTO E INSERÇÃO ---
// Expressão regular para encontrar cada transação no formato DD-MM-YYYYDescricaoValorPessoa
preg_match_all('/(\d{2}-\d{2}-\d{4})([a-zA-ZÁ-ú]+)([\d\.]+)(Gustavo|Diogo|Mariana)/u', $data_string, $matches, PREG_SET_ORDER);

if (empty($matches)) {
    echo "<p class='error'>Nenhuma transação encontrada nos dados. Verifique o formato.</p>";
} else {
    $stmt = $conn->prepare("INSERT INTO transactions (description, amount, type, person, transaction_date) VALUES (?, ?, ?, ?, ?)");
    
    foreach ($matches as $match) {
        // $match[0] é a string completa, [1] é a data, [2] a descrição, [3] o valor, [4] a pessoa
        
        // Converter data de DD-MM-YYYY para YYYY-MM-DD
        $date_obj = DateTime::createFromFormat('d-m-Y', $match[1]);
        if (!$date_obj) {
             echo "<p class='error'>Data inválida encontrada: " . htmlspecialchars($match[1]) . ". A saltar...</p>";
             continue;
        }
        $transaction_date = $date_obj->format('Y-m-d');
        
        $description = $match[2];
        $amount = (float)$match[3];
        $person = $match[4];
        $type = 'Expense'; // Todas são despesas

        $stmt->bind_param("sdsss", $description, $amount, $type, $person, $transaction_date);

        if ($stmt->execute()) {
            echo "<p class='success'>IMPORTADO: [{$transaction_date}] {$description} - {$amount}€ - {$person}</p>";
        } else {
            echo "<p class='error'>FALHOU ao importar [{$transaction_date}] {$description}: " . htmlspecialchars($stmt->error) . "</p>";
        }
    }

    $stmt->close();
    echo "<hr><p><strong>Importação concluída.</strong></p>";
}

$conn->close();
echo "</body></html>";
?>
