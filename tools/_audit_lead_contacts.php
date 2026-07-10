<?php
require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/contact_normalize.php';

$userId = (int)($argv[1] ?? 8);
$s = $conn->prepare('SELECT encryption_key FROM users WHERE id=?');
$s->bind_param('i', $userId);
$s->execute();
$key = $s->get_result()->fetch_assoc()['encryption_key'];
$s->close();

$q = $conn->prepare('SELECT lead_code, phone_enc, line_id_enc FROM leads WHERE user_id=?');
$q->bind_param('i', $userId);
$q->execute();
$rows = $q->get_result()->fetch_all(MYSQLI_ASSOC);
$q->close();

$bad = 0;
foreach ($rows as $r) {
    $line = decrypt_data($r['line_id_enc'], $key) ?: '';
    if ($line !== '' && (looks_like_zone_text($line) || normalize_line_id_string($line) === '')) {
        echo "{$r['lead_code']}: [{$line}]\n";
        $bad++;
    }
}
echo $bad === 0
    ? "leads OK — {$bad} issues / " . count($rows) . "\n"
    : "leads bad line: $bad / " . count($rows) . "\n";
