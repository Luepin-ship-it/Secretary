<?php
/** Proxy รูปปก Google Drive — เบราว์เซอร์โหลดจาก localhost แทน drive.google.com โดยตรง */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/gdrive_cover.php';

$id = trim($_GET['id'] ?? '');
$img = gdrive_fetch_image($id);
if ($img === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Cover not found';
    exit;
}

header('Content-Type: ' . $img['type']);
header('Cache-Control: public, max-age=86400');
echo $img['body'];
