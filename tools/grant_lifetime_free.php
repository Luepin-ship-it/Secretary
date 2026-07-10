<?php
/**
 * ตั้งสิทธิ์ใช้ฟรีตลอดชีพ
 * Usage: php tools/grant_lifetime_free.php [user_id]
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/subscription.php';

$userId = (int)($argv[1] ?? 8);

subscription_ensure_schema($conn);

$stmt = $conn->prepare('SELECT id, user_name, is_subscribed, is_lifetime_free FROM users WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    fwrite(STDERR, "user_id=$userId not found\n");
    exit(1);
}

if (!grant_lifetime_free($conn, $userId)) {
    fwrite(STDERR, "update failed\n");
    exit(1);
}

echo "OK: {$user['user_name']} (id=$userId) → Free ตลอดชีพ\n";
echo "  is_subscribed={$user['is_subscribed']} is_lifetime_free=1\n";
