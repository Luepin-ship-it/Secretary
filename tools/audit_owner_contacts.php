<?php
/**
 * ตรวจเบอร์/LINE/โซนที่คอลัมน์ผิด (shift จาก Excel)
 * Usage: php tools/audit_owner_contacts.php [user_id]
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/contact_normalize.php';

$userId = (int)($argv[1] ?? 8);
$stmt = $conn->prepare('SELECT id, encryption_key FROM users WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user) {
    fwrite(STDERR, "user not found\n");
    exit(1);
}
$key = $user['encryption_key'];

$stmt = $conn->prepare(
    'SELECT code_list, phone_enc, line_id_enc, zone_enc FROM owners WHERE user_id = ? ORDER BY code_list'
);
$stmt->bind_param('i', $userId);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$counts = [
    'zone_in_phone' => 0,
    'zone_in_line'  => 0,
    'phone_in_line' => 0,
    'bad_phone'     => 0,
    'bad_line'      => 0,
];

foreach ($rows as $r) {
    $phone = decrypt_data($r['phone_enc'], $key) ?: '';
    $line = decrypt_data($r['line_id_enc'], $key) ?: '';
    $zone = decrypt_data($r['zone_enc'], $key) ?: '';

    $issues = [];

    if ($phone !== '' && looks_like_zone_text($phone)) {
        $issues[] = "zone-in-phone=[$phone]";
        $counts['zone_in_phone']++;
    } elseif ($phone !== '' && normalize_phone_string($phone) === '') {
        $issues[] = "bad-phone=[$phone]";
        $counts['bad_phone']++;
    }

    if ($line !== '' && looks_like_zone_text($line)) {
        $issues[] = "zone-in-line=[$line]";
        $counts['zone_in_line']++;
    } elseif ($line !== '' && normalize_line_id_string($line) === '') {
        $issues[] = "bad-line=[$line]";
        $counts['bad_line']++;
    }

    $lineDigits = preg_replace('/\D/', '', $line);
    if ($line !== '' && looks_like_thai_mobile_digits($lineDigits) && strlen($lineDigits) >= 9) {
        $issues[] = "phone-in-line=[$line]";
        $counts['phone_in_line']++;
    }

    if ($issues) {
        $zoneHint = $zone !== '' ? " (zone=[$zone])" : '';
        echo $r['code_list'] . ': ' . implode('; ', $issues) . $zoneHint . "\n";
    }
}

echo "--- user_id=$userId / " . count($rows) . " owners ---\n";
foreach ($counts as $k => $n) {
    if ($n > 0) {
        echo "  $k: $n\n";
    }
}
$total = array_sum($counts);
echo $total === 0 ? "OK — no contact issues\n" : "Total issues: $total\n";
