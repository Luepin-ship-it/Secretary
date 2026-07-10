<?php
/**
 * LIFF — ยืนยัน access token + ผูก user ในระบบ
 */

require_once __DIR__ . '/liff_project_search.php';

/** @return array{userId:string,displayName?:string}|null */
function liff_profile_from_token(string $token): ?array
{
    $token = trim($token);
    if ($token === '') {
        return null;
    }
    if (!liff_verify_access_token($token)) {
        return null;
    }
    $ch = curl_init('https://api.line.me/v2/profile');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !is_string($body)) {
        return null;
    }
    $data = json_decode($body, true);
    if (!is_array($data) || empty($data['userId'])) {
        return null;
    }
    return $data;
}

/** @return array<string,mixed>|null */
function liff_auth_user(mysqli $conn, string $token): ?array
{
    $profile = liff_profile_from_token($token);
    if (!$profile) {
        return null;
    }
    $lineUid = $profile['userId'];
    $stmt = $conn->prepare('SELECT * FROM users WHERE line_user_id = ? LIMIT 1');
    $stmt->bind_param('s', $lineUid);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $user ?: null;
}

function liff_bearer_token_from_request(): string
{
    if (!empty($_SERVER['HTTP_AUTHORIZATION']) && preg_match('/Bearer\s+(\S+)/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
        return $m[1];
    }
    if (!empty($_GET['access_token'])) {
        return (string)$_GET['access_token'];
    }
    return '';
}
