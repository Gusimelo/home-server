<?php
// Ativar o report de erros para debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// --- CONFIGURAÇÕES ---
define('UPLOAD_DIR', 'uploads/');

header('Content-Type: application/json; charset=utf-8');

// --- FUNÇÕES DE RESPOSTA ---
function send_response($success, $data = null, $error = null) {
    echo json_encode(['success' => $success, 'data' => $data, 'error' => $error], JSON_NUMERIC_CHECK);
    exit();
}

// --- CONFIGURAÇÃO DA BASE DE DADOS ---
$servername = "localhost";
$username = "hass";
$password = "fcfheetl";
$dbname = "expenses";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    send_response(false, null, "Falha na ligação à base de dados: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

// --- LÓGICA DE UPLOAD DE FICHEIRO ---
function handle_file_upload($file_input_name) {
    if (isset($_FILES[$file_input_name]) && $_FILES[$file_input_name]['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES[$file_input_name];
        if ($file['type'] !== 'application/pdf') return ['error' => 'Apenas ficheiros PDF são permitidos.'];
        
        $filename = uniqid() . '-' . basename(preg_replace("/[^a-zA-Z0-9.\-_]/", "", $file['name']));
        $target_path = UPLOAD_DIR . $filename;

        if (move_uploaded_file($file['tmp_name'], $target_path)) return ['filename' => $filename];
        else return ['error' => 'Falha ao mover o ficheiro.'];
    }
    return ['filename' => null];
}

// --- ROUTING ---
$action = $_POST['action'] ?? null;
$json_data = [];
if (!$action) {
    $json_data = json_decode(file_get_contents('php://input'), true);
    $action = $json_data['action'] ?? $_GET['action'] ?? null;
}

switch ($action) {
    case 'get': handle_get($conn); break;
    case 'add': handle_add($conn, $_POST); break;
    case 'update': handle_update($conn, $_POST); break;
    case 'delete': handle_delete($conn, $json_data); break;
    case 'delete_attachment': handle_delete_attachment($conn, $json_data); break;
    case 'add_settlement': handle_add_settlement($conn, $json_data); break;
    case 'update_status': handle_update_status($conn, $json_data); break;
    case 'duplicate': handle_duplicate($conn, $json_data); break;
    default: send_response(false, null, 'Ação desconhecida.');
}

// --- FUNÇÕES DE GESTÃO ---

function handle_get($conn) {
    $transactions = [];
    $result = $conn->query("SELECT * FROM transactions ORDER BY transaction_date DESC, id DESC");
    if (!$result) send_response(false, null, "Erro ao obter movimentos: " . $conn->error);

    while ($row = $result->fetch_assoc()) {
        if ($row['type'] === 'Expense') {
            $parcels_stmt = $conn->prepare("SELECT person, amount FROM payment_parcels WHERE transaction_id = ?");
            $parcels_stmt->bind_param("i", $row['id']);
            $parcels_stmt->execute();
            $parcels_result = $parcels_stmt->get_result();
            $row['parcels'] = $parcels_result->fetch_all(MYSQLI_ASSOC);
            $parcels_stmt->close();
        }
        $transactions[] = $row;
    }
    send_response(true, $transactions);
}

function handle_add($conn, $data) {
    $parcels = isset($data['parcels']) ? json_decode($data['parcels'], true) : [];
    $type = $data['type'] ?? null;

    if ($type === 'Expense') {
        if (empty($parcels)) send_response(false, null, 'Uma despesa deve ter pelo menos um pagador.');
        $parcel_total = array_sum(array_column($parcels, 'amount'));
        if (abs($parcel_total - (float)$data['amount']) > 0.01) {
            send_response(false, null, 'A soma das parcelas (' . $parcel_total . ') não corresponde ao total do movimento (' . $data['amount'] . ').');
        }
    }

    $upload_result = handle_file_upload('attachment');
    if (isset($upload_result['error'])) send_response(false, null, $upload_result['error']);
    
    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare("INSERT INTO transactions (description, cost_center, amount, type, person, transaction_date, status, attachment) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $person = $type === 'Income' ? $data['person'] : null;
        $cost_center = $type === 'Expense' ? ($data['cost_center'] ?? null) : null;
        $stmt->bind_param("ssdsssss", $data['description'], $cost_center, $data['amount'], $type, $person, $data['date'], $data['status'], $upload_result['filename']);
        if(!$stmt->execute()) throw new Exception($stmt->error);
        $transaction_id = $conn->insert_id;
        $stmt->close();

        if ($type === 'Expense') {
            $parcel_stmt = $conn->prepare("INSERT INTO payment_parcels (transaction_id, person, amount) VALUES (?, ?, ?)");
            foreach ($parcels as $parcel) {
                $parcel_stmt->bind_param("isd", $transaction_id, $parcel['person'], $parcel['amount']);
                if(!$parcel_stmt->execute()) throw new Exception($parcel_stmt->error);
            }
            $parcel_stmt->close();
        }
        
        $conn->commit();
        send_response(true, ['id' => $transaction_id]);
    } catch (Exception $e) {
        $conn->rollback();
        send_response(false, null, "Erro ao guardar movimento: " . $e->getMessage());
    }
}

function handle_update($conn, $data) {
    $transaction_id = $data['id'] ?? null;
    $parcels = isset($data['parcels']) ? json_decode($data['parcels'], true) : [];
    $type = $data['type'] ?? null;

    if (!$transaction_id) send_response(false, null, 'ID em falta.');

    if ($type === 'Expense') {
        if (empty($parcels)) send_response(false, null, 'Uma despesa deve ter pelo menos um pagador.');
        $parcel_total = array_sum(array_column($parcels, 'amount'));
        if (abs($parcel_total - (float)$data['amount']) > 0.01) {
            send_response(false, null, 'A soma das parcelas não corresponde ao total do movimento.');
        }
    }
    
    $conn->begin_transaction();
    try {
        $upload_result = handle_file_upload('attachment');
        if (isset($upload_result['error'])) throw new Exception($upload_result['error']);
        
        $person = $type === 'Income' ? $data['person'] : null;
        $cost_center = $type === 'Expense' ? ($data['cost_center'] ?? null) : null;

        if ($upload_result['filename']) {
            $stmt_get = $conn->prepare("SELECT attachment FROM transactions WHERE id = ?");
            $stmt_get->bind_param("i", $transaction_id); $stmt_get->execute();
            $old_attachment = $stmt_get->get_result()->fetch_object()->attachment ?? null;
            if ($old_attachment && file_exists(UPLOAD_DIR . $old_attachment)) unlink(UPLOAD_DIR . $old_attachment);
            
            $stmt = $conn->prepare("UPDATE transactions SET description=?, cost_center=?, amount=?, type=?, person=?, transaction_date=?, status=?, attachment=? WHERE id=?");
            $stmt->bind_param("ssdsssssi", $data['description'], $cost_center, $data['amount'], $type, $person, $data['date'], $data['status'], $upload_result['filename'], $transaction_id);
        } else {
            $stmt = $conn->prepare("UPDATE transactions SET description=?, cost_center=?, amount=?, type=?, person=?, transaction_date=?, status=? WHERE id=?");
            $stmt->bind_param("ssdssssi", $data['description'], $cost_center, $data['amount'], $type, $person, $data['date'], $data['status'], $transaction_id);
        }
        if(!$stmt->execute()) throw new Exception($stmt->error);
        $stmt->close();
        
        $conn->query("DELETE FROM payment_parcels WHERE transaction_id = $transaction_id");
        if ($type === 'Expense') {
            $parcel_stmt = $conn->prepare("INSERT INTO payment_parcels (transaction_id, person, amount) VALUES (?, ?, ?)");
            foreach ($parcels as $parcel) {
                $parcel_stmt->bind_param("isd", $transaction_id, $parcel['person'], $parcel['amount']);
                if(!$parcel_stmt->execute()) throw new Exception($parcel_stmt->error);
            }
            $parcel_stmt->close();
        }
        
        $conn->commit();
        send_response(true);
    } catch (Exception $e) {
        $conn->rollback();
        send_response(false, null, "Erro ao atualizar: " . $e->getMessage());
    }
}

function handle_delete($conn, $data) {
    $id = $data['id'] ?? null;
    if (!$id) send_response(false, null, 'ID em falta.');

    $stmt_get = $conn->prepare("SELECT attachment FROM transactions WHERE id = ?");
    $stmt_get->bind_param("i", $id); $stmt_get->execute();
    $attachment = $stmt_get->get_result()->fetch_object()->attachment ?? null;
    if ($attachment && file_exists(UPLOAD_DIR . $attachment)) unlink(UPLOAD_DIR . $attachment);
    
    $stmt = $conn->prepare("DELETE FROM transactions WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) send_response(true);
    else send_response(false, null, "Erro ao apagar.");
}

function handle_delete_attachment($conn, $data) {
    $id = $data['id'] ?? null;
    if (!$id) send_response(false, null, 'ID em falta.');
    
    $stmt_get = $conn->prepare("SELECT attachment FROM transactions WHERE id = ?");
    $stmt_get->bind_param("i", $id); $stmt_get->execute();
    $attachment = $stmt_get->get_result()->fetch_object()->attachment ?? null;
    if ($attachment && file_exists(UPLOAD_DIR . $attachment)) unlink(UPLOAD_DIR . $attachment);

    $stmt = $conn->prepare("UPDATE transactions SET attachment = NULL WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) send_response(true);
    else send_response(false, null, "Erro ao remover anexo.");
}

function handle_add_settlement($conn, $data) {
    $amount = $data['amount'] ?? null;
    $person_from = $data['person_from'] ?? null;
    $person_to = $data['person_to'] ?? null;
    $date = $data['date'] ?? null;

    if (!$amount || !$person_from || !$person_to || !$date) send_response(false, null, 'Dados de acerto em falta.');
    
    $type = 'Acerto'; $status = 'paid'; $description = 'Acerto de Contas'; $cost_center = 'Acerto';

    $stmt = $conn->prepare("INSERT INTO transactions (description, cost_center, amount, type, person, person_to, transaction_date, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssdsssss", $description, $cost_center, $amount, $type, $person_from, $person_to, $date, $status);
    if ($stmt->execute()) send_response(true, ['id' => $conn->insert_id]);
    else send_response(false, null, "Erro ao guardar acerto.");
}

function handle_update_status($conn, $data) {
    $id = $data['id'] ?? null;
    $status = $data['status'] ?? null;
    if (!$id || !$status) send_response(false, null, 'Dados em falta para atualizar estado.');
    $stmt = $conn->prepare("UPDATE transactions SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $status, $id);
    if ($stmt->execute()) send_response(true);
    else send_response(false, null, "Erro ao atualizar estado.");
}

function handle_duplicate($conn, $data) {
    $id = $data['id'] ?? null;
    if (!$id) send_response(false, null, 'ID em falta para duplicar.');

    $conn->begin_transaction();
    try {
        $stmt_get = $conn->prepare("SELECT * FROM transactions WHERE id = ?");
        $stmt_get->bind_param("i", $id); $stmt_get->execute();
        $original = $stmt_get->get_result()->fetch_assoc();
        $stmt_get->close();

        if (!$original) throw new Exception('Movimento original não encontrado.');

        $new_status = $original['type'] === 'Income' ? 'paid' : 'pending';
        $today_date = date('Y-m-d');
        
        $stmt_insert = $conn->prepare("INSERT INTO transactions (description, cost_center, amount, type, person, transaction_date, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt_insert->bind_param("ssdssss", $original['description'], $original['cost_center'], $original['amount'], $original['type'], $original['person'], $today_date, $new_status);
        if(!$stmt_insert->execute()) throw new Exception($stmt_insert->error);
        $new_transaction_id = $conn->insert_id;
        $stmt_insert->close();
        
        if ($original['type'] === 'Expense') {
            $parcels_stmt = $conn->prepare("SELECT person, amount FROM payment_parcels WHERE transaction_id = ?");
            $parcels_stmt->bind_param("i", $id);
            $parcels_stmt->execute();
            $parcels_result = $parcels_stmt->get_result();
            
            $new_parcel_stmt = $conn->prepare("INSERT INTO payment_parcels (transaction_id, person, amount) VALUES (?, ?, ?)");
            while ($parcel = $parcels_result->fetch_assoc()) {
                $new_parcel_stmt->bind_param("isd", $new_transaction_id, $parcel['person'], $parcel['amount']);
                if(!$new_parcel_stmt->execute()) throw new Exception($new_parcel_stmt->error);
            }
            $new_parcel_stmt->close();
            $parcels_stmt->close();
        }

        $conn->commit();
        send_response(true, ['id' => $new_transaction_id]);
    } catch (Exception $e) {
        $conn->rollback();
        send_response(false, null, "Erro ao duplicar: " . $e->getMessage());
    }
}

$conn->close();
?>