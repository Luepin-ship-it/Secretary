<?php
/**
 * แกะ lat/lng จากลิงก์ Google Maps และบันทึกลง owners (+ sync leads)
 */

function map_coords_valid($lat, $lng): bool
{
    return $lat !== null && $lng !== null && (float)$lat != 0 && (float)$lng != 0;
}

function map_coords_sane(float $lat, float $lng): bool
{
    return $lat >= -90.0 && $lat <= 90.0 && $lng >= -180.0 && $lng <= 180.0;
}

function map_is_google_maps_url(string $url): bool
{
    $url = strtolower(trim($url));
    if ($url === '') {
        return false;
    }
    return (bool)preg_match(
        '#^(https?://)?((maps\.app\.)?goo\.gl|(www\.)?(google\.|maps\.google\.))#i',
        $url
    );
}

/** ลองดึงพิกัดจากข้อความ URL (ไม่ follow redirect) — ให้ !3d!4d (หมุด) ก่อน @ (กล้องแผนที่) */
function map_extract_coords_from_text(string $text): ?array
{
    $text = urldecode($text);
    $pinPatterns = [
        '/!3d(-?\d+(?:\.\d+)?)!4d(-?\d+(?:\.\d+)?)/',
        '/!8m2!3d(-?\d+(?:\.\d+)?)!4d(-?\d+(?:\.\d+)?)/',
    ];
    foreach ($pinPatterns as $pattern) {
        if (preg_match($pattern, $text, $m)) {
            $lat = (float)$m[1];
            $lng = (float)$m[2];
            if (map_coords_sane($lat, $lng)) {
                return [$lat, $lng];
            }
        }
    }
    $fallbackPatterns = [
        '/[?&](?:q|query|ll|center|destination|daddr|saddr|cbll)=(-?\d+(?:\.\d+)?)[,%2C\s\+]+(-?\d+(?:\.\d+)?)/i',
        '~\/(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)(?:[/?#]|$)~',
        '/@(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/',
    ];
    foreach ($fallbackPatterns as $pattern) {
        if (preg_match($pattern, $text, $m)) {
            $lat = (float)$m[1];
            $lng = (float)$m[2];
            if (map_coords_sane($lat, $lng)) {
                return [$lat, $lng];
            }
        }
    }
    return null;
}

/** ขยายลิงก์สั้น (goo.gl / maps.app.goo.gl) ให้ได้ URL ปลายทาง */
function map_expand_maps_url(string $url): string
{
    $url = trim($url);
    if ($url === '' || !map_is_google_maps_url($url)) {
        return $url;
    }
    if (map_extract_coords_from_text($url)) {
        return $url;
    }
    if (!function_exists('curl_init')) {
        return $url;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return $url;
    }
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 6,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; AntigravityMap/1.0)',
        CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml'],
    ]);
    curl_exec($ch);
    $final = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $err = curl_errno($ch);
    curl_close($ch);

    return ($err === 0 && $final !== '') ? $final : $url;
}

/** ดึง lat,lng จากลิงก์ Google Maps */
function map_parse_coords_from_url(string $url, bool $followRedirects = true): ?array
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }

    $candidates = [$url];
    if ($followRedirects && map_is_google_maps_url($url)) {
        $expanded = map_expand_maps_url($url);
        if ($expanded !== '' && $expanded !== $url) {
            $candidates[] = $expanded;
        }
    }

    foreach ($candidates as $candidate) {
        $coords = map_extract_coords_from_text($candidate);
        if ($coords) {
            return $coords;
        }
    }
    return null;
}

/** อัปเดต lat/lng ของ owner จาก map_url (plain text) */
function owner_apply_map_coords(mysqli $conn, int $userId, int $ownerId, string $codeList, string $mapUrlPlain): bool
{
    $mapUrlPlain = trim($mapUrlPlain);
    $lat = null;
    $lng = null;
    if ($mapUrlPlain !== '') {
        $parsed = map_parse_coords_from_url($mapUrlPlain);
        if ($parsed) {
            [$lat, $lng] = $parsed;
        }
    }

    $stmt = $conn->prepare('UPDATE owners SET lat = ?, lng = ? WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->bind_param('ddii', $lat, $lng, $ownerId, $userId);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok && $codeList !== '') {
        owner_sync_lead_coords($conn, $userId, $codeList, $lat, $lng);
    }
    return $ok;
}

/** คัดลอกพิกัดไป leads ที่ผูก owner_code นี้ */
function owner_sync_lead_coords(mysqli $conn, int $userId, string $codeList, ?float $lat, ?float $lng): void
{
    if ($codeList === '') {
        return;
    }
    $stmt = $conn->prepare('UPDATE leads SET lat = ?, lng = ? WHERE user_id = ? AND owner_code = ?');
    $stmt->bind_param('ddis', $lat, $lng, $userId, $codeList);
    $stmt->execute();
    $stmt->close();
}

/** คืน [lat, lng] สำหรับ bind_param ตอน import (null ถ้าแกะไม่ได้) */
function map_coords_for_import(string $mapUrlPlain): array
{
    $parsed = map_parse_coords_from_url($mapUrlPlain);
    if (!$parsed) {
        return [null, null];
    }
    return [$parsed[0], $parsed[1]];
}
