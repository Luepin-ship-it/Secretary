<?php
/**
 * LINE — เพิ่ม Task แบบ TickTick: พิมพ์งานก่อน → เลือกวันจาก Quick Reply
 */

require_once __DIR__ . '/task_flex_lib.php';
require_once __DIR__ . '/line_messaging.php';

function task_line_ensure_schema(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS task_line_drafts (
        user_id INT NOT NULL PRIMARY KEY,
        step VARCHAR(24) NOT NULL DEFAULT 'await_input',
        title_enc TEXT NULL,
        due_date DATE NULL,
        due_time TIME NULL,
        priority TINYINT NOT NULL DEFAULT 2,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $cols = [];
    $res = $conn->query("SHOW COLUMNS FROM task_line_drafts");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $cols[$row['Field']] = true;
        }
    }
    if (empty($cols['title_enc'])) {
        $conn->query('ALTER TABLE task_line_drafts ADD COLUMN title_enc TEXT NULL AFTER step');
    }
}

function task_line_get_draft(mysqli $conn, int $user_id, ?string $key = null): ?array
{
    $stmt = $conn->prepare('SELECT * FROM task_line_drafts WHERE user_id = ? LIMIT 1');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return null;
    }

    $title = '';
    if (!empty($row['title_enc']) && $key) {
        $title = decrypt_data($row['title_enc'], $key);
    }

    return [
        'step' => $row['step'] ?? 'await_input',
        'title' => is_string($title) ? trim($title) : '',
        'due_date' => $row['due_date'] ?? null,
        'due_time' => $row['due_time'] ? substr((string)$row['due_time'], 0, 5) : '',
        'priority' => (int)($row['priority'] ?? 2),
    ];
}

function task_line_save_draft(mysqli $conn, int $user_id, array $data, string $step, ?string $key = null): void
{
    $due_date = ($data['due_date'] ?? null) ?: null;
    $due_time = ($data['due_time'] ?? '') !== '' ? ($data['due_time'] . ':00') : null;
    $priority = (int)($data['priority'] ?? 2);
    $title_enc = null;
    if (!empty($data['title']) && $key) {
        $title_enc = encrypt_data(trim((string)$data['title']), $key);
    }

    $stmt = $conn->prepare(
        'INSERT INTO task_line_drafts (user_id, step, title_enc, due_date, due_time, priority)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE step=VALUES(step), title_enc=VALUES(title_enc),
             due_date=VALUES(due_date), due_time=VALUES(due_time), priority=VALUES(priority)'
    );
    $stmt->bind_param('issssi', $user_id, $step, $title_enc, $due_date, $due_time, $priority);
    $stmt->execute();
    $stmt->close();
}

function task_line_clear_draft(mysqli $conn, int $user_id): void
{
    $stmt = $conn->prepare('DELETE FROM task_line_drafts WHERE user_id = ?');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stmt->close();
}

function task_line_has_active_draft(mysqli $conn, int $user_id): bool
{
    return task_line_get_draft($conn, $user_id) !== null;
}

function task_line_add_prompt_text(): string
{
    return "✍️ พิมพ์รายละเอียด Task ที่ต้องการเพิ่มได้เลย\n"
        . "(หรือกดเลือกวันลัดจากปุ่มด้านล่าง)\n\n"
        . "ตัวอย่าง: ติดตามคุณนัท เลียบคลองสอง พรุ่งนี้ 10 โมง\n\n"
        . "พิมพ์「ยกเลิก」เพื่อยกเลิก";
}

function task_line_pick_date_prompt(string $title): string
{
    return "✅ บันทึกงานแล้ว:\n「{$title}」\n\n"
        . "▶ เลือกวันครบกำหนดจากปุ่มด้านล่าง\n"
        . "(วันนี้ · พรุ่งนี้ · เลือกวันอื่น)\n\n"
        . "พิมพ์「ยกเลิก」เพื่อยกเลิก";
}

function task_line_type_after_date_prompt(string $due_date, string $due_time = ''): string
{
    $when = task_flex_thai_date_label($due_date);
    if ($due_time !== '') {
        $when .= ' · ' . task_flex_format_time($due_time);
    }
    return "📅 เลือกวันแล้ว: {$when}\n\n"
        . "▶ พิมพ์รายละเอียดงานในแชทตอนนี้\n\n"
        . "พิมพ์「ยกเลิก」เพื่อยกเลิก";
}

function task_line_send_add_prompt(string $replyToken, string $text = ''): void
{
    if (!function_exists('quick_reply_send')) {
        return;
    }
    quick_reply_send($replyToken, [[
        'type' => 'text',
        'text' => $text !== '' ? $text : task_line_add_prompt_text(),
    ]], 'task_add');
}

/**
 * @return array{title:string,due_date:?string,due_time:?string,is_date_only:bool}
 */
function task_line_parse_message(string $text): array
{
    $raw = trim($text);
    if ($raw === '') {
        return ['title' => '', 'due_date' => null, 'due_time' => null, 'is_date_only' => false];
    }

    $due_date = null;
    $due_time = null;
    $work = $raw;

    if (preg_match('/^(วันนี้|today)$/ui', $work)) {
        return ['title' => '', 'due_date' => date('Y-m-d'), 'due_time' => null, 'is_date_only' => true];
    }
    if (preg_match('/^(พรุ่งนี้|tomorrow)$/ui', $work)) {
        return ['title' => '', 'due_date' => date('Y-m-d', strtotime('+1 day')), 'due_time' => null, 'is_date_only' => true];
    }
    if (preg_match('/^(มะรืน(?:นี้)?)$/ui', $work)) {
        return ['title' => '', 'due_date' => date('Y-m-d', strtotime('+2 days')), 'due_time' => null, 'is_date_only' => true];
    }

    if (preg_match('/พรุ่งนี้/u', $work)) {
        $due_date = date('Y-m-d', strtotime('+1 day'));
        $work = trim(preg_replace('/พรุ่งนี้/u', ' ', $work));
    } elseif (preg_match('/มะรืน(?:นี้)?/u', $work)) {
        $due_date = date('Y-m-d', strtotime('+2 days'));
        $work = trim(preg_replace('/มะรื(?:นนี้)?/u', ' ', $work));
    } elseif (preg_match('/วันนี้/u', $work)) {
        $due_date = date('Y-m-d');
        $work = trim(preg_replace('/วันนี้/u', ' ', $work));
    }

    if (preg_match('/(\d{1,2})[\.\:](\d{2})\s*(?:น\.)?/u', $work, $m)) {
        $due_time = sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
        $work = trim(preg_replace('/(\d{1,2})[\.\:](\d{2})\s*(?:น\.)?/u', ' ', $work, 1));
    } elseif (preg_match('/บ่าย\s*(\d{1,2})(?:\s*โมง)?/u', $work, $m)) {
        $h = (int)$m[1];
        if ($h < 12) {
            $h += 12;
        }
        $due_time = sprintf('%02d:00', $h);
        $work = trim(preg_replace('/บ่าย\s*(\d{1,2})(?:\s*โมง)?/u', ' ', $work, 1));
    } elseif (preg_match('/(\d{1,2})\s*ทุ่ม/u', $work, $m)) {
        $h = min(23, 18 + (int)$m[1]);
        $due_time = sprintf('%02d:00', $h);
        $work = trim(preg_replace('/(\d{1,2})\s*ทุ่ม/u', ' ', $work, 1));
    } elseif (preg_match('/(\d{1,2})\s*โมง/u', $work, $m)) {
        $h = (int)$m[1];
        if ($h >= 1 && $h <= 6) {
            $h += 12;
        }
        $due_time = sprintf('%02d:00', $h);
        $work = trim(preg_replace('/(\d{1,2})\s*โมง/u', ' ', $work, 1));
    }

    $title = trim(preg_replace('/\s+/u', ' ', $work));

    return [
        'title' => $title,
        'due_date' => $due_date,
        'due_time' => $due_time,
        'is_date_only' => ($title === '' && $due_date !== null),
    ];
}

function task_line_apply_datetime(array $draft, string $dt): array
{
    if (preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})/', $dt, $m)) {
        $draft['due_date'] = $m[1];
        $draft['due_time'] = $m[2];
    } elseif (preg_match('/^(\d{4}-\d{2}-\d{2})/', $dt, $m)) {
        $draft['due_date'] = $m[1];
    }
    return $draft;
}

function task_line_create_task(mysqli $conn, int $user_id, string $key, string $title, array $draft): bool
{
    $title = trim($title);
    if ($title === '') {
        return false;
    }
    $title_enc = encrypt_data($title, $key);
    $due_date = $draft['due_date'] ?? date('Y-m-d');
    $due_time = ($draft['due_time'] ?? '') !== '' ? ($draft['due_time'] . ':00') : null;
    $priority = (int)($draft['priority'] ?? 2);

    if ($due_time) {
        $stmt = $conn->prepare(
            'INSERT INTO tasks (user_id, title_enc, due_date, due_time, is_completed, priority, task_kind)
             VALUES (?, ?, ?, ?, 0, ?, ?)'
        );
        $kind = 'manual';
        $stmt->bind_param('isssis', $user_id, $title_enc, $due_date, $due_time, $priority, $kind);
    } else {
        $stmt = $conn->prepare(
            'INSERT INTO tasks (user_id, title_enc, due_date, is_completed, priority, task_kind)
             VALUES (?, ?, ?, 0, ?, ?)'
        );
        $kind = 'manual';
        $stmt->bind_param('issis', $user_id, $title_enc, $due_date, $priority, $kind);
    }
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function task_line_finish_task(mysqli $conn, array $user, array $draft, string $title, string $replyToken): void
{
    $uid = (int)$user['id'];
    $key = $user['encryption_key'];
    $ok = task_line_create_task($conn, $uid, $key, $title, $draft);
    task_line_clear_draft($conn, $uid);

    if (!function_exists('quick_reply_send')) {
        return;
    }

    if (!$ok) {
        quick_reply_send($replyToken, [['type' => 'text', 'text' => 'ไม่สามารถบันทึก Task ได้ กรุณาลองใหม่']], 'main');
        return;
    }

    $when = task_flex_thai_date_label($draft['due_date'] ?? date('Y-m-d'));
    if (($draft['due_time'] ?? '') !== '') {
        $when .= ' · ' . task_flex_format_time($draft['due_time']);
    }
    quick_reply_send($replyToken, [[
        'type' => 'text',
        'text' => "✅ เพิ่ม Task แล้ว:\n「{$title}」\n📅 {$when}",
    ]], 'main');
}

function task_line_cancel(mysqli $conn, int $user_id, string $replyToken): void
{
    task_line_clear_draft($conn, $user_id);
    if (function_exists('quick_reply_send')) {
        quick_reply_send($replyToken, [['type' => 'text', 'text' => 'ยกเลิกการเพิ่ม Task แล้ว']], 'main');
    }
}

/** @return bool handled */
function task_line_handle_postback(mysqli $conn, array $user, array $params, array $postback_params, string $replyToken): bool
{
    $action = $params['action'] ?? '';

    if ($action === 'task_view_date') {
        task_flex_send_carousel($conn, $user, $replyToken);
        return true;
    }

    if ($action === 'task_list_more') {
        $scope = (string)($params['scope'] ?? 'today');
        task_flex_send_more_list($conn, $user, $replyToken, $scope);
        return true;
    }

    if ($action !== 'task_add') {
        return false;
    }

    $uid = (int)$user['id'];
    $key = $user['encryption_key'];
    $field = $params['field'] ?? '';

    if ($field === 'cancel') {
        task_line_cancel($conn, $uid, $replyToken);
        return true;
    }

    $draft = task_line_get_draft($conn, $uid, $key) ?? [
        'title' => '',
        'due_date' => null,
        'due_time' => '',
        'priority' => 2,
        'step' => 'await_input',
    ];

    if (in_array($field, ['pick_date', 'due_datetime', 'due_date'], true)) {
        $dt = $postback_params['datetime'] ?? '';
        $draft = task_line_apply_datetime($draft, $dt);

        if (($draft['title'] ?? '') !== '') {
            task_line_finish_task($conn, $user, $draft, $draft['title'], $replyToken);
            return true;
        }

        task_line_save_draft($conn, $uid, $draft, 'await_title', $key);
        task_line_send_add_prompt($replyToken, task_line_type_after_date_prompt(
            $draft['due_date'] ?? date('Y-m-d'),
            $draft['due_time'] ?? ''
        ));
        return true;
    }

    return false;
}

/** @return bool handled */
function task_line_handle_text(mysqli $conn, array $user, string $text, string $replyToken): bool
{
    $uid = (int)$user['id'];
    $key = $user['encryption_key'];
    $draft = task_line_get_draft($conn, $uid, $key);

    if (in_array(mb_strtolower(trim($text), 'UTF-8'), ['ยกเลิก', 'cancel'], true)) {
        if ($draft) {
            task_line_cancel($conn, $uid, $replyToken);
            return true;
        }
        return false;
    }

    if (!$draft) {
        return false;
    }

    $step = $draft['step'] ?? 'await_input';
    $parsed = task_line_parse_message($text);

    if ($step === 'await_title') {
        if (trim($text) === '') {
            return false;
        }
        if (!$draft['due_date']) {
            $draft['due_date'] = date('Y-m-d');
        }
        task_line_finish_task($conn, $user, $draft, trim($text), $replyToken);
        return true;
    }

    if ($step === 'await_date') {
        if ($parsed['is_date_only'] && $parsed['due_date']) {
            $draft['due_date'] = $parsed['due_date'];
            $draft['due_time'] = $parsed['due_time'] ?? $draft['due_time'];
            task_line_finish_task($conn, $user, $draft, $draft['title'], $replyToken);
            return true;
        }
        if ($parsed['title'] !== '' && $parsed['due_date']) {
            $draft['due_date'] = $parsed['due_date'];
            $draft['due_time'] = $parsed['due_time'] ?? '';
            task_line_finish_task($conn, $user, $draft, $parsed['title'], $replyToken);
            return true;
        }
        task_line_send_add_prompt($replyToken, task_line_pick_date_prompt($draft['title']));
        return true;
    }

    // await_input
    if ($parsed['is_date_only'] && $parsed['due_date']) {
        $draft['due_date'] = $parsed['due_date'];
        $draft['due_time'] = $parsed['due_time'] ?? '';
        task_line_save_draft($conn, $uid, $draft, 'await_title', $key);
        task_line_send_add_prompt($replyToken, task_line_type_after_date_prompt(
            $draft['due_date'],
            $draft['due_time'] ?? ''
        ));
        return true;
    }

    if ($parsed['title'] !== '' && $parsed['due_date']) {
        $draft['due_date'] = $parsed['due_date'];
        $draft['due_time'] = $parsed['due_time'] ?? '';
        task_line_finish_task($conn, $user, $draft, $parsed['title'], $replyToken);
        return true;
    }

    if ($parsed['title'] !== '') {
        $draft['title'] = $parsed['title'];
        task_line_save_draft($conn, $uid, $draft, 'await_date', $key);
        task_line_send_add_prompt($replyToken, task_line_pick_date_prompt($parsed['title']));
        return true;
    }

    task_line_send_add_prompt($replyToken);
    return true;
}

function task_line_start_add(mysqli $conn, array $user, string $replyToken): void
{
    $uid = (int)$user['id'];
    $key = $user['encryption_key'];
    task_line_save_draft($conn, $uid, [
        'title' => '',
        'due_date' => null,
        'due_time' => '',
        'priority' => 2,
    ], 'await_input', $key);
    task_line_send_add_prompt($replyToken);
}
