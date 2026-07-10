<?php
/**
 * LINE Quick Reply — Menu / ทรัพย์ / Task + Flex สรุปตัวเลข
 */

require_once __DIR__ . '/line_messaging.php';
require_once __DIR__ . '/flex_theme.php';
require_once __DIR__ . '/owner_field_normalize.php';
require_once __DIR__ . '/../task_helpers.php';
require_once __DIR__ . '/task_flex_lib.php';
require_once __DIR__ . '/task_line_flow.php';
require_once __DIR__ . '/owner_line_flow.php';
require_once __DIR__ . '/lead_line_flow.php';

function qr_dashboard_url(string $fragment = '', array $query = []): string
{
    if (function_exists('build_public_base_url')) {
        $base = build_public_base_url();
    } elseif (function_exists('auth_base_url')) {
        $base = auth_base_url();
    } else {
        $base = '';
    }
    $q = $query ? ('?' . http_build_query($query)) : '';
    $hash = $fragment !== '' ? ((strpos($fragment, '#') === 0) ? $fragment : '#' . $fragment) : '';
    return $base . '/dashboard.php' . $q . $hash;
}

/** ป้าย Quick Reply — ◆ เมนูหลัก · ▸ เมนูย่อย · ← กลับ */
function qr_fit_label(string $label, int $max = 20): string
{
    $label = trim($label);
    if ($label === '' || mb_strlen($label, 'UTF-8') <= $max) {
        return $label;
    }
    return mb_substr($label, 0, $max - 1, 'UTF-8') . '…';
}

function qr_label_main(string $text): string
{
    return qr_fit_label('◆ ' . trim($text));
}

function qr_label_sub(string $text): string
{
    return qr_fit_label('▸ ' . trim($text));
}

function qr_label_back(string $text = 'Menu'): string
{
    return qr_fit_label('← ' . trim($text));
}

function qr_message_item(string $label, string $text, string $tier = 'sub'): array
{
    $buttonLabel = match ($tier) {
        'main' => qr_label_main($label),
        'back' => qr_label_back($label),
        default => qr_label_sub($label),
    };
    return [
        'type' => 'action',
        'action' => [
            'type' => 'message',
            'label' => $buttonLabel,
            'text' => $text,
        ],
    ];
}

function qr_uri_item(string $label, string $uri, string $tier = 'sub'): array
{
    $buttonLabel = match ($tier) {
        'main' => qr_label_main($label),
        'back' => qr_label_back($label),
        default => qr_label_sub($label),
    };
    return [
        'type' => 'action',
        'action' => [
            'type' => 'uri',
            'label' => $buttonLabel,
            'uri' => $uri,
        ],
    ];
}

function qr_datetime_item(string $label, string $data, string $mode = 'datetime', string $tier = 'sub'): array
{
    $buttonLabel = match ($tier) {
        'main' => qr_label_main($label),
        'back' => qr_label_back($label),
        default => qr_label_sub($label),
    };
    return [
        'type' => 'action',
        'action' => [
            'type' => 'datetimepicker',
            'label' => $buttonLabel,
            'data' => $data,
            'mode' => $mode,
        ],
    ];
}

function qr_postback_item(string $label, string $cmd, array $extra = [], string $tier = 'sub'): array
{
    $data = array_merge(['action' => 'qr', 'cmd' => $cmd], $extra);
    $parts = [];
    foreach ($data as $k => $v) {
        $parts[] = rawurlencode($k) . '=' . rawurlencode((string)$v);
    }
    $buttonLabel = match ($tier) {
        'main' => qr_label_main($label),
        'back' => qr_label_back($label),
        default => qr_label_sub($label),
    };
    return [
        'type' => 'action',
        'action' => [
            'type' => 'postback',
            'label' => $buttonLabel,
            'data' => implode('&', $parts),
            'displayText' => $label,
        ],
    ];
}

function quick_reply_main(): array
{
    return [
        'items' => [
            qr_postback_item('Menu', 'menu', [], 'main'),
            qr_postback_item('Project', 'project_menu', [], 'main'),
            qr_postback_item('Lead', 'lead_menu', [], 'main'),
            qr_postback_item('Task', 'task_menu', [], 'main'),
            qr_postback_item('เอกสาร/คำนวน', 'docs_menu', [], 'main'),
            qr_uri_item('Dashboard', qr_dashboard_url(), 'main'),
        ],
    ];
}

function quick_reply_project_sub(): array
{
    return [
        'items' => [
            qr_postback_item('สรุปตัวเลข', 'project_stats'),
            qr_postback_item('เลขที่บ้าน/โลเคชั่น', 'listing'),
            qr_postback_item('เพิ่ม Project', 'project_add'),
            qr_postback_item('Menu', 'menu', [], 'back'),
        ],
    ];
}

function quick_reply_lead_sub(): array
{
    return [
        'items' => [
            qr_postback_item('สรุปตัวเลข', 'lead_stats', ['scope' => 'month']),
            qr_postback_item('เพิ่ม Lead', 'lead_add'),
            qr_postback_item('แจ้ง reserve/close/win', 'lead_deal'),
            qr_postback_item('Menu', 'menu', [], 'back'),
        ],
    ];
}

function quick_reply_task_sub(): array
{
    return [
        'items' => [
            qr_postback_item('สรุปงาน', 'tasks_list'),
            qr_postback_item('เพิ่ม Task', 'task_add'),
            qr_postback_item('Menu', 'menu', [], 'back'),
        ],
    ];
}

function quick_reply_docs_sub(): array
{
    return [
        'items' => [
            qr_postback_item('คำนวนกรมที่ดิน', 'docs_land_fee'),
            qr_postback_item('ยินยอมคู่สมรส', 'docs_spouse'),
            qr_postback_item('มอบอำนาจ', 'docs_poa'),
            qr_postback_item('เตรียมวันโอน', 'docs_transfer'),
            qr_postback_item('Menu', 'menu', [], 'back'),
        ],
    ];
}

/** @deprecated ใช้ quick_reply_project_sub แทน */
function quick_reply_menu_sub(): array
{
    return quick_reply_project_sub();
}

/** Quick Reply ตอนเพิ่ม Task — วันนี้ / พรุ่งนี้ / เลือกวันอื่น */
function quick_reply_task_add(): array
{
    return [
        'items' => [
            qr_message_item('วันนี้', 'วันนี้'),
            qr_message_item('พรุ่งนี้', 'พรุ่งนี้'),
            qr_datetime_item('เลือกวันอื่น', 'action=task_add&field=pick_date'),
            qr_postback_item('ยกเลิก', 'task_add_cancel', [], 'back'),
        ],
    ];
}

function quick_reply_lead_scope(): array
{
    return [
        'items' => [
            qr_postback_item('ดูทั้งหมด', 'lead_stats', ['scope' => 'all']),
            qr_postback_item('เดือนนี้', 'lead_stats', ['scope' => 'month']),
            qr_postback_item('Menu', 'menu', [], 'back'),
        ],
    ];
}

function quick_reply_listing_sub(mysqli $conn, array $user): array
{
    $items = [];
    $projects = function_exists('listing_quick_reply_projects')
        ? listing_quick_reply_projects($conn, $user)
        : (function_exists('listing_default_project_shortcuts') ? listing_default_project_shortcuts() : []);
    foreach ($projects as $proj) {
        $items[] = qr_message_item($proj['label'], $proj['text']);
    }
    $items[] = qr_postback_item('Menu', 'project_menu', [], 'back');
    return ['items' => $items];
}

function quick_reply_main_prompt(): string
{
    return "เมนูหลัก (◆)\n· Project · Lead · Task · เอกสาร/คำนวน · Dashboard\n\n◆ เมนูหลัก · ▸ เมนูย่อย · ← กลับ";
}

function quick_reply_project_prompt(): string
{
    return "Project (▸)\n· สรุปตัวเลข · เลขที่บ้าน/โลเคชั่น · เพิ่ม Project\n← Menu = กลับเมนูหลัก";
}

function quick_reply_lead_prompt(): string
{
    return "Lead (▸)\n· สรุปตัวเลข · เพิ่ม Lead · แจ้ง reserve/close/win\n← Menu = กลับเมนูหลัก";
}

function quick_reply_task_prompt(): string
{
    return "Task (▸)\n· สรุปงาน · เพิ่ม Task\n← Menu = กลับเมนูหลัก";
}

function quick_reply_docs_prompt(): string
{
    return "เอกสาร/คำนวน (▸)\n· คำนวนกรมที่ดิน · หนังสือต่างๆ · เตรียมวันโอน\n← Menu = กลับเมนูหลัก";
}

function qr_documents_url(string $section = ''): string
{
    $hash = 'documents';
    if ($section !== '') {
        $hash .= '-' . preg_replace('/[^a-z0-9_-]/i', '', $section);
    }
    return qr_dashboard_url($hash);
}

function quick_reply_listing_prompt(mysqli $conn, array $user): string
{
    $projects = function_exists('listing_quick_reply_projects')
        ? listing_quick_reply_projects($conn, $user)
        : [];
    if ($projects) {
        return "เลือกโครงการด้านล่าง (▸)\nหรือพิมพ์ชื่อโครงการ / รหัสทรัพย์ / ชื่อ Owner\n\n◆ เมนูหลัก · ▸ เมนูย่อย · ← กลับ";
    }
    return "ถ้าต้องการดูเลขที่บ้านหรือโครงการไหน สามารถพิมพ์ชื่อโครงการหรือรหัสทรัพย์ได้เลย\n\nตัวอย่าง: เพฟ รามอินทรา · TAN617 · คุณนัท";
}

function quick_reply_carrier_text(): string
{
    return "⌨️ ◆ เมนูหลัก · ▸ เมนูย่อย · ← กลับ\n(Desktop: พิมพ์ Menu / Project / Lead / Task / เมนู)";
}

/** Flex/Template บาง client ไม่แสดง Quick Reply บนข้อความนั้น */
function quick_reply_message_needs_carrier(array $message): bool
{
    $type = $message['type'] ?? '';
    return in_array($type, ['flex', 'template', 'imagemap'], true);
}

/** @param string $kind main|project_sub|lead_sub|task_sub|docs_sub|task_add|lead_scope|menu|none */
function quick_reply_attach(array $messages, string $kind = 'main'): array
{
    if ($kind === 'none' || !$messages) {
        return $messages;
    }
    $map = [
        'main' => 'quick_reply_main',
        'project_sub' => 'quick_reply_project_sub',
        'menu' => 'quick_reply_project_sub',
        'lead_sub' => 'quick_reply_lead_sub',
        'lead_scope' => 'quick_reply_lead_scope',
        'task_sub' => 'quick_reply_task_sub',
        'docs_sub' => 'quick_reply_docs_sub',
        'task_add' => 'quick_reply_task_add',
    ];
    $fn = $map[$kind] ?? null;
    if (!$fn || !function_exists($fn)) {
        return $messages;
    }

    $qr = $fn();
    $mainQr = quick_reply_main();
    $lastIdx = count($messages) - 1;
    $last = $messages[$lastIdx];

    if (!empty($last['quickReply'])) {
        return $messages;
    }

    // Flex/Carousel — แนบข้อความท้ายชุดพร้อมเมนูหลักเสมอ
    if (quick_reply_message_needs_carrier($last) && count($messages) < 5) {
        $messages[] = [
            'type' => 'text',
            'text' => quick_reply_carrier_text(),
            'quickReply' => $mainQr,
        ];
    } else {
        $messages[$lastIdx]['quickReply'] = $qr;
    }

    return $messages;
}

function quick_reply_send(string $replyToken, array $messages, string $qrKind = 'main'): void
{
    if (!function_exists('send_line_reply')) {
        return;
    }
    send_line_reply([
        'replyToken' => $replyToken,
        'messages' => quick_reply_attach($messages, $qrKind),
    ], $qrKind);
}

function qr_lead_potential_is_set(?string $raw): bool
{
    $g = strtoupper(trim((string)$raw));
    return in_array($g, ['A', 'B', 'C'], true);
}

function qr_pipeline_calc_win(int $monthly_target, int $commission): int
{
    return (int)max(1, ceil($monthly_target / max(1000, $commission)));
}

function qr_month_grade(int $revenue_pct, int $win_gap, int $revenue_gap): array
{
    if ($revenue_pct >= 90) {
        $grade = 'S';
        $note = 'ใกล้เป้าแล้ว — อีกนิดเดียว!';
    } elseif ($revenue_pct >= 70) {
        $grade = 'A';
        $note = 'ทำได้ดีมาก';
    } elseif ($revenue_pct >= 50) {
        $grade = 'B';
        $note = 'กำลังไปได้สวย';
    } elseif ($revenue_pct >= 30) {
        $grade = 'C';
        $note = 'ยังมีงานให้ไล่ต่อ';
    } else {
        $grade = 'D';
        $note = 'เร่งเครื่องเดือนนี้';
    }
    $need = [];
    if ($win_gap > 0) {
        $need[] = 'Win อีก ' . $win_gap . ' ดีล';
    }
    if ($revenue_gap > 0) {
        $need[] = 'รายได้อีก ฿' . number_format($revenue_gap);
    }
    return [
        'grade' => $grade,
        'note' => $note,
        'need_line' => $need ? implode(' · ', $need) : 'ถึงเป้าเดือนนี้แล้ว',
    ];
}

function qr_pipeline_suggest_counts(int $win): array
{
    $win = max(1, $win);
    $nego = (int)ceil($win / 0.4);
    $showing = (int)ceil($nego / 0.45);
    $app = (int)ceil($showing / 0.6);
    $lead = (int)ceil($app / 0.5);
    $project = (int)ceil($lead / 0.25);
    return compact('project', 'lead', 'app', 'showing', 'nego', 'win');
}

function qr_pipeline_snapshot(mysqli $conn, int $user_id): array
{
    $month = date('Y-m');
    $defaults = [
        'monthly_target' => 500000,
        'commission_per_deal' => 50000,
    ];
    $stmt = $conn->prepare('SELECT * FROM pipeline_settings WHERE user_id = ? LIMIT 1');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $pipeline = $row ? array_merge($defaults, $row) : $defaults;

    $commission = max(1000, (int)$pipeline['commission_per_deal']);
    $target = (int)$pipeline['monthly_target'];
    $need = ['win' => qr_pipeline_calc_win($target, $commission)];
    if ((int)($pipeline['need_project'] ?? 0) > 0) {
        $need = [
            'project' => (int)$pipeline['need_project'],
            'lead' => (int)$pipeline['need_lead'],
            'app' => (int)$pipeline['need_app'],
            'showing' => (int)$pipeline['need_showing'],
            'nego' => (int)$pipeline['need_nego'],
            'win' => qr_pipeline_calc_win($target, $commission),
        ];
    } else {
        $need = qr_pipeline_suggest_counts($need['win']);
    }
    $need_win = (int)$need['win'];

    $actual = lead_pipeline_actual_counts($conn, $user_id, $month);
    $win_month = (int)$actual['win_month'];
    $actual_revenue = $win_month * $commission;
    $revenue_pct = $target > 0 ? (int)min(100, round($actual_revenue / $target * 100)) : 0;
    $win_gap = max(0, $need_win - $win_month);
    $revenue_gap = max(0, $target - $actual_revenue);

    $ytd_year = (int)date('Y');
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM leads WHERE user_id = ? AND status = 'Win' AND YEAR(COALESCE(win_date, DATE(updated_at))) = ?");
    $stmt->bind_param('ii', $user_id, $ytd_year);
    $stmt->execute();
    $ytd_win = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    $ytd_commission = $ytd_win * $commission;
    $grade = qr_month_grade($revenue_pct, $win_gap, $revenue_gap);

    $thai_m = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    [, $m] = array_map('intval', explode('-', $month));

    return [
        'month_label' => ($thai_m[$m] ?? $month) . ' ' . date('Y'),
        'ytd_commission' => $ytd_commission,
        'ytd_win' => $ytd_win,
        'commission' => $commission,
        'target' => $target,
        'actual_revenue' => $actual_revenue,
        'win_month' => $win_month,
        'need_win' => $need_win,
        'revenue_pct' => $revenue_pct,
        'grade' => $grade,
        'dashboard_url' => qr_dashboard_url('pipeline', ['pl_month' => $month]),
    ];
}

function qr_owner_snapshot(mysqli $conn, int $user_id, string $key): array
{
    $stmt = $conn->prepare('SELECT COUNT(*) c FROM owners WHERE user_id = ?');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    $counts = ['active' => 0, 'sold' => 0, 'other' => 0];
    $stmt = $conn->prepare('SELECT availability_status, COUNT(*) c FROM owners WHERE user_id = ? GROUP BY availability_status');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $st = (string)($r['availability_status'] ?? '');
        $c = (int)$r['c'];
        if ($st === 'ยังขายอยู่') {
            $counts['active'] += $c;
        } elseif ($st === 'ขายได้แล้ว' || $st === 'ยกเลิกการขาย') {
            $counts['sold'] += $c;
        } else {
            $counts['other'] += $c;
        }
    }
    $stmt->close();

    $recent = [];
    $stmt = $conn->prepare('SELECT * FROM owners WHERE user_id = ? ORDER BY updated_at DESC LIMIT 3');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($o = $res->fetch_assoc()) {
        $name = decrypt_data($o['project_name_en_enc'] ?? '', $key);
        if ($name === '') {
            $name = decrypt_data($o['project_enc'] ?? '', $key);
        }
        if ($name === '') {
            $name = decrypt_data($o['project_name_th_enc'] ?? '', $key);
        }
        $recent[] = [
            'code' => $o['code_list'] ?? '-',
            'name' => $name !== '' ? $name : '-',
            'updated' => substr((string)($o['updated_at'] ?? ''), 0, 10),
        ];
    }
    $stmt->close();

    return [
        'total' => $total,
        'counts' => $counts,
        'recent' => $recent,
        'dashboard_url' => qr_dashboard_url('products'),
    ];
}

function qr_lead_is_stale_candidate(array $lead, array $events_map): bool
{
    if (qr_lead_potential_is_set($lead['potential'] ?? '')) {
        return false;
    }
    $lid = (int)($lead['id'] ?? 0);
    if ($lid > 0 && !empty($events_map[$lid])) {
        return false;
    }
    $terminal = array_merge(lead_terminal_statuses(), ['Win']);
    if (in_array($lead['status'] ?? '', $terminal, true)) {
        return false;
    }
    return true;
}

function qr_stale_leads(mysqli $conn, int $user_id, string $key, string $scope, int $limit = 5): array
{
    $events_map = lead_stage_events_map_for_user($conn, $user_id);
    $days = $scope === 'month' ? 7 : 30;
    $cutoff = date('Y-m-d', strtotime('-' . $days . ' days'));
    $month = date('Y-m');

    $stmt = $conn->prepare('SELECT * FROM leads WHERE user_id = ? ORDER BY updated_at ASC');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        if (!qr_lead_is_stale_candidate($row, $events_map)) {
            continue;
        }
        $updated = substr((string)($row['updated_at'] ?? ''), 0, 10);
        if ($updated === '' || $updated > $cutoff) {
            continue;
        }
        if ($scope === 'month') {
            $contact = ($row['contact_date'] ?? '') ?: substr((string)($row['created_at'] ?? ''), 0, 10);
            if ($contact === '' || date('Y-m', strtotime($contact)) !== $month) {
                continue;
            }
        }
        $name = decrypt_data($row['lead_name_enc'] ?? '', $key);
        $out[] = [
            'code' => $row['lead_code'] ?? '-',
            'name' => $name !== '' ? $name : '-',
            'status' => $row['status'] ?? '-',
            'updated' => $updated,
            'days' => (int)max(1, floor((strtotime('today') - strtotime($updated)) / 86400)),
        ];
        if (count($out) >= $limit) {
            break;
        }
    }
    $stmt->close();
    return $out;
}

function qr_lead_snapshot(mysqli $conn, int $user_id, string $key, string $scope): array
{
    $month = date('Y-m');
    $terminal = array_merge(lead_terminal_statuses(), ['Win']);
    $stats = ['total' => 0, 'active' => 0, 'win_month' => 0, 'no_potential' => 0];

    $stmt = $conn->prepare('SELECT * FROM leads WHERE user_id = ?');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $events_map = lead_stage_events_map_for_user($conn, $user_id);
    while ($row = $res->fetch_assoc()) {
        $contact = ($row['contact_date'] ?? '') ?: substr((string)($row['created_at'] ?? ''), 0, 10);
        if ($scope === 'month') {
            if ($contact === '' || date('Y-m', strtotime($contact)) !== $month) {
                continue;
            }
        }
        $stats['total']++;
        $st = $row['status'] ?? '';
        if (!in_array($st, $terminal, true)) {
            $stats['active']++;
        }
        if ($st === 'Win') {
            $wd = $row['win_date'] ?? '';
            $win_m = (!empty($wd) && $wd !== '0000-00-00')
                ? date('Y-m', strtotime($wd))
                : date('Y-m', strtotime($row['updated_at'] ?? 'now'));
            if ($win_m === $month) {
                $stats['win_month']++;
            }
        }
        if (!qr_lead_potential_is_set($row['potential'] ?? '')) {
            $stats['no_potential']++;
        }
    }
    $stmt->close();

    $funnel = lead_pipeline_actual_counts($conn, $user_id, $scope === 'month' ? $month : null);
    $stale = qr_stale_leads($conn, $user_id, $key, $scope, 5);
    $scope_label = $scope === 'month' ? 'เดือนนี้ (' . $month . ')' : 'ทั้งหมด';
    $stale_rule = $scope === 'month' ? '7+ วัน · ยังไม่ลง Potential · ไม่มีประวัติอัปเดต' : '30+ วัน · ยังไม่ลง Potential · ไม่มีประวัติอัปเดต';

    return [
        'scope' => $scope,
        'scope_label' => $scope_label,
        'stats' => $stats,
        'funnel' => $funnel,
        'win_funnel' => (int)$funnel['win_month'],
        'stale' => $stale,
        'stale_rule' => $stale_rule,
        'dashboard_url' => qr_dashboard_url('leads', $scope === 'month' ? ['lead_month' => $month] : ['lead_month' => 'all']),
    ];
}

function qr_tasks_snapshot(mysqli $conn, int $user_id, string $key): array
{
    $today = date('Y-m-d');
    $stmt = $conn->prepare('SELECT * FROM tasks WHERE user_id = ? AND is_completed = 0 ORDER BY due_date ASC, id ASC');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $tasks = [];
    while ($row = $res->fetch_assoc()) {
        $tasks[] = $row;
    }
    $stmt->close();

    $grouped = build_task_time_groups($tasks, $key, $today);
    $today_list = [];
    foreach ($grouped['groups']['today'] ?? [] as $t) {
        $today_list[] = [
            'title' => $t['title'] ?? '-',
            'due' => $t['due_date'] ?? $today,
            'lead' => $t['lead_code'] ?? '',
            'owner' => $t['owner_code'] ?? '',
        ];
    }

    $recent_overdue = [];
    $d1 = date('Y-m-d', strtotime('-1 day'));
    $d2 = date('Y-m-d', strtotime('-2 days'));
    foreach ($grouped['groups']['overdue'] ?? [] as $t) {
        $due = $t['due_date'] ?? '';
        if ($due === $d1 || $due === $d2) {
            $recent_overdue[] = [
                'title' => $t['title'] ?? '-',
                'due' => $due,
                'lead' => $t['lead_code'] ?? '',
                'owner' => $t['owner_code'] ?? '',
            ];
        }
        if (count($recent_overdue) >= 3) {
            break;
        }
    }

    return [
        'today' => $today_list,
        'overdue_recent' => $recent_overdue,
        'overdue_total' => count($grouped['groups']['overdue'] ?? []),
        'dashboard_url' => qr_dashboard_url('tasks'),
    ];
}

function qr_flex_text(string $text, string $size = 'sm', string $role = 'text', string $margin = 'md', bool $bold = false): array
{
    if (str_starts_with($role, '#')) {
        $box = [
            'type' => 'text',
            'text' => $text,
            'wrap' => true,
            'size' => $size,
            'color' => $role,
            'margin' => $margin,
        ];
        if ($bold) {
            $box['weight'] = 'bold';
        }
        return $box;
    }
    return flex_theme_text($text, $size, $role, $margin, $bold);
}

function qr_flex_footer_uri(string $label, string $uri): array
{
    $c = flex_theme_colors();
    return [
        'type' => 'box',
        'layout' => 'vertical',
        'contents' => [
            [
                'type' => 'button',
                'style' => 'primary',
                'height' => 'sm',
                'color' => $c['btn_secondary'],
                'action' => ['type' => 'uri', 'label' => $label, 'uri' => $uri],
            ],
        ],
    ];
}

function qr_flex_summary_header(string $title): array
{
    $c = flex_theme_colors();
    return [
        'type' => 'box',
        'layout' => 'vertical',
        'backgroundColor' => $c['green'],
        'paddingAll' => '14px',
        'contents' => [
            qr_flex_text($title, 'xs', 'on_green', 'none', true),
        ],
    ];
}

function qr_build_commission_flex(array $d): array
{
    $g = $d['grade'];
    $body = [
        qr_flex_text('ยอดคอมมิชชั่นสะสม', 'xs', 'muted', 'none'),
        qr_flex_text('฿' . number_format($d['ytd_commission']), 'xl', 'dark', 'sm', true),
        qr_flex_text('Win สะสมปีนี้ ' . $d['ytd_win'] . ' ดีล · ฿' . number_format($d['commission']) . '/ดีล', 'xs', 'faint', 'sm'),
        ['type' => 'separator', 'margin' => 'lg', 'color' => flex_theme_colors()['border']],
        qr_flex_text($d['month_label'], 'xs', 'muted', 'lg'),
        qr_flex_text('เกรดเดือนนี้: ' . $g['grade'] . ' · ' . $g['note'], 'sm', 'dark', 'sm', true),
        qr_flex_text('รายได้เดือนนี้ ฿' . number_format($d['actual_revenue']) . ' / ฿' . number_format($d['target']) . ' (' . $d['revenue_pct'] . '%)', 'sm', 'text', 'sm'),
        qr_flex_text('Win เดือนนี้ ' . $d['win_month'] . ' / เป้า ' . $d['need_win'] . ' ดีล', 'xs', 'faint', 'sm'),
        qr_flex_text('เดือนนี้ต้องทำอีก: ' . $g['need_line'], 'sm', 'dark', 'md', true),
    ];
    return [
        'type' => 'flex',
        'altText' => 'ยอดคอมมิชชั่น · เกรด ' . $g['grade'],
        'contents' => [
            'type' => 'bubble',
            'size' => 'kilo',
            'header' => qr_flex_summary_header('Commission'),
            'styles' => array_merge(flex_theme_bubble_styles(), ['header' => ['backgroundColor' => flex_theme_colors()['green']]]),
            'body' => ['type' => 'box', 'layout' => 'vertical', 'paddingAll' => '16px', 'contents' => $body],
            'footer' => qr_flex_footer_uri('ดูเป้า Pipeline', $d['dashboard_url']),
        ],
    ];
}

function qr_build_owner_flex(array $d, string $header = 'Project'): array
{
    $lines = [
        qr_flex_text('ทรัพย์ทั้งหมด ' . $d['total'] . ' รายการ', 'md', 'dark', 'none', true),
        qr_flex_text('ขายอยู่ ' . $d['counts']['active'] . ' · ปิดแล้ว/ยกเลิก ' . $d['counts']['sold'] . ' · อื่นๆ ' . $d['counts']['other'], 'xs', 'faint', 'sm'),
        ['type' => 'separator', 'margin' => 'lg', 'color' => flex_theme_colors()['border']],
        qr_flex_text('อัปเดตล่าสุด 3 รายการ', 'xs', 'muted', 'md', true),
    ];
    if (!$d['recent']) {
        $lines[] = qr_flex_text('ยังไม่มีทรัพย์ในระบบ', 'sm', 'text', 'sm');
    } else {
        foreach ($d['recent'] as $r) {
            $lines[] = qr_flex_text('• ' . $r['code'] . ' — ' . $r['name'] . ' (' . $r['updated'] . ')', 'xs', 'text', 'sm');
        }
    }
    return [
        'type' => 'flex',
        'altText' => 'สรุปตัวเลข ' . $header,
        'contents' => [
            'type' => 'bubble',
            'size' => 'kilo',
            'header' => qr_flex_summary_header($header),
            'styles' => array_merge(flex_theme_bubble_styles(), ['header' => ['backgroundColor' => flex_theme_colors()['green']]]),
            'body' => ['type' => 'box', 'layout' => 'vertical', 'paddingAll' => '16px', 'contents' => $lines],
            'footer' => qr_flex_footer_uri('ดูเพิ่มเติม', $d['dashboard_url']),
        ],
    ];
}

function qr_build_lead_flex(array $d): array
{
    $s = $d['stats'];
    $f = $d['funnel'];
    $win_label = ($d['scope'] ?? '') === 'month' ? 'Win เดือนนี้' : 'Win รวม';
    $win_count = (int)($d['win_funnel'] ?? $f['win_month'] ?? 0);
    $lines = [
        qr_flex_text('ช่วง: ' . $d['scope_label'], 'xs', 'muted', 'none'),
        qr_flex_text('Lead ' . $s['total'] . ' · Active ' . $s['active'] . ' · ' . $win_label . ' ' . $win_count, 'sm', 'dark', 'sm', true),
        qr_flex_text('ยังไม่ลง Potential ' . $s['no_potential'] . ' · Funnel Lead ' . $f['lead'] . ' · App ' . $f['app'], 'xs', 'faint', 'sm'),
        ['type' => 'separator', 'margin' => 'lg', 'color' => flex_theme_colors()['border']],
        qr_flex_text('ต้องตาม (' . $d['stale_rule'] . ')', 'xs', 'muted', 'md', true),
    ];
    if (!$d['stale']) {
        $lines[] = qr_flex_text('ไม่มี Lead ที่เข้าเงื่อนไข', 'sm', 'text', 'sm');
    } else {
        foreach ($d['stale'] as $l) {
            $lines[] = qr_flex_text('• ' . $l['code'] . ' — ' . $l['name'] . ' · ' . $l['days'] . ' วัน · ' . $l['status'], 'xs', 'text', 'sm');
        }
    }
    return [
        'type' => 'flex',
        'altText' => 'สรุปตัวเลข Lead',
        'contents' => [
            'type' => 'bubble',
            'size' => 'kilo',
            'header' => qr_flex_summary_header('Lead'),
            'styles' => array_merge(flex_theme_bubble_styles(), ['header' => ['backgroundColor' => flex_theme_colors()['green']]]),
            'body' => ['type' => 'box', 'layout' => 'vertical', 'paddingAll' => '16px', 'contents' => $lines],
            'footer' => qr_flex_footer_uri('ดูเพิ่มเติม', $d['dashboard_url']),
        ],
    ];
}

function qr_build_tasks_flex(array $d): array
{
    $lines = [
        qr_flex_text('งานวันนี้ (' . count($d['today']) . ')', 'sm', '#141414', 'none', true),
    ];
    if (!$d['today']) {
        $lines[] = qr_flex_text('ไม่มีงานครบกำหนดวันนี้', 'xs', '#52525b', 'sm');
    } else {
        foreach ($d['today'] as $t) {
            $ref = $t['lead'] !== '' ? $t['lead'] : ($t['owner'] !== '' ? $t['owner'] : '');
            $suffix = $ref !== '' ? ' · ' . $ref : '';
            $lines[] = qr_flex_text('• ' . $t['title'] . $suffix, 'xs', '#3f3f46', 'sm');
        }
    }
    $lines[] = ['type' => 'separator', 'margin' => 'lg'];
    $lines[] = qr_flex_text('ค้างเมื่อวาน/มะรืน (' . count($d['overdue_recent']) . ')', 'sm', '#141414', 'md', true);
    if (!$d['overdue_recent']) {
        $lines[] = qr_flex_text('ไม่มีงานค้าง 1–2 วัน', 'xs', '#52525b', 'sm');
    } else {
        foreach ($d['overdue_recent'] as $t) {
            $lines[] = qr_flex_text('• ' . $t['title'] . ' (' . $t['due'] . ')', 'xs', '#3f3f46', 'sm');
        }
    }
    if ($d['overdue_total'] > count($d['overdue_recent'])) {
        $lines[] = qr_flex_text('ค้างรวมทั้งหมด ' . $d['overdue_total'] . ' งาน', 'xxs', '#71717a', 'sm');
    }
    return [
        'type' => 'flex',
        'altText' => 'งานวันนี้',
        'contents' => [
            'type' => 'bubble',
            'size' => 'kilo',
            'header' => [
                'type' => 'box',
                'layout' => 'vertical',
                'backgroundColor' => '#141414',
                'paddingAll' => '14px',
                'contents' => [qr_flex_text('Task', 'xs', '#E2E800', 'none', true)],
            ],
            'body' => ['type' => 'box', 'layout' => 'vertical', 'paddingAll' => '16px', 'contents' => $lines],
            'footer' => qr_flex_footer_uri('ดูเพิ่มเติม', $d['dashboard_url']),
        ],
    ];
}

function quick_reply_dispatch(mysqli $conn, array $user, string $cmd, array $params, string $replyToken): bool
{
    $uid = (int)$user['id'];
    $key = $user['encryption_key'];
    $line_uid = $user['line_user_id'] ?? '';

    switch ($cmd) {
        case 'menu':
            quick_reply_send($replyToken, [[
                'type' => 'text',
                'text' => quick_reply_main_prompt(),
            ]], 'main');
            return true;

        case 'project_menu':
            quick_reply_send($replyToken, [[
                'type' => 'text',
                'text' => quick_reply_project_prompt(),
            ]], 'project_sub');
            return true;

        case 'lead_menu':
            quick_reply_send($replyToken, [[
                'type' => 'text',
                'text' => quick_reply_lead_prompt(),
            ]], 'lead_sub');
            return true;

        case 'task_menu':
        case 'tasks':
            quick_reply_send($replyToken, [[
                'type' => 'text',
                'text' => quick_reply_task_prompt(),
            ]], 'task_sub');
            return true;

        case 'docs_menu':
            quick_reply_send($replyToken, [[
                'type' => 'text',
                'text' => quick_reply_docs_prompt(),
            ]], 'docs_sub');
            return true;

        case 'project_stats':
        case 'owner_stats':
            if ($line_uid !== '') {
                line_begin_slow_work($line_uid, 'report', $user);
            }
            $data = qr_owner_snapshot($conn, $uid, $key);
            quick_reply_send($replyToken, [qr_build_owner_flex($data, 'Project')], 'project_sub');
            return true;

        case 'listing':
            quick_reply_send($replyToken, [[
                'type' => 'text',
                'text' => quick_reply_listing_prompt($conn, $user),
                'quickReply' => quick_reply_listing_sub($conn, $user),
            ]], 'none');
            return true;

        case 'project_add':
            owner_line_start_add($conn, $user, $replyToken);
            return true;

        case 'lead_stats':
            $scope = ($params['scope'] ?? '') === 'all' ? 'all' : 'month';
            if ($line_uid !== '') {
                line_begin_slow_work($line_uid, 'report', $user);
            }
            $data = qr_lead_snapshot($conn, $uid, $key, $scope);
            quick_reply_send($replyToken, [qr_build_lead_flex($data)], 'lead_sub');
            return true;

        case 'lead_add':
            lead_line_start_add($conn, $user, $replyToken);
            return true;

        case 'lead_deal':
            quick_reply_send($replyToken, [[
                'type' => 'text',
                'text' => "แจ้ง reserve / close / win\n\n"
                    . "พิมพ์รหัสทรัพย์ ชื่อโครงการ หรือชื่อลูกค้า\n"
                    . "แล้วระบุ เช่น\n"
                    . "จอง 10,000 · มัดจำ 40,000\nวันทำสัญญา · วันโอน · ราคาเปิด/ปิด\n\n"
                    . "ขั้นตอน: reserve → Close → win",
            ]], 'lead_sub');
            return true;

        case 'tasks_list':
            task_flex_send_carousel($conn, $user, $replyToken);
            return true;

        case 'task_add':
            task_line_start_add($conn, $user, $replyToken);
            return true;

        case 'task_add_cancel':
            task_line_cancel($conn, (int)$user['id'], $replyToken);
            return true;

        case 'docs_land_fee':
            quick_reply_send($replyToken, [[
                'type' => 'text',
                'text' => "คำนวนค่าใช้จ่ายกรมที่ดิน\n\n"
                    . "พิมพ์รหัสทรัพย์หรือชื่อโครงการสั้นๆ\n"
                    . "ระบบจะแสดงข้อมูลทรัพย์ให้ตรวจสอบ\n"
                    . "จากนั้น copy ค่าใช้จ่ายจากกรมที่ดินมาวางในแชท\n\n"
                    . 'เปิดเครื่องมือ: ' . qr_documents_url('land'),
            ]], 'docs_sub');
            return true;

        case 'docs_spouse':
            quick_reply_send($replyToken, [[
                'type' => 'text',
                'text' => "หนังสือยินยอมคู่สมรส\n\n"
                    . "ดาวน์โหลดแบบฟอร์ม · กรอกใน Dashboard · ส่ง PDF ให้ลูกค้า\n\n"
                    . qr_documents_url('spouse'),
            ]], 'docs_sub');
            return true;

        case 'docs_poa':
            quick_reply_send($replyToken, [[
                'type' => 'text',
                'text' => "หนังสือมอบอำนาจ\n\n"
                    . "ดาวน์โหลดแบบฟอร์ม · กรอกใน Dashboard · ส่ง PDF ให้ลูกค้า\n\n"
                    . qr_documents_url('poa'),
            ]], 'docs_sub');
            return true;

        case 'docs_transfer':
            quick_reply_send($replyToken, [[
                'type' => 'text',
                'text' => "สิ่งที่ต้องเตรียม ณ วันโอน\n\n"
                    . "มี checklist Owner / Buyer พร้อม copy ส่งลูกค้า\n\n"
                    . qr_documents_url('transfer'),
            ]], 'docs_sub');
            return true;

        case 'stats_menu':
            quick_reply_send($replyToken, [[
                'type' => 'text',
                'text' => quick_reply_project_prompt(),
            ]], 'project_sub');
            return true;

        case 'commission':
            if ($line_uid !== '') {
                line_begin_slow_work($line_uid, 'report', $user);
            }
            $data = qr_pipeline_snapshot($conn, $uid);
            quick_reply_send($replyToken, [qr_build_commission_flex($data)], 'main');
            return true;

        case 'lead_stats_menu':
            quick_reply_send($replyToken, [[
                'type' => 'text',
                'text' => 'สรุป Lead — เลือกช่วงที่ต้องการดู:',
            ]], 'lead_scope');
            return true;

        default:
            return false;
    }
}

function quick_reply_handle_postback(mysqli $conn, array $user, array $params, string $replyToken): bool
{
    if (($params['action'] ?? '') !== 'qr') {
        return false;
    }
    $cmd = trim((string)($params['cmd'] ?? ''));
    if ($cmd === '') {
        return false;
    }
    return quick_reply_dispatch($conn, $user, $cmd, $params, $replyToken);
}

function quick_reply_text_commands(): array
{
    return [
        'Menu' => 'menu',
        'menu' => 'menu',
        'เมนู' => 'menu',
        'Project' => 'project_menu',
        'project' => 'project_menu',
        'Lead' => 'lead_menu',
        'lead' => 'lead_menu',
        'Task' => 'task_menu',
        'task' => 'task_menu',
        'เอกสาร/คำนวน' => 'docs_menu',
        'เอกสาร คำนวน' => 'docs_menu',
        'สรุปตัวเลข' => 'project_stats',
        'เลขที่บ้าน/โลเคชั่น' => 'listing',
        'เพิ่ม Project' => 'project_add',
        'เพิ่ม Lead' => 'lead_add',
        'แจ้ง reserve/close/win' => 'lead_deal',
        'สรุปงาน' => 'tasks_list',
        'ดูงานทั้งหมด' => 'tasks_list',
        'เพิ่ม Task' => 'task_add',
        'เพิ่ม Task ใหม่' => 'task_add',
        'เพิ่ม task ใหม่' => 'task_add',
        'งานวันนี้' => 'task_menu',
        'คำนวนค่าใช้จ่ายกรมที่ดิน' => 'docs_land_fee',
        'หนังสือยินยอมคู่สมรส' => 'docs_spouse',
        'หนังสือมอบอำนาจ' => 'docs_poa',
        'สิ่งที่ต้องเตรียม ณ วันโอน' => 'docs_transfer',
        'ดูยอดคอมมิชชั่นสะสม' => 'commission',
        'สรุปตัวเลข Owner' => 'project_stats',
        'สรุปตัวเลข Lead' => 'lead_stats',
        'ดูทั้งหมด' => 'lead_stats',
        'เดือนนี้' => 'lead_stats',
    ];
}

function quick_reply_handle_text(mysqli $conn, array $user, string $text, string $replyToken): bool
{
    $map = quick_reply_text_commands();
    if (!isset($map[$text])) {
        return false;
    }
    $cmd = $map[$text];
    $params = [];
    if ($cmd === 'lead_stats') {
        $params['scope'] = in_array($text, ['ดูทั้งหมด'], true) ? 'all' : 'month';
    }
    return quick_reply_dispatch($conn, $user, $cmd, $params, $replyToken);
}
