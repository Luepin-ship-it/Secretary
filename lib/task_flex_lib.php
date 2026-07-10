<?php
/**
 * LINE Flex Task — carousel ตาม flex task.json + ฟอร์มเพิ่มงาน (event book)
 */

require_once __DIR__ . '/../task_helpers.php';

function task_flex_dashboard_url(string $fragment = 'tasks', array $query = []): string
{
    if (function_exists('qr_dashboard_url')) {
        return qr_dashboard_url($fragment, $query);
    }
    if (function_exists('build_public_base_url')) {
        $base = build_public_base_url();
    } else {
        $base = '';
    }
    $q = $query ? ('?' . http_build_query($query)) : '';
    $hash = $fragment !== '' ? ((strpos($fragment, '#') === 0) ? $fragment : '#' . $fragment) : '';
    return $base . '/dashboard.php' . $q . $hash;
}

function task_flex_priority_badge(int $priority): array
{
    $map = [
        1 => ['label' => '🚨 HIGH PRIORITY', 'color' => '#CC0000'],
        2 => ['label' => '📋 PLAN', 'color' => '#004FE6'],
        3 => ['label' => '📤 DELEGATE', 'color' => '#333333'],
        4 => ['label' => '☕ LATER', 'color' => '#333333'],
    ];
    return $map[$priority] ?? ['label' => '⚡ NORMAL', 'color' => '#333333'];
}

function task_flex_format_time(?string $due_time): string
{
    $t = trim((string)$due_time);
    if ($t === '') {
        return '⏰ ไม่ระบุเวลา';
    }
    $parts = explode(':', substr($t, 0, 5));
    $h = (int)($parts[0] ?? 0);
    $m = (int)($parts[1] ?? 0);
    return sprintf('⏰ %d:%02d น.', $h, $m);
}

function task_flex_thai_date_label(string $ymd): string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
        return $ymd;
    }
    $months = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    [, $m, $d] = array_map('intval', explode('-', $ymd));
    return ($months[$m] ?? $m) . ' ' . $d;
}

function task_flex_list_limit(): int
{
    return 5;
}

function task_flex_truncate(string $text, int $max = 96): string
{
    $text = trim($text);
    if (mb_strlen($text, 'UTF-8') <= $max) {
        return $text;
    }
    return mb_substr($text, 0, $max - 1, 'UTF-8') . '…';
}

/** @return array{today: array, tomorrow: array, overdue: array, today_date: string, tomorrow_date: string, dashboard_url: string} */
function task_flex_fetch_snapshot(mysqli $conn, int $user_id, string $key): array
{
    $today_date = date('Y-m-d');
    $tomorrow_date = date('Y-m-d', strtotime('+1 day'));

    $stmt = $conn->prepare('SELECT * FROM tasks WHERE user_id = ? ORDER BY due_date ASC, due_time ASC, id ASC');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $today = [];
    $tomorrow = [];
    $overdue = [];
    while ($row = $res->fetch_assoc()) {
        $t = task_row_client($row, $key);
        if (!$t || (int)($t['is_completed'] ?? 0) === 1) {
            continue;
        }
        $due = $t['due_date'] ?? '';
        if ($due === $today_date) {
            $today[] = $t;
        } elseif ($due === $tomorrow_date) {
            $tomorrow[] = $t;
        } elseif ($due !== '' && $due < $today_date) {
            $overdue[] = $t;
        }
    }
    $stmt->close();

    return [
        'today_date' => $today_date,
        'tomorrow_date' => $tomorrow_date,
        'today' => $today,
        'tomorrow' => $tomorrow,
        'overdue' => $overdue,
        'dashboard_url' => task_flex_dashboard_url('tasks'),
    ];
}

function task_flex_tasks_for_scope(array $snap, string $scope): array
{
    return match ($scope) {
        'tomorrow' => $snap['tomorrow'] ?? [],
        'overdue' => $snap['overdue'] ?? [],
        default => $snap['today'] ?? [],
    };
}

function task_flex_scope_meta(string $scope, array $snap): array
{
    $tomorrow_label = task_flex_thai_date_label($snap['tomorrow_date'] ?? date('Y-m-d', strtotime('+1 day')));
    $today_label = task_flex_thai_date_label($snap['today_date'] ?? date('Y-m-d'));

    return match ($scope) {
        'tomorrow' => [
            'title' => "งานพรุ่งนี้ ({$tomorrow_label})",
            'subtitle' => 'สถานะ: รอทำ · ' . count($snap['tomorrow'] ?? []) . ' งาน',
            'variant' => 'tomorrow',
            'color' => '#7C3AED',
            'empty' => 'ไม่มีงานพรุ่งนี้',
        ],
        'overdue' => [
            'title' => 'งานที่ยังค้าง',
            'subtitle' => 'สถานะ: เกินกำหนด · ' . count($snap['overdue'] ?? []) . ' งาน',
            'variant' => 'overdue',
            'color' => '#CC0000',
            'empty' => 'ไม่มีงานค้างสะสม 🎉',
        ],
        default => [
            'title' => "งานวันนี้ ({$today_label})",
            'subtitle' => 'สถานะ: รอทำวันนี้ · ' . count($snap['today'] ?? []) . ' งาน',
            'variant' => 'today',
            'color' => '#004FE6',
            'empty' => 'ไม่มีงานค้างของวันนี้',
        ],
    };
}

function task_flex_task_card(array $task, string $variant = 'today'): array
{
    $prio = task_flex_priority_badge((int)($task['priority'] ?? 0));
    $is_overdue = $variant === 'overdue';
    $is_tomorrow = $variant === 'tomorrow';
    $bg = $is_overdue ? '#FFF2F2' : ($is_tomorrow ? '#F5F3FF' : '#F2F7FF');
    $border = $is_overdue ? '#CC0000' : ($is_tomorrow ? '#7C3AED' : '#004FE6');
    $time_label = $is_overdue
        ? '📅 ค้าง ' . task_flex_thai_date_label($task['due_date'] ?? '')
        : task_flex_format_time($task['due_time'] ?? '');

    $title = '• ' . task_flex_truncate($task['title'] ?? '-');
    $ref = trim(($task['lead_code'] ?? '') . ($task['owner_code'] ?? '' ? ' · ' . ($task['owner_code'] ?? '') : ''));
    if ($ref !== '' && $ref !== '·') {
        $title .= ' (' . trim($ref, ' ·') . ')';
    }

    return [
        'type' => 'box',
        'layout' => 'vertical',
        'backgroundColor' => $bg,
        'paddingAll' => '12px',
        'cornerRadius' => '8px',
        'borderColor' => $border,
        'borderWidth' => 'medium',
        'contents' => [
            [
                'type' => 'box',
                'layout' => 'horizontal',
                'contents' => [
                    ['type' => 'text', 'text' => $time_label, 'size' => 'xs', 'color' => $border, 'weight' => 'bold'],
                    ['type' => 'text', 'text' => $prio['label'], 'size' => 'xs', 'color' => $prio['color'], 'align' => 'end', 'weight' => 'bold'],
                ],
            ],
            ['type' => 'text', 'text' => $title, 'color' => '#000000', 'size' => 'sm', 'wrap' => true, 'margin' => 'sm', 'weight' => 'bold'],
        ],
    ];
}

function task_flex_progress_bar(int $pct, string $color): array
{
    $pct = max(0, min(100, $pct));
    return [
        'type' => 'box',
        'layout' => 'vertical',
        'margin' => 'md',
        'backgroundColor' => '#CCCCCC',
        'height' => '8px',
        'cornerRadius' => '30px',
        'contents' => [
            [
                'type' => 'box',
                'layout' => 'vertical',
                'width' => $pct . '%',
                'backgroundColor' => $color,
                'height' => '8px',
                'cornerRadius' => '30px',
                'contents' => [],
            ],
        ],
    ];
}

function task_flex_empty_card(string $text, string $color = '#52525b'): array
{
    return [
        'type' => 'box',
        'layout' => 'vertical',
        'backgroundColor' => '#FFFFFF',
        'paddingAll' => '12px',
        'cornerRadius' => '8px',
        'borderColor' => '#CCCCCC',
        'borderWidth' => 'light',
        'contents' => [
            ['type' => 'text', 'text' => $text, 'color' => $color, 'size' => 'sm', 'wrap' => true],
        ],
    ];
}

function task_flex_build_scope_bubble(array $snap, string $scope, int $offset = 0, ?int $limit = null): array
{
    $meta = task_flex_scope_meta($scope, $snap);
    $tasks = task_flex_tasks_for_scope($snap, $scope);
    $total = count($tasks);
    $limit = $limit ?? task_flex_list_limit();
    $slice = array_slice($tasks, $offset, $limit);
    $remaining = max(0, $total - ($offset + count($slice)));
    $is_more_view = $offset > 0;

    $body = [];
    if (!$slice) {
        $body[] = task_flex_empty_card($meta['empty'], $meta['color']);
    } else {
        foreach ($slice as $t) {
            $body[] = task_flex_task_card($t, $meta['variant']);
        }
    }

    $footer = null;
    $footer_items = [];
    if (!$is_more_view && $remaining > 0) {
        $footer_items[] = [
            'type' => 'button',
            'style' => 'secondary',
            'height' => 'sm',
            'color' => $meta['color'],
            'action' => [
                'type' => 'postback',
                'label' => '📋 ดูเพิ่มใน LINE (' . $remaining . ')',
                'data' => 'action=task_list_more&scope=' . rawurlencode($scope),
                'displayText' => 'ดูงานเพิ่มเติม',
            ],
        ];
    }
    $footer_items[] = [
        'type' => 'button',
        'style' => 'link',
        'height' => 'sm',
        'action' => [
            'type' => 'uri',
            'label' => '📂 เปิด Dashboard',
            'uri' => $snap['dashboard_url'] ?? task_flex_dashboard_url('tasks'),
        ],
    ];
    if ($footer_items) {
        $footer = [
            'type' => 'box',
            'layout' => 'vertical',
            'paddingAll' => '12px',
            'spacing' => 'sm',
            'contents' => $footer_items,
        ];
    }

    $header_title = $is_more_view
        ? $meta['title'] . ' · เพิ่มเติม'
        : $meta['title'];

    $bubble = [
        'type' => 'bubble',
        'size' => 'mega',
        'header' => [
            'type' => 'box',
            'layout' => 'vertical',
            'paddingAll' => '16px',
            'contents' => [
                ['type' => 'text', 'text' => $header_title, 'color' => $meta['color'], 'weight' => 'bold', 'size' => 'md', 'wrap' => true],
                ['type' => 'text', 'text' => $is_more_view
                    ? 'แสดง ' . count($slice) . ' จาก ' . $total . ' งาน'
                    : $meta['subtitle'], 'color' => '#000000', 'size' => 'xs', 'margin' => 'sm', 'weight' => 'bold', 'wrap' => true],
                task_flex_progress_bar($total > 0 ? min(100, count($slice) / max(1, $total) * 100) : 0, $meta['color']),
            ],
        ],
        'body' => [
            'type' => 'box',
            'layout' => 'vertical',
            'paddingAll' => '16px',
            'spacing' => 'md',
            'contents' => $body,
        ],
        'styles' => ['header' => ['backgroundColor' => '#FFFFFF'], 'body' => ['backgroundColor' => '#FFFFFF']],
    ];
    if ($footer) {
        $bubble['footer'] = $footer;
    }
    return $bubble;
}

function task_flex_build_carousel(mysqli $conn, int $user_id, string $key, ?string $view_date = null): array
{
    $snap = task_flex_fetch_snapshot($conn, $user_id, $key);

    return [
        'type' => 'flex',
        'altText' => 'งานวันนี้ ' . count($snap['today']) . ' · พรุ่งนี้ ' . count($snap['tomorrow']) . ' · ค้าง ' . count($snap['overdue']),
        'contents' => [
            'type' => 'carousel',
            'contents' => [
                task_flex_build_scope_bubble($snap, 'today'),
                task_flex_build_scope_bubble($snap, 'tomorrow'),
                task_flex_build_scope_bubble($snap, 'overdue'),
            ],
        ],
    ];
}

function task_flex_build_more_flex(mysqli $conn, int $user_id, string $key, string $scope): array
{
    $snap = task_flex_fetch_snapshot($conn, $user_id, $key);
    $meta = task_flex_scope_meta($scope, $snap);
    $total = count(task_flex_tasks_for_scope($snap, $scope));
    $offset = task_flex_list_limit();
    $more_limit = min(10, max(0, $total - $offset));

    return [
        'type' => 'flex',
        'altText' => $meta['title'] . ' · เพิ่มเติม',
        'contents' => task_flex_build_scope_bubble($snap, $scope, $offset, $more_limit > 0 ? $more_limit : task_flex_list_limit()),
    ];
}

function task_flex_send_more_list(mysqli $conn, array $user, string $replyToken, string $scope): void
{
    $scope = in_array($scope, ['today', 'tomorrow', 'overdue'], true) ? $scope : 'today';
    $flex = task_flex_build_more_flex($conn, (int)$user['id'], $user['encryption_key'], $scope);
    if (function_exists('quick_reply_send')) {
        quick_reply_send($replyToken, [$flex], 'task_sub');
    } elseif (function_exists('send_line_reply')) {
        send_line_reply(['replyToken' => $replyToken, 'messages' => [$flex]], 'main');
    }
}

/** Flex ฟอร์มเพิ่ม Task — ขั้นที่ 1/2: เลือกวัน+เวลาเท่านั้น */
function task_flex_build_event_book(?array $draft = null): array
{
    $draft = $draft ?? [];
    $dash = task_flex_dashboard_url('tasks');

    return [
        'type' => 'flex',
        'altText' => 'เพิ่ม Task — ขั้นที่ 1: เลือกวันและเวลา',
        'contents' => [
            'type' => 'bubble',
            'size' => 'mega',
            'header' => [
                'type' => 'box',
                'layout' => 'vertical',
                'paddingAll' => '16px',
                'backgroundColor' => '#FFFFFF',
                'contents' => [
                    ['type' => 'text', 'text' => 'เพิ่ม Task ใหม่', 'color' => '#004FE6', 'weight' => 'bold', 'size' => 'lg'],
                    ['type' => 'text', 'text' => '① เลือกวันและเวลา (ขั้นที่ 1/2)', 'color' => '#000000', 'size' => 'sm', 'margin' => 'sm', 'weight' => 'bold'],
                ],
            ],
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'paddingAll' => '16px',
                'spacing' => 'md',
                'contents' => [
                    [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'backgroundColor' => '#F2F7FF',
                        'paddingAll' => '14px',
                        'cornerRadius' => '8px',
                        'borderColor' => '#004FE6',
                        'borderWidth' => 'medium',
                        'contents' => [
                            ['type' => 'text', 'text' => '① เลือกวันและเวลา ← ทำตอนนี้', 'size' => 'sm', 'color' => '#004FE6', 'weight' => 'bold', 'wrap' => true],
                            ['type' => 'text', 'text' => 'กดปุ่มด้านล่าง แล้วเลื่อนเลือกวัน+เวลา', 'size' => 'xs', 'color' => '#333333', 'margin' => 'sm', 'wrap' => true],
                        ],
                    ],
                    [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'backgroundColor' => '#F4F4F5',
                        'paddingAll' => '14px',
                        'cornerRadius' => '8px',
                        'borderColor' => '#CCCCCC',
                        'borderWidth' => 'light',
                        'contents' => [
                            ['type' => 'text', 'text' => '② พิมพ์ชื่องาน — รอขั้น 1', 'size' => 'sm', 'color' => '#71717A', 'weight' => 'bold', 'wrap' => true],
                            ['type' => 'text', 'text' => 'เลือกวันเวลาเสร็จแล้ว ค่อยพิมพ์ในแชท', 'size' => 'xs', 'color' => '#A1A1AA', 'margin' => 'sm', 'wrap' => true],
                        ],
                    ],
                    ['type' => 'text', 'text' => 'ความสำคัญ: ปกติ (แก้ได้บน Dashboard ภายหลัง)', 'size' => 'xxs', 'color' => '#71717A', 'wrap' => true],
                ],
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'paddingAll' => '12px',
                'contents' => [
                    [
                        'type' => 'button',
                        'style' => 'primary',
                        'color' => '#004FE6',
                        'height' => 'sm',
                        'action' => [
                            'type' => 'datetimepicker',
                            'label' => '① เลือกวันและเวลา',
                            'data' => 'action=task_add&field=due_datetime',
                            'mode' => 'datetime',
                        ],
                    ],
                    [
                        'type' => 'button',
                        'style' => 'link',
                        'height' => 'sm',
                        'action' => ['type' => 'uri', 'label' => 'เปิดฟอร์มบน Dashboard', 'uri' => $dash],
                    ],
                ],
            ],
        ],
    ];
}

function task_line_step2_prompt(array $draft): string
{
    $when = task_flex_thai_date_label($draft['due_date'] ?? date('Y-m-d'));
    if (($draft['due_time'] ?? '') !== '') {
        $when .= ' · ' . task_flex_format_time($draft['due_time']);
    }
    return "✅ ขั้นที่ 1 เสร็จแล้ว\n📅 {$when}\n\n"
        . "▶ ขั้นที่ 2/2: พิมพ์ชื่องานในแชทตอนนี้\n"
        . "ตัวอย่าง: ติดตาม Owner TAN617\n\n"
        . "พิมพ์「ยกเลิก」เพื่อยกเลิก";
}

function task_flex_send_carousel(mysqli $conn, array $user, string $replyToken, ?string $view_date = null): void
{
    $flex = task_flex_build_carousel($conn, (int)$user['id'], $user['encryption_key'], $view_date);
    if (function_exists('quick_reply_send')) {
        quick_reply_send($replyToken, [$flex], 'task_sub');
    } elseif (function_exists('send_line_reply')) {
        send_line_reply(['replyToken' => $replyToken, 'messages' => [$flex]], 'main');
    }
}

function task_flex_send_event_book(array $user, string $replyToken, ?array $draft = null): void
{
    $flex = task_flex_build_event_book($draft);
    if (function_exists('quick_reply_send')) {
        quick_reply_send($replyToken, [$flex], 'task_add');
    } elseif (function_exists('send_line_reply')) {
        send_line_reply(['replyToken' => $replyToken, 'messages' => [$flex]], 'main');
    }
}
