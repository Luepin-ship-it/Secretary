<?php
/**
 * ทดสอบแจ้ง Lead ซ้ำ (Flex) ไป LINE ของ user
 * php tools/_test_lead_duplicate_line.php [user_id] [--dry]
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/task_helpers.php';
require_once dirname(__DIR__) . '/lib/lead_customer_group.php';

$uid = (int)($argv[1] ?? 8);
$dry = in_array('--dry', $argv, true);

lead_customer_group_ensure_schema($conn);

$stmt = $conn->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $uid);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    fwrite(STDERR, "User {$uid} not found\n");
    exit(1);
}

$key = $user['encryption_key'];
$lineUid = trim($user['line_user_id'] ?? '');
echo 'User: ' . ($user['user_name'] ?? '') . " (id={$uid})\n";
echo 'LINE: ' . ($lineUid ?: '(none)') . "\n\n";

$synced = lead_customer_group_backfill_user($conn, $uid, $key);
echo "Backfill synced rows: {$synced}\n";

$stmt = $conn->prepare(
    'SELECT phone_norm_hash, COUNT(*) c, GROUP_CONCAT(id ORDER BY id) ids
     FROM leads WHERE user_id = ? AND phone_norm_hash IS NOT NULL AND phone_norm_hash != \'\'
     GROUP BY phone_norm_hash HAVING c > 1 ORDER BY c DESC LIMIT 1'
);
$stmt->bind_param('i', $uid);
$stmt->execute();
$dup = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$dup) {
    echo "No duplicate phone groups found — using first two leads with phones for mock.\n";
    $stmt = $conn->prepare(
        'SELECT * FROM leads WHERE user_id = ? AND phone_enc IS NOT NULL AND phone_enc != \'\' ORDER BY id ASC LIMIT 2'
    );
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if (count($rows) < 2) {
        fwrite(STDERR, "Need at least 2 leads with phone numbers.\n");
        exit(1);
    }
    $matchedRow = $rows[0];
    $incomingRow = $rows[1];
    $phone = decrypt_data($matchedRow['phone_enc'], $key) ?: '0812345678';
} else {
    $ids = array_map('intval', explode(',', $dup['ids']));
    $stmt = $conn->prepare('SELECT * FROM leads WHERE user_id = ? AND id IN (?, ?) ORDER BY id ASC');
    $a = $ids[0];
    $b = $ids[1];
    $stmt->bind_param('iii', $uid, $a, $b);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $matchedRow = $rows[0];
    $incomingRow = $rows[1];
    $phone = decrypt_data($matchedRow['phone_enc'], $key) ?: '';
    echo "Found duplicate hash group ({$dup['c']} leads): {$dup['ids']}\n";
}

$matched = lead_row_brief($matchedRow, $key);
$incoming = lead_row_brief($incomingRow, $key);
$phoneDisplay = lead_format_phone_display($phone);
$sizes = lead_customer_group_sizes($conn, $uid);
$gid = lead_effective_group_id($matchedRow);
$groupSize = $sizes[$gid] ?? 2;

echo "\nMock duplicate alert:\n";
echo "  Phone: {$phoneDisplay}\n";
echo "  Matched: {$matched['name']} · {$matched['lead_code']} · {$matched['owner_code']}\n";
echo "  Incoming: {$incoming['name']} · {$incoming['lead_code']} · {$incoming['owner_code']}\n";
echo "  Group size: {$groupSize}\n\n";

$flex = lead_build_duplicate_flex($matched, $incoming, $phoneDisplay, $groupSize);
echo 'Flex altText: ' . ($flex['altText'] ?? '') . "\n";

if ($dry) {
    echo "\n--dry: not sending to LINE.\n";
    exit(0);
}

if ($lineUid === '') {
    fwrite(STDERR, "No line_user_id on user {$uid}\n");
    exit(1);
}

$http = lead_line_push_messages($lineUid, [$flex]);
echo "LINE push HTTP: {$http}\n";
exit($http >= 200 && $http < 300 ? 0 : 1);
