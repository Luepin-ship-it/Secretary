<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/task_helpers.php';

$user_id = isset($argv[1]) ? (int)$argv[1] : 8;

$stmt = $conn->prepare('SELECT u.user_name, u.encryption_key, o.code_list, o.owner_asking_price_enc, o.asking_price_enc
    FROM owners o JOIN users u ON u.id = o.user_id WHERE o.user_id = ? ORDER BY o.code_list');
$stmt->bind_param('i', $user_id);
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

echo "user_id={$user_id} ({$user_name}) — owner_asking_price_enc\n";
echo str_repeat('-', 110) . "\n";
printf("%-12s | %-42s | %-20s | %s\n", 'code', 'owner_asking_price_enc', 'เจ้าของตั้ง', 'ราคาเราเสนอ');
echo str_repeat('-', 110) . "\n";

foreach ($rows as $row) {
    $enc = $row['owner_asking_price_enc'] ?? '';
    $dec = $enc !== '' ? (decrypt_data($enc, $key) ?: '(fail)') : '-';
    $ask = !empty($row['asking_price_enc']) ? (decrypt_data($row['asking_price_enc'], $key) ?: '-') : '-';
    $encShow = $enc === '' ? 'NULL' : (strlen($enc) > 42 ? substr($enc, 0, 42) . '...' : $enc);
    printf("%-12s | %-42s | %-20s | %s\n", $row['code_list'], $encShow, $dec, $ask);
}

echo str_repeat('-', 110) . "\n";
echo 'รวม ' . count($rows) . " รายการ\n";
