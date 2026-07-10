<?php
// api_get_user.php
// API ดึงข้อมูลโปรไฟล์ผู้ใช้งานสำหรับใช้ในระบบลงทะเบียน LIFF
require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');

$line_id = isset($_GET['line_id']) ? trim($_GET['line_id']) : '';

if (empty($line_id)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing line_id']);
    exit();
}

$stmt = $conn->prepare("SELECT user_name, first_name, last_name, phone, consent_optional, google_drive_id, job_title, sales_branch, sales_display_name, work_context, has_teammates, teammate_roles, agent_areas, reject_cases, reject_reasons, bot_name, persona_style, business_type, google_sheet_id, encryption_key FROM users WHERE line_user_id = ? LIMIT 1");
$stmt->bind_param("s", $line_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($user) {
    echo json_encode(['success' => true, 'data' => $user]);
} else {
    echo json_encode(['success' => false, 'message' => 'User not found']);
}
