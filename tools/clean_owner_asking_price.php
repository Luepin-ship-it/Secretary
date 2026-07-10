<?php
/**
 * ล้าง owner_asking_price_enc ที่ใส่ผิดคอลัมน์ (เช่น 50/50 Transfer Fee)
 * คงไว้เฉพาะค่าที่เป็นตัวเลขราคา
 *
 * Usage: php tools/clean_owner_asking_price.php [--user-id=8] [--dry-run]
 */
require_once dirname(__DIR__) . '/config.php';

$userId = null;
$dryRun = in_array('--dry-run', $argv, true);
foreach ($argv as $arg) {
    if (strpos($arg, '--user-id=') === 0) {
        $userId = (int)substr($arg, 10);
    }
}

/** ตรวจว่าข้อความถอดรหัสแล้วเป็นราคาจริงหรือไม่ */
function is_valid_owner_asking_price(string $plain): bool {
    $s = trim($plain);
    if ($s === '') {
        return false;
    }
    if (preg_match('/transfer|included|http|drive\.google|www\.|\/\//iu', $s)) {
        return false;
    }
    if (preg_match('/[a-zA-Zก-๙]/u', $s)) {
        return false;
    }
    $n = str_replace(',', '', $s);
    if (preg_match('/^[\d.]+([eE][+\-]?\d+)?$/', $n)) {
        return (float)$n > 0;
    }
    if (preg_match('/^([\d.,]+)\s*ลบ\.?$/u', $s, $m)) {
        return (float)str_replace(',', '', $m[1]) > 0;
    }
    return false;
}

$sql = 'SELECT o.id, o.user_id, o.code_list, o.owner_asking_price_enc, u.encryption_key
    FROM owners o JOIN users u ON u.id = o.user_id
    WHERE o.owner_asking_price_enc IS NOT NULL AND o.owner_asking_price_enc != ""';
if ($userId !== null) {
    $sql .= ' AND o.user_id = ?';
}
$sql .= ' ORDER BY o.user_id, o.code_list';

if ($userId !== null) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $userId);
} else {
    $stmt = $conn->prepare($sql);
}
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$cleared = [];
$kept = [];

foreach ($rows as $row) {
    $plain = decrypt_data($row['owner_asking_price_enc'], $row['encryption_key']) ?? '';
    if (is_valid_owner_asking_price($plain)) {
        $kept[] = [
            'code' => $row['code_list'],
            'value' => $plain,
        ];
        continue;
    }
    $cleared[] = [
        'id' => (int)$row['id'],
        'user_id' => (int)$row['user_id'],
        'code' => $row['code_list'],
        'was' => $plain !== '' ? $plain : '(decrypt empty)',
    ];
}

echo ($dryRun ? '[DRY RUN] ' : '') . 'clean owner_asking_price_enc' . ($userId !== null ? " user_id={$userId}" : ' (all users)') . "\n";
echo str_repeat('-', 80) . "\n";

if ($cleared) {
    echo "จะล้างเป็น NULL (" . count($cleared) . " รายการ):\n";
    foreach ($cleared as $c) {
        echo "  [{$c['code']}] was: {$c['was']}\n";
    }
} else {
    echo "ไม่มีรายการที่ต้องล้าง\n";
}

if ($kept) {
    echo "\nคงไว้ (" . count($kept) . " รายการ):\n";
    foreach ($kept as $k) {
        echo "  [{$k['code']}] {$k['value']}\n";
    }
}

if (!$dryRun && $cleared) {
    $upd = $conn->prepare('UPDATE owners SET owner_asking_price_enc = NULL WHERE id = ?');
    foreach ($cleared as $c) {
        $id = $c['id'];
        $upd->bind_param('i', $id);
        $upd->execute();
    }
    $upd->close();
    echo "\nล้างแล้ว " . count($cleared) . " รายการ\n";
}
