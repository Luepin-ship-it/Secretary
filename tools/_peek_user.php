<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/auth.php';

$id = (int)($argv[1] ?? 8);
$stmt = $conn->prepare('SELECT id, user_name, line_user_id FROM users WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$u = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$u) {
    echo "user not found\n";
    exit(1);
}

echo "id: {$u['id']}\n";
echo "name: {$u['user_name']}\n";
echo "line_user_id: {$u['line_user_id']}\n";
echo "dashboard (login): " . auth_base_url() . "/dashboard.php\n";
echo "dev preview (localhost): http://localhost/Ai%20agent%20Line%20OA/_dev_preview.php?user_id={$id}\n";
