<?php
/**
 * API อัปโหลด/เรียง/ตั้งชื่อรูปทรัพย์ (LIFF)
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/liff_auth.php';
require_once __DIR__ . '/lib/owner_photos_store.php';

global $conn;
if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db'], JSON_UNESCAPED_UNICODE);
    exit;
}
owner_photos_ensure_schema($conn);

$token = liff_bearer_token_from_request();
$user = $token !== '' ? liff_auth_user($conn, $token) : null;
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int)$user['id'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));
$code = owner_photos_sanitize_code((string)($_GET['code'] ?? $_POST['code'] ?? ''));

if ($code === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_code'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'GET' && ($action === '' || $action === 'list')) {
    echo json_encode([
        'ok' => true,
        'photos' => owner_photos_list($conn, $userId, $code),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'POST' && $action === 'upload') {
    if (!isset($_FILES['photo'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'no_file'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = owner_photos_upload($conn, $userId, $code, $_FILES['photo']);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'POST' && $action === 'reorder') {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw ?: '{}', true);
    $ids = $body['order'] ?? [];
    if (!is_array($ids)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_order'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $ok = owner_photos_reorder($conn, $userId, $code, array_map('intval', $ids));
    echo json_encode(['ok' => $ok, 'photos' => owner_photos_list($conn, $userId, $code)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'POST' && $action === 'delete') {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw ?: '{}', true);
    $id = (int)($body['id'] ?? 0);
    $ok = $id > 0 && owner_photos_delete_row($conn, $userId, $code, $id);
    echo json_encode(['ok' => $ok, 'photos' => owner_photos_list($conn, $userId, $code)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'POST' && $action === 'finalize') {
    $result = owner_photos_finalize($conn, $userId, $code);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'unknown_action'], JSON_UNESCAPED_UNICODE);
