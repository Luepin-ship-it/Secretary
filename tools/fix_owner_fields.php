<?php
/**
 * แก้ฟิลด์ owner ใน DB ที่ import ก่อนแก้ normalize/repair
 *
 * Usage: php tools/fix_owner_fields.php [--user-id=8] [--dry-run]
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/owner_field_normalize.php';

$userId = 8;
$dryRun = in_array('--dry-run', $argv, true);
foreach ($argv as $arg) {
    if (strpos($arg, '--user-id=') === 0) {
        $userId = (int)substr($arg, 10);
    }
}

$stmt = $conn->prepare('SELECT id, encryption_key FROM users WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    fwrite(STDERR, "User $userId not found\n");
    exit(1);
}

$key = $user['encryption_key'];
$stmt = $conn->prepare('SELECT id, code_list, asking_price_enc, direction_enc, rental_price_enc, unit_no_enc FROM owners WHERE user_id = ?');
$stmt->bind_param('i', $userId);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$updated = 0;
foreach ($rows as $row) {
    $before = [
        'asking_price' => decrypt_data($row['asking_price_enc'], $key) ?: '',
        'direction'    => decrypt_data($row['direction_enc'], $key) ?: '',
        'rental_price' => decrypt_data($row['rental_price_enc'], $key) ?: '',
        'unit_no'      => decrypt_data($row['unit_no_enc'], $key) ?: '',
    ];
    $after = repair_shifted_owner_fields($before);

    $changed = false;
    foreach (['asking_price', 'direction', 'rental_price', 'unit_no'] as $f) {
        if ($before[$f] !== $after[$f]) {
            $changed = true;
            break;
        }
    }
    if (!$changed) {
        continue;
    }

    echo "{$row['code_list']}: ";
    foreach (['asking_price', 'direction', 'rental_price', 'unit_no'] as $f) {
        if ($before[$f] !== $after[$f]) {
            echo "$f [{$before[$f]}] -> [{$after[$f]}]; ";
        }
    }
    echo "\n";

    if ($dryRun) {
        $updated++;
        continue;
    }

    $askEnc = $after['asking_price'] !== '' ? encrypt_data($after['asking_price'], $key) : null;
    $dirEnc = $after['direction'] !== '' ? encrypt_data($after['direction'], $key) : null;
    $rentEnc = $after['rental_price'] !== '' ? encrypt_data($after['rental_price'], $key) : null;
    $unitEnc = $after['unit_no'] !== '' ? encrypt_data($after['unit_no'], $key) : null;

    $upd = $conn->prepare('UPDATE owners SET asking_price_enc=?, direction_enc=?, rental_price_enc=?, unit_no_enc=? WHERE id=?');
    $upd->bind_param('ssssi', $askEnc, $dirEnc, $rentEnc, $unitEnc, $row['id']);
    $upd->execute();
    $upd->close();
    $updated++;
}

echo ($dryRun ? 'Would update' : 'Updated') . " $updated rows for user_id=$userId\n";
