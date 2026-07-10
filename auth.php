<?php
// auth.php
// ตัวช่วยจัดการ Session และการยืนยันตัวตนผ่าน LINE Login สำหรับหน้า Dashboard

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * สร้าง Base URL สาธารณะของระบบ (รองรับ ngrok / โดเมนจริง / โฟลเดอร์ที่มีช่องว่างในชื่อ)
 */
function auth_base_url() {
    $http_host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $is_local = strpos($http_host, 'localhost') !== false || strpos($http_host, '127.0.0.1') !== false;
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    if (!$is_local) {
        $protocol = 'https://';
    }

    $script_dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $script_dir = trim($script_dir, '/');
    $encoded_dir = '';
    if ($script_dir !== '') {
        $parts = array_map('rawurlencode', explode('/', $script_dir));
        $encoded_dir = '/' . implode('/', $parts);
    }

    return rtrim($protocol . $http_host . $encoded_dir, '/');
}

/**
 * URL ปลายทางที่ LINE จะ redirect กลับมาหลังล็อกอิน (ต้องตรงกับที่ตั้งใน LINE Developers Console)
 */
function auth_callback_url() {
    return auth_base_url() . '/auth_callback.php';
}

/**
 * ลิงก์เปิดแชท LINE OA พร้อมข้อความเริ่มต้น (ว่างถ้ายังไม่ตั้ง LINE_OA_BASIC_ID)
 */
function line_oa_message_url($text = 'ปรับโครงสร้าง') {
    if (!defined('LINE_OA_BASIC_ID') || trim(LINE_OA_BASIC_ID) === '') {
        return '';
    }
    $id = ltrim(trim(LINE_OA_BASIC_ID), '@');
    return 'https://line.me/R/oaMessage/@' . $id . '/?' . rawurlencode($text);
}

/**
 * เช็คว่าตั้งค่า LINE Login Channel แล้วหรือยัง
 */
function auth_is_configured() {
    return LINE_LOGIN_CHANNEL_ID !== '' && LINE_LOGIN_CHANNEL_SECRET !== '';
}

/**
 * เช็คว่าล็อกอินอยู่ไหม
 */
function auth_is_logged_in() {
    return !empty($_SESSION['line_user_id']);
}

/**
 * บังคับให้ล็อกอินก่อนเข้าหน้า ถ้ายังไม่ล็อกอินจะเด้งไปหน้า login
 */
function auth_require_login() {
    if (!auth_is_logged_in()) {
        header('Location: login.php');
        exit();
    }
}

/**
 * ดึงข้อมูลผู้ใช้ที่ล็อกอินอยู่จากฐานข้อมูล (คืน null ถ้ายังไม่มีแถวในตาราง users)
 */
function auth_current_user($conn) {
    if (!auth_is_logged_in()) {
        return null;
    }
    $stmt = $conn->prepare("SELECT * FROM users WHERE line_user_id = ? LIMIT 1");
    $stmt->bind_param("s", $_SESSION['line_user_id']);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $user ?: null;
}

/**
 * ลงทะเบียนครบแล้วหรือยัง (ชื่อ + Google Drive + ยอมรับนโยบาย หรือ legacy ที่มีสาขาแล้ว)
 */
function auth_registration_complete($user) {
    if (!$user || !is_array($user)) {
        return false;
    }
    $has_name = trim(($user['first_name'] ?? '') . ($user['last_name'] ?? '')) !== '';
    if (!$has_name) {
        return false;
    }
    if (trim($user['google_drive_id'] ?? '') === '') {
        return false;
    }
    if (!empty($user['policy_accepted_at'])) {
        return true;
    }
    $branch = trim($user['sales_branch'] ?? '');
    if ($branch !== '' && function_exists('branch_is_valid') && branch_is_valid($branch)) {
        return true;
    }
    return false;
}

/**
 * หลัง LINE Login — ไป register ถ้ายังไม่ครบ ไม่งั้น dashboard
 */
function auth_redirect_after_login($conn) {
    require_once __DIR__ . '/policy_lib.php';
    require_once __DIR__ . '/branch_config.php';
    policy_ensure_schema($conn);
    $user = auth_current_user($conn);
    $target = ($user && auth_registration_complete($user)) ? 'dashboard.php' : 'register.php';
    header('Location: ' . $target);
    exit();
}

/**
 * บังคับล็อกอิน + ลงทะเบียนครบแล้ว (หน้า Dashboard เป็นต้น)
 */
function auth_require_registration($conn) {
    auth_require_login();
    require_once __DIR__ . '/policy_lib.php';
    require_once __DIR__ . '/branch_config.php';
    policy_ensure_schema($conn);
    $user = auth_current_user($conn);
    if (!$user) {
        header('Location: logout.php');
        exit();
    }
    if (!auth_registration_complete($user)) {
        header('Location: register.php');
        exit();
    }
}
