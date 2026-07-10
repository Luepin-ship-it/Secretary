<?php
// add_test_user.php
// สคริปต์ช่วยลงทะเบียนผู้ใช้ทดสอบในระบบ (Project Antigravity)
require_once 'config.php';

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.");
}

if ($argc < 2) {
    echo "วิธีใช้งาน: php add_test_user.php <LINE_USER_ID> [GOOGLE_SHEET_ID]\n";
    echo "ตัวอย่าง: php add_test_user.php U123456789abcdef0123456789abcdef1\n";
    exit(1);
}

$line_user_id = trim($argv[1]);
$google_sheet_id = isset($argv[2]) ? trim($argv[2]) : 'YOUR_GOOGLE_SHEET_ID_HERE';

// ตรวจสอบว่าผู้ใช้งานรายนี้มีอยู่ในระบบแล้วหรือยัง
$stmt = $conn->prepare("SELECT id FROM users WHERE line_user_id = ?");
$stmt->bind_param("s", $line_user_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows > 0) {
    echo "ผู้ใช้งานที่มี LINE User ID: $line_user_id นี้ลงทะเบียนอยู่แล้วในระบบ\n";
    $stmt->close();
    exit(0);
}
$stmt->close();

// กำหนดข้อมูลเริ่มต้นจำลอง
$user_name = "ผู้ทดสอบระบบ";
$bot_name = "เลขา AI";
$persona_style = "formal_polite"; // formal_polite, casual_friendly, assertive_professional
$business_type = "Real Estate";   // Real Estate, Automotive, Financial
$encryption_key = bin2hex(random_bytes(16)); // สร้างคีย์สุ่มแบบปลอดภัยสำหรับการเข้ารหัสข้อมูลลีด

$stmt = $conn->prepare("INSERT INTO users (line_user_id, user_name, bot_name, persona_style, business_type, google_sheet_id, encryption_key) VALUES (?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("sssssss", $line_user_id, $user_name, $bot_name, $persona_style, $business_type, $google_sheet_id, $encryption_key);

if ($stmt->execute()) {
    echo "--------------------------------------------------\n";
    echo "ลงทะเบียนผู้ใช้ทดสอบสำเร็จแล้ว!\n";
    echo "LINE User ID: $line_user_id\n";
    echo "ชื่อผู้ใช้: $user_name\n";
    echo "ชื่อบอท: $bot_name\n";
    echo "ประเภทธุรกิจ: $business_type\n";
    echo "Google Sheet ID: $google_sheet_id\n";
    echo "Encryption Key (คีย์เข้ารหัสลับ): $encryption_key\n";
    echo "--------------------------------------------------\n";
} else {
    echo "เกิดข้อผิดพลาดในการลงทะเบียน: " . $conn->error . "\n";
}
$stmt->close();
