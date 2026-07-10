<?php
/**
 * Flex หลังบันทึก Project — การ์ดทรัพย์ (เหมือนเลขที่บ้าน/โลเคชั่น) + Task ติดตาม (ถ้ามี)
 */

require_once __DIR__ . '/listing_flex_lib.php';
require_once __DIR__ . '/flex_theme.php';
require_once __DIR__ . '/task_flex_lib.php';
require_once __DIR__ . '/liff_project_photos.php';
require_once __DIR__ . '/owner_line_photo_flow.php';

/** @return array<string,mixed>|null */
function owner_line_fetch_owner_row(mysqli $conn, int $user_id, int $owner_id, string $code = ''): ?array
{
    if ($owner_id > 0) {
        $st = $conn->prepare('SELECT * FROM owners WHERE id = ? AND user_id = ? LIMIT 1');
        $st->bind_param('ii', $owner_id, $user_id);
    } elseif ($code !== '') {
        $st = $conn->prepare('SELECT * FROM owners WHERE user_id = ? AND code_list = ? LIMIT 1');
        $st->bind_param('is', $user_id, $code);
    } else {
        return null;
    }
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

/** @return array<string,mixed>|null */
function owner_line_fetch_follow_task(mysqli $conn, int $user_id, string $code, string $key): ?array
{
    $tasks = owner_line_fetch_owner_task_cards($conn, $user_id, $code, $key);
    foreach ($tasks as $t) {
        if (($t['task_kind'] ?? '') === 'owner_follow') {
            return $t;
        }
    }
    return null;
}

function owner_line_saved_listing_header(string $code): array
{
    $subtitle = 'ตรวจสอบทรัพย์ที่ลง — ' . ($code !== '' ? $code : 'รหัสทรัพย์');
    return flex_theme_header_box('✓ บันทึกข้อมูลแล้ว', $subtitle, 'green');
}

function owner_line_apply_saved_header(array $bubble, string $code): array
{
    $c = flex_theme_colors();
    $bubble['header'] = owner_line_saved_listing_header($code);
    if (!isset($bubble['styles'])) {
        $bubble['styles'] = [];
    }
    $bubble['styles']['header'] = ['backgroundColor' => $c['green']];
    return $bubble;
}

/** @return array<string,mixed> */
function owner_line_follow_task_bubble(array $task): array
{
    return owner_line_task_bubble_for_kind($task);
}

/**
 * @return array{type:string,altText:string,contents:array}|null
 */
function owner_line_build_success_flex(mysqli $conn, array $user, string $code, int $owner_id): ?array
{
    $key = (string)($user['encryption_key'] ?? '');
    $owner = owner_line_fetch_owner_row($conn, (int)$user['id'], $owner_id, $code);
    if (!$owner) {
        return null;
    }

    $listing = listing_owner_fields($owner, $key);
    $taskCards = owner_line_fetch_owner_task_cards($conn, (int)$user['id'], $code, $key);
    $compact = count($taskCards) > 0;

    $projectBubble = owner_line_apply_saved_header(
        build_listing_bubble($listing, $compact),
        $code
    );

    $bubbles = [$projectBubble];
    foreach ($taskCards as $task) {
        $bubbles[] = owner_line_task_bubble_for_kind($task);
    }

    if (count($bubbles) === 1) {
        return [
            'type' => 'flex',
            'altText' => 'บันทึกแล้ว — ' . $code,
            'contents' => $projectBubble,
        ];
    }

    $alt = 'บันทึกแล้ว — ' . $code;
    if (count($taskCards) > 0) {
        $alt .= ' + Task';
    }

    return [
        'type' => 'flex',
        'altText' => $alt,
        'contents' => [
            'type' => 'carousel',
            'contents' => array_slice($bubbles, 0, 5),
        ],
    ];
}
