<?php
// ชั่วคราว — ดู asking_price_enc (local only)
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/task_helpers.php';

$host = $_SERVER['HTTP_HOST'] ?? 'cli';
if (php_sapi_name() !== 'cli' && strpos($host, 'localhost') === false && strpos($host, '127.0.0.1') === false) {
    http_response_code(403);
    die('local only');
}

$user_id = isset($argv[1]) ? (int)$argv[1] : 8;
$limit = isset($argv[2]) ? (int)$argv[2] : 30;

$stmt = $conn->prepare('SELECT u.user_name, u.encryption_key, o.code_list, o.asking_price_enc, o.rental_price_enc
    FROM owners o JOIN users u ON u.id = o.user_id WHERE o.user_id = ? ORDER BY o.code_list LIMIT ?');
$stmt->bind_param('ii', $user_id, $limit);
$stmt->execute();
$res = $stmt->get_result();

$user_name = '';
$key = '';
$rows = [];
while ($row = $res->fetch_assoc()) {
    if ($user_name === '') {
        $user_name = $row['user_name'];
        $key = $row['encryption_key'];
    }
    $rows[] = $row;
}
$stmt->close();

$c = $conn->prepare('SELECT COUNT(*) c FROM owners WHERE user_id = ?');
$c->bind_param('i', $user_id);
$c->execute();
$total = (int)$c->get_result()->fetch_assoc()['c'];
$c->close();

header('Content-Type: text/plain; charset=utf-8');
echo "user_id={$user_id} ({$user_name}) — asking_price_enc\n";
echo str_repeat('-', 100) . "\n";
printf("%-12s | %-40s | %-18s | %s\n", 'code', 'asking_price_enc (ต้นฉบับ)', 'ถอดรหัส', 'เช่า');
echo str_repeat('-', 100) . "\n";

foreach ($rows as $row) {
    $enc = $row['asking_price_enc'] ?? '';
    $dec = $enc !== '' ? (decrypt_data($enc, $key) ?: '(decrypt fail)') : '-';
    $rent = !empty($row['rental_price_enc']) ? (decrypt_data($row['rental_price_enc'], $key) ?: '-') : '-';
    $encShow = $enc === '' ? 'NULL' : (strlen($enc) > 40 ? substr($enc, 0, 40) . '...' : $enc);
    printf("%-12s | %-40s | %-18s | %s\n", $row['code_list'], $encShow, $dec, $rent);
}

echo str_repeat('-', 100) . "\n";
echo 'แสดง ' . count($rows) . " / {$total} รายการ\n";
