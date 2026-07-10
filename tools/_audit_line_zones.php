<?php
require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/contact_normalize.php';

$userId = (int)($argv[1] ?? 8);

$s = $conn->prepare('SELECT encryption_key FROM users WHERE id=?');
$s->bind_param('i', $userId);
$s->execute();
$key = $s->get_result()->fetch_assoc()['encryption_key'];
$s->close();

$q = $conn->prepare('SELECT code_list, line_id_enc, zone_enc, phone_enc, owner_name_enc FROM owners WHERE user_id=?');
$q->bind_param('i', $userId);
$q->execute();
$rows = $q->get_result()->fetch_all(MYSQLI_ASSOC);
$q->close();

$zoneInLine = 0;
$zoneInPhone = 0;
$phoneInLine = 0;

foreach ($rows as $r) {
    $line = decrypt_data($r['line_id_enc'], $key) ?: '';
    $zone = decrypt_data($r['zone_enc'], $key) ?: '';
    $phone = decrypt_data($r['phone_enc'], $key) ?: '';
    $name = decrypt_data($r['owner_name_enc'], $key) ?: '';

    if ($line !== '' && looks_like_zone_text($line)) {
        echo "ZONE-IN-LINE {$r['code_list']} ({$name}): line=[{$line}] zone=[{$zone}]\n";
        $zoneInLine++;
    }
    if ($phone !== '' && looks_like_zone_text($phone)) {
        echo "ZONE-IN-PHONE {$r['code_list']}: phone=[{$phone}]\n";
        $zoneInPhone++;
    }
    if ($line !== '' && preg_match('/^0\d{8,9}$/', preg_replace('/\D/', '', $line))) {
        echo "PHONE-IN-LINE {$r['code_list']}: line=[{$line}]\n";
        $phoneInLine++;
    }
}

echo "\nSummary user_id={$userId}: zone-in-line={$zoneInLine}, zone-in-phone={$zoneInPhone}, phone-in-line={$phoneInLine}, total=" . count($rows) . "\n";
