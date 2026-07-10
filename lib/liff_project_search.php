<?php
/**
 * LIFF — ค้นหาชื่อโครงการ (Google Places) + คัดลอก / ส่งกลับแชท
 */

function liff_project_search_id(): string
{
    if (defined('LINE_LIFF_PROJECT_SEARCH_ID') && LINE_LIFF_PROJECT_SEARCH_ID !== '') {
        return trim(LINE_LIFF_PROJECT_SEARCH_ID);
    }
    return '';
}

function liff_project_search_enabled(): bool
{
    return liff_project_search_id() !== '';
}

function liff_project_search_url(): string
{
    $id = liff_project_search_id();
    return $id !== '' ? 'https://liff.line.me/' . $id : '';
}

/** @return array<string,mixed>|null */
function liff_project_search_line_message(): ?array
{
    if (!liff_project_search_enabled()) {
        return null;
    }
    return [
        'type' => 'template',
        'altText' => 'ค้นหาชื่อโครงการ',
        'template' => [
            'type' => 'buttons',
            'text' => "🔍 ไม่แน่ใจชื่อโครงการ?\nเปิดค้นหา → เลือก → คัดลอกหรือส่งกลับแชท",
            'actions' => [[
                'type' => 'uri',
                'label' => 'ค้นหาชื่อโครงการ',
                'uri' => liff_project_search_url(),
            ]],
        ],
    ];
}

/** @return array<string,mixed>|null */
function liff_verify_access_token(string $token): ?array
{
    $token = trim($token);
    if ($token === '') {
        return null;
    }
    $url = 'https://api.line.me/oauth2/v2.1/verify?access_token=' . rawurlencode($token);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !is_string($body)) {
        return null;
    }
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

function project_name_format_pair(string $th, string $en): string
{
    $th = trim($th);
    $en = trim($en);
    if ($th === '' && $en === '') {
        return '';
    }
    if ($en === '' || strcasecmp($th, $en) === 0) {
        return $th;
    }
    if ($th === '') {
        return $en;
    }
    return $th . ' / ' . $en;
}

/** @return list<array{place_id:string,name_th:string,name_en:string,address:string}> */
function project_name_places_search(string $query, int $limit = 6): array
{
    $query = trim($query);
    $limit = max(1, min(8, $limit));
    if ($query === '') {
        return [];
    }

    $key = defined('GOOGLE_MAPS_API_KEY') ? trim(GOOGLE_MAPS_API_KEY) : '';
    if ($key === '') {
        return [];
    }

    $searchUrl = 'https://maps.googleapis.com/maps/api/place/textsearch/json?' . http_build_query([
        'query' => $query . ' โครงการ หมู่บ้าน',
        'language' => 'th',
        'region' => 'th',
        'key' => $key,
    ]);

    $payload = project_name_http_json($searchUrl);
    if (!$payload || ($payload['status'] ?? '') !== 'OK' || empty($payload['results'])) {
        return [];
    }

    $out = [];
    foreach (array_slice($payload['results'], 0, $limit) as $row) {
        $placeId = (string)($row['place_id'] ?? '');
        if ($placeId === '') {
            continue;
        }
        $nameTh = trim((string)($row['name'] ?? ''));
        $address = trim((string)($row['formatted_address'] ?? ''));
        $nameEn = project_name_place_detail_name($placeId, 'en', $key);
        if ($nameEn === '') {
            $nameEn = $nameTh;
        }
        $out[] = [
            'place_id' => $placeId,
            'name_th' => $nameTh,
            'name_en' => $nameEn,
            'address' => $address,
            'formatted' => project_name_format_pair($nameTh, $nameEn),
        ];
    }

    return $out;
}

function project_name_place_detail_name(string $placeId, string $lang, string $key): string
{
    $url = 'https://maps.googleapis.com/maps/api/place/details/json?' . http_build_query([
        'place_id' => $placeId,
        'fields' => 'name',
        'language' => $lang,
        'key' => $key,
    ]);
    $payload = project_name_http_json($url);
    if (!$payload || ($payload['status'] ?? '') !== 'OK') {
        return '';
    }
    return trim((string)($payload['result']['name'] ?? ''));
}

/** @return array<string,mixed>|null */
function project_name_http_json(string $url): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    if (!is_string($body) || $body === '') {
        return null;
    }
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}
