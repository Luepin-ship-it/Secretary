<?php
/**
 * AI สกัดข้อมูลทรัพย์/Lead จากข้อความดิบ (LINE + Dashboard Magic Paste)
 */

require_once __DIR__ . '/../openai_agent.php';
require_once __DIR__ . '/markdown_field_parser.php';
require_once __DIR__ . '/owner_field_normalize.php';
require_once __DIR__ . '/contact_normalize.php';
require_once __DIR__ . '/tan_workbook_import.php';

function listing_ai_decode_json(string $content): ?array
{
    $content = trim($content);
    if ($content === '') {
        return null;
    }
    if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $content, $m)) {
        $content = trim($m[1]);
    }
    $parsed = json_decode($content, true);
    return is_array($parsed) ? $parsed : null;
}

function listing_ai_call(string $system, string $userText): ?array
{
    $res = call_openai_chat([
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $userText],
    ], true, 0.1);
    if (!$res) {
        return null;
    }
    return listing_ai_decode_json((string)($res['choices'][0]['message']['content'] ?? ''));
}

function listing_ai_str($v): string
{
    if ($v === null) {
        return '';
    }
    if (is_bool($v)) {
        return $v ? '1' : '';
    }
    if (is_scalar($v)) {
        return trim((string)$v);
    }
    return '';
}

/** @param array<string,mixed> $raw */
function listing_ai_normalize_owner_row(array $raw): array
{
    $data = [
        'name_th' => listing_ai_str($raw['name_th'] ?? $raw['project_name_th'] ?? ''),
        'name_en' => listing_ai_str($raw['name_en'] ?? $raw['project_name_en'] ?? ''),
        'area_sqwa' => listing_ai_str($raw['area_sqwa'] ?? $raw['sqwa'] ?? ''),
        'area_sqm' => listing_ai_str($raw['area_sqm'] ?? $raw['sqm'] ?? ''),
        'bed' => listing_ai_str($raw['bed'] ?? $raw['bedrooms'] ?? ''),
        'bath' => listing_ai_str($raw['bath'] ?? $raw['bathrooms'] ?? ''),
        'parking' => listing_ai_str($raw['parking'] ?? ''),
        'maid' => listing_ai_str($raw['maid'] ?? $raw['extra_room'] ?? ''),
        'unit_no' => listing_ai_str($raw['unit_no'] ?? $raw['house_no'] ?? ''),
        'map_url' => listing_ai_str($raw['map_url'] ?? ''),
        'direction' => listing_ai_str($raw['direction'] ?? ''),
        'code_list' => listing_ai_str($raw['code_list'] ?? $raw['listing_code'] ?? ''),
        'owner_name' => listing_ai_str($raw['owner_name'] ?? ''),
        'phone' => listing_ai_str($raw['phone'] ?? $raw['owner_phone'] ?? ''),
        'line_id' => listing_ai_str($raw['line_id'] ?? $raw['owner_line'] ?? ''),
        'listing_date' => listing_ai_str($raw['listing_date'] ?? ''),
        'marketing_date' => listing_ai_str($raw['marketing_date'] ?? ''),
        'owner_price' => listing_ai_str($raw['owner_price'] ?? ''),
        'asking_price' => listing_ai_str($raw['asking_price'] ?? $raw['price'] ?? $raw['selling_price'] ?? ''),
        'rental_price' => listing_ai_str($raw['rental_price'] ?? $raw['rent'] ?? ''),
        'transfer_fee' => listing_ai_str($raw['transfer_fee'] ?? $raw['selling_condition'] ?? ''),
        'sales_status' => listing_ai_str($raw['sales_status'] ?? $raw['status'] ?? ''),
        'owner_urgency' => listing_ai_str($raw['owner_urgency'] ?? $raw['potential'] ?? ''),
        'selling_reason' => listing_ai_str($raw['selling_reason'] ?? ''),
        'photos_link' => listing_ai_str($raw['photos_link'] ?? $raw['drive_link'] ?? ''),
        'property_type' => listing_ai_str($raw['property_type'] ?? ''),
        'zone' => listing_ai_str($raw['zone'] ?? ''),
        'soi' => listing_ai_str($raw['soi'] ?? ''),
        'floor' => listing_ai_str($raw['floor'] ?? ''),
        'selling_timeline' => listing_ai_str($raw['selling_timeline'] ?? ''),
        'contact_summary' => listing_ai_str($raw['contact_summary'] ?? $raw['next_follow_action'] ?? ''),
        'next_follow_date' => listing_ai_str($raw['next_follow_date'] ?? ''),
    ];

    $data['code_list'] = TanWorkbookImport::normalizeListingCode($data['code_list']);
    $data['phone'] = normalize_phone_string($data['phone']);
    $data['line_id'] = normalize_line_id_string($data['line_id']);
    $data['unit_no'] = normalize_unit_no_string($data['unit_no']);
    $data['direction'] = sanitize_direction($data['direction']);
    $data['rental_price'] = sanitize_rental_price(mdf_normalize_price($data['rental_price']));
    $data['asking_price'] = mdf_normalize_price($data['asking_price']);
    $data['owner_price'] = mdf_normalize_price($data['owner_price']);
    if ($data['sales_status'] !== '') {
        $data['sales_status'] = mdf_normalize_sales_status($data['sales_status']);
    }
    if ($data['owner_urgency'] !== '') {
        $data['owner_urgency'] = mdf_normalize_potential($data['owner_urgency']);
    }
    if ($data['next_follow_date'] !== '') {
        $normDate = mdf_normalize_date($data['next_follow_date']);
        $data['next_follow_date'] = $normDate ?: $data['next_follow_date'];
    } elseif ($data['contact_summary'] !== '') {
        $normDate = mdf_normalize_date($data['contact_summary']);
        if ($normDate) {
            $data['next_follow_date'] = $normDate;
        }
    }

    return $data;
}

/**
 * เติมฟิลด์ที่ AI อาจพลาดจากข้อความดิบ (Magic Paste แบบไม่มี label)
 * @param array<string,string> $data
 * @return array<string,string>
 */
function listing_ai_owner_enrich_from_text(string $text, array $data): array
{
    $text = trim($text);
    if ($text === '') {
        return $data;
    }

    if (($data['name_en'] ?? '') === '' && strpos($data['name_th'] ?? '', '/') !== false) {
        $parts = preg_split('/\s*\/\s*/', (string)$data['name_th'], 2);
        $data['name_th'] = trim($parts[0] ?? '');
        $data['name_en'] = trim($parts[1] ?? '');
    }

    if (($data['name_th'] ?? '') === '' || ($data['name_en'] ?? '') === '') {
        if (preg_match(
            '/([\x{0E00}-\x{0E7F}][\x{0E00}-\x{0E7F}\s\-—]+?)\s*\/\s*([A-Za-z][A-Za-z0-9\s\-—]+?)(?=\s+\d|\s*[,\.]|$)/u',
            $text,
            $m
        )) {
            if (($data['name_th'] ?? '') === '') {
                $data['name_th'] = trim($m[1]);
            }
            if (($data['name_en'] ?? '') === '') {
                $data['name_en'] = trim($m[2]);
            }
        }
    }

    if (($data['name_th'] ?? '') === '' && ($data['name_en'] ?? '') === '') {
        if (preg_match(
            '/(?:ขายพร้อมผู้เช่า|ขาย|เช่า|ปล่อยเช่า)\s+(.+?)\s+(\d+(?:\.\d+)?)\s*ตร\.?\s*ว/u',
            $text,
            $m
        )) {
            $chunk = trim($m[1]);
            if (strpos($chunk, '/') !== false) {
                $parts = preg_split('/\s*\/\s*/', $chunk, 2);
                $data['name_th'] = trim($parts[0] ?? '');
                $data['name_en'] = trim($parts[1] ?? '');
            } elseif (preg_match('/[\x{0E00}-\x{0E7F}]/u', $chunk)) {
                $data['name_th'] = $chunk;
            } else {
                $data['name_en'] = $chunk;
            }
        }
    }

    if (($data['owner_name'] ?? '') === '' && preg_match('/(?:คุณ|นาย|นางสาว|นาง)([\p{L}]{1,24})/u', $text, $m)) {
        $data['owner_name'] = trim($m[0]);
    }

    if (($data['phone'] ?? '') === '' && preg_match('/\b(0\d[\d\-]{8,12})\b/', $text, $m)) {
        $data['phone'] = normalize_phone_string($m[1]);
    }

    if (($data['code_list'] ?? '') === '' && preg_match('/\b(TAN|NING|DIT|AMPK|FEW|PINP|NAME|TPM)\s*(\d+)\b/i', $text, $m)) {
        $data['code_list'] = TanWorkbookImport::normalizeListingCode($m[1] . $m[2]);
    }

    if (($data['bed'] ?? '') === '' && preg_match('/(\d+)\s*ห้องนอน/u', $text, $m)) {
        $data['bed'] = $m[1];
    }
    if (($data['bath'] ?? '') === '' && preg_match('/(\d+)\s*ห้องน้ำ/u', $text, $m)) {
        $data['bath'] = $m[1];
    }
    if (($data['area_sqwa'] ?? '') === '' && preg_match('/(\d+(?:\.\d+)?)\s*ตร\.?\s*ว/u', $text, $m)) {
        $data['area_sqwa'] = $m[1];
    }
    if (($data['area_sqm'] ?? '') === '' && preg_match('/(\d+(?:\.\d+)?)\s*ตร\.?\s*ม/u', $text, $m)) {
        $data['area_sqm'] = $m[1];
    }

    if (($data['sales_status'] ?? '') === '' && preg_match('/ขายพร้อมผู้เช่า|พร้อมผู้เช่า/u', $text)) {
        $data['sales_status'] = mdf_normalize_sales_status('sale with tenant');
    }

    if (($data['owner_urgency'] ?? '') === '' && preg_match('/เกรด\s*([ABC])/ui', $text, $m)) {
        $data['owner_urgency'] = mdf_normalize_potential($m[1]);
    }

    if (($data['selling_reason'] ?? '') === '' && preg_match('/ขายเพราะ(?:ว่า)?\s*(.+?)(?=ไทม์ไลน์|เกรด|อัป?เดต|$)/uis', $text, $m)) {
        $data['selling_reason'] = trim(preg_replace('/\s+/u', ' ', $m[1]));
    }

    if (($data['selling_timeline'] ?? '') === '' && preg_match('/(?:ไทม์ไลน์|กรอบเวลา)(?:การขาย)?\s*(.+?)(?=ไม่รีบ|เกรด|อัป?เดต|$)/uis', $text, $m)) {
        $data['selling_timeline'] = trim(preg_replace('/\s+/u', ' ', $m[1]));
    }

    if (($data['contact_summary'] ?? '') === '' && preg_match('/อัป?เดต(?:อีกที)?\s*(.+)$/ui', $text, $m)) {
        $data['contact_summary'] = 'อัปเดต ' . trim($m[1]);
    }
    if (($data['next_follow_date'] ?? '') === '' && ($data['contact_summary'] ?? '') !== '') {
        $normDate = mdf_normalize_date($data['contact_summary']);
        if ($normDate) {
            $data['next_follow_date'] = $normDate;
        }
    }

    if (($data['asking_price'] ?? '') === '' && preg_match('/(\d+(?:\.\d+)?)\s*ล้าน/u', $text, $m)) {
        $data['asking_price'] = mdf_normalize_price((string)((float)$m[1] * 1000000));
    }

    foreach (['name_th', 'name_en'] as $nk) {
        if (($data[$nk] ?? '') !== '') {
            $data[$nk] = trim(preg_replace('/^(ขายพร้อมผู้เช่า|ขาย|เช่า|ปล่อยเช่า)\s+/u', '', $data[$nk]));
        }
    }

    return $data;
}

/** @return array{ok:bool,errors:list<string>,data:array<string,string>,source:string} */
function listing_ai_parse_owner(string $text): array
{
    $text = trim($text);
    if ($text === '') {
        return ['ok' => false, 'errors' => ['ข้อความว่าง'], 'data' => [], 'source' => 'none'];
    }

    $today = date('Y-m-d');
    $system = <<<PROMPT
คุณสกัดข้อมูลทรัพย์อสังหาริมทรัพย์จากข้อความดิบภาษาไทย/อังกฤษ ส่งกลับเฉพาะ JSON object เดียว ห้ามมีข้อความอื่น

ฟิลด์ (ใส่ null ถ้าไม่พบ):
name_th, name_en, area_sqwa, area_sqm, bed, bath, parking, maid, unit_no, map_url, direction,
code_list (รหัสทรัพย์เช่น TAN617), owner_name, phone, line_id,
listing_date, marketing_date, owner_price, asking_price, rental_price, transfer_fee,
sales_status (Sale|sale&available|sale with tenant|rent), owner_urgency (A|B|C จาก Owner เกรด),
selling_reason, selling_timeline (กรอบเวลาขาย เช่น 3-5 เดือน),
contact_summary (แผนติดตาม/อัปเดตถัดไป เช่น ดูตลาดแล้วรายงาน),
next_follow_date (YYYY-MM-DD จากข้อความเช่น อีก 1 สัปดาห์),
photos_link, property_type, zone, soi, floor

กฎ:
- วันนี้คือ {$today} — ถ้ามี "อีก N วัน/สัปดาห์/เดือน" ให้คำนวณ next_follow_date เป็นวันที่จริง
- ราคาเป็นตัวเลขล้วนไม่มีล้าน/หมื่น (แปลง 5 ล้าน → 5000000)
- ชื่อโครงการมักเป็น "ชื่อไทย / English" — แยก name_th กับ name_en เสมอ
- owner_name มักเป็น "คุณ..." หน้าเบอร์โทร
- สกัดทุกฟิลด์ที่มีในข้อความ ห้ามทิ้งว่างถ้าหาได้
- รหัสทรัพย์ uppercase ไม่มีช่องว่าง
PROMPT;

    $source = 'heuristic';
    $data = [];

    $raw = listing_ai_call($system, $text);
    if (is_array($raw)) {
        $data = listing_ai_normalize_owner_row($raw);
        $source = 'ai';
    } elseif (function_exists('owner_line_parse_markdown')) {
        $md = owner_line_parse_markdown($text);
        if (!empty($md['data'])) {
            $data = $md['data'];
            $source = 'markdown';
        }
    }

    $data = listing_ai_owner_enrich_from_text($text, $data);
    if ($source === 'ai' || $source === 'markdown') {
        $source .= '+heuristic';
    }

    $check = listing_ai_owner_validate($data);
    if ($data === []) {
        return ['ok' => false, 'errors' => ['AI อ่านข้อความไม่ได้ ลองใหม่อีกครั้ง'], 'data' => [], 'source' => 'fail'];
    }

    return [
        'ok' => $check['errors'] === [],
        'errors' => $check['errors'],
        'data' => $data,
        'source' => $source,
    ];
}

/** @param array<string,string> $data @return array{errors:list<string>,missing:list<string>} */
function listing_ai_owner_validate(array $data): array
{
    $errors = [];
    $missing = [];
    if (($data['code_list'] ?? '') === '') {
        $errors[] = 'รหัสทรัพย์';
        $missing[] = 'code_list';
    }
    if (($data['owner_name'] ?? '') === '') {
        $errors[] = 'ชื่อ Owner';
        $missing[] = 'owner_name';
    }
    if (($data['name_th'] ?? '') === '' && ($data['name_en'] ?? '') === '') {
        $errors[] = 'ชื่อโครงการ';
        $missing[] = 'project_name';
    }
    return ['errors' => $errors, 'missing' => $missing];
}

/** @param array<string,mixed> $raw */
function listing_ai_normalize_lead_row(array $raw): array
{
    $data = [
        'lead_name' => listing_ai_str($raw['lead_name'] ?? $raw['name'] ?? ''),
        'phone' => listing_ai_str($raw['phone'] ?? ''),
        'line_id' => listing_ai_str($raw['line_id'] ?? ''),
        'background' => listing_ai_str($raw['background'] ?? ''),
        'pain_point' => listing_ai_str($raw['pain_point'] ?? ''),
        'budget' => listing_ai_str($raw['budget'] ?? ''),
        'financials' => listing_ai_str($raw['financials'] ?? $raw['finance'] ?? ''),
        'target_date' => listing_ai_str($raw['target_date'] ?? $raw['timeline'] ?? ''),
        'potential' => listing_ai_str($raw['potential'] ?? ''),
        'project' => listing_ai_str($raw['project'] ?? $raw['interested_project'] ?? ''),
        'owner_code' => listing_ai_str($raw['owner_code'] ?? $raw['listing_code'] ?? ''),
        'current_update' => listing_ai_str($raw['current_update'] ?? ''),
        'next_plan_date' => listing_ai_str($raw['next_plan_date'] ?? ''),
        'next_plan_action' => listing_ai_str($raw['next_plan_action'] ?? ''),
        'status' => listing_ai_str($raw['status'] ?? $raw['stage'] ?? ''),
        'photos_link' => listing_ai_str($raw['photos_link'] ?? $raw['chat_photos_link'] ?? ''),
        'requirement' => listing_ai_str($raw['requirement'] ?? ''),
    ];
    $data['phone'] = normalize_phone_string($data['phone']);
    $data['line_id'] = normalize_line_id_string($data['line_id']);
    $data['owner_code'] = TanWorkbookImport::normalizeListingCode($data['owner_code']);
    if ($data['potential'] !== '') {
        $data['potential'] = mdf_normalize_potential($data['potential']);
    }
    return $data;
}

/** @return array{ok:bool,errors:list<string>,data:array<string,string>,source:string} */
function listing_ai_parse_lead(string $text): array
{
    $text = trim($text);
    if ($text === '') {
        return ['ok' => false, 'errors' => ['ข้อความว่าง'], 'data' => [], 'source' => 'none'];
    }

    $system = <<<'PROMPT'
คุณสกัดข้อมูล Lead ลูกค้าอสังหาริมทรัพย์จากข้อความดิบ ส่งกลับเฉพาะ JSON object เดียว

ฟิลด์ (null ถ้าไม่พบ):
lead_name, phone, line_id, background, pain_point, budget, financials, target_date (timeline),
potential (A|B|C), project, owner_code, current_update, next_plan_date, next_plan_action,
status (Call|Follow|Appointment|Show|Nego|Reserve|Close|Bank|Win), photos_link, requirement
PROMPT;

    $raw = listing_ai_call($system, $text);
    if (is_array($raw)) {
        $data = listing_ai_normalize_lead_row($raw);
        $errors = ($data['lead_name'] ?? '') === '' ? ['ชื่อลูกค้า'] : [];
        $missing = ($data['lead_name'] ?? '') === '' ? ['lead_name'] : [];
        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'data' => $data,
            'source' => 'ai',
            'missing' => $missing,
        ];
    }

    if (function_exists('lead_line_parse_markdown')) {
        $md = lead_line_parse_markdown($text);
        return [
            'ok' => $md['ok'],
            'errors' => $md['errors'],
            'data' => $md['data'],
            'source' => 'markdown',
            'missing' => $md['ok'] ? [] : ['lead_name'],
        ];
    }

    return ['ok' => false, 'errors' => ['AI อ่านข้อความไม่ได้'], 'data' => [], 'source' => 'fail', 'missing' => []];
}

/** แมปฟิลด์สำหรับ Dashboard product edit (เติมช่องว่างเท่านั้น) */
function listing_ai_owner_dashboard_map(array $data): array
{
    return array_filter([
        'owner_name' => $data['owner_name'] ?? '',
        'phone' => $data['phone'] ?? '',
        'line_id' => $data['line_id'] ?? '',
        'name_en' => $data['name_en'] ?? '',
        'name_th' => $data['name_th'] ?? '',
        'property_type' => $data['property_type'] ?? '',
        'zone' => $data['zone'] ?? '',
        'soi' => $data['soi'] ?? '',
        'unit_no' => $data['unit_no'] ?? '',
        'floor' => $data['floor'] ?? '',
        'direction' => $data['direction'] ?? '',
        'map_url' => $data['map_url'] ?? '',
        'price' => $data['asking_price'] ?? '',
        'rent' => $data['rental_price'] ?? '',
        'owner_price' => $data['owner_price'] ?? '',
        'sales_status' => $data['sales_status'] ?? '',
        'owner_urgency' => $data['owner_urgency'] ?? '',
        'transfer_fee' => $data['transfer_fee'] ?? '',
        'photos_link' => $data['photos_link'] ?? '',
        'contact_summary' => $data['selling_reason'] ?? '',
    ], static fn ($v) => trim((string)$v) !== '');
}

/** แมปฟิลด์สำหรับ Dashboard lead case edit */
function listing_ai_lead_dashboard_map(array $data): array
{
    return array_filter([
        'background' => $data['background'] ?? '',
        'pain_point' => $data['pain_point'] ?? '',
        'requirement' => $data['requirement'] ?? '',
        'financials' => $data['financials'] ?? '',
        'timeline' => $data['target_date'] ?? '',
        'budget' => $data['budget'] ?? '',
        'potential' => $data['potential'] ?? '',
        'owner_code' => $data['owner_code'] ?? '',
    ], static fn ($v) => trim((string)$v) !== '');
}

function listing_ai_detect_supplement(string $text): array
{
    $text = trim($text);
    $out = [];
    if (preg_match('#https?://\S+#i', $text, $m)) {
        $url = rtrim($m[0], '.,;)');
        if (stripos($url, 'drive.google.com') !== false || stripos($url, 'docs.google.com') !== false) {
            $out['photos_link'] = $url;
        } elseif (function_exists('map_is_google_maps_url') && map_is_google_maps_url($url)) {
            $out['map_url'] = $url;
        }
    }
    return $out;
}
