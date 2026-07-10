<?php
/**
 * CLI: แกะ lat/lng จาก map_url_enc ของ owners ที่มีอยู่แล้ว
 *
 * Usage:
 *   php tools/backfill_owner_coords.php [--user-id=8] [--dry-run]
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/map_coords.php';

$userId = 0;
$dryRun = in_array('--dry-run', $argv, true);
foreach ($argv as $arg) {
    if (strpos($arg, '--user-id=') === 0) {
        $userId = (int)substr($arg, 10);
    }
}

$sql = 'SELECT o.id, o.user_id, o.code_list, o.map_url_enc, u.encryption_key
        FROM owners o
        INNER JOIN users u ON u.id = o.user_id
        WHERE o.map_url_enc IS NOT NULL AND o.map_url_enc != \'\'';
if ($userId > 0) {
    $sql .= ' AND o.user_id = ' . (int)$userId;
}
$sql .= ' ORDER BY o.user_id, o.id';

$res = $conn->query($sql);
if (!$res) {
    fwrite(STDERR, "Query failed\n");
    exit(1);
}

$total = 0;
$parsed = 0;
$updated = 0;
$leadsSynced = 0;

while ($row = $res->fetch_assoc()) {
    $total++;
    $mapUrl = decrypt_data($row['map_url_enc'], $row['encryption_key']);
    $coords = map_parse_coords_from_url($mapUrl);
    if (!$coords) {
        continue;
    }
    $parsed++;
    [$lat, $lng] = $coords;
    echo sprintf(
        "  %s (user %d): %.5f, %.5f\n",
        $row['code_list'],
        $row['user_id'],
        $lat,
        $lng
    );

    if ($dryRun) {
        continue;
    }

    $stmt = $conn->prepare('UPDATE owners SET lat = ?, lng = ? WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->bind_param('ddii', $lat, $lng, $row['id'], $row['user_id']);
    if ($stmt->execute()) {
        $updated++;
    }
    $stmt->close();

    owner_sync_lead_coords($conn, (int)$row['user_id'], (string)$row['code_list'], $lat, $lng);
    $leadsSynced += $conn->affected_rows;
}

echo "\nOwners with map_url: $total\n";
echo "Parsed coords: $parsed\n";
if ($dryRun) {
    echo "Dry run — no DB changes\n";
} else {
    echo "Owners updated: $updated\n";
    echo "Lead rows synced: $leadsSynced\n";
}
