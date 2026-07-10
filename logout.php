<?php
// logout.php
// ออกจากระบบ: ล้าง session ทั้งหมดแล้วกลับไปหน้า login
require_once 'auth.php';

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();

header('Location: login.php');
exit();
