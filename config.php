<?php
// config.php
// ไฟล์กำหนดค่าระบบและการเชื่อมต่อฐานข้อมูล (Project Antigravity)

// ตั้งค่า Timezone เป็นประเทศไทย
date_default_timezone_set('Asia/Bangkok');

// Production (GCP / host จริง): คัดลอก config.local.example.php → config.local.php
$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) {
    require $localConfig;
}

// Local XAMPP defaults — ใช้เมื่อไม่มี config.local.php
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');
if (!defined('DB_NAME')) define('DB_NAME', 'antigravity_db');

if (!defined('LINE_ACCESS_TOKEN')) define('LINE_ACCESS_TOKEN', '');
if (!defined('OPENAI_API_KEY')) define('OPENAI_API_KEY', '');
if (!defined('LINE_LIFF_ID')) define('LINE_LIFF_ID', '');
// LIFF ค้นหาชื่อโครงการ — สร้างใน LINE Developers → LIFF → Endpoint: https://YOUR_DOMAIN/liff/project_search.php
// Scope: profile, openid, chat_message.write (ส่งกลับแชท)
if (!defined('LINE_LIFF_PROJECT_SEARCH_ID')) define('LINE_LIFF_PROJECT_SEARCH_ID', '');
// LIFF คู่มืออัปรูปทรัพย์ — Endpoint: https://YOUR_DOMAIN/liff/project_photos.php (Scope: profile)
if (!defined('LINE_LIFF_PROJECT_PHOTOS_ID')) define('LINE_LIFF_PROJECT_PHOTOS_ID', '');
if (!defined('LINE_LOGIN_CHANNEL_ID')) define('LINE_LOGIN_CHANNEL_ID', '');
if (!defined('LINE_LOGIN_CHANNEL_SECRET')) define('LINE_LOGIN_CHANNEL_SECRET', '');
if (!defined('GOOGLE_MAPS_API_KEY')) define('GOOGLE_MAPS_API_KEY', '');
if (!defined('GOOGLE_MAP_ID')) define('GOOGLE_MAP_ID', '');
if (!defined('LINE_OA_BASIC_ID')) define('LINE_OA_BASIC_ID', '');
if (!defined('INTRO_STRUCTURE_PREVIEW_URL')) define('INTRO_STRUCTURE_PREVIEW_URL', '');
if (!defined('GOOGLE_DRIVE_API_KEY')) define('GOOGLE_DRIVE_API_KEY', '');

// การเปิดใช้งานการเชื่อมต่อฐานข้อมูลด้วย mysqli
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// ตรวจสอบความผิดพลาดในการเชื่อมต่อ
if ($conn->connect_error) {
    die("การเชื่อมต่อฐานข้อมูลล้มเหลว: " . $conn->connect_error);
}

// ตั้งค่าการเข้ารหัสอักขระเป็น utf8mb4
$conn->set_charset("utf8mb4");

/**
 * ฟังก์ชันเข้ารหัสข้อมูลด้วย AES-256-CTR โดยใช้คีย์เฉพาะของแต่ละผู้ใช้งาน (Dynamic Per-User Key)
 * 
 * @param string $data ข้อมูลที่ต้องการเข้ารหัส (ข้อความธรรมดา)
 * @param string $key คีย์เฉพาะผู้ใช้สำหรับการเข้ารหัส
 * @return string|null ข้อความที่เข้ารหัสแล้วในรูปแบบ Base64 หรือ null
 */
function encrypt_data($data, $key) {
    if ($data === null || $data === '') {
        return null;
    }
    // ดึงความยาว IV สำหรับ cipher aes-256-ctr (ปกติคือ 16 ไบต์)
    $iv_length = openssl_cipher_iv_length('aes-256-ctr');
    // สุ่ม IV เพื่อป้องกันการถอดรหัสแบบเดิมเมื่อใช้คีย์ซ้ำกัน
    $iv = openssl_random_pseudo_bytes($iv_length);
    
    // สร้าง 256-bit Key จากคีย์ผู้ใช้ด้วย SHA-256
    $encryption_key = hash('sha256', $key, true);
    
    // ทำการเข้ารหัสข้อมูล
    $encrypted = openssl_encrypt($data, 'aes-256-ctr', $encryption_key, OPENSSL_RAW_DATA, $iv);
    
    // แนบ IV ไว้ข้างหน้าข้อมูลที่เข้ารหัส แล้วเข้ารหัสด้วย Base64 เพื่อความปลอดภัยในการบันทึกและโอนย้าย
    return base64_encode($iv . $encrypted);
}

/**
 * ฟังก์ชันถอดรหัสข้อมูลด้วย AES-256-CTR โดยใช้คีย์เฉพาะของแต่ละผู้ใช้งาน (Dynamic Per-User Key)
 * 
 * @param string $data ข้อมูลที่เข้ารหัสแล้วแบบ Base64
 * @param string $key คีย์เฉพาะผู้ใช้สำหรับการถอดรหัส
 * @return string|null ข้อความเดิมที่ถอดรหัสแล้ว หรือ null
 */
function decrypt_data($data, $key) {
    if ($data === null || $data === '') {
        return null;
    }
    // ถอดรหัส Base64
    $decoded = base64_decode($data);
    $iv_length = openssl_cipher_iv_length('aes-256-ctr');
    
    // ตรวจสอบความสมบูรณ์ของข้อมูล
    if (strlen($decoded) <= $iv_length) {
        return '';
    }
    
    // ดึง IV ที่แนบอยู่ด้านหน้าออกมา
    $iv = substr($decoded, 0, $iv_length);
    // ดึงเฉพาะก้อนเนื้อหาที่เข้ารหัส
    $encrypted = substr($decoded, $iv_length);
    
    // สร้าง 256-bit Key ด้วย SHA-256
    $encryption_key = hash('sha256', $key, true);
    
    // ถอดรหัสข้อมูล
    return openssl_decrypt($encrypted, 'aes-256-ctr', $encryption_key, OPENSSL_RAW_DATA, $iv);
}
