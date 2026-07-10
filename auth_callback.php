<?php
// auth_callback.php
// รับ Authorization Code จาก LINE Login -> แลก Access Token -> ดึงโปรไฟล์ -> สร้าง Session -> register หรือ dashboard
require_once 'config.php';
require_once 'auth.php';

function redirect_login_with_error($msg) {
    header('Location: login.php?error=' . urlencode($msg));
    exit();
}

if (!auth_is_configured()) {
    redirect_login_with_error('ระบบยังไม่ได้ตั้งค่า LINE Login Channel');
}

// 1. ตรวจสอบ state กัน CSRF
$code = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';

if (isset($_GET['error'])) {
    redirect_login_with_error('การเข้าสู่ระบบถูกยกเลิก: ' . ($_GET['error_description'] ?? $_GET['error']));
}
if ($code === '' || $state === '' || $state !== ($_SESSION['line_login_state'] ?? '')) {
    redirect_login_with_error('การยืนยันตัวตนไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง');
}
unset($_SESSION['line_login_state']);

// 2. แลก Authorization Code เป็น Access Token + ID Token
$ch = curl_init('https://api.line.me/oauth2/v2.1/token');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'grant_type' => 'authorization_code',
    'code' => $code,
    'redirect_uri' => auth_callback_url(),
    'client_id' => LINE_LOGIN_CHANNEL_ID,
    'client_secret' => LINE_LOGIN_CHANNEL_SECRET,
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
$token_res = curl_exec($ch);
$token_http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$token_data = json_decode($token_res, true);
if ($token_http !== 200 || empty($token_data['id_token'])) {
    file_put_contents(__DIR__ . '/line_webhook_debug.log', "[LINE Login] Token exchange failed | HTTP: $token_http | $token_res\n", FILE_APPEND);
    redirect_login_with_error('ไม่สามารถแลก Token กับ LINE ได้ กรุณาลองใหม่');
}

// 3. ตรวจสอบ ID Token กับ LINE เพื่อดึงข้อมูลโปรไฟล์ (userId, ชื่อ, รูป)
$ch = curl_init('https://api.line.me/oauth2/v2.1/verify');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'id_token' => $token_data['id_token'],
    'client_id' => LINE_LOGIN_CHANNEL_ID,
    'nonce' => $_SESSION['line_login_nonce'] ?? '',
]));
$verify_res = curl_exec($ch);
$verify_http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
unset($_SESSION['line_login_nonce']);

$profile = json_decode($verify_res, true);
if ($verify_http !== 200 || empty($profile['sub'])) {
    file_put_contents(__DIR__ . '/line_webhook_debug.log', "[LINE Login] Verify failed | HTTP: $verify_http | $verify_res\n", FILE_APPEND);
    redirect_login_with_error('ไม่สามารถยืนยันตัวตนกับ LINE ได้ กรุณาลองใหม่');
}

$line_user_id = $profile['sub'];
$display_name = $profile['name'] ?? '';
$picture_url = $profile['picture'] ?? '';

// 4. สร้าง Session — ไม่สร้าง user อัตโนมัติ (ให้ register.php เป็นจุดเดียว)
session_regenerate_id(true);
$_SESSION['line_user_id'] = $line_user_id;
$_SESSION['line_display_name'] = $display_name;
$_SESSION['line_picture_url'] = $picture_url;

auth_redirect_after_login($conn);
