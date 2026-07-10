<?php
/**
 * ลบ Lead ทั้งหมดของ user (พร้อม matrix events, logs, tasks ที่ผูก lead_code)
 * Usage: php tools/purge_leads.php [user_id]
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/task_helpers.php';

$userId = (int)($argv[1] ?? 8);
if ($userId <= 0) {
    fwrite(STDERR, "Usage: php tools/purge_leads.php <user_id>\n");
    exit(1);
}

lead_stage_events_ensure_schema($conn);

$stmt = $conn->prepare('SELECT id, user_name FROM users WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user) {
    fwrite(STDERR, "user_id=$userId not found\n");
    exit(1);
}

function count_for_user(mysqli $conn, string $sql, int $userId): int {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $c = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
    return $c;
}

$before = [
    'leads' => count_for_user($conn, 'SELECT COUNT(*) c FROM leads WHERE user_id = ?', $userId),
    'stage_events' => count_for_user($conn, 'SELECT COUNT(*) c FROM lead_stage_events WHERE user_id = ?', $userId),
    'status_logs' => count_for_user($conn, 'SELECT COUNT(*) c FROM lead_status_logs WHERE user_id = ?', $userId),
    'lead_tasks' => count_for_user($conn, "SELECT COUNT(*) c FROM tasks WHERE user_id = ? AND lead_code IS NOT NULL AND lead_code != ''", $userId),
];

echo "=== Purge leads: {$user['user_name']} (user_id=$userId) ===\n";
echo "Before: leads={$before['leads']} stage_events={$before['stage_events']} status_logs={$before['status_logs']} lead_tasks={$before['lead_tasks']}\n";

$conn->begin_transaction();
try {
    $stmt = $conn->prepare("DELETE FROM tasks WHERE user_id = ? AND lead_code IS NOT NULL AND lead_code != ''");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $tasksDel = $stmt->affected_rows;
    $stmt->close();

    $stmt = $conn->prepare('DELETE FROM lead_stage_events WHERE user_id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $eventsDel = $stmt->affected_rows;
    $stmt->close();

    $stmt = $conn->prepare('DELETE FROM lead_status_logs WHERE user_id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $logsDel = $stmt->affected_rows;
    $stmt->close();

    $stmt = $conn->prepare('DELETE FROM leads WHERE user_id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $leadsDel = $stmt->affected_rows;
    $stmt->close();

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}

$after = [
    'leads' => count_for_user($conn, 'SELECT COUNT(*) c FROM leads WHERE user_id = ?', $userId),
    'stage_events' => count_for_user($conn, 'SELECT COUNT(*) c FROM lead_stage_events WHERE user_id = ?', $userId),
    'status_logs' => count_for_user($conn, 'SELECT COUNT(*) c FROM lead_status_logs WHERE user_id = ?', $userId),
    'lead_tasks' => count_for_user($conn, "SELECT COUNT(*) c FROM tasks WHERE user_id = ? AND lead_code IS NOT NULL AND lead_code != ''", $userId),
];

echo "Deleted: leads=$leadsDel stage_events=$eventsDel status_logs=$logsDel lead_tasks=$tasksDel\n";
echo "After:  leads={$after['leads']} stage_events={$after['stage_events']} status_logs={$after['status_logs']} lead_tasks={$after['lead_tasks']}\n";
echo "OK — พร้อม import Lead ใหม่ได้\n";
