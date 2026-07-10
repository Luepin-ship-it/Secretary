<?php
/**
 * LINE Flex — การ์ดทรัพย์จาก Flex listing.json
 * รองรับ: ขอเลขที่บ้าน + รหัสทรัพย์ / ชื่อโครงการ / ชื่อ Owner (carousel เมื่อหลายรายการ)
 */

require_once __DIR__ . '/contact_normalize.php';
require_once __DIR__ . '/owner_field_normalize.php';
require_once __DIR__ . '/gdrive_cover.php';
require_once __DIR__ . '/line_messaging.php';
require_once __DIR__ . '/flex_theme.php';
require_once __DIR__ . '/owner_line_photo_flow.php';

const LISTING_FLEX_MAX_CAROUSEL = 5;
const LISTING_FLEX_MAX_JSON_BYTES = 48000;
const LISTING_COVER_FALLBACK = 'https://developers-resource.landpress.line.me/fx/clip/clip4.jpg';

function listing_normalize_text(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    $s = str_replace(['เพฟ', 'พave'], 'pave', $s);
    $s = preg_replace('/[\s\-–—_·]+/u', '', $s);
    $s = str_replace(['โครงการ', 'project'], '', $s);
    return $s;
}

function listing_public_base_url(): string
{
    if (function_exists('build_public_base_url')) {
        return build_public_base_url();
    }
    if (function_exists('auth_base_url')) {
        return auth_base_url();
    }
    return '';
}

/** LINE Flex ต้องเป็น URL สมบูรณ์ https:// */
function listing_absolute_https_url(?string $url, string $fallback = ''): string
{
    $url = trim((string)$url);
    if ($url === '') {
        return $fallback;
    }

    if (!preg_match('#^https?://#i', $url)) {
        $base = listing_public_base_url();
        if ($base === '') {
            return $fallback !== '' ? $fallback : LISTING_COVER_FALLBACK;
        }
        $url = rtrim($base, '/') . '/' . ltrim($url, '/');
    }

    if (stripos($url, 'http://') === 0) {
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        $is_local = $host === 'localhost' || $host === '127.0.0.1';
        if (!$is_local) {
            $url = 'https://' . substr($url, 7);
        }
    }

    return $url;
}

function listing_normalize_owner_name(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = preg_replace('/^(คุณ|คุ|พี่|น้อง|น\\.?|ป้า|ลุง|อา|mr\\.?|mrs\\.?|khun)\\s*/iu', '', $s);
    $s = preg_replace('/[\s\-–—_·]+/u', '', $s);
    return $s;
}

function listing_looks_like_owner_search(string $text): bool
{
    $text = trim($text);
    if ($text === '' || preg_match('/[\r\n]/', $text)) {
        return false;
    }
    if (mb_strlen($text, 'UTF-8') > 40) {
        return false;
    }
    if (preg_match('/^(คุณ|พี่|น้อง|ป้า|ลุง|อา|เจ้าของ|owner)/iu', $text)) {
        return true;
    }
    if (preg_match('/^[A-Za-z]{2,6}\d{2,6}$/u', $text)) {
        return false;
    }
    $len = mb_strlen($text, 'UTF-8');
    if ($len >= 2 && $len <= 12 && !preg_match('/[A-Za-z]{4,}/', $text)) {
        return (bool)preg_match('/^[\p{Thai}\s.]+$/u', $text);
    }
    return false;
}

function listing_looks_like_listing_search(string $text): bool
{
    $text = trim($text);
    if ($text === '') {
        return false;
    }
    $lower = mb_strtolower($text, 'UTF-8');
    $blocked_cmds = [
        'สวัสดี', 'รายงาน', 'report', 'dashboard', 'register', 'ลงทะเบียน',
        'ปรับโครงสร้าง', 'ปรับโครงการ', 'กรอกข้อมูลที่ยังไม่ครบ',
        'menu', 'task', 'เมนู', 'งานวันนี้',
    ];
    foreach ($blocked_cmds as $cmd) {
        if ($lower === $cmd) {
            return false;
        }
    }

    return listing_looks_like_project_search($text) || listing_looks_like_owner_search($text);
}

function listing_looks_like_project_search(string $text): bool
{
    $text = trim($text);
    if ($text === '' || preg_match('/[\r\n]/', $text)) {
        return false;
    }
    if (mb_strlen($text, 'UTF-8') > 80) {
        return false;
    }

    $lower = mb_strtolower($text, 'UTF-8');
    $blocked_cmds = [
        'สวัสดี', 'รายงาน', 'report', 'dashboard', 'register', 'ลงทะเบียน',
        'ปรับโครงสร้าง', 'ปรับโครงการ', 'กรอกข้อมูลที่ยังไม่ครบ',
    ];
    foreach ($blocked_cmds as $cmd) {
        if ($lower === $cmd || strpos($lower, $cmd) === 0) {
            return false;
        }
    }

    if (preg_match('/(?:ลูกค้า|ลีด|lead|มาใหม่|รับสาย|นัดดู|follow\s?up|call\s?lead)/iu', $text)) {
        return false;
    }

    if (preg_match('/^[A-Za-z]{2,6}\d{2,6}$/u', $text)) {
        return true;
    }

    return mb_strlen($text, 'UTF-8') >= 4;
}

/** ตรวจจับคำขอเลขที่บ้าน / รหัสทรัพย์ / ชื่อโครงการ */
function extract_listing_request(string $text): ?array
{
    $text = trim($text);
    if ($text === '') {
        return null;
    }

    $is_house = (bool)preg_match('/ขอเลขที่(?:บ้าน)?/u', $text);

    $property_code = null;
    if (preg_match('/รหัส\s*([A-Za-z0-9_-]+)/u', $text, $m)) {
        $property_code = strtoupper($m[1]);
    } elseif (preg_match('/\b([A-Z]{2,6}\d{2,6})\b/u', $text, $m)) {
        $property_code = strtoupper($m[1]);
    }

    $project_query = '';
    if (preg_match('/โครงการ\s*(.+)$/u', $text, $m)) {
        $project_query = trim($m[1]);
    } elseif ($is_house) {
        $rest = preg_replace('/^.*?ขอเลขที่(?:บ้าน)?\s*/u', '', $text);
        $rest = preg_replace('/^โครงการ\s*/u', '', trim($rest));
        if ($rest !== '') {
            $project_query = trim(preg_replace('/\s+รหัส\s+[A-Za-z0-9_-]+.*$/u', '', $rest));
        }
    }

    $owner_name = '-';
    if ($property_code && preg_match('/รหัส\s*' . preg_quote($property_code, '/') . '\s*(.+)$/iu', $text, $m)) {
        $owner_name = trim($m[1]) ?: '-';
    }

    if (!$is_house && !$property_code) {
        if (preg_match('/^โครงการ\s+(.+)$/u', $text, $m)) {
            $project_query = trim($m[1]);
            $is_house = true;
        } elseif (listing_looks_like_listing_search($text)) {
            $project_query = trim(preg_replace('/^โครงการ\s*/u', '', $text));
            if (preg_match('/^(?:เจ้าของ|owner)\s+/iu', $project_query)) {
                $project_query = trim(preg_replace('/^(?:เจ้าของ|owner)\s+/iu', '', $project_query));
            }
            if (preg_match('/^[A-Za-z]{2,6}\d{2,6}$/u', $project_query)) {
                $property_code = strtoupper($project_query);
                $project_query = '';
            }
        }
    }

    if (!$is_house && !$property_code && $project_query === '') {
        return null;
    }
    if (!$property_code && $project_query === '' && !$is_house) {
        return null;
    }

    return [
        'property_code'  => $property_code,
        'project_query'  => $project_query,
        'owner_name'     => $owner_name,
        'is_house'       => $is_house,
    ];
}

function listing_owner_fields(array $owner, string $key, string $hint_owner_name = '-'): array
{
    $fields = repair_shifted_owner_fields([
        'asking_price' => decrypt_data($owner['asking_price_enc'] ?? '', $key),
        'unit_no'      => decrypt_data($owner['unit_no_enc'] ?? '', $key),
    ]);
    $contacts = repair_owner_contacts([
        'phone'   => decrypt_data($owner['phone_enc'] ?? '', $key),
        'line_id' => decrypt_data($owner['line_id_enc'] ?? '', $key),
    ]);

    $name_en = decrypt_data($owner['project_name_en_enc'] ?? '', $key);
    if ($name_en === '') {
        $name_en = decrypt_data($owner['project_enc'] ?? '', $key);
    }
    $name_th = decrypt_data($owner['project_name_th_enc'] ?? '', $key);
    if ($name_th === '') {
        $name_th = decrypt_data($owner['project_enc'] ?? '', $key);
    }

    $owner_name = decrypt_data($owner['owner_name_enc'] ?? '', $key);
    if ($hint_owner_name !== '-' && $hint_owner_name !== '') {
        $owner_name = $hint_owner_name;
    }

    $soi = decrypt_data($owner['soi_enc'] ?? '', $key);
    $zone = decrypt_data($owner['zone_enc'] ?? '', $key);
    $unit = $fields['unit_no'] ?: '';
    $addr_parts = array_filter([$unit, $soi, $zone], static fn($v) => trim((string)$v) !== '');
    $house_no = $addr_parts ? implode(' · ', $addr_parts) : 'รอข้อมูล';

    $phone_disp = format_phone_display($contacts['phone']);
    $phone_digits = preg_replace('/\D/', '', normalize_phone_string($contacts['phone']));
    $phone_tel = ($phone_digits !== '' && $phone_digits[0] === '0')
        ? 'tel:+66' . substr($phone_digits, 1)
        : '';

    $line_id = $contacts['line_id'];
    $line_url = listing_line_url($line_id);

    $price = fmt_price_full($fields['asking_price']);
    $cover_stored = (string)($owner['cover_image_url'] ?? '');
    $cover = gdrive_line_image_url($cover_stored);
    if ($cover === '') {
        $cover = LISTING_COVER_FALLBACK;
    }

    $map_url = decrypt_data($owner['map_url_enc'] ?? '', $key);
    if ($map_url === '') {
        $map_url = 'https://www.google.com/maps';
    }
    $map_url = listing_absolute_https_url($map_url, 'https://www.google.com/maps');

    $photos = decrypt_data($owner['photos_link_enc'] ?? '', $key);
    $detail_url = $photos !== '' ? $photos : $map_url;
    $detail_url = listing_absolute_https_url($detail_url, $map_url);

    $sales = strtoupper(str_replace(' ', '', trim($owner['sales_status'] ?? 'Sale')));
    $avail = $owner['availability_status'] ?? '';
    if ($avail === 'ขายได้แล้ว' || $sales === 'SOLD') {
        $status_badge = 'SOLD';
    } elseif ($avail === 'ยกเลิกการขาย' || $sales === 'CANCEL') {
        $status_badge = 'CANCEL';
    } elseif ($sales === 'RENT' || $sales === 'RENTAL') {
        $status_badge = 'RENT';
    } else {
        $status_badge = 'SALE';
    }

    return [
        'property_code'  => $owner['code_list'] ?? '',
        'owner_name'     => $owner_name ?: '-',
        'house_no'       => $house_no,
        'unit_no'        => $unit,
        'soi'            => $soi,
        'zone'           => $zone,
        'project_name'   => $name_th ?: $name_en,
        'name_en'        => $name_en ?: '-',
        'name_th'        => $name_th ?: '-',
        'property_type'  => decrypt_data($owner['property_type_enc'] ?? '', $key) ?: '-',
        'price'          => $price !== '' ? $price : '-',
        'common_fee'     => '-',
        'phone'          => $phone_disp ?: '-',
        'phone_tel'      => $phone_tel,
        'line_id'        => $line_id ?: '-',
        'line_url'       => $line_url,
        'status_badge'   => $status_badge,
        'cover_main'     => $cover,
        'cover_sub1'     => $cover,
        'cover_sub2'     => $cover,
        'map_url'        => $map_url,
        'detail_url'     => $detail_url,
    ];
}

function listing_line_url(string $line_id): string
{
    $id = normalize_line_id_string($line_id);
    if ($id === '') {
        return '';
    }
    if ($id[0] === '@') {
        return 'https://line.me/ti/p/' . $id;
    }
    return 'https://line.me/ti/p/~' . $id;
}

function listing_owner_search_blob(array $owner, string $key): string
{
    $parts = [
        $owner['code_list'] ?? '',
        decrypt_data($owner['project_enc'] ?? '', $key),
        decrypt_data($owner['project_name_th_enc'] ?? '', $key),
        decrypt_data($owner['project_name_en_enc'] ?? '', $key),
        decrypt_data($owner['zone_enc'] ?? '', $key),
        decrypt_data($owner['soi_enc'] ?? '', $key),
    ];
    return listing_normalize_text(implode(' ', array_filter($parts, static fn($p) => trim((string)$p) !== '')));
}

function listing_project_match_score(array $owner, string $key, string $query): int
{
    $q = listing_normalize_text($query);
    if ($q === '') {
        return 0;
    }
    $hay = listing_owner_search_blob($owner, $key);
    if ($hay === '') {
        return 0;
    }
    if ($hay === $q) {
        return 100;
    }
    if (strpos($hay, $q) !== false) {
        return 90;
    }
    if (strpos($q, $hay) !== false) {
        return 85;
    }
    $tokens = preg_split('/[\s\-–—]+/u', mb_strtolower($query, 'UTF-8'));
    $hit = 0;
    foreach ($tokens as $tok) {
        $tok = listing_normalize_text($tok);
        if ($tok !== '' && strpos($hay, $tok) !== false) {
            $hit++;
        }
    }
    if ($hit >= 2) {
        return 70 + min(15, $hit * 5);
    }
    if ($hit === 1 && mb_strlen($query, 'UTF-8') <= 12) {
        return 60;
    }
    return 0;
}

function listing_owner_name_match_score(array $owner, string $key, string $query): int
{
    $q = listing_normalize_owner_name($query);
    if ($q === '' || mb_strlen($q, 'UTF-8') < 2) {
        return 0;
    }

    $raw_name = decrypt_data($owner['owner_name_enc'] ?? '', $key);
    $hay = listing_normalize_owner_name($raw_name);
    if ($hay === '') {
        return 0;
    }

    if ($hay === $q) {
        return 100;
    }
    if (strpos($hay, $q) !== false) {
        return 92;
    }
    if (strpos($q, $hay) !== false) {
        return 88;
    }

    $tokens = preg_split('/[\s\-–—]+/u', mb_strtolower($raw_name, 'UTF-8'));
    foreach ($tokens as $tok) {
        $tok = listing_normalize_owner_name($tok);
        if ($tok === '') {
            continue;
        }
        if ($tok === $q) {
            return 95;
        }
        if (strpos($tok, $q) !== false || strpos($q, $tok) !== false) {
            return 90;
        }
    }

    return 0;
}

/** @return array<int, array> */
function search_listing_owners(mysqli $conn, array $user, array $request): array
{
    $user_id = (int)$user['id'];
    $key = $user['encryption_key'];
    $code = $request['property_code'] ?? null;
    $project_query = trim($request['project_query'] ?? '');

    if ($code) {
        $stmt = $conn->prepare('SELECT * FROM owners WHERE user_id = ? AND code_list = ? LIMIT 1');
        $stmt->bind_param('is', $user_id, $code);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? [$row] : [];
    }

    if ($project_query === '') {
        return [];
    }

    $stmt = $conn->prepare('SELECT * FROM owners WHERE user_id = ? ORDER BY updated_at DESC, id DESC');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $scored = [];
    while ($row = $res->fetch_assoc()) {
        $p_score = listing_project_match_score($row, $key, $project_query);
        $o_score = listing_owner_name_match_score($row, $key, $project_query);
        $score = max($p_score, $o_score);
        if ($score >= 55) {
            $scored[] = ['score' => $score, 'owner' => $row, 'owner_hit' => $o_score >= $p_score];
        }
    }
    $stmt->close();

    usort($scored, static fn($a, $b) => $b['score'] <=> $a['score']);

    if ($scored !== []) {
        $best = $scored[0]['score'];
        $owner_led = !empty($scored[0]['owner_hit']);
        if ($owner_led && $best >= 88) {
            $min_score = 85;
        } elseif ($best >= 85) {
            $min_score = 80;
        } else {
            $min_score = 55;
        }
        $scored = array_values(array_filter($scored, static fn($x) => $x['score'] >= $min_score));
    }

    return array_map(static fn($x) => $x['owner'], array_slice($scored, 0, LISTING_FLEX_MAX_CAROUSEL));
}

function replace_listing_placeholders($value, array $replacements)
{
    if (is_array($value)) {
        foreach ($value as $k => $child) {
            $value[$k] = replace_listing_placeholders($child, $replacements);
        }
        return $value;
    }
    if (is_string($value)) {
        return str_replace(array_keys($replacements), array_values($replacements), $value);
    }
    return $value;
}

function listing_flex_template(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $path = dirname(__DIR__) . '/Flex listing.json';
    $cached = [
        'type' => 'bubble',
        'body' => [
            'type' => 'box',
            'layout' => 'vertical',
            'contents' => [
                ['type' => 'text', 'text' => '__PROJECT_NAME__', 'weight' => 'bold', 'size' => 'md', 'wrap' => true],
                ['type' => 'text', 'text' => '__PROJECT_NAME_EN__', 'size' => 'xs', 'wrap' => true, 'margin' => 'xs', 'color' => '#71717a'],
                ['type' => 'text', 'text' => '__PRICE__ บาท', 'size' => 'lg', 'weight' => 'bold', 'margin' => 'md'],
                ['type' => 'text', 'text' => 'Owner: __OWNER_NAME__ · __PROPERTY_CODE__ · __HOUSE_NO__', 'wrap' => true, 'size' => 'xs', 'margin' => 'md'],
            ],
        ],
    ];

    if (!is_readable($path)) {
        return $cached;
    }

    $raw = trim(file_get_contents($path));
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    if ($raw === '') {
        return $cached;
    }

    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $cached = $decoded;
    }
    return $cached;
}

/** การ์ดย่อสำหรับ carousel — ลดขนาด JSON ให้อยู่ใน limit 50 KB ของ LINE */
function listing_flex_compact_template(): array
{
    return [
        'type' => 'bubble',
        'size' => 'kilo',
        'hero' => [
            'type' => 'image',
            'url' => '__COVER_MAIN__',
            'size' => 'full',
            'aspectRatio' => '20:13',
            'aspectMode' => 'cover',
        ],
        'body' => [
            'type' => 'box',
            'layout' => 'vertical',
            'contents' => [
                ['type' => 'text', 'text' => '__STATUS_BADGE__ · __PROPERTY_CODE__', 'size' => 'xxs', 'color' => '#71717a', 'wrap' => true],
                ['type' => 'text', 'text' => '__PROJECT_NAME__', 'weight' => 'bold', 'size' => 'sm', 'wrap' => true, 'margin' => 'xs'],
                ['type' => 'text', 'text' => '__PRICE__ บาท', 'size' => 'md', 'weight' => 'bold', 'margin' => 'sm'],
                ['type' => 'text', 'text' => 'เลขที่: __HOUSE_NO__', 'size' => 'xs', 'wrap' => true, 'margin' => 'sm'],
                ['type' => 'text', 'text' => '__OWNER_LINE__', 'size' => 'xs', 'wrap' => true, 'margin' => 'xs', 'color' => '#52525b'],
            ],
            'paddingAll' => '14px',
        ],
        'footer' => [
            'type' => 'box',
            'layout' => 'horizontal',
            'spacing' => 'sm',
            'contents' => [
                ['type' => 'button', 'style' => 'primary', 'height' => 'sm', 'color' => '#141414', 'action' => ['type' => 'uri', 'label' => 'โทร', 'uri' => '__PHONE_TEL__']],
                ['type' => 'button', 'style' => 'primary', 'height' => 'sm', 'color' => '#5C4E4E', 'action' => ['type' => 'uri', 'label' => 'แผนที่', 'uri' => '__MAP_URL__']],
            ],
        ],
    ];
}

function listing_customer_project_line(array $listing_data): string
{
    $th = trim((string)($listing_data['name_th'] ?? ''));
    if ($th !== '' && $th !== '-') {
        return $th;
    }
    $full = trim((string)($listing_data['project_name'] ?? ''));
    if ($full === '' || $full === '-') {
        return '';
    }
    if (preg_match('/[\x{0E00}-\x{0E7F}][\x{0E00}-\x{0E7F}\s\-0-9\/\.]*/u', $full, $m)) {
        $thai = trim($m[0]);
        if ($thai !== '') {
            return $thai;
        }
    }
    return $full;
}

function listing_customer_address_line(array $listing_data): string
{
    $unit = trim((string)($listing_data['unit_no'] ?? ''));
    $soi = trim((string)($listing_data['soi'] ?? ''));
    $zone = trim((string)($listing_data['zone'] ?? ''));

    if ($unit === '' && $soi === '' && $zone === '') {
        $house = trim((string)($listing_data['house_no'] ?? ''));
        if ($house === '' || $house === 'รอข้อมูล') {
            return '';
        }
        $unit = $house;
    }

    $detail = $unit;
    foreach ([$soi, $zone] as $part) {
        if ($part === '' || mb_stripos($detail, $part, 0, 'UTF-8') !== false) {
            continue;
        }
        $detail = trim($detail . ' ' . $part);
    }

    if ($detail === '') {
        return '';
    }
    if (!preg_match('/บ้านเลขที่/u', $detail)) {
        $detail = 'บ้านเลขที่ ' . $detail;
    }
    return $detail;
}

/** ข้อความพร้อมวางส่งลูกค้า (นัดดู / ส่งโลเคชั่น) */
function listing_customer_share_text(array $listing_data): string
{
    $lines = [];
    $project = listing_customer_project_line($listing_data);
    $address = listing_customer_address_line($listing_data);
    if ($project !== '') {
        $lines[] = $project;
    }
    if ($address !== '') {
        $lines[] = $address;
    }
    return implode("\n", $lines);
}

function listing_copy_phone_text(array $listing_data): string
{
    $phone = trim((string)($listing_data['phone'] ?? ''));
    if ($phone === '' || $phone === '-') {
        return '';
    }
    return $phone;
}

function listing_clipboard_footer_buttons(array $listing_data): array
{
    $buttons = [];

    $copyPhone = listing_copy_phone_text($listing_data);
    if ($copyPhone !== '') {
        $btn = line_flex_clipboard_button('📋 เบอร์โทร', $copyPhone, 'secondary', 'sm');
        if ($btn) {
            $buttons[] = $btn;
        }
    }

    $shareText = listing_customer_share_text($listing_data);
    if ($shareText !== '') {
        $btn = line_flex_clipboard_button('📋 ส่งลูกค้า', $shareText, 'secondary', 'sm');
        if ($btn) {
            $buttons[] = $btn;
        }
    }

    return $buttons;
}

function listing_short_house_no(string $house): string
{
    $house = trim($house);
    if ($house === '') {
        return 'รอข้อมูล';
    }
    if (mb_strlen($house, 'UTF-8') <= 48) {
        return $house;
    }
    $parts = preg_split('/\s·\s/u', $house);
    return trim($parts[0] ?? $house);
}

function listing_owner_line(array $listing_data): string
{
    $name = trim((string)($listing_data['owner_name'] ?? ''));
    if ($name === '' || $name === '-') {
        $name = 'ไม่ระบุ';
    }
    $parts = ['Owner: ' . $name];
    $phone = trim((string)($listing_data['phone'] ?? ''));
    $line = trim((string)($listing_data['line_id'] ?? ''));
    if ($phone !== '' && $phone !== '-') {
        $parts[] = $phone;
    }
    if ($line !== '' && $line !== '-') {
        $parts[] = $line;
    }
    return implode(' · ', $parts);
}

function listing_project_subtitle_en(array $listing_data): string
{
    $main = trim((string)($listing_data['project_name'] ?? ''));
    $en = trim((string)($listing_data['name_en'] ?? ''));
    if ($en === '' || $en === '-' || $en === $main) {
        return '';
    }
    return $en;
}

function listing_bubble_replacements(array $listing_data): array
{
    $phone_tel = $listing_data['phone_tel'] ?: 'https://line.me';
    $line_url = $listing_data['line_url'] ?: 'https://line.me';
    $project = trim((string)($listing_data['project_name'] ?? ''));
    $subtitle_en = listing_project_subtitle_en($listing_data);

    return [
        '__OWNER_NAME__'     => $listing_data['owner_name'] ?: '-',
        '__OWNER_LINE__'     => listing_owner_line($listing_data),
        '__PROPERTY_CODE__'  => $listing_data['property_code'] ?: '-',
        '__HOUSE_NO__'       => listing_short_house_no($listing_data['house_no'] ?? ''),
        '__PROJECT_NAME__'   => $project !== '' ? $project : '-',
        '__PROJECT_NAME_EN__'=> $subtitle_en,
        '__PROJECT_NAME_TH__'=> trim((string)($listing_data['name_th'] ?? '')) ?: '-',
        '__PRICE__'          => $listing_data['price'] ?: '-',
        '__COMMON_FEE__'     => $listing_data['common_fee'] ?: '-',
        '__PHONE__'          => $listing_data['phone'] ?: '-',
        '__LINE_ID__'        => $listing_data['line_id'] ?: '-',
        '__LINE_URL__'       => $line_url,
        '__STATUS_BADGE__'   => $listing_data['status_badge'] ?: 'SALE',
        '__COVER_MAIN__'     => $listing_data['cover_main'] ?: LISTING_COVER_FALLBACK,
        '__COVER_SUB1__'     => $listing_data['cover_sub1'] ?: LISTING_COVER_FALLBACK,
        '__COVER_SUB2__'     => $listing_data['cover_sub2'] ?: LISTING_COVER_FALLBACK,
        '__MAP_URL__'        => $listing_data['map_url'] ?: 'https://www.google.com/maps',
        '__DETAIL_URL__'     => $listing_data['detail_url'] ?: 'https://www.google.com/maps',
        '__PHONE_TEL__'      => $phone_tel,
        'tel:0812345678'     => $phone_tel,
        'https://maps.google.com' => $listing_data['map_url'] ?: 'https://www.google.com/maps',
    ];
}

function listing_flex_prune_empty_text_nodes(array $node): array
{
    if (($node['type'] ?? '') === 'text' && trim((string)($node['text'] ?? '')) === '') {
        return [];
    }
    foreach (['contents', 'body', 'header', 'footer', 'hero'] as $key) {
        if (!isset($node[$key])) {
            continue;
        }
        if ($key === 'body' || $key === 'header' || $key === 'footer' || $key === 'hero') {
            $node[$key] = listing_flex_prune_empty_text_nodes($node[$key]);
            continue;
        }
        if (is_array($node[$key])) {
            $next = [];
            foreach ($node[$key] as $child) {
                if (!is_array($child)) {
                    $next[] = $child;
                    continue;
                }
                $child = listing_flex_prune_empty_text_nodes($child);
                if ($child !== []) {
                    $next[] = $child;
                }
            }
            $node[$key] = $next;
        }
    }
    return $node;
}

function build_listing_bubble(array $listing_data, bool $compact = false): array
{
    $replacements = listing_bubble_replacements($listing_data);
    $template = $compact ? listing_flex_compact_template() : listing_flex_template();
    $bubble = replace_listing_placeholders($template, $replacements);

    if (($bubble['type'] ?? '') === 'flex' && isset($bubble['contents'])) {
        $bubble = $bubble['contents'];
    }

    $bubble = listing_flex_prune_empty_text_nodes($bubble);

    $clipBtns = listing_clipboard_footer_buttons($listing_data);
    if ($clipBtns) {
        $bubble = line_flex_append_footer_buttons($bubble, $clipBtns);
    }

    return $bubble;
}

function listing_flex_payload_size(array $flex_message): int
{
    return strlen(json_encode(['messages' => [$flex_message]], JSON_UNESCAPED_UNICODE));
}

function build_listing_flex_message(array $listing_data): array
{
    $bubbles = $listing_data['bubbles'] ?? null;
    if (is_array($bubbles) && count($bubbles) > 1) {
        $total = count($bubbles);
        $compact = true;
        $contents = array_map(static fn($b) => build_listing_bubble($b, true), $bubbles);

        while (count($contents) > 1 && listing_flex_payload_size([
            'type' => 'flex',
            'altText' => 'x',
            'contents' => ['type' => 'carousel', 'contents' => $contents],
        ]) > LISTING_FLEX_MAX_JSON_BYTES) {
            array_pop($contents);
        }

        $shown = count($contents);
        $codes = array_map(static fn($b) => $b['property_code'] ?? '', array_slice($bubbles, 0, $shown));
        $alt = 'พบ ' . $total . ' ทรัพย์';
        if ($shown < $total) {
            $alt .= " (แสดง {$shown} การ์ด)";
        }
        $alt .= ' · ' . implode(', ', array_filter($codes));

        return [
            'type' => 'flex',
            'altText' => $alt,
            'contents' => [
                'type' => 'carousel',
                'contents' => $contents,
            ],
        ];
    }

    $one = is_array($bubbles) && isset($bubbles[0]) ? $bubbles[0] : $listing_data;
    $code = $one['property_code'] ?? '';
    return [
        'type' => 'flex',
        'altText' => 'ข้อมูลทรัพย์' . ($code !== '' ? ' ' . $code : ''),
        'contents' => build_listing_bubble($one, false),
    ];
}

function listing_text_summary(array $bubbles, int $shown = 0): string
{
    $total = count($bubbles);
    $shown = $shown > 0 ? min($shown, $total) : min(8, $total);
    $lines = ['พบ ' . $total . ' ทรัพย์' . ($shown < $total ? " (แสดง {$shown} รายการ)" : '') . ':'];
    foreach (array_slice($bubbles, 0, $shown) as $b) {
        $code = $b['property_code'] ?? '-';
        $house = listing_short_house_no($b['house_no'] ?? '-');
        $name = ($b['name_en'] ?? '-') !== '-' ? $b['name_en'] : ($b['name_th'] ?? '-');
        $lines[] = "• {$code} — {$house} · {$name}";
    }
    if ($total > $shown) {
        $lines[] = '… และอีก ' . ($total - $shown) . ' รายการ';
    }
    return implode("\n", $lines);
}

function listing_search_stats_ensure_schema(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS listing_project_search_stats (
        user_id INT NOT NULL,
        project_key VARCHAR(191) NOT NULL,
        label_enc TEXT NOT NULL,
        search_count INT NOT NULL DEFAULT 1,
        last_searched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, project_key),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_listing_search_popular (user_id, search_count DESC, last_searched_at DESC)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** นับสถิติเฉพาะค้นหาชื่อโครงการ (ไม่รวมรหัสทรัพย์ / ชื่อเจ้าของ) */
function listing_request_counts_as_project_name_search(array $request): bool
{
    if (!empty($request['property_code'])) {
        return false;
    }
    $q = trim((string)($request['project_query'] ?? ''));
    if ($q === '') {
        return false;
    }
    if (listing_looks_like_owner_search($q)) {
        return false;
    }
    if (preg_match('/^[A-Za-z]{2,6}\d{2,6}$/u', $q)) {
        return false;
    }
    return true;
}

function listing_project_stats_label(array $owner, string $enc_key): string
{
    $th = trim((string)decrypt_data($owner['project_name_th_enc'] ?? '', $enc_key));
    if ($th !== '' && $th !== '-') {
        return $th;
    }
    $en = trim((string)decrypt_data($owner['project_enc'] ?? '', $enc_key));
    return ($en !== '' && $en !== '-') ? $en : '';
}

function listing_project_stats_key(array $owner, string $enc_key): string
{
    $label = listing_project_stats_label($owner, $enc_key);
    if ($label === '') {
        return '';
    }
    $norm = listing_normalize_text($label);
    if ($norm === '') {
        $norm = mb_strtolower(trim($label), 'UTF-8');
    }
    return mb_substr($norm, 0, 190, 'UTF-8');
}

function listing_qr_short_label(string $text, int $max = 20): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }
    if (mb_strlen($text, 'UTF-8') <= $max) {
        return $text;
    }
    return mb_substr($text, 0, $max - 1, 'UTF-8') . '…';
}

function listing_record_project_search(mysqli $conn, array $user, array $owner): void
{
    $uid = (int)($user['id'] ?? 0);
    $key = (string)($user['encryption_key'] ?? '');
    if ($uid <= 0 || $key === '') {
        return;
    }

    listing_search_stats_ensure_schema($conn);

    $project_key = listing_project_stats_key($owner, $key);
    $label = listing_project_stats_label($owner, $key);
    if ($project_key === '' || $label === '') {
        return;
    }

    $label_enc = encrypt_data($label, $key);
    $stmt = $conn->prepare(
        'INSERT INTO listing_project_search_stats (user_id, project_key, label_enc, search_count)
         VALUES (?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE
             search_count = search_count + 1,
             label_enc = VALUES(label_enc),
             last_searched_at = CURRENT_TIMESTAMP'
    );
    $stmt->bind_param('iss', $uid, $project_key, $label_enc);
    $stmt->execute();
    $stmt->close();
}

/** @return array<int, array{label: string, text: string}> */
function listing_popular_projects_for_user(mysqli $conn, array $user, int $limit = 4): array
{
    $uid = (int)($user['id'] ?? 0);
    $key = (string)($user['encryption_key'] ?? '');
    if ($uid <= 0 || $key === '' || $limit <= 0) {
        return [];
    }

    listing_search_stats_ensure_schema($conn);

    $stmt = $conn->prepare(
        'SELECT label_enc FROM listing_project_search_stats
         WHERE user_id = ?
         ORDER BY search_count DESC, last_searched_at DESC
         LIMIT ?'
    );
    $stmt->bind_param('ii', $uid, $limit);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $label = trim((string)decrypt_data($row['label_enc'] ?? '', $key));
        if ($label === '') {
            continue;
        }
        $out[] = [
            'label' => listing_qr_short_label($label),
            'text' => $label,
        ];
    }
    $stmt->close();
    return $out;
}

/** โครงการเริ่มต้น — แสดงเมื่อ user ยังไม่มีประวัติค้นหา */
function listing_default_project_shortcuts(): array
{
    return [
        ['label' => 'ไลฟ์ บางกอก', 'text' => 'ไลฟ์ บางกอก'],
        ['label' => 'เพฟ รามอินทรา', 'text' => 'เพฟ รามอินทรา'],
        ['label' => 'ลัดดารมย์', 'text' => 'ลัดดารมย์'],
        ['label' => 'เศรษฐสิริ', 'text' => 'เศรษฐสิริ'],
        ['label' => 'บุราสิริ', 'text' => 'บุราสิริ'],
    ];
}

/** ประวัติค้นหาบ่อย (สูงสุด 4) หรือรายการเริ่มต้น */
function listing_quick_reply_projects(mysqli $conn, array $user): array
{
    $popular = listing_popular_projects_for_user($conn, $user, 4);
    if ($popular !== []) {
        return $popular;
    }
    return listing_default_project_shortcuts();
}

function send_listing_flex_message(mysqli $conn, array $user, string $replyToken, array $listing_request): void
{
    $owners = search_listing_owners($conn, $user, $listing_request);
    $hint_name = $listing_request['owner_name'] ?? '-';

    if (!$owners) {
        $hint = $listing_request['property_code']
            ?? ($listing_request['project_query'] ?? '');
        $msg = $hint !== ''
            ? "ไม่พบทรัพย์ที่ตรงกับ \"{$hint}\" ในระบบ\nลองพิมพ์ชื่อ Owner เช่น คุณนัท\nชื่อโครงการ เช่น Pave รามอินทรา - วงแหวน\nหรือรหัสทรัพย์ เช่น TAN617"
            : 'ไม่พบทรัพย์ในระบบ กรุณาระบุชื่อโครงการหรือรหัสทรัพย์';
        send_line_text_reply($replyToken, $msg);
        return;
    }

    $key = $user['encryption_key'];
    if (listing_request_counts_as_project_name_search($listing_request)) {
        listing_record_project_search($conn, $user, $owners[0]);
    }

    $bubbles = [];
    foreach ($owners as $owner) {
        $bubbles[] = listing_owner_fields($owner, $key, count($owners) === 1 ? $hint_name : '-');
    }

    $flex = build_listing_flex_message(['bubbles' => $bubbles]);
    $key = $user['encryption_key'] ?? '';

    if (count($owners) === 1 && $key !== '') {
        $code = $owners[0]['code_list'] ?? '';
        $tasks = owner_line_fetch_owner_task_cards($conn, (int)$user['id'], $code, $key);
        if ($tasks !== [] && isset($flex['contents'])) {
            $contents = $flex['contents'];
            if (($contents['type'] ?? '') === 'bubble') {
                $carousel = [$contents];
                foreach ($tasks as $task) {
                    $carousel[] = owner_line_task_bubble_for_kind($task);
                }
                $flex['contents'] = [
                    'type' => 'carousel',
                    'contents' => array_slice($carousel, 0, LISTING_FLEX_MAX_CAROUSEL),
                ];
                $flex['altText'] .= ' + Task';
            }
        }
    }

    $flex_size = listing_flex_payload_size($flex);

    if ($flex_size > LISTING_FLEX_MAX_JSON_BYTES) {
        send_line_text_reply($replyToken, listing_text_summary($bubbles));
        return;
    }

    $http = send_line_reply([
        'replyToken' => $replyToken,
        'messages'   => [$flex],
    ]);

    if ($http >= 400 && !empty($user['line_user_id']) && function_exists('send_line_push_text')) {
        send_line_push_text($user['line_user_id'], listing_text_summary($bubbles), true);
    }
}
