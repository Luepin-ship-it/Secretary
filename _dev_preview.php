<?php
// _dev_preview.php — ไฟล์ชั่วคราวสำหรับทดสอบ dashboard บน localhost เท่านั้น (ลบทิ้งหลังใช้)
require_once 'config.php';
session_start();

$host = $_SERVER['HTTP_HOST'] ?? '';
if (strpos($host, 'localhost') === false && strpos($host, '127.0.0.1') === false) {
    http_response_code(403);
    die('local only');
}

$uid_param = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
if ($uid_param > 0) {
    $stmt = $conn->prepare("SELECT line_user_id, user_name FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $uid_param);
    $stmt->execute();
    $res = $stmt->get_result();
    $u = $res ? $res->fetch_assoc() : null;
    $stmt->close();
} else {
    $res = $conn->query("SELECT line_user_id, user_name FROM users ORDER BY id DESC LIMIT 1");
    $u = $res ? $res->fetch_assoc() : null;
}
if (!$u) {
    die('ไม่มี user ในฐานข้อมูล');
}

$_SESSION['line_user_id'] = $u['line_user_id'];
$_SESSION['line_display_name'] = $u['user_name'];
$_SESSION['line_picture_url'] = '';

header('Location: dashboard.php');
