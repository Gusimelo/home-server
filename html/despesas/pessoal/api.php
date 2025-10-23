<?php
require_once 'config.php';

// --- CONFIGURAÇÃO BÁSICA E GESTÃO DE ERROS ---
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

// --- CONFIGURAÇÃO ADICIONAL ---
$upload_dir = 'uploads/';

// --- FUNÇÃO DE RESPOSTA ---
function send_response($success, $data = null, $error = null) {
    echo json_encode(['success' => $success, 'data' => $data, 'error' => $error]);
    exit();
}

// --- LIGAÇÃO À BASE DE DADOS ---
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    send_response(false, null, "Falha na ligação: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

// --- ROUTING DAS AÇÕES ---
$action = $_POST['action'] ?? ($_GET['action'] ?? null);
if (!$action && $_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST)) {
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? null;
} else {
    $data = $_GET; // Usar GET para a filtragem
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = $_POST;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($data['action'])) {
    switch ($data['action']) {
        case 'get': handle_get($conn); break;
        case 'get_filtered': handle_get_filtered($conn, $data); break;
        default: send_response(false, null, 'Ação GET desconhecida.');
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['action'] ?? null;
    $json_data = json_decode(file_get_contents('php://input'), true);
    $json_action = $json_data['action'] ?? null;
    
    $action = $post_action ?: $json_action;
    $request_data = $post_action ? $_POST : $json_data;

    switch ($action) {
        case 'add': handle_add_or_update($conn, $_POST, $_FILES); break;
        case 'update': handle_add_or_update($conn, $_POST, $_FILES, true); break;
        case 'delete': handle_delete($conn, $request_data); break;
        case 'update_status': handle_update_status($conn, $request_data); break;
        case 'update_multiple_status': handle_update_multiple_status($conn, $request_data); break;
        case 'combine_transactions': handle_combine_transactions($conn, $request_data); break;
        
        case 'add_loan': handle_add_loan($conn, $_POST); break;
        case 'update_loan': handle_update_loan($conn, $_POST); break;
        case 'delete_loan': handle_delete_loan($conn, $request_data); break;

        case 'add_cost_center': handle_add_cost_center($conn, $request_data); break;
        case 'update_cost_center': handle_update_cost_center($conn, $request_data); break;
        case 'delete_cost_center': handle_delete_cost_center($conn, $request_data); break;
        
        case 'add_subcategory': handle_add_subcategory($conn, $request_data); break;
        case 'update_subcategory': handle_update_subcategory($conn, $request_data); break;
        case 'delete_subcategory': handle_delete_subcategory($conn, $request_data); break;
        case 'move_subcategory': handle_move_subcategory($conn, $request_data); break;

        case 'move_transactions': handle_move_transactions($conn, $_POST); break;
        case 'move_transactions_by_description': handle_move_transactions_by_description($conn, $_POST); break;

        default: send_response(false, null, 'Ação POST desconhecida.');
    }
} else {
    send_response(false, null, 'Ação ou método inválido.');
}


// --- FUNÇÕES DE GESTÃO DE FICHEIROS ---
function handle_multiple_uploads($files, $upload_dir) {
    $uploaded_paths = [];
    if (isset($files['attachments']) && is_array($files['attachments']['name'])) {
        $file_count = count($files['attachments']['name']);
        for ($i = 0; $i < $file_count; $i++) {
            if ($files['attachments']['error'][$i] === UPLOAD_ERR_OK) {
                $file_extension = strtolower(pathinfo($files['attachments']['name'][$i], PATHINFO_EXTENSION));
                if ($file_extension !== 'pdf') {
                    foreach($uploaded_paths as $path) unlink($path);
                    return ['success' => false, 'error' => 'Apenas ficheiros PDF são permitidos.'];
                }
                $filename = uniqid('', true) . '.' . $file_extension;
                $destination = $upload_dir . $filename;
                if (move_uploaded_file($files['attachments']['tmp_name'][$i], $destination)) {
                    $uploaded_paths[] = $destination;
                } else {
                    foreach($uploaded_paths as $path) unlink($path);
                    return ['success' => false, 'error' => 'Falha ao mover um dos ficheiros.'];
                }
            }
        }
    }
    return ['success' => true, 'paths' => $uploaded_paths];
}


function delete_file($filepath) {
    if ($filepath && file_exists($filepath)) {
        unlink($filepath);
    }
}

// --- FUNÇÕES DE GESTÃO DE DADOS ---

function build_category_tree(array &$elements, $parentId = null) {
    $branch = array();
    foreach ($elements as $element) {
        if ($element['parent_id'] == $parentId) {
            $children = build_category_tree($elements, $element['id']);
            if ($children) {
                $element['children'] = $children;
            }
            $branch[$element['id']] = $element;
            unset($elements[$element['id']]);
        }
    }
    return $branch;
}

function handle_get($conn) {
    $sql = "
        SELECT 
            t.*,
            (SELECT JSON_ARRAYAGG(JSON_OBJECT('id', ti.id, 'description', ti.description, 'amount', ti.amount, 'person', ti.person, 'cost_center', ti.cost_center, 'subcategory', ti.subcategory, 'sub_subcategory', ti.sub_subcategory)) FROM transaction_items ti WHERE ti.transaction_id = t.id) as items,
            (SELECT JSON_ARRAYAGG(JSON_OBJECT('id', a.id, 'file_path', a.file_path)) FROM attachments a WHERE a.transaction_id = t.id) as attachments
        FROM transactions t
        ORDER BY t.transaction_date DESC, t.id DESC
    ";
    
    $result = $conn->query($sql);
    if (!$result) send_response(false, null, "Erro ao obter movimentos: " . $conn->error);
    
    $transactions = [];
    while($row = $result->fetch_assoc()) {
        $row['items'] = $row['items'] ? json_decode($row['items']) : [];
        $row['attachments'] = $row['attachments'] ? json_decode($row['attachments']) : [];
        $transactions[] = $row;
    }

    $loans_result = $conn->query("SELECT l.*, IFNULL(SUM(t.amount), 0) as amount_paid FROM loans l LEFT JOIN transactions t ON l.id = t.loan_id AND t.status = 'paid' GROUP BY l.id ORDER BY l.name ASC");
    if (!$loans_result) send_response(false, null, "Erro ao obter empréstimos: " . $conn->error);
    $loans = $loans_result->fetch_all(MYSQLI_ASSOC);

    $cost_centers = [];
    $cc_result = $conn->query("SELECT * FROM cost_centers ORDER BY name ASC");
    if (!$cc_result) send_response(false, null, "Erro ao obter centros de custo: " . $conn->error);
    while($cc_row = $cc_result->fetch_assoc()) {
        $cost_centers[$cc_row['id']] = [ 'id' => $cc_row['id'], 'name' => $cc_row['name'], 'subcategories' => [] ];
    }
    
    $subcategories = [];
    $sc_result = $conn->query("SELECT * FROM subcategories ORDER BY name ASC");
    if (!$sc_result) send_response(false, null, "Erro ao obter subcategorias: " . $conn->error);
    while($sc_row = $sc_result->fetch_assoc()) {
        $subcategories[$sc_row['id']] = $sc_row;
    }
    
    $category_tree = build_category_tree($subcategories);
    
    foreach($category_tree as $id => $sub) {
        if(isset($cost_centers[$sub['cost_center_id']])) {
            $cost_centers[$sub['cost_center_id']]['subcategories'][$id] = $sub;
        }
    }

    send_response(true, [ 'transactions' => $transactions, 'loans' => $loans, 'cost_centers' => $cost_centers ]);
}

function handle_get_filtered($conn, $data) {
    $view_mode = $data['view_mode'] ?? 'detailed';

    $base_sql_from = "FROM transactions t LEFT JOIN transaction_items ti ON t.id = ti.transaction_id";
    $where_clauses = ["1=1"];
    $params = [];
    $types = "";

    if (!empty($data['type']) && $data['type'] !== 'all') {
        $where_clauses[] = "t.type = ?";
        $params[] = $data['type'];
        $types .= "s";
    }
    if (!empty($data['person']) && $data['person'] !== 'all') {
        $where_clauses[] = "t.person = ?";
        $params[] = $data['person'];
        $types .= "s";
    }

    // Category filters only apply if at least one item matches
    if (!empty($data['cost_center']) || !empty($data['subcategory']) || !empty($data['sub_subcategory'])) {
        $item_filters = [];
        if (!empty($data['cost_center'])) {
            $item_filters[] = "ti.cost_center = ?";
            $params[] = $data['cost_center'];
            $types .= "s";
        }
        if (!empty($data['subcategory'])) {
            $item_filters[] = "ti.subcategory = ?";
            $params[] = $data['subcategory'];
            $types .= "s";
        }
        if (!empty($data['sub_subcategory'])) {
            $item_filters[] = "ti.sub_subcategory = ?";
            $params[] = $data['sub_subcategory'];
            $types .= "s";
        }
         // For grouped view, we need to check existence. For detailed, it's a direct filter.
        if ($view_mode === 'grouped') {
            $where_clauses[] = "EXISTS (SELECT 1 FROM transaction_items ti_filter WHERE ti_filter.transaction_id = t.id AND " . implode(" AND ", $item_filters) . ")";
        } else {
            $where_clauses = array_merge($where_clauses, $item_filters);
        }
    }


    if (!empty($data['start_date']) && !empty($data['end_date'])) {
        $where_clauses[] = "t.transaction_date BETWEEN ? AND ?";
        $params[] = $data['start_date'];
        $params[] = $data['end_date'];
        $types .= "ss";
    } elseif (!empty($data['year'])) {
        $where_clauses[] = "YEAR(t.transaction_date) = ?";
        $params[] = $data['year'];
        $types .= "i";
        if (!empty($data['month'])) {
            $where_clauses[] = "MONTH(t.transaction_date) = ?";
            $params[] = $data['month'];
            $types .= "i";
        }
    }
    
    $where_sql = "WHERE " . implode(" AND ", $where_clauses);

    if ($view_mode === 'grouped') {
        $sql = "
            SELECT DISTINCT
                t.*,
                (SELECT JSON_ARRAYAGG(JSON_OBJECT('id', ti.id, 'description', ti.description, 'amount', ti.amount, 'cost_center', ti.cost_center, 'subcategory', ti.subcategory, 'sub_subcategory', ti.sub_subcategory)) FROM transaction_items ti WHERE ti.transaction_id = t.id) as items,
                (SELECT JSON_ARRAYAGG(JSON_OBJECT('id', a.id, 'file_path', a.file_path)) FROM attachments a WHERE a.transaction_id = t.id) as attachments
            $base_sql_from $where_sql
            ORDER BY t.transaction_date DESC, t.id DESC
        ";
    } else { // 'detailed' view
        $sql = "SELECT t.*, ti.id as item_id, ti.description as item_description, ti.amount as item_amount, ti.cost_center, ti.subcategory, ti.sub_subcategory 
                $base_sql_from $where_sql 
                ORDER BY t.transaction_date DESC, t.id DESC";
    }
    
    $stmt = $conn->prepare($sql);
    if ($stmt === false) { send_response(false, null, "Erro ao preparar a query: " . $conn->error . " | SQL: " . $sql); }

    if (!empty($params)) {
        $refs = [];
        foreach ($params as $key => $value) {
            $refs[$key] = &$params[$key];
        }
        call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $refs));
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result === false) { send_response(false, null, "Erro ao executar a query: " . $stmt->error); }

    $transactions = [];
    if ($view_mode === 'grouped') {
        while($row = $result->fetch_assoc()) {
            $row['items'] = $row['items'] ? json_decode($row['items']) : [];
            $row['attachments'] = $row['attachments'] ? json_decode($row['attachments']) : [];
            $transactions[] = $row;
        }
    } else {
        $transactions = $result->fetch_all(MYSQLI_ASSOC);
    }
    
    send_response(true, $transactions);
}


function handle_add_or_update($conn, $data, $files, $is_update = false) {
    global $upload_dir;
    
    $id = $data['id'] ?? null;
    $description = $data['description'] ?? null;
    $type = $data['type'] ?? null;
    $person = $data['person'] ?? null;
    $date = $data['date'] ?? null;
    $end_date = !empty($data['end_date']) ? $data['end_date'] : null;
    $status = $data['status'] ?? 'paid';
    $items_json = $data['items_json'] ?? '[]';
    $items = json_decode($items_json, true);
    $remove_attachments = $data['remove_attachments'] ?? [];
    $is_direct_debit = isset($data['is_direct_debit']) ? 1 : 0;

    if ($is_update && !$id) send_response(false, null, 'ID em falta para atualizar.');
    if (!$description || !$type || !$person || !$date) send_response(false, null, 'Dados principais em falta.');
    if ($type === 'Expense' && empty($items)) send_response(false, null, 'Uma despesa deve ter pelo menos uma parcela.');
    
    $total_amount = 0;
    if($type === 'Expense') {
        foreach($items as $item) {
            $total_amount += (float)$item['amount'];
        }
    } else {
         $total_amount = (float)$data['amount'];
    }

    $conn->begin_transaction();
    try {
        // --- INÍCIO DA CORREÇÃO ---
        if (!$is_update && $type === 'Expense' && $end_date) {
            $start = new DateTime($date);
            $end = new DateTime($end_date);
            if ($start > $end) throw new Exception("A data de fim deve ser posterior à data de início.");

            $current_date = $start;
            while ($current_date <= $end) {
                $date_str = $current_date->format('Y-m-d');
                $stmt_main = $conn->prepare("INSERT INTO transactions (description, amount, type, person, transaction_date, status, is_direct_debit) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt_main->bind_param("sdssssi", $description, $total_amount, $type, $person, $date_str, $status, $is_direct_debit);
                if (!$stmt_main->execute()) throw new Exception("Erro ao inserir transação recorrente para " . $date_str . ": " . $stmt_main->error);
                $transaction_id = $conn->insert_id;
                $stmt_main->close();

                $stmt_item = $conn->prepare("INSERT INTO transaction_items (transaction_id, description, amount, person, cost_center, subcategory, sub_subcategory) VALUES (?, ?, ?, ?, ?, ?, ?)");
                foreach($items as $item) {
                    $item_desc = $item['description'] ?: $description;
                    $sub_sub = $item['sub_subcategory'] ?: null;
                    $item_person = !empty($item['person']) ? $item['person'] : null;
                    $stmt_item->bind_param("isdssss", $transaction_id, $item_desc, $item['amount'], $item_person, $item['cost_center'], $item['subcategory'], $sub_sub);
                    if (!$stmt_item->execute()) throw new Exception("Erro ao inserir parcela recorrente para " . $date_str . ": " . $stmt_item->error);
                }
                $stmt_item->close();
                
                // Anexos só são adicionados à primeira transação da série
                if ($current_date == $start) {
                    $upload_result = handle_multiple_uploads($files, $upload_dir);
                    if (!$upload_result['success']) {
                        throw new Exception($upload_result['error']);
                    }
                    if (!empty($upload_result['paths'])) {
                        $stmt_att = $conn->prepare("INSERT INTO attachments (transaction_id, file_path) VALUES (?, ?)");
                        foreach($upload_result['paths'] as $path) {
                            $stmt_att->bind_param("is", $transaction_id, $path);
                            if (!$stmt_att->execute()) throw new Exception("Erro ao guardar anexo na transação recorrente: " . $stmt_att->error);
                        }
                        $stmt_att->close();
                    }
                }

                $current_date->modify('first day of next month');
            }
        } else { // --- FIM DA CORREÇÃO ---
            if ($is_update) {
                $stmt = $conn->prepare("UPDATE transactions SET description=?, amount=?, type=?, person=?, transaction_date=?, status=?, is_direct_debit=? WHERE id=?");
                $stmt->bind_param("sdssssii", $description, $total_amount, $type, $person, $date, $status, $is_direct_debit, $id);
                if (!$stmt->execute()) throw new Exception("Erro ao atualizar transação principal: " . $stmt->error);
                $transaction_id = $id;

                if(!empty($remove_attachments)) {
                    $in_clause = implode(',', array_fill(0, count($remove_attachments), '?'));
                    $types_rem = str_repeat('i', count($remove_attachments));
                    $stmt_get_paths = $conn->prepare("SELECT file_path FROM attachments WHERE id IN ($in_clause)");
                    $stmt_get_paths->bind_param($types_rem, ...$remove_attachments);
                    $stmt_get_paths->execute();
                    $paths_to_delete = $stmt_get_paths->get_result();
                    while($row = $paths_to_delete->fetch_assoc()) {
                        delete_file($row['file_path']);
                    }
                    $stmt_get_paths->close();
                    $stmt_delete_att = $conn->prepare("DELETE FROM attachments WHERE id IN ($in_clause)");
                    $stmt_delete_att->bind_param($types_rem, ...$remove_attachments);
                    $stmt_delete_att->execute();
                    $stmt_delete_att->close();
                }
                
                $conn->query("DELETE FROM transaction_items WHERE transaction_id = $transaction_id");

            } else {
                $stmt = $conn->prepare("INSERT INTO transactions (description, amount, type, person, transaction_date, status, is_direct_debit) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sdssssi", $description, $total_amount, $type, $person, $date, $status, $is_direct_debit);
                if (!$stmt->execute()) throw new Exception("Erro ao inserir transação principal: " . $stmt->error);
                $transaction_id = $conn->insert_id;
            }
            
            if ($type === 'Expense') {
                $stmt_item = $conn->prepare("INSERT INTO transaction_items (transaction_id, description, amount, person, cost_center, subcategory, sub_subcategory) VALUES (?, ?, ?, ?, ?, ?, ?)");
                foreach($items as $item) {
                    $item_desc = $item['description'] ?: $description;
                    $sub_sub = $item['sub_subcategory'] ?: null;
                    $item_person = !empty($item['person']) ? $item['person'] : null;
                    $stmt_item->bind_param("isdssss", $transaction_id, $item_desc, $item['amount'], $item_person, $item['cost_center'], $item['subcategory'], $sub_sub);
                    if (!$stmt_item->execute()) throw new Exception("Erro ao inserir parcela: " . $stmt_item->error);
                }
            }
            
            $upload_result = handle_multiple_uploads($files, $upload_dir);
            if (!$upload_result['success']) {
                throw new Exception($upload_result['error']);
            }
            if (!empty($upload_result['paths'])) {
                $stmt_att = $conn->prepare("INSERT INTO attachments (transaction_id, file_path) VALUES (?, ?)");
                foreach($upload_result['paths'] as $path) {
                    $stmt_att->bind_param("is", $transaction_id, $path);
                    if (!$stmt_att->execute()) throw new Exception("Erro ao guardar anexo: " . $stmt_att->error);
                }
            }
        }

        $conn->commit();
        send_response(true);

    } catch (Exception $e) {
        $conn->rollback();
        send_response(false, null, $e->getMessage());
    }
}


function handle_delete($conn, $data) {
    $id = $data['id'] ?? null;
    if (!$id) send_response(false, null, 'ID em falta.');
    
    $conn->begin_transaction();
    try {
        $stmt_files = $conn->prepare("SELECT file_path FROM attachments WHERE transaction_id = ?");
        $stmt_files->bind_param("i", $id);
        $stmt_files->execute();
        $result = $stmt_files->get_result();
        while($row = $result->fetch_assoc()) {
            delete_file($row['file_path']);
        }
        $stmt_files->close();

        $stmt_delete = $conn->prepare("DELETE FROM transactions WHERE id = ?");
        $stmt_delete->bind_param("i", $id);
        if (!$stmt_delete->execute()) throw new Exception("Erro ao apagar transação: " . $stmt_delete->error);

        $conn->commit();
        send_response(true);

    } catch (Exception $e) {
        $conn->rollback();
        send_response(false, null, $e->getMessage());
    }
}

function handle_update_status($conn, $data) {
    $id = $data['id'] ?? null;
    $status = $data['status'] ?? null;
    if (!$id || !$status) send_response(false, null, 'Dados em falta.');
    $stmt = $conn->prepare("UPDATE transactions SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $status, $id);
    if ($stmt->execute()) send_response(true);
    else send_response(false, null, "Erro ao atualizar: " . $stmt->error);
}

function handle_update_multiple_status($conn, $data) {
    $ids = $data['ids'] ?? null;
    $status = $data['status'] ?? null;

    if (!is_array($ids) || empty($ids) || !$status) {
        send_response(false, null, 'IDs em falta ou estado inválido.');
    }

    // Sanitize IDs to ensure they are all integers
    $sanitized_ids = array_map('intval', $ids);
    $placeholders = implode(',', array_fill(0, count($sanitized_ids), '?'));
    $types = str_repeat('i', count($sanitized_ids));

    $sql = "UPDATE transactions SET status = ? WHERE id IN ($placeholders)";
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        send_response(false, null, "Erro ao preparar a query: " . $conn->error);
    }

    $params = array_merge([$status], $sanitized_ids);
    $stmt->bind_param("s" . $types, ...$params);

    if ($stmt->execute()) {
        send_response(true, ['affected_rows' => $stmt->affected_rows]);
    } else {
        send_response(false, null, "Erro ao atualizar múltiplos estados: " . $stmt->error);
    }
}

function handle_combine_transactions($conn, $data) {
    $source_id = $data['source_id'] ?? null;
    $destination_id = $data['destination_id'] ?? null;

    if (!$source_id || !$destination_id) send_response(false, null, 'IDs de origem e destino em falta.');
    if ($source_id == $destination_id) send_response(false, null, 'Não pode mover um movimento para dentro de si mesmo.');

    $conn->begin_transaction();
    try {
        // Obter valor do movimento de origem
        $stmt_source = $conn->prepare("SELECT amount FROM transactions WHERE id = ?");
        $stmt_source->bind_param("i", $source_id);
        $stmt_source->execute();
        $result_source = $stmt_source->get_result();
        if ($result_source->num_rows === 0) throw new Exception("Movimento de origem não encontrado.");
        $source_amount = $result_source->fetch_assoc()['amount'];
        $stmt_source->close();

        // Mover todas as parcelas do movimento de origem para o de destino
        $stmt_move_items = $conn->prepare("UPDATE transaction_items SET transaction_id = ? WHERE transaction_id = ?");
        $stmt_move_items->bind_param("ii", $destination_id, $source_id);
        if (!$stmt_move_items->execute()) throw new Exception("Erro ao mover parcelas: " . $stmt_move_items->error);
        $stmt_move_items->close();

        // Atualizar o valor total do movimento de destino
        $stmt_update_dest = $conn->prepare("UPDATE transactions SET amount = amount + ? WHERE id = ?");
        $stmt_update_dest->bind_param("di", $source_amount, $destination_id);
        if (!$stmt_update_dest->execute()) throw new Exception("Erro ao atualizar o total de destino: " . $stmt_update_dest->error);
        $stmt_update_dest->close();

        // Mover anexos
        $stmt_move_attachments = $conn->prepare("UPDATE attachments SET transaction_id = ? WHERE transaction_id = ?");
        $stmt_move_attachments->bind_param("ii", $destination_id, $source_id);
        $stmt_move_attachments->execute();
        $stmt_move_attachments->close();

        // Apagar o movimento de origem
        $stmt_delete_source = $conn->prepare("DELETE FROM transactions WHERE id = ?");
        $stmt_delete_source->bind_param("i", $source_id);
        if (!$stmt_delete_source->execute()) throw new Exception("Erro ao apagar movimento de origem: " . $stmt_delete_source->error);
        $stmt_delete_source->close();

        $conn->commit();
        send_response(true);
    } catch (Exception $e) {
        $conn->rollback();
        send_response(false, null, $e->getMessage());
    }
}


// --- Funções de Empréstimos ---
function handle_add_loan($conn, $data) {
    $name = $data['name'] ?? null;
    $cost_center = $data['cost_center'] ?? null;
    $subcategory = $data['subcategory'] ?? null;
    $sub_subcategory = isset($data['sub_subcategory']) && $data['sub_subcategory'] !== '' ? $data['sub_subcategory'] : null;
    $monthly_payment = $data['monthly_payment'] ?? null;
    $start_date = $data['start_date'] ?? null;
    $end_date = $data['end_date'] ?? null;
    $person = $data['person'] ?? null;

    if (!$name || !$monthly_payment || !$start_date || !$end_date || !$person || !$cost_center || !$subcategory) {
        send_response(false, null, 'Dados do empréstimo em falta.');
    }
    
    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    $months = ($end->format('Y') - $start->format('Y')) * 12 + ($end->format('m') - $start->format('m')) + 1;
    $total_amount = $months * $monthly_payment;
    
    $conn->begin_transaction();
    try {
        $stmt_loan = $conn->prepare("INSERT INTO loans (name, total_amount, monthly_payment, start_date, end_date, person, cost_center, subcategory, sub_subcategory) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt_loan->bind_param("sddssssss", $name, $total_amount, $monthly_payment, $start_date, $end_date, $person, $cost_center, $subcategory, $sub_subcategory);
        if (!$stmt_loan->execute()) { throw new Exception("Erro ao guardar o empréstimo: " . $stmt_loan->error); }
        $loan_id = $conn->insert_id;

        $stmt_trans = $conn->prepare("INSERT INTO transactions (description, amount, type, person, transaction_date, status, loan_id) VALUES (?, ?, 'Expense', ?, ?, ?, ?)");
        $stmt_item = $conn->prepare("INSERT INTO transaction_items (transaction_id, description, amount, cost_center, subcategory, sub_subcategory) VALUES (?, ?, ?, ?, ?, ?)");
        
        $current_date = $start;
        $today = new DateTime();

        while ($current_date <= $end) {
            $date_str = $current_date->format('Y-m-d');
            $status = ($current_date < $today) ? 'paid' : 'pending';
            
            // CORREÇÃO APLICADA AQUI: O tipo de dados "i" (integer) para loan_id foi adicionado.
            $stmt_trans->bind_param("sdsssi", $name, $monthly_payment, $person, $date_str, $status, $loan_id);
            
            if (!$stmt_trans->execute()) { throw new Exception("Erro ao criar transação para prestação de " . $date_str . ": " . $stmt_trans->error); }
            $transaction_id = $conn->insert_id;

            $stmt_item->bind_param("isdsss", $transaction_id, $name, $monthly_payment, $cost_center, $subcategory, $sub_subcategory);
            if (!$stmt_item->execute()) { throw new Exception("Erro ao criar item para prestação de " . $date_str . ": " . $stmt_item->error); }

            $current_date->modify('+1 month');
        }
        $conn->commit();
        send_response(true);
    } catch (Exception $e) {
        $conn->rollback();
        send_response(false, null, $e->getMessage());
    }
}

function handle_update_loan($conn, $data) {
    $id = $data['id'] ?? null;
    if (!$id) { send_response(false, null, "ID do empréstimo em falta."); }

    $name = $data['name'] ?? null;
    $cost_center = $data['cost_center'] ?? null;
    $subcategory = $data['subcategory'] ?? null;
    $sub_subcategory = isset($data['sub_subcategory']) && $data['sub_subcategory'] !== '' ? $data['sub_subcategory'] : null;
    $monthly_payment = $data['monthly_payment'] ?? null;
    $start_date = $data['start_date'] ?? null;
    $end_date = $data['end_date'] ?? null;
    $person = $data['person'] ?? null;

    if (!$name || !$monthly_payment || !$start_date || !$end_date || !$person || !$cost_center || !$subcategory) {
        send_response(false, null, 'Dados do empréstimo em falta.');
    }

    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    $months = ($end->format('Y') - $start->format('Y')) * 12 + ($end->format('m') - $start->format('m')) + 1;
    $total_amount = $months * $monthly_payment;

    $conn->begin_transaction();
    try {
        $stmt_update_loan = $conn->prepare("UPDATE loans SET name=?, total_amount=?, monthly_payment=?, start_date=?, end_date=?, person=?, cost_center=?, subcategory=?, sub_subcategory=? WHERE id=?");
        $stmt_update_loan->bind_param("sddssssssi", $name, $total_amount, $monthly_payment, $start_date, $end_date, $person, $cost_center, $subcategory, $sub_subcategory, $id);
        if (!$stmt_update_loan->execute()) { throw new Exception("Erro ao atualizar o empréstimo: " . $stmt_update_loan->error); }
        
        $stmt_delete = $conn->prepare("DELETE FROM transactions WHERE loan_id = ? AND status = 'pending'");
        $stmt_delete->bind_param("i", $id);
        if (!$stmt_delete->execute()) { throw new Exception("Erro ao apagar prestações pendentes antigas: " . $stmt_delete->error); }

        $stmt_trans = $conn->prepare("INSERT INTO transactions (description, amount, type, person, transaction_date, status, loan_id) VALUES (?, ?, 'Expense', ?, ?, ?, ?)");
        $stmt_item = $conn->prepare("INSERT INTO transaction_items (transaction_id, description, amount, cost_center, subcategory, sub_subcategory) VALUES (?, ?, ?, ?, ?, ?)");
        
        $current_date = $start;
        $today = new DateTime();

        while ($current_date <= $end) {
             if ($current_date >= $today) {
                $date_str = $current_date->format('Y-m-d');
                $status = 'pending';
                
                // CORREÇÃO APLICADA AQUI: O tipo de dados "i" (integer) para o id do empréstimo foi adicionado.
                $stmt_trans->bind_param("sdsssi", $name, $monthly_payment, $person, $date_str, $status, $id);
                
                if (!$stmt_trans->execute()) {
                     if ($stmt_trans->errno != 1062) throw new Exception("Erro ao recriar transação para prestação de " . $date_str . ": " . $stmt_trans->error);
                }
                $transaction_id = $conn->insert_id;

                $stmt_item->bind_param("isdsss", $transaction_id, $name, $monthly_payment, $cost_center, $subcategory, $sub_subcategory);
                 if (!$stmt_item->execute()) {
                     if ($stmt_item->errno != 1062) throw new Exception("Erro ao recriar item para prestação de " . $date_str . ": " . $stmt_item->error);
                }
            }
            $current_date->modify('+1 month');
        }
        $conn->commit();
        send_response(true);
    } catch (Exception $e) {
        $conn->rollback();
        send_response(false, null, $e->getMessage());
    }
}

function handle_delete_loan($conn, $data) {
    $id = $data['id'] ?? null;
    if (!$id) { send_response(false, null, "ID do empréstimo em falta."); }
    
    $conn->begin_transaction();
    try {
        $stmt_delete_pending = $conn->prepare("DELETE FROM transactions WHERE loan_id = ? AND status = 'pending'");
        $stmt_delete_pending->bind_param("i", $id);
        if (!$stmt_delete_pending->execute()) { throw new Exception("Erro ao apagar prestações pendentes: " . $stmt_delete_pending->error); }
        
        $stmt_delete_loan = $conn->prepare("DELETE FROM loans WHERE id = ?");
        $stmt_delete_loan->bind_param("i", $id);
        if (!$stmt_delete_loan->execute()) { throw new Exception("Erro ao apagar o empréstimo: " . $stmt_delete_loan->error); }

        $conn->commit();
        send_response(true);
    } catch (Exception $e) {
        $conn->rollback();
        send_response(false, null, $e->getMessage());
    }
}

// --- Funções de Categorias ---
function handle_add_cost_center($conn, $data) {
    $name = $data['name'] ?? null;
    if (!$name) send_response(false, null, "Nome do centro de custo em falta.");
    $stmt = $conn->prepare("INSERT INTO cost_centers (name) VALUES (?)");
    $stmt->bind_param("s", $name);
    if ($stmt->execute()) send_response(true);
    else send_response(false, null, "Erro: " . $stmt->error);
}

function handle_update_cost_center($conn, $data) {
    $id = $data['id'] ?? null;
    $new_name = $data['name'] ?? null;
    if (!$id || !$new_name) send_response(false, null, "Dados em falta.");

    $conn->begin_transaction();
    try {
        $stmt_get = $conn->prepare("SELECT name FROM cost_centers WHERE id = ?");
        $stmt_get->bind_param("i", $id);
        $stmt_get->execute();
        $result = $stmt_get->get_result();
        if ($result->num_rows === 0) throw new Exception("Centro de custo não encontrado.");
        $old_name = $result->fetch_assoc()['name'];
        $stmt_get->close();
        
        if ($old_name === $new_name) {
            $conn->commit();
            send_response(true);
            return;
        }

        $stmt_items = $conn->prepare("UPDATE transaction_items SET cost_center = ? WHERE cost_center = ?");
        if(!$stmt_items) throw new Exception("Erro ao preparar a query de itens de transação: " . $conn->error);
        $stmt_items->bind_param("ss", $new_name, $old_name);
        if (!$stmt_items->execute()) throw new Exception("Erro ao atualizar itens de transações: " . $stmt_items->error);
        $stmt_items->close();

        $stmt_loans = $conn->prepare("UPDATE loans SET cost_center = ? WHERE cost_center = ?");
        if(!$stmt_loans) throw new Exception("Erro ao preparar a query de empréstimos: " . $conn->error);
        $stmt_loans->bind_param("ss", $new_name, $old_name);
        if (!$stmt_loans->execute()) throw new Exception("Erro ao atualizar empréstimos: " . $stmt_loans->error);
        $stmt_loans->close();

        $stmt_update = $conn->prepare("UPDATE cost_centers SET name = ? WHERE id = ?");
        if(!$stmt_update) throw new Exception("Erro ao preparar a query de centro de custo: " . $conn->error);
        $stmt_update->bind_param("si", $new_name, $id);
        if (!$stmt_update->execute()) throw new Exception("Erro ao renomear o centro de custo: " . $stmt_update->error);
        $stmt_update->close();

        $conn->commit();
        send_response(true);
    } catch (Exception $e) {
        $conn->rollback();
        send_response(false, null, $e->getMessage());
    }
}


function handle_delete_cost_center($conn, $data) {
    $id = $data['id'] ?? null;
    if (!$id) send_response(false, null, "ID em falta.");
    $stmt = $conn->prepare("DELETE FROM cost_centers WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) send_response(true);
    else send_response(false, null, "Erro: " . $stmt->error);
}

function handle_add_subcategory($conn, $data) {
    $name = $data['name'] ?? null;
    $cost_center_id = $data['cost_center_id'] ?? null;
    $parent_id = empty($data['parent_id']) ? null : (int)$data['parent_id'];
    
    if (!$name || !$cost_center_id) send_response(false, null, "Dados em falta.");

    $stmt = $conn->prepare("INSERT INTO subcategories (name, cost_center_id, parent_id) VALUES (?, ?, ?)");
    $stmt->bind_param("sii", $name, $cost_center_id, $parent_id);
    if ($stmt->execute()) send_response(true);
    else send_response(false, null, "Erro: " . $stmt->error);
}

function handle_update_subcategory($conn, $data) {
    $id = $data['id'] ?? null;
    $new_name = $data['name'] ?? null;
    if (!$id || !$new_name) send_response(false, null, "Dados em falta.");

    $conn->begin_transaction();
    try {
        $stmt_get = $conn->prepare("SELECT name, parent_id FROM subcategories WHERE id = ?");
        $stmt_get->bind_param("i", $id);
        $stmt_get->execute();
        $result = $stmt_get->get_result();
        if ($result->num_rows === 0) throw new Exception("Subcategoria não encontrada.");
        
        $category_info = $result->fetch_assoc();
        $old_name = $category_info['name'];
        $parent_id = $category_info['parent_id'];
        $stmt_get->close();

        if ($old_name === $new_name) {
            $conn->commit();
            send_response(true);
            return;
        }

        if ($parent_id === null) { // Nível 2
            $stmt_items = $conn->prepare("UPDATE transaction_items SET subcategory = ? WHERE subcategory = ?");
             if(!$stmt_items) throw new Exception("Erro ao preparar query para itens (nível 2): " . $conn->error);
            $stmt_items->bind_param("ss", $new_name, $old_name);
            if (!$stmt_items->execute()) throw new Exception("Erro ao atualizar itens: " . $stmt_items->error);
        } else { // Nível 3
            $stmt_items = $conn->prepare("UPDATE transaction_items SET sub_subcategory = ? WHERE sub_subcategory = ?");
            if(!$stmt_items) throw new Exception("Erro ao preparar query para itens (nível 3): " . $conn->error);
            $stmt_items->bind_param("ss", $new_name, $old_name);
            if (!$stmt_items->execute()) throw new Exception("Erro ao atualizar itens (sub-sub): " . $stmt_items->error);
        }

        $stmt_update = $conn->prepare("UPDATE subcategories SET name = ? WHERE id = ?");
        if(!$stmt_update) throw new Exception("Erro ao preparar query para subcategorias: " . $conn->error);
        $stmt_update->bind_param("si", $new_name, $id);
        if (!$stmt_update->execute()) throw new Exception("Erro ao renomear a subcategoria: " . $stmt_update->error);
        
        $conn->commit();
        send_response(true);

    } catch (Exception $e) {
        $conn->rollback();
        send_response(false, null, $e->getMessage());
    }
}


function handle_delete_subcategory($conn, $data) {
    $id = $data['id'] ?? null;
    if (!$id) send_response(false, null, "ID em falta.");

    $conn->begin_transaction();
    try {
        // Find the category being deleted and all its children
        $stmt_get = $conn->prepare("SELECT id, name, parent_id FROM subcategories WHERE id = ? OR parent_id = ?");
        $stmt_get->bind_param("ii", $id, $id);
        $stmt_get->execute();
        $result = $stmt_get->get_result();
        
        $cat_to_delete = null;
        $child_names = [];
        $all_ids_to_delete = [];

        while($row = $result->fetch_assoc()) {
            $all_ids_to_delete[] = $row['id'];
            if($row['id'] == $id) {
                $cat_to_delete = $row;
            } else {
                $child_names[] = $row['name'];
            }
        }
        $stmt_get->close();

        if (!$cat_to_delete) throw new Exception("Subcategoria não encontrada.");

        // Disassociate from transaction_items
        if ($cat_to_delete['parent_id'] === null) { // It's a Level 2 category
            // Nullify subcategory field
            $stmt_update_sub = $conn->prepare("UPDATE transaction_items SET subcategory = NULL WHERE subcategory = ?");
            $stmt_update_sub->bind_param("s", $cat_to_delete['name']);
            if (!$stmt_update_sub->execute()) throw new Exception("Erro ao desassociar subcategorias: " . $stmt_update_sub->error);
            $stmt_update_sub->close();

            // Nullify sub_subcategory field for all its children
            if (!empty($child_names)) {
                $placeholders = implode(',', array_fill(0, count($child_names), '?'));
                $types = str_repeat('s', count($child_names));
                $stmt_update_sub_sub = $conn->prepare("UPDATE transaction_items SET sub_subcategory = NULL WHERE sub_subcategory IN ($placeholders)");
                $stmt_update_sub_sub->bind_param($types, ...$child_names);
                if (!$stmt_update_sub_sub->execute()) throw new Exception("Erro ao desassociar sub-subcategorias: " . $stmt_update_sub_sub->error);
                $stmt_update_sub_sub->close();
            }
        } else { // It's a Level 3 category
            $stmt_update_sub_sub = $conn->prepare("UPDATE transaction_items SET sub_subcategory = NULL WHERE sub_subcategory = ?");
            $stmt_update_sub_sub->bind_param("s", $cat_to_delete['name']);
            if (!$stmt_update_sub_sub->execute()) throw new Exception("Erro ao desassociar sub-subcategorias: " . $stmt_update_sub_sub->error);
            $stmt_update_sub_sub->close();
        }

        // Delete the category and all its children
        if (!empty($all_ids_to_delete)) {
             $placeholders = implode(',', array_fill(0, count($all_ids_to_delete), '?'));
             $types = str_repeat('i', count($all_ids_to_delete));
             $stmt_delete = $conn->prepare("DELETE FROM subcategories WHERE id IN ($placeholders)");
             $stmt_delete->bind_param($types, ...$all_ids_to_delete);
             if (!$stmt_delete->execute()) throw new Exception("Erro ao apagar subcategoria(s): " . $stmt_delete->error);
             $stmt_delete->close();
        }

        $conn->commit();
        send_response(true);
    } catch (Exception $e) {
        $conn->rollback();
        send_response(false, null, $e->getMessage());
    }
}

function handle_move_subcategory($conn, $data) {
    $subcategory_id = $data['subcategory_id'] ?? null;
    $new_cost_center_id = $data['new_cost_center_id'] ?? null;

    if (!$subcategory_id || !$new_cost_center_id) {
        send_response(false, null, 'Dados em falta para mover a subcategoria.');
    }

    $conn->begin_transaction();
    try {
        // Get details of the subcategory and its current cost center
        $stmt_get_sub = $conn->prepare("SELECT s.name, s.parent_id, cc.name as cost_center_name FROM subcategories s JOIN cost_centers cc ON s.cost_center_id = cc.id WHERE s.id = ?");
        $stmt_get_sub->bind_param("i", $subcategory_id);
        $stmt_get_sub->execute();
        $result_sub = $stmt_get_sub->get_result();
        if ($result_sub->num_rows === 0) throw new Exception("Subcategoria não encontrada.");
        $sub_info = $result_sub->fetch_assoc();
        $subcategory_name = $sub_info['name'];
        $old_cost_center_name = $sub_info['cost_center_name'];
        $stmt_get_sub->close();

        if ($sub_info['parent_id'] !== null) throw new Exception('Apenas subcategorias de primeiro nível podem ser movidas entre centros de custo.');
        
        // Get the name of the new cost center
        $stmt_get_new_cc = $conn->prepare("SELECT name FROM cost_centers WHERE id = ?");
        $stmt_get_new_cc->bind_param("i", $new_cost_center_id);
        $stmt_get_new_cc->execute();
        $result_new_cc = $stmt_get_new_cc->get_result();
        if ($result_new_cc->num_rows === 0) throw new Exception("Novo centro de custo de destino não encontrado.");
        $new_cost_center_name = $result_new_cc->fetch_assoc()['name'];
        $stmt_get_new_cc->close();

        // Update the cost_center in transaction_items
        $stmt_update_items = $conn->prepare("UPDATE transaction_items SET cost_center = ? WHERE cost_center = ? AND subcategory = ?");
        $stmt_update_items->bind_param("sss", $new_cost_center_name, $old_cost_center_name, $subcategory_name);
        if (!$stmt_update_items->execute()) throw new Exception("Erro ao atualizar os movimentos: " . $stmt_update_items->error);
        $stmt_update_items->close();

        // Move the subcategory itself
        $stmt_move_sub = $conn->prepare("UPDATE subcategories SET cost_center_id = ? WHERE id = ?");
        $stmt_move_sub->bind_param("ii", $new_cost_center_id, $subcategory_id);
        if (!$stmt_move_sub->execute()) throw new Exception("Erro ao mover a subcategoria: " . $stmt_move_sub->error);
        $stmt_move_sub->close();

        $conn->commit();
        send_response(true);
    } catch (Exception $e) {
        $conn->rollback();
        send_response(false, null, $e->getMessage());
    }
}


function handle_move_transactions($conn, $data) {
    // From parameters
    $from_cost_center = $data['from_cost_center'] ?? null;
    $from_subcategory = $data['from_subcategory'] ?? null;
    $from_sub_subcategory = isset($data['from_sub_subcategory']) && $data['from_sub_subcategory'] !== '' ? $data['from_sub_subcategory'] : null;

    // To parameters
    $to_cost_center = $data['to_cost_center'] ?? null;
    $to_subcategory = $data['to_subcategory'] ?? null;
    $to_sub_subcategory = isset($data['to_sub_subcategory']) && $data['to_sub_subcategory'] !== '' ? $data['to_sub_subcategory'] : null;
    
    if (!$from_cost_center || !$from_subcategory || !$to_cost_center || !$to_subcategory) {
        send_response(false, null, 'As categorias de origem e destino são obrigatórias.');
    }
    
    $where_clauses = [];
    $params = [];
    $types = "";

    array_push($params, $to_cost_center, $to_subcategory, $to_sub_subcategory);
    $types .= "sss";

    $where_clauses[] = "cost_center = ?";
    array_push($params, $from_cost_center);
    $types .= "s";
    
    $where_clauses[] = "subcategory = ?";
    array_push($params, $from_subcategory);
    $types .= "s";

    if ($from_sub_subcategory !== null) {
        $where_clauses[] = "sub_subcategory = ?";
        array_push($params, $from_sub_subcategory);
        $types .= "s";
    } else {
        $where_clauses[] = "sub_subcategory IS NULL";
    }

    $where_sql = implode(" AND ", $where_clauses);
    $sql = "UPDATE transaction_items SET cost_center = ?, subcategory = ?, sub_subcategory = ? WHERE " . $where_sql;

    $stmt = $conn->prepare($sql);
    if ($stmt === false) { send_response(false, null, "Erro ao preparar a query: " . $conn->error); }
    
    $refs = [];
    foreach($params as $key => $value) $refs[$key] = &$params[$key];
    call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $refs));

    if ($stmt->execute()) {
        send_response(true, ['affected_rows' => $stmt->affected_rows]);
    } else {
        send_response(false, null, "Erro ao mover os movimentos: " . $stmt->error);
    }
}

function handle_move_transactions_by_description($conn, $data) {
    $description_text = $data['description_text'] ?? null;
    $to_cost_center = $data['to_cost_center'] ?? null;
    $to_subcategory = $data['to_subcategory'] ?? null;
    $to_sub_subcategory = isset($data['to_sub_subcategory']) && $data['to_sub_subcategory'] !== '' ? $data['to_sub_subcategory'] : null;
    
    if (!$description_text || !$to_cost_center || !$to_subcategory) {
        send_response(false, null, 'O texto da descrição e as categorias de destino são obrigatórios.');
    }
    
    $sql = "UPDATE transaction_items SET cost_center = ?, subcategory = ?, sub_subcategory = ? WHERE description LIKE ?";

    $stmt = $conn->prepare($sql);
    if ($stmt === false) { send_response(false, null, "Erro ao preparar a query: " . $conn->error); }
    
    $like_description = "%" . $description_text . "%";
    $stmt->bind_param("ssss", $to_cost_center, $to_subcategory, $to_sub_subcategory, $like_description);

    if ($stmt->execute()) {
        send_response(true, ['affected_rows' => $stmt->affected_rows]);
    } else {
        send_response(false, null, "Erro ao mover os movimentos por descrição: " . $stmt->error);
    }
}


$conn->close();
?>