<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/listing_flex_lib.php';

$uid = (int)($argv[1] ?? 8);
$code = $argv[2] ?? 'TAN617';

$stmt = $conn->prepare('SELECT * FROM users WHERE id = ?');
$stmt->bind_param('i', $uid);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$stmt = $conn->prepare('SELECT cover_image_url FROM owners WHERE user_id = ? AND code_list = ?');
$stmt->bind_param('is', $uid, $code);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

echo "cover_image_url (raw): " . ($row['cover_image_url'] ?? '(null)') . "\n";

$_SERVER['HTTP_HOST'] = 'catnap-overpay-computer.ngrok-free.dev';
$_SERVER['HTTPS'] = 'on';
$_SERVER['SCRIPT_NAME'] = '/Ai agent Line OA/webhook.php';

$stmt = $conn->prepare('SELECT * FROM owners WHERE user_id = ? AND code_list = ?');
$stmt->bind_param('is', $uid, $code);
$stmt->execute();
$owner = $stmt->get_result()->fetch_assoc();
$stmt->close();

$f = listing_owner_fields($owner, $user['encryption_key']);
echo "cover_main: " . $f['cover_main'] . "\n";

$id = gdrive_file_id_from_url($row['cover_image_url'] ?? '');
echo "drive file id: " . ($id ?? '(none)') . "\n";

if ($id) {
    $img = gdrive_fetch_image($id);
    echo 'gdrive_fetch_image: ' . ($img ? 'OK ' . $img['type'] . ' ' . strlen($img['body']) . ' bytes' : 'FAILED') . "\n";
}
