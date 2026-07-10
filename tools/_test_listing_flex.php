<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/listing_flex_lib.php';

$uid = (int)($argv[1] ?? 8);
$text = $argv[2] ?? 'ขอเลขที่บ้าน โครงการ Pave รามอินทรา - วงแหวน';

$stmt = $conn->prepare('SELECT * FROM users WHERE id = ?');
$stmt->bind_param('i', $uid);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    fwrite(STDERR, "User {$uid} not found\n");
    exit(1);
}

$req = extract_listing_request($text);
echo "Request:\n";
var_export($req);
echo "\n\n";

$owners = search_listing_owners($conn, $user, $req);
echo 'Found: ' . count($owners) . "\n";
foreach ($owners as $o) {
    $f = listing_owner_fields($o, $user['encryption_key']);
    echo $f['property_code'] . ' | ' . $f['name_en'] . ' | ' . $f['house_no'] . "\n";
}

if (count($owners) > 0) {
    $bubbles = [];
    foreach ($owners as $owner) {
        $bubbles[] = listing_owner_fields($owner, $user['encryption_key']);
    }
    $flex = build_listing_flex_message(['bubbles' => $bubbles]);
    $size = listing_flex_payload_size($flex);
    $type = $flex['contents']['type'] ?? 'bubble';
    $cards = $type === 'carousel' ? count($flex['contents']['contents'] ?? []) : 1;
    echo "\nFlex: {$type}, cards={$cards}, json={$size} bytes\n";
    echo 'altText: ' . ($flex['altText'] ?? '') . "\n";
    echo ($size <= LISTING_FLEX_MAX_JSON_BYTES ? "OK under limit\n" : "TOO LARGE\n");
}
