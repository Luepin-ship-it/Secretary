<?php
/**
 * แก้เบอร์/LINE ใน DB (E-notation, 5.0→50, zone ในเบอร์, handle สลับคอลัมน์)
 * Usage: php tools/fix_owner_phones.php [user_id] [--dry-run]
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/contact_normalize.php';

$userId = isset($argv[1]) && strpos($argv[1], '--') !== 0 ? (int)$argv[1] : 8;
$dryRun = in_array('--dry-run', $argv, true);

$stmt = $conn->prepare('SELECT encryption_key FROM users WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user) {
    fwrite(STDERR, "User $userId not found\n");
    exit(1);
}
$key = $user['encryption_key'];

$q = $conn->prepare('SELECT id, code_list, phone_enc, line_id_enc, zone_enc FROM owners WHERE user_id = ?');
$q->bind_param('i', $userId);
$q->execute();
$rows = $q->get_result()->fetch_all(MYSQLI_ASSOC);
$q->close();

$upd = $conn->prepare('UPDATE owners SET phone_enc = ?, line_id_enc = ?, zone_enc = ? WHERE id = ?');
$fixed = 0;

foreach ($rows as $row) {
    $phoneRaw = decrypt_data($row['phone_enc'], $key) ?: '';
    $lineRaw = decrypt_data($row['line_id_enc'], $key) ?: '';
    $zoneRaw = decrypt_data($row['zone_enc'], $key) ?: '';
    $repaired = repair_owner_contacts([
        'phone'   => $phoneRaw,
        'line_id' => $lineRaw,
        'zone'    => $zoneRaw,
    ]);

    $phoneChanged = $phoneRaw !== $repaired['phone'];
    $lineChanged = $lineRaw !== $repaired['line_id'];
    $zoneChanged = $zoneRaw !== ($repaired['zone'] ?? $zoneRaw);
    if (!$phoneChanged && !$lineChanged && !$zoneChanged) {
        continue;
    }

    $zoneOut = $repaired['zone'] ?? $zoneRaw;
    echo "{$row['code_list']}: phone [{$phoneRaw}] -> [{$repaired['phone']}]; "
        . "line [{$lineRaw}] -> [{$repaired['line_id']}]; zone [{$zoneRaw}] -> [{$zoneOut}]\n";

    if ($dryRun) {
        $fixed++;
        continue;
    }

    $phoneEnc = $repaired['phone'] !== '' ? encrypt_data($repaired['phone'], $key) : null;
    $lineEnc = $repaired['line_id'] !== '' ? encrypt_data($repaired['line_id'], $key) : null;
    $zoneEnc = $zoneOut !== '' ? encrypt_data($zoneOut, $key) : null;
    $upd->bind_param('sssi', $phoneEnc, $lineEnc, $zoneEnc, $row['id']);
    $upd->execute();
    $fixed++;
}

echo ($dryRun ? 'Would fix' : 'Fixed') . " $fixed / " . count($rows) . " owners for user_id=$userId\n";
