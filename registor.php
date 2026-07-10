<?php
// URL เก่าที่สะกดผิด — redirect ไปทางหลัก
$query = $_SERVER['QUERY_STRING'] ?? '';
$target = 'login.php' . ($query !== '' ? '?' . $query : '');
header('Location: ' . $target, true, 302);
exit;
