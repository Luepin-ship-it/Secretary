<?php
/** แสดงรูปทรัพย์ที่อัปโหลดผ่าน LIFF */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/owner_photos_store.php';

$uid = (int)($_GET['u'] ?? 0);
$code = owner_photos_sanitize_code((string)($_GET['c'] ?? ''));
$file = basename((string)($_GET['f'] ?? ''));
$sig = (string)($_GET['sig'] ?? '');

if ($uid <= 0 || $code === '' || $file === '' || !owner_photos_verify_sign($uid, $code, $file, $sig)) {
    http_response_code(403);
    exit('Forbidden');
}

$path = owner_photos_dir($uid, $code) . '/' . $file;
if (!is_file($path)) {
    http_response_code(404);
    exit('Not found');
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = $finfo ? finfo_file($finfo, $path) : 'image/jpeg';
if ($finfo) {
    finfo_close($finfo);
}

header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=86400');
readfile($path);
