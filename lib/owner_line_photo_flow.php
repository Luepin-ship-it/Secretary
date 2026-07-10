<?php
/**
 * LINE — สายรูปทรัพย์: มีรูป / ยังไม่มีรูป → Webapp หรือ Task นัดอัป
 */

require_once __DIR__ . '/flex_theme.php';
require_once __DIR__ . '/liff_project_photos.php';
require_once __DIR__ . '/owner_photos_store.php';
require_once __DIR__ . '/task_flex_lib.php';
require_once __DIR__ . '/../task_helpers.php';

function owner_line_photo_quick_reply(): array
{
    return [
        'items' => [
            [
                'type' => 'action',
                'action' => [
                    'type' => 'postback',
                    'label' => '▸ มีรูปแล้ว',
                    'data' => 'action=owner_line&op=photo_has',
                ],
            ],
            [
                'type' => 'action',
                'action' => [
                    'type' => 'postback',
                    'label' => '▸ ยังไม่มีรูป',
                    'data' => 'action=owner_line&op=photo_none',
                ],
            ],
            [
                'type' => 'action',
                'action' => [
                    'type' => 'postback',
                    'label' => '← ยกเลิก',
                    'data' => 'action=owner_line&op=cancel',
                ],
            ],
        ],
    ];
}

function owner_line_photo_schedule_quick_reply(): array
{
    if (!function_exists('qr_datetime_item')) {
        return owner_line_photo_quick_reply();
    }
    return [
        'items' => [
            [
                'type' => 'action',
                'action' => [
                    'type' => 'postback',
                    'label' => '▸ พรุ่งนี้',
                    'data' => 'action=owner_line&op=photo_date&when=tomorrow',
                ],
            ],
            qr_datetime_item('เลือกวันเวลา', 'action=owner_line&op=photo_pick', 'datetime'),
            [
                'type' => 'action',
                'action' => [
                    'type' => 'postback',
                    'label' => '← ยกเลิก',
                    'data' => 'action=owner_line&op=cancel',
                ],
            ],
        ],
    ];
}

function owner_line_photo_upload_done_quick_reply(): array
{
    return [
        'items' => [
            [
                'type' => 'action',
                'action' => [
                    'type' => 'postback',
                    'label' => '▸ อัปรูปเสร็จแล้ว',
                    'data' => 'action=owner_line&op=photo_done',
                ],
            ],
            [
                'type' => 'action',
                'action' => [
                    'type' => 'postback',
                    'label' => '← ยกเลิก',
                    'data' => 'action=owner_line&op=cancel',
                ],
            ],
        ],
    ];
}

function owner_line_ask_photo_choice(string $replyToken, array $data): void
{
    if (!function_exists('quick_reply_send')) {
        return;
    }
    $code = trim($data['code_list'] ?? '');
    $project = trim($data['name_th'] ?? '') ?: trim($data['name_en'] ?? '') ?: $code;
    quick_reply_send($replyToken, [[
        'type' => 'text',
        'text' => "🖼 รูปทรัพย์ — {$code}\n{$project}\n\nมีรูปพร้อมอัปแล้วหรือยัง?",
        'quickReply' => owner_line_photo_quick_reply(),
    ]], 'project_sub');
}

function owner_line_send_photo_upload_link(string $replyToken, array $user, array $data): void
{
    if (!function_exists('quick_reply_send')) {
        return;
    }
    $code = trim($data['code_list'] ?? '');
    $hint = function_exists('owner_line_drive_folder_hint')
        ? owner_line_drive_folder_hint($code, $data)
        : $code;
    $driveUrl = function_exists('owner_line_user_drive_folder_url')
        ? owner_line_user_drive_folder_url($user)
        : '';

    $uploadUrl = liff_project_photos_url($code, $hint, $driveUrl);
    $messages = [[
        'type' => 'text',
        'text' => "📷 อัปรูปทรัพย์ {$code}\n\n"
            . "1) เปิดอัปรูป → เลือกรูป → ลากเรียง\n"
            . "2) กด「ตั้งชื่อ 1, 2, 3…」\n"
            . "3) คัดลอกชื่อโฟลเดอร์ ({$code}) → เปิด Drive\n"
            . "   สร้างโฟลเดอร์ใหม่ตามรหัส → บันทึกรูปลงเครื่อง → อัปใน Drive\n"
            . "4) กลับแชทกด「อัปรูปเสร็จแล้ว」",
        'quickReply' => owner_line_photo_upload_done_quick_reply(),
    ]];

    if ($uploadUrl !== '') {
        $messages[] = [
            'type' => 'template',
            'altText' => 'อัปรูปทรัพย์ ' . $code,
            'template' => [
                'type' => 'buttons',
                'text' => "อัปรูป {$code}\nโฟลเดอร์ใน Drive: {$code}",
                'actions' => [[
                    'type' => 'uri',
                    'label' => 'เปิดอัปรูป',
                    'uri' => $uploadUrl,
                ]],
            ],
        ];
    }

    quick_reply_send($replyToken, $messages, 'project_sub');
}

function owner_line_ask_photo_schedule(string $replyToken, array $data): void
{
    if (!function_exists('quick_reply_send')) {
        return;
    }
    $code = trim($data['code_list'] ?? '');
    quick_reply_send($replyToken, [[
        'type' => 'text',
        'text' => "📅 ยังไม่มีรูป — {$code}\n\nจะอัปรูปเมื่อไหร่?\nเลือกวันและเวลาด้านล่าง",
        'quickReply' => owner_line_photo_schedule_quick_reply(),
    ]], 'project_sub');
}

function owner_line_apply_photo_schedule(array $data, string $when, string $datetime = ''): array
{
    $dueDate = null;
    $dueTime = '';
    if ($when === 'tomorrow') {
        $dueDate = date('Y-m-d', strtotime('+1 day'));
    } elseif (preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})/', $datetime, $m)) {
        $dueDate = $m[1];
        $dueTime = $m[2];
    } elseif (preg_match('/^(\d{4}-\d{2}-\d{2})/', $datetime, $m)) {
        $dueDate = $m[1];
    }
    if ($dueDate) {
        $data['photo_upload_due_date'] = $dueDate;
        $data['photo_upload_due_time'] = $dueTime;
        $data['photo_choice'] = 'none';
    }
    return $data;
}

function owner_line_photo_schedule_label(array $data): string
{
    $d = $data['photo_upload_due_date'] ?? '';
    if ($d === '') {
        return '';
    }
    $label = task_flex_thai_date_label($d) . ' (' . $d . ')';
    $t = trim($data['photo_upload_due_time'] ?? '');
    if ($t !== '') {
        $label .= ' · ' . task_flex_format_time($t);
    }
    return $label;
}

function sync_owner_photo_upload_task(
    mysqli $conn,
    int $user_id,
    string $encryption_key,
    string $owner_code,
    string $owner_name,
    string $due_date,
    string $due_time = ''
): bool {
    if ($owner_code === '' || $due_date === '') {
        return false;
    }
    $when = $due_time !== '' ? " เวลา {$due_time}" : '';
    $title = "อัปรูปทรัพย์ {$owner_code} ({$owner_name}){$when}";
    $title_enc = encrypt_data($title, $encryption_key);
    $group_label_enc = encrypt_data($owner_name, $encryption_key);
    $list_name = 'อัปรูปทรัพย์';
    $group_key = 'photo:' . $owner_code;
    $task_kind = 'owner_photo_upload';
    $due_time_val = $due_time !== '' ? ($due_time . ':00') : null;

    $stmt = $conn->prepare(
        "SELECT id FROM tasks WHERE user_id = ? AND owner_code = ? AND task_kind = 'owner_photo_upload' AND is_completed = 0 LIMIT 1"
    );
    $stmt->bind_param('is', $user_id, $owner_code);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        if ($due_time_val) {
            $stmt = $conn->prepare(
                'UPDATE tasks SET title_enc=?, due_date=?, due_time=?, list_name=?, group_key=?, group_label_enc=? WHERE id=?'
            );
            $stmt->bind_param('sssssi', $title_enc, $due_date, $due_time_val, $list_name, $group_key, $group_label_enc, $existing['id']);
        } else {
            $stmt = $conn->prepare(
                'UPDATE tasks SET title_enc=?, due_date=?, due_time=NULL, list_name=?, group_key=?, group_label_enc=? WHERE id=?'
            );
            $stmt->bind_param('ssssi', $title_enc, $due_date, $list_name, $group_key, $group_label_enc, $existing['id']);
        }
    } else {
        if ($due_time_val) {
            $stmt = $conn->prepare(
                "INSERT INTO tasks (user_id, title_enc, due_date, due_time, is_completed, owner_code, list_name, group_key, group_label_enc, task_kind)
                 VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('issssssss', $user_id, $title_enc, $due_date, $due_time_val, $owner_code, $list_name, $group_key, $group_label_enc, $task_kind);
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO tasks (user_id, title_enc, due_date, is_completed, owner_code, list_name, group_key, group_label_enc, task_kind)
                 VALUES (?, ?, ?, 0, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('isssssss', $user_id, $title_enc, $due_date, $owner_code, $list_name, $group_key, $group_label_enc, $task_kind);
        }
    }
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/** @return list<array<string,mixed>> */
function owner_line_fetch_owner_task_cards(mysqli $conn, int $user_id, string $code, string $key): array
{
    if ($code === '' || $key === '') {
        return [];
    }
    $kinds = ['owner_follow', 'owner_photo_upload'];
    $placeholders = implode(',', array_fill(0, count($kinds), '?'));
    $sql = "SELECT * FROM tasks WHERE user_id = ? AND owner_code = ? AND task_kind IN ($placeholders) AND is_completed = 0 ORDER BY due_date ASC, id ASC LIMIT 2";
    $stmt = $conn->prepare($sql);
    $types = 'is' . str_repeat('s', count($kinds));
    $params = array_merge([$user_id, $code], $kinds);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $tasks = [];
    while ($row = $res->fetch_assoc()) {
        $client = task_row_client($row, $key);
        if ($client) {
            $tasks[] = $client;
        }
    }
    $stmt->close();
    return $tasks;
}

/** @return array<string,mixed> */
function owner_line_task_bubble_for_kind(array $task): array
{
    $kind = $task['task_kind'] ?? 'manual';
    $header = $kind === 'owner_photo_upload' ? 'Task อัปรูปทรัพย์' : 'Task ติดตาม Owner';
    $sub = $kind === 'owner_photo_upload' ? 'ตรวจสอบวันนัดอัปรูป' : 'ตรวจสอบวันนัดที่ลงไว้';
    $dueYmd = $task['due_date'] ?? '';
    $dueLabel = $dueYmd !== '' ? task_flex_thai_date_label($dueYmd) : 'ไม่ระบุวัน';
    $time = trim($task['due_time'] ?? '');
    $timeLine = $time !== '' ? (' · ' . task_flex_format_time($time)) : '';
    $title = trim($task['title'] ?? '-');
    $ownerCode = trim($task['owner_code'] ?? '');

    return [
        'type' => 'bubble',
        'size' => 'kilo',
        'header' => [
            'type' => 'box',
            'layout' => 'vertical',
            'paddingAll' => '14px',
            'backgroundColor' => '#2D6A4F',
            'contents' => [
                ['type' => 'text', 'text' => $header, 'color' => '#FFFFFF', 'weight' => 'bold', 'size' => 'sm'],
                ['type' => 'text', 'text' => $sub, 'color' => '#E8F4EC', 'size' => 'xs', 'margin' => 'xs'],
            ],
        ],
        'body' => [
            'type' => 'box',
            'layout' => 'vertical',
            'paddingAll' => '16px',
            'spacing' => 'md',
            'contents' => [
                [
                    'type' => 'text',
                    'text' => '📅 นัด ' . $dueLabel . ($dueYmd !== '' ? ' (' . $dueYmd . ')' : '') . $timeLine,
                    'weight' => 'bold',
                    'size' => 'sm',
                    'color' => '#1B4332',
                    'wrap' => true,
                ],
                ['type' => 'text', 'text' => $title, 'size' => 'sm', 'color' => '#1A1A1A', 'wrap' => true],
                [
                    'type' => 'text',
                    'text' => $ownerCode !== '' ? ('รหัส ' . $ownerCode) : '',
                    'size' => 'xs',
                    'color' => '#5C5C5C',
                    'wrap' => true,
                ],
            ],
        ],
        'styles' => [
            'header' => ['backgroundColor' => '#2D6A4F'],
            'body' => ['backgroundColor' => '#FFFFFF'],
        ],
    ];
}

function owner_line_build_task_carousel_bubbles(array $tasks): array
{
    $bubbles = [];
    foreach ($tasks as $task) {
        $bubbles[] = owner_line_task_bubble_for_kind($task);
    }
    return $bubbles;
}
