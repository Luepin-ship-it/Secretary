<?php
/**
 * ดึง cover จาก photos_link (โฟลเดอร์ Drive) แล้วอัปเดต cover_image_url
 *
 * Usage: php tools/resolve_owner_covers.php --line-id=Uxxx [--dry-run]
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/gdrive_cover_resolver.php';

$dryRun = in_array('--dry-run', $argv, true);
$lineId = '';
foreach ($argv as $arg) {
    if (strpos($arg, '--line-id=') === 0) {
        $lineId = substr($arg, 10);
    }
}
if ($lineId === '') {
    fwrite(STDERR, "Usage: php tools/resolve_owner_covers.php --line-id=Uxxxx [--dry-run]\n");
    exit(1);
}

$stmt = $conn->prepare('SELECT id, encryption_key FROM users WHERE line_user_id = ? LIMIT 1');
$stmt->bind_param('s', $lineId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user) {
    fwrite(STDERR, "User not found\n");
    exit(1);
}

$userId = (int)$user['id'];
$key = $user['encryption_key'];
$apiKey = defined('GOOGLE_DRIVE_API_KEY') ? trim((string)GOOGLE_DRIVE_API_KEY) : '';

$q = $conn->prepare('SELECT id, code_list, photos_link_enc, cover_image_url FROM owners WHERE user_id = ?');
$q->bind_param('i', $userId);
$q->execute();
$rows = $q->get_result()->fetch_all(MYSQLI_ASSOC);
$q->close();

$resolved = 0;
$skipped = 0;
$failed = 0;

foreach ($rows as $row) {
    $photos = trim(decrypt_data($row['photos_link_enc'], $key) ?? '');
    if ($photos === '') {
        $skipped++;
        continue;
    }

    $cover = GdriveCoverResolver::resolveAtImport($photos, $row['code_list'], null, $apiKey !== '' ? $apiKey : null);
    if ($cover === null) {
        $failed++;
        continue;
    }

    $resolved++;
    if ($dryRun) {
        echo "{$row['code_list']} => $cover\n";
        continue;
    }

    $upd = $conn->prepare('UPDATE owners SET cover_image_url = ? WHERE id = ?');
    $upd->bind_param('si', $cover, $row['id']);
    $upd->execute();
    $upd->close();
    usleep(150000);
}

echo ($dryRun ? 'Dry-run: ' : '') . "resolved=$resolved skipped=$skipped failed=$failed total=" . count($rows) . "\n";
