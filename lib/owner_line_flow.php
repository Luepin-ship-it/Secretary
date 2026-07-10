<?php
/**
 * LINE — เพิ่ม Project: วางข้อความดิบ → AI สกัด → Flex ยืนยัน → บันทึก
 * สร้างใน LINE เท่านั้น · Dashboard แก้ไขอย่างเดียว
 */

require_once __DIR__ . '/listing_ai_parser.php';
require_once __DIR__ . '/markdown_field_parser.php';
require_once __DIR__ . '/owner_field_normalize.php';
require_once __DIR__ . '/contact_normalize.php';
require_once __DIR__ . '/map_coords.php';
require_once __DIR__ . '/listing_code_seq.php';
require_once __DIR__ . '/liff_project_search.php';
require_once __DIR__ . '/liff_project_photos.php';
require_once __DIR__ . '/owner_line_photo_flow.php';
require_once __DIR__ . '/owner_line_success_flex.php';
require_once __DIR__ . '/tan_workbook_import.php';
require_once __DIR__ . '/line_messaging.php';
require_once __DIR__ . '/../task_helpers.php';
require_once __DIR__ . '/owner_photos_store.php';

function owner_line_ensure_schema(mysqli $conn): void
{
    owner_photos_ensure_schema($conn);
    $conn->query("CREATE TABLE IF NOT EXISTS owner_line_drafts (
        user_id INT NOT NULL PRIMARY KEY,
        step VARCHAR(32) NOT NULL DEFAULT 'await_paste',
        parsed_enc TEXT NULL,
        pending_field VARCHAR(32) NULL,
        lat DECIMAL(10,7) DEFAULT NULL,
        lng DECIMAL(10,7) DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    foreach (['parsed_enc', 'pending_field'] as $col) {
        $chk = $conn->query("SHOW COLUMNS FROM owner_line_drafts LIKE '{$col}'");
        if ($chk && $chk->num_rows === 0) {
            if ($col === 'parsed_enc') {
                $conn->query('ALTER TABLE owner_line_drafts ADD COLUMN parsed_enc TEXT NULL AFTER step');
            } else {
                $conn->query('ALTER TABLE owner_line_drafts ADD COLUMN pending_field VARCHAR(32) NULL AFTER parsed_enc');
            }
        }
    }
}

function owner_line_has_draft(mysqli $conn, int $user_id): bool
{
    $stmt = $conn->prepare('SELECT 1 FROM owner_line_drafts WHERE user_id = ? LIMIT 1');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $ok;
}

/** @return array<string,mixed>|null */
function owner_line_get_draft(mysqli $conn, int $user_id, ?string $key = null): ?array
{
    $stmt = $conn->prepare('SELECT step, parsed_enc, pending_field, lat, lng FROM owner_line_drafts WHERE user_id = ? LIMIT 1');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return null;
    }
    $parsed = [];
    if ($key && !empty($row['parsed_enc'])) {
        $dec = decrypt_data($row['parsed_enc'], $key);
        if (is_string($dec) && $dec !== '') {
            $parsed = json_decode($dec, true) ?: [];
        }
    }
    return [
        'step' => $row['step'] ?? 'await_paste',
        'parsed' => $parsed,
        'pending_field' => $row['pending_field'] ?? '',
        'lat' => isset($row['lat']) ? (float)$row['lat'] : null,
        'lng' => isset($row['lng']) ? (float)$row['lng'] : null,
    ];
}

function owner_line_store_draft(
    mysqli $conn,
    int $user_id,
    string $step,
    array $parsed,
    ?string $key,
    ?string $pendingField = null,
    ?float $lat = null,
    ?float $lng = null
): void {
    $parsedEnc = null;
    if ($key && $parsed) {
        $parsedEnc = encrypt_data(json_encode($parsed, JSON_UNESCAPED_UNICODE), $key);
    }
    $stmt = $conn->prepare(
        'INSERT INTO owner_line_drafts (user_id, step, parsed_enc, pending_field, lat, lng)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE step=VALUES(step), parsed_enc=VALUES(parsed_enc),
             pending_field=VALUES(pending_field), lat=VALUES(lat), lng=VALUES(lng)'
    );
    $pendingField = $pendingField ?? '';
    $latBind = $lat !== null ? $lat : 0.0;
    $lngBind = $lng !== null ? $lng : 0.0;
    $stmt->bind_param('isssdd', $user_id, $step, $parsedEnc, $pendingField, $latBind, $lngBind);
    $stmt->execute();
    $stmt->close();
}

function owner_line_clear_draft(mysqli $conn, int $user_id): void
{
    $stmt = $conn->prepare('DELETE FROM owner_line_drafts WHERE user_id = ?');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stmt->close();
}

function owner_line_prompt_text(): string
{
    return "💡 แนะนำให้มีข้อมูลหลักเหล่านี้ เพื่อความแม่นยำ:\n"
        . "• ชื่อโครงการ (ไทย/อังกฤษ)\n"
        . "• ขนาดพื้นที่ (ตร.ว. หรือ ตร.ม.)\n"
        . "• จำนวนห้อง (นอน/น้ำ/ที่จอดรถ)\n"
        . "• ราคาขาย หรือ ราคาเช่า และเงื่อนไขการโอน\n"
        . "• ชื่อ และเบอร์โทร Owner / ลิงก์แผนที่\n"
        . "• ไทม์ไลน์และเหตุผลการขาย\n\n"
        . "📍 ส่งพิกัด LINE หรือลิงก์แผนที่ได้\n\n"
        . "🖼 หลังวางข้อความจะถามว่ามีรูปหรือยัง\n"
        . "· มีรูป → อัปและเรียงใน Webapp (ชื่อ 1, 2, 3...)\n"
        . "· ยังไม่มี → เลือกวันนัดอัป → สร้าง Task\n\n"
        . "พิมพ์「ยกเลิก」เพื่อยกเลิก";
}

function owner_line_send_prompt(string $replyToken, string $text = ''): void
{
    if (!function_exists('quick_reply_send')) {
        return;
    }
    quick_reply_send($replyToken, [[
        'type' => 'text',
        'text' => $text !== '' ? $text : owner_line_prompt_text(),
    ]], 'project_sub');
}

function owner_line_missing_prompt(string $field, ?string $suggestedNext = null): string
{
    $map = [
        'code_list' => '🔑 รหัสทรัพย์ถัดไปคืออะไร?',
        'owner_name' => '👤 ชื่อเจ้าของทรัพย์?',
        'project_name' => '🏠 ชื่อโครงการ (ไทยหรืออังกฤษ)?',
    ];
    $base = $map[$field] ?? ('กรุณาระบุข้อมูลเพิ่ม: ' . $field);
    if ($field === 'code_list' && $suggestedNext !== null && $suggestedNext !== '') {
        $base .= "\n\n▶ แนะนำ: {$suggestedNext}";
    }
    return $base;
}

function owner_line_apply_missing(array $data, string $field, string $answer): array
{
    $answer = trim($answer);
    if ($field === 'code_list') {
        $data['code_list'] = TanWorkbookImport::normalizeListingCode($answer);
    } elseif ($field === 'owner_name') {
        $data['owner_name'] = $answer;
    } elseif ($field === 'project_name') {
        if (strpos($answer, '/') !== false) {
            $parts = preg_split('/\s*\/\s*/', $answer, 2);
            $data['name_th'] = trim($parts[0] ?? '');
            $data['name_en'] = trim($parts[1] ?? '');
        } elseif (preg_match('/[a-z]/i', $answer) && !preg_match('/[\x{0E00}-\x{0E7F}]/u', $answer)) {
            $data['name_en'] = $answer;
        } else {
            $data['name_th'] = $answer;
        }
    }
    return $data;
}

function owner_line_next_missing(array $data): string
{
    $check = listing_ai_owner_validate($data);
    return $check['missing'][0] ?? '';
}

function owner_line_fmt_price(string $v): string
{
    $v = trim($v);
    if ($v === '' || !ctype_digit(str_replace(',', '', $v))) {
        return $v !== '' ? $v : '-';
    }
    return number_format((int)str_replace(',', '', $v));
}

function owner_line_confirm_flex(array $data, ?array $seq = null): array
{
    $c = listing_code_seq_colors();
    $project = trim($data['name_th'] ?? '') ?: trim($data['name_en'] ?? '') ?: '-';
    $code = trim($data['code_list'] ?? '') ?: '-';
    $owner = trim($data['owner_name'] ?? '') ?: '-';
    $phone = trim($data['phone'] ?? '') ?: '-';
    $spec = [];
    if (($data['bed'] ?? '') !== '') {
        $spec[] = $data['bed'] . ' นอน';
    }
    if (($data['bath'] ?? '') !== '') {
        $spec[] = $data['bath'] . ' น้ำ';
    }
    if (($data['area_sqwa'] ?? '') !== '') {
        $spec[] = $data['area_sqwa'] . ' ตร.ว.';
    }
    $specTxt = $spec ? implode(' · ', $spec) : '-';
    $price = owner_line_fmt_price($data['asking_price'] ?? $data['owner_price'] ?? '');
    $codeLine = $code;
    if ($seq && ($seq['next_code'] ?? '') !== '' && strcasecmp($code, $seq['next_code']) === 0) {
        $codeLine = $code . ' ✓ ลำดับถูกต้อง';
    }

    $body = [
        ['type' => 'text', 'text' => 'ตรวจสอบก่อนบันทึก', 'weight' => 'bold', 'size' => 'lg', 'color' => $c['green_dark']],
        ['type' => 'separator', 'margin' => 'lg', 'color' => $c['separator']],
        ['type' => 'text', 'text' => $project, 'weight' => 'bold', 'size' => 'lg', 'wrap' => true, 'color' => $c['text'], 'margin' => 'md'],
        ['type' => 'text', 'text' => 'รหัส ' . $codeLine, 'size' => 'sm', 'color' => $c['green_dark'], 'wrap' => true, 'margin' => 'sm'],
        ['type' => 'text', 'text' => 'Owner: ' . $owner . ' · ' . $phone, 'size' => 'sm', 'wrap' => true, 'color' => $c['text_muted'], 'margin' => 'sm'],
        ['type' => 'text', 'text' => $specTxt, 'size' => 'sm', 'wrap' => true, 'color' => $c['text_muted'], 'margin' => 'sm'],
        ['type' => 'text', 'text' => 'ราคา: ' . $price, 'size' => 'sm', 'weight' => 'bold', 'wrap' => true, 'color' => $c['text'], 'margin' => 'md'],
    ];

    $reason = trim($data['selling_reason'] ?? '');
    if ($reason !== '') {
        $body[] = ['type' => 'text', 'text' => 'เหตุผลขาย: ' . $reason, 'size' => 'sm', 'wrap' => true, 'color' => $c['text_muted'], 'margin' => 'sm'];
    }
    $timeline = trim($data['selling_timeline'] ?? '');
    if ($timeline !== '') {
        $body[] = ['type' => 'text', 'text' => 'กรอบเวลา: ' . $timeline, 'size' => 'sm', 'wrap' => true, 'color' => $c['text_muted'], 'margin' => 'sm'];
    }
    $urgency = trim($data['owner_urgency'] ?? '');
    if ($urgency !== '') {
        $body[] = ['type' => 'text', 'text' => 'Owner เกรด ' . $urgency, 'size' => 'sm', 'wrap' => true, 'color' => $c['text_muted'], 'margin' => 'sm'];
    }
    $followAction = trim($data['contact_summary'] ?? '');
    $followDate = mdf_normalize_date($data['next_follow_date'] ?? '') ?: '';
    if ($followAction !== '') {
        $followLine = 'ติดตาม: ' . $followAction;
        if ($followDate !== '') {
            $followLine .= ' · กำหนด ' . $followDate;
        }
        $body[] = ['type' => 'text', 'text' => $followLine, 'size' => 'sm', 'wrap' => true, 'color' => $c['text_muted'], 'margin' => 'sm'];
    }
    $photoSched = owner_line_photo_schedule_label($data);
    if ($photoSched !== '') {
        $body[] = ['type' => 'text', 'text' => '🖼 นัดอัปรูป: ' . $photoSched, 'size' => 'sm', 'wrap' => true, 'color' => $c['text_muted'], 'margin' => 'sm'];
    }
    if (($data['photo_choice'] ?? '') === 'has' || !empty($data['photos_finalized'])) {
        $body[] = ['type' => 'text', 'text' => '🖼 รูป: อัปและเรียงแล้ว (ชื่อ 1, 2, 3…)', 'size' => 'sm', 'wrap' => true, 'color' => $c['green_dark'], 'margin' => 'sm'];
    }

    $body[] = ['type' => 'text', 'text' => 'กด ✅ บันทึก หรือยกเลิก', 'size' => 'xs', 'color' => $c['text_muted'], 'margin' => 'xl', 'wrap' => true];

    return [
        'type' => 'flex',
        'altText' => 'ยืนยันเพิ่ม Project: ' . $project,
        'contents' => [
            'type' => 'bubble',
            'size' => 'mega',
            'styles' => listing_code_seq_bubble_styles(),
            'body' => ['type' => 'box', 'layout' => 'vertical', 'spacing' => 'md', 'contents' => $body, 'paddingAll' => '20px'],
            'footer' => [
                'type' => 'box',
                'layout' => 'horizontal',
                'spacing' => 'sm',
                'contents' => [
                    [
                        'type' => 'button',
                        'style' => 'primary',
                        'color' => $c['green_dark'],
                        'action' => [
                            'type' => 'postback',
                            'label' => '✅ บันทึก',
                            'data' => 'action=owner_line&op=confirm',
                        ],
                    ],
                    [
                        'type' => 'button',
                        'style' => 'secondary',
                        'action' => [
                            'type' => 'postback',
                            'label' => 'ยกเลิก',
                            'data' => 'action=owner_line&op=cancel',
                        ],
                    ],
                ],
            ],
        ],
    ];
}

function owner_line_confirm_quick_reply(): array
{
    return [
        'items' => [
            [
                'type' => 'action',
                'action' => [
                    'type' => 'postback',
                    'label' => '▸ ✅ บันทึก',
                    'data' => 'action=owner_line&op=confirm',
                    'displayText' => 'บันทึก',
                ],
            ],
            [
                'type' => 'action',
                'action' => [
                    'type' => 'postback',
                    'label' => '← ยกเลิก',
                    'data' => 'action=owner_line&op=cancel',
                    'displayText' => 'ยกเลิก',
                ],
            ],
        ],
    ];
}

function owner_line_send_confirm(string $replyToken, array $data, ?array $seq = null): void
{
    if (!function_exists('quick_reply_send')) {
        return;
    }
    quick_reply_send($replyToken, [
        owner_line_confirm_flex($data, $seq),
        [
            'type' => 'text',
            'text' => 'กด ✅ บันทึก ในการ์ด หรือปุ่ม「▸ ✅ บันทึก」ด้านล่าง',
            'quickReply' => owner_line_confirm_quick_reply(),
        ],
    ], 'project_sub');
}

/**
 * @return array{ok:bool,errors:list<string>,data:array<string,string>}
 */
function owner_line_parse_markdown(string $text): array
{
    $fields = mdf_parse_labeled_text($text);
    $data = [
        'name_th' => mdf_pick($fields, ['ชื่อโครงการ th', 'ชื่อโครงการ']),
        'name_en' => mdf_pick($fields, ['ชื่อโครงการ en']),
        'area_sqwa' => mdf_pick($fields, ['พื้นที่ ตร.ว.', 'ตร.ว.']),
        'area_sqm' => mdf_pick($fields, ['พื้นที่ ตร.ม.', 'ตร.ม.']),
        'bed' => mdf_pick($fields, ['จำนวนห้องนอน', 'ห้องนอน']),
        'bath' => mdf_pick($fields, ['จำนวนห้องน้ำ', 'ห้องน้ำ']),
        'parking' => mdf_pick($fields, ['จำนวนที่จอดรถ', 'ที่จอดรถ']),
        'maid' => mdf_pick($fields, ['ห้องแม่บ้าน/อเนกประสงค์', 'ห้องแม่บ้าน']),
        'unit_no' => mdf_pick($fields, ['เลขที่บ้าน', 'เลขที่']),
        'map_url' => mdf_pick($fields, ['map url', 'แผนที่']),
        'direction' => mdf_pick($fields, ['ทิศ']),
        'code_list' => mdf_pick($fields, ['รหัสทรัพย์', 'รหัส']),
        'owner_name' => mdf_pick($fields, ['ชื่อ owner', 'ชื่อเจ้าของ']),
        'phone' => mdf_pick($fields, ['เบอร์ owner', 'เบอร์']),
        'line_id' => mdf_pick($fields, ['line owner', 'line id']),
        'listing_date' => mdf_pick($fields, ['วันที่ได้ listing']),
        'marketing_date' => mdf_pick($fields, ['วันที่ทำการตลาด']),
        'owner_price' => mdf_pick($fields, ['ราคา owner']),
        'asking_price' => mdf_pick($fields, ['ราคาขาย']),
        'rental_price' => mdf_pick($fields, ['ราคาเช่า']),
        'transfer_fee' => mdf_pick($fields, ['ค่าธรรมเนียมการโอน']),
        'sales_status' => mdf_pick($fields, ['สถานะ']),
        'owner_urgency' => mdf_pick($fields, ['potential']),
        'selling_reason' => mdf_pick($fields, ['เหตุผลในการขาย', 'ขายเพราะ']),
        'selling_timeline' => mdf_pick($fields, ['กรอบเวลาขาย', 'กรอบเวลา']),
        'contact_summary' => mdf_pick($fields, ['แผนติดตาม', 'อัปเดต', 'ติดตาม']),
        'next_follow_date' => mdf_pick($fields, ['วันติดตาม', 'นัดติดตาม']),
        'photos_link' => mdf_pick($fields, ['ลิงก์รูป google drive', 'ลิงก์รูป']),
    ];
    if (($data['next_follow_date'] ?? '') === '' && ($data['contact_summary'] ?? '') !== '') {
        $fromContact = mdf_normalize_date($data['contact_summary']);
        if ($fromContact) {
            $data['next_follow_date'] = $fromContact;
        }
    }
    $data['code_list'] = TanWorkbookImport::normalizeListingCode($data['code_list']);
    $check = listing_ai_owner_validate($data);
    return ['ok' => $check['errors'] === [], 'errors' => $check['errors'], 'data' => $data];
}

function owner_line_enc(?string $key, string $plain): ?string
{
    $plain = trim($plain);
    if ($plain === '' || !$key) {
        return null;
    }
    return encrypt_data($plain, $key);
}

/** โฟลเดอร์ Drive หลักจากหน้าลงทะเบียน */
function owner_line_user_drive_folder_url(array $user): string
{
    $raw = trim((string)($user['google_drive_id'] ?? ''));
    if ($raw === '') {
        return '';
    }
    if (preg_match('#drive\.google\.com/drive(?:/u/\d+)?/folders/([a-zA-Z0-9_-]+)#', $raw, $m)) {
        return 'https://drive.google.com/drive/folders/' . $m[1];
    }
    if (preg_match('#^[a-zA-Z0-9_-]{10,}$#', $raw)) {
        return 'https://drive.google.com/drive/folders/' . $raw;
    }
    return '';
}

function owner_line_drive_folder_hint(string $code, array $data): string
{
    $code = trim($code);
    $name = trim($data['name_th'] ?? '') ?: trim($data['name_en'] ?? '') ?: 'ทรัพย์';
    return $code !== '' ? "{$code} - {$name}" : $name;
}

function owner_line_default_photos_link(array $user, array $data): string
{
    $existing = trim($data['photos_link'] ?? '');
    if ($existing !== '') {
        return $existing;
    }
    return owner_line_user_drive_folder_url($user);
}

function owner_line_profile_liff_url(): string
{
    if (defined('LINE_LIFF_ID') && LINE_LIFF_ID !== '') {
        return 'https://liff.line.me/' . LINE_LIFF_ID;
    }
    return '';
}

function owner_line_reply(string $replyToken, array $user, array $messages, string $qrKind = 'project_sub'): bool
{
    if (!function_exists('send_line_reply')) {
        return false;
    }
    if (count($messages) > 5) {
        $messages = array_slice($messages, 0, 5);
    }
    $http = send_line_reply([
        'replyToken' => $replyToken,
        'messages' => $messages,
    ], $qrKind);
    if ($http >= 200 && $http < 300) {
        return true;
    }
    $lineUid = trim((string)($user['line_user_id'] ?? ''));
    if ($lineUid !== '' && function_exists('line_push_text')) {
        $summary = 'ดำเนินการแล้ว — กด Menu ด้านล่าง';
        foreach ($messages as $msg) {
            if (($msg['type'] ?? '') === 'text' && trim((string)($msg['text'] ?? '')) !== '') {
                $summary = trim((string)$msg['text']);
                break;
            }
        }
        line_push_text($lineUid, $summary, true, $qrKind);
        return true;
    }
    return false;
}

function owner_line_send_success(mysqli $conn, string $replyToken, array $user, array $parsed, array $result): bool
{
    if (!function_exists('quick_reply_attach')) {
        return false;
    }
    $code = $result['code'] ?? '';
    $ownerId = (int)($result['id'] ?? 0);
    $name = ($parsed['name_th'] ?? '') ?: ($parsed['name_en'] ?? '') ?: $code;
    $driveUrl = owner_line_user_drive_folder_url($user);
    $hint = owner_line_drive_folder_hint($code, $parsed);

    $hasFollow = mdf_normalize_date($parsed['next_follow_date'] ?? '') !== ''
        && trim($parsed['contact_summary'] ?? '') !== '';
    $hasPhotoTask = ($parsed['photo_choice'] ?? '') === 'none'
        && mdf_normalize_date($parsed['photo_upload_due_date'] ?? '') !== '';

    $messages = [[
        'type' => 'text',
        'text' => "✅ บันทึกข้อมูลแล้ว\n\nเลื่อนดูการ์ดทรัพย์"
            . (($hasFollow || $hasPhotoTask) ? ' + Task' : '') . " ด้านข้าง",
    ]];

    $successFlex = owner_line_build_success_flex($conn, $user, $code, $ownerId);
    if ($successFlex !== null) {
        $messages[] = $successFlex;
    } else {
        $messages[0]['text'] .= "\n\nรหัส: {$code}\nโครงการ: {$name}";
    }

    $tail = "แก้ไขรายละเอียดเพิ่มได้ที่ Dashboard";
    $uploadUrl = liff_project_photos_url($code, $hint, $driveUrl);
    if ($uploadUrl !== '') {
        $tail = "🖼 อัปรูปทรัพย์ {$code} (โฟลเดอร์ Drive: {$code})\n" . $uploadUrl . "\n\n" . $tail;
    } elseif ($driveUrl !== '') {
        $tail = "🖼 สร้างโฟลเดอร์ {$code} ใน Drive แล้วอัปรูป\n" . $driveUrl . "\n\n" . $tail;
    } elseif (owner_line_profile_liff_url() !== '') {
        $tail = "⚠️ ยังไม่ได้ตั้งโฟลเดอร์ Drive ในโปรไฟล\n" . owner_line_profile_liff_url() . "\n\n" . $tail;
    }
    $messages[] = ['type' => 'text', 'text' => $tail];

    return owner_line_reply($replyToken, $user, $messages, 'main');
}

/** บันทึก Project จาก draft (postback หรือข้อความยืนยัน) */
function owner_line_confirm_save(mysqli $conn, array $user, string $replyToken): bool
{
    $uid = (int)$user['id'];
    $key = $user['encryption_key'] ?? '';
    $draft = owner_line_get_draft($conn, $uid, $key);
    if (!$draft || ($draft['step'] ?? '') !== 'await_confirm') {
        quick_reply_send($replyToken, [[
            'type' => 'text',
            'text' => 'ไม่พบข้อมูลรอบันทึก — กดเพิ่ม Project ใหม่',
        ]], 'main');
        return true;
    }

    try {
        $parsed = $draft['parsed'] ?? [];
        $result = owner_line_insert($conn, $user, $parsed, $draft['lat'] ?? null, $draft['lng'] ?? null);

        if (!$result['ok']) {
            quick_reply_send($replyToken, [[
                'type' => 'text',
                'text' => $result['message'],
                'quickReply' => owner_line_confirm_quick_reply(),
            ]], 'project_sub');
            return true;
        }

        owner_line_clear_draft($conn, $uid);
        owner_line_send_success($conn, $replyToken, $user, $parsed, $result);
    } catch (Throwable $e) {
        @file_put_contents(
            dirname(__DIR__) . '/line_webhook_debug.log',
            '[owner_line_confirm_save] ' . date('Y-m-d H:i:s') . ' | ' . $e->getMessage() . "\n",
            FILE_APPEND
        );
        quick_reply_send($replyToken, [[
            'type' => 'text',
            'text' => "บันทึกไม่สำเร็จชั่วคราว — ลองกด「▸ ✅ บันทึก」อีกครั้ง\nหรือพิมพ์ menu",
            'quickReply' => owner_line_confirm_quick_reply(),
        ]], 'project_sub');
    }
    return true;
}

function owner_line_is_confirm_text(string $text): bool
{
    $t = mb_strtolower(trim($text), 'UTF-8');
    if (in_array($t, [
        'บันทึก',
        'บันทึก project',
        'บันทึกโครงการ',
        'ยืนยัน',
        'confirm',
        'ok',
        '✅',
        '✅ บันทึก',
    ], true)) {
        return true;
    }
    return (bool)preg_match('/^✅?\s*บันทึก(\s*project)?$/u', $t);
}

function owner_line_strip_internal(array $data): array
{
    unset($data['_source_text']);
    return $data;
}

/** @param array<string,string> $data @return array{ok:bool,message:string,code?:string,id?:int} */
function owner_line_insert(mysqli $conn, array $user, array $data, ?float $draft_lat = null, ?float $draft_lng = null): array
{
    $data = owner_line_strip_internal($data);
    $userId = (int)$user['id'];
    $key = $user['encryption_key'] ?? '';
    $code = $data['code_list'] ?? '';
    if ($code === '') {
        return ['ok' => false, 'message' => 'ไม่พบรหัสทรัพย์'];
    }

    $seqErr = listing_code_seq_validate_next($conn, $userId, $code);
    if ($seqErr !== null) {
        return ['ok' => false, 'message' => $seqErr];
    }

    $chk = $conn->prepare('SELECT id FROM owners WHERE user_id = ? AND code_list = ? LIMIT 1');
    $chk->bind_param('is', $userId, $code);
    $chk->execute();
    if ($chk->get_result()->fetch_assoc()) {
        $chk->close();
        return ['ok' => false, 'message' => "รหัสทรัพย์ {$code} มีในระบบแล้ว\nแก้ไขได้ที่ Dashboard (หน้า Products)"];
    }
    $chk->close();

    $nameTh = $data['name_th'] ?? '';
    $nameEn = $data['name_en'] ?? '';
    if ($nameEn === '' && $nameTh !== '') {
        $nameEn = $nameTh;
    }
    $projectEnc = owner_line_enc($key, $nameEn);
    $phone = normalize_phone_string($data['phone'] ?? '');
    $lineId = normalize_line_id_string($data['line_id'] ?? '');
    $listingDate = mdf_normalize_date($data['listing_date'] ?? '') ?: date('Y-m-d');
    $marketingDate = mdf_normalize_date($data['marketing_date'] ?? '');
    $salesStatus = mdf_normalize_sales_status($data['sales_status'] ?? 'Sale');
    $urgency = mdf_normalize_potential($data['owner_urgency'] ?? 'B');
    $transfer = trim($data['transfer_fee'] ?? '') ?: '50/50 Transfer Fee';
    $mapUrl = trim($data['map_url'] ?? '');

    $ownerLat = $draft_lat;
    $ownerLng = $draft_lng;
    if (($ownerLat === null || $ownerLng === null) && $mapUrl !== '') {
        $coords = map_coords_for_import($mapUrl);
        $ownerLat = $coords[0];
        $ownerLng = $coords[1];
    }

    $stripPrice = static fn ($v) => mdf_normalize_price((string)$v);
    $unitNo = normalize_unit_no_string($data['unit_no'] ?? '');
    $direction = sanitize_direction($data['direction'] ?? '');
    $rental = sanitize_rental_price($stripPrice($data['rental_price'] ?? ''));

    $mktStatus = 'ลงการตลาดแล้ว';
    $avail = 'ยังขายอยู่';

    $pOwnerName = owner_line_enc($key, $data['owner_name'] ?? '');
    $pNameTh = owner_line_enc($key, $nameTh);
    $pNameEn = owner_line_enc($key, $nameEn);
    $pPhone = owner_line_enc($key, $phone);
    $pLine = owner_line_enc($key, $lineId);
    $pBed = owner_line_enc($key, $data['bed'] ?? '');
    $pBath = owner_line_enc($key, $data['bath'] ?? '');
    $pUnit = owner_line_enc($key, $unitNo);
    $pSqwa = owner_line_enc($key, $data['area_sqwa'] ?? '');
    $pSqm = owner_line_enc($key, $data['area_sqm'] ?? '');
    $pPark = owner_line_enc($key, $data['parking'] ?? '');
    $pMaid = owner_line_enc($key, $data['maid'] ?? '');
    $pDir = owner_line_enc($key, $direction);
    $pAsk = owner_line_enc($key, $stripPrice($data['asking_price'] ?? ''));
    $pRent = owner_line_enc($key, $rental);
    $pOwnerAsk = owner_line_enc($key, $stripPrice($data['owner_price'] ?? ''));
    $pMap = owner_line_enc($key, $mapUrl);
    $photosLink = owner_line_default_photos_link($user, $data);
    $pPhotos = owner_line_enc($key, $photosLink);
    $pTimeline = owner_line_enc($key, $data['selling_timeline'] ?? '');
    $pContact = owner_line_enc($key, $data['contact_summary'] ?? '');
    $pReason = owner_line_enc($key, $data['selling_reason'] ?? '');

    $sql = 'INSERT INTO owners (
        user_id, code_list, owner_name_enc, project_name_th_enc, project_name_en_enc, project_enc,
        listing_date, marketing_status, marketing_date, phone_enc, line_id_enc,
        bed_enc, bath_enc, unit_no_enc, area_sqwa_enc, area_sqm_enc,
        parking_enc, maid_enc, direction_enc, asking_price_enc, rental_price_enc, owner_asking_price_enc,
        map_url_enc, photos_link_enc, selling_timeline_enc, contact_summary_enc, selling_reason_enc,
        availability_status, sales_status, owner_urgency, selling_condition, lat, lng
    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';

    $st = $conn->prepare($sql);
    $latBind = $ownerLat !== null ? $ownerLat : 0.0;
    $lngBind = $ownerLng !== null ? $ownerLng : 0.0;
    $ownerTypes = 'i' . str_repeat('s', 30) . 'dd';
    $st->bind_param(
        $ownerTypes,
        $userId, $code, $pOwnerName, $pNameTh, $pNameEn, $projectEnc,
        $listingDate, $mktStatus, $marketingDate, $pPhone, $pLine,
        $pBed, $pBath, $pUnit, $pSqwa, $pSqm, $pPark, $pMaid, $pDir,
        $pAsk, $pRent, $pOwnerAsk, $pMap, $pPhotos, $pTimeline, $pContact, $pReason,
        $avail, $salesStatus, $urgency, $transfer, $latBind, $lngBind
    );

    if (!$st->execute()) {
        $st->close();
        return ['ok' => false, 'message' => 'บันทึกไม่สำเร็จ กรุณาลองใหม่'];
    }
    $ownerId = (int)$conn->insert_id;
    $st->close();

    if ($mapUrl !== '' && $ownerId > 0) {
        owner_apply_map_coords($conn, $userId, $ownerId, $code, $mapUrl);
    } elseif ($ownerLat !== null && $ownerLng !== null && $code !== '') {
        owner_sync_lead_coords($conn, $userId, $code, $ownerLat, $ownerLng);
    }

    $followDate = mdf_normalize_date($data['next_follow_date'] ?? '');
    $followAction = trim($data['contact_summary'] ?? '');
    if ($followDate && $followAction !== '') {
        sync_owner_follow_task(
            $conn,
            $userId,
            $key,
            $code,
            trim($data['owner_name'] ?? '') ?: $code,
            $followAction,
            $followDate
        );
    }

    $photoDue = mdf_normalize_date($data['photo_upload_due_date'] ?? '');
    if ($photoDue !== '' && ($data['photo_choice'] ?? '') === 'none') {
        sync_owner_photo_upload_task(
            $conn,
            $userId,
            $key,
            $code,
            trim($data['owner_name'] ?? '') ?: $code,
            $photoDue,
            trim($data['photo_upload_due_time'] ?? '')
        );
    }

    if (($data['photo_choice'] ?? '') === 'has' || owner_photos_list($conn, $userId, $code) !== []) {
        owner_photos_finalize($conn, $userId, $code);
        owner_photos_apply_to_owner($conn, $userId, $code, $ownerId);
    }

    return ['ok' => true, 'message' => 'เพิ่ม Project เรียบร้อย', 'code' => $code, 'id' => $ownerId];
}

function owner_line_start_add(mysqli $conn, array $user, string $replyToken): void
{
    if (function_exists('lead_line_clear_draft')) {
        lead_line_clear_draft($conn, (int)$user['id']);
    }
    owner_line_store_draft($conn, (int)$user['id'], 'await_paste', [], $user['encryption_key'] ?? null);

    $seq = listing_code_seq_for_user($conn, (int)$user['id']);
    $messages = [listing_code_seq_flex_message($seq)];
    $prompt = owner_line_prompt_text();
    if ($seq['has_sequence']) {
        $prompt = "▶ รหัสถัดไป: {$seq['next_code']} (ล่าสุด {$seq['last_code']})\n\n" . $prompt;
    }
    $messages[] = ['type' => 'text', 'text' => $prompt];

    $searchMsg = liff_project_search_line_message();
    if ($searchMsg !== null) {
        $messages[] = $searchMsg;
    }

    if (function_exists('quick_reply_send')) {
        quick_reply_send($replyToken, $messages, 'project_sub');
    }
}

function owner_line_cancel(mysqli $conn, int $user_id, string $replyToken): void
{
    owner_line_clear_draft($conn, $user_id);
    if (function_exists('quick_reply_send')) {
        quick_reply_send($replyToken, [['type' => 'text', 'text' => 'ยกเลิกการเพิ่ม Project แล้ว']], 'project_sub');
    }
}

function owner_line_process_parsed(mysqli $conn, array $user, string $replyToken, array $data, ?float $lat, ?float $lng): void
{
    $uid = (int)$user['id'];
    $key = $user['encryption_key'] ?? '';
    $seq = listing_code_seq_for_user($conn, $uid);

    if (!empty($data['_source_text'])) {
        $data = listing_ai_owner_enrich_from_text((string)$data['_source_text'], $data);
    }

    if (($data['code_list'] ?? '') === '' && $seq['next_code'] !== '') {
        $data['code_list'] = $seq['next_code'];
    }

    $missing = owner_line_next_missing($data);
    if ($missing !== '') {
        $suggest = $missing === 'code_list' ? $seq['next_code'] : null;
        owner_line_store_draft($conn, $uid, 'await_missing', $data, $key, $missing, $lat, $lng);
        owner_line_send_prompt(
            $replyToken,
            owner_line_missing_prompt($missing, $suggest) . "\n\nพิมพ์「ยกเลิก」เพื่อยกเลิก"
        );
        return;
    }

    if (($data['code_list'] ?? '') !== '') {
        $seqErr = listing_code_seq_validate_next($conn, $uid, $data['code_list']);
        if ($seqErr !== null) {
            owner_line_store_draft($conn, $uid, 'await_missing', $data, $key, 'code_list', $lat, $lng);
            owner_line_send_prompt(
                $replyToken,
                $seqErr . "\n\nพิมพ์รหัสที่ถูกต้อง หรือ「ยกเลิก」"
            );
            return;
        }
    }

    owner_line_store_draft($conn, $uid, 'await_photo_choice', $data, $key, null, $lat, $lng);
    owner_line_ask_photo_choice($replyToken, $data);
}

function owner_line_is_menu_escape(string $text): bool
{
    $t = mb_strtolower(trim($text), 'UTF-8');
    return in_array($t, ['menu', 'เมนู', 'main menu', 'main'], true);
}

/** @return bool */
function owner_line_handle_text(mysqli $conn, array $user, string $text, string $replyToken): bool
{
    $uid = (int)$user['id'];
    $key = $user['encryption_key'] ?? '';
    $draft = owner_line_get_draft($conn, $uid, $key);
    $lower = mb_strtolower(trim($text), 'UTF-8');

    if (owner_line_is_menu_escape($text) && $draft) {
        owner_line_clear_draft($conn, $uid);
        if (function_exists('quick_reply_dispatch')) {
            quick_reply_dispatch($conn, $user, 'menu', [], $replyToken);
            return true;
        }
    }

    if (in_array($lower, ['ยกเลิก', 'cancel'], true)) {
        if ($draft) {
            owner_line_cancel($conn, $uid, $replyToken);
            return true;
        }
        return false;
    }

    if (!$draft) {
        if (owner_line_is_confirm_text($text)) {
            quick_reply_send($replyToken, [[
                'type' => 'text',
                'text' => "ไม่พบข้อมูลรอบันทึก — อาจบันทึกไปแล้ว\n"
                    . "ลองกด Project → เลขที่บ้าน/โลเคชั่น ดูทรัพย์\n"
                    . "หรือกดเพิ่ม Project ใหม่",
            ]], 'main');
            return true;
        }
        return false;
    }

    $step = $draft['step'] ?? 'await_paste';
    $parsed = is_array($draft['parsed'] ?? null) ? $draft['parsed'] : [];
    $lat = $draft['lat'] ?? null;
    $lng = $draft['lng'] ?? null;

    if ($step === 'await_photo_upload') {
        if (in_array($lower, ['อัปรูปเสร็จแล้ว', 'เสร็จแล้ว', 'พร้อม', 'done'], true)) {
            return owner_line_handle_photo_done($conn, $user, $replyToken, $parsed, $lat, $lng);
        }
        quick_reply_send($replyToken, [[
            'type' => 'text',
            'text' => "รออัปรูปใน Webapp\n"
                . "1) เปิดอัปรูป → เรียง →「ตั้งชื่อ 1, 2, 3…」\n"
                . "2) คัดลอกชื่อโฟลเดอร์ (รหัสทรัพย์) → เปิด Drive → สร้างโฟลเดอร์ → อัปรูป\n"
                . "3) กลับแชทกด「อัปรูปเสร็จแล้ว」",
            'quickReply' => owner_line_photo_upload_done_quick_reply(),
        ]], 'project_sub');
        return true;
    }

    if ($step === 'await_photo_schedule') {
        quick_reply_send($replyToken, [[
            'type' => 'text',
            'text' => "เลือกวันเวลาจะอัปรูปจากปุ่มด้านล่าง",
            'quickReply' => owner_line_photo_schedule_quick_reply(),
        ]], 'project_sub');
        return true;
    }

    if ($step === 'await_photo_choice') {
        quick_reply_send($replyToken, [[
            'type' => 'text',
            'text' => "กดปุ่มด้านล่าง: มีรูปแล้ว หรือ ยังไม่มีรูป",
            'quickReply' => owner_line_photo_quick_reply(),
        ]], 'project_sub');
        return true;
    }

    if ($step === 'await_confirm') {
        if (owner_line_is_confirm_text($text)) {
            return owner_line_confirm_save($conn, $user, $replyToken);
        }
        $extra = listing_ai_detect_supplement($text);
        if ($extra) {
            $parsed = array_merge($parsed, array_filter($extra));
            owner_line_store_draft($conn, $uid, 'await_confirm', $parsed, $key, null, $lat, $lng);
            $seq = listing_code_seq_for_user($conn, $uid);
            owner_line_send_confirm($replyToken, $parsed, $seq);
            return true;
        }
        if (mb_strlen(trim($text), 'UTF-8') >= 20) {
            $lineUid = $user['line_user_id'] ?? '';
            if ($lineUid !== '' && function_exists('line_begin_slow_work')) {
                line_begin_slow_work($lineUid, 'listing', $user);
            }
            $ai = listing_ai_parse_owner($text);
            if (!empty($ai['data'])) {
                owner_line_process_parsed($conn, $user, $replyToken, $ai['data'], $lat, $lng);
                return true;
            }
        }
        quick_reply_send($replyToken, [[
            'type' => 'text',
            'text' => "อยู่ขั้นยืนยัน — กด ✅ บันทึก ในการ์ด\nหรือปุ่ม「▸ ✅ บันทึก」ด้านล่าง",
            'quickReply' => owner_line_confirm_quick_reply(),
        ]], 'project_sub');
        return true;
    }

    if ($step === 'await_missing') {
        $field = (string)($draft['pending_field'] ?? '');
        if ($field === '') {
            $field = owner_line_next_missing($parsed);
        }
        $parsed = owner_line_apply_missing($parsed, $field, $text);
        if (!empty($parsed['_source_text'])) {
            $parsed = listing_ai_owner_enrich_from_text((string)$parsed['_source_text'], $parsed);
        }
        owner_line_process_parsed($conn, $user, $replyToken, $parsed, $lat, $lng);
        return true;
    }

    // await_paste
    if (mb_strlen(trim($text), 'UTF-8') < 8) {
        owner_line_send_prompt($replyToken, "ข้อความสั้นเกินไป — วางข้อความจากเจ้าของทรัพย์ทั้งก้อน\n\nพิมพ์「ยกเลิก」เพื่อยกเลิก");
        return true;
    }

    $lineUid = $user['line_user_id'] ?? '';
    if ($lineUid !== '' && function_exists('line_begin_slow_work')) {
        line_begin_slow_work($lineUid, 'listing', $user);
    }

    $ai = listing_ai_parse_owner($text);
    if (empty($ai['data']) && !$ai['ok']) {
        owner_line_send_prompt($replyToken, implode("\n", $ai['errors']) . "\n\nลองวางข้อความใหม่ หรือพิมพ์「ยกเลิก」");
        return true;
    }

    $data = $ai['data'];
    $data['_source_text'] = $text;
    $extra = listing_ai_detect_supplement($text);
    if ($extra) {
        $data = array_merge($data, array_filter($extra));
    }
    owner_line_process_parsed($conn, $user, $replyToken, $data, $lat, $lng);
    return true;
}

/** @return bool */
function owner_line_handle_postback(mysqli $conn, array $user, array $params, string $replyToken, array $postback_params = []): bool
{
    if (($params['action'] ?? '') !== 'owner_line') {
        return false;
    }
    $uid = (int)$user['id'];
    $key = $user['encryption_key'] ?? '';
    $op = $params['op'] ?? '';

    if ($op === 'cancel') {
        owner_line_cancel($conn, $uid, $replyToken);
        return true;
    }

    $draft = owner_line_get_draft($conn, $uid, $key);
    if (!$draft) {
        return false;
    }
    $parsed = is_array($draft['parsed'] ?? null) ? $draft['parsed'] : [];
    $lat = $draft['lat'] ?? null;
    $lng = $draft['lng'] ?? null;

    if ($op === 'photo_has') {
        $parsed['photo_choice'] = 'has';
        owner_line_store_draft($conn, $uid, 'await_photo_upload', $parsed, $key, null, $lat, $lng);
        owner_line_send_photo_upload_link($replyToken, $user, $parsed);
        return true;
    }

    if ($op === 'photo_none') {
        $parsed['photo_choice'] = 'none';
        owner_line_store_draft($conn, $uid, 'await_photo_schedule', $parsed, $key, null, $lat, $lng);
        owner_line_ask_photo_schedule($replyToken, $parsed);
        return true;
    }

    if ($op === 'photo_date' && ($params['when'] ?? '') === 'tomorrow') {
        $parsed = owner_line_apply_photo_schedule($parsed, 'tomorrow');
        owner_line_proceed_to_confirm($conn, $user, $replyToken, $parsed, $lat, $lng);
        return true;
    }

    if ($op === 'photo_pick') {
        $dt = $postback_params['datetime'] ?? '';
        $parsed = owner_line_apply_photo_schedule($parsed, 'pick', $dt);
        if (empty($parsed['photo_upload_due_date'])) {
            quick_reply_send($replyToken, [[
                'type' => 'text',
                'text' => 'ไม่ได้รับวันเวลา — ลองเลือกอีกครั้ง',
                'quickReply' => owner_line_photo_schedule_quick_reply(),
            ]], 'project_sub');
            return true;
        }
        owner_line_proceed_to_confirm($conn, $user, $replyToken, $parsed, $lat, $lng);
        return true;
    }

    if ($op === 'photo_done') {
        return owner_line_handle_photo_done($conn, $user, $replyToken, $parsed, $lat, $lng);
    }

    if ($op !== 'confirm') {
        return false;
    }

    return owner_line_confirm_save($conn, $user, $replyToken);
}

function owner_line_proceed_to_confirm(mysqli $conn, array $user, string $replyToken, array $parsed, ?float $lat, ?float $lng): void
{
    $uid = (int)$user['id'];
    $key = $user['encryption_key'] ?? '';
    owner_line_store_draft($conn, $uid, 'await_confirm', $parsed, $key, null, $lat, $lng);
    $seq = listing_code_seq_for_user($conn, $uid);
    owner_line_send_confirm($replyToken, $parsed, $seq);
}

/** @return bool */
function owner_line_handle_photo_done(mysqli $conn, array $user, string $replyToken, array $parsed, ?float $lat, ?float $lng): bool
{
    $uid = (int)$user['id'];
    $code = trim($parsed['code_list'] ?? '');
    $photos = owner_photos_list($conn, $uid, $code);
    if ($photos === []) {
        quick_reply_send($replyToken, [[
            'type' => 'text',
            'text' => "ยังไม่พบรูปที่อัป — กด「เปิดอัปรูป」ใน Webapp ก่อน\nแล้วกด「ตั้งชื่อ 1, 2, 3…」",
            'quickReply' => owner_line_photo_upload_done_quick_reply(),
        ]], 'project_sub');
        return true;
    }
    $final = owner_photos_finalize($conn, $uid, $code);
    if (empty($final['ok'])) {
        quick_reply_send($replyToken, [[
            'type' => 'text',
            'text' => 'ยังจัดรูปไม่สำเร็จ — ลองอีกครั้งใน Webapp',
            'quickReply' => owner_line_photo_upload_done_quick_reply(),
        ]], 'project_sub');
        return true;
    }
    $parsed['photos_finalized'] = true;
    $parsed['photo_choice'] = 'has';
    owner_line_proceed_to_confirm($conn, $user, $replyToken, $parsed, $lat, $lng);
    return true;
}

/** @return bool */
function owner_line_handle_location(mysqli $conn, array $user, array $message, string $replyToken): bool
{
    $uid = (int)$user['id'];
    $key = $user['encryption_key'] ?? '';
    if (!owner_line_has_draft($conn, $uid)) {
        return false;
    }

    $lat = isset($message['latitude']) ? (float)$message['latitude'] : null;
    $lng = isset($message['longitude']) ? (float)$message['longitude'] : null;
    if ($lat === null || $lng === null) {
        return false;
    }

    $draft = owner_line_get_draft($conn, $uid, $key) ?? ['step' => 'await_paste', 'parsed' => []];
    $step = $draft['step'] ?? 'await_paste';
    $parsed = $draft['parsed'] ?? [];

    if ($step === 'await_confirm') {
        owner_line_store_draft($conn, $uid, 'await_confirm', $parsed, $key, null, $lat, $lng);
        $seq = listing_code_seq_for_user($conn, $uid);
        owner_line_send_confirm($replyToken, $parsed, $seq);
        return true;
    }

    owner_line_store_draft($conn, $uid, $step, $parsed, $key, $draft['pending_field'] ?? null, $lat, $lng);
    quick_reply_send($replyToken, [[
        'type' => 'text',
        'text' => "📍 รับพิกัดแล้ว\n\nวางข้อความจากเจ้าของทรัพย์ต่อได้เลย",
    ]], 'project_sub');
    return true;
}
