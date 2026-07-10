<?php
// test_webhook_local.php
// สคริปต์จำลองการยิง Webhook ของ LINE เข้ามาทดสอบแบบ Local

// ตรวจสอบก่อนว่าเริ่มทำ MySQL ใน XAMPP หรือยัง
$host = 'localhost';
$user = 'root';
$pass = '';
$db = 'antigravity_db';

echo "=== ขั้นตอนการเตรียมความพร้อมสำหรับการทดสอบ ===" . PHP_EOL;
echo "1. กรุณาเปิดใช้งาน MySQL และ Apache ใน XAMPP Control Panel" . PHP_EOL;
echo "2. กรุณาอัปเดตไฟล์ config.php โดยระบุ OPENAI_API_KEY ให้ถูกต้อง" . PHP_EOL;
echo "3. รันไฟล์ db_import.php เพื่อเตรียม Database (php db_import.php)" . PHP_EOL . PHP_EOL;

// ทดสอบเชื่อมต่อ DB
$conn = @new mysqli($host, $user, $pass);
if ($conn->connect_error) {
    echo "[!] ไม่สามารถเชื่อมต่อ MySQL ได้: " . $conn->connect_error . PHP_EOL;
    echo "[!] กรุณาเปิด MySQL ใน XAMPP ก่อนทำขั้นตอนถัดไป" . PHP_EOL;
    exit();
}

// ตรวจสอบฐานข้อมูลว่ามีอยู่หรือไม่
$conn->select_db($db);
if ($conn->error) {
    echo "[!] ไม่พบฐานข้อมูล {$db} กรุณารัน php db_import.php ก่อน" . PHP_EOL;
    $conn->close();
    exit();
}

// สร้างหรืออัปเดตผู้ใช้งานจำลองในระบบ เพื่อใช้ทดสอบการถอดรหัส/ซิงก์ชีต
$mock_line_id = 'U1234567890abcdef1234567890abcdef';
$mock_user_name = 'สมชาย ใจดี';
$mock_encryption_key = 'antigravity_super_secret_key_123';
$mock_google_sheet_id = 'YOUR_GOOGLE_SHEET_ID_HERE'; // เปลี่ยนเป็น ID จริงเพื่อลองซิงก์

// ลบผู้ใช้ทดลองเดิม (หากมี) เพื่อหลีกเลี่ยงการซ้ำ
$conn->query("DELETE FROM users WHERE line_user_id = '$mock_line_id'");

$stmt = $conn->prepare("INSERT INTO users (line_user_id, user_name, bot_name, persona_style, business_type, google_sheet_id, encryption_key) VALUES (?, ?, 'เลขาจำลอง', 'casual_friendly', 'Real Estate', ?, ?)");
$stmt->bind_param("sss", $mock_line_id, $mock_google_sheet_id, $mock_encryption_key);
if ($stmt->execute()) {
    echo "[+] สร้างผู้ใช้งานทดสอบในระบบเรียบร้อย (Mock User Created)" . PHP_EOL;
} else {
    echo "[!] เกิดข้อผิดพลาดในการสร้างผู้ใช้ทดสอบ: " . $stmt->error . PHP_EOL;
    $stmt->close();
    $conn->close();
    exit();
}
$stmt->close();

// จำลองข้อความ Webhook ส่งเข้า webhook.php
echo PHP_EOL . "=== เริ่มต้นทดสอบจำลองส่งแชทจากเซลส์ ===" . PHP_EOL;
echo "กรุณาเลือกประเภทข้อความที่จะจำลองส่ง:" . PHP_EOL;
echo "[1] อัปเดตข้อมูลลีดปกติ (Normal Update)" . PHP_EOL;
echo "[2] พยายามปฏิเสธดีล (Reject Attempt)" . PHP_EOL;
echo "ใส่ตัวเลือก (1 หรือ 2): ";

// อ่านอินพุต (หากรันผ่าน CLI)
$handle = fopen ("php://stdin","r");
$line = fgets($handle);
$choice = trim($line);
fclose($handle);

if ($choice == '2') {
    $message_text = "ลีด L042 แจ้งว่าขอยกเลิกนัดดูคอนโดวันพรุ่งนี้และขอเลื่อนดีลออกไปไม่มีกำหนดเนื่องจากกู้ธนาคารไม่ผ่านแล้วครับ";
    echo "จำลองข้อความ: '{$message_text}' (ประเภทปฏิเสธดีล)" . PHP_EOL;
} else {
    $message_text = "อัปเดตลีด L042 ลูกค้าชื่อคุณอัครพล สนใจคอนโด 2 ห้องนอน งบ 5 ล้านบาท มีความต้องการด่วนเรื่องอยากย้ายเข้าอยู่ก่อนสิ้นเดือนนี้";
    echo "จำลองข้อความ: '{$message_text}' (ประเภทอัปเดตปกติ)" . PHP_EOL;
}

// โครงสร้าง Payload ของ LINE Webhook
$mock_payload = [
    "destination" => "xxxxxxxxxx",
    "events" => [
        [
            "type" => "message",
            "message" => [
                "type" => "text",
                "id" => "32543637746211",
                "text" => $message_text
            ],
            "timestamp" => time() * 1000,
            "source" => [
                "type" => "user",
                "userId" => $mock_line_id
            ],
            "replyToken" => "nH7ghzDhWnSTbc1R2SD145"
        ]
    ]
];

// ดึงการตั้งค่าพอร์ตและโฮสต์ของ Apache เพื่อยิงให้ถูกจุด
$webhook_url = "http://localhost/Ai%20agent%20Line%20OA/webhook.php";
echo "กำลังยิง Webhook ไปที่: {$webhook_url}" . PHP_EOL;

$ch = curl_init($webhook_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($mock_payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "--- ผลการยิงทดสอบ ---" . PHP_EOL;
echo "HTTP Status Code: {$http_code}" . PHP_EOL;
echo "Webhook Response: " . (empty($response) ? "(ไม่มีการตอบกลับเนื้อหา ซึ่งเป็นปกติของ Webhook)" : $response) . PHP_EOL;

// คิวรี่ดูข้อมูลใน DB เพื่อยืนยันว่าเข้าและเข้ารหัสถูกต้องจริง
echo PHP_EOL . "=== ตรวจสอบข้อมูลในฐานข้อมูล ===" . PHP_EOL;
include_once 'config.php';
$lead_res = $conn->query("SELECT * FROM leads WHERE user_id = (SELECT id FROM users WHERE line_user_id = '$mock_line_id' LIMIT 1) AND lead_code = 'L042' LIMIT 1");
$lead_row = $lead_res->fetch_assoc();

if ($lead_row) {
    echo "[+] พบข้อมูลลีด L042 ในตาราง leads!" . PHP_EOL;
    echo "    - สถานะลีด: " . $lead_row['status'] . PHP_EOL;
    echo "    - คะแนนความสำคัญ: " . $lead_row['priority_score'] . PHP_EOL;
    echo "    - ข้อมูลใน DB (เข้ารหัสไว้):" . PHP_EOL;
    echo "      * lead_name_enc: " . $lead_row['lead_name_enc'] . PHP_EOL;
    echo "      * customer_insight_enc: " . $lead_row['customer_insight_enc'] . PHP_EOL;
    echo "      * deal_context_enc: " . $lead_row['deal_context_enc'] . PHP_EOL;
    
    // ลองถอดรหัสแสดงผล
    echo "    - ข้อมูลหลังถอดรหัสสำเร็จ (Decrypted Data):" . PHP_EOL;
    echo "      * ชื่อลีด: " . decrypt_data($lead_row['lead_name_enc'], $mock_encryption_key) . PHP_EOL;
    echo "      * ข้อมูลเชิงลึก: " . decrypt_data($lead_row['customer_insight_enc'], $mock_encryption_key) . PHP_EOL;
    echo "      * บริบท/ข้อความดิบ: " . decrypt_data($lead_row['deal_context_enc'], $mock_encryption_key) . PHP_EOL;
} else {
    echo "[!] ไม่พบข้อมูลลีดในตาราง leads คาดว่าเชื่อมต่อ OpenAI ไม่สำเร็จ หรือ API Key ผิดพลาด" . PHP_EOL;
}

$conn->close();
