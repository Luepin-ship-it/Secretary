<?php
/**
 * LINE — เพิ่ม Lead: วางข้อความดิบ → AI สกัด → Flex ยืนยัน → บันทึก
 */

require_once __DIR__ . '/listing_ai_parser.php';
require_once __DIR__ . '/markdown_field_parser.php';
require_once __DIR__ . '/contact_normalize.php';
require_once __DIR__ . '/lead_code.php';
require_once __DIR__ . '/line_messaging.php';
require_once __DIR__ . '/flex_theme.php';
require_once __DIR__ . '/../task_helpers.php';

function lead_line_ensure_schema(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS lead_line_drafts (
        user_id INT NOT NULL PRIMARY KEY,
        step VARCHAR(32) NOT NULL DEFAULT 'await_paste',
        parsed_enc TEXT NULL,
        pending_field VARCHAR(32) NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    foreach (['parsed_enc', 'pending_field'] as $col) {
        $chk = $conn->query("SHOW COLUMNS FROM lead_line_drafts LIKE '{$col}'");
        if ($chk && $chk->num_rows === 0) {
            if ($col === 'parsed_enc') {
                $conn->query('ALTER TABLE lead_line_drafts ADD COLUMN parsed_enc TEXT NULL AFTER step');
            } else {
                $conn->query('ALTER TABLE lead_line_drafts ADD COLUMN pending_field VARCHAR(32) NULL AFTER parsed_enc');
            }
        }
    }
}

function lead_line_has_draft(mysqli $conn, int $user_id): bool
{
    $stmt = $conn->prepare('SELECT 1 FROM lead_line_drafts WHERE user_id = ? LIMIT 1');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $ok;
}

function lead_line_get_draft(mysqli $conn, int $user_id, ?string $key = null): ?array
{
    $stmt = $conn->prepare('SELECT step, parsed_enc, pending_field FROM lead_line_drafts WHERE user_id = ? LIMIT 1');
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
    ];
}

function lead_line_store_draft(
    mysqli $conn,
    int $user_id,
    string $step,
    array $parsed,
    ?string $key,
    ?string $pendingField = null
): void {
    $parsedEnc = null;
    if ($key && $parsed) {
        $parsedEnc = encrypt_data(json_encode($parsed, JSON_UNESCAPED_UNICODE), $key);
    }
    $stmt = $conn->prepare(
        'INSERT INTO lead_line_drafts (user_id, step, parsed_enc, pending_field)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE step=VALUES(step), parsed_enc=VALUES(parsed_enc), pending_field=VALUES(pending_field)'
    );
    $stmt->bind_param('isss', $user_id, $step, $parsedEnc, $pendingField);
    $stmt->execute();
    $stmt->close();
}

function lead_line_clear_draft(mysqli $conn, int $user_id): void
{
    $stmt = $conn->prepare('DELETE FROM lead_line_drafts WHERE user_id = ?');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stmt->close();
}

function lead_line_prompt_text(): string
{
    return "📋 เพิ่ม Lead — วางข้อความดิบจากลูกค้า/โน้ตได้เลย\n"
        . "(คัดลอกจากแชทมาวางทั้งก้อน ไม่ต้องจัดรูปแบบ)\n\n"
        . "ตัวอย่าง:\n"
        . "คุณนัท 089-876-5432 งบ 4-5 ล้าน สนใจเพฟ รามอินทรา\n"
        . "กู้ได้ 80% อยากย้ายภายใน 3 เดือน\n\n"
        . "พิมพ์「ยกเลิก」เพื่อยกเลิก";
}

function lead_line_send_prompt(string $replyToken, string $text = ''): void
{
    if (!function_exists('quick_reply_send')) {
        return;
    }
    quick_reply_send($replyToken, [[
        'type' => 'text',
        'text' => $text !== '' ? $text : lead_line_prompt_text(),
    ]], 'lead_sub');
}

function lead_line_missing_prompt(string $field): string
{
    return $field === 'lead_name'
        ? '👤 ชื่อลูกค้าคืออะไร?'
        : 'กรุณาระบุ: ' . $field;
}

function lead_line_apply_missing(array $data, string $field, string $answer): array
{
    if ($field === 'lead_name') {
        $data['lead_name'] = trim($answer);
    }
    return $data;
}

function lead_line_confirm_flex(array $data): array
{
    $c = flex_theme_colors();
    $name = trim($data['lead_name'] ?? '') ?: '-';
    $phone = trim($data['phone'] ?? '') ?: '-';
    $budget = trim($data['budget'] ?? '') ?: '-';
    $project = trim($data['project'] ?? '') ?: '-';
    $potential = trim($data['potential'] ?? '') ?: 'B';
    $status = lead_line_normalize_status($data['status'] ?? 'Call');

    $body = [
        flex_theme_text('ตรวจสอบก่อนบันทึก Lead', 'md', 'green_dark', 'none', true),
        ['type' => 'separator', 'margin' => 'md', 'color' => $c['border']],
        flex_theme_text($name, 'lg', 'dark', 'none', true),
        flex_theme_text($phone, 'sm', 'muted'),
        flex_theme_text('งบ: ' . $budget, 'sm', 'text'),
        flex_theme_text('สนใจ: ' . $project, 'sm', 'text'),
        flex_theme_text('Potential ' . $potential . ' · Stage ' . $status, 'sm', 'text'),
        flex_theme_text('กด ✅ บันทึก หรือยกเลิก', 'xs', 'disabled', 'md'),
    ];

    return [
        'type' => 'flex',
        'altText' => 'ยืนยันเพิ่ม Lead: ' . $name,
        'contents' => [
            'type' => 'bubble',
            'size' => 'mega',
            'styles' => flex_theme_bubble_styles(),
            'body' => ['type' => 'box', 'layout' => 'vertical', 'contents' => $body, 'paddingAll' => '16px'],
            'footer' => [
                'type' => 'box',
                'layout' => 'horizontal',
                'spacing' => 'sm',
                'contents' => [
                    [
                        'type' => 'button',
                        'style' => 'primary',
                        'color' => $c['green'],
                        'action' => [
                            'type' => 'postback',
                            'label' => '✅ บันทึก',
                            'data' => 'action=lead_line&op=confirm',
                            'displayText' => 'บันทึก Lead',
                        ],
                    ],
                    [
                        'type' => 'button',
                        'style' => 'secondary',
                        'action' => [
                            'type' => 'postback',
                            'label' => 'ยกเลิก',
                            'data' => 'action=lead_line&op=cancel',
                            'displayText' => 'ยกเลิก',
                        ],
                    ],
                ],
            ],
        ],
    ];
}

function lead_line_send_confirm(string $replyToken, array $data): void
{
    if (!function_exists('quick_reply_send')) {
        return;
    }
    quick_reply_send($replyToken, [lead_line_confirm_flex($data)], 'lead_sub');
}

/** @return array{ok:bool,errors:list<string>,data:array<string,string>} */
function lead_line_parse_markdown(string $text): array
{
    $fields = mdf_parse_labeled_text($text);
    $data = [
        'lead_name' => mdf_pick($fields, ['ชื่อ', 'ชื่อลูกค้า']),
        'phone' => mdf_pick($fields, ['เบอร์', 'โทร']),
        'line_id' => mdf_pick($fields, ['line id', 'line']),
        'background' => mdf_pick($fields, ['background']),
        'pain_point' => mdf_pick($fields, ['pain point', 'pain']),
        'budget' => mdf_pick($fields, ['budget', 'งบ']),
        'financials' => mdf_pick($fields, ['finance', 'financials']),
        'target_date' => mdf_pick($fields, ['timeline']),
        'potential' => mdf_pick($fields, ['potential']),
        'project' => mdf_pick($fields, ['โครงการที่สนใจ', 'โครงการ']),
        'owner_code' => mdf_pick($fields, ['รหัสทรัพย์']),
        'current_update' => mdf_pick($fields, ['แผนอัปเดต']),
        'next_plan_date' => mdf_pick($fields, ['วันที่แผนต่อไป']),
        'next_plan_action' => mdf_pick($fields, ['แผนถัดไป']),
        'status' => mdf_pick($fields, ['stage', 'สถานะ']),
        'photos_link' => mdf_pick($fields, ['ลิงก์รูป']),
        'requirement' => mdf_pick($fields, ['requirement']),
    ];
    if ($data['owner_code'] !== '') {
        require_once __DIR__ . '/tan_workbook_import.php';
        $data['owner_code'] = TanWorkbookImport::normalizeListingCode($data['owner_code']);
    }
    $errors = ($data['lead_name'] ?? '') === '' ? ['ชื่อลูกค้า'] : [];
    return ['ok' => $errors === [], 'errors' => $errors, 'data' => $data];
}

function lead_line_enc(?string $key, string $plain): ?string
{
    $plain = trim($plain);
    if ($plain === '' || !$key) {
        return null;
    }
    return encrypt_data($plain, $key);
}

function lead_line_normalize_status(string $raw): string
{
    $s = trim($raw);
    if ($s === '') {
        return 'Call';
    }
    foreach (lead_funnel_statuses() as $st) {
        if (strcasecmp($s, $st) === 0) {
            return $st;
        }
    }
    return 'Call';
}

/** @param array<string,string> $data @return array{ok:bool,message:string,lead_code?:string,id?:int} */
function lead_line_insert(mysqli $conn, array $user, array $data): array
{
    $userId = (int)$user['id'];
    $key = $user['encryption_key'] ?? '';

    $leadName = trim($data['lead_name'] ?? '');
    $phone = normalize_phone_string($data['phone'] ?? '');
    $lineId = normalize_line_id_string($data['line_id'] ?? '');
    $ownerCode = trim($data['owner_code'] ?? '');
    $leadCode = lead_import_make_code($ownerCode, $phone);

    $chk = $conn->prepare('SELECT id FROM leads WHERE user_id = ? AND lead_code = ? LIMIT 1');
    $chk->bind_param('is', $userId, $leadCode);
    $chk->execute();
    if ($chk->get_result()->fetch_assoc()) {
        $chk->close();
        $leadCode .= '-' . strtoupper(bin2hex(random_bytes(2)));
    } else {
        $chk->close();
    }

    $status = lead_line_normalize_status($data['status'] ?? 'Call');
    $potential = mdf_normalize_potential($data['potential'] ?? 'B');
    $contactDate = date('Y-m-d');
    $nextPlanDate = mdf_normalize_date($data['next_plan_date'] ?? '');

    $pLeadName = lead_line_enc($key, $leadName);
    $pProject = lead_line_enc($key, $data['project'] ?? '');
    $pPhone = lead_line_enc($key, $phone);
    $pLine = lead_line_enc($key, $lineId);
    $pBudget = lead_line_enc($key, $data['budget'] ?? '');
    $pTarget = lead_line_enc($key, $data['target_date'] ?? '');
    $pPain = lead_line_enc($key, $data['pain_point'] ?? '');
    $pFin = lead_line_enc($key, $data['financials'] ?? '');
    $pBg = lead_line_enc($key, $data['background'] ?? '');
    $pUpdate = lead_line_enc($key, $data['current_update'] ?? '');
    $pNextAction = lead_line_enc($key, $data['next_plan_action'] ?? '');
    $pPhotos = lead_line_enc($key, $data['photos_link'] ?? '');
    $pReq = lead_line_enc($key, $data['requirement'] ?? '');

    $sql = 'INSERT INTO leads (
        user_id, lead_code, lead_name_enc, project_enc, phone_enc, line_id_enc, budget_enc, potential,
        contact_date, target_date_enc, pain_point_enc, requirement_enc, financials_enc, background_enc,
        current_update_enc, status, next_plan_action_enc, next_plan_date, owner_code, chat_photos_link_enc
    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';

    $st = $conn->prepare($sql);
    $st->bind_param(
        'isssssssssssssssssss',
        $userId, $leadCode, $pLeadName, $pProject, $pPhone, $pLine, $pBudget, $potential,
        $contactDate, $pTarget, $pPain, $pReq, $pFin, $pBg, $pUpdate, $status,
        $pNextAction, $nextPlanDate, $ownerCode, $pPhotos
    );

    if (!$st->execute()) {
        $st->close();
        return ['ok' => false, 'message' => 'บันทึกไม่สำเร็จ กรุณาลองใหม่'];
    }
    $leadId = (int)$conn->insert_id;
    $st->close();

    if ($leadId > 0) {
        log_lead_status($conn, $userId, $leadId, $status, encrypt_data('เพิ่ม Lead ผ่าน LINE', $key), $contactDate);
        $nextAction = trim($data['next_plan_action'] ?? '');
        if ($nextAction !== '' && $nextPlanDate) {
            sync_lead_plan_task($conn, $userId, $key, $leadCode, $leadName, $nextAction, $nextPlanDate, $ownerCode);
        }
    }

    return ['ok' => true, 'message' => 'เพิ่ม Lead เรียบร้อย', 'lead_code' => $leadCode, 'id' => $leadId];
}

function lead_line_start_add(mysqli $conn, array $user, string $replyToken): void
{
    if (function_exists('owner_line_clear_draft')) {
        owner_line_clear_draft($conn, (int)$user['id']);
    }
    lead_line_store_draft($conn, (int)$user['id'], 'await_paste', [], $user['encryption_key'] ?? null);
    lead_line_send_prompt($replyToken);
}

function lead_line_cancel(mysqli $conn, int $user_id, string $replyToken): void
{
    lead_line_clear_draft($conn, $user_id);
    if (function_exists('quick_reply_send')) {
        quick_reply_send($replyToken, [['type' => 'text', 'text' => 'ยกเลิกการเพิ่ม Lead แล้ว']], 'lead_sub');
    }
}

function lead_line_process_parsed(mysqli $conn, array $user, string $replyToken, array $data): void
{
    $uid = (int)$user['id'];
    $key = $user['encryption_key'] ?? '';
    if (trim($data['lead_name'] ?? '') === '') {
        lead_line_store_draft($conn, $uid, 'await_missing', $data, $key, 'lead_name');
        lead_line_send_prompt($replyToken, lead_line_missing_prompt('lead_name') . "\n\nพิมพ์「ยกเลิก」เพื่อยกเลิก");
        return;
    }
    lead_line_store_draft($conn, $uid, 'await_confirm', $data, $key, null);
    lead_line_send_confirm($replyToken, $data);
}

/** @return bool */
function lead_line_handle_text(mysqli $conn, array $user, string $text, string $replyToken): bool
{
    $uid = (int)$user['id'];
    $key = $user['encryption_key'] ?? '';
    $draft = lead_line_get_draft($conn, $uid, $key);
    $lower = mb_strtolower(trim($text), 'UTF-8');

    if (in_array($lower, ['ยกเลิก', 'cancel'], true)) {
        if ($draft) {
            lead_line_cancel($conn, $uid, $replyToken);
            return true;
        }
        return false;
    }

    if (!$draft) {
        return false;
    }

    $step = $draft['step'] ?? 'await_paste';
    $parsed = is_array($draft['parsed'] ?? null) ? $draft['parsed'] : [];

    if ($step === 'await_confirm') {
        $extra = listing_ai_detect_supplement($text);
        if (!empty($extra['photos_link'])) {
            $parsed['photos_link'] = $extra['photos_link'];
            lead_line_store_draft($conn, $uid, 'await_confirm', $parsed, $key, null);
            lead_line_send_confirm($replyToken, $parsed);
            return true;
        }
        if (mb_strlen(trim($text), 'UTF-8') >= 15) {
            $lineUid = $user['line_user_id'] ?? '';
            if ($lineUid !== '' && function_exists('line_begin_slow_work')) {
                line_begin_slow_work($lineUid, 'lead', $user);
            }
            $ai = listing_ai_parse_lead($text);
            if (!empty($ai['data'])) {
                lead_line_process_parsed($conn, $user, $replyToken, $ai['data']);
                return true;
            }
        }
        quick_reply_send($replyToken, [[
            'type' => 'text',
            'text' => "อยู่ขั้นยืนยัน — กด ✅ บันทึก ในการ์ดด้านบน",
        ]], 'lead_sub');
        return true;
    }

    if ($step === 'await_missing') {
        $field = (string)($draft['pending_field'] ?? 'lead_name');
        $parsed = lead_line_apply_missing($parsed, $field, $text);
        lead_line_process_parsed($conn, $user, $replyToken, $parsed);
        return true;
    }

    if (mb_strlen(trim($text), 'UTF-8') < 6) {
        lead_line_send_prompt($replyToken, "ข้อความสั้นเกินไป — วางข้อความจากลูกค้าทั้งก้อน\n\nพิมพ์「ยกเลิก」เพื่อยกเลิก");
        return true;
    }

    $lineUid = $user['line_user_id'] ?? '';
    if ($lineUid !== '' && function_exists('line_begin_slow_work')) {
        line_begin_slow_work($lineUid, 'lead', $user);
    }

    $ai = listing_ai_parse_lead($text);
    if (empty($ai['data'])) {
        lead_line_send_prompt($replyToken, implode("\n", $ai['errors'] ?? ['อ่านข้อความไม่ได้']) . "\n\nลองใหม่หรือพิมพ์「ยกเลิก」");
        return true;
    }

    $data = $ai['data'];
    $extra = listing_ai_detect_supplement($text);
    if ($extra) {
        $data = array_merge($data, array_filter($extra));
    }
    lead_line_process_parsed($conn, $user, $replyToken, $data);
    return true;
}

/** @return bool */
function lead_line_handle_postback(mysqli $conn, array $user, array $params, string $replyToken): bool
{
    if (($params['action'] ?? '') !== 'lead_line') {
        return false;
    }
    $uid = (int)$user['id'];
    $key = $user['encryption_key'] ?? '';
    $op = $params['op'] ?? '';

    if ($op === 'cancel') {
        lead_line_cancel($conn, $uid, $replyToken);
        return true;
    }
    if ($op !== 'confirm') {
        return false;
    }

    $draft = lead_line_get_draft($conn, $uid, $key);
    if (!$draft || ($draft['step'] ?? '') !== 'await_confirm') {
        quick_reply_send($replyToken, [['type' => 'text', 'text' => 'ไม่พบข้อมูลรอบันทึก — กดเพิ่ม Lead ใหม่']], 'lead_sub');
        return true;
    }

    $parsed = $draft['parsed'] ?? [];
    $result = lead_line_insert($conn, $user, $parsed);
    lead_line_clear_draft($conn, $uid);

    if (!$result['ok']) {
        quick_reply_send($replyToken, [['type' => 'text', 'text' => $result['message']]], 'lead_sub');
        return true;
    }

    $code = $result['lead_code'] ?? '';
    $name = $parsed['lead_name'] ?? '';
    quick_reply_send($replyToken, [[
        'type' => 'text',
        'text' => "✅ เพิ่ม Lead เรียบร้อย\n\nรหัส: {$code}\nชื่อ: {$name}\n\nแก้ไขเพิ่มเติมได้ที่ Dashboard",
    ]], 'lead_sub');
    return true;
}
