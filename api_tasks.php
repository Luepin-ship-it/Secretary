<?php
// api_tasks.php
// API สำหรับจัดการบันทึกงานการติดตามผล (Tasks) ของผู้ใช้งานระบบ (Project Antigravity)
require_once 'config.php';
require_once __DIR__ . '/lib/subscription.php';

subscription_ensure_schema($conn);

header('Content-Type: application/json; charset=utf-8');

$line_id = isset($_REQUEST['line_id']) ? trim($_REQUEST['line_id']) : '';

if (empty($line_id)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing line_id']);
    exit();
}

$stmt = $conn->prepare("SELECT id, encryption_key, google_sheet_id, trial_ends_at, is_subscribed, is_lifetime_free FROM users WHERE line_user_id = ? LIMIT 1");
$stmt->bind_param("s", $line_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'User not registered']);
    exit();
}

$deny = subscription_deny_payload($user);
if ($deny) {
    http_response_code(402);
    echo json_encode($deny);
    exit();
}

$encryption_key = $user['encryption_key'];
$user_id = $user['id'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // ------------------ ดึงรายการ Tasks ------------------
    $stmt = $conn->prepare("SELECT * FROM tasks WHERE user_id = ? ORDER BY is_completed ASC, due_date ASC, created_at DESC");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $tasks = [];
    
    while ($row = $result->fetch_assoc()) {
        $tasks[] = [
            'id' => $row['id'],
            'title' => decrypt_data($row['title_enc'], $encryption_key),
            'due_date' => $row['due_date'],
            'is_completed' => (int)$row['is_completed'],
            'lead_code' => $row['lead_code'],
            'owner_code' => $row['owner_code'],
            'created_at' => $row['created_at']
        ];
    }
    $stmt->close();
    
    echo json_encode(['success' => true, 'data' => $tasks]);
    exit();

} elseif ($method === 'POST') {
    // ------------------ เพิ่ม, อัปเดต หรือสลับสถานะความสำเร็จ ------------------
    $input_raw = file_get_contents('php://input');
    $data = json_decode($input_raw, true);
    
    if (!$data) {
        $data = $_POST;
    }
    
    $action = trim($data['action'] ?? '');
    
    if ($action === 'toggle') {
        // สลับสถานะงานสำเร็จ/ไม่สำเร็จ
        $task_id = (int)($data['id'] ?? 0);
        $is_completed = (int)($data['is_completed'] ?? 0);
        
        $stmt = $conn->prepare("UPDATE tasks SET is_completed = ? WHERE id = ? AND user_id = ?");
        $stmt->bind_param("iii", $is_completed, $task_id, $user_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Task status updated']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error']);
        }
        $stmt->close();
        exit();
        
    } elseif ($action === 'delete') {
        // ลบงาน
        $task_id = (int)($data['id'] ?? 0);
        
        $stmt = $conn->prepare("DELETE FROM tasks WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $task_id, $user_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Task deleted successfully']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error']);
        }
        $stmt->close();
        exit();
        
    } else {
        // สร้าง Task ใหม่
        $title = trim($data['title'] ?? '');
        $due_date = !empty($data['due_date']) ? $data['due_date'] : date('Y-m-d');
        $lead_code = !empty($data['lead_code']) ? trim($data['lead_code']) : null;
        
        if (empty($title)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Task title is required']);
            exit();
        }
        
        $title_enc = encrypt_data($title, $encryption_key);
        
        $stmt = $conn->prepare("INSERT INTO tasks (user_id, title_enc, due_date, is_completed, lead_code) VALUES (?, ?, ?, 0, ?)");
        $stmt->bind_param("isss", $user_id, $title_enc, $due_date, $lead_code);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Task created successfully']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error']);
        }
        $stmt->close();
        exit();
    }
}
