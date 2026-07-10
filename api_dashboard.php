<?php
// api_dashboard.php
// API สำหรับประมวลผลสรุปสถิติตัวเลข (Dashboard) ของผู้ใช้งานระบบ (Project Antigravity)
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

$stmt = $conn->prepare("SELECT id, encryption_key, trial_ends_at, is_subscribed, is_lifetime_free FROM users WHERE line_user_id = ? LIMIT 1");
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

// 1. จำนวนลีดแยกตามสถานะ
$status_counts = [
    'Call' => 0, 'Follow' => 0, 'Appointment' => 0, 'Show' => 0,
    'Nego' => 0, 'Reserve' => 0, 'Close' => 0, 'Bank' => 0, 'Win' => 0,
    'Lose' => 0, 'Hold_Reject' => 0, 'Rejected' => 0
];

$stmt = $conn->prepare("SELECT status, COUNT(*) as count FROM leads WHERE user_id = ? GROUP BY status");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$total_leads = 0;
while ($row = $res->fetch_assoc()) {
    if (isset($status_counts[$row['status']])) {
        $status_counts[$row['status']] = (int)$row['count'];
    }
    $total_leads += (int)$row['count'];
}
$stmt->close();

// 2. ข้อมูลฝั่ง Owner (Listing)
$owner_stats = [
    'total' => 0,
    'active' => 0,
    'cancelled' => 0,
    'sold' => 0
];

$stmt = $conn->prepare("SELECT availability_status, COUNT(*) as count FROM owners WHERE user_id = ? GROUP BY availability_status");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $status = $row['availability_status'];
    $count = (int)$row['count'];
    $owner_stats['total'] += $count;
    
    if ($status === 'ยังขายอยู่' || $status === 'Active') {
        $owner_stats['active'] += $count;
    } elseif ($status === 'ยกเลิกการขาย' || $status === 'Cancelled') {
        $owner_stats['cancelled'] += $count;
    } elseif ($status === 'ขายได้แล้ว' || $status === 'Sold') {
        $owner_stats['sold'] += $count;
    }
}
$stmt->close();

// 3. ยอดขายสะสม (Sum of Decrypted Sold Prices)
// ดึงราคาของรายการที่ขายได้แล้วทั้งหมดมาถอดรหัสในหน่วยความจำ
$total_sales = 0.0;
$stmt = $conn->prepare("SELECT sold_price_enc FROM owners WHERE user_id = ? AND (availability_status = 'ขายได้แล้ว' OR availability_status = 'Sold')");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    if (!empty($row['sold_price_enc'])) {
        $decrypted_price = decrypt_data($row['sold_price_enc'], $encryption_key);
        // คลีนตัวเลข (ลบลูกน้ำ ช่องว่าง ฯลฯ)
        $clean_price = (float)preg_replace('/[^0-9.]/', '', $decrypted_price);
        $total_sales += $clean_price;
    }
}
$stmt->close();

// 4. สรุป Tasks
$task_stats = [
    'total' => 0,
    'completed' => 0,
    'pending' => 0
];
$stmt = $conn->prepare("SELECT is_completed, COUNT(*) as count FROM tasks WHERE user_id = ? GROUP BY is_completed");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $count = (int)$row['count'];
    $task_stats['total'] += $count;
    if ((int)$row['is_completed'] === 1) {
        $task_stats['completed'] += $count;
    } else {
        $task_stats['pending'] += $count;
    }
}
$stmt->close();

echo json_encode([
    'success' => true,
    'data' => [
        'leads' => [
            'total' => $total_leads,
            'by_status' => $status_counts
        ],
        'owners' => [
            'total' => $owner_stats['total'],
            'active' => $owner_stats['active'],
            'cancelled' => $owner_stats['cancelled'],
            'sold' => $owner_stats['sold'],
            'total_sales' => $total_sales
        ],
        'tasks' => $task_stats
    ]
]);
exit();
