<?php
require_once 'config.php';

// --- HTML and CSS for better visual feedback ---
echo "<!DOCTYPE html><html lang='pt-PT'><head><meta charset='UTF-8'><title>Configuração de Categorias</title>";
echo "<style>body { font-family: sans-serif; background-color: #f4f4f4; color: #333; padding: 20px; } .container { max-width: 800px; margin: auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); } .success { color: #28a745; } .error { color: #dc3545; } .info { color: #17a2b8; }</style>";
echo "</head><body><div class='container'>";
echo "<h1>Configurador da Base de Dados para Categorias</h1>";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("<p class='error'>Falha na ligação: " . $conn->connect_error . "</p></div></body></html>");
}
$conn->set_charset("utf8mb4");

// --- 1. Create cost_centers table ---
$sql_cost_centers = "
CREATE TABLE IF NOT EXISTS `cost_centers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

echo "<p>A verificar/criar tabela <strong>cost_centers</strong>...</p>";
if ($conn->query($sql_cost_centers) === TRUE) {
    echo "<p class='success'>Tabela `cost_centers` pronta.</p>";
} else {
    die("<p class='error'>Erro ao criar a tabela `cost_centers`: " . $conn->error . "</p></div></body></html>");
}

// --- 2. Create subcategories table ---
$sql_subcategories = "
CREATE TABLE IF NOT EXISTS `subcategories` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `cost_center_id` INT NOT NULL,
  FOREIGN KEY (`cost_center_id`) REFERENCES `cost_centers`(`id`) ON DELETE CASCADE,
  UNIQUE (`name`, `cost_center_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

echo "<p>A verificar/criar tabela <strong>subcategories</strong>...</p>";
if ($conn->query($sql_subcategories) === TRUE) {
    echo "<p class='success'>Tabela `subcategories` pronta.</p>";
} else {
    die("<p class='error'>Erro ao criar a tabela `subcategories`: " . $conn->error . "</p></div></body></html>");
}

// --- 3. Migrate data from old config file ---
echo "<hr><h2>A migrar categorias do ficheiro de configuração...</h2>";

// Temporarily define the old structure here for migration purposes
$old_cost_centers_structure = [
    'Casa' => ['Hipoteca', 'Energia', 'Água', 'Gás', 'Seguros', 'Manutenção', 'Condomínio', 'Outros'],
    'Alimentação' => ['Supermercado', 'Restauração', 'Take-away'],
    'Transportes' => ['Combustível', 'Manutenção Veículo', 'Transportes Públicos'],
    'Saúde' => ['Farmácia', 'Consultas'],
    'Lazer' => ['Férias', 'Cinema', 'Jantares', 'Outros'],
    'Outros' => ['Vestuário', 'Educação', 'Donativos', 'Geral']
];

$stmt_cc_check = $conn->prepare("SELECT id FROM cost_centers WHERE name = ?");
$stmt_cc_insert = $conn->prepare("INSERT INTO cost_centers (name) VALUES (?)");
$stmt_sc_check = $conn->prepare("SELECT id FROM subcategories WHERE name = ? AND cost_center_id = ?");
$stmt_sc_insert = $conn->prepare("INSERT INTO subcategories (name, cost_center_id) VALUES (?, ?)");

foreach ($old_cost_centers_structure as $cc_name => $subcategories) {
    $stmt_cc_check->bind_param("s", $cc_name);
    $stmt_cc_check->execute();
    $result_cc = $stmt_cc_check->get_result();
    
    $cc_id = null;
    if ($result_cc->num_rows > 0) {
        $cc_id = $result_cc->fetch_assoc()['id'];
        echo "<p class='info'>Centro de custo '{$cc_name}' já existe.</p>";
    } else {
        $stmt_cc_insert->bind_param("s", $cc_name);
        if ($stmt_cc_insert->execute()) {
            $cc_id = $conn->insert_id;
            echo "<p class='success'>Centro de custo '{$cc_name}' adicionado.</p>";
        } else {
            echo "<p class='error'>Erro ao adicionar centro de custo '{$cc_name}': " . $stmt_cc_insert->error . "</p>";
            continue;
        }
    }

    if ($cc_id) {
        foreach ($subcategories as $sc_name) {
            $stmt_sc_check->bind_param("si", $sc_name, $cc_id);
            $stmt_sc_check->execute();
            $result_sc = $stmt_sc_check->get_result();

            if ($result_sc->num_rows > 0) {
                echo "<p class='info'>- Subcategoria '{$sc_name}' já existe em '{$cc_name}'.</p>";
            } else {
                $stmt_sc_insert->bind_param("si", $sc_name, $cc_id);
                if ($stmt_sc_insert->execute()) {
                    echo "<p class='success'>- Subcategoria '{$sc_name}' adicionada a '{$cc_name}'.</p>";
                } else {
                     echo "<p class='error'>- Erro ao adicionar subcategoria '{$sc_name}': " . $stmt_sc_insert->error . "</p>";
                }
            }
        }
    }
}

echo "<hr><p><b>Processo concluído!</b> A sua base de dados está pronta para a gestão dinâmica de categorias. Por segurança, pode agora apagar este ficheiro (`setup_categories.php`).</p>";
$conn->close();
echo "</div></body></html>";
?>
