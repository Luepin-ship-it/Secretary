<?php
/**
 * API ค้นหาชื่อโครงการ (LIFF) — Google Places Text Search
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/liff_project_search.php';

$token = '';
if (!empty($_SERVER['HTTP_AUTHORIZATION']) && preg_match('/Bearer\s+(\S+)/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
    $token = $m[1];
} elseif (!empty($_GET['access_token'])) {
    $token = (string)$_GET['access_token'];
}

if ($token !== '' && !liff_verify_access_token($token)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid_token'], JSON_UNESCAPED_UNICODE);
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) {
    echo json_encode(['ok' => false, 'error' => 'query_too_short'], JSON_UNESCAPED_UNICODE);
    exit;
}

$key = defined('GOOGLE_MAPS_API_KEY') ? trim(GOOGLE_MAPS_API_KEY) : '';
if ($key === '') {
    echo json_encode(['ok' => false, 'error' => 'maps_key_missing'], JSON_UNESCAPED_UNICODE);
    exit;
}

$results = project_name_places_search($q, 6);
echo json_encode(['ok' => true, 'results' => $results], JSON_UNESCAPED_UNICODE);
