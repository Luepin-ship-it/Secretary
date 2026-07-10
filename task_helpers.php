<?php
// task_helpers.php — สร้าง/จัดกลุ่ม Task อัตโนมัติจาก Lead, Owner, Reserve
require_once __DIR__ . '/lib/lead_code.php';

/** อัปเกรดคอลัมน์ tasks / leads / owners ที่จำเป็น */
function task_ensure_schema($conn) {
    $task_cols = [
        "list_name VARCHAR(50) DEFAULT NULL COMMENT 'หัวข้อกลุ่ม เช่น Follow Owner'",
        "group_key VARCHAR(80) DEFAULT NULL COMMENT 'คีย์จัดกลุ่ม เช่น owner:DEMO-O01'",
        "group_label_enc TEXT DEFAULT NULL COMMENT 'ชื่อกลุ่มย่อย (เข้ารหัส)'",
        "task_kind VARCHAR(30) DEFAULT 'manual' COMMENT 'manual|lead_plan|owner_follow|reserve_daily'",
        "sort_order INT DEFAULT 0",
        "parent_id INT DEFAULT NULL COMMENT 'งานหลัก (sub-task)'",
    ];
    foreach ($task_cols as $col_def) {
        $col_name = preg_match('/^(\w+)/', $col_def, $m) ? $m[1] : '';
        if ($col_name === '') continue;
        $chk = $conn->query("SHOW COLUMNS FROM tasks LIKE '$col_name'");
        if ($chk && $chk->num_rows === 0) {
            $conn->query("ALTER TABLE tasks ADD COLUMN $col_def");
        }
    }

    $lead_cols = [
        "reserve_date DATE DEFAULT NULL COMMENT 'วันที่วางจอง'",
        "owner_code VARCHAR(50) DEFAULT NULL",
    ];
    foreach ($lead_cols as $col_def) {
        $col_name = preg_match('/^(\w+)/', $col_def, $m) ? $m[1] : '';
        if ($col_name === '') continue;
        $chk = $conn->query("SHOW COLUMNS FROM leads LIKE '$col_name'");
        if ($chk && $chk->num_rows === 0) {
            $conn->query("ALTER TABLE leads ADD COLUMN $col_def");
        }
    }

    $owner_cols = [
        "next_follow_action_enc TEXT DEFAULT NULL",
        "next_follow_date DATE DEFAULT NULL",
    ];
    foreach ($owner_cols as $col_def) {
        $col_name = preg_match('/^(\w+)/', $col_def, $m) ? $m[1] : '';
        if ($col_name === '') continue;
        $chk = $conn->query("SHOW COLUMNS FROM owners LIKE '$col_name'");
        if ($chk && $chk->num_rows === 0) {
            $conn->query("ALTER TABLE owners ADD COLUMN $col_def");
        }
    }

    $user_cols = [
        "max_active_leads INT UNSIGNED DEFAULT 30",
        "daily_task_capacity INT UNSIGNED DEFAULT 8",
        "is_lifetime_free TINYINT UNSIGNED NOT NULL DEFAULT 0",
        "line_wait_style VARCHAR(16) DEFAULT 'loading' COMMENT 'loading=ไอคอนรอ (มือถือ) | text=ข้อความรอ (Desktop เป็นหลัก)'",
    ];
    foreach ($user_cols as $col_def) {
        $col_name = preg_match('/^(\w+)/', $col_def, $m) ? $m[1] : '';
        if ($col_name === '') continue;
        $chk = $conn->query("SHOW COLUMNS FROM users LIKE '$col_name'");
        if ($chk && $chk->num_rows === 0) {
            $conn->query("ALTER TABLE users ADD COLUMN $col_def");
        }
    }

    $conn->query("CREATE TABLE IF NOT EXISTS project_surveys (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        project_slug VARCHAR(120) NOT NULL,
        name_en VARCHAR(255) DEFAULT NULL,
        name_th VARCHAR(255) DEFAULT NULL,
        developer VARCHAR(255) DEFAULT NULL,
        segment VARCHAR(50) DEFAULT NULL COMMENT 'Luxury, Premium ฯลฯ',
        total_units INT UNSIGNED DEFAULT 0,
        phases INT UNSIGNED DEFAULT 0,
        launch_year SMALLINT DEFAULT NULL,
        built_year SMALLINT DEFAULT NULL,
        common_fee DECIMAL(10,2) DEFAULT NULL,
        fee_period VARCHAR(20) DEFAULT 'yearly',
        property_type VARCHAR(50) DEFAULT 'House',
        amenities_json TEXT DEFAULT NULL,
        cover_image_url VARCHAR(512) DEFAULT NULL,
        lat DECIMAL(10,7) DEFAULT NULL,
        lng DECIMAL(10,7) DEFAULT NULL,
        nearby_json TEXT DEFAULT NULL,
        units_json TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_slug (user_id, project_slug),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** สถานะ funnel หลัก */
function lead_funnel_statuses() {
    return ['Call', 'Follow', 'Appointment', 'Show', 'Nego', 'Reserve', 'Close', 'Bank', 'Win'];
}

/** สถานะด้านลบ / ปิดเคส */
function lead_terminal_statuses() {
    return ['Rejected', 'Hold_Reject', 'Lose'];
}

/** ป้าย Lead สถานะอื่นๆ (เคสที่เซลล์ไม่ focus หลัก) */
function lead_aux_tags(): array
{
    return ['agent', 'boss', 'eval', 'friend'];
}

function lead_normalize_aux_tag($raw): string
{
    $t = trim((string)$raw);
    if ($t === 'boss_eval') {
        return 'boss';
    }
    return in_array($t, lead_aux_tags(), true) ? $t : '';
}

/** อ่านป้ายจาก lead_aux_tag หรือ is_agent (import เก่า) */
function lead_aux_tag_resolve(array $l): string
{
    $tag = lead_normalize_aux_tag($l['lead_aux_tag'] ?? '');
    if ($tag !== '') {
        return $tag;
    }
    if (!empty($l['is_agent'])) {
        return 'agent';
    }
    return '';
}

function lead_aux_tag_meta(?string $tag): ?array
{
    $map = [
        'agent' => [
            'key'   => 'agent',
            'label' => 'Agent',
            'short' => 'Agent',
            'icon'  => 'briefcase',
            'desc'  => 'เอเจนต์ — ไม่ใช่ลูกค้าจริง',
        ],
        'boss' => [
            'key'   => 'boss',
            'label' => 'ดูให้เจ้านาย',
            'short' => 'เจ้านาย',
            'icon'  => 'user-check',
            'desc'  => 'ดูให้เจ้านาย / ผู้บริหาร',
        ],
        'eval' => [
            'key'   => 'eval',
            'label' => 'ประเมิน',
            'short' => 'ประเมิน',
            'icon'  => 'clipboard-check',
            'desc'  => 'เคสประเมิน / สำรวจความต้องการ',
        ],
        'friend' => [
            'key'   => 'friend',
            'label' => 'ดูให้เพื่อน',
            'short' => 'เพื่อน',
            'icon'  => 'users',
            'desc'  => 'ดูให้เพื่อน / คนรู้จัก',
        ],
    ];
    $t = lead_normalize_aux_tag($tag ?? '');
    return $map[$t] ?? null;
}

/** ค่าแทนเมื่อเอเจนต์ยังไม่แจ้งชื่อ/เบอร์ลูกค้า */
function lead_agent_unknown_marker(): string
{
    return '-';
}

function lead_is_agent_unknown_marker(string $raw): bool
{
    $s = trim($raw);
    return $s === '-' || $s === '—' || $s === '–';
}

function lead_normalize_agent_client_name(string $raw): string
{
    $s = trim($raw);
    if ($s === '') {
        return '';
    }
    if (lead_is_agent_unknown_marker($s)) {
        return lead_agent_unknown_marker();
    }
    return $s;
}

function lead_normalize_agent_phone_last4(string $raw): string
{
    $s = trim($raw);
    if ($s === '') {
        return '';
    }
    if (lead_is_agent_unknown_marker($s)) {
        return lead_agent_unknown_marker();
    }
    $digits = preg_replace('/\D/', '', $s);
    if (strlen($digits) > 4) {
        $digits = substr($digits, -4);
    }
    return $digits;
}

function lead_agent_client_name_valid(string $raw): bool
{
    return lead_normalize_agent_client_name($raw) !== '';
}

function lead_agent_phone_last4_valid(string $raw): bool
{
    $p = lead_normalize_agent_phone_last4($raw);
    return $p === lead_agent_unknown_marker() || preg_match('/^\d{4}$/', $p);
}

/** รหัสทรัพย์ (Listing Code) ที่ผูก Lead ↔ Owner */
function lead_normalize_owner_code(string $raw): string
{
    require_once __DIR__ . '/lib/tan_workbook_import.php';
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    $norm = TanWorkbookImport::normalizeListingCode($raw);
    if ($norm !== '') {
        return $norm;
    }
    return strtoupper(preg_replace('/\s+/', '', $raw));
}

/** จำนวนหลัง/ยูนิตที่เสนอไปแล้ว (ชีท Unit Sent) */
function lead_normalize_units_sent($raw): ?int
{
    $s = trim((string)$raw);
    if ($s === '') {
        return null;
    }
    $n = (int)preg_replace('/\D/', '', $s);
    return $n >= 0 ? $n : null;
}

/** รายการโครงการ/รหัสทรัพย์ที่เสนอไปแล้ว (ข้อความอิสระ) */
function lead_normalize_offered_listings(string $raw): string
{
    $s = trim(preg_replace("/\r\n?/", "\n", $raw));
    if (strlen($s) > 4000) {
        $s = substr($s, 0, 4000);
    }
    return $s;
}

/** Win ปิดที่หลังนี้หรือหลังอื่น */
function lead_normalize_win_close_scope($raw): string
{
    return strtolower(trim((string)$raw)) === 'other' ? 'other' : 'this';
}

function lead_win_close_scope_meta(string $scope): array
{
    return match (lead_normalize_win_close_scope($scope)) {
        'other' => ['key' => 'other', 'label' => 'หลังอื่น', 'icon' => 'map-pin'],
        default => ['key' => 'this', 'label' => 'จบหลังนี้', 'icon' => 'home'],
    };
}

/** Ensure table for Stage Outcome Matrix (Phase 2) */
function lead_stage_events_ensure_schema($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS lead_stage_events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        lead_id INT NOT NULL,
        stage VARCHAR(20) NOT NULL,
        outcome VARCHAR(10) NOT NULL,
        note_enc TEXT DEFAULT NULL,
        event_date DATE DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_lead (user_id, lead_id),
        INDEX idx_user_stage_date (user_id, stage, event_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Map stage/outcome (Phase 2) -> derived lead.status (legacy-compatible)
 * outcome: yes|lose|reject|hold
 */
function lead_matrix_outcome_to_terminal_status(string $outcome): string {
    return match ($outcome) {
        'lose' => 'Lose',
        'reject' => 'Rejected',
        'hold' => 'Hold_Reject',
        default => 'Lose',
    };
}

/**
 * Resolve derived status + current pipeline stage from stage events.
 * If stage events are empty, caller should fallback to legacy leads.status.
 *
 * @param array<int, array<string,mixed>> $stage_events (raw rows from lead_stage_events)
 * @return array{
 *   status:string,
 *   current_stage:string,
 *   pipeline_idx:int,
 *   stage_outcome_latest:array<string,string>,
 *   stage_events_sorted:array<int,array<string,mixed>>
 * }
 */
function lead_resolve_from_stage_events(array $lead_row, array $stage_events): array {
    $pipeline = lead_funnel_statuses(); // includes Win
    if (empty($stage_events)) {
        $st = $lead_row['status'] ?? 'Call';
        $idx = array_search($st, $pipeline, true);
        if ($idx === false) $idx = 0;
        return [
            'status' => $st,
            'current_stage' => $st,
            'pipeline_idx' => (int)$idx,
            'stage_outcome_latest' => [],
            'stage_events_sorted' => [],
        ];
    }

    // Deterministic ordering: event_date -> created_at -> id
    usort($stage_events, function ($a, $b) {
        $da = !empty($a['event_date']) ? (string)$a['event_date'] : '';
        $db = !empty($b['event_date']) ? (string)$b['event_date'] : '';
        if ($da !== $db) return strcmp($da, $db);
        $ca = !empty($a['created_at']) ? (string)$a['created_at'] : '';
        $cb = !empty($b['created_at']) ? (string)$b['created_at'] : '';
        if ($ca !== $cb) return strcmp($ca, $cb);
        return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
    });

    $latest = $stage_events[count($stage_events) - 1];
    $stage = (string)($latest['stage'] ?? 'Call');
    $outcome = (string)($latest['outcome'] ?? 'yes');

    $status = 'Call';
    if ($stage === 'Win') {
        $status = 'Win';
    } elseif ($outcome === 'yes') {
        $status = $stage;
    } else {
        $status = lead_matrix_outcome_to_terminal_status($outcome);
    }

    $idx = array_search($stage, $pipeline, true);
    if ($idx === false) $idx = 0;

    $latestByStage = [];
    foreach ($stage_events as $e) {
        $s = (string)($e['stage'] ?? '');
        $o = (string)($e['outcome'] ?? '');
        if ($s !== '' && $o !== '') $latestByStage[$s] = $o;
    }

    return [
        'status' => $status,
        'current_stage' => $stage,
        'pipeline_idx' => (int)$idx,
        'stage_outcome_latest' => $latestByStage,
        'stage_events_sorted' => $stage_events,
    ];
}

/** โหลด stage events ทั้งหมดของ user จัดกลุ่มตาม lead_id */
function lead_stage_events_map_for_user($conn, $user_id): array {
    $map = [];
    $stmt = $conn->prepare("SELECT * FROM lead_stage_events WHERE user_id = ? ORDER BY lead_id ASC, event_date ASC, id ASC");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $lid = (int)$row['lead_id'];
        if (!isset($map[$lid])) $map[$lid] = [];
        $map[$lid][] = $row;
    }
    $stmt->close();
    return $map;
}

/** ซิงก์ current_update_enc จากหมายเหตุ stage event ล่าสุด (ใช้หลังลบประวัติ) */
function lead_sync_current_update_from_stage_events(mysqli $conn, int $user_id, string $key, int $lead_id, array $stage_events): void
{
    $latest_note = '';
    if ($stage_events !== []) {
        for ($i = count($stage_events) - 1; $i >= 0; $i--) {
            $raw = $stage_events[$i]['note_enc'] ?? '';
            $note = $raw !== '' ? trim(decrypt_data($raw, $key)) : '';
            if ($note !== '') {
                $latest_note = $note;
                break;
            }
        }
    }

    if ($latest_note !== '') {
        $enc = encrypt_data($latest_note, $key);
        $up = $conn->prepare('UPDATE leads SET current_update_enc = ?, updated_at = NOW() WHERE id = ? AND user_id = ?');
        $up->bind_param('sii', $enc, $lead_id, $user_id);
    } else {
        $up = $conn->prepare('UPDATE leads SET current_update_enc = NULL, updated_at = NOW() WHERE id = ? AND user_id = ?');
        $up->bind_param('ii', $lead_id, $user_id);
    }
    $up->execute();
    $up->close();
}

/** สถานะที่ resolve แล้วของ lead หนึ่งรายการ */
function lead_resolved_status_for_row(array $lead_row, array $stage_events = []): string {
    return lead_resolve_from_stage_events($lead_row, $stage_events)['status'] ?? ($lead_row['status'] ?? 'Call');
}

/** นับ funnel สำหรับกราฟ Home จากสถานะที่ resolve แล้ว */
function lead_funnel_status_counts($conn, $user_id): array {
    $funnel_order = ['Call', 'Follow', 'Appointment', 'Show', 'Nego', 'Reserve', 'Close', 'Bank', 'Win'];
    $counts = array_fill_keys($funnel_order, 0);
    $events_map = lead_stage_events_map_for_user($conn, $user_id);

    $stmt = $conn->prepare("SELECT * FROM leads WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $lid = (int)$row['id'];
        $st = lead_resolved_status_for_row($row, $events_map[$lid] ?? []);
        if (isset($counts[$st])) {
            $counts[$st]++;
        }
    }
    $stmt->close();
    return $counts;
}

/**
 * นับจำนวนจริงใน funnel (resolve จาก matrix + fallback legacy)
 * @return array{lead:int,app:int,showing:int,nego:int,win_month:int}
 */
function lead_pipeline_actual_counts($conn, $user_id, $target_month = null): array {
    $events_map = lead_stage_events_map_for_user($conn, $user_id);
    $lead_set = ['Call', 'Follow', 'Appointment', 'Show', 'Nego', 'Reserve', 'Close', 'Bank'];
    $app_set = ['Appointment', 'Show', 'Nego', 'Reserve', 'Close', 'Bank'];
    $show_set = ['Show', 'Nego', 'Reserve', 'Close', 'Bank'];
    $nego_set = ['Nego', 'Reserve', 'Close', 'Bank'];
    $counts = ['lead' => 0, 'app' => 0, 'showing' => 0, 'nego' => 0, 'win_month' => 0];

    $stmt = $conn->prepare("SELECT * FROM leads WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $lid = (int)$row['id'];
        $st = lead_resolved_status_for_row($row, $events_map[$lid] ?? []);
        if (in_array($st, $lead_set, true)) $counts['lead']++;
        if (in_array($st, $app_set, true)) $counts['app']++;
        if (in_array($st, $show_set, true)) $counts['showing']++;
        if (in_array($st, $nego_set, true)) $counts['nego']++;
        if ($st === 'Win') {
            $wd = $row['win_date'] ?? '';
            $win_month = (!empty($wd) && $wd !== '0000-00-00')
                ? date('Y-m', strtotime($wd))
                : date('Y-m', strtotime($row['updated_at'] ?? 'now'));
            if ($target_month === null || $target_month === '' || $win_month === $target_month) {
                $counts['win_month']++;
            }
        }
    }
    $stmt->close();
    return $counts;
}

/**
 * สถิติ Stage Outcome Matrix (เฉพาะ lead ที่มี event)
 * @return array{
 *   matrix_leads:int,
 *   revivals:int,
 *   stages:array<string,array{yes:int,drop:int,conv_pct:float|null}>
 * }
 */
function lead_matrix_analytics($conn, $user_id): array {
    $stages = ['Call', 'Follow', 'Appointment', 'Show', 'Nego', 'Reserve', 'Close', 'Bank', 'Win'];
    $stage_stats = [];
    foreach ($stages as $s) {
        $stage_stats[$s] = ['yes' => 0, 'drop' => 0, 'conv_pct' => null];
    }

    $events_map = lead_stage_events_map_for_user($conn, $user_id);
    $matrix_leads = 0;
    $revivals = 0;

    $stmt = $conn->prepare("SELECT id FROM leads WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $lid = (int)$row['id'];
        $events = $events_map[$lid] ?? [];
        if (empty($events)) continue;
        $matrix_leads++;

        $resolved = lead_resolve_from_stage_events(['status' => 'Call'], $events);
        $latestByStage = $resolved['stage_outcome_latest'] ?? [];
        foreach ($stages as $st) {
            if (!isset($latestByStage[$st])) continue;
            $o = $latestByStage[$st];
            if ($o === 'yes') {
                $stage_stats[$st]['yes']++;
            } elseif (in_array($o, ['lose', 'reject', 'hold'], true)) {
                $stage_stats[$st]['drop']++;
            }
        }

        $had_drop = false;
        $lead_revived = false;
        foreach ($resolved['stage_events_sorted'] ?? $events as $e) {
            $o = (string)($e['outcome'] ?? '');
            if (in_array($o, ['lose', 'reject', 'hold'], true)) {
                $had_drop = true;
            } elseif ($o === 'yes' && $had_drop && !$lead_revived) {
                $revivals++;
                $lead_revived = true;
            }
        }
    }
    $stmt->close();

    foreach ($stage_stats as $st => &$info) {
        $total = $info['yes'] + $info['drop'];
        $info['conv_pct'] = $total > 0 ? round($info['yes'] / $total * 100, 1) : null;
    }
    unset($info);

    return [
        'matrix_leads' => $matrix_leads,
        'revivals' => $revivals,
        'stages' => $stage_stats,
    ];
}

/** สร้างหรืออัปเดต Task จากแผนงาน Lead */
function sync_lead_plan_task($conn, $user_id, $encryption_key, $lead_code, $lead_name, $next_plan_action, $next_plan_date, $owner_code = '') {
    if ($next_plan_action === '' || empty($next_plan_date)) {
        return false;
    }
    $codeLabel = lead_code_for_display($lead_code);
    $title = "ติดตามลีด {$codeLabel} ({$lead_name}): {$next_plan_action}";
    $title_enc = encrypt_data($title, $encryption_key);
    $group_label_enc = encrypt_data($lead_name, $encryption_key);
    $list_name = 'Follow Lead';
    $group_key = 'lead:' . $lead_code;
    $task_kind = 'lead_plan';
    $owner_val = $owner_code !== '' ? $owner_code : null;

    $stmt = $conn->prepare("SELECT id FROM tasks WHERE user_id = ? AND lead_code = ? AND task_kind = 'lead_plan' AND is_completed = 0 LIMIT 1");
    $stmt->bind_param("is", $user_id, $lead_code);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        $stmt = $conn->prepare("UPDATE tasks SET title_enc=?, due_date=?, list_name=?, group_key=?, group_label_enc=?, owner_code=COALESCE(?, owner_code) WHERE id=?");
        $stmt->bind_param("ssssssi", $title_enc, $next_plan_date, $list_name, $group_key, $group_label_enc, $owner_val, $existing['id']);
    } else {
        $stmt = $conn->prepare("INSERT INTO tasks (user_id, title_enc, due_date, is_completed, lead_code, owner_code, list_name, group_key, group_label_enc, task_kind)
            VALUES (?, ?, ?, 0, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("issssssss", $user_id, $title_enc, $next_plan_date, $lead_code, $owner_val, $list_name, $group_key, $group_label_enc, $task_kind);
    }
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/** สร้างหรืออัปเดต Task ติดตาม Owner */
function sync_owner_follow_task($conn, $user_id, $encryption_key, $owner_code, $owner_name, $action, $due_date) {
    if ($action === '' || empty($due_date)) {
        return false;
    }
    $title = "ติดตาม Owner {$owner_code} ({$owner_name}): {$action}";
    $title_enc = encrypt_data($title, $encryption_key);
    $group_label_enc = encrypt_data($owner_name, $encryption_key);
    $list_name = 'Follow Owner';
    $group_key = 'owner:' . $owner_code;
    $task_kind = 'owner_follow';

    $stmt = $conn->prepare("SELECT id FROM tasks WHERE user_id = ? AND owner_code = ? AND task_kind = 'owner_follow' AND is_completed = 0 LIMIT 1");
    $stmt->bind_param("is", $user_id, $owner_code);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        $stmt = $conn->prepare("UPDATE tasks SET title_enc=?, due_date=?, list_name=?, group_key=?, group_label_enc=? WHERE id=?");
        $stmt->bind_param("sssssi", $title_enc, $due_date, $list_name, $group_key, $group_label_enc, $existing['id']);
    } else {
        $stmt = $conn->prepare("INSERT INTO tasks (user_id, title_enc, due_date, is_completed, owner_code, list_name, group_key, group_label_enc, task_kind)
            VALUES (?, ?, ?, 0, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssssss", $user_id, $title_enc, $due_date, $owner_code, $list_name, $group_key, $group_label_enc, $task_kind);
    }
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/** Task รายวันสำหรับเคส Reserve จนกว่าจะโอน */
function sync_reserve_daily_task($conn, $user_id, $encryption_key, $lead_code, $lead_name, $owner_code = '') {
    $today = date('Y-m-d');
    $codeLabel = lead_code_for_display($lead_code);
    $title = "ติดตามจองรอโอน · {$lead_name} ({$codeLabel})";
    $title_enc = encrypt_data($title, $encryption_key);
    $group_label = $owner_code !== '' ? "{$lead_name} · {$owner_code}" : $lead_name;
    $group_label_enc = encrypt_data($group_label, $encryption_key);
    $list_name = 'จองรอโอน';
    $group_key = 'reserve:' . $lead_code;
    $task_kind = 'reserve_daily';
    $owner_val = $owner_code !== '' ? $owner_code : null;

    $stmt = $conn->prepare("SELECT id FROM tasks WHERE user_id = ? AND lead_code = ? AND task_kind = 'reserve_daily' LIMIT 1");
    $stmt->bind_param("is", $user_id, $lead_code);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        $stmt = $conn->prepare("UPDATE tasks SET title_enc=?, due_date=?, is_completed=0, list_name=?, group_key=?, group_label_enc=?, owner_code=COALESCE(?, owner_code) WHERE id=?");
        $stmt->bind_param("ssssssi", $title_enc, $today, $list_name, $group_key, $group_label_enc, $owner_val, $existing['id']);
    } else {
        $stmt = $conn->prepare("INSERT INTO tasks (user_id, title_enc, due_date, is_completed, lead_code, owner_code, list_name, group_key, group_label_enc, task_kind)
            VALUES (?, ?, ?, 0, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("issssssss", $user_id, $title_enc, $today, $lead_code, $owner_val, $list_name, $group_key, $group_label_enc, $task_kind);
    }
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/** ปิด task ที่ผูกกับ lead เมื่อ Win / Reject / Lose */
function close_lead_linked_tasks($conn, $user_id, $lead_code, $kinds = null) {
    if ($kinds === null) {
        $kinds = ['lead_plan', 'reserve_daily', 'bank_daily'];
    }
    if (empty($kinds)) return;
    $placeholders = implode(',', array_fill(0, count($kinds), '?'));
    $sql = "UPDATE tasks SET is_completed = 1 WHERE user_id = ? AND lead_code = ? AND task_kind IN ($placeholders) AND is_completed = 0";
    $stmt = $conn->prepare($sql);
    $types = 'is' . str_repeat('s', count($kinds));
    $params = array_merge([$user_id, $lead_code], $kinds);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->close();
}

/** Task รายวันสำหรับเคส Close/Bank จนกว่าจะ Win */
function sync_bank_daily_task($conn, $user_id, $encryption_key, $lead_code, $lead_name, $owner_code = '') {
    $today = date('Y-m-d');
    $oc = $owner_code !== '' ? " · {$owner_code}" : '';
    $title = "ติดตามธนาคาร/ปิดดีล · {$lead_name}{$oc}";
    $title_enc = encrypt_data($title, $encryption_key);
    $group_label = $owner_code !== '' ? "{$lead_name} · {$owner_code}" : $lead_name;
    $group_label_enc = encrypt_data($group_label, $encryption_key);
    $list_name = 'รอปิดดีล';
    $group_key = 'bank:' . $lead_code;
    $task_kind = 'bank_daily';
    $owner_val = $owner_code !== '' ? $owner_code : null;

    $stmt = $conn->prepare("SELECT id FROM tasks WHERE user_id = ? AND lead_code = ? AND task_kind = 'bank_daily' LIMIT 1");
    $stmt->bind_param("is", $user_id, $lead_code);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        $stmt = $conn->prepare("UPDATE tasks SET title_enc=?, due_date=?, is_completed=0, list_name=?, group_key=?, group_label_enc=?, owner_code=COALESCE(?, owner_code) WHERE id=?");
        $stmt->bind_param("ssssssi", $title_enc, $today, $list_name, $group_key, $group_label_enc, $owner_val, $existing['id']);
    } else {
        $stmt = $conn->prepare("INSERT INTO tasks (user_id, title_enc, due_date, is_completed, lead_code, owner_code, list_name, group_key, group_label_enc, task_kind)
            VALUES (?, ?, ?, 0, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("issssssss", $user_id, $title_enc, $today, $lead_code, $owner_val, $list_name, $group_key, $group_label_enc, $task_kind);
    }
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/** สร้าง task แจ้งเจ้าของเมื่อ Lead หลุด/Reject และมีรหัสทรัพย์ */
function notify_owner_on_lead_terminal($conn, $user_id, $encryption_key, $owner_code, $lead_name, $status, $note = '') {
    if ($owner_code === '' || !in_array($status, lead_terminal_statuses(), true)) {
        return false;
    }
    $oname = $owner_code;
    $stmt = $conn->prepare("SELECT owner_name_enc FROM owners WHERE user_id = ? AND code_list = ? LIMIT 1");
    $stmt->bind_param("is", $user_id, $owner_code);
    $stmt->execute();
    $orow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($orow) {
        $oname = decrypt_data($orow['owner_name_enc'], $encryption_key) ?: $owner_code;
    }
    $st_label = $status === 'Lose' ? 'ลูกค้าหลุด' : ($status === 'Rejected' ? 'ลูกค้า Reject' : 'Hold');
    $action = "แจ้งเจ้าของ · {$st_label} · {$lead_name}";
    if ($note !== '') {
        $action .= " — {$note}";
    }
    return sync_owner_follow_task($conn, $user_id, $encryption_key, $owner_code, $oname, $action, date('Y-m-d'));
}

/** รีเฟรช due_date ของ task รายวันที่เลยวันแล้ว (จอง / รอปิดดีล) */
function refresh_reserve_tasks($conn, $user_id) {
    $today = date('Y-m-d');
    foreach (['reserve_daily', 'bank_daily'] as $kind) {
        $stmt = $conn->prepare("UPDATE tasks SET due_date = ?, is_completed = 0
            WHERE user_id = ? AND task_kind = ? AND is_completed = 0 AND (due_date IS NULL OR due_date < ?)");
        $stmt->bind_param("siss", $today, $user_id, $kind, $today);
        $stmt->execute();
        $stmt->close();
    }
}

/** จัดการ side-effects เมื่อเปลี่ยน status lead (รองรับ Stage Outcome Matrix) */
function handle_lead_status_side_effects($conn, $user_id, $encryption_key, $lead_code, $lead_name, $status, $owner_code = '', $reserve_date = null, $old_status = null, $terminal_note = '', $stage = null, $outcome = null) {
    // Matrix mode: ใช้ stage+outcome เป็นหลักเมื่อมีการบันทึก event
    if ($stage !== null && $outcome !== null) {
        if ($outcome === 'yes') {
            if ($stage === 'Reserve') {
                if (empty($reserve_date)) {
                    $reserve_date = date('Y-m-d');
                    $stmt = $conn->prepare("UPDATE leads SET reserve_date = ? WHERE user_id = ? AND lead_code = ?");
                    $stmt->bind_param("sis", $reserve_date, $user_id, $lead_code);
                    $stmt->execute();
                    $stmt->close();
                }
                close_lead_linked_tasks($conn, $user_id, $lead_code, ['bank_daily']);
                sync_reserve_daily_task($conn, $user_id, $encryption_key, $lead_code, $lead_name, $owner_code);
                return;
            }
            if (in_array($stage, ['Close', 'Bank'], true)) {
                close_lead_linked_tasks($conn, $user_id, $lead_code, ['reserve_daily']);
                sync_bank_daily_task($conn, $user_id, $encryption_key, $lead_code, $lead_name, $owner_code);
                return;
            }
            if ($stage === 'Win') {
                close_lead_linked_tasks($conn, $user_id, $lead_code);
                return;
            }
            // revival จาก terminal -> yes ที่ขั้นอื่น: ปิด task รอปิดดีล/จองเก่า
            if ($old_status && in_array($old_status, lead_terminal_statuses(), true)) {
                close_lead_linked_tasks($conn, $user_id, $lead_code);
            } elseif ($old_status && in_array($old_status, ['Close', 'Bank'], true) && !in_array($stage, ['Close', 'Bank'], true)) {
                close_lead_linked_tasks($conn, $user_id, $lead_code, ['bank_daily']);
            }
            return;
        }
        if (in_array($outcome, ['lose', 'reject', 'hold'], true)) {
            close_lead_linked_tasks($conn, $user_id, $lead_code);
            $term_status = lead_matrix_outcome_to_terminal_status($outcome);
            notify_owner_on_lead_terminal($conn, $user_id, $encryption_key, $owner_code, $lead_name, $term_status, $terminal_note);
            return;
        }
    }

    if ($status === 'Reserve') {
        if (empty($reserve_date)) {
            $reserve_date = date('Y-m-d');
            $stmt = $conn->prepare("UPDATE leads SET reserve_date = ? WHERE user_id = ? AND lead_code = ?");
            $stmt->bind_param("sis", $reserve_date, $user_id, $lead_code);
            $stmt->execute();
            $stmt->close();
        }
        close_lead_linked_tasks($conn, $user_id, $lead_code, ['bank_daily']);
        sync_reserve_daily_task($conn, $user_id, $encryption_key, $lead_code, $lead_name, $owner_code);
    } elseif (in_array($status, ['Close', 'Bank'], true)) {
        close_lead_linked_tasks($conn, $user_id, $lead_code, ['reserve_daily']);
        sync_bank_daily_task($conn, $user_id, $encryption_key, $lead_code, $lead_name, $owner_code);
    } elseif ($status === 'Win') {
        close_lead_linked_tasks($conn, $user_id, $lead_code);
    } elseif (in_array($status, lead_terminal_statuses(), true)) {
        close_lead_linked_tasks($conn, $user_id, $lead_code);
        notify_owner_on_lead_terminal($conn, $user_id, $encryption_key, $owner_code, $lead_name, $status, $terminal_note);
    } elseif ($old_status && in_array($old_status, ['Close', 'Bank'], true) && !in_array($status, ['Close', 'Bank'], true)) {
        close_lead_linked_tasks($conn, $user_id, $lead_code, ['bank_daily']);
    }
}

/** บันทึก log สถานะ lead */
function log_lead_status($conn, $user_id, $lead_id, $status, $note_enc, $log_date = null) {
    $log_date = $log_date ?: date('Y-m-d');
    $stmt = $conn->prepare("INSERT INTO lead_status_logs (lead_id, user_id, status, note_enc, log_date) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("iisss", $lead_id, $user_id, $status, $note_enc, $log_date);
    $stmt->execute();
    $stmt->close();
}

/** นับ lead ตาม chip filter — นับตาม customer_group_id (เบอร์ซ้ำ = 1) */
function lead_chip_counts($conn, $user_id, $month = null) {
    require_once __DIR__ . '/lib/lead_customer_group.php';
    $events_map = lead_stage_events_map_for_user($conn, $user_id);
    return lead_chip_counts_deduped($conn, $user_id, $month, $events_map);
}

/** เดือนที่ใช้กรอง lead: Win ใช้ win_date, อื่นใช้ contact_date แล้วค่อย updated_at */
function lead_filter_month_for_row($row) {
    $st = $row['status'] ?? '';
    $wd = $row['win_date'] ?? '';
    if ($st === 'Win' && !empty($wd) && $wd !== '0000-00-00') {
        return date('Y-m', strtotime($wd));
    }
    $cd = $row['contact_date'] ?? '';
    if (!empty($cd) && $cd !== '0000-00-00') {
        return date('Y-m', strtotime($cd));
    }
    $ua = $row['updated_at'] ?? '';
    return $ua ? date('Y-m', strtotime($ua)) : date('Y-m');
}

/** ตรวจว่า nesting จะวงจรหรือไม่ */
function task_nest_would_cycle($conn, $user_id, $child_id, $parent_id) {
    if ($parent_id <= 0 || $child_id <= 0 || $parent_id === $child_id) return true;
    $stmt = $conn->prepare("SELECT id, parent_id FROM tasks WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $parent_of = [];
    while ($r = $res->fetch_assoc()) {
        $parent_of[(int)$r['id']] = (int)($r['parent_id'] ?? 0);
    }
    $stmt->close();
    $cur = $parent_id;
    $guard = 0;
    while ($cur > 0 && $guard++ < 200) {
        if ($cur === $child_id) return true;
        $cur = $parent_of[$cur] ?? 0;
    }
    return false;
}

/** คอลัมน์ที่ใช้ snapshot งาน (undo ลบ) */
function task_snapshot_columns() {
    return [
        'id', 'user_id', 'title_enc', 'due_date', 'due_time', 'is_completed', 'priority',
        'lead_code', 'owner_code', 'list_name', 'group_key', 'group_label_enc',
        'task_kind', 'sort_order', 'parent_id',
    ];
}

function task_get_row($conn, $user_id, $task_id) {
    $stmt = $conn->prepare("SELECT * FROM tasks WHERE id = ? AND user_id = ? LIMIT 1");
    $stmt->bind_param("ii", $task_id, $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/** เก็บ snapshot ก่อนลบ (รวมลูกที่จะถูกถอด parent) */
function task_delete_snapshot($conn, $user_id, $task_id) {
    $task = task_get_row($conn, $user_id, $task_id);
    if (!$task) return null;

    $detached = [];
    $stmt = $conn->prepare("SELECT id FROM tasks WHERE parent_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $task_id, $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $detached[] = ['id' => (int)$row['id'], 'parent_id' => $task_id];
    }
    $stmt->close();

    $allowed = task_snapshot_columns();
    $snap_task = [];
    foreach ($allowed as $col) {
        if (array_key_exists($col, $task)) {
            $snap_task[$col] = $task[$col];
        }
    }
    return ['task' => $snap_task, 'detached_children' => $detached];
}

/** ลบงาน + คืน snapshot สำหรับ undo */
function task_delete_with_snapshot($conn, $user_id, $task_id) {
    $snapshot = task_delete_snapshot($conn, $user_id, $task_id);
    if (!$snapshot) {
        return ['success' => false, 'message' => 'ไม่พบงาน'];
    }

    $stmt = $conn->prepare("UPDATE tasks SET parent_id = NULL WHERE parent_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $task_id, $user_id);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM tasks WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $task_id, $user_id);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok
        ? ['success' => true, 'snapshot' => $snapshot]
        : ['success' => false, 'message' => 'ลบไม่สำเร็จ'];
}

/** กู้คืนงานจาก snapshot (undo ลบ) */
function task_restore_snapshot($conn, $user_id, $snapshot) {
    if (!is_array($snapshot) || empty($snapshot['task']['id'])) {
        return ['success' => false, 'message' => 'ข้อมูลกู้คืนไม่ถูกต้อง'];
    }
    $t = $snapshot['task'];
    if ((int)($t['user_id'] ?? 0) !== $user_id) {
        return ['success' => false, 'message' => 'ไม่มีสิทธิ์กู้คืน'];
    }
    $tid = (int)$t['id'];
    $chk = $conn->prepare("SELECT id FROM tasks WHERE id = ? LIMIT 1");
    $chk->bind_param("i", $tid);
    $chk->execute();
    if ($chk->get_result()->fetch_assoc()) {
        $chk->close();
        return ['success' => false, 'message' => 'งานนี้มีอยู่แล้ว'];
    }
    $chk->close();

    $cols = [];
    $vals = [];
    $placeholders = [];
    foreach (task_snapshot_columns() as $col) {
        if (!array_key_exists($col, $t)) continue;
        $cols[] = $col;
        if ($t[$col] === null) {
            $placeholders[] = 'NULL';
        } else {
            $placeholders[] = '?';
            $vals[] = $t[$col];
        }
    }
    if (empty($cols)) {
        return ['success' => false, 'message' => 'ไม่มีข้อมูลงาน'];
    }

    $sql = 'INSERT INTO tasks (' . implode(',', $cols) . ') VALUES (' . implode(',', $placeholders) . ')';
    if (empty($vals)) {
        $ok = $conn->query($sql);
    } else {
        $types = str_repeat('s', count($vals));
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$vals);
        $ok = $stmt->execute();
        $stmt->close();
    }
    if (!$ok) {
        return ['success' => false, 'message' => 'กู้คืนไม่สำเร็จ'];
    }

    if (!empty($snapshot['detached_children'])) {
        $ust = $conn->prepare("UPDATE tasks SET parent_id = ? WHERE id = ? AND user_id = ?");
        foreach ($snapshot['detached_children'] as $c) {
            $cid = (int)($c['id'] ?? 0);
            $pid = (int)($c['parent_id'] ?? 0);
            if ($cid <= 0 || $pid <= 0) continue;
            $ust->bind_param("iii", $pid, $cid, $user_id);
            $ust->execute();
        }
        $ust->close();
    }

    return ['success' => true, 'id' => $tid];
}

/** ย้ายงาน: before | after | child */
function task_move_relative($conn, $user_id, $task_id, $target_id, $mode) {
    if ($task_id <= 0 || $target_id <= 0 || $task_id === $target_id) {
        return ['success' => false, 'message' => 'ข้อมูลไม่ถูกต้อง'];
    }
    $task = task_get_row($conn, $user_id, $task_id);
    $target = task_get_row($conn, $user_id, $target_id);
    if (!$task || !$target) {
        return ['success' => false, 'message' => 'ไม่พบงาน'];
    }

    if ($mode === 'child') {
        if (task_nest_would_cycle($conn, $user_id, $task_id, $target_id)) {
            return ['success' => false, 'message' => 'ไม่สามารถซ้อนงานแบบวงจรได้'];
        }
        $stmt = $conn->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 AS nxt FROM tasks WHERE user_id = ? AND parent_id = ?");
        $stmt->bind_param("ii", $user_id, $target_id);
        $stmt->execute();
        $nxt = (int)($stmt->get_result()->fetch_assoc()['nxt'] ?? 1);
        $stmt->close();
        $stmt = $conn->prepare("UPDATE tasks SET parent_id = ?, sort_order = ? WHERE id = ? AND user_id = ?");
        $stmt->bind_param("iiii", $target_id, $nxt, $task_id, $user_id);
        $ok = $stmt->execute();
        $stmt->close();
        return ['success' => $ok, 'message' => $ok ? 'ย้ายเป็นงานย่อยแล้ว' : 'บันทึกไม่สำเร็จ'];
    }

    $parent_id = $target['parent_id'] ?? null;
    $parent_val = $parent_id ? (int)$parent_id : null;

    if ($mode === 'before') {
        $new_order = (int)($target['sort_order'] ?? 0);
    } else {
        $new_order = (int)($target['sort_order'] ?? 0) + 1;
    }

    if ($parent_val) {
        $stmt = $conn->prepare("UPDATE tasks SET sort_order = sort_order + 1 WHERE user_id = ? AND parent_id = ? AND sort_order >= ? AND id != ?");
        $stmt->bind_param("iiii", $user_id, $parent_val, $new_order, $task_id);
    } else {
        $stmt = $conn->prepare("UPDATE tasks SET sort_order = sort_order + 1 WHERE user_id = ? AND parent_id IS NULL AND sort_order >= ? AND id != ?");
        $stmt->bind_param("iii", $user_id, $new_order, $task_id);
    }
    $stmt->execute();
    $stmt->close();

    if ($parent_val) {
        $stmt = $conn->prepare("UPDATE tasks SET parent_id = ?, sort_order = ? WHERE id = ? AND user_id = ?");
        $stmt->bind_param("iiii", $parent_val, $new_order, $task_id, $user_id);
    } else {
        $stmt = $conn->prepare("UPDATE tasks SET parent_id = NULL, sort_order = ? WHERE id = ? AND user_id = ?");
        $stmt->bind_param("iii", $new_order, $task_id, $user_id);
    }
    $ok = $stmt->execute();
    $stmt->close();
    return ['success' => $ok, 'message' => $ok ? 'ย้ายงานแล้ว' : 'บันทึกไม่สำเร็จ'];
}

/** แปลงแถวงานเป็น JSON สำหรับ UI */
function task_row_client($row, $encryption_key) {
    if (!$row) return null;
    $time = !empty($row['due_time']) ? substr($row['due_time'], 0, 5) : '';
    return [
        'id' => (int)$row['id'],
        'title' => lead_title_for_display(decrypt_data($row['title_enc'], $encryption_key)),
        'due_date' => $row['due_date'] ?? '',
        'due_time' => $time,
        'priority' => (int)($row['priority'] ?? 0),
        'is_completed' => (int)($row['is_completed'] ?? 0),
        'parent_id' => (int)($row['parent_id'] ?? 0),
        'lead_code' => lead_code_for_display($row['lead_code'] ?? ''),
        'owner_code' => $row['owner_code'] ?? '',
        'task_kind' => $row['task_kind'] ?? 'manual',
    ];
}

/** ผูกงานเป็น sub-task */
function task_set_parent($conn, $user_id, $encryption_key, $child_id, $parent_id) {
    $stmt = $conn->prepare("SELECT id, parent_id FROM tasks WHERE id = ? AND user_id = ? LIMIT 1");
    $stmt->bind_param("ii", $child_id, $user_id);
    $stmt->execute();
    $child = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$child) {
        return ['success' => false, 'message' => 'ไม่พบงานย่อย'];
    }
    $old_parent_id = (int)($child['parent_id'] ?? 0);

    if ($parent_id > 0) {
        if (task_nest_would_cycle($conn, $user_id, $child_id, $parent_id)) {
            return ['success' => false, 'message' => 'ไม่สามารถซ้อนงานแบบวงจรได้'];
        }
        $stmt = $conn->prepare("SELECT id FROM tasks WHERE id = ? AND user_id = ? LIMIT 1");
        $stmt->bind_param("ii", $parent_id, $user_id);
        $stmt->execute();
        $parent = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$parent) {
            return ['success' => false, 'message' => 'ไม่พบงานหลัก'];
        }
        $stmt = $conn->prepare("UPDATE tasks SET parent_id = ? WHERE id = ? AND user_id = ?");
        $stmt->bind_param("iii", $parent_id, $child_id, $user_id);
    } else {
        $stmt = $conn->prepare("UPDATE tasks SET parent_id = NULL WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $child_id, $user_id);
    }
    $ok = $stmt->execute();
    $stmt->close();
    return [
        'success' => $ok,
        'message' => $ok ? 'จัดกลุ่มงานแล้ว' : 'บันทึกไม่สำเร็จ',
        'old_parent_id' => $old_parent_id,
        'child_id' => $child_id,
        'parent_id' => $parent_id,
    ];
}

/** แนบ children ให้แต่ละ task (คืนเฉพาะ root) */
function task_build_tree_roots(array $tasks) {
    $by_id = [];
    $child_ids = [];
    foreach ($tasks as $t) {
        $id = (int)$t['id'];
        $by_id[$id] = $t;
        $by_id[$id]['children'] = [];
        $pid = (int)($t['parent_id'] ?? 0);
        if ($pid > 0 && isset($by_id[$pid])) {
            $child_ids[$id] = true;
        } elseif ($pid > 0) {
            // parent อยู่นอกชุดนี้ — แสดงเป็น root ในหน้านี้
        }
    }
    // รอบสอง: parent อาจมาทีหลังในลิสต์
    foreach ($tasks as $t) {
        $id = (int)$t['id'];
        $pid = (int)($t['parent_id'] ?? 0);
        if ($pid > 0 && isset($by_id[$pid])) {
            $child_ids[$id] = true;
        }
    }
    $attach = function ($id) use (&$attach, &$by_id) {
        $node = $by_id[$id];
        $node['children'] = [];
        foreach ($by_id as $cid => $ct) {
            if ((int)($ct['parent_id'] ?? 0) === $id) {
                $node['children'][] = $attach($cid);
            }
        }
        return $node;
    };
    $roots = [];
    foreach ($by_id as $id => $t) {
        if (empty($child_ids[$id])) {
            $roots[] = $attach($id);
        }
    }
    return $roots;
}

function task_count_tree(array $items) {
    $n = 0;
    foreach ($items as $it) {
        $n++;
        if (!empty($it['children'])) {
            $n += task_count_tree($it['children']);
        }
    }
    return $n;
}

/** จัดกลุ่ม Task แบบ TickTick: list_name > group_label */
function build_nested_task_groups($tasks, $decrypt_key, $today_str) {
    $list_order = ['Follow Owner', 'Follow Lead', 'จองรอโอน', 'Inbox'];
    $active = [];
    $done = [];

    foreach ($tasks as $t) {
        $row = $t;
        $row['title'] = lead_title_for_display(decrypt_data($t['title_enc'], $decrypt_key));
        $row['group_label'] = !empty($t['group_label_enc']) ? decrypt_data($t['group_label_enc'], $decrypt_key) : '';
        if ((int)$t['is_completed'] === 1) {
            $done[] = $row;
            continue;
        }
        $list = trim($t['list_name'] ?? '') ?: 'Inbox';
        $group = trim($row['group_label']) ?: ($t['lead_code'] ?: ($t['owner_code'] ?: 'ทั่วไป'));
        if (!isset($active[$list])) $active[$list] = [];
        if (!isset($active[$list][$group])) $active[$list][$group] = [];
        $active[$list][$group][] = $row;
    }

    // จัดเป็น tree (sub-task ใต้งานหลัก)
    foreach ($active as $ln => &$groups) {
        foreach ($groups as $gl => &$items) {
            $items = task_build_tree_roots($items);
        }
        unset($groups, $items);
    }
    unset($groups);

    $ordered = [];
    foreach ($list_order as $ln) {
        if (!empty($active[$ln])) {
            $ordered[$ln] = $active[$ln];
            unset($active[$ln]);
        }
    }
    foreach ($active as $ln => $groups) {
        $ordered[$ln] = $groups;
    }

    $overdue = [];
    $collect_overdue = function ($items) use (&$collect_overdue, &$overdue, $today_str) {
        foreach ($items as $it) {
            if (!empty($it['due_date']) && $it['due_date'] < $today_str && (int)($it['is_completed'] ?? 0) === 0) {
                $overdue[] = $it;
            }
            if (!empty($it['children'])) $collect_overdue($it['children']);
        }
    };
    foreach ($ordered as $list_name => $groups) {
        foreach ($groups as $group_label => $items) {
            $collect_overdue($items);
        }
    }

    return ['nested' => $ordered, 'done' => $done, 'overdue' => $overdue];
}

/** จัดกลุ่ม Task ตามวันที่ (รายการปกติ) + sub-task ใต้ parent */
function build_task_time_groups($tasks, $decrypt_key, $today_str) {
    $active = [];
    $done = [];
    foreach ($tasks as $t) {
        $row = $t;
        $row['title'] = lead_title_for_display(decrypt_data($t['title_enc'], $decrypt_key));
        if ((int)$t['is_completed'] === 1) {
            $done[] = $row;
        } else {
            $active[] = $row;
        }
    }
    $roots = task_build_tree_roots($active);
    $groups = ['overdue' => [], 'today' => [], 'upcoming' => [], 'no_date' => []];
    foreach ($roots as $r) {
        $due = $r['due_date'] ?? '';
        if ($due === '' || $due === '0000-00-00') {
            $groups['no_date'][] = $r;
        } elseif ($due < $today_str) {
            $groups['overdue'][] = $r;
        } elseif ($due === $today_str) {
            $groups['today'][] = $r;
        } else {
            $groups['upcoming'][] = $r;
        }
    }
    return [
        'groups' => $groups,
        'done' => array_slice(task_build_tree_roots($done), 0, 20),
    ];
}

/** HTML แถวงาน + sub-task (recursive) */
function render_task_rows_html(array $tasks, int $depth, array $prio_meta, string $today_str, array $opts = []) {
    $html = '';
    $skip_overdue = !empty($opts['skip_overdue']);
    foreach ($tasks as $t) {
        if ($skip_overdue && !empty($t['due_date']) && $t['due_date'] < $today_str) {
            if (!empty($t['children'])) {
                $html .= render_task_rows_html($t['children'], $depth + 1, $prio_meta, $today_str, $opts);
            }
            continue;
        }
        $done = (int)($t['is_completed'] ?? 0) === 1;
        [$dlbl] = due_label($t['due_date'] ?? '');
        $prio = (int)($t['priority'] ?? 0);
        $time_txt = !empty($t['due_time']) ? substr($t['due_time'], 0, 5) : '';
        $tid = (int)$t['id'];
        $pid = (int)($t['parent_id'] ?? 0);
        $indent = $depth > 0 ? ' style="margin-left:' . ($depth * 14) . 'px"' : '';
        $border = !empty($opts['overdue']) ? ' border-red-500/30' : ' border-[var(--border)]';
        $opacity = $done ? ' opacity-70' : '';
        $strike = $done ? ' line-through text-[var(--faint)]' : '';

        $html .= '<li class="task-item flex items-center gap-3 bg-[var(--card)] border rounded-xl px-3.5 py-3' . $border . $opacity . '"'
            . $indent
            . ' data-task-id="' . $tid . '"'
            . ' data-parent-id="' . $pid . '"'
            . ' data-depth="' . $depth . '"'
            . ' data-due="' . htmlspecialchars($t['due_date'] ?? '', ENT_QUOTES) . '"'
            . ' data-time="' . htmlspecialchars($time_txt, ENT_QUOTES) . '"'
            . ' data-priority="' . $prio . '"'
            . ' data-done="' . ($done ? '1' : '0') . '"'
            . ' data-lead="' . htmlspecialchars($t['lead_code'] ?? '', ENT_QUOTES) . '"'
            . ' data-owner="' . htmlspecialchars($t['owner_code'] ?? '', ENT_QUOTES) . '">';

        if ($done) {
            $html .= '<button type="button" class="task-toggle w-5 h-5 rounded-full border-2 shrink-0 flex items-center justify-center bg-emerald-500 border-emerald-500" data-done="1">'
                . '<i data-lucide="check" class="w-3 h-3 text-white"></i></button>';
        } else {
            $html .= '<button type="button" class="task-toggle w-5 h-5 rounded-full border-2 shrink-0 flex items-center justify-center transition border-[var(--border-2)] active:border-[#E2E800]" data-done="0"></button>';
        }

        $html .= '<div class="task-open flex-1 min-w-0 cursor-pointer active:opacity-80">';
        if ($depth > 0) {
            $html .= '<p class="text-[10px] text-[var(--faint)] mb-0.5 flex items-center gap-1">'
                . '<i data-lucide="corner-down-right" class="w-3 h-3"></i>งานย่อย</p>';
        }
        $html .= '<p class="text-sm task-title-line' . $strike . '">' . htmlspecialchars($t['title'] ?? '') . '</p>';
        $html .= '<p class="text-[11px] text-[var(--faint)] flex items-center gap-1.5 flex-wrap"><span>';
        $html .= thai_short_date($t['due_date'] ?? '');
        if ($time_txt !== '') $html .= ' · ' . $time_txt . ' น.';
        if (!$done && $dlbl !== '') $html .= ' · ' . $dlbl;
        if (!empty($t['lead_code'])) $html .= ' · ' . htmlspecialchars(lead_code_for_display($t['lead_code']));
        $html .= '</span>';
        if (!$done && isset($prio_meta[$prio])) {
            $html .= '<span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded font-bold text-[10px] ' . $prio_meta[$prio][1] . '">'
                . '<i data-lucide="' . $prio_meta[$prio][2] . '" class="w-3 h-3"></i>' . $prio_meta[$prio][0] . '</span>';
        }
        $html .= '</p></div>';
        $html .= '<button type="button" class="task-menu-btn w-8 h-8 rounded-lg flex items-center justify-center text-[var(--faint)] hover:text-[var(--text-2)] hover:bg-[var(--surface)] shrink-0 transition" aria-label="ตัวเลือกงาน">'
            . '<i data-lucide="more-horizontal" class="w-4 h-4"></i></button>';
        $html .= '</li>';

        if (!empty($t['children'])) {
            $html .= render_task_rows_html($t['children'], $depth + 1, $prio_meta, $today_str, $opts);
        }
    }
    return $html;
}

/** สร้าง HTML รายการงานสำหรับ refresh แบบไม่ reload หน้า */
function render_tasks_list_fragment($conn, $user_id, $encryption_key) {
    $today_str = date('Y-m-d');
    $stmt = $conn->prepare("SELECT * FROM tasks WHERE user_id = ?
        ORDER BY is_completed ASC,
                 due_date IS NULL, due_date ASC,
                 parent_id IS NULL, parent_id ASC,
                 sort_order ASC,
                 due_time IS NULL, due_time ASC,
                 priority = 0, priority ASC,
                 created_at ASC
        LIMIT 200");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $all_tasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $task_time = build_task_time_groups($all_tasks, $encryption_key, $today_str);
    $task_dates = [];
    foreach ($all_tasks as $t) {
        $d = $t['due_date'] ?? '';
        if ($d === '' || $d === '0000-00-00') continue;
        if (!isset($task_dates[$d])) $task_dates[$d] = ['pending' => 0, 'done' => 0];
        $task_dates[$d][(int)$t['is_completed'] === 1 ? 'done' : 'pending']++;
    }

    $prio_meta = [
        1 => ['ทำทันที',  'bg-red-500/15 text-red-400',                  'flame'],
        2 => ['วางแผนทำ', 'bg-[#E2E800]/15 text-[var(--accent-text)]',   'calendar-check'],
        3 => ['มอบหมาย',  'bg-amber-500/15 text-amber-500',              'send'],
        4 => ['ทำทีหลัง', 'bg-[var(--chip)] text-[var(--muted)]',        'coffee'],
    ];
    $task_group_meta = [
        'overdue'  => ['label' => 'เกินกำหนด',   'tone' => 'text-red-400', 'icon' => 'alert-circle'],
        'today'    => ['label' => 'วันนี้',        'tone' => 'text-[var(--accent-text)]', 'icon' => 'sun'],
        'upcoming' => ['label' => 'กำลังจะถึง',   'tone' => 'text-[var(--text-2)]', 'icon' => 'calendar'],
        'no_date'  => ['label' => 'ยังไม่กำหนดวัน', 'tone' => 'text-[var(--muted)]', 'icon' => 'circle-dashed'],
    ];

    $pending = 0;
    foreach ($all_tasks as $t) {
        if ((int)$t['is_completed'] === 0) $pending++;
    }

    ob_start();
    if (count($all_tasks) === 0) {
        echo '<div id="tasks-empty-state" class="text-center py-16">';
        echo '<i data-lucide="check-circle-2" class="w-10 h-10 text-[var(--border-2)] mx-auto mb-3"></i>';
        echo '<p class="text-sm text-[var(--muted)]">ยังไม่มีงานในระบบ</p>';
        echo '<p class="text-xs text-[var(--faint)] mt-1">กดปุ่ม + มุมขวาล่าง หรือสั่งเลขา AI ในแชท LINE</p>';
        echo '</div>';
    }
    echo '<div id="task-groups-wrap" class="space-y-4">';
    foreach ($task_group_meta as $gkey => $gmeta) {
        $gcount = count($task_time['groups'][$gkey]);
        echo '<div class="task-group' . ($gcount === 0 ? ' hidden' : '') . '" data-group="' . htmlspecialchars($gkey) . '">';
        echo '<p class="task-group-label text-xs font-bold mb-2 ' . $gmeta['tone'] . ' flex items-center gap-1.5">';
        echo '<i data-lucide="' . $gmeta['icon'] . '" class="w-3.5 h-3.5"></i>';
        echo htmlspecialchars($gmeta['label']) . ' · <span class="task-group-count">' . task_count_tree($task_time['groups'][$gkey]) . '</span></p>';
        echo '<ul class="space-y-2 task-group-list">';
        echo render_task_rows_html($task_time['groups'][$gkey], 0, $prio_meta, $today_str, ['overdue' => $gkey === 'overdue']);
        echo '</ul></div>';
    }
    echo '</div>';
    if (count($task_time['done']) > 0) {
        echo '<div id="task-done-group" class="task-group mt-4">';
        echo '<p class="text-xs font-bold mb-2 text-[var(--faint)] flex items-center gap-1.5">';
        echo '<i data-lucide="check-circle-2" class="w-3.5 h-3.5"></i>เสร็จแล้ว · ' . task_count_tree($task_time['done']) . '</p>';
        echo '<ul class="space-y-2 task-group-list">';
        echo render_task_rows_html($task_time['done'], 0, $prio_meta, $today_str);
        echo '</ul></div>';
    }
    $html = ob_get_clean();

    return [
        'html' => $html,
        'task_dates' => $task_dates,
        'pending' => $pending,
    ];
}

/** ดึงบริบท capacity สำหรับ AI */
function fetch_user_capacity_context($conn, $user_id, $encryption_key) {
    $stmt = $conn->prepare("SELECT max_active_leads, daily_task_capacity FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $max_leads = (int)($u['max_active_leads'] ?? 30);
    $max_tasks = (int)($u['daily_task_capacity'] ?? 8);

    $terminal = lead_terminal_statuses();
    $placeholders = implode(',', array_fill(0, count($terminal), '?'));
    $sql = "SELECT COUNT(*) c FROM leads WHERE user_id = ? AND status NOT IN ($placeholders,'Win')";
    $stmt = $conn->prepare($sql);
    $types = 'i' . str_repeat('s', count($terminal));
    $params = array_merge([$user_id], $terminal);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $active_leads = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    $today = date('Y-m-d');
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM tasks WHERE user_id = ? AND is_completed = 0 AND (due_date IS NULL OR due_date <= ?)");
    $stmt->bind_param("is", $user_id, $today);
    $stmt->execute();
    $tasks_due = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    $reserve = 0;
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM leads WHERE user_id = ? AND status = 'Reserve'");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $reserve = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    return [
        'max_active_leads' => $max_leads,
        'daily_task_capacity' => $max_tasks,
        'active_leads' => $active_leads,
        'tasks_due_today' => $tasks_due,
        'reserve_cases' => $reserve,
        'load_pct' => $max_leads > 0 ? min(100, (int)round($active_leads / $max_leads * 100)) : 0,
    ];
}

/** slug จากชื่อโครงการ */
function project_make_slug($name) {
    $s = mb_strtolower(trim($name), 'UTF-8');
    $s = preg_replace('/\s+/u', '-', $s);
    $s = preg_replace('/[^a-z0-9ก-๙\-]/u', '', $s);
    return $s !== '' ? $s : 'project-' . substr(md5($name), 0, 8);
}
