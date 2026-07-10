<?php
require_once dirname(__DIR__) . '/config.php';

$userId = isset($argv[1]) ? (int)$argv[1] : 0; // 0 = all users' owners/leads

function count_table(mysqli $conn, string $table, int $userId = 0): int {
    if ($userId > 0 && in_array($table, ['owners', 'leads', 'tasks', 'lead_status_logs', 'owner_contacts', 'owner_price_logs'], true)) {
        $stmt = $conn->prepare("SELECT COUNT(*) c FROM $table WHERE user_id = ?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $c = (int)$stmt->get_result()->fetch_assoc()['c'];
        $stmt->close();
        return $c;
    }
    return (int)$conn->query("SELECT COUNT(*) c FROM $table")->fetch_assoc()['c'];
}

echo "=== Before ===\n";
$tables = ['owners', 'leads', 'tasks', 'lead_status_logs'];
foreach ($tables as $t) {
    echo "$t: " . count_table($conn, $t, $userId) . "\n";
}

if ($userId > 0) {
    $stmt = $conn->prepare('DELETE FROM leads WHERE user_id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $leadsDel = $stmt->affected_rows;
    $stmt->close();

    $stmt = $conn->prepare('DELETE FROM owners WHERE user_id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $ownersDel = $stmt->affected_rows;
    $stmt->close();

    echo "\nDeleted for user_id=$userId: owners=$ownersDel leads=$leadsDel\n";
} else {
    $conn->query('DELETE FROM leads');
    $leadsDel = $conn->affected_rows;
    $conn->query('DELETE FROM owners');
    $ownersDel = $conn->affected_rows;
    echo "\nDeleted ALL: owners=$ownersDel leads=$leadsDel\n";
}

echo "\n=== After ===\n";
foreach ($tables as $t) {
    echo "$t: " . count_table($conn, $t, $userId) . "\n";
}
