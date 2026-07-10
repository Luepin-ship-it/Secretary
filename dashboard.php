<?php
// dashboard.php
// Dashboard หลัก (Mobile-first) โทน Salt & Pepper — 5 แท็บ: Home / Product / Lead / Tasks / Pipeline
require_once 'config.php';
require_once 'auth.php';
require_once 'task_helpers.php';
require_once 'branch_config.php';
require_once 'ms_report_helpers.php';
require_once __DIR__ . '/lib/contact_normalize.php';
require_once __DIR__ . '/lib/gdrive_cover.php';
require_once __DIR__ . '/lib/owner_field_normalize.php';
require_once __DIR__ . '/lib/subscription.php';
require_once __DIR__ . '/lib/lead_customer_group.php';
require_once __DIR__ . '/lib/map_coords.php';

auth_require_registration($conn);

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$user = auth_current_user($conn);

$key     = $user['encryption_key'];
$user_id = (int)$user['id'];

ms_ensure_schema($conn);
$is_metal_sheet = branch_is_metal_sheet($user);
$nav_items = branch_nav_items($user);
$ms_sales_name = branch_sales_display_name($user);
$ms_report_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['report_date'] ?? '') ? $_GET['report_date'] : date('Y-m-d');
$ms_stats = $is_metal_sheet ? ms_get_daily_stats($conn, $user_id, $ms_report_date) : [];
$ms_entries = $is_metal_sheet ? ms_entries_for_date($conn, $user_id, $key, $ms_report_date) : [];
$ms_month = $is_metal_sheet ? ms_month_summary($conn, $user_id, (int)date('Y'), (int)date('n')) : [];
$ms_today_deposit = $is_metal_sheet ? ms_today_deposit_total($conn, $user_id, $ms_report_date) : 0;
$ms_preview_text = $is_metal_sheet
    ? ms_build_report_text($ms_sales_name, $ms_report_date, $ms_stats, $ms_entries, $ms_month)
    : '';

task_ensure_schema($conn);
subscription_ensure_schema($conn);
lead_stage_events_ensure_schema($conn);
require_once __DIR__ . '/lib/lead_sheet_schema.php';
lead_sheet_ensure_schema($conn);
lead_customer_group_ensure_schema($conn);
lead_customer_group_maybe_backfill($conn, $user_id, $key);
refresh_reserve_tasks($conn, $user_id);

// ===== อัปเกรดโครงสร้างตาราง tasks อัตโนมัติ (เพิ่ม priority แบบ Eisenhower + เวลาแจ้งเตือน) =====
$col_check = $conn->query("SHOW COLUMNS FROM tasks LIKE 'priority'");
if ($col_check && $col_check->num_rows === 0) {
    $conn->query("ALTER TABLE tasks
        ADD COLUMN priority TINYINT DEFAULT 0 COMMENT 'Eisenhower: 1=ด่วน+สำคัญ 2=สำคัญไม่ด่วน 3=ด่วนไม่สำคัญ 4=ไม่ด่วนไม่สำคัญ' AFTER is_completed,
        ADD COLUMN due_time TIME DEFAULT NULL COMMENT 'เวลาแจ้งเตือน (สำหรับลิงก์แจ้งเตือน LINE)' AFTER due_date");
}

// ===== อัปเกรดตาราง owners สำหรับ Product card + infowindow =====
$owner_cols = [
    "project_name_en_enc TEXT DEFAULT NULL AFTER project_enc",
    "project_name_th_enc TEXT DEFAULT NULL AFTER project_name_en_enc",
    "cover_image_url VARCHAR(512) DEFAULT NULL COMMENT 'ลิงก์รูปปก Google Drive'",
    "photos_link_enc TEXT DEFAULT NULL COMMENT 'ลิงก์โฟลเดอร์รูปทั้งหมด'",
    "listing_source VARCHAR(50) DEFAULT NULL COMMENT 'survey, FB, livinginsider, other'",
    "marketing_date DATE DEFAULT NULL",
    "has_deed TINYINT DEFAULT NULL COMMENT '1=มีโฉนด 0=ไม่มี'",
    "owner_asking_price_enc TEXT DEFAULT NULL COMMENT 'ราคาที่เจ้าของตั้ง'",
    "sold_date DATE DEFAULT NULL",
    "maid_enc TEXT DEFAULT NULL COMMENT 'ห้องแม่บ้าน'",
    "last_contact_date DATE DEFAULT NULL COMMENT 'ติดต่อล่าสุด'",
    "contact_summary_enc TEXT DEFAULT NULL COMMENT 'สรุปการติดต่อล่าสุด'",
    "price_consult_enc TEXT DEFAULT NULL COMMENT 'Consult ราคา'",
    "soi_enc TEXT DEFAULT NULL COMMENT 'ซอย (เข้ารหัส)'",
    "lat DECIMAL(10,7) DEFAULT NULL COMMENT 'พิกัดแผนที่'",
    "lng DECIMAL(10,7) DEFAULT NULL COMMENT 'พิกัดแผนที่'",
];
foreach ($owner_cols as $col_def) {
    $col_name = preg_match('/^(\w+)/', $col_def, $m) ? $m[1] : '';
    if ($col_name === '') continue;
    $chk = $conn->query("SHOW COLUMNS FROM owners LIKE '$col_name'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query("ALTER TABLE owners ADD COLUMN $col_def");
    }
}

// ===== ตารางประวัติติดต่อ & ปรับราคา (Owners) =====
$conn->query("CREATE TABLE IF NOT EXISTS owner_contact_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_id INT NOT NULL,
    user_id INT NOT NULL,
    contact_date DATE NOT NULL,
    note_enc TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_id) REFERENCES owners(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_owner_contact (owner_id, contact_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$conn->query("CREATE TABLE IF NOT EXISTS owner_price_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_id INT NOT NULL,
    user_id INT NOT NULL,
    log_date DATE NOT NULL,
    old_price_enc TEXT DEFAULT NULL,
    new_price_enc TEXT NOT NULL,
    changed_by VARCHAR(20) DEFAULT 'owner' COMMENT 'owner=เจ้าของปรับ agent=ที่ปรึกษาปรับ',
    note_enc TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_id) REFERENCES owners(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_owner_price (owner_id, log_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ===== อัปเกรดตาราง leads สำหรับ infowindow + webhook =====
$lead_cols = [
    "customer_insight_enc TEXT DEFAULT NULL",
    "deal_context_enc TEXT DEFAULT NULL",
    "priority_score TINYINT DEFAULT 3",
    "owner_code VARCHAR(50) DEFAULT NULL COMMENT 'รหัสทรัพย์ที่ลูกค้าสนใจ เช่น DEMO-O01'",
    "chat_image_url VARCHAR(512) DEFAULT NULL COMMENT 'รูปแชท/ทรัพย์จากลูกค้า'",
    "chat_photos_link_enc TEXT DEFAULT NULL COMMENT 'ลิงก์โฟลเดอร์ Drive รูปแชท'",
    "product_price_enc TEXT DEFAULT NULL COMMENT 'ราคาทรัพย์ (ถ้าไม่ผูก owner)'",
    "win_date DATE DEFAULT NULL",
    "win_price_enc TEXT DEFAULT NULL",
    "win_payment_method VARCHAR(20) DEFAULT NULL COMMENT 'cash|loan'",
    "visited_unit_enc TEXT DEFAULT NULL COMMENT 'ห้อง/บ้านเลขที่ที่ลูกค้าเข้าดู'",
    "lat DECIMAL(10,7) DEFAULT NULL COMMENT 'พิกัดแผนที่ (หรือจาก owner)'",
    "lng DECIMAL(10,7) DEFAULT NULL COMMENT 'พิกัดแผนที่'",
    "lead_aux_tag VARCHAR(24) DEFAULT NULL COMMENT 'agent|boss|eval|friend — Lead สถานะอื่นๆ'",
    "offered_listings_enc TEXT DEFAULT NULL COMMENT 'รายการโครงการ/รหัสทรัพย์ที่เสนอไปแล้ว'",
    "win_close_scope VARCHAR(10) DEFAULT 'this' COMMENT 'this=จบหลังนี้ other=หลังอื่น'",
    "close_open_price_enc TEXT DEFAULT NULL COMMENT 'ราคาเปิดทรัพย์ที่ปิด (หลังอื่น)'",
];
foreach ($lead_cols as $col_def) {
    $col_name = preg_match('/^(\w+)/', $col_def, $m) ? $m[1] : '';
    if ($col_name === '') continue;
    $chk = $conn->query("SHOW COLUMNS FROM leads LIKE '$col_name'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query("ALTER TABLE leads ADD COLUMN $col_def");
    }
}
$conn->query("CREATE TABLE IF NOT EXISTS lead_status_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lead_id INT NOT NULL,
    user_id INT NOT NULL,
    status VARCHAR(30) NOT NULL,
    note_enc TEXT DEFAULT NULL,
    log_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_lead_status (lead_id, log_date DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ===== ตารางตั้งเป้า Pipeline (รายได้ + conversion funnel) =====
$conn->query("CREATE TABLE IF NOT EXISTS pipeline_settings (
    user_id INT PRIMARY KEY,
    target_month CHAR(7) NOT NULL DEFAULT '',
    monthly_target INT UNSIGNED DEFAULT 0,
    commission_per_deal INT UNSIGNED DEFAULT 50000,
    project_target_price INT UNSIGNED DEFAULT 5000000,
    rate_project_lead DECIMAL(5,2) DEFAULT 25.00,
    rate_lead_app DECIMAL(5,2) DEFAULT 50.00,
    rate_app_show DECIMAL(5,2) DEFAULT 60.00,
    rate_show_nego DECIMAL(5,2) DEFAULT 45.00,
    rate_nego_win DECIMAL(5,2) DEFAULT 40.00,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$need_col = $conn->query("SHOW COLUMNS FROM pipeline_settings LIKE 'need_project'");
if ($need_col && $need_col->num_rows === 0) {
    $conn->query("ALTER TABLE pipeline_settings
        ADD COLUMN need_project INT UNSIGNED DEFAULT 0,
        ADD COLUMN need_lead INT UNSIGNED DEFAULT 0,
        ADD COLUMN need_app INT UNSIGNED DEFAULT 0,
        ADD COLUMN need_showing INT UNSIGNED DEFAULT 0,
        ADD COLUMN need_nego INT UNSIGNED DEFAULT 0");
}

// ===== AJAX API (Tasks + Pipeline) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $ajax = $_POST['ajax'];

    if ($ajax === 'task_toggle') {
        $task_id = (int)($_POST['id'] ?? 0);
        $done    = (int)($_POST['is_completed'] ?? 0);
        $prev = $conn->prepare("SELECT is_completed FROM tasks WHERE id = ? AND user_id = ? LIMIT 1");
        $prev->bind_param("ii", $task_id, $user_id);
        $prev->execute();
        $row = $prev->get_result()->fetch_assoc();
        $prev->close();
        $was_done = (int)($row['is_completed'] ?? 0);
        $stmt = $conn->prepare("UPDATE tasks SET is_completed = ? WHERE id = ? AND user_id = ?");
        $stmt->bind_param("iii", $done, $task_id, $user_id);
        $ok = $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => $ok, 'was_completed' => $was_done, 'id' => $task_id]);
        exit();
    }

    if ($ajax === 'task_add') {
        $title    = trim($_POST['title'] ?? '');
        $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : date('Y-m-d');
        $due_time = trim($_POST['due_time'] ?? '');
        $due_time = preg_match('/^\d{2}:\d{2}$/', $due_time) ? $due_time . ':00' : null;
        $priority = max(0, min(4, (int)($_POST['priority'] ?? 0))); // 0 = ไม่ระบุ, 1-4 = Eisenhower
        if ($title === '') {
            echo json_encode(['success' => false, 'message' => 'กรุณากรอกชื่องาน']);
            exit();
        }
        $title_enc = encrypt_data($title, $key);
        $stmt = $conn->prepare("INSERT INTO tasks (user_id, title_enc, due_date, due_time, priority, is_completed) VALUES (?, ?, ?, ?, ?, 0)");
        $stmt->bind_param("isssi", $user_id, $title_enc, $due_date, $due_time, $priority);
        $ok = $stmt->execute();
        $new_id = $ok ? (int)$conn->insert_id : 0;
        $stmt->close();
        $snapshot = null;
        $html = '';
        $group = 'today';
        if ($ok && $new_id > 0) {
            $snapshot = task_delete_snapshot($conn, $user_id, $new_id);
            $row = task_get_row($conn, $user_id, $new_id);
            if ($row) {
                $row['title'] = lead_title_for_display(decrypt_data($row['title_enc'], $key));
                $today_ajax = date('Y-m-d');
                $due = $row['due_date'] ?? '';
                if ($due === '' || $due === '0000-00-00') {
                    $group = 'no_date';
                } elseif ($due < $today_ajax) {
                    $group = 'overdue';
                } elseif ($due === $today_ajax) {
                    $group = 'today';
                } else {
                    $group = 'upcoming';
                }
                $prio_meta_ajax = [
                    1 => ['ทำทันที',  'bg-red-500/15 text-red-400',                'flame'],
                    2 => ['วางแผนทำ', 'bg-[#E2E800]/15 text-[var(--accent-text)]', 'calendar-check'],
                    3 => ['มอบหมาย',  'bg-amber-500/15 text-amber-500',            'send'],
                    4 => ['ทำทีหลัง', 'bg-[var(--chip)] text-[var(--muted)]',      'coffee'],
                ];
                $roots = task_build_tree_roots([$row]);
                $html = render_task_rows_html($roots, 0, $prio_meta_ajax, $today_ajax, ['overdue' => $group === 'overdue']);
            }
        }
        echo json_encode(['success' => $ok, 'id' => $new_id, 'snapshot' => $snapshot, 'html' => $html, 'group' => $group]);
        exit();
    }

    if ($ajax === 'task_delete') {
        $task_id = (int)($_POST['id'] ?? 0);
        $result = task_delete_with_snapshot($conn, $user_id, $task_id);
        echo json_encode($result);
        exit();
    }

    if ($ajax === 'task_restore') {
        $raw = $_POST['snapshot'] ?? '';
        $snapshot = is_string($raw) ? json_decode($raw, true) : $raw;
        $result = task_restore_snapshot($conn, $user_id, $snapshot);
        echo json_encode($result);
        exit();
    }

    if ($ajax === 'task_row') {
        $task_id = (int)($_POST['id'] ?? 0);
        $row = task_get_row($conn, $user_id, $task_id);
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'ไม่พบงาน']);
            exit();
        }
        $row['title'] = lead_title_for_display(decrypt_data($row['title_enc'], $key));
        $today_ajax = date('Y-m-d');
        $due = $row['due_date'] ?? '';
        if ($due === '' || $due === '0000-00-00') {
            $group = 'no_date';
        } elseif ($due < $today_ajax) {
            $group = 'overdue';
        } elseif ($due === $today_ajax) {
            $group = 'today';
        } else {
            $group = 'upcoming';
        }
        $prio_meta_ajax = [
            1 => ['ทำทันที',  'bg-red-500/15 text-red-400',                'flame'],
            2 => ['วางแผนทำ', 'bg-[#E2E800]/15 text-[var(--accent-text)]', 'calendar-check'],
            3 => ['มอบหมาย',  'bg-amber-500/15 text-amber-500',            'send'],
            4 => ['ทำทีหลัง', 'bg-[var(--chip)] text-[var(--muted)]',      'coffee'],
        ];
        $roots = task_build_tree_roots([$row]);
        $html = render_task_rows_html($roots, 0, $prio_meta_ajax, $today_ajax, ['overdue' => $group === 'overdue']);
        echo json_encode(['success' => true, 'html' => $html, 'group' => $group]);
        exit();
    }

    if ($ajax === 'task_detail') {
        $task_id = (int)($_POST['id'] ?? 0);
        $row = task_get_row($conn, $user_id, $task_id);
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'ไม่พบงาน']);
            exit();
        }
        echo json_encode(['success' => true, 'task' => task_row_client($row, $key)]);
        exit();
    }

    if ($ajax === 'task_update') {
        $task_id = (int)($_POST['id'] ?? 0);
        $row = task_get_row($conn, $user_id, $task_id);
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'ไม่พบงาน']);
            exit();
        }
        $fields = [];
        $types = '';
        $vals = [];

        if (isset($_POST['title'])) {
            $title = trim($_POST['title']);
            if ($title === '') {
                echo json_encode(['success' => false, 'message' => 'ชื่องานว่างไม่ได้']);
                exit();
            }
            $fields[] = 'title_enc = ?';
            $types .= 's';
            $vals[] = encrypt_data($title, $key);
        }
        if (array_key_exists('due_date', $_POST)) {
            $due = trim($_POST['due_date'] ?? '');
            $due = preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) ? $due : null;
            $fields[] = 'due_date = ?';
            $types .= 's';
            $vals[] = $due;
        }
        if (array_key_exists('due_time', $_POST)) {
            $dt = trim($_POST['due_time'] ?? '');
            $dt = preg_match('/^\d{2}:\d{2}$/', $dt) ? $dt . ':00' : null;
            $fields[] = 'due_time = ?';
            $types .= 's';
            $vals[] = $dt;
        }
        if (isset($_POST['priority'])) {
            $prio = max(0, min(4, (int)$_POST['priority']));
            $fields[] = 'priority = ?';
            $types .= 'i';
            $vals[] = $prio;
        }
        if (empty($fields)) {
            echo json_encode(['success' => false, 'message' => 'ไม่มีข้อมูลอัปเดต']);
            exit();
        }
        $sql = 'UPDATE tasks SET ' . implode(', ', $fields) . ' WHERE id = ? AND user_id = ?';
        $types .= 'ii';
        $vals[] = $task_id;
        $vals[] = $user_id;
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$vals);
        $ok = $stmt->execute();
        $stmt->close();
        $fresh = task_get_row($conn, $user_id, $task_id);
        echo json_encode(['success' => $ok, 'task' => task_row_client($fresh, $key)]);
        exit();
    }

    if ($ajax === 'task_move') {
        $task_id = (int)($_POST['id'] ?? 0);
        $target_id = (int)($_POST['target_id'] ?? 0);
        $mode = $_POST['mode'] ?? 'after';
        if (!in_array($mode, ['before', 'after', 'child'], true)) {
            $mode = 'after';
        }
        $result = task_move_relative($conn, $user_id, $task_id, $target_id, $mode);
        echo json_encode($result);
        exit();
    }

    if ($ajax === 'task_list_html') {
        $frag = render_tasks_list_fragment($conn, $user_id, $key);
        echo json_encode(['success' => true] + $frag);
        exit();
    }

    if ($ajax === 'task_nest') {
        $child_id = (int)($_POST['child_id'] ?? 0);
        $parent_id = (int)($_POST['parent_id'] ?? 0);
        $result = task_set_parent($conn, $user_id, $key, $child_id, $parent_id);
        echo json_encode($result);
        exit();
    }

    if ($ajax === 'pipeline_save') {
        $target_month = preg_match('/^\d{4}-\d{2}$/', $_POST['target_month'] ?? '') ? $_POST['target_month'] : date('Y-m');
        $monthly_target = max(0, (int)($_POST['monthly_target'] ?? 0));
        $commission = max(1000, (int)($_POST['commission_per_deal'] ?? 50000));
        $project_price = max(0, (int)($_POST['project_target_price'] ?? 0));
        $need_project = max(0, (int)($_POST['need_project'] ?? 0));
        $need_lead    = max(0, (int)($_POST['need_lead'] ?? 0));
        $need_app     = max(0, (int)($_POST['need_app'] ?? 0));
        $need_showing = max(0, (int)($_POST['need_showing'] ?? 0));
        $need_nego    = max(0, (int)($_POST['need_nego'] ?? 0));
        $stmt = $conn->prepare("INSERT INTO pipeline_settings
            (user_id, target_month, monthly_target, commission_per_deal, project_target_price,
             need_project, need_lead, need_app, need_showing, need_nego)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            target_month=VALUES(target_month), monthly_target=VALUES(monthly_target),
            commission_per_deal=VALUES(commission_per_deal), project_target_price=VALUES(project_target_price),
            need_project=VALUES(need_project), need_lead=VALUES(need_lead),
            need_app=VALUES(need_app), need_showing=VALUES(need_showing), need_nego=VALUES(need_nego)");
        $stmt->bind_param("isiiiiiiii", $user_id, $target_month, $monthly_target, $commission, $project_price,
            $need_project, $need_lead, $need_app, $need_showing, $need_nego);
        $ok = $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => $ok]);
        exit();
    }

    if ($ajax === 'owner_ai_fill') {
        require_once __DIR__ . '/lib/listing_ai_parser.php';
        $raw = trim($_POST['raw_text'] ?? '');
        if ($raw === '') {
            echo json_encode(['success' => false, 'message' => 'วางข้อความก่อน']);
            exit();
        }
        $ai = listing_ai_parse_owner($raw);
        if (empty($ai['data'])) {
            echo json_encode([
                'success' => false,
                'message' => implode(', ', $ai['errors'] ?? ['อ่านข้อมูลไม่ได้']),
            ]);
            exit();
        }
        echo json_encode([
            'success' => true,
            'fields' => listing_ai_owner_dashboard_map($ai['data']),
            'warnings' => $ai['errors'] ?? [],
        ]);
        exit();
    }

    if ($ajax === 'lead_ai_fill') {
        require_once __DIR__ . '/lib/listing_ai_parser.php';
        $raw = trim($_POST['raw_text'] ?? '');
        if ($raw === '') {
            echo json_encode(['success' => false, 'message' => 'วางข้อความก่อน']);
            exit();
        }
        $ai = listing_ai_parse_lead($raw);
        if (empty($ai['data'])) {
            echo json_encode([
                'success' => false,
                'message' => implode(', ', $ai['errors'] ?? ['อ่านข้อมูลไม่ได้']),
            ]);
            exit();
        }
        echo json_encode([
            'success' => true,
            'fields' => listing_ai_lead_dashboard_map($ai['data']),
            'warnings' => $ai['errors'] ?? [],
        ]);
        exit();
    }

    if ($ajax === 'owner_save') {
        $oid = (int)($_POST['owner_id'] ?? 0);
        if ($oid <= 0) {
            echo json_encode(['success' => false, 'message' => 'ไม่พบทรัพย์']);
            exit();
        }
        $chk = $conn->prepare("SELECT id FROM owners WHERE id = ? AND user_id = ? LIMIT 1");
        $chk->bind_param("ii", $oid, $user_id);
        $chk->execute();
        if (!$chk->get_result()->fetch_assoc()) {
            $chk->close();
            echo json_encode(['success' => false, 'message' => 'ไม่มีสิทธิ์แก้ไขทรัพย์นี้']);
            exit();
        }
        $chk->close();

        $enc = function ($v) use ($key) {
            $v = trim((string)$v);
            return $v === '' ? null : encrypt_data($v, $key);
        };
        $name_en = trim($_POST['name_en'] ?? '');
        $has_deed = $_POST['has_deed'] ?? '';
        $has_deed_val = ($has_deed === '' || $has_deed === 'null') ? null : (int)$has_deed;
        $last_contact = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['last_contact'] ?? '') ? $_POST['last_contact'] : null;
        $cover_url = trim($_POST['cover_image_url'] ?? '');
        $listing_src = trim($_POST['listing_source'] ?? '');
        $urgency = strtoupper(substr(trim($_POST['owner_urgency'] ?? ''), 0, 1));
        if (!in_array($urgency, ['A', 'B', 'C'], true)) {
            $urgency = null;
        }
        $sales = trim($_POST['sales_status'] ?? 'Sale');
        $mkt = trim($_POST['marketing_status'] ?? '') ?: 'ลงการตลาดแล้ว';
        $transfer = trim($_POST['transfer_fee'] ?? '') ?: '50/50 Transfer Fee';
        $listing_val = $listing_src !== '' ? $listing_src : null;
        $cover_val = $cover_url !== '' ? $cover_url : null;

        $p_owner_name   = $enc($_POST['owner_name'] ?? '');
        $p_name_en      = $enc($name_en);
        $p_name_th      = $enc($_POST['name_th'] ?? '');
        $p_project      = $enc($name_en);
        $p_phone        = $enc(normalize_phone_string($_POST['phone'] ?? ''));
        $p_line         = $enc(normalize_line_id_string($_POST['line_id'] ?? ''));
        $p_type         = $enc($_POST['property_type'] ?? '');
        $p_zone         = $enc($_POST['zone'] ?? '');
        $p_soi          = $enc($_POST['soi'] ?? '');
        $p_unit         = $enc($_POST['unit_no'] ?? '');
        $p_floor        = $enc($_POST['floor'] ?? '');
        $p_direction    = $enc($_POST['direction'] ?? '');
        $strip_price = static fn ($v) => str_replace(',', '', trim((string)$v));
        $p_price        = $enc($strip_price($_POST['price'] ?? ''));
        $p_rent         = $enc($strip_price($_POST['rent'] ?? ''));
        $p_owner_price  = $enc($strip_price($_POST['owner_price'] ?? ''));
        $p_contact_sum  = $enc($_POST['contact_summary'] ?? '');
        $p_price_cons   = $enc($_POST['price_consult'] ?? '');
        $p_incomplete   = $enc($_POST['incomplete'] ?? '');
        $p_photos       = $enc($_POST['photos_link'] ?? '');
        $map_url_plain  = trim($_POST['map_url'] ?? '');
        $p_map          = $enc($map_url_plain);

        $stmt = $conn->prepare("UPDATE owners SET
            owner_name_enc=?, project_name_en_enc=?, project_name_th_enc=?, project_enc=?,
            phone_enc=?, line_id_enc=?, property_type_enc=?, zone_enc=?,
            soi_enc=?, unit_no_enc=?, floor_enc=?, direction_enc=?,
            asking_price_enc=?, rental_price_enc=?, owner_asking_price_enc=?,
            sales_status=?, owner_urgency=?, contact_summary_enc=?, price_consult_enc=?,
            last_contact_date=?, listing_source=?, marketing_status=?, incomplete_details_enc=?,
            cover_image_url=?, photos_link_enc=?, map_url_enc=?, selling_condition=?, has_deed=?
            WHERE id=? AND user_id=?");
        $stmt->bind_param("sssssssssssssssssssssssssssiii",
            $p_owner_name, $p_name_en, $p_name_th, $p_project,
            $p_phone, $p_line, $p_type, $p_zone,
            $p_soi, $p_unit, $p_floor, $p_direction,
            $p_price, $p_rent, $p_owner_price,
            $sales, $urgency, $p_contact_sum, $p_price_cons,
            $last_contact, $listing_val, $mkt, $p_incomplete,
            $cover_val, $p_photos, $p_map, $transfer, $has_deed_val,
            $oid, $user_id
        );
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
            $codeStmt = $conn->prepare('SELECT code_list FROM owners WHERE id = ? AND user_id = ? LIMIT 1');
            $codeStmt->bind_param('ii', $oid, $user_id);
            $codeStmt->execute();
            $codeRow = $codeStmt->get_result()->fetch_assoc();
            $codeStmt->close();
            owner_apply_map_coords(
                $conn,
                $user_id,
                $oid,
                (string)($codeRow['code_list'] ?? ''),
                $map_url_plain
            );
        }

        $payload = ['success' => $ok, 'message' => $ok ? 'บันทึกแล้ว' : 'บันทึกไม่สำเร็จ'];
        if ($ok) {
            $stmt = $conn->prepare("SELECT * FROM owners WHERE id = ? AND user_id = ? LIMIT 1");
            $stmt->bind_param("ii", $oid, $user_id);
            $stmt->execute();
            $o = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $clogs = [];
            $stmt = $conn->prepare("SELECT * FROM owner_contact_logs WHERE owner_id = ? ORDER BY contact_date DESC, id DESC");
            $stmt->bind_param("i", $oid);
            $stmt->execute();
            $clogs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $plogs = [];
            $stmt = $conn->prepare("SELECT * FROM owner_price_logs WHERE owner_id = ? ORDER BY log_date DESC, id DESC");
            $stmt->bind_param("i", $oid);
            $stmt->execute();
            $plogs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            if ($o) {
                $payload['owner'] = owner_display_row($o, $key, $clogs, $plogs);
            }
        }
        // สร้าง Task จากแผนติดตาม Owner (ถ้ามี)
        $next_follow = trim($_POST['next_follow_action'] ?? '');
        $next_follow_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['next_follow_date'] ?? '') ? $_POST['next_follow_date'] : null;
        if ($next_follow !== '' && $next_follow_date) {
            $ost = $conn->prepare("SELECT code_list, owner_name_enc FROM owners WHERE id = ? AND user_id = ? LIMIT 1");
            $ost->bind_param("ii", $oid, $user_id);
            $ost->execute();
            $orow = $ost->get_result()->fetch_assoc();
            $ost->close();
            if ($orow) {
                $oname = dec($orow['owner_name_enc'], $key) ?: $orow['code_list'];
                sync_owner_follow_task($conn, $user_id, $key, $orow['code_list'], $oname, $next_follow, $next_follow_date);
                $nf_enc = encrypt_data($next_follow, $key);
                $nf_stmt = $conn->prepare("UPDATE owners SET next_follow_action_enc=?, next_follow_date=? WHERE id=? AND user_id=?");
                $nf_stmt->bind_param("ssii", $nf_enc, $next_follow_date, $oid, $user_id);
                $nf_stmt->execute();
                $nf_stmt->close();
            }
        }

        echo json_encode($payload);
        exit();
    }

    if ($ajax === 'lead_save') {
        $lead_code = trim($_POST['lead_code'] ?? '');
        if ($lead_code === '') {
            echo json_encode(['success' => false, 'message' => 'ไม่พบรหัส Lead']);
            exit();
        }
        $stmt = $conn->prepare("SELECT * FROM leads WHERE user_id = ? AND lead_code = ? LIMIT 1");
        $stmt->bind_param("is", $user_id, $lead_code);
        $stmt->execute();
        $lead = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$lead) {
            echo json_encode(['success' => false, 'message' => 'ไม่พบ Lead']);
            exit();
        }

        $stage = trim($_POST['stage'] ?? '');
        $outcome = trim($_POST['outcome'] ?? '');
        $status = '';
        $used_matrix = false;

        if ($stage !== '' && $outcome !== '') {
            $valid_stages = lead_funnel_statuses(); // includes Win
            $valid_outcomes = ['yes', 'lose', 'reject', 'hold'];
            if (!in_array($stage, $valid_stages, true)) {
                echo json_encode(['success' => false, 'message' => 'ขั้นตอนไม่ถูกต้อง']);
                exit();
            }
            if (!in_array($outcome, $valid_outcomes, true)) {
                echo json_encode(['success' => false, 'message' => 'ผลลัพธ์ไม่ถูกต้อง']);
                exit();
            }
            if ($stage === 'Win' && $outcome !== 'yes') {
                echo json_encode(['success' => false, 'message' => 'Win ต้องเป็น outcome=Yes']);
                exit();
            }

            if ($stage === 'Win') {
                $status = 'Win';
            } elseif ($outcome === 'yes') {
                $status = $stage;
            } else {
                $status = lead_matrix_outcome_to_terminal_status($outcome);
            }
            $used_matrix = true;
        } else {
            // legacy mode (phase 1)
            $valid_statuses = array_merge(lead_funnel_statuses(), lead_terminal_statuses());
            $status = trim($_POST['status'] ?? $lead['status']);
            if (!in_array($status, $valid_statuses, true)) {
                echo json_encode(['success' => false, 'message' => 'สถานะไม่ถูกต้อง']);
                exit();
            }
        }
        $owner_code = trim($_POST['owner_code'] ?? ($lead['owner_code'] ?? ''));
        $next_plan = trim($_POST['next_plan_action'] ?? '');
        $next_plan_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['next_plan_date'] ?? '') ? $_POST['next_plan_date'] : null;
        $clear_next_plan = $used_matrix && in_array($outcome, ['lose', 'reject'], true);
        if ($clear_next_plan) {
            $next_plan = '';
            $next_plan_date = null;
        }
        $current_update = trim($_POST['current_update'] ?? '');
        $status_note = trim($_POST['status_note'] ?? '');
        $event_note = $current_update !== '' ? $current_update : $status_note;
        $reserve_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['reserve_date'] ?? '') ? $_POST['reserve_date'] : null;

        $next_enc = $next_plan !== '' ? encrypt_data($next_plan, $key) : null;
        $upd_enc = $event_note !== '' ? encrypt_data($event_note, $key) : null;
        $old_status = $lead['status'];
        $event_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['event_date'] ?? '') ? $_POST['event_date'] : date('Y-m-d');

        $stmt = $conn->prepare("UPDATE leads SET status=?, owner_code=?, current_update_enc=COALESCE(?, current_update_enc),
                next_plan_action_enc=COALESCE(?, next_plan_action_enc), next_plan_date=COALESCE(?, next_plan_date),
                reserve_date=COALESCE(?, reserve_date), updated_at=NOW() WHERE id=? AND user_id=?");
        $stmt->bind_param("ssssssii", $status, $owner_code, $upd_enc, $next_enc, $next_plan_date, $reserve_date, $lead['id'], $user_id);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok && $used_matrix && $stage === 'Win' && $outcome === 'yes') {
            $win_transfer = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['win_transfer_date'] ?? '')
                ? $_POST['win_transfer_date'] : $event_date;
            $win_price_raw = str_replace(',', '', trim($_POST['win_price'] ?? ''));
            $revenue_raw = str_replace(',', '', trim($_POST['revenue'] ?? ''));
            $win_payment = $_POST['win_payment_method'] ?? '';
            $win_payment = in_array($win_payment, ['cash', 'loan'], true) ? $win_payment : null;
            $win_close_scope = lead_normalize_win_close_scope($_POST['win_close_scope'] ?? 'this');

            if ($win_close_scope === 'other') {
                $close_project = trim($_POST['close_project'] ?? '');
                $close_owner_code = lead_normalize_owner_code($_POST['close_owner_code'] ?? '');
                $close_open_price_raw = str_replace(',', '', trim($_POST['close_open_price'] ?? ''));
                if ($close_project === '' || $close_owner_code === '') {
                    echo json_encode(['success' => false, 'message' => 'หลังอื่น — กรุณาระบุโครงการและรหัสทรัพย์']);
                    exit();
                }
                if ($close_open_price_raw === '' || $win_price_raw === '') {
                    echo json_encode(['success' => false, 'message' => 'หลังอื่น — กรุณาระบุราคาเปิดและราคาปิด']);
                    exit();
                }
                $close_project_enc = encrypt_data($close_project, $key);
                $close_open_price_enc = encrypt_data($close_open_price_raw, $key);
            } else {
                $close_project_enc = null;
                $close_owner_code = null;
                $close_open_price_enc = null;
            }

            $sets = ['win_date = ?', 'win_close_scope = ?', 'close_project_enc = ?', 'close_owner_code = ?', 'close_open_price_enc = ?'];
            $types = 'sssss';
            $params = [
                $win_transfer,
                $win_close_scope,
                $close_project_enc,
                $win_close_scope === 'other' ? $close_owner_code : null,
                $close_open_price_enc,
            ];
            if ($win_price_raw !== '') {
                $sets[] = 'win_price_enc = ?';
                $types .= 's';
                $params[] = encrypt_data($win_price_raw, $key);
            }
            if ($revenue_raw !== '') {
                $sets[] = 'revenue_enc = ?';
                $types .= 's';
                $params[] = encrypt_data($revenue_raw, $key);
            }
            if ($win_payment !== null) {
                $sets[] = 'win_payment_method = ?';
                $types .= 's';
                $params[] = $win_payment;
            }
            $types .= 'ii';
            $params[] = (int)$lead['id'];
            $params[] = $user_id;
            $wstmt = $conn->prepare('UPDATE leads SET ' . implode(', ', $sets) . ' WHERE id = ? AND user_id = ?');
            $wstmt->bind_param($types, ...$params);
            $wstmt->execute();
            $wstmt->close();
        }

        if ($ok) {
            $lead_name = dec($lead['lead_name_enc'], $key) ?: $lead_code;
            $lead_id = (int)$lead['id'];

            if ($clear_next_plan) {
                $clr = $conn->prepare('UPDATE leads SET next_plan_action_enc=NULL, next_plan_date=NULL WHERE id=? AND user_id=?');
                $clr->bind_param('ii', $lead_id, $user_id);
                $clr->execute();
                $clr->close();
            }

            // Phase 2: save Stage Outcome Matrix event
            if ($used_matrix) {
                $note_enc = $event_note !== '' ? encrypt_data($event_note, $key) : '';
                $se = $conn->prepare("INSERT INTO lead_stage_events (user_id, lead_id, stage, outcome, note_enc, event_date)
                    VALUES (?, ?, ?, ?, ?, ?)");
                $se->bind_param("iissss", $user_id, $lead_id, $stage, $outcome, $note_enc, $event_date);
                $se->execute();
                $se->close();
            }

            if ($status !== $old_status) {
                $revived = in_array($old_status, lead_terminal_statuses(), true)
                    && in_array($status, lead_funnel_statuses(), true);
                if ($event_note !== '') {
                    $note_text = ($revived ? 'ดึงกลับมาติดตาม · ' : '')
                        . "เปลี่ยนจาก {$old_status} เป็น {$status}: {$event_note}";
                } else {
                    $note_text = ($revived ? 'ดึงกลับมาติดตาม · ' : '')
                        . "เปลี่ยนจาก {$old_status} เป็น {$status}";
                }
                log_lead_status($conn, $user_id, $lead_id, $status, encrypt_data($note_text, $key));
            } elseif ($event_note !== '') {
                log_lead_status($conn, $user_id, $lead_id, $status, encrypt_data($event_note, $key));
            }
            if ($next_plan !== '' && $next_plan_date) {
                sync_lead_plan_task($conn, $user_id, $key, $lead_code, $lead_name, $next_plan, $next_plan_date, $owner_code);
            }
            handle_lead_status_side_effects(
                $conn, $user_id, $key, $lead_code, $lead_name, $status, $owner_code, $reserve_date, $old_status, $event_note,
                $used_matrix ? $stage : null,
                $used_matrix ? $outcome : null
            );

            $lead_payload = dashboard_lead_payload($conn, $user_id, $key, $lead_id);
            echo json_encode(['success' => true, 'message' => 'บันทึกสำเร็จ', 'lead' => $lead_payload]);
        } else {
            echo json_encode(['success' => false, 'message' => 'บันทึกไม่สำเร็จ']);
        }
        exit();
    }

    if ($ajax === 'lead_delete_history') {
        $lead_id = (int)($_POST['lead_id'] ?? 0);
        $kind = trim($_POST['kind'] ?? '');
        $item_id = (int)($_POST['item_id'] ?? 0);
        if ($lead_id <= 0 || $item_id <= 0 || !in_array($kind, ['stage_event', 'status_log'], true)) {
            echo json_encode(['success' => false, 'message' => 'คำขอไม่ถูกต้อง']);
            exit();
        }

        $stmt = $conn->prepare('SELECT * FROM leads WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->bind_param('ii', $lead_id, $user_id);
        $stmt->execute();
        $lead_row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$lead_row) {
            echo json_encode(['success' => false, 'message' => 'ไม่พบ Lead']);
            exit();
        }

        if ($kind === 'stage_event') {
            $del = $conn->prepare('DELETE FROM lead_stage_events WHERE id = ? AND user_id = ? AND lead_id = ?');
            $del->bind_param('iii', $item_id, $user_id, $lead_id);
            $del->execute();
            $deleted = $del->affected_rows > 0;
            $del->close();
            if (!$deleted) {
                echo json_encode(['success' => false, 'message' => 'ลบรายการไม่สำเร็จ']);
                exit();
            }

            $se2 = $conn->prepare('SELECT * FROM lead_stage_events WHERE user_id = ? AND lead_id = ? ORDER BY event_date ASC, id ASC');
            $se2->bind_param('ii', $user_id, $lead_id);
            $se2->execute();
            $stage_events = $se2->get_result()->fetch_all(MYSQLI_ASSOC);
            $se2->close();

            $resolved = lead_resolve_from_stage_events($lead_row, $stage_events);
            $new_status = $resolved['status'] ?? ($lead_row['status'] ?? 'Call');
            $up = $conn->prepare('UPDATE leads SET status = ?, updated_at = NOW() WHERE id = ? AND user_id = ?');
            $up->bind_param('sii', $new_status, $lead_id, $user_id);
            $up->execute();
            $up->close();

            lead_sync_current_update_from_stage_events($conn, $user_id, $key, $lead_id, $stage_events);
        } else {
            $del = $conn->prepare('DELETE FROM lead_status_logs WHERE id = ? AND user_id = ? AND lead_id = ?');
            $del->bind_param('iii', $item_id, $user_id, $lead_id);
            $del->execute();
            $deleted = $del->affected_rows > 0;
            $del->close();
            if (!$deleted) {
                echo json_encode(['success' => false, 'message' => 'ลบรายการไม่สำเร็จ']);
                exit();
            }
        }

        $lead_payload = dashboard_lead_payload($conn, $user_id, $key, $lead_id);
        echo json_encode(['success' => true, 'message' => 'ลบรายการแล้ว', 'lead' => $lead_payload]);
        exit();
    }

    if ($ajax === 'lead_save_case') {
        $lead_code = trim($_POST['lead_code'] ?? '');
        if ($lead_code === '') {
            echo json_encode(['success' => false, 'message' => 'ไม่พบรหัส Lead']);
            exit();
        }
        $stmt = $conn->prepare('SELECT id FROM leads WHERE user_id = ? AND lead_code = ? LIMIT 1');
        $stmt->bind_param('is', $user_id, $lead_code);
        $stmt->execute();
        $lead = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$lead) {
            echo json_encode(['success' => false, 'message' => 'ไม่พบ Lead']);
            exit();
        }
        $lead_id = (int)$lead['id'];

        $potential = lead_normalize_potential($_POST['potential'] ?? '');
        $background = trim($_POST['background'] ?? '');
        $pain_point = trim($_POST['pain_point'] ?? '');
        $requirement = trim($_POST['requirement'] ?? '');
        $financials = trim($_POST['financials'] ?? '');
        $timeline = trim($_POST['timeline'] ?? '');
        $budget = str_replace(',', '', trim($_POST['budget'] ?? ''));
        $aux_tag = lead_normalize_aux_tag($_POST['lead_aux_tag'] ?? '');
        $is_agent = $aux_tag === 'agent' ? 1 : 0;
        $aux_tag_save = $aux_tag !== '' ? $aux_tag : null;
        $owner_code = lead_normalize_owner_code($_POST['owner_code'] ?? '');
        $units_sent = lead_normalize_units_sent($_POST['units_sent'] ?? '');
        $offered_listings = lead_normalize_offered_listings($_POST['offered_listings'] ?? '');
        $offered_listings_enc = $offered_listings !== '' ? encrypt_data($offered_listings, $key) : null;

        $agent_name = lead_normalize_agent_client_name($_POST['agent_client_name'] ?? '');
        $agent_phone4 = lead_normalize_agent_phone_last4($_POST['agent_client_phone_last4'] ?? '');
        if ($is_agent) {
            if (!lead_agent_client_name_valid($_POST['agent_client_name'] ?? '')) {
                echo json_encode(['success' => false, 'message' => 'เคส Agent กรุณาระบุชื่อลูกค้า (หรือ - ถ้ายังไม่ทราบ)']);
                exit();
            }
            if (!lead_agent_phone_last4_valid($_POST['agent_client_phone_last4'] ?? '')) {
                echo json_encode(['success' => false, 'message' => 'เคส Agent กรุณาระบุเบอร์ 4 ตัวท้าย (หรือ - ถ้ายังไม่ทราบ)']);
                exit();
            }
        } else {
            $agent_name = '';
            $agent_phone4 = '';
        }
        $agent_name_enc = $agent_name !== '' ? encrypt_data($agent_name, $key) : null;
        $agent_phone4_enc = $agent_phone4 !== '' ? encrypt_data($agent_phone4, $key) : null;

        $bg_enc = $background !== '' ? encrypt_data($background, $key) : null;
        $pain_enc = $pain_point !== '' ? encrypt_data($pain_point, $key) : null;
        $req_enc = $requirement !== '' ? encrypt_data($requirement, $key) : null;
        $fin_enc = $financials !== '' ? encrypt_data($financials, $key) : null;
        $tl_enc = $timeline !== '' ? encrypt_data($timeline, $key) : null;
        $budget_enc = $budget !== '' ? encrypt_data($budget, $key) : null;

        $stmt = $conn->prepare('UPDATE leads SET potential=?, background_enc=?, pain_point_enc=?, pain_point_found=?,
            requirement_enc=?, financials_enc=?, target_date_enc=?, budget_enc=?, lead_aux_tag=?, is_agent=?,
            agent_client_name_enc=?, agent_client_phone_last4_enc=?, owner_code=?, units_sent=?, offered_listings_enc=?,
            updated_at=NOW()
            WHERE id=? AND user_id=?');
        $units_sent_bind = $units_sent !== null ? (string)(int)$units_sent : null;
        $stmt->bind_param(
            'sssssssssississii',
            $potential,
            $bg_enc,
            $pain_enc,
            $pain_point,
            $req_enc,
            $fin_enc,
            $tl_enc,
            $budget_enc,
            $aux_tag_save,
            $is_agent,
            $agent_name_enc,
            $agent_phone4_enc,
            $owner_code,
            $units_sent_bind,
            $offered_listings_enc,
            $lead_id,
            $user_id
        );
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
            $lead_payload = dashboard_lead_payload($conn, $user_id, $key, $lead_id);
            echo json_encode(['success' => true, 'message' => 'บันทึกข้อมูลเคสแล้ว', 'lead' => $lead_payload]);
        } else {
            echo json_encode(['success' => false, 'message' => 'บันทึกไม่สำเร็จ']);
        }
        exit();
    }

    if ($ajax === 'ms_save_stats') {
        if (!branch_is_metal_sheet($user)) {
            echo json_encode(['success' => false, 'message' => 'ไม่รองรับสาขานี้']);
            exit();
        }
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['stat_date'] ?? '') ? $_POST['stat_date'] : date('Y-m-d');
        $ok = ms_save_daily_stats($conn, $user_id, $date, $_POST);
        echo json_encode(['success' => $ok]);
        exit();
    }

    if ($ajax === 'ms_add_entry') {
        if (!branch_is_metal_sheet($user)) {
            echo json_encode(['success' => false, 'message' => 'ไม่รองรับสาขานี้']);
            exit();
        }
        $id = ms_add_entry($conn, $user_id, $key, $_POST);
        echo json_encode(['success' => $id > 0, 'id' => $id]);
        exit();
    }

    if ($ajax === 'ms_save_sales_name') {
        if (!branch_is_metal_sheet($user)) {
            echo json_encode(['success' => false, 'message' => 'ไม่รองรับสาขานี้']);
            exit();
        }
        $name = trim($_POST['sales_display_name'] ?? '');
        if ($name === '') {
            echo json_encode(['success' => false, 'message' => 'กรุณากรอกชื่อเซลล์']);
            exit();
        }
        $stmt = $conn->prepare("UPDATE users SET sales_display_name = ? WHERE id = ?");
        $stmt->bind_param("si", $name, $user_id);
        $ok = $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => $ok, 'sales_display_name' => $name]);
        exit();
    }

    if ($ajax === 'ms_report_preview') {
        if (!branch_is_metal_sheet($user)) {
            echo json_encode(['success' => false, 'message' => 'ไม่รองรับสาขานี้']);
            exit();
        }
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['stat_date'] ?? '') ? $_POST['stat_date'] : date('Y-m-d');
        $sales_name = trim($_POST['sales_display_name'] ?? '') ?: branch_sales_display_name($user);
        $stats = ms_get_daily_stats($conn, $user_id, $date);
        foreach (['deposit_count','survey_count','collection_count','chat_line_count','chat_facebook_count','chat_tel_count'] as $f) {
            if (isset($_POST[$f]) && $_POST[$f] !== '') {
                $stats[$f] = max(0, (int)$_POST[$f]);
            }
        }
        $entries = ms_entries_for_date($conn, $user_id, $key, $date);
        $ym = explode('-', $date);
        $month_sum = ms_month_summary($conn, $user_id, (int)$ym[0], (int)$ym[1]);
        $text = ms_build_report_text($sales_name, $date, $stats, $entries, $month_sum);
        echo json_encode(['success' => true, 'text' => $text, 'month' => $month_sum]);
        exit();
    }

    if ($ajax === 'map_data') {
        require_once __DIR__ . '/lib/map_bbox.php';
        $bbox = [
            'north' => (float)($_POST['north'] ?? 0),
            'south' => (float)($_POST['south'] ?? 0),
            'east'  => (float)($_POST['east'] ?? 0),
            'west'  => (float)($_POST['west'] ?? 0),
        ];
        if (!map_bbox_normalize($bbox)) {
            echo json_encode(['success' => false, 'message' => 'ขอบเขตแผนที่ไม่ถูกต้อง']);
            exit();
        }
        $layer = trim((string)($_POST['layer'] ?? 'owner'));
        $out = ['success' => true, 'layer' => $layer];

        if ($layer === 'owner') {
            $rows = map_bbox_fetch_owners($conn, $user_id, $bbox);
            $payload = map_build_payload($rows, [], $key);
            $out['owner_groups'] = $payload['owner_groups'];
        } elseif ($layer === 'lead') {
            $win = map_bbox_lead_window_from_request($_POST);
            $rows = map_bbox_fetch_leads($conn, $user_id, $bbox, $win);
            $codes = array_column($rows, 'owner_code');
            $ownersForLeads = map_bbox_fetch_owners_by_codes($conn, $user_id, $codes);
            $payload = map_build_payload($ownersForLeads, $rows, $key);
            $out['lead_groups'] = $payload['lead_groups'];
        } elseif ($layer === 'project') {
            $allProjects = map_load_projects($conn, $user_id);
            $out['projects'] = map_bbox_filter_projects($allProjects, $bbox);
        } else {
            echo json_encode(['success' => false, 'message' => 'ชั้นข้อมูลไม่รองรับ']);
            exit();
        }

        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        exit();
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit();
}

// ===== ตัวช่วยถอดรหัส / จัดรูปแบบ =====
function dec($value, $key) {
    if ($value === null || $value === '') return '';
    return (string)decrypt_data($value, $key);
}

/** พิกัดโซนสำรอง (เมื่อยังไม่มี lat/lng) — เรียงจับคำยาวก่อนใน map_resolve_coords */
function map_zone_coords() {
    return [
        'Noble Around'       => [13.7238, 100.5858],
        'The Line'           => [13.9107, 100.5309],
        'Ideo Q'             => [13.7234, 100.5854],
        'Life Asoke'         => [13.7373, 100.5608],
        'Ashton'             => [13.7308, 100.5645],
        'Rhythm'             => [13.7196, 100.5853],
        'บ้านกลางเมือง'      => [13.8540, 100.6420],
        'บุราสิริ'           => [13.8820, 100.6780],
        'วัชรพล'             => [13.8820, 100.6780],
        'รามคำแหง'           => [13.7540, 100.6440],
        'รามอินทรา'          => [13.8260, 100.6550],
        'เอกมัย-รามอินทรา'   => [13.8540, 100.6420],
        'แจ้งวัฒนะ'          => [13.9080, 100.5210],
        'งามวงศ์วาน'         => [13.8740, 100.5140],
        'นอร์ธปาร์ค'         => [13.9080, 100.5210],
        'ดอนเมือง'           => [13.9130, 100.6030],
        'สายไหม'             => [13.9100, 100.6850],
        'มีนบุรี'            => [13.8130, 100.7280],
        'บางนา'              => [13.6680, 100.6040],
        'อ่อนนุช'            => [13.6820, 100.6480],
        'ห้วยขวาง'           => [13.7780, 100.5790],
        'บางกะปิ'            => [13.7650, 100.6470],
        'ลาดพร้าว'           => [13.8160, 100.6030],
        'พหลโยธิน'           => [13.8940, 100.6520],
        'จตุจักร'            => [13.8300, 100.5700],
        'บางซื่อ'            => [13.8130, 100.5470],
        'สาทร'               => [13.7190, 100.5290],
        'สีลม'               => [13.7260, 100.5340],
        'ราชเทวี'            => [13.7520, 100.5370],
        'พระโขนง'            => [13.7234, 100.5854],
        'อโศก'               => [13.7373, 100.5608],
        'สุขุมวิท'           => [13.7367, 100.5681],
        'พร้อมพงษ์'          => [13.7308, 100.5645],
        'ปุณณวิถี'           => [13.7232, 100.5614],
        'เอกมัย'             => [13.7196, 100.5853],
        'ปทุมธานี'           => [14.0208, 100.5250],
        'นนทบุรี'            => [13.8621, 100.5145],
        'ธนบุรี'             => [13.7220, 100.4860],
        'ฝั่งธน'             => [13.7220, 100.4860],
    ];
}

/** ข้อความช่วยจับโซนจาก Owner */
function map_location_hints_from_owner(array $o, string $key): array
{
    $hints = [];
    foreach ([
        map_project_label($o, $key),
        dec($o['project_name_th_enc'] ?? '', $key),
        dec($o['project_name_en_enc'] ?? '', $key),
        dec($o['project_enc'] ?? '', $key),
        dec($o['zone_enc'] ?? '', $key),
        dec($o['soi_enc'] ?? '', $key),
    ] as $h) {
        $h = trim((string)$h);
        if ($h !== '' && $h !== 'ไม่ระบุโครงการ' && !in_array($h, $hints, true)) {
            $hints[] = $h;
        }
    }
    return $hints;
}

function map_resolve_coords($lat, $lng, $zone_text) {
    if (map_coords_valid($lat, $lng)) {
        return [(float)$lat, (float)$lng];
    }
    $blob = trim((string)$zone_text);
    if ($blob === '' || $blob === 'ไม่ระบุโครงการ') {
        return [13.7563, 100.5018];
    }
    $zones = map_zone_coords();
    uksort($zones, static function ($a, $b) {
        return mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8');
    });
    foreach ($zones as $k => $c) {
        if (mb_strpos($blob, $k, 0, 'UTF-8') !== false) {
            return $c;
        }
    }
    return [13.7563, 100.5018];
}

/** พิกัด Owner: ลิงก์แผนที่ → DB → จับโซนจากชื่อโครงการ/โซน */
function map_resolve_owner_coords(array $o, string $key): array
{
    $mapUrl = dec($o['map_url_enc'] ?? '', $key);
    if ($mapUrl !== '') {
        $parsed = map_parse_coords_from_url($mapUrl);
        if ($parsed) {
            return $parsed;
        }
    }
    $lat = $o['lat'] ?? null;
    $lng = $o['lng'] ?? null;
    if (map_coords_valid($lat, $lng)) {
        return [(float)$lat, (float)$lng];
    }
    $hints = map_location_hints_from_owner($o, $key);
    return map_resolve_coords(null, null, implode(' ', $hints));
}

function map_project_label($o, $key) {
    $th = dec($o['project_name_th_enc'] ?? '', $key);
    $en = dec($o['project_name_en_enc'] ?? '', $key);
    $legacy = dec($o['project_enc'] ?? '', $key);
    return $th ?: ($en ?: $legacy) ?: 'ไม่ระบุโครงการ';
}

/** คีย์รวมหมุดจาก lat/lng (4 ทศนิยม ≈ 11 ม.) */
function map_coord_group_key($lat, $lng) {
    return round((float)$lat, 4) . '|' . round((float)$lng, 4);
}

/** รวมกลุ่มโครงการเดียวกัน → หมุดเดียว (เฉลี่ยพิกัด) */
function map_merge_groups_by_project(array $groups) {
    $by = [];
    $loose = [];
    foreach ($groups as $g) {
        $pk = mb_strtolower(trim((string)($g['project'] ?? '')));
        if ($pk === '' || $pk === 'ไม่ระบุโครงการ') {
            $loose[] = $g;
            continue;
        }
        if (!isset($by[$pk])) {
            $g['id'] = 'mp_' . substr(md5($pk), 0, 10);
            $by[$pk] = $g;
            continue;
        }
        $prevCount = count($by[$pk]['items'] ?? []);
        $by[$pk]['items'] = array_merge($by[$pk]['items'], $g['items'] ?? []);
        $newCount = count($g['items'] ?? []);
        if ($newCount >= $prevCount) {
            $by[$pk]['lat'] = $g['lat'];
            $by[$pk]['lng'] = $g['lng'];
        }
    }
    $out = $loose;
    foreach ($by as $g) {
        $out[] = $g;
    }
    return $out;
}

/** ชุด Segment โครงการ (Survey) */
function map_project_segment_options() {
    return [
        'Economy class',
        'Main class',
        'Upper class',
        'High class',
        'Luxury class',
        'Super Luxury class',
    ];
}

/** แมป segment เก่า/ย่อ → ชุดมาตรฐาน */
function map_normalize_segment($segment) {
    $s = strtolower(trim((string)$segment));
    if ($s === '') return '';
    $legacy = [
        'mid'            => 'Economy class',
        'economy'        => 'Economy class',
        'economy class'  => 'Economy class',
        'main'           => 'Main class',
        'main class'     => 'Main class',
        'premium'        => 'Main class',
        'upper'          => 'Upper class',
        'upper class'    => 'Upper class',
        'high'           => 'High class',
        'highclass'      => 'High class',
        'high class'     => 'High class',
        'luxury'         => 'Luxury class',
        'luxury class'   => 'Luxury class',
        'super luxury'   => 'Super Luxury class',
        'super luxury class' => 'Super Luxury class',
    ];
    if (isset($legacy[$s])) return $legacy[$s];
    foreach (map_project_segment_options() as $opt) {
        if (strtolower($opt) === $s) return $opt;
    }
    return trim((string)$segment);
}

/** ค่าสูงสุดห้องนอน/น้ำจาก units_json */
function map_project_unit_max($units) {
    $max_bed = 0;
    $max_bath = 0;
    foreach ($units as $u) {
        if (!is_array($u)) continue;
        $max_bed = max($max_bed, (int)($u['bed'] ?? 0));
        $max_bath = max($max_bath, (int)($u['bath'] ?? 0));
    }
    return ['max_bed' => $max_bed, 'max_bath' => $max_bath];
}

function map_build_payload($owners, $leads, $key) {
    $owner_by_code = [];
    foreach ($owners as $o) $owner_by_code[$o['code_list']] = $o;

    $owner_groups = [];
    foreach ($owners as $o) {
        if (($o['availability_status'] ?? '') === 'ยกเลิกการขาย') continue;
        $project = map_project_label($o, $key);
        $zone = dec($o['zone_enc'] ?? '', $key);
        [$lat, $lng] = map_resolve_owner_coords($o, $key);
        $gk = map_coord_group_key($lat, $lng);
        if (!isset($owner_groups[$gk])) {
            $owner_groups[$gk] = [
                'id'      => 'og_' . substr(md5($gk), 0, 8),
                'project' => $project,
                'lat'     => $lat,
                'lng'     => $lng,
                'items'   => [],
            ];
        }
        $owner_groups[$gk]['items'][] = [
            'code'          => $o['code_list'],
            'name'          => dec($o['owner_name_enc'], $key) ?: $o['code_list'],
            'unit'          => dec($o['unit_no_enc'] ?? '', $key) ?: '-',
            'price'         => dec($o['asking_price_enc'] ?? '', $key) ?: '-',
            'grade'         => strtoupper(trim($o['owner_urgency'] ?? '')),
            'property_type' => dec($o['property_type_enc'] ?? '', $key),
            'zone'          => $zone,
        ];
    }

    $lead_groups = [];
    foreach ($leads as $l) {
        if (in_array($l['status'] ?? '', ['Rejected', 'Hold_Reject', 'Lose'], true)) continue;
        $project = trim(dec($l['project_enc'] ?? '', $key));
        $unit = dec($l['visited_unit_enc'] ?? '', $key);
        $oc = $l['owner_code'] ?? '';
        $lat = $l['lat'] ?? null;
        $lng = $l['lng'] ?? null;
        $hints = $project !== '' ? [$project] : [];
        if ($oc !== '' && isset($owner_by_code[$oc])) {
            $oo = $owner_by_code[$oc];
            if ($unit === '') $unit = dec($oo['unit_no_enc'] ?? '', $key);
            if ($project === '') {
                $project = map_project_label($oo, $key);
            }
            $hints = array_merge($hints, map_location_hints_from_owner($oo, $key));
            if (!map_coords_valid($lat, $lng)) {
                [$oLat, $oLng] = map_resolve_owner_coords($oo, $key);
                $lat = $oLat;
                $lng = $oLng;
            }
        }
        if ($project === '') {
            $project = 'ไม่ระบุโครงการ';
        }
        $hints = array_values(array_unique(array_filter($hints)));
        [$lat, $lng] = map_resolve_coords($lat, $lng, implode(' ', $hints));
        $gk = map_coord_group_key($lat, $lng);
        if (!isset($lead_groups[$gk])) {
            $lead_groups[$gk] = [
                'id'      => 'lg_' . substr(md5($gk), 0, 8),
                'project' => $project,
                'lat'     => $lat,
                'lng'     => $lng,
                'items'   => [],
            ];
        }
        $lead_groups[$gk]['items'][] = [
            'id'        => 'ld_' . (int)$l['id'],
            'lead_code' => lead_code_for_display($l['lead_code']),
            'name'      => dec($l['lead_name_enc'], $key) ?: $l['lead_code'],
            'unit'      => $unit ?: '-',
            'status'    => $l['status'] ?? 'Call',
            'contact_date'  => ($l['contact_date'] ?? '') ?: substr((string)($l['created_at'] ?? ''), 0, 10) ?: null,
            'contact_label' => thai_short_date(($l['contact_date'] ?? '') ?: substr((string)($l['created_at'] ?? ''), 0, 10)),
        ];
    }

    $lead_groups = array_values($lead_groups);
    $lead_total = 0;
    foreach ($lead_groups as $g) $lead_total += count($g['items']);

    $owner_groups = map_merge_groups_by_project(array_values($owner_groups));
    $lead_groups = map_merge_groups_by_project($lead_groups);

    return [
        'owner_groups' => $owner_groups,
        'lead_groups'  => $lead_groups,
        'lead_total'   => $lead_total,
        'projects'     => [],
        'center'       => map_payload_center($owner_groups, $lead_groups),
    ];
}

function map_payload_center($owner_groups, $lead_groups) {
    $lats = [];
    $lngs = [];
    foreach ($owner_groups as $g) {
        $lats[] = $g['lat'];
        $lngs[] = $g['lng'];
    }
    foreach ($lead_groups as $g) {
        $lats[] = $g['lat'];
        $lngs[] = $g['lng'];
    }
    if (!$lats) return ['lat' => 13.7563, 'lng' => 100.5018];
    return [
        'lat' => array_sum($lats) / count($lats),
        'lng' => array_sum($lngs) / count($lngs),
    ];
}

function map_load_projects($conn, $user_id) {
    $stmt = $conn->prepare("SELECT * FROM project_surveys WHERE user_id = ? ORDER BY name_th ASC, name_en ASC");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $out = [];
    foreach ($rows as $p) {
        $name = ($p['name_th'] ?? '') ?: ($p['name_en'] ?? 'โครงการ');
        $lat = $p['lat'] ?? null;
        $lng = $p['lng'] ?? null;
        if ($lat === null || $lng === null || (float)$lat == 0) continue;
        $amenities = json_decode($p['amenities_json'] ?? '[]', true) ?: [];
        $nearby = json_decode($p['nearby_json'] ?? '[]', true) ?: [];
        $units = json_decode($p['units_json'] ?? '[]', true) ?: [];
        $unit_max = map_project_unit_max($units);
        $out[] = [
            'id'            => (int)$p['id'],
            'slug'          => $p['project_slug'],
            'name'          => $name,
            'name_en'       => $p['name_en'] ?? '',
            'developer'     => $p['developer'] ?? '',
            'segment'       => map_normalize_segment($p['segment'] ?? ''),
            'property_type' => $p['property_type'] ?? '',
            'total_units'   => (int)($p['total_units'] ?? 0),
            'phases'        => (int)($p['phases'] ?? 0),
            'common_fee'    => $p['common_fee'] ?? null,
            'fee_period'    => $p['fee_period'] ?? 'yearly',
            'launch_year'   => $p['launch_year'] ?? null,
            'built_year'    => $p['built_year'] ?? null,
            'cover'         => $p['cover_image_url'] ?? '',
            'lat'           => (float)$lat,
            'lng'           => (float)$lng,
            'amenities'     => $amenities,
            'nearby'        => $nearby,
            'units'         => $units,
            'max_bed'       => $unit_max['max_bed'],
            'max_bath'      => $unit_max['max_bath'],
        ];
    }
    return $out;
}

/** แปลงวันที่เป็นข้อความสั้นแบบไทย เช่น 11 มิ.ย. */
function thai_short_date($date_str) {
    if (empty($date_str) || $date_str === '0000-00-00') return '-';
    $months = [1=>'ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    $ts = strtotime($date_str);
    if ($ts === false) return '-';
    return date('j', $ts) . ' ' . $months[(int)date('n', $ts)];
}

/** ข้อความ relative ของวันครบกำหนด เช่น วันนี้ / พรุ่งนี้ / อีก 3 วัน / เลย 2 วัน */
function due_label($date_str) {
    if (empty($date_str)) return ['', 'gray'];
    $today = strtotime(date('Y-m-d'));
    $due   = strtotime($date_str);
    $diff  = (int)round(($due - $today) / 86400);
    if ($diff < 0)  return ['เลยมา ' . abs($diff) . ' วัน', 'red'];
    if ($diff === 0) return ['วันนี้', 'lime'];
    if ($diff === 1) return ['พรุ่งนี้', 'lime'];
    return ['อีก ' . $diff . ' วัน', 'gray'];
}

// ===== ดึงข้อมูลสรุปสำหรับหน้า Home =====
$counts = ['leads' => 0, 'owners' => 0, 'tasks_pending' => 0, 'wins' => 0];

$stmt = $conn->prepare("SELECT COUNT(*) c FROM leads WHERE user_id = ?");
$stmt->bind_param("i", $user_id); $stmt->execute();
$counts['leads'] = (int)$stmt->get_result()->fetch_assoc()['c']; $stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) c FROM owners WHERE user_id = ?");
$stmt->bind_param("i", $user_id); $stmt->execute();
$counts['owners'] = (int)$stmt->get_result()->fetch_assoc()['c']; $stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) c FROM tasks WHERE user_id = ? AND is_completed = 0");
$stmt->bind_param("i", $user_id); $stmt->execute();
$counts['tasks_pending'] = (int)$stmt->get_result()->fetch_assoc()['c']; $stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) c FROM leads WHERE user_id = ? AND status = 'Win'");
$stmt->bind_param("i", $user_id); $stmt->execute();
$counts['wins'] = (int)$stmt->get_result()->fetch_assoc()['c']; $stmt->close();

// กราฟ Pipeline + chip Lead
$lead_filter_month = preg_match('/^\d{4}-\d{2}$/', $_GET['lead_month'] ?? '') ? $_GET['lead_month'] : '';
$lead_month_all = !isset($_GET['lead_month']) || $_GET['lead_month'] === 'all' || $lead_filter_month === '';
if ($lead_month_all) $lead_filter_month = '';

$status_counts = lead_funnel_status_counts($conn, $user_id);
$chip_counts = lead_chip_counts($conn, $user_id, $lead_filter_month !== '' ? $lead_filter_month : null);

// 3 Tasks ที่ใกล้ถึงกำหนดที่สุด (งานเลยกำหนดจะลอยขึ้นก่อนเอง) — smart sort เหมือนหน้า Tasks
$stmt = $conn->prepare("SELECT * FROM tasks WHERE user_id = ? AND is_completed = 0
    ORDER BY due_date IS NULL, due_date ASC,
             due_time IS NULL, due_time ASC,
             priority = 0, priority ASC,
             created_at ASC
    LIMIT 3");
$stmt->bind_param("i", $user_id); $stmt->execute();
$home_tasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

// 3 Leads ที่ไม่ได้อัปเดตนานที่สุด (ยังไม่จบดีล)
$stmt = $conn->prepare("SELECT * FROM leads WHERE user_id = ? AND status NOT IN ('Win','Rejected','Hold_Reject','Lose') ORDER BY updated_at ASC LIMIT 3");
$stmt->bind_param("i", $user_id); $stmt->execute();
$stale_leads = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

// ===== ข้อมูลหน้า Product (Owners) =====
$stmt = $conn->prepare("SELECT * FROM owners WHERE user_id = ? ORDER BY updated_at DESC LIMIT 100");
$stmt->bind_param("i", $user_id); $stmt->execute();
$owners = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

$owner_contact_logs_map = [];
$owner_price_logs_map = [];
$stmt = $conn->prepare("SELECT l.* FROM owner_contact_logs l
    INNER JOIN owners o ON o.id = l.owner_id
    WHERE o.user_id = ? ORDER BY l.contact_date DESC, l.id DESC");
$stmt->bind_param("i", $user_id); $stmt->execute();
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    $oid = (int)$row['owner_id'];
    if (!isset($owner_contact_logs_map[$oid])) $owner_contact_logs_map[$oid] = [];
    $owner_contact_logs_map[$oid][] = $row;
}
$stmt->close();
$stmt = $conn->prepare("SELECT l.* FROM owner_price_logs l
    INNER JOIN owners o ON o.id = l.owner_id
    WHERE o.user_id = ? ORDER BY l.log_date DESC, l.id DESC");
$stmt->bind_param("i", $user_id); $stmt->execute();
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    $oid = (int)$row['owner_id'];
    if (!isset($owner_price_logs_map[$oid])) $owner_price_logs_map[$oid] = [];
    $owner_price_logs_map[$oid][] = $row;
}
$stmt->close();

$owner_details_map = [];
foreach ($owners as $o) {
    $oid = (int)$o['id'];
    $owner_details_map[$oid] = owner_display_row(
        $o, $key,
        $owner_contact_logs_map[$oid] ?? [],
        $owner_price_logs_map[$oid] ?? []
    );
}

// ===== ข้อมูลหน้า Lead =====
$stmt = $conn->prepare("SELECT * FROM leads WHERE user_id = ? ORDER BY contact_date DESC, updated_at DESC");
$stmt->bind_param("i", $user_id); $stmt->execute();
$leads = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

$owner_price_by_code = [];
foreach ($owners as $o) {
    $owner_price_by_code[$o['code_list']] = fmt_price_full(dec($o['asking_price_enc'], $key));
}

$owner_lookup = dashboard_owner_lookup($conn, $user_id, $key);

$leads_by_owner_code = [];

$lead_status_logs_map = [];
$stmt = $conn->prepare("SELECT l.* FROM lead_status_logs l
    INNER JOIN leads ld ON ld.id = l.lead_id
    WHERE ld.user_id = ? ORDER BY l.log_date DESC, l.id DESC");
$stmt->bind_param("i", $user_id); $stmt->execute();
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    $lid = (int)$row['lead_id'];
    if (!isset($lead_status_logs_map[$lid])) $lead_status_logs_map[$lid] = [];
    $lead_status_logs_map[$lid][] = $row;
}
$stmt->close();

$lead_stage_events_map = [];
$stmt = $conn->prepare("SELECT * FROM lead_stage_events WHERE user_id = ? ORDER BY lead_id ASC, event_date ASC, id ASC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    $lid = (int)$row['lead_id'];
    if (!isset($lead_stage_events_map[$lid])) $lead_stage_events_map[$lid] = [];
    $lead_stage_events_map[$lid][] = $row;
}
$stmt->close();

$lead_group_sizes = lead_customer_group_sizes($conn, $user_id);
$lead_details_map = [];
foreach ($leads as $l) {
    $lid = (int)$l['id'];
    $lead_details_map[$lid] = lead_display_row(
        $l, $key,
        $lead_status_logs_map[$lid] ?? [],
        $owner_price_by_code,
        $lead_stage_events_map[$lid] ?? [],
        $lead_group_sizes,
        $owner_lookup
    );
    $oc = $l['owner_code'] ?? '';
    if ($oc !== '') {
        if (!isset($leads_by_owner_code[$oc])) {
            $leads_by_owner_code[$oc] = [];
        }
        $st = $lead_details_map[$lid]['status'] ?? ($l['status'] ?? 'Call');
        $leads_by_owner_code[$oc][] = [
            'id'          => $lid,
            'code'        => $l['lead_code'],
            'name'        => dec($l['lead_name_enc'], $key) ?: $l['lead_code'],
            'status'      => $st,
            'status_meta' => lead_status_step_meta($st),
        ];
    }
}

$map_payload = [
    'owner_groups' => [],
    'lead_groups'  => [],
    'lead_total'   => 0,
    'owner_total'  => 0,
    'projects'     => [],
    'center'       => ['lat' => 13.7563, 'lng' => 100.5018],
];
require_once __DIR__ . '/lib/map_bbox.php';
$map_meta = map_meta_counts($conn, $user_id);
$map_payload['lead_total'] = $map_meta['lead_total'];
$map_payload['owner_total'] = $map_meta['owner_total'];
$map_payload['projects'] = map_load_projects($conn, $user_id);
$map_payload['center'] = map_meta_center($conn, $user_id);

// ===== ข้อมูลหน้า Tasks =====
// Smart sort: วันที่ → งานมีเวลามาก่อน (เรียงเวลา) → priority (1-4, ไม่ระบุไปท้าย) → สร้างก่อนมาก่อน
$stmt = $conn->prepare("SELECT * FROM tasks WHERE user_id = ?
    ORDER BY is_completed ASC,
             due_date IS NULL, due_date ASC,
             parent_id IS NULL, parent_id ASC,
             sort_order ASC,
             due_time IS NULL, due_time ASC,
             priority = 0, priority ASC,
             created_at ASC
    LIMIT 200");
$stmt->bind_param("i", $user_id); $stmt->execute();
$all_tasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

$today_str = date('Y-m-d');
$task_time = build_task_time_groups($all_tasks, $key, $today_str);

// สรุปจำนวนงานต่อวัน สำหรับวาดจุดบนปฏิทินหน้า Tasks
$task_dates = [];
foreach ($all_tasks as $t) {
    if (empty($t['due_date'])) continue;
    $d = $t['due_date'];
    if (!isset($task_dates[$d])) $task_dates[$d] = ['pending' => 0, 'done' => 0];
    $task_dates[$d][(int)$t['is_completed'] === 1 ? 'done' : 'pending']++;
}

// ===== หน้า Pipeline: ตั้งเป้า + คำนวณ funnel ถอยหลัง =====
$current_month = date('Y-m');
if (preg_match('/^\d{4}-\d{2}$/', $_GET['pl_month'] ?? '')) {
    $current_month = $_GET['pl_month'];
}
$pipeline_defaults = [
    'target_month' => $current_month,
    'monthly_target' => 500000,
    'commission_per_deal' => 50000,
    'project_target_price' => 5000000,
    'need_project' => 0,
    'need_lead' => 0,
    'need_app' => 0,
    'need_showing' => 0,
    'need_nego' => 0,
];
$stmt = $conn->prepare("SELECT * FROM pipeline_settings WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$pipeline_row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$pipeline = $pipeline_row ? array_merge($pipeline_defaults, $pipeline_row) : $pipeline_defaults;
$pipeline['target_month'] = $current_month;
if (empty($pipeline_row)) {
    $pipeline['target_month'] = $current_month;
}

/** Win จากเป้ารายได้ ÷ คอมต่อดีล */
function pipeline_calc_win($monthly_target, $commission) {
    return (int)max(1, ceil((int)$monthly_target / max(1000, (int)$commission)));
}

/** ค่าเริ่มต้นแนะนำ (ครั้งแรกที่ยังไม่เคยตั้งจำนวน) */
function pipeline_suggest_counts($win) {
    $win = max(1, (int)$win);
    $nego    = (int)ceil($win / 0.4);
    $showing = (int)ceil($nego / 0.45);
    $app     = (int)ceil($showing / 0.6);
    $lead    = (int)ceil($app / 0.5);
    $project = (int)ceil($lead / 0.25);
    return compact('project', 'lead', 'app', 'showing', 'nego', 'win');
}

$pipeline_need = ['win' => pipeline_calc_win((int)$pipeline['monthly_target'], (int)$pipeline['commission_per_deal'])];
$has_saved_counts = (int)($pipeline['need_project'] ?? 0) > 0;
if ($has_saved_counts) {
    $pipeline_need['project']  = (int)$pipeline['need_project'];
    $pipeline_need['lead']     = (int)$pipeline['need_lead'];
    $pipeline_need['app']      = (int)$pipeline['need_app'];
    $pipeline_need['showing']  = (int)$pipeline['need_showing'];
    $pipeline_need['nego']     = (int)$pipeline['need_nego'];
} else {
    $pipeline_need = pipeline_suggest_counts($pipeline_need['win']);
}

// นับจริงในระบบ (resolve จาก Stage Matrix + fallback legacy)
$stmt = $conn->prepare("SELECT COUNT(*) c FROM owners WHERE user_id = ? AND availability_status = 'ยังขายอยู่'");
$stmt->bind_param("i", $user_id); $stmt->execute();
$actual_project = (int)$stmt->get_result()->fetch_assoc()['c']; $stmt->close();

$pipeline_actual = lead_pipeline_actual_counts($conn, $user_id, $pipeline['target_month']);
$actual_lead = $pipeline_actual['lead'];
$actual_app = $pipeline_actual['app'];
$actual_showing = $pipeline_actual['showing'];
$actual_nego = $pipeline_actual['nego'];
$actual_win_month = $pipeline_actual['win_month'];
$matrix_analytics = lead_matrix_analytics($conn, $user_id);
$pl_matrix_stage_labels = [];
foreach (lead_funnel_statuses() as $mst) {
    $pl_matrix_stage_labels[$mst] = lead_status_step_meta($mst)['label'];
}

$actual_revenue = $actual_win_month * (int)$pipeline['commission_per_deal'];
$target_revenue = (int)$pipeline['monthly_target'];
$revenue_pct = $target_revenue > 0 ? min(100, round($actual_revenue / $target_revenue * 100)) : 0;

// ยอดสะสมรายปี (YTD) — นับจาก win_date (ไม่มีใช้ updated_at แทน)
$ytd_year = (int)date('Y');
$stmt = $conn->prepare("SELECT COUNT(*) c FROM leads WHERE user_id = ? AND status = 'Win' AND YEAR(COALESCE(win_date, DATE(updated_at))) = ?");
$stmt->bind_param("ii", $user_id, $ytd_year);
$stmt->execute();
$ytd_win_count = (int)$stmt->get_result()->fetch_assoc()['c']; $stmt->close();

$ytd_commission = $ytd_win_count * (int)$pipeline['commission_per_deal'];

$ytd_gmv = 0.0;
$stmt = $conn->prepare("SELECT win_price_enc FROM leads WHERE user_id = ? AND status = 'Win' AND YEAR(COALESCE(win_date, DATE(updated_at))) = ?");
$stmt->bind_param("ii", $user_id, $ytd_year);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    if (!empty($row['win_price_enc'])) {
        $ytd_gmv += parse_price_to_baht(dec($row['win_price_enc'], $key));
    }
}
$stmt->close();

$pipeline_stages = [
    ['key' => 'project',  'label' => 'Project',  'icon' => 'building-2',     'need' => $pipeline_need['project'],  'actual' => $actual_project,   'editable' => true],
    ['key' => 'lead',     'label' => 'Lead',     'icon' => 'users',          'need' => $pipeline_need['lead'],     'actual' => $actual_lead,      'editable' => true],
    ['key' => 'app',      'label' => 'App.',     'icon' => 'calendar',       'need' => $pipeline_need['app'],      'actual' => $actual_app,       'editable' => true],
    ['key' => 'showing',  'label' => 'Showing',  'icon' => 'eye',            'need' => $pipeline_need['showing'],  'actual' => $actual_showing,   'editable' => true],
    ['key' => 'nego',     'label' => 'Nego',     'icon' => 'file-signature', 'need' => $pipeline_need['nego'],     'actual' => $actual_nego,      'editable' => true],
    ['key' => 'win',      'label' => 'Win',      'icon' => 'trophy',         'need' => $pipeline_need['win'],      'actual' => $actual_win_month, 'editable' => false],
];

// ข้อความสรุปว่าควรโฟกัสอะไร (ช่องที่ขาดมากที่สุด)
$pipeline_focus = [];
foreach ($pipeline_stages as $s) {
    $gap = $s['need'] - $s['actual'];
    if ($gap > 0) $pipeline_focus[] = ['label' => $s['label'], 'gap' => $gap];
}
usort($pipeline_focus, fn($a, $b) => $b['gap'] <=> $a['gap']);

$thai_months_full = ['','มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
$thai_m_short = ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
[$py, $pm] = array_map('intval', explode('-', $pipeline['target_month']));
$pipeline_month_label = $thai_months_full[$pm] . ' ' . $py;

function fmt_baht_short($n) {
    $n = (int)$n;
    if ($n >= 1000000) return round($n / 1000000, 1) . ' ลบ.';
    if ($n >= 1000) return round($n / 1000) . 'k';
    return (string)$n;
}

/** แปลงข้อความราคาเป็นตัวเลขบาท (สำหรับรวมยอด) */
function parse_price_to_baht($raw) {
    $s = trim((string)$raw);
    if ($s === '') return 0.0;
    if (preg_match('/^([\d.,]+)\s*ลบ\.?$/u', $s, $m)) {
        return (float)str_replace(',', '', $m[1]) * 1000000;
    }
    return (float)preg_replace('/[^0-9.]/', '', str_replace(',', '', $s));
}

// ===== ข้อมูลโปรไฟล์ / สถานะแพ็กเกจ =====
$display_name = $_SESSION['line_display_name'] ?: ($user['user_name'] ?? 'ผู้ใช้งาน');
$picture_url  = $_SESSION['line_picture_url'] ?? '';

$plan_badge = user_plan_badge($user);
$trial_text = user_plan_subtext($user);
$profile_url = auth_base_url() . '/profile.php';

// สีของ badge สถานะลีด
function lead_status_class($status) {
    switch ($status) {
        case 'Win':         return 'badge-status-yes';
        case 'Rejected':
        case 'Hold_Reject': return 'bg-red-500/15 text-red-400';
        case 'Lose':        return 'bg-orange-500/15 text-orange-400';
        case 'Reserve':     return 'bg-sky-500/15 text-sky-400';
        case 'Nego':
        case 'Close':
        case 'Bank':        return 'bg-[#E2E800]/15 text-[var(--accent-text)]';
        default:            return 'bg-[var(--chip)] text-[var(--text-2)]';
    }
}

function lead_pipeline_steps() {
    return ['Call', 'Follow', 'Appointment', 'Show', 'Nego', 'Reserve', 'Close', 'Bank', 'Win'];
}

function lead_status_step_meta($status) {
    $map = [
        'Call'        => ['label' => 'Call',    'icon' => 'phone'],
        'Follow'      => ['label' => 'Follow',  'icon' => 'refresh-cw'],
        'Appointment' => ['label' => 'Appointment', 'icon' => 'calendar'],
        'Show'        => ['label' => 'Show',        'icon' => 'eye'],
        'Nego'        => ['label' => 'Nego',        'icon' => 'message-square'],
        'Reserve'     => ['label' => 'Reserve',     'icon' => 'bookmark'],
        'Close'       => ['label' => 'Close',   'icon' => 'file-check'],
        'Bank'        => ['label' => 'Bank',    'icon' => 'landmark'],
        'Win'         => ['label' => 'Win',     'icon' => 'trophy'],
        'Rejected'    => ['label' => 'Reject',  'icon' => 'ban'],
        'Hold_Reject' => ['label' => 'Hold',    'icon' => 'pause-circle'],
        'Lose'        => ['label' => 'Lose',    'icon' => 'user-x'],
    ];
    return $map[$status] ?? ['label' => $status, 'icon' => 'circle'];
}

function lead_pain_point_display(array $l, string $key): string
{
    $fromEnc = trim((string)dec($l['pain_point_enc'] ?? '', $key));
    if ($fromEnc !== '') {
        return $fromEnc;
    }
    return trim((string)($l['pain_point_found'] ?? ''));
}

/** สร้าง lookup รหัสทรัพย์ → id + ชื่อโครงการ (เชื่อม Lead ↔ Owner) */
function dashboard_owner_lookup(mysqli $conn, int $user_id, string $key): array
{
    $lookup = [];
    $stmt = $conn->prepare('SELECT id, code_list, project_enc, project_name_en_enc, project_name_th_enc FROM owners WHERE user_id = ?');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($o = $res->fetch_assoc()) {
        $code = $o['code_list'] ?? '';
        if ($code === '') {
            continue;
        }
        $lookup[$code] = [
            'id'      => (int)$o['id'],
            'project' => map_project_label($o, $key),
        ];
    }
    $stmt->close();
    return $lookup;
}

/** โหลด lead payload สำหรับ AJAX (หลังบันทึก / ลบประวัติ) */
function dashboard_lead_payload(mysqli $conn, int $user_id, string $key, int $lead_id): ?array
{
    $stmt = $conn->prepare('SELECT * FROM leads WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->bind_param('ii', $lead_id, $user_id);
    $stmt->execute();
    $lead = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$lead) {
        return null;
    }

    $logs = [];
    $ls = $conn->prepare('SELECT * FROM lead_status_logs WHERE lead_id = ? AND user_id = ? ORDER BY log_date DESC, id DESC');
    $ls->bind_param('ii', $lead_id, $user_id);
    $ls->execute();
    $logs = $ls->get_result()->fetch_all(MYSQLI_ASSOC);
    $ls->close();

    $owner_prices = [];
    $ops = $conn->prepare('SELECT code_list, asking_price_enc FROM owners WHERE user_id = ?');
    $ops->bind_param('i', $user_id);
    $ops->execute();
    $ores = $ops->get_result();
    while ($or = $ores->fetch_assoc()) {
        $owner_prices[$or['code_list']] = fmt_price_full(dec($or['asking_price_enc'], $key));
    }
    $ops->close();

    $stage_events = [];
    $se2 = $conn->prepare('SELECT * FROM lead_stage_events WHERE user_id = ? AND lead_id = ? ORDER BY event_date ASC, id ASC');
    $se2->bind_param('ii', $user_id, $lead_id);
    $se2->execute();
    $stage_events = $se2->get_result()->fetch_all(MYSQLI_ASSOC);
    $se2->close();

    $group_sizes = lead_customer_group_sizes($conn, $user_id);
    $owner_lookup = dashboard_owner_lookup($conn, $user_id, $key);
    return lead_display_row($lead, $key, $logs, $owner_prices, $stage_events, $group_sizes, $owner_lookup);
}

function lead_display_row($l, $key, $status_logs = [], $owner_prices = [], $stage_events = [], $group_sizes = [], $owner_lookup = []) {
    $owner_code = $l['owner_code'] ?? '';
    $owner_id = null;
    $owner_linked = false;
    $owner_project = '';
    if ($owner_code !== '' && isset($owner_lookup[$owner_code])) {
        $owner_id = (int)$owner_lookup[$owner_code]['id'];
        $owner_linked = true;
        $owner_project = $owner_lookup[$owner_code]['project'] ?? '';
    }
    $product_price = '';
    if ($owner_code !== '' && isset($owner_prices[$owner_code])) {
        $product_price = $owner_prices[$owner_code];
    } else {
        $product_price = fmt_price_full(dec($l['product_price_enc'] ?? '', $key));
    }
    $chat_raw = $l['chat_image_url'] ?? '';
    $pipeline = lead_pipeline_steps();
    $stage_events = is_array($stage_events) ? $stage_events : [];
    $resolved = lead_resolve_from_stage_events($l, $stage_events);
    $status = $resolved['status'] ?? ($l['status'] ?? 'Call');
    $current_stage = $resolved['current_stage'] ?? $status;
    $step_idx = (int)($resolved['pipeline_idx'] ?? 0);
    $stage_outcome_latest = $resolved['stage_outcome_latest'] ?? [];
    $stage_events_sorted = $resolved['stage_events_sorted'] ?? [];
    $has_stage_events = !empty($stage_events_sorted);

    $matrix_stage_events = array_map(function ($e) use ($key) {
        $stage = (string)($e['stage'] ?? '');
        $outcome = (string)($e['outcome'] ?? 'yes');
        $note_dec = '';
        if (!empty($e['note_enc'])) {
            $note_dec = dec($e['note_enc'], $key) ?: '';
        }
        $event_date = $e['event_date'] ?? '';
        if (empty($event_date) || $event_date === '0000-00-00') {
            $event_date = substr((string)($e['created_at'] ?? ''), 0, 10);
        }
        return [
            'id' => (int)($e['id'] ?? 0),
            'kind' => 'stage_event',
            'stage' => $stage,
            'outcome' => $outcome,
            'date' => $event_date ?: null,
            'note' => $note_dec,
        ];
    }, $stage_events_sorted);

    $phoneRaw = dec($l['phone_enc'] ?? '', $key);
    $contacts = repair_owner_contacts([
        'phone'   => $phoneRaw,
        'line_id' => dec($l['line_id_enc'] ?? '', $key),
    ]);
    $phoneMeta = phone_contact_meta($contacts['phone'] !== '' ? $contacts['phone'] : $phoneRaw);
    return [
        'id'              => (int)$l['id'],
        'customer_group_id' => lead_effective_group_id($l),
        'group_size'      => (int)($group_sizes[lead_effective_group_id($l)] ?? 1),
        'code'            => $l['lead_code'],
        'name'            => dec($l['lead_name_enc'], $key),
        'project'         => dec($l['project_enc'], $key),
        'owner_code'      => $owner_code,
        'owner_id'        => $owner_id,
        'owner_linked'    => $owner_linked,
        'owner_project'   => $owner_project,
        'budget'          => dec($l['budget_enc'], $key),
        'budget_fmt'      => fmt_price_full(dec($l['budget_enc'], $key)),
        'product_price'   => $product_price,
        'potential'       => lead_normalize_potential($l['potential'] ?? ''),
        'potential_class' => lead_normalize_potential($l['potential'] ?? '') !== '' ? potential_class($l['potential']) : '',
        'potential_meta'  => lead_potential_meta($l['potential'] ?? ''),
        'status'          => $status,
        'status_class'    => lead_status_class($status),
        'status_meta'     => lead_status_step_meta($status),
        'pipeline'        => $pipeline,
        'pipeline_idx'    => $step_idx < 0 ? 0 : (int)$step_idx,
        'pipeline_current_stage' => $current_stage,
        'stage_outcome_latest'  => $stage_outcome_latest,
        'has_stage_events'       => $has_stage_events,
        'inbound_date'    => ($l['contact_date'] ?? '') ?: substr((string)($l['created_at'] ?? ''), 0, 10),
        'background'      => dec($l['background_enc'] ?? '', $key),
        'requirement'     => dec($l['requirement_enc'] ?? '', $key),
        'pain_point'      => lead_pain_point_display($l, $key),
        'financials'      => dec($l['financials_enc'] ?? '', $key),
        'timeline'        => dec($l['target_date_enc'] ?? '', $key),
        'current_update'  => dec($l['current_update_enc'] ?? '', $key),
        'next_plan'       => dec($l['next_plan_action_enc'] ?? '', $key),
        'next_plan_date'  => $l['next_plan_date'] ?? '',
        'customer_insight'=> dec($l['customer_insight_enc'] ?? '', $key),
        'deal_context'    => dec($l['deal_context_enc'] ?? '', $key),
        'phone'           => $phoneMeta['display'],
        'phone_tel'       => $phoneMeta['tel'],
        'phone_suspect'   => $phoneMeta['suspect'],
        'line_id'         => $contacts['line_id'],
        'line_url'        => line_profile_url($contacts['line_id']),
        'chat_url'        => gdrive_cover_url($chat_raw),
        'chat_image_url'  => $chat_raw,
        'chat_photos_link'=> dec($l['chat_photos_link_enc'] ?? '', $key),
        'win_date'        => $l['win_date'] ?? '',
        'win_price'       => fmt_price_full(dec($l['win_price_enc'] ?? '', $key)),
        'win_payment_method' => $l['win_payment_method'] ?? '',
        'win_payment_label' => match ($l['win_payment_method'] ?? '') {
            'cash' => 'เงินสด',
            'loan' => 'กู้ธนาคาร',
            default => '',
        },
        'sheet_status'    => $l['sheet_status'] ?? '',
        'pain_point_found'=> $l['pain_point_found'] ?? '',
        'revenue'         => fmt_price_full(dec($l['revenue_enc'] ?? '', $key)),
        'units_sent'      => $l['units_sent'] ?? null,
        'offered_listings'=> dec($l['offered_listings_enc'] ?? '', $key),
        'gender'          => dec($l['gender_enc'] ?? '', $key),
        'nationality'     => dec($l['nationality_enc'] ?? '', $key),
        'source'          => dec($l['source_enc'] ?? '', $key),
        'contact_by'      => dec($l['contact_by_enc'] ?? '', $key),
        'intent_buy_rent' => $l['intent_buy_rent'] ?? '',
        'unit_type'       => dec($l['unit_type_enc'] ?? '', $key),
        'listing_type'    => dec($l['listing_type_enc'] ?? '', $key),
        'aux_tag'         => lead_aux_tag_resolve($l),
        'aux_tag_meta'    => lead_aux_tag_meta(lead_aux_tag_resolve($l)),
        'is_agent'        => !empty($l['is_agent']),
        'agent_client_name' => dec($l['agent_client_name_enc'] ?? '', $key),
        'agent_client_phone_last4' => dec($l['agent_client_phone_last4_enc'] ?? '', $key),
        'age'             => dec($l['age_enc'] ?? '', $key),
        'occupation'      => dec($l['occupation_enc'] ?? '', $key),
        'work_area'       => dec($l['work_area_enc'] ?? '', $key),
        'commute'         => dec($l['commute_enc'] ?? '', $key),
        'interest_area'   => dec($l['interest_area_enc'] ?? '', $key),
        'purchase_purpose'=> dec($l['purchase_purpose_enc'] ?? '', $key),
        'close_project'   => dec($l['close_project_enc'] ?? '', $key),
        'close_owner_code'=> $l['close_owner_code'] ?? '',
        'close_open_price'=> dec($l['close_open_price_enc'] ?? '', $key),
        'close_open_price_fmt' => fmt_price_full(dec($l['close_open_price_enc'] ?? '', $key)),
        'win_close_scope' => lead_normalize_win_close_scope($l['win_close_scope'] ?? 'this'),
        'win_close_scope_meta' => lead_win_close_scope_meta($l['win_close_scope'] ?? 'this'),
        'is_win'          => $status === 'Win',
        'is_reject'       => in_array($status, ['Rejected', 'Hold_Reject'], true),
        'is_lose'         => $status === 'Lose',
        'is_terminal'     => in_array($status, lead_terminal_statuses(), true),
        'is_reserve'      => $status === 'Reserve',
        'reserve_date'    => $l['reserve_date'] ?? '',
        'status_logs'     => array_map(function ($log) use ($key) {
            $st = $log['status'];
            $meta = lead_status_step_meta($st);
            return [
                'id'     => (int)($log['id'] ?? 0),
                'kind'   => 'status_log',
                'date'   => $log['log_date'],
                'status' => $st,
                'label'  => $meta['label'],
                'icon'   => $meta['icon'],
                'note'   => dec($log['note_enc'] ?? '', $key),
            ];
        }, $status_logs),
        'stage_events' => $matrix_stage_events,
        'filter_month'  => lead_filter_month_for_row($l),
    ];
}

/** แปลงลิงก์ Google Drive เป็น URL รูปปก */
function gdrive_cover_url($url) {
    return gdrive_cover_display_url($url);
}

/** สถานะ sales มาตรฐาน (sale / sale&available=ขาย+เช่า / rent / cancel / sold) */
function owner_sales_status_key($o) {
    $sales = strtolower(str_replace(' ', '', trim($o['sales_status'] ?? '')));
    $avail = $o['availability_status'] ?? '';
    if ($avail === 'ขายได้แล้ว' || $sales === 'sold') return 'sold';
    if ($avail === 'ยกเลิกการขาย' || $sales === 'cancel') return 'cancel';
    if ($sales === 'rent' || $sales === 'rental') return 'rent';
    if (strpos($sales, 'sale&available') !== false || $sales === 'saleavailable') return 'sale&available';
    if ($sales === 'salewithtenant' || strpos($sales, 'withtenant') !== false) return 'sale_with_tenant';
    if ($sales === 'sale' || strpos($sales, 'sale') === 0) return 'sale';
    return 'sale';
}

/** ป้ายสถานะบนการ์ด — สี + ไอคอน + ข้อความ */
function owner_card_status_meta($o) {
    $key = owner_sales_status_key($o);
    $map = [
        'sale'               => ['label' => 'Sale',           'icon' => 'tag',          'class' => 'badge-status-yes'],
        'sale&available'     => ['label' => 'Sale·Rent',      'icon' => 'key',          'class' => 'badge-status-yes'],
        'sale_with_tenant'   => ['label' => 'Sale·Tenant',    'icon' => 'users',        'class' => 'badge-status-yes'],
        'rent'               => ['label' => 'Rent',           'icon' => 'key',          'class' => 'badge-status-yes'],
        'cancel'             => ['label' => 'Cancel',         'icon' => 'ban',          'class' => 'bg-[var(--chip)] text-[var(--text-2)]'],
        'sold'               => ['label' => 'Sold',           'icon' => 'check-circle', 'class' => 'bg-red-500/15 text-red-400'],
    ];
    return $map[$key] ?? $map['sale'];
}

/** วันที่อัปเดตล่าสุด (ติดต่อ / แก้ข้อมูล / log) */
function owner_last_updated_at($o, $contact_logs = []) {
    $dates = [];
    if (!empty($o['last_contact_date'])) $dates[] = $o['last_contact_date'];
    if (!empty($o['updated_at'])) $dates[] = substr((string)$o['updated_at'], 0, 10);
    foreach ($contact_logs as $l) {
        if (!empty($l['contact_date'])) $dates[] = $l['contact_date'];
    }
    return $dates ? max($dates) : '';
}

/** ระยะเวลาที่ผ่านมาจากวันที่ (ภาษาไทย) */
function owner_elapsed_th($date_str) {
    if (!$date_str) return '';
    $diff = max(0, (int)floor((strtotime('today') - strtotime($date_str)) / 86400));
    if ($diff === 0) return 'วันนี้';
    if ($diff < 30) return "ผ่านมา {$diff} วัน";
    $months = intdiv($diff, 30);
    $days   = $diff % 30;
    $parts  = [];
    if ($months >= 12) {
        $years = intdiv($months, 12);
        $months = $months % 12;
        if ($years) $parts[] = "{$years} ปี";
    }
    if ($months) $parts[] = "{$months} เดือน";
    if ($days) $parts[] = "{$days} วัน";
    return 'ผ่านมา ' . implode(' ', $parts);
}

/** วันที่สั้น สำหรับการ์ด */
function owner_date_short_th($iso) {
    if (!$iso) return '-';
    $p = explode('-', (string)$iso);
    if (count($p) < 3) return $iso;
    $m = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    return (int)$p[2] . ' ' . $m[(int)$p[1] - 1] . ' ' . $p[0];
}

/** สถานะทรัพย์ภาษาไทย (แสดงบนการ์ด) */
function owner_status_th($o) {
    $sales = strtolower(str_replace(' ', '', trim($o['sales_status'] ?? '')));
    $avail = $o['availability_status'] ?? '';
    if ($avail === 'ขายได้แล้ว' || $sales === 'sold') return 'ขายแล้ว';
    if ($avail === 'ยกเลิกการขาย' || $sales === 'cancel') return 'ยกเลิก';
    if ($sales === 'rent' || $sales === 'rental') return 'เช่า';
    if (strpos($sales, 'sale&available') !== false) return 'ขาย·เช่า';
    if ($sales === 'salewithtenant' || strpos($sales, 'withtenant') !== false) return 'ขายพร้อมผู้เช่า';
    if ($sales === 'sale') return 'ขาย';
    return $avail !== '' ? $avail : 'ขาย';
}

/** ช่องทางที่ได้ Listing */
function listing_source_label($src) {
    $map = ['survey' => 'Survey', 'fb' => 'Facebook', 'livinginsider' => 'LivingInsider', 'other' => 'เว็บอื่นๆ'];
    return $map[strtolower($src ?? '')] ?? ($src ?: '-');
}

/** Potential จากชีท — ว่างถ้าไม่มีใน CSV */
function lead_normalize_potential($raw): string
{
    $g = strtoupper(trim((string)$raw));
    return in_array($g, ['A', 'B', 'C'], true) ? $g : '';
}

/** เกรด Lead (Potential A/B/C) — ไม่ใส่เหตุผล/ไทม์ไลน์ที่ระบบเดาเอง */
function lead_potential_meta($grade): ?array
{
    $g = lead_normalize_potential($grade);
    if ($g === '') {
        return null;
    }
    $map = [
        'A' => [
            'grade' => 'A',
            'label' => 'A',
            'desc'  => 'มีเงิน · ไทม์ชัด · pain ชัด · พร้อมซื้อ',
            'icon'  => 'flame',
        ],
        'B' => [
            'grade' => 'B',
            'label' => 'B',
            'desc'  => 'มีเงิน · ไทม์ยังไม่ชัด · ยังช้อปปิ้ง',
            'icon'  => 'thermometer',
        ],
        'C' => [
            'grade' => 'C',
            'label' => 'C',
            'desc'  => 'ไม่มีเงิน · ไม่มีไทม์ · ดูเฉยๆ',
            'icon'  => 'snowflake',
        ],
    ];
    $m = $map[$g];
    $m['class'] = potential_class($g);
    return $m;
}

/** เกรดความเร่งด่วนเจ้าของทรัพย์ A/B/C — คืน null ถ้ายังไม่ได้ตั้ง */
function urgency_meta($grade) {
    $g = strtoupper(trim((string)$grade));
    if ($g === '' || !in_array($g, ['A', 'B', 'C'], true)) {
        return null;
    }
    $map = [
        'A' => ['grade' => 'A', 'label' => 'Hot', 'icon' => 'flame', 'timeline' => '1–3 เดือน', 'desc' => 'รีบมาก อยากขายให้ได้เร็ว ถ้าขายไม่ได้มีผลกระทบมาก'],
        'B' => ['grade' => 'B', 'label' => 'Warm', 'icon' => 'thermometer', 'timeline' => '4–6 เดือน', 'desc' => 'รีบแต่ยังรอได้ เช่น มีบ้านหลังใหม่ ยังผ่อนหลังเดิมได้'],
        'C' => ['grade' => 'C', 'label' => 'Cold', 'icon' => 'snowflake', 'timeline' => 'ภายในปีนี้', 'desc' => 'รอได้ ไม่ขายไม่กระทบชีวิต แต่อยากขาย'],
    ];
    $m = $map[$g];
    $m['class'] = potential_class($g);
    return $m;
}

/** tel: สำหรับกดโทร */
function phone_tel_href($phone) {
    $digits = preg_replace('/\D/', '', normalize_phone_string($phone));
    if ($digits === '') return '';
    if ($digits[0] === '0') $digits = '66' . substr($digits, 1);
    return 'tel:+' . $digits;
}

/** ลิงก์เปิด LINE จาก Line ID */
function line_profile_url($line_id) {
    $id = normalize_line_id_string($line_id);
    if ($id === '') return '';
    if ($id[0] === '@') return 'https://line.me/ti/p/' . $id;
    return 'https://line.me/ti/p/~' . $id;
}

/** ผู้ปรับราคา — ข้อความไทย */
function price_changed_by_label($by) {
    return strtolower($by ?? '') === 'agent' ? 'ที่ปรึกษาปรับ' : 'เจ้าของปรับ';
}

/** เป็นคอนโด/อพาร์ทเมนต์หรือไม่ */
function is_condo_type($ptype) {
    $p = mb_strtolower(trim((string)$ptype));
    return strpos($p, 'คอนโด') !== false || strpos($p, 'condo') !== false
        || strpos($p, 'อพาร์ท') !== false || strpos($p, 'apartment') !== false;
}

/** ป้ายทิศตามประเภททรัพย์ */
function direction_label_for_type($ptype) {
    return is_condo_type($ptype) ? 'ทิศระเบียง' : 'หน้าบ้านหันทิศ';
}

/** รวมข้อมูล owner สำหรับแสดงผล + infowindow */
function owner_display_row($o, $key, $contact_logs = [], $price_logs = []) {
    $name_en = dec($o['project_name_en_enc'] ?? '', $key);
    if ($name_en === '') $name_en = dec($o['project_enc'], $key);
    $cover_raw = $o['cover_image_url'] ?? '';
    $last_upd  = owner_last_updated_at($o, $contact_logs);
    $fields = repair_shifted_owner_fields([
        'asking_price'  => dec($o['asking_price_enc'], $key),
        'direction'     => dec($o['direction_enc'], $key),
        'rental_price'  => dec($o['rental_price_enc'], $key),
        'unit_no'       => dec($o['unit_no_enc'], $key),
    ]);
    $contacts = repair_owner_contacts([
        'phone'   => dec($o['phone_enc'], $key),
        'line_id' => dec($o['line_id_enc'], $key),
    ]);
    return [
        'id'              => (int)$o['id'],
        'code'            => $o['code_list'],
        'name_en'         => $name_en,
        'name_th'         => dec($o['project_name_th_enc'] ?? '', $key),
        'price'           => fmt_price_full($fields['asking_price']),
        'price_raw'       => normalize_price_string($fields['asking_price']),
        'rent'            => fmt_price_full($fields['rental_price']),
        'rent_raw'        => normalize_price_string($fields['rental_price']),
        'status_th'       => owner_status_th($o),
        'status_card'     => owner_card_status_meta($o),
        'last_updated'    => $last_upd,
        'last_updated_fmt'=> owner_date_short_th($last_upd),
        'elapsed_th'      => owner_elapsed_th($last_upd),
        'cover_url'       => gdrive_cover_url($cover_raw),
        'owner_name'      => dec($o['owner_name_enc'], $key),
        'phone'           => format_phone_display($contacts['phone']),
        'phone_raw'       => $contacts['phone'],
        'phone_tel'       => phone_tel_href($contacts['phone']),
        'line_id'         => $contacts['line_id'],
        'line_url'        => line_profile_url($contacts['line_id']),
        'last_contact'    => $o['last_contact_date'] ?? '',
        'contact_summary' => dec($o['contact_summary_enc'] ?? '', $key),
        'price_consult'   => dec($o['price_consult_enc'] ?? '', $key),
        'urgency_meta'    => urgency_meta($o['owner_urgency'] ?? ''),
        'contact_logs'    => array_map(function ($l) use ($key) {
            return ['date' => $l['contact_date'], 'note' => dec($l['note_enc'], $key)];
        }, $contact_logs),
        'price_logs'      => array_map(function ($l) use ($key) {
            return [
                'date'       => $l['log_date'],
                'old_price'  => fmt_price_full(dec($l['old_price_enc'] ?? '', $key)),
                'new_price'  => fmt_price_full(dec($l['new_price_enc'], $key)),
                'changed_by' => price_changed_by_label($l['changed_by'] ?? 'owner'),
                'note'       => dec($l['note_enc'] ?? '', $key),
            ];
        }, $price_logs),
        'listing_source'  => listing_source_label($o['listing_source'] ?? ''),
        'listing_date'    => $o['listing_date'] ?? '',
        'marketing_date'  => $o['marketing_date'] ?? '',
        'marketing_status'=> $o['marketing_status'] ?? '',
        'has_deed'        => isset($o['has_deed']) ? ($o['has_deed'] === null ? '' : ((int)$o['has_deed'] ? 'มี' : 'ไม่มี')) : '',
        'incomplete'      => dec($o['incomplete_details_enc'], $key),
        'sold_date'       => $o['sold_date'] ?? '',
        'sold_by'         => dec($o['sold_by_enc'], $key),
        'sold_price'      => fmt_price_full(dec($o['sold_price_enc'], $key)),
        'owner_price'     => fmt_price_full(dec($o['owner_asking_price_enc'] ?? '', $key)),
        'owner_price_raw' => dec($o['owner_asking_price_enc'] ?? '', $key),
        'cover_image_url' => $cover_raw,
        'listing_source_raw' => $o['listing_source'] ?? '',
        'has_deed_val'    => $o['has_deed'] ?? '',
        'property_type'   => dec($o['property_type_enc'], $key),
        'zone'            => dec($o['zone_enc'], $key),
        'soi'             => dec($o['soi_enc'] ?? '', $key),
        'unit_no'         => $fields['unit_no'],
        'floor'           => dec($o['floor_enc'], $key),
        'direction'       => $fields['direction'],
        'is_condo'        => is_condo_type(dec($o['property_type_enc'], $key)),
        'direction_label' => direction_label_for_type(dec($o['property_type_enc'], $key)),
        'sales_status'    => $o['sales_status'] ?? '',
        'area_rai'        => dec($o['area_rai_enc'], $key),
        'area_ngan'       => dec($o['area_ngan_enc'], $key),
        'area_sqwa'       => dec($o['area_sqwa_enc'], $key),
        'area_sqm'        => dec($o['area_sqm_enc'], $key),
        'bed'             => dec($o['bed_enc'], $key),
        'bath'            => dec($o['bath_enc'], $key),
        'maid'            => dec($o['maid_enc'] ?? '', $key),
        'parking'         => dec($o['parking_enc'], $key),
        'transfer_fee'    => $o['selling_condition'] ?? '',
        'map_url'         => dec($o['map_url_enc'], $key),
        'photos_link'     => dec($o['photos_link_enc'] ?? '', $key),
        'urgency'         => strtoupper(trim($o['owner_urgency'] ?? '')),
        'urgency_class'   => ($o['owner_urgency'] ?? '') !== '' ? potential_class($o['owner_urgency']) : '',
    ];
}

// สีของ badge ความรีบ (A/B/C)
function potential_class($p) {
    $p = strtoupper(trim((string)$p));
    if ($p === 'A') return 'bg-[#E2E800] text-[#141414]';
    if ($p === 'B') return 'bg-[var(--chip-strong)] text-[var(--text)]';
    return 'bg-[var(--surface)] text-[var(--muted)]';
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
  <meta http-equiv="Pragma" content="no-cache">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Dashboard - เลขา AI</title>
  <script src="https://cdn.tailwindcss.com/3.4.17"></script>
  <script src="https://cdn.jsdelivr.net/npm/lucide@0.263.0/dist/umd/lucide.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Noto+Sans+Thai:wght@400;500;700&display=swap" rel="stylesheet">
  <script>
    // ตั้งธีมก่อนหน้าโหลดเสร็จ กันจอกะพริบ (default = dark)
    if ((localStorage.getItem('theme') || 'dark') === 'light') document.documentElement.classList.add('light');
  </script>
  <style>
    /* ===== ชุดสี Salt & Pepper (Dark = ค่าเริ่มต้น) ===== */
    :root {
      --bg: #141414;
      --card: #1C1C1C;
      --surface: #232323;
      --chip: #2E2E2E;
      --chip-strong: #3A3A3A;
      --border: #2A2A2A;
      --border-2: #555555;
      --text: #F0F0F0;
      --text-2: #B5B5B5;
      --muted: #979797;
      --faint: #666666;
      --accent-text: #E2E800;
      /* สถานะบวก (Sale / Yes / Win) — เขียวเข้ม แยกโทนจาก lime accent */
      --status-yes-bg: rgba(4, 120, 87, 0.42);
      --status-yes-border: #047857;
      --status-yes-text: #10b981;
      --nav-dock-bg: rgba(28, 28, 28, 0.92);
      --nav-dock-border: rgba(255, 255, 255, 0.08);
      --nav-dock-shadow: 0 14px 44px rgba(0, 0, 0, 0.48), 0 4px 14px rgba(0, 0, 0, 0.28), inset 0 1px 0 rgba(255, 255, 255, 0.07);
      --nav-icon: #8a8a8a;
      --nav-active-bg: #F2F2F2;
      --nav-active-text: #141414;
      --nav-active-shadow: 0 2px 10px rgba(0, 0, 0, 0.18);
    }
    html.light {
      --bg: #F4F4F2;
      --card: #FFFFFF;
      --surface: #F0F0EE;
      --chip: #E9E9E6;
      --chip-strong: #DCDCD8;
      --border: #E4E4E0;
      --border-2: #B9B9B4;
      --text: #141414;
      --text-2: #4A4A4A;
      --muted: #6B6B6B;
      --faint: #8F8F8A;
      --accent-text: #7A7E00;
      --status-yes-bg: rgba(4, 120, 87, 0.14);
      --status-yes-border: #059669;
      --status-yes-text: #047857;
      --nav-dock-bg: rgba(255, 255, 255, 0.94);
      --nav-dock-border: rgba(0, 0, 0, 0.07);
      --nav-dock-shadow: 0 14px 40px rgba(0, 0, 0, 0.14), 0 4px 12px rgba(0, 0, 0, 0.08), inset 0 1px 0 rgba(255, 255, 255, 0.95);
      --nav-icon: #9a9a9a;
      --nav-active-bg: #141414;
      --nav-active-text: #F4F4F2;
      --nav-active-shadow: 0 2px 10px rgba(0, 0, 0, 0.14);
    }

    :root {
      --dash-header-h: 5.25rem;
      --dash-nav-h: calc(3.65rem + env(safe-area-inset-bottom, 0px));
    }
    #app-shell.shell-full-bleed { padding-bottom: 0 !important; min-height: 0; }
    #page-map:not(.hidden),
    #page-report:not(.hidden) {
      position: fixed;
      top: var(--dash-header-h);
      bottom: var(--dash-nav-h);
      left: 50%;
      transform: translateX(-50%);
      width: 100%;
      max-width: 28rem;
      height: auto !important;
      z-index: 15;
      background: var(--bg);
    }
    #page-report:not(.hidden) {
      display: flex;
      flex-direction: column;
    }
    #map-detail-sheet[data-state="idle"] #map-detail-scroll { flex: 0 0 auto; }

    body { font-family: 'Noto Sans Thai', 'DM Sans', sans-serif; transition: background-color .3s, color .3s; }
    h1, h2, h3 { text-wrap: balance; }
    .no-scrollbar::-webkit-scrollbar { display: none; }
    .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    .task-nest-target { cursor: pointer; }
    .task-item.task-dragging { opacity: 0.35; }
    .task-item.task-drop-before { box-shadow: inset 0 2px 0 0 #E2E800; }
    .task-item.task-drop-after { box-shadow: inset 0 -2px 0 0 #E2E800; }
    .task-item.task-drop-child { outline: 2px dashed #E2E800; outline-offset: 2px; }
    .task-open .task-title-line {
      display: -webkit-box;
      -webkit-box-orient: vertical;
      -webkit-line-clamp: 2;
      overflow: hidden;
      overflow-wrap: anywhere;
      word-break: break-word;
      line-height: 1.35;
    }
    .task-drag-ghost {
      position: fixed; z-index: 90; pointer-events: none;
      box-shadow: 0 8px 24px rgba(0,0,0,.25); transform: scale(1.02);
    }
    #task-context-menu { min-width: 16rem; }
    #task-detail-title {
      min-height: 3.25rem;
      max-height: min(40vh, 16rem);
      overflow-y: auto;
      resize: none;
      white-space: pre-wrap;
      overflow-wrap: anywhere;
      word-break: break-word;
      line-height: 1.45;
      field-sizing: content;
    }
    .task-undo-btn:disabled, .task-redo-btn:disabled { opacity: 0.35; pointer-events: none; }
    #bottom-nav-scroll {
      width: 100%;
      overflow-x: auto;
      overflow-y: hidden;
      -webkit-overflow-scrolling: touch;
      touch-action: pan-x;
      overscroll-behavior-x: contain;
      scroll-behavior: smooth;
      scroll-snap-type: x proximity;
    }
    #bottom-nav-track {
      display: flex;
      flex-wrap: nowrap;
      align-items: center;
      justify-content: space-between;
      width: 100%;
      gap: 0.15rem;
      padding: 0.4rem 0.5rem 0.5rem;
      border-radius: 0;
      background: var(--nav-dock-bg);
      border: none;
      box-shadow: none;
    }
    #bottom-nav-scroll .nav-btn {
      scroll-snap-align: center;
      flex: 1 1 0;
      min-width: 2rem;
      display: inline-flex;
      flex-direction: row;
      align-items: center;
      justify-content: center;
      gap: 0;
      height: 2.75rem;
      padding: 0 0.25rem;
      border-radius: 9999px;
      border: none;
      background: transparent;
      color: var(--nav-icon);
      font-size: 0.625rem;
      font-weight: 700;
      line-height: 1;
      transition: background-color .25s ease, color .25s ease, box-shadow .25s ease, gap .25s ease, padding .25s ease, flex-grow .25s ease;
    }
    #bottom-nav-scroll .nav-btn .nav-icon { width: 1.25rem; height: 1.25rem; flex-shrink: 0; }
    #bottom-nav-scroll .nav-btn .nav-label {
      display: inline-block;
      max-width: 0;
      opacity: 0;
      overflow: hidden;
      white-space: nowrap;
      transition: max-width .28s ease, opacity .2s ease, margin .28s ease;
      margin-left: 0;
    }
    #bottom-nav-scroll .nav-btn.is-active {
      flex: 1.35 1 0;
      padding: 0 0.55rem;
      gap: 0.3rem;
      background: var(--nav-active-bg);
      color: var(--nav-active-text);
      box-shadow: var(--nav-active-shadow);
    }
    #bottom-nav-scroll .nav-btn.is-active .nav-label {
      max-width: 3.25rem;
      opacity: 1;
      margin-left: 0;
    }
    input[type="date"] { color-scheme: dark; }
    html.light input[type="date"] { color-scheme: light; }
    .app-date-wrap { position: relative; display: block; }
    .app-date-wrap.w-full { width: 100%; }
    .app-date-display { padding-right: 2.5rem !important; }
    .app-date-cal-btn {
      position: absolute;
      right: 0.35rem;
      top: 50%;
      transform: translateY(-50%);
      width: 2rem;
      height: 2rem;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 0.5rem;
      color: var(--muted);
      transition: background-color 0.12s ease;
    }
    .app-date-cal-btn:hover { background: var(--surface); }
    .app-date-cal-btn:active { opacity: 0.85; }
    .app-date-wrap--open .app-date-cal-btn {
      color: var(--accent-text);
      background: rgba(226, 232, 0, 0.14);
    }
    .app-date-wrap--open .app-date-display {
      border-color: #E2E800;
      outline: 1px solid rgba(226, 232, 0, 0.35);
    }
    .app-date-iso-native {
      position: absolute !important;
      width: 1px !important;
      height: 1px !important;
      opacity: 0 !important;
      pointer-events: none !important;
      overflow: hidden !important;
    }
    .edit-inp {
      width: 100%;
      padding: 0.625rem 0.75rem;
      border-radius: 0.75rem;
      border: 1px solid var(--border);
      background: var(--card);
      color: var(--text);
      font-size: 0.875rem;
    }
    .edit-inp:focus { outline: 2px solid #E2E800; outline-offset: 1px; }

    /* Custom select — แทน native dropdown ให้เข้ากับธีม Salt & Pepper */
    .app-select-native {
      position: absolute !important;
      width: 1px !important;
      height: 1px !important;
      padding: 0 !important;
      margin: -1px !important;
      overflow: hidden !important;
      clip: rect(0, 0, 0, 0) !important;
      white-space: nowrap !important;
      border: 0 !important;
      opacity: 0 !important;
      pointer-events: none !important;
    }
    .app-select-wrap {
      position: relative;
      display: block;
      min-width: 7rem;
    }
    .app-select-wrap--open {
      z-index: 30;
    }
    .app-select-wrap.w-full { width: 100%; }
    .app-select-wrap--compact {
      flex: 1 1 7rem;
      min-width: 7rem;
    }
    .app-select-wrap--sort { flex: 1 1 9rem; min-width: 9rem; }
    .app-select-trigger {
      width: 100%;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.5rem;
      padding: 0.5rem 0.75rem;
      border-radius: 0.75rem;
      border: 1px solid var(--border);
      background: var(--card);
      color: var(--text-2);
      font-size: 0.75rem;
      font-weight: 700;
      line-height: 1.25;
      cursor: pointer;
      text-align: left;
      transition: border-color 0.15s ease, background-color 0.15s ease;
    }
    .app-select-wrap--surface .app-select-trigger { background: var(--surface); }
    .app-select-wrap--form .app-select-trigger {
      padding: 0.625rem 0.75rem;
      font-size: 0.875rem;
      font-weight: 400;
      color: var(--text);
    }
    .app-select-trigger:hover { border-color: var(--border-2); }
    .app-select-trigger:focus-visible {
      outline: 2px solid #E2E800;
      outline-offset: 1px;
    }
    .app-select-wrap--open .app-select-trigger {
      border-color: #E2E800;
    }
    .app-select-wrap--disabled .app-select-trigger {
      opacity: 0.5;
      cursor: not-allowed;
      pointer-events: none;
    }
    .app-select-menu {
      position: absolute;
      left: 0;
      right: 0;
      top: calc(100% + 4px);
      z-index: 200;
      max-height: min(16rem, 50vh);
      overflow-y: auto;
      overflow-x: hidden;
      border-radius: 0.75rem;
      border: 1px solid var(--border);
      background: var(--card);
      box-shadow: 0 12px 32px rgba(0, 0, 0, 0.18);
      padding: 0.25rem;
    }
    .app-select-opt {
      display: flex;
      align-items: center;
      width: 100%;
      padding: 0.5rem 0.625rem;
      border-radius: 0.5rem;
      font-size: 0.75rem;
      font-weight: 600;
      color: var(--text-2);
      text-align: left;
      cursor: pointer;
      transition: background-color 0.12s ease;
    }
    .app-select-wrap--form .app-select-opt { font-size: 0.875rem; font-weight: 500; }
    .app-select-opt:hover,
    .app-select-opt:focus-visible {
      background: var(--surface);
      outline: none;
    }
    .app-select-opt--selected {
      background: rgba(226, 232, 0, 0.14);
      color: var(--accent-text);
      font-weight: 700;
    }
    .app-select-opt--selected:hover { background: rgba(226, 232, 0, 0.22); }
    .lead-matrix-month {
      flex: 1 1 auto;
      min-width: 0;
      max-width: 15rem;
    }
    .lead-matrix-toolbar-btn {
      flex-shrink: 0;
      font-size: 0.75rem;
      font-weight: 700;
      padding: 0.5rem 0.75rem;
      border-radius: 9999px;
      border: 1px solid var(--border);
      transition: background-color 0.15s ease, color 0.15s ease, border-color 0.15s ease;
    }
    .lead-matrix-toolbar-btn--active {
      background: #E2E800;
      color: #141414;
      border-color: #E2E800;
    }
    .lead-matrix-toolbar-btn:not(.lead-matrix-toolbar-btn--active) {
      background: var(--card);
      color: var(--muted);
    }
    @media (max-width: 1023px) {
      #page-leads .lead-matrix-toolbar-unified {
        flex-wrap: nowrap;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        margin-left: -1.25rem;
        margin-right: -1.25rem;
        padding-left: 1.25rem;
        padding-right: 1.25rem;
      }
      #page-leads .lead-matrix-month {
        flex: 0 0 auto;
        max-width: 9.5rem;
        min-width: 8.25rem;
      }
      #page-leads .lead-matrix-toolbar-info {
        display: none;
      }
      #page-leads .pl-matrix-pager-info {
        display: none;
      }
      /* Matrix: ตรึงคอลัมน์ชิดขอบจอ — ไม่ให้เซลล์โผล่ทางซ้ายตอนเลื่อน */
      #page-leads #lead-matrix-section .pl-matrix-wrap {
        margin-left: -1.25rem;
        margin-right: -1.25rem;
        width: calc(100% + 2.5rem);
        --pl-matrix-sticky-pad: 1.25rem;
        border-left: none;
        border-right: none;
        border-radius: 0;
      }
      #page-pipeline #pl-matrix-section .pl-matrix-wrap {
        margin-left: -1rem;
        margin-right: -1rem;
        width: calc(100% + 2rem);
        --pl-matrix-sticky-pad: 1rem;
        border-left: none;
        border-right: none;
        border-radius: 0;
      }
    }
    @media (min-width: 1024px) {
      #page-leads .lead-matrix-toolbar-unified {
        flex-wrap: wrap;
        gap: 0.5rem;
      }
      #page-leads .lead-matrix-month {
        flex: 0 0 auto;
      }
      #page-leads .lead-matrix-toolbar-month-actions {
        flex: 0 0 auto;
      }
      #page-leads .lead-filter-chips {
        flex-basis: 100%;
        order: 10;
        margin-top: 0.125rem;
      }
    }
    .pl-matrix-head-count {
      display: block;
      font-size: 0.5625rem;
      font-weight: 700;
      color: var(--muted);
      line-height: 1.25;
      text-align: left;
      white-space: normal;
    }
    @media (min-width: 1024px) {
      .pl-matrix-head-count { display: none; }
    }

    /* ป้ายสถานะบวก — เขียวเข้ม + ขอบชัด (อ่านได้แม้แยกสีไม่ออก) */
    .badge-status-yes {
      background-color: var(--status-yes-bg);
      color: var(--status-yes-text);
      border: 1px solid var(--status-yes-border);
    }
    .text-status-yes { color: var(--status-yes-text); }
    .border-status-yes { border-color: var(--status-yes-border); }
    .bg-status-yes { background-color: var(--status-yes-bg); }

    /* ปฏิทิน Tasks — ย่อเป็นสัปดาห์เดียวแบบ TickTick */
    #task-cal #cal-grid {
      transition: max-height 0.28s ease;
      max-height: 16.5rem;
    }
    #task-cal.task-cal--collapsed #cal-grid {
      max-height: 2.75rem;
    }
    #task-cal-grab {
      touch-action: none;
    }
    #task-cal-grab[aria-expanded="false"] .task-cal-grab-icon {
      transform: rotate(180deg);
    }
    .task-cal-grab-icon {
      transition: transform 0.2s ease;
    }

    /* From Uiverse.io by andrew-demchenk0 */
    .switch {
      font-size: 17px;
      position: relative;
      display: inline-block;
      width: 64px;
      height: 34px;
      transform: scale(0.78);
      transform-origin: center;
      flex-shrink: 0;
    }

    .switch input {
      opacity: 0;
      width: 0;
      height: 0;
    }

    .slider {
      position: absolute;
      cursor: pointer;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background-color: #73C0FC;
      transition: .4s;
      border-radius: 30px;
    }

    .slider:before {
      position: absolute;
      content: "";
      height: 30px;
      width: 30px;
      border-radius: 20px;
      left: 2px;
      bottom: 2px;
      z-index: 2;
      background-color: #e8e8e8;
      transition: .4s;
    }

    .sun svg {
      position: absolute;
      top: 6px;
      left: 36px;
      z-index: 1;
      width: 24px;
      height: 24px;
    }

    .moon svg {
      fill: #73C0FC;
      position: absolute;
      top: 5px;
      left: 5px;
      z-index: 1;
      width: 24px;
      height: 24px;
    }

    /* .switch:hover */.sun svg {
      animation: rotate 15s linear infinite;
    }

    @keyframes rotate {
      0% { transform: rotate(0); }
      100% { transform: rotate(360deg); }
    }

    /* .switch:hover */.moon svg {
      animation: tilt 5s linear infinite;
    }

    @keyframes tilt {
      0% { transform: rotate(0deg); }
      25% { transform: rotate(-10deg); }
      75% { transform: rotate(10deg); }
      100% { transform: rotate(0deg); }
    }

    .input:checked + .slider {
      background-color: #183153;
    }

    .input:focus + .slider {
      box-shadow: 0 0 1px #183153;
    }

    .input:checked + .slider:before {
      transform: translateX(30px);
    }

    /* Lead Pipeline Matrix — ตารางแบบชีท (Yes/Lose/Reject/Hold) */
    .pl-matrix-wrap {
      --pl-matrix-date-w: 4.25rem;
      --pl-matrix-lead-w: 10rem;
      --pl-matrix-sticky-pad: 0.625rem;
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
      overscroll-behavior-x: contain;
      border: 1px solid var(--border);
      border-radius: 0.75rem;
      background: var(--surface);
      padding: 0.5rem var(--pl-matrix-sticky-pad) 0.625rem 0;
      isolation: isolate;
    }
    .pl-matrix-table {
      width: 100%;
      border-collapse: separate;
      border-spacing: 0;
      font-size: 0.6875rem;
    }
    .pl-matrix-table th,
    .pl-matrix-table td {
      border-bottom: 1px solid var(--border);
      border-right: 1px solid var(--border);
      padding: 0;
      vertical-align: middle;
    }
    .pl-matrix-table th:last-child,
    .pl-matrix-table td:last-child { border-right: none; }
    .pl-matrix-table tbody tr:last-child td { border-bottom: none; }
    .pl-matrix-sticky-col {
      position: sticky;
      z-index: 2;
      background-color: var(--card);
      box-sizing: border-box;
      transform: translateZ(0);
      -webkit-backface-visibility: hidden;
      backface-visibility: hidden;
    }
    .pl-matrix-sticky-col--date {
      left: 0;
      z-index: 5;
      min-width: var(--pl-matrix-date-w);
      max-width: var(--pl-matrix-date-w);
      width: var(--pl-matrix-date-w);
      padding-left: var(--pl-matrix-sticky-pad);
      border-right: 1px solid var(--border);
    }
    .pl-matrix-sticky-col--lead {
      left: var(--pl-matrix-date-w);
      z-index: 4;
      min-width: var(--pl-matrix-lead-w);
      max-width: var(--pl-matrix-lead-w);
      width: var(--pl-matrix-lead-w);
      overflow: hidden;
      border-right: 2px solid var(--border-2);
    }
    .pl-matrix-summary-row .pl-matrix-sticky-col--lead {
      background-color: var(--chip-strong);
    }
    .pl-matrix-head-row .pl-matrix-sticky-col--lead {
      background-color: var(--card);
    }
    .pl-matrix-table tbody .pl-matrix-sticky-col--lead {
      background-color: var(--card);
    }
    .pl-matrix-summary-row th {
      background: var(--chip-strong);
      color: var(--text-2);
      font-weight: 700;
      padding: 0.5rem 0.5rem;
      text-align: center;
      white-space: nowrap;
    }
    .pl-matrix-summary-row .pl-matrix-sticky-col {
      background: var(--chip-strong);
    }
    .pl-matrix-head-row th {
      background: var(--card);
      color: var(--muted);
      font-weight: 700;
      padding: 0.5rem 0.5rem;
      text-align: center;
      white-space: nowrap;
    }
    .pl-matrix-head-row .pl-matrix-sticky-col {
      background: var(--card);
    }
    .pl-matrix-th-lead {
      text-align: left !important;
      padding: 0.5rem 0.75rem 0.5rem 0.375rem !important;
    }
    .pl-matrix-th-stage {
      padding-left: 0.375rem !important;
      padding-right: 0.375rem !important;
      position: relative;
      z-index: 0;
    }
    .pl-matrix-td-lead {
      padding: 0.375rem 0.75rem 0.375rem 0.375rem;
    }
    .pl-matrix-lead-btn {
      display: flex;
      flex-direction: column;
      align-items: flex-start;
      gap: 0.3125rem;
      width: 100%;
      max-width: 100%;
      overflow: hidden;
      text-align: left;
      padding: 0.3125rem 0.375rem;
      border-radius: 0.5rem;
      cursor: pointer;
      border: none;
      background: transparent;
      font: inherit;
      color: inherit;
    }
    .pl-matrix-lead-btn:active { opacity: 0.85; }
    .pl-matrix-lead-btn:focus-visible {
      outline: 2px solid var(--accent-text);
      outline-offset: 1px;
    }
    .pl-matrix-lead-name {
      display: block;
      margin: 0;
      font-weight: 700;
      font-size: 0.8125rem;
      line-height: 1.35;
      letter-spacing: -0.01em;
      color: var(--text);
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      max-width: 100%;
    }
    .pl-matrix-lead-chip {
      display: inline-block;
      max-width: 100%;
      padding: 0.125rem 0.4375rem;
      font-size: 0.625rem;
      font-weight: 600;
      line-height: 1.35;
      color: var(--muted);
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 0.375rem;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .pl-matrix-lead-chip--group {
      color: var(--accent-text);
      border-color: rgba(226, 232, 0, 0.45);
      background: rgba(226, 232, 0, 0.1);
      font-weight: 700;
    }
    .ld-aux-tag {
      display: inline-flex;
      align-items: center;
      gap: 0.25rem;
      padding: 0.125rem 0.4375rem;
      border-radius: 9999px;
      border: 1px solid var(--border-2);
      background: var(--surface);
      font-size: 0.625rem;
      font-weight: 700;
      color: var(--muted);
      vertical-align: middle;
      white-space: nowrap;
    }
    .ld-aux-tag--compact {
      font-size: 0.5625rem;
      padding: 0.0625rem 0.3125rem;
    }
    .ld-aux-tag-picker {
      display: flex;
      flex-wrap: wrap;
      gap: 0.5rem;
    }
    .ld-aux-tag-opt {
      cursor: pointer;
      margin: 0;
    }
    .ld-aux-tag-opt input {
      position: absolute;
      opacity: 0;
      width: 0;
      height: 0;
      pointer-events: none;
    }
    .ld-aux-tag-opt__inner {
      display: inline-flex;
      align-items: center;
      gap: 0.375rem;
      padding: 0.4375rem 0.6875rem;
      border-radius: 0.75rem;
      border: 1px solid var(--border);
      background: var(--card);
      font-size: 0.6875rem;
      font-weight: 700;
      color: var(--muted);
      transition: border-color 0.15s, background 0.15s;
    }
    .ld-aux-tag-opt input:checked + .ld-aux-tag-opt__inner {
      border-color: rgba(226, 232, 0, 0.55);
      background: rgba(226, 232, 0, 0.1);
      color: var(--accent-text);
    }
    .ld-aux-tag-opt:active .ld-aux-tag-opt__inner { transform: scale(0.98); }
    #ld-group-badge {
      cursor: pointer;
      transition: opacity 0.15s;
    }
    #ld-group-badge:active { opacity: 0.85; }
    #ld-group-badge[aria-expanded="true"] {
      background: rgba(226, 232, 0, 0.2);
      border-color: rgba(226, 232, 0, 0.65);
    }
    .ld-group-case { display: block; width: 100%; }
    .ld-group-case[aria-current="true"] { box-shadow: inset 0 0 0 1px rgba(226, 232, 0, 0.25); }
    .pl-matrix-td-date {
      padding: 0.375rem 0.3125rem;
      vertical-align: middle;
      text-align: center;
    }
    .pl-matrix-date-text {
      display: block;
      font-size: 0.625rem;
      font-weight: 600;
      line-height: 1.35;
      color: var(--muted);
      white-space: nowrap;
      tabular-nums;
      font-variant-numeric: tabular-nums;
    }
    .pl-matrix-th-date {
      padding-left: 0.25rem !important;
      padding-right: 0.25rem !important;
      text-align: center;
    }
    .pl-matrix-sort-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.1875rem;
      width: 100%;
      font: inherit;
      font-weight: 700;
      color: inherit;
      background: transparent;
      border: none;
      padding: 0;
      cursor: pointer;
    }
    .pl-matrix-sort-btn:active { opacity: 0.85; }
    .pl-matrix-sort-btn--active {
      color: var(--accent-text);
    }
    .pl-matrix-summary-date {
      color: var(--faint);
      font-weight: 600;
    }
    .pl-matrix-td-cell {
      padding: 0.25rem 0.375rem;
      position: relative;
      z-index: 0;
    }
    .pl-matrix-table th:last-child.pl-matrix-th-stage,
    .pl-matrix-table td:last-child.pl-matrix-td-cell {
      padding-right: 0.25rem;
    }
    .pl-matrix-cell {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.25rem;
      width: 100%;
      min-width: 3.75rem;
      min-height: 2.125rem;
      padding: 0.375rem 0.5rem;
      border-radius: 0.5rem;
      border: 1px solid var(--border);
      background: var(--card);
      font-weight: 700;
      font-size: 0.625rem;
      line-height: 1.1;
      white-space: nowrap;
      transition: border-color 0.15s, background 0.15s;
    }
    .pl-matrix-cell:active { transform: scale(0.97); }
    .pl-matrix-cell--yes {
      border-color: var(--status-yes-border);
      background: var(--status-yes-bg);
      color: var(--status-yes-text);
    }
    .pl-matrix-cell--lose {
      border-color: rgba(248, 113, 113, 0.45);
      background: rgba(248, 113, 113, 0.12);
      color: var(--text);
    }
    .pl-matrix-cell--reject {
      border-color: var(--border-2);
      background: var(--chip);
      color: var(--text-2);
    }
    .pl-matrix-cell--hold {
      border-color: rgba(251, 191, 36, 0.45);
      background: rgba(251, 191, 36, 0.1);
      color: var(--text);
    }
    .pl-matrix-cell--empty {
      border-style: dashed;
      color: var(--faint);
      font-weight: 500;
    }
    .pl-matrix-empty {
      text-align: center;
      padding: 2rem 1rem !important;
      color: var(--muted);
      font-size: 0.75rem;
    }
    .pl-matrix-pager {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem;
      padding: 0.625rem 0.75rem 0.25rem;
      flex-wrap: wrap;
    }
    .pl-matrix-pager-info {
      flex: 1;
      min-width: 0;
      text-align: center;
      font-size: 0.6875rem;
      font-weight: 600;
      color: var(--muted);
      line-height: 1.35;
    }
    .pl-matrix-pager-btn {
      display: inline-flex;
      align-items: center;
      gap: 0.375rem;
      padding: 0.5rem 0.875rem;
      border-radius: 0.75rem;
      border: 1px solid var(--border);
      background: var(--card);
      color: var(--text);
      font-size: 0.75rem;
      font-weight: 700;
      white-space: nowrap;
      transition: opacity 0.15s;
    }
    .pl-matrix-pager-btn:active:not(:disabled) { opacity: 0.85; }
    .pl-matrix-pager-btn:disabled {
      opacity: 0.38;
      cursor: not-allowed;
      color: var(--faint);
    }
    .matrix-picker-opt:active { transform: scale(0.98); }
    .matrix-picker-opt--selected {
      outline: 2px solid var(--accent-text);
      outline-offset: 1px;
    }
    .matrix-picker-opt--selected.matrix-picker-opt--yes {
      border-color: var(--status-yes-border) !important;
      background: var(--status-yes-bg) !important;
      color: var(--status-yes-text) !important;
    }
    .matrix-picker-opt--selected.matrix-picker-opt--lose { border-color: rgba(248, 113, 113, 0.55) !important; background: rgba(248, 113, 113, 0.08) !important; }
    .matrix-picker-opt--selected.matrix-picker-opt--reject { border-color: var(--border-2) !important; background: var(--surface) !important; }
    .matrix-picker-opt--selected.matrix-picker-opt--hold { border-color: rgba(251, 191, 36, 0.55) !important; background: rgba(251, 191, 36, 0.08) !important; }

    .ld-section-save {
      margin-top: 0.75rem;
      padding-top: 0.75rem;
      border-top: 1px solid var(--border);
    }
    .ld-section-save-btn {
      width: 100%;
      padding: 0.75rem 1rem;
      border-radius: 0.75rem;
      font-size: 0.875rem;
      font-weight: 700;
      transition: transform 0.1s ease, opacity 0.15s ease;
    }
    .ld-section-save-btn:active:not(:disabled) { transform: scale(0.98); }
    .ld-section-save-btn:disabled { opacity: 0.5; pointer-events: none; }
    .ld-section-save-btn--case {
      border: 1px solid var(--border);
      background: var(--card);
      color: var(--text-2);
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
    }
    .ld-section-save-btn--update {
      border: none;
      background: #E2E800;
      color: #141414;
    }
    .ld-case-view-val {
      font-size: 0.8125rem;
      line-height: 1.45;
      color: var(--text-2);
      font-weight: 500;
    }
    .ld-case-empty {
      color: var(--faint);
      font-style: italic;
      font-size: 0.75rem;
    }
    .ld-case-view-field {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 0.75rem;
      padding: 0.5rem 0.75rem;
    }
    #ld-case-edit-btn {
      padding: 0.375rem 0.75rem;
      border-radius: 0.625rem;
      border: 1px solid var(--border);
      background: var(--card);
      font-size: 0.6875rem;
      font-weight: 700;
      color: var(--muted);
      display: inline-flex;
      align-items: center;
      gap: 0.25rem;
    }
    #ld-case-edit-btn:active { transform: scale(0.97); }
    .ld-case-edit-actions {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 0.5rem;
      margin-top: 0.75rem;
      padding-top: 0.75rem;
      border-top: 1px solid var(--border);
    }
    #ld-case-cancel-btn {
      padding: 0.75rem 1rem;
      border-radius: 0.75rem;
      border: 1px solid var(--border);
      background: var(--card);
      font-size: 0.875rem;
      font-weight: 700;
      color: var(--text-2);
    }
    #matrix-picker-history-wrap ul li:last-child { border-bottom: none; padding-bottom: 0; }

    /* ===== Desktop layout (≥1024px) — mobile ยังใช้ max-w-md เหมือนเดิม ===== */
    @media (min-width: 1024px) {
      body {
        padding-left: 5.75rem;
      }

      #app-shell {
        max-width: 80rem;
        margin-left: auto;
        margin-right: auto;
        padding-left: 2rem;
        padding-right: 2rem;
        padding-bottom: 2rem;
      }

      #app-shell header {
        padding-top: 1.75rem;
        padding-bottom: 1.25rem;
      }

      .page {
        padding-left: 0 !important;
        padding-right: 0 !important;
      }

      /* Bottom nav → sidebar ซ้าย */
      #bottom-nav {
        top: 0;
        bottom: 0;
        left: 0;
        right: auto;
        width: 5.75rem;
        justify-content: flex-start;
        align-items: stretch;
      }
      #bottom-nav .bottom-nav-dock {
        max-width: none;
        height: 100%;
        border-top: none;
        border-right: 1px solid var(--border);
      }
      #bottom-nav-scroll {
        height: 100%;
      }
      #bottom-nav-track {
        flex-direction: column;
        justify-content: flex-start;
        align-items: stretch;
        height: 100%;
        gap: 0.35rem;
        padding: 1rem 0.5rem 1.25rem;
      }
      #bottom-nav-scroll .nav-btn {
        flex: 0 0 auto;
        flex-direction: column;
        width: 100%;
        min-width: 0;
        height: auto;
        min-height: 3.25rem;
        padding: 0.45rem 0.35rem;
        gap: 0.2rem;
      }
      #bottom-nav-scroll .nav-btn .nav-label {
        max-width: none;
        opacity: 1;
        font-size: 0.5625rem;
        line-height: 1.15;
        text-align: center;
        white-space: normal;
      }
      #bottom-nav-scroll .nav-btn.is-active {
        flex: 0 0 auto;
        padding: 0.45rem 0.35rem;
      }

      /* Sheet / modal บน desktop */
      .app-sheet--modal {
        top: 50% !important;
        bottom: auto !important;
        left: 50% !important;
        right: auto !important;
        transform: translate(-50%, -50%) !important;
        width: min(28rem, calc(100vw - 8rem)) !important;
        max-width: 28rem !important;
        max-height: min(85vh, 40rem) !important;
        border-radius: 1.25rem !important;
        border: 1px solid var(--border) !important;
        box-shadow: 0 24px 64px rgba(0, 0, 0, 0.45);
      }

      .app-sheet--drawer {
        top: 1rem !important;
        bottom: 1rem !important;
        left: auto !important;
        right: max(1rem, calc((100vw - 80rem) / 2 + 1rem)) !important;
        transform: none !important;
        width: min(26rem, 34vw) !important;
        max-width: 26rem !important;
        max-height: none !important;
        border-radius: 1.25rem !important;
        border: 1px solid var(--border) !important;
        box-shadow: 0 24px 64px rgba(0, 0, 0, 0.45);
      }

      /* Infowindow ทรัพย์/Lead — กว้าง กลางจอ บน desktop */
      .infowin-desktop-only { display: none !important; }
      #product-detail .app-sheet--infowin,
      #lead-detail .app-sheet--infowin {
        top: 50% !important;
        left: 50% !important;
        right: auto !important;
        bottom: auto !important;
        transform: translate(-50%, -50%) !important;
        width: min(58rem, calc(100vw - 6.5rem - 5.75rem)) !important;
        max-width: 58rem !important;
        height: min(92vh, 44rem) !important;
        max-height: 92vh !important;
        border-radius: 1.25rem !important;
        border: 1px solid var(--border) !important;
        box-shadow: 0 28px 72px rgba(0, 0, 0, 0.5);
      }
      #product-detail .infowin-header-title,
      #lead-detail .infowin-header-title {
        font-size: 1.125rem;
        line-height: 1.35;
      }
      .infowin-layout {
        display: grid;
        grid-template-columns: minmax(15rem, 20rem) minmax(0, 1fr);
        gap: 1.5rem;
        align-items: start;
      }
      .infowin-aside {
        position: sticky;
        top: 0;
      }
      .infowin-main {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1rem;
        align-content: start;
      }
      .infowin-main > .infowin-span-2 {
        grid-column: 1 / -1;
      }
      .infowin-desktop-only { display: block !important; }
      .infowin-desktop-only.inline-flex { display: inline-flex !important; }
      .infowin-desktop-only.flex { display: flex !important; }
      #pd-cover-wrap.infowin-cover-desktop {
        aspect-ratio: auto;
        max-height: 14rem;
      }
      #ld-cover-wrap.infowin-cover-desktop {
        aspect-ratio: auto;
        max-height: 14rem;
      }
      #lead-detail .ld-case-grid {
        display: grid;
        gap: 0.75rem;
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
      #lead-detail .ld-case-grid--wide {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
      @media (min-width: 1024px) {
        #lead-detail .ld-case-grid--wide {
          grid-template-columns: repeat(3, minmax(0, 1fr));
        }
      }
      #lead-detail .ld-case-grid .ld-case-span-2 {
        grid-column: span 2;
      }
      #lead-detail .ld-case-grid .ld-case-span-3,
      #lead-detail .ld-case-grid--wide .ld-case-span-3 {
        grid-column: 1 / -1;
      }
      #lead-detail .ld-case-field textarea.edit-inp {
        min-height: 4.5rem;
        font-size: 0.8125rem;
        line-height: 1.45;
      }
      #lead-detail .ld-case-field--potential select {
        font-weight: 700;
      }
      #lead-detail .ld-history-more-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.375rem;
        width: 100%;
        margin-top: 0.5rem;
        padding: 0.5rem 0.75rem;
        border-radius: 0.75rem;
        border: 1px solid var(--border);
        background: var(--surface);
        font-size: 0.6875rem;
        font-weight: 700;
        color: var(--muted);
      }
      #lead-detail .ld-history-more-btn:active { opacity: 0.85; }
      #lead-detail .ld-history-pager {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
        margin-top: 0.5rem;
      }
      #lead-detail .ld-history-pager-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        padding: 0.375rem 0.625rem;
        border-radius: 0.625rem;
        border: 1px solid var(--border);
        background: var(--card);
        font-size: 0.6875rem;
        font-weight: 700;
        color: var(--text-2);
        flex-shrink: 0;
      }
      #lead-detail .ld-history-pager-btn:disabled {
        opacity: 0.4;
        pointer-events: none;
      }
      #lead-detail .ld-history-pager-btn:active:not(:disabled) { opacity: 0.85; }
      #lead-detail .app-sheet--infowin #ld-action-bar.ld-action-bar--revive-only {
        display: block;
      }

      /* Home: กราฟ + งานคู่กันบน desktop */
      .desktop-home-split {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1.25rem;
      }

      /* รายการ 2 คอลัมน์ */
      .desktop-list-2col {
        display: grid !important;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1rem;
      }
      .desktop-list-2col > li {
        margin: 0 !important;
      }

      /* Home stat cards 4 คอลัมน์ */
      .desktop-stats-4 {
        grid-template-columns: repeat(4, minmax(0, 1fr));
      }

      /* Pipeline funnel 2 คอลัมน์ */
      .pipeline-stages-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.75rem;
      }
      .pipeline-stages-grid .pipeline-stage-arrow {
        display: none;
      }

      /* Tasks: ปฏิทินซ้าย รายการขวา (เฉพาะตอนแท็บ Tasks เปิดอยู่ — อย่า override .hidden) */
      #page-tasks:not(.hidden) {
        display: grid;
        grid-template-columns: minmax(18rem, 22rem) minmax(0, 1fr);
        column-gap: 2rem;
        row-gap: 1rem;
        align-items: start;
      }
      #page-tasks:not(.hidden) > .flex.items-center.justify-between {
        grid-column: 1 / -1;
      }
      #page-tasks:not(.hidden) > #task-cal {
        grid-column: 1;
        position: sticky;
        top: 1rem;
        align-self: start;
      }
      #page-tasks:not(.hidden) > #task-list-anchor {
        grid-column: 2;
      }
      #page-tasks:not(.hidden) > #task-page-hint {
        grid-column: 2;
      }

      #fab-add-task {
        right: max(1.5rem, calc((100vw - 80rem) / 2 + 1.5rem)) !important;
        bottom: 1.5rem !important;
      }

      #task-undo-toast {
        bottom: 1.5rem !important;
        left: 5.75rem !important;
        justify-content: center;
      }

      /* Lead detail form 3 คอลัมน์ */
      .ld-form-matrix-row {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0.75rem;
      }
      .ld-form-matrix-row > div {
        margin: 0;
      }

      #ld-mini-matrix {
        grid-template-columns: repeat(5, minmax(0, 1fr));
      }
      #lead-detail #ld-mini-matrix {
        grid-template-columns: repeat(5, minmax(0, 1fr));
      }

      /* Pipeline YTD 4 คอลัมน์บน desktop กว้าง */
      .pipeline-ytd-grid {
        grid-template-columns: repeat(4, minmax(0, 1fr));
      }

      /* Map + Report: เต็มพื้นที่ desktop */
      #app-shell.shell-full-bleed {
        max-width: none;
        width: auto;
        margin-left: 0;
        margin-right: 0;
        padding-left: 1rem;
        padding-right: 1rem;
        padding-bottom: 0.75rem;
        display: flex;
        flex-direction: column;
        min-height: 100vh;
      }

      #app-shell.shell-full-bleed header {
        padding-top: 1rem;
        padding-bottom: 0.75rem;
        padding-left: 0;
        padding-right: 0;
      }

      #page-map:not(.hidden),
      #page-report:not(.hidden) {
        position: relative;
        top: auto;
        bottom: auto;
        left: auto;
        right: auto;
        transform: none;
        width: 100%;
        max-width: none;
        flex: 1 1 auto;
        min-height: 0;
        height: auto !important;
        z-index: auto;
      }

      #page-report:not(.hidden) {
        max-width: 76rem;
        margin-left: auto;
        margin-right: auto;
      }

      #page-report:not(.hidden) #price-report-frame {
        border-radius: 1rem;
        border: 1px solid var(--border);
      }

      /* แผนที่ desktop: กรอง | แผนที่ | รายละเอียด (3 คอลัมน์) */
      #page-map:not(.hidden) {
        display: grid;
        grid-template-columns: minmax(14.5rem, 16.5rem) minmax(0, 1fr) minmax(18rem, 22rem);
        grid-template-rows: minmax(0, 1fr) auto auto;
        column-gap: 0.75rem;
        row-gap: 0.35rem;
        align-items: stretch;
        min-height: 0;
        height: calc(100vh - var(--dash-header-h) - 1.25rem);
      }

      #page-map:not(.hidden) > #map-toolbar {
        grid-column: 1;
        grid-row: 1;
        overflow-y: auto;
        align-self: stretch;
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 1rem;
        padding: 0.875rem 0.75rem;
      }

      #page-map:not(.hidden) > #map-stage {
        display: contents;
      }

      #page-map:not(.hidden) #map-toolbar .map-chip {
        flex: none;
        width: 100%;
        min-width: 0;
      }

      #page-map:not(.hidden) #map-toolbar > div.flex.gap-2.flex-wrap {
        flex-direction: column;
      }

      #page-map:not(.hidden) #map-canvas-wrap {
        grid-column: 2;
        grid-row: 1;
        position: relative;
        min-height: 0 !important;
        height: auto !important;
        flex: none !important;
        border: 1px solid var(--border);
        border-radius: 1rem 0 0 1rem;
        border-right: none;
        overflow: hidden;
      }

      #page-map:not(.hidden) #map-detail-sheet {
        grid-column: 3;
        grid-row: 1;
        display: flex;
        flex-direction: column;
        min-height: 0;
        height: auto;
        flex: none !important;
        max-width: none;
        width: auto;
        border: 1px solid var(--border);
        border-radius: 0 1rem 1rem 0;
        background: var(--card);
      }

      #page-map:not(.hidden) #map-detail-sheet[data-state="idle"] #map-detail-scroll {
        flex: 1 1 auto;
      }

      #page-map:not(.hidden) #map-sheet-handle {
        display: none;
      }

      #page-map:not(.hidden) #map-detail-scroll {
        flex: 1 1 auto;
        min-height: 0;
      }

      #page-map:not(.hidden) > #map-load-err,
      #page-map:not(.hidden) > #map-api-hint {
        grid-column: 1 / -1;
      }

      #page-map:not(.hidden) #map-toolbar h1 {
        font-size: 1.125rem;
      }

      #page-map:not(.hidden) #map-search {
        font-size: 0.8125rem;
        padding-top: 0.625rem;
        padding-bottom: 0.625rem;
      }

      #page-map:not(.hidden) #map-detail-empty {
        padding-top: 2.5rem;
        padding-bottom: 2.5rem;
      }

      /* Pipeline matrix — desktop กว้างขึ้น */
      .pl-matrix-wrap { --pl-matrix-date-w: 4.5rem; --pl-matrix-lead-w: 13rem; }
      .pl-matrix-lead-name { font-size: 0.875rem; }
      .pl-matrix-lead-chip { font-size: 0.6875rem; max-width: 12.5rem; }
      .pl-matrix-th-lead { padding: 0.625rem 1rem 0.625rem 0.5rem !important; }
      .pl-matrix-td-lead { padding: 0.5rem 1rem 0.5rem 0.5rem; }
      .pl-matrix-td-cell { padding: 0.3125rem 0.4375rem; }
      .pl-matrix-cell { min-width: 4.75rem; min-height: 2.375rem; font-size: 0.6875rem; padding: 0.4375rem 0.5625rem; }
      #page-pipeline #pl-matrix-section { margin-top: 0.5rem; }
      #lead-matrix-root,
      #pl-matrix-root { margin-top: 0.125rem; }
    }
  </style>
</head>
<body class="min-h-screen bg-[var(--bg)] text-[var(--text)]">

<div id="app-shell" class="max-w-md lg:max-w-none mx-auto min-h-screen pb-24 lg:pb-8">

  <!-- ===== Header ===== -->
  <header class="relative z-20 px-5 pt-6 pb-4 flex items-center gap-3">
    <a href="<?php echo htmlspecialchars($profile_url); ?>" id="header-profile-link" target="_top"
       class="flex items-center gap-3 min-w-0 flex-1 active:opacity-80 transition group"
       title="โปรไฟล &amp; แพ็กเกจ" aria-label="เปิดหน้าโปรไฟลและแพ็กเกจ">
      <?php if ($picture_url !== ''): ?>
        <img src="<?php echo htmlspecialchars($picture_url); ?>" class="w-11 h-11 rounded-full object-cover border border-[var(--border)] shrink-0 pointer-events-none" alt="">
      <?php else: ?>
        <div class="w-11 h-11 rounded-full bg-[var(--surface)] border border-[var(--border)] flex items-center justify-center shrink-0 pointer-events-none">
          <i data-lucide="user" class="w-5 h-5 text-[var(--muted)]"></i>
        </div>
      <?php endif; ?>
      <div class="min-w-0 flex-1">
        <p class="text-xs text-[var(--muted)]">ยินดีต้อนรับกลับมา</p>
        <p class="font-bold truncate"><?php echo htmlspecialchars($display_name); ?></p>
        <p class="text-[10px] text-[var(--faint)]">โปรไฟล · แพ็กเกจ</p>
      </div>
      <i data-lucide="chevron-right" class="w-4 h-4 text-[var(--faint)] shrink-0 group-hover:text-[var(--muted)]"></i>
    </a>
    <a href="<?php echo htmlspecialchars($profile_url); ?>" target="_top"
       class="text-[10px] font-bold px-2.5 py-1 rounded-full shrink-0 active:opacity-80 transition <?php echo $plan_badge === 'Pro' ? 'bg-[#E2E800]/15 text-[var(--accent-text)]' : 'bg-[var(--surface)] text-[var(--muted)] border border-[var(--border)]'; ?>"
       title="ดูแพ็กเกจ">
      <?php echo $plan_badge; ?><?php echo $trial_text !== '' ? ' · ' . htmlspecialchars($trial_text) : ''; ?>
    </a>

    <!-- สวิตช์สลับธีม Dark/Light (From Uiverse.io by andrew-demchenk0) -->
    <label class="switch" title="สลับธีม Dark / Light">
      <span class="sun">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
          <g fill="#ffd43b">
            <circle r="5" cy="12" cx="12"></circle>
            <path d="m21 13h-1a1 1 0 0 1 0-2h1a1 1 0 0 1 0 2zm-17 0h-1a1 1 0 0 1 0-2h1a1 1 0 0 1 0 2zm13.66-5.66a1 1 0 0 1 -.66-.29 1 1 0 0 1 0-1.41l.71-.71a1 1 0 1 1 1.41 1.41l-.71.71a1 1 0 0 1 -.75.29zm-12.02 12.02a1 1 0 0 1 -.71-.29 1 1 0 0 1 0-1.41l.71-.66a1 1 0 0 1 1.41 1.41l-.71.71a1 1 0 0 1 -.7.24zm6.36-14.36a1 1 0 0 1 -1-1v-1a1 1 0 0 1 2 0v1a1 1 0 0 1 -1 1zm0 17a1 1 0 0 1 -1-1v-1a1 1 0 0 1 2 0v1a1 1 0 0 1 -1 1zm-5.66-14.66a1 1 0 0 1 -.7-.29l-.71-.71a1 1 0 0 1 1.41-1.41l.71.71a1 1 0 0 1 0 1.41 1 1 0 0 1 -.71.29zm12.02 12.02a1 1 0 0 1 -.7-.29l-.66-.71a1 1 0 0 1 1.36-1.36l.71.71a1 1 0 0 1 0 1.41 1 1 0 0 1 -.71.24z"></path>
          </g>
        </svg>
      </span>
      <span class="moon">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 384 512">
          <path d="m223.5 32c-123.5 0-223.5 100.3-223.5 224s100 224 223.5 224c60.6 0 115.5-24.2 155.8-63.4 5-4.9 6.3-12.5 3.1-18.7s-10.1-9.7-17-8.5c-9.8 1.7-19.8 2.6-30.1 2.6-96.9 0-175.5-78.8-175.5-176 0-65.8 36-123.1 89.3-153.3 6.1-3.5 9.2-10.5 7.7-17.3s-7.3-11.9-14.3-12.5c-6.3-.5-12.6-.8-19-.8z"></path>
        </svg>
      </span>
      <input id="theme-toggle" type="checkbox" class="input">
      <span class="slider"></span>
    </label>
  </header>

  <!-- ============================================================ -->
  <!-- หน้า 1 : HOME                                                -->
  <!-- ============================================================ -->
  <section id="page-home" class="page px-5 space-y-5">

    <?php if ($is_metal_sheet): ?>
    <!-- หน้าหลัก — สาขาเมทัลชีท -->
    <button type="button" onclick="switchTab('report')" class="w-full text-left bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4 active:scale-[0.98] transition">
      <div class="flex items-center justify-between mb-2">
        <span class="text-xs text-[var(--muted)] flex items-center gap-1.5">
          <i data-lucide="file-text" class="w-3.5 h-3.5 text-[var(--accent-text)]"></i>รายงานวันนี้
        </span>
        <span class="text-[11px] text-[var(--accent-text)] font-bold">เปิดรายงาน →</span>
      </div>
      <p class="text-2xl font-bold">฿<?php echo number_format($ms_today_deposit, 0); ?></p>
      <p class="text-[11px] text-[var(--faint)] mt-1">มัดจำวันนี้ · <?php echo branch_thai_short_date($ms_report_date); ?></p>
      <div class="mt-3 pt-3 border-t border-[var(--border)] grid grid-cols-2 gap-2 text-[11px]">
        <p class="text-[var(--faint)]">มัดจำเดือนนี้<br><b class="text-[var(--text-2)] text-sm">฿<?php echo number_format($ms_month['deposit_total'], 0); ?></b></p>
        <p class="text-[var(--faint)]">ส่งมอบเดือนนี้<br><b class="text-[var(--text-2)] text-sm"><?php echo (int)$ms_month['delivery_count']; ?> คน · ฿<?php echo number_format($ms_month['delivery_total'], 0); ?></b></p>
      </div>
    </button>

    <div class="grid grid-cols-2 gap-3 desktop-stats-4">
      <button type="button" onclick="switchTab('leads')" class="text-left bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4 active:scale-[0.98] transition">
        <div class="flex items-center justify-between mb-2">
          <span class="text-xs text-[var(--muted)]">Lead</span>
          <i data-lucide="users" class="w-4 h-4 text-[var(--faint)]"></i>
        </div>
        <p class="text-3xl font-bold"><?php echo $counts['leads']; ?></p>
        <p class="text-[11px] text-[var(--faint)] mt-1">ลูกค้าในระบบ</p>
      </button>
      <button type="button" onclick="switchTab('tasks')" class="text-left bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4 active:scale-[0.98] transition">
        <div class="flex items-center justify-between mb-2">
          <span class="text-xs text-[var(--muted)]">งานค้าง</span>
          <i data-lucide="list-todo" class="w-4 h-4 text-[var(--faint)]"></i>
        </div>
        <p class="text-3xl font-bold text-[var(--accent-text)]"><?php echo $counts['tasks_pending']; ?></p>
        <p class="text-[11px] text-[var(--faint)] mt-1">รายการที่ยังไม่เสร็จ</p>
      </button>
    </div>

    <?php else: ?>

    <!-- เป้ารายได้เดือนนี้ -->
    <button type="button" onclick="switchTab('pipeline')" class="w-full text-left bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4 active:scale-[0.98] transition">
      <div class="flex items-center justify-between mb-2">
        <span class="text-xs text-[var(--muted)] flex items-center gap-1.5">
          <i data-lucide="target" class="w-3.5 h-3.5 text-[var(--accent-text)]"></i>เป้า <?php echo $pipeline_month_label; ?>
        </span>
        <span class="text-[11px] text-[var(--accent-text)] font-bold">ดูทั้งหมด →</span>
      </div>
      <p class="text-2xl font-bold">฿<?php echo number_format($target_revenue); ?></p>
      <div class="h-1.5 rounded-full bg-[var(--surface)] overflow-hidden mt-2 mb-1.5">
        <div class="h-full bg-[#E2E800] rounded-full" style="width:<?php echo $revenue_pct; ?>%"></div>
      </div>
      <p class="text-[11px] text-[var(--faint)]">
        ทำได้แล้ว <b class="text-[var(--text-2)]">฿<?php echo number_format($actual_revenue); ?></b>
        · <?php echo $revenue_pct; ?>% ของเป้า
      </p>
      <div class="mt-3 pt-3 border-t border-[var(--border)]">
        <p class="text-[11px] text-[var(--faint)] flex items-center gap-1">
          <i data-lucide="calendar-range" class="w-3 h-3"></i> สะสมปี <?php echo $ytd_year; ?>
        </p>
        <p class="text-sm font-bold mt-1 flex flex-wrap items-center gap-x-1.5">
          <span>Comm. <span class="text-[var(--accent-text)]">฿<?php echo number_format($ytd_commission); ?></span></span>
          <span class="text-[var(--faint)] font-normal">·</span>
          <span class="inline-flex items-center gap-1">
            <i data-lucide="trophy" class="w-3 h-3 shrink-0"></i> Win <?php echo $ytd_win_count; ?> ดีล
          </span>
        </p>
        <?php if ($ytd_gmv > 0): ?>
        <p class="text-[10px] text-[var(--faint)] mt-0.5">มูลค่าปิดดีล ฿<?php echo number_format((int)round($ytd_gmv)); ?></p>
        <?php endif; ?>
      </div>
    </button>

    <!-- Stat cards 2x2 — กดการ์ดหรือ "ดูทั้งหมด" ไปหน้านั้นๆ -->
    <div class="grid grid-cols-2 gap-3 desktop-stats-4">
      <button type="button" onclick="switchTab('products')" class="text-left bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4 active:scale-[0.98] transition">
        <div class="flex items-center justify-between mb-2">
          <span class="text-xs text-[var(--muted)]">Product</span>
          <i data-lucide="building-2" class="w-4 h-4 text-[var(--faint)]"></i>
        </div>
        <p class="text-3xl font-bold"><?php echo $counts['owners']; ?></p>
        <p class="text-[11px] text-[var(--faint)] mt-1">ทรัพย์ในลิสต์ทั้งหมด</p>
        <p class="text-[10px] text-[var(--accent-text)] font-bold mt-2 text-right">ดูทั้งหมด →</p>
      </button>
      <button type="button" onclick="switchTab('leads')" class="text-left bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4 active:scale-[0.98] transition">
        <div class="flex items-center justify-between mb-2">
          <span class="text-xs text-[var(--muted)]">Lead</span>
          <i data-lucide="users" class="w-4 h-4 text-[var(--faint)]"></i>
        </div>
        <p class="text-3xl font-bold"><?php echo $counts['leads']; ?></p>
        <p class="text-[11px] text-[var(--faint)] mt-1">ลูกค้าในระบบทั้งหมด</p>
        <p class="text-[10px] text-[var(--accent-text)] font-bold mt-2 text-right">ดูทั้งหมด →</p>
      </button>
      <button type="button" onclick="switchTab('tasks')" class="text-left bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4 active:scale-[0.98] transition">
        <div class="flex items-center justify-between mb-2">
          <span class="text-xs text-[var(--muted)]">งานค้าง</span>
          <i data-lucide="list-todo" class="w-4 h-4 text-[var(--faint)]"></i>
        </div>
        <p class="text-3xl font-bold text-[var(--accent-text)]"><?php echo $counts['tasks_pending']; ?></p>
        <p class="text-[11px] text-[var(--faint)] mt-1">รายการที่ยังไม่เสร็จ</p>
        <p class="text-[10px] text-[var(--accent-text)] font-bold mt-2 text-right">ดูทั้งหมด →</p>
      </button>
      <button type="button" onclick="switchTab('pipeline')" class="text-left bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4 active:scale-[0.98] transition">
        <div class="flex items-center justify-between mb-2">
          <span class="text-xs text-[var(--muted)]">ปิดดีลแล้ว</span>
          <i data-lucide="trophy" class="w-4 h-4 text-[var(--faint)]"></i>
        </div>
        <p class="text-3xl font-bold text-status-yes"><?php echo $counts['wins']; ?></p>
        <p class="text-[11px] text-[var(--faint)] mt-1">ดีลสถานะ Win</p>
        <p class="text-[10px] text-[var(--accent-text)] font-bold mt-2 text-right">ดูทั้งหมด →</p>
      </button>
    </div>

    <!-- กราฟ Pipeline + งานใกล้ถึงกำหนด (desktop: คู่กัน) -->
    <div class="desktop-home-split space-y-5">
    <!-- กราฟ Pipeline -->
    <div role="button" tabindex="0" onclick="switchTab('leads')"
         onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();switchTab('leads')}"
         class="bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4 active:scale-[0.99] transition cursor-pointer">
      <div class="flex items-center justify-between mb-3">
        <h2 class="text-sm font-bold">Lead Pipeline</h2>
        <span class="text-[11px] text-[var(--accent-text)] font-bold">ดูทั้งหมด →</span>
      </div>
      <?php if ($counts['leads'] > 0): ?>
        <div class="h-40"><canvas id="pipelineChart"></canvas></div>
      <?php else: ?>
        <p class="text-xs text-[var(--faint)] text-center py-8">ยังไม่มี Lead ในระบบ — ส่ง Lead แรกผ่านแชท LINE ได้เลย</p>
      <?php endif; ?>
    </div>

    <!-- Tasks ใกล้ถึงกำหนด -->
    <div role="button" tabindex="0" onclick="switchTab('tasks')"
         onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();switchTab('tasks')}"
         class="bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4 active:scale-[0.99] transition cursor-pointer">
      <div class="flex items-center justify-between mb-3">
        <h2 class="text-sm font-bold">งานที่ใกล้ถึงกำหนด</h2>
        <span class="text-[11px] text-[var(--accent-text)] font-bold">ดูทั้งหมด →</span>
      </div>
      <?php if (count($home_tasks) === 0): ?>
        <p class="text-xs text-[var(--faint)] text-center py-4">ไม่มีงานค้าง เคลียร์หมดแล้ว 🎉</p>
      <?php else: ?>
        <ul class="space-y-2.5">
          <?php foreach ($home_tasks as $t):
            [$label, $tone] = due_label($t['due_date']);
            $tone_class = $tone === 'red' ? 'text-red-400' : ($tone === 'lime' ? 'text-[var(--accent-text)]' : 'text-[var(--muted)]');
          ?>
          <li class="flex items-center gap-3 bg-[var(--surface)] rounded-xl px-3.5 py-3">
            <span class="w-1.5 h-1.5 rounded-full shrink-0 <?php echo $tone === 'red' ? 'bg-red-400' : 'bg-[#E2E800]'; ?>"></span>
            <div class="flex-1 min-w-0">
              <p class="text-sm truncate"><?php echo htmlspecialchars(lead_title_for_display(dec($t['title_enc'], $key))); ?></p>
              <?php if (!empty($t['lead_code']) || !empty($t['owner_code'])): ?>
                <p class="text-[11px] text-[var(--faint)]"><?php echo htmlspecialchars(lead_code_for_display($t['lead_code'] ?: '') ?: ($t['owner_code'] ?? '')); ?></p>
              <?php endif; ?>
            </div>
            <div class="text-right shrink-0">
              <p class="text-[11px] font-bold <?php echo $tone_class; ?>"><?php echo $label; ?></p>
              <p class="text-[10px] text-[var(--faint)]"><?php echo thai_short_date($t['due_date']); ?></p>
            </div>
          </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>

    </div>

    <!-- Leads ที่ค้างไม่ได้ Follow -->
    <div role="button" tabindex="0" onclick="switchTab('leads')"
         onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();switchTab('leads')}"
         class="bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4 active:scale-[0.99] transition cursor-pointer">
      <div class="flex items-center justify-between mb-3">
        <h2 class="text-sm font-bold">Lead ที่ไม่ได้อัปเดตนานสุด</h2>
        <span class="text-[11px] text-[var(--accent-text)] font-bold">ดูทั้งหมด →</span>
      </div>
      <?php if (count($stale_leads) === 0): ?>
        <p class="text-xs text-[var(--faint)] text-center py-4">ยังไม่มี Lead ที่ต้องตาม</p>
      <?php else: ?>
        <ul class="space-y-2.5">
          <?php foreach ($stale_leads as $l):
            $days_stale = max(0, (int)floor((time() - strtotime($l['updated_at'])) / 86400));
            $name = dec($l['lead_name_enc'], $key) ?: $l['lead_code'];
            $owner_code = $l['owner_code'] ?? '';
          ?>
          <li class="flex items-center gap-3 bg-[var(--surface)] rounded-xl px-3.5 py-3">
            <div class="w-9 h-9 rounded-full bg-[var(--chip)] flex items-center justify-center shrink-0 text-xs font-bold text-[var(--text-2)]">
              <?php echo htmlspecialchars(mb_substr($name, 0, 1, 'UTF-8')); ?>
            </div>
            <div class="flex-1 min-w-0">
              <p class="text-sm font-bold truncate"><?php echo htmlspecialchars($name); ?></p>
              <p class="text-[11px] text-[var(--muted)] truncate">
                <?php if ($owner_code !== ''): ?><span class="font-mono font-bold text-[var(--text-2)]"><?php echo htmlspecialchars($owner_code); ?></span> · <?php endif; ?>
                <?php echo htmlspecialchars($l['status']); ?>
              </p>
            </div>
            <span class="text-[11px] font-bold shrink-0 <?php echo $days_stale >= 7 ? 'text-red-400' : 'text-[var(--muted)]'; ?>">
              <?php echo $days_stale === 0 ? 'วันนี้' : "เงียบ {$days_stale} วัน"; ?>
            </span>
          </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>

    <?php endif; ?>

  </section>

  <!-- ============================================================ -->
  <!-- หน้า 2 : PRODUCT (Owners)                                    -->
  <!-- ============================================================ -->
  <section id="page-products" class="page px-5 space-y-4 hidden">
    <div class="flex items-center justify-between">
      <h1 class="text-xl font-bold">Product</h1>
      <span class="text-xs text-[var(--muted)]"><?php echo count($owners); ?> รายการ</span>
    </div>

    <div class="relative">
      <i data-lucide="search" class="w-4 h-4 text-[var(--faint)] absolute left-3.5 top-1/2 -translate-y-1/2"></i>
      <input id="search-products" type="text" placeholder="ค้นหาทรัพย์ / โครงการ / โซน..."
             class="w-full pl-10 pr-4 py-2.5 bg-[var(--card)] border border-[var(--border)] rounded-xl text-sm placeholder-[var(--faint)] focus:outline-none focus:ring-2 focus:ring-[#E2E800]"
             oninput="applyProductFilters()">
    </div>

    <!-- ตัวกรองเกรดความรีบ -->
    <div class="flex gap-2 overflow-x-auto no-scrollbar -mx-5 px-5 pb-1">
      <?php
      $grade_filters = [
          'ทั้งหมด' => '',
          'A·Hot'   => 'A',
          'B·Warm'  => 'B',
          'C·Cold'  => 'C',
      ];
      $gf_first = true;
      foreach ($grade_filters as $label => $grade):
          $gmeta = $grade !== '' ? urgency_meta($grade) : null;
      ?>
      <button type="button" onclick="filterProductGrade(this, '<?php echo $grade; ?>')"
              class="product-grade-filter shrink-0 text-xs font-bold px-3.5 py-1.5 rounded-full border transition inline-flex items-center gap-1.5 <?php echo $gf_first ? 'bg-[#E2E800] text-[#141414] border-[#E2E800]' : 'bg-[var(--card)] text-[var(--muted)] border-[var(--border)]'; ?>">
        <?php if ($gmeta): ?><i data-lucide="<?php echo $gmeta['icon']; ?>" class="w-3.5 h-3.5"></i><?php endif; ?>
        <?php echo htmlspecialchars($label); ?>
      </button>
      <?php $gf_first = false; endforeach; ?>
    </div>

    <?php if (count($owners) === 0): ?>
      <div class="text-center py-16">
        <i data-lucide="building-2" class="w-10 h-10 text-[var(--border-2)] mx-auto mb-3"></i>
        <p class="text-sm text-[var(--muted)]">ยังไม่มีทรัพย์ในระบบ</p>
        <p class="text-xs text-[var(--faint)] mt-1">เพิ่มทรัพย์ใหม่ได้ผ่านแชท LINE</p>
      </div>
    <?php endif; ?>

    <ul id="list-products" class="space-y-3 desktop-list-2col">
      <?php foreach ($owners as $o):
        $d = $owner_details_map[$o['id']];
        $search_blob = strtolower($o['code_list'] . ' ' . $d['name_en'] . ' ' . $d['name_th'] . ' ' . $d['property_type'] . ' ' . $d['zone'] . ' ' . $d['owner_name']);
        $st_card = $d['status_card'];
      ?>
      <li class="bg-[var(--card)] border border-[var(--border)] rounded-2xl p-3 flex gap-3 cursor-pointer active:scale-[0.99] transition"
          data-search="<?php echo htmlspecialchars($search_blob); ?>"
          data-grade="<?php echo htmlspecialchars($d['urgency']); ?>"
          data-owner-id="<?php echo (int)$o['id']; ?>"
          onclick="openProductDetail(<?php echo (int)$o['id']; ?>)">
        <!-- รูปปก -->
        <div class="w-[92px] shrink-0 rounded-xl overflow-hidden bg-[var(--surface)] border border-[var(--border)] flex items-center justify-center self-center aspect-square">
          <?php if ($d['cover_url'] !== ''): ?>
            <img src="<?php echo htmlspecialchars($d['cover_url']); ?>" alt="" class="w-full h-full object-cover" loading="lazy"
                 onerror="this.style.display='none';this.nextElementSibling.classList.remove('hidden')">
            <div class="hidden flex flex-col items-center justify-center text-[var(--faint)]">
              <i data-lucide="image-off" class="w-6 h-6"></i>
            </div>
          <?php else: ?>
            <i data-lucide="building-2" class="w-8 h-8 text-[var(--faint)]"></i>
          <?php endif; ?>
        </div>
        <!-- รายละเอียด — ซ้าย/ขวา กระจายเต็มการ์ด -->
        <div class="flex-1 min-w-0 flex flex-col justify-between gap-2 min-h-[92px]">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0 flex-1 space-y-0.5">
              <p class="font-bold text-sm truncate leading-tight"><?php echo htmlspecialchars($d['name_en'] ?: 'ไม่ระบุชื่อ EN'); ?></p>
              <?php if ($d['name_th'] !== ''): ?>
                <p class="text-[11px] text-[var(--muted)] truncate"><?php echo htmlspecialchars($d['name_th']); ?></p>
              <?php endif; ?>
              <p class="text-[10px] text-[var(--faint)] truncate pt-0.5">
                <span class="font-mono"><?php echo htmlspecialchars($d['code']); ?></span>
                <?php if ($d['owner_name'] !== ''): ?>
                  <span> · <?php echo htmlspecialchars($d['owner_name']); ?></span>
                <?php endif; ?>
              </p>
            </div>
            <span class="text-[10px] font-bold px-2 py-1 rounded-full shrink-0 inline-flex items-center gap-1 <?php echo htmlspecialchars($st_card['class']); ?>">
              <i data-lucide="<?php echo htmlspecialchars($st_card['icon']); ?>" class="w-3 h-3"></i><?php echo htmlspecialchars($st_card['label']); ?>
            </span>
          </div>
          <div class="flex items-end justify-between gap-3">
            <div class="min-w-0">
              <?php if ($d['price'] !== ''): ?>
                <p class="text-base font-bold leading-tight"><?php echo htmlspecialchars($d['price']); ?></p>
              <?php endif; ?>
              <?php if ($d['rent'] !== ''): ?>
                <p class="text-[11px] text-[var(--muted)] mt-0.5">เช่า <?php echo htmlspecialchars($d['rent']); ?></p>
              <?php endif; ?>
            </div>
            <div class="text-right shrink-0">
              <?php if ($d['last_updated'] !== ''): ?>
                <p class="text-xs font-bold text-[var(--text)] leading-tight whitespace-nowrap flex items-center justify-end gap-1">
                  <i data-lucide="clock" class="w-3.5 h-3.5 text-[var(--accent-text)]"></i>
                  <?php echo htmlspecialchars($d['elapsed_th']); ?>
                </p>
                <p class="text-[10px] text-[var(--faint)] mt-1 whitespace-nowrap">อัปเดต <?php echo htmlspecialchars($d['last_updated_fmt']); ?></p>
              <?php else: ?>
                <p class="text-[10px] text-[var(--faint)] whitespace-nowrap">ยังไม่มีวันอัปเดต</p>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>
  </section>

  <!-- ============================================================ -->
  <!-- หน้า 3 : LEAD                                                -->
  <!-- ============================================================ -->
  <section id="page-leads" class="page px-5 space-y-4 hidden">
    <div class="flex items-center justify-between gap-2">
      <h1 class="text-xl font-bold">Lead</h1>
      <span id="lead-count-label" class="hidden lg:inline text-xs text-[var(--muted)]"><?php echo count($leads); ?> รายการ</span>
    </div>

    <?php
    $thai_m_short = ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    $thai_m_full = ['','มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
    $lead_m_val = $lead_month_all ? date('Y-m') : ($lead_filter_month ?: date('Y-m'));
    [$lmy, $lmm] = array_map('intval', explode('-', $lead_m_val));
    $lead_m_display = $thai_m_full[$lmm] . ' ' . $lmy;
    ?>

    <input type="hidden" id="lead-month-value" value="<?php echo htmlspecialchars($lead_m_val); ?>">

    <!-- Bottom sheet เลือกเดือน -->
    <div id="lead-month-sheet" class="fixed inset-0 z-[70] hidden">
      <div id="lead-month-sheet-backdrop" class="absolute inset-0 bg-black/60"></div>
      <div class="app-sheet app-sheet--modal absolute inset-x-0 bottom-0 left-1/2 -translate-x-1/2 w-full max-w-md bg-[var(--bg)] border-t border-[var(--border)] rounded-t-3xl px-5 pt-4 pb-6 max-h-[70vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-sm font-bold">เลือกเดือน</h3>
          <button type="button" id="lead-month-sheet-close" class="w-8 h-8 rounded-full bg-[var(--card)] border border-[var(--border)] flex items-center justify-center">
            <i data-lucide="x" class="w-4 h-4"></i>
          </button>
        </div>
        <div class="flex items-center justify-between mb-4 bg-[var(--card)] border border-[var(--border)] rounded-xl px-3 py-2">
          <button type="button" id="lead-sheet-year-prev" class="w-9 h-9 flex items-center justify-center rounded-lg active:bg-[var(--surface)]">
            <i data-lucide="chevron-left" class="w-4 h-4"></i>
          </button>
          <span id="lead-sheet-year" class="text-sm font-bold tabular-nums"><?php echo $lmy; ?></span>
          <button type="button" id="lead-sheet-year-next" class="w-9 h-9 flex items-center justify-center rounded-lg active:bg-[var(--surface)]">
            <i data-lucide="chevron-right" class="w-4 h-4"></i>
          </button>
        </div>
        <div id="lead-month-grid" class="grid grid-cols-3 gap-2">
          <?php for ($mi = 1; $mi <= 12; $mi++): ?>
            <button type="button" class="lead-month-chip text-xs font-bold py-2.5 rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--muted)] transition"
                    data-month="<?php echo $mi; ?>">
              <?php echo $thai_m_short[$mi]; ?>
            </button>
          <?php endfor; ?>
        </div>
        <div class="flex gap-2 mt-4">
          <button type="button" id="lead-month-this" class="flex-1 text-xs font-bold py-2.5 rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--text-2)]">
            เดือนนี้
          </button>
          <button type="button" id="lead-month-clear" class="flex-1 text-xs font-bold py-2.5 rounded-xl border border-[var(--border)] bg-[var(--surface)] text-[var(--muted)]">
            ทั้งหมด
          </button>
        </div>
      </div>
    </div>

    <div class="relative">
      <i data-lucide="search" class="w-4 h-4 text-[var(--faint)] absolute left-3.5 top-1/2 -translate-y-1/2"></i>
      <input id="search-leads" type="text" placeholder="ค้นหาชื่อ / รหัส / โครงการ..."
             class="w-full pl-10 pr-4 py-2.5 bg-[var(--card)] border border-[var(--border)] rounded-xl text-sm placeholder-[var(--faint)] focus:outline-none focus:ring-2 focus:ring-[#E2E800]"
             oninput="applyLeadFilters()">
    </div>

    <!-- ตัวกรองสถานะ + เดือน (มือถือ: แถวเดียวเลื่อนได้) -->
    <?php
    $appointment_label = '<span class="sm:hidden">App...</span><span class="hidden sm:inline">Appointment</span>';
    $filters = [
        'ทั้งหมด'          => ['statuses' => '', 'count' => $chip_counts['all'], 'key' => 'all'],
        'Call'             => ['statuses' => 'Call', 'count' => $chip_counts['Call'], 'key' => 'Call'],
        'Follow'           => ['statuses' => 'Follow', 'count' => $chip_counts['Follow'], 'key' => 'Follow'],
        $appointment_label => ['statuses' => 'Appointment', 'count' => $chip_counts['Appointment'], 'key' => 'Appointment'],
        'Showing'          => ['statuses' => 'Show', 'count' => $chip_counts['Show'], 'key' => 'Show'],
        'Reserve'          => ['statuses' => 'Reserve', 'count' => $chip_counts['Reserve'], 'key' => 'Reserve'],
        'Nego'             => ['statuses' => 'Nego,Close,Bank', 'count' => $chip_counts['Nego'], 'key' => 'Nego'],
        'Win'              => ['statuses' => 'Win', 'count' => $chip_counts['Win'], 'key' => 'Win'],
        'Lose'             => ['statuses' => 'Lose', 'count' => $chip_counts['Lose'], 'key' => 'Lose'],
        'Reject'           => ['statuses' => 'Rejected,Hold_Reject', 'count' => $chip_counts['Reject'], 'key' => 'Reject'],
    ];
    $first_filter = true;
    ob_start();
    foreach ($filters as $label => $f): ?>
      <button type="button" onclick="filterLeadStatus(this, '<?php echo $f['statuses']; ?>')"
              data-statuses="<?php echo htmlspecialchars($f['statuses']); ?>"
              data-count-key="<?php echo htmlspecialchars($f['key']); ?>"
              class="lead-filter shrink-0 text-xs font-bold px-3.5 py-1.5 rounded-full border transition <?php echo $first_filter ? 'bg-[#E2E800] text-[#141414] border-[#E2E800]' : 'bg-[var(--card)] text-[var(--muted)] border-[var(--border)]'; ?>">
        <?php echo $label; ?>
        <span class="ml-1 opacity-80 tabular-nums">(<?php echo (int)$f['count']; ?>)</span>
      </button>
    <?php $first_filter = false; endforeach;
    $lead_filter_chips_html = ob_get_clean();
    ?>

    <div class="lead-matrix-toolbar space-y-2 mb-3">
      <div class="lead-matrix-toolbar-unified flex items-center gap-2 pb-1 no-scrollbar">
        <div class="lead-matrix-month flex items-center bg-[var(--card)] border border-[var(--border)] rounded-xl overflow-hidden shrink-0">
          <button type="button" id="lead-month-prev" aria-label="เดือนก่อนหน้า"
                  class="w-9 h-9 flex items-center justify-center text-[var(--muted)] active:bg-[var(--surface)] shrink-0">
            <i data-lucide="chevron-left" class="w-4 h-4"></i>
          </button>
          <button type="button" id="lead-month-open"
                  class="flex-1 min-w-0 py-2 px-1 text-xs font-bold text-center truncate active:bg-[var(--surface)]">
            <span id="lead-month-label" class="inline-flex items-center justify-center gap-1.5">
              <i data-lucide="calendar" class="w-3.5 h-3.5 text-[var(--accent-text)]"></i>
              <span id="lead-month-label-text"><?php echo htmlspecialchars($lead_m_display); ?></span>
            </span>
          </button>
          <button type="button" id="lead-month-next" aria-label="เดือนถัดไป"
                  class="w-9 h-9 flex items-center justify-center text-[var(--muted)] active:bg-[var(--surface)] shrink-0">
            <i data-lucide="chevron-right" class="w-4 h-4"></i>
          </button>
        </div>
        <div class="lead-matrix-toolbar-month-actions flex items-center gap-2 shrink-0">
        <button type="button" id="lead-month-this-toolbar" class="lead-matrix-toolbar-btn shrink-0">เดือนนี้</button>
        <button type="button" id="lead-month-all"
                class="lead-matrix-toolbar-btn shrink-0 <?php echo $lead_month_all ? 'lead-matrix-toolbar-btn--active' : ''; ?>">
          ทั้งหมด
        </button>
        </div>
        <div class="lead-filter-chips flex gap-2 shrink-0">
          <?php echo $lead_filter_chips_html; ?>
        </div>
        <span id="lead-matrix-count" class="hidden lg:inline lg:ml-auto text-[10px] font-bold text-[var(--muted)] shrink-0"><?php echo count($leads); ?> รายการ</span>
      </div>
      <p class="lead-matrix-toolbar-info text-[10px] text-[var(--faint)] flex items-center gap-1">
        <i data-lucide="info" class="w-3 h-3 shrink-0"></i>
        กรองเดือนอัปเดต · Win ใช้ win_date
      </p>
    </div>

    <?php if (count($leads) === 0): ?>
      <div class="text-center py-16">
        <i data-lucide="users" class="w-10 h-10 text-[var(--border-2)] mx-auto mb-3"></i>
        <p class="text-sm text-[var(--muted)]">ยังไม่มี Lead ในระบบ</p>
        <p class="text-xs text-[var(--faint)] mt-1">ส่ง Lead แรกผ่านแชท LINE ได้เลย</p>
      </div>
    <?php else: ?>
    <div id="lead-matrix-section" class="space-y-3">
      <p class="text-[10px] text-[var(--faint)] flex items-center gap-1">
        <i data-lucide="info" class="w-3 h-3 shrink-0"></i>
        กดเซลล์เลือก Yes / Lose / Reject / Hold · กดชื่อ Lead เปิดรายละเอียด
      </p>
      <div class="flex flex-wrap gap-2">
        <select id="lead-matrix-stage-filter" class="app-select app-select--compact" aria-label="กรองขั้นตอน">
          <option value="">ทุกขั้น</option>
          <?php foreach (lead_funnel_statuses() as $mst): ?>
          <option value="<?php echo htmlspecialchars($mst); ?>"><?php echo htmlspecialchars($pl_matrix_stage_labels[$mst] ?? $mst); ?></option>
          <?php endforeach; ?>
        </select>
        <select id="lead-matrix-outcome-filter" class="app-select app-select--compact" aria-label="กรองผลลัพธ์">
          <option value="">ทุกผล</option>
          <option value="yes">Yes</option>
          <option value="lose">Lose</option>
          <option value="reject">Reject</option>
          <option value="hold">Hold</option>
          <option value="__empty">ยังไม่กรอก</option>
        </select>
      </div>
      <div id="lead-matrix-root"></div>
      <p class="text-[10px] text-[var(--faint)] flex items-center gap-1 lg:hidden">
        <i data-lucide="smartphone" class="w-3 h-3 shrink-0"></i>
        เลื่อนซ้าย-ขวาดูคอลัมน์ · กดหัว วันที่ / Lead เพื่อเรียง
      </p>
    </div>
    <?php endif; ?>
  </section>

  <!-- ============================================================ -->
  <!-- หน้า 4 : TASKS (สไตล์ TickTick)                              -->
  <!-- ============================================================ -->
  <section id="page-tasks" class="page px-5 space-y-4 hidden">
    <div class="flex items-center justify-between gap-2">
      <h1 class="text-xl font-bold">Tasks</h1>
      <div class="flex items-center gap-2 shrink-0">
        <div class="flex items-center gap-0.5 bg-[var(--card)] border border-[var(--border)] rounded-xl p-0.5">
          <button type="button" id="task-undo-btn" class="task-undo-btn w-9 h-9 rounded-lg flex items-center justify-center text-[var(--muted)] active:bg-[var(--surface)] transition disabled:opacity-35"
                  title="ย้อนกลับ (Ctrl+Z)" aria-label="ย้อนกลับ">
            <i data-lucide="undo-2" class="w-4 h-4"></i>
          </button>
          <button type="button" id="task-redo-btn" class="task-redo-btn w-9 h-9 rounded-lg flex items-center justify-center text-[var(--muted)] active:bg-[var(--surface)] transition disabled:opacity-35"
                  title="ทำซ้ำ (Ctrl+Y)" aria-label="ทำซ้ำ">
            <i data-lucide="redo-2" class="w-4 h-4"></i>
          </button>
        </div>
        <span id="tasks-pending-count" class="text-xs text-[var(--muted)]"><?php echo $counts['tasks_pending']; ?> งานค้าง</span>
      </div>
    </div>

    <!-- ปฏิทินรายเดือน (ปัดลง / เลื่อนลง = ย่อเหลือสัปดาห์เดียว) -->
    <div id="task-cal" class="bg-[var(--card)] border border-[var(--border)] rounded-2xl overflow-hidden">
      <div class="flex items-center justify-between px-4 py-3 border-b border-[var(--border)] bg-[var(--surface)]/50">
        <div class="flex items-center gap-2 min-w-0">
          <i data-lucide="calendar-days" class="w-4 h-4 text-[var(--accent-text)] shrink-0"></i>
          <p id="cal-title" class="font-bold text-sm truncate"></p>
        </div>
        <div class="flex items-center gap-1 shrink-0">
          <button id="cal-prev" type="button" class="w-8 h-8 rounded-lg flex items-center justify-center text-[var(--muted)] hover:bg-[var(--card)] active:scale-95 transition">
            <i data-lucide="chevron-left" class="w-4 h-4"></i>
          </button>
          <button id="cal-today" type="button" class="text-[10px] font-bold px-2.5 py-1.5 rounded-lg bg-[#E2E800] text-[#141414] active:scale-95 transition">วันนี้</button>
          <button id="cal-next" type="button" class="w-8 h-8 rounded-lg flex items-center justify-center text-[var(--muted)] hover:bg-[var(--card)] active:scale-95 transition">
            <i data-lucide="chevron-right" class="w-4 h-4"></i>
          </button>
        </div>
      </div>
      <div id="task-cal-body" class="p-4 pt-3 pb-1">
        <div class="grid grid-cols-7 gap-1 mb-2">
          <?php foreach (['อา','จ','อ','พ','พฤ','ศ','ส'] as $wd): ?>
            <span class="text-center text-[10px] font-bold text-[var(--faint)] py-1"><?php echo $wd; ?>.</span>
          <?php endforeach; ?>
        </div>
        <div id="cal-grid" class="flex flex-col gap-y-1.5 overflow-hidden"></div>
      </div>
      <button type="button" id="task-cal-grab" class="w-full flex flex-col items-center py-2 text-[var(--faint)] active:bg-[var(--surface)] transition" aria-expanded="true" aria-label="ย่อหรือขยายปฏิทิน">
        <i data-lucide="chevrons-down" class="task-cal-grab-icon w-4 h-4"></i>
        <span class="text-[9px] mt-0.5">ปัดลงเพื่อย่อ</span>
      </button>
    </div>

    <!-- ปุ่มเพิ่มงานลอยมุมขวาล่าง (เหนือ bottom nav) -->
    <button id="fab-add-task" title="เพิ่มงานใหม่"
            class="fixed bottom-24 z-50 w-14 h-14 rounded-full bg-[#E2E800] text-[#141414] shadow-xl shadow-black/30 flex items-center justify-center active:scale-90 transition"
            style="right: max(1.25rem, calc(50vw - 14rem + 1.25rem));">
      <i data-lucide="plus" class="w-7 h-7"></i>
    </button>

    <!-- Bottom sheet ฟอร์มเพิ่มงาน -->
    <div id="task-sheet" class="fixed inset-0 z-[70] hidden">
      <div id="task-sheet-backdrop" class="absolute inset-0 bg-black/60"></div>
      <div class="app-sheet app-sheet--modal absolute bottom-0 left-1/2 -translate-x-1/2 w-full max-w-md bg-[var(--card)] border-t border-[var(--border)] rounded-t-3xl p-5 pb-[calc(1.5rem+env(safe-area-inset-bottom))]">
        <div class="w-10 h-1 rounded-full bg-[var(--border-2)] mx-auto mb-4"></div>
        <form id="add-task-form" class="space-y-4">
          <div class="relative flex items-center bg-[var(--surface)] border border-[var(--border)] rounded-xl focus-within:border-[#E2E800] transition">
            <input id="new-task-title" type="text" placeholder="จะทำอะไร..." autocomplete="off"
                   class="flex-1 min-w-0 bg-transparent border-0 text-sm px-3.5 py-3 pr-12 placeholder-[var(--faint)] focus:outline-none">
            <button type="button" id="add-task-submit" aria-label="เพิ่มงาน"
                    class="absolute right-1.5 w-9 h-9 rounded-lg flex items-center justify-center bg-[#E2E800] text-[#141414] active:scale-95 transition disabled:opacity-40"
                    disabled>
              <i data-lucide="arrow-right" class="w-4 h-4"></i>
            </button>
          </div>

          <!-- วันที่ (ไอคอน) + เวลาแจ้งเตือน (ไอคอน) -->
          <div class="flex items-center gap-2">
            <button type="button" id="pick-date-btn"
                    class="flex items-center gap-1.5 bg-[var(--surface)] border border-[var(--border)] rounded-xl px-3 py-2.5 active:border-[#E2E800] transition">
              <i data-lucide="calendar" class="w-4 h-4 text-[var(--accent-text)]"></i>
              <span id="pick-date-label" class="text-xs font-bold text-[var(--text-2)]">วันนี้</span>
            </button>
            <button type="button" id="pick-time-btn"
                    class="flex items-center gap-1.5 bg-[var(--surface)] border border-[var(--border)] rounded-xl px-3 py-2.5 active:border-[#E2E800] transition">
              <i data-lucide="clock" class="w-4 h-4 text-[var(--accent-text)]"></i>
              <span id="pick-time-label" class="text-xs font-bold text-[var(--faint)]">เวลาแจ้งเตือน</span>
            </button>
            <button type="button" id="clear-time-btn" class="hidden text-[var(--faint)] p-1.5" title="ล้างเวลา">
              <i data-lucide="x" class="w-3.5 h-3.5"></i>
            </button>
            <!-- input จริงซ่อนไว้ ใช้ showPicker() เปิดผ่านไอคอน (เวลาใช้ panel 24 ชม. ของเราเอง) -->
            <input id="new-task-date" type="hidden" value="<?php echo $today_str; ?>">
            <input id="new-task-time" type="hidden" value="">
          </div>

          <!-- แผงเลือกเวลาแบบ 24 ชม. (คนไทยไม่ถนัด AM/PM) -->
          <div id="time-panel" class="hidden items-center gap-2 bg-[var(--surface)] border border-[var(--border)] rounded-xl px-3 py-2.5">
            <i data-lucide="clock" class="w-4 h-4 shrink-0 text-[var(--accent-text)]"></i>
            <input id="time-hour" type="text" inputmode="numeric" maxlength="2" value="09" autocomplete="off"
                   class="w-12 text-center bg-[var(--card)] border border-[var(--border)] rounded-lg text-sm font-bold text-[var(--text)] px-2 py-1.5 focus:outline-none focus:border-[#E2E800]">
            <span class="text-sm font-bold text-[var(--muted)]">:</span>
            <input id="time-min" type="text" inputmode="numeric" maxlength="2" value="00" autocomplete="off"
                   class="w-12 text-center bg-[var(--card)] border border-[var(--border)] rounded-lg text-sm font-bold text-[var(--text)] px-2 py-1.5 focus:outline-none focus:border-[#E2E800]">
            <span class="text-xs text-[var(--faint)]">น.</span>
            <button type="button" id="time-ok" class="ml-auto bg-[#E2E800] text-[#141414] text-xs font-bold px-3 py-1.5 rounded-lg active:scale-95 transition">ตกลง</button>
          </div>

          <!-- ระดับความสำคัญ Eisenhower Matrix -->
          <div>
            <p class="text-[11px] font-bold text-[var(--muted)] mb-2 flex items-center gap-1">
              ระดับความสำคัญ (Eisenhower Matrix)
              <button type="button" class="tip-link text-[var(--faint)] hover:text-[var(--accent-text)] transition" data-tip="eisenhower" title="อันนี้คืออะไร?">
                <i data-lucide="help-circle" class="w-3.5 h-3.5"></i>
              </button>
            </p>
            <!-- แต่ละช่องมีไอคอน + ข้อความกำกับ ไม่พึ่งสีอย่างเดียว (เผื่อคนตาบอดสี) -->
            <div class="grid grid-cols-2 gap-2">
              <button type="button" data-priority="1" class="prio-chip flex items-center gap-2 text-left rounded-xl border border-[var(--border)] bg-[var(--surface)] px-3 py-2.5 transition">
                <i data-lucide="flame" class="w-4 h-4 shrink-0 text-red-400"></i>
                <span>
                  <span class="block text-xs font-bold text-red-400">ทำทันที</span>
                  <span class="block text-[10px] text-[var(--faint)]">ด่วน + สำคัญ</span>
                </span>
              </button>
              <button type="button" data-priority="2" class="prio-chip flex items-center gap-2 text-left rounded-xl border border-[var(--border)] bg-[var(--surface)] px-3 py-2.5 transition">
                <i data-lucide="calendar-check" class="w-4 h-4 shrink-0 text-[var(--accent-text)]"></i>
                <span>
                  <span class="block text-xs font-bold text-[var(--accent-text)]">วางแผนทำ</span>
                  <span class="block text-[10px] text-[var(--faint)]">สำคัญ ไม่ด่วน</span>
                </span>
              </button>
              <button type="button" data-priority="3" class="prio-chip flex items-center gap-2 text-left rounded-xl border border-[var(--border)] bg-[var(--surface)] px-3 py-2.5 transition">
                <i data-lucide="send" class="w-4 h-4 shrink-0 text-amber-500"></i>
                <span>
                  <span class="block text-xs font-bold text-amber-500">มอบหมาย</span>
                  <span class="block text-[10px] text-[var(--faint)]">ด่วน ไม่สำคัญ</span>
                </span>
              </button>
              <button type="button" data-priority="4" class="prio-chip flex items-center gap-2 text-left rounded-xl border border-[var(--border)] bg-[var(--surface)] px-3 py-2.5 transition">
                <i data-lucide="coffee" class="w-4 h-4 shrink-0 text-[var(--muted)]"></i>
                <span>
                  <span class="block text-xs font-bold text-[var(--muted)]">ทำทีหลัง</span>
                  <span class="block text-[10px] text-[var(--faint)]">ไม่ด่วน ไม่สำคัญ</span>
                </span>
              </button>
            </div>
            <input type="hidden" id="new-task-priority" value="0">
          </div>
        </form>
      </div>
    </div>

    <?php
    $prio_meta = [
        1 => ['ทำทันที',  'bg-red-500/15 text-red-400',                  'flame'],
        2 => ['วางแผนทำ', 'bg-[#E2E800]/15 text-[var(--accent-text)]',   'calendar-check'],
        3 => ['มอบหมาย',  'bg-amber-500/15 text-amber-500',              'send'],
        4 => ['ทำทีหลัง', 'bg-[var(--chip)] text-[var(--muted)]',        'coffee'],
    ];
    $has_any_task = count($all_tasks) > 0;
    ?>

    <div id="task-list-anchor">
    <?php if (!$has_any_task): ?>
      <div id="tasks-empty-state" class="text-center py-16">
        <i data-lucide="check-circle-2" class="w-10 h-10 text-[var(--border-2)] mx-auto mb-3"></i>
        <p class="text-sm text-[var(--muted)]">ยังไม่มีงานในระบบ</p>
        <p class="text-xs text-[var(--faint)] mt-1">กดปุ่ม + มุมขวาล่าง หรือสั่งเลขา AI ในแชท LINE</p>
      </div>
    <?php endif; ?>

    <?php
    $task_group_meta = [
        'overdue'  => ['label' => 'เกินกำหนด',   'tone' => 'text-red-400', 'icon' => 'alert-circle'],
        'today'    => ['label' => 'วันนี้',        'tone' => 'text-[var(--accent-text)]', 'icon' => 'sun'],
        'upcoming' => ['label' => 'กำลังจะถึง',   'tone' => 'text-[var(--text-2)]', 'icon' => 'calendar'],
        'no_date'  => ['label' => 'ยังไม่กำหนดวัน', 'tone' => 'text-[var(--muted)]', 'icon' => 'circle-dashed'],
    ];
    ?>

    <div id="task-groups-wrap" class="space-y-4">
    <?php foreach ($task_group_meta as $gkey => $gmeta): ?>
      <?php $gcount = count($task_time['groups'][$gkey]); ?>
      <div class="task-group<?php echo $gcount === 0 ? ' hidden' : ''; ?>" data-group="<?php echo $gkey; ?>">
        <p class="task-group-label text-xs font-bold mb-2 <?php echo $gmeta['tone']; ?> flex items-center gap-1.5">
          <i data-lucide="<?php echo $gmeta['icon']; ?>" class="w-3.5 h-3.5"></i>
          <?php echo $gmeta['label']; ?> · <span class="task-group-count"><?php echo task_count_tree($task_time['groups'][$gkey]); ?></span>
        </p>
        <ul class="space-y-2 task-group-list">
          <?php echo render_task_rows_html($task_time['groups'][$gkey], 0, $prio_meta, $today_str, ['overdue' => $gkey === 'overdue']); ?>
        </ul>
      </div>
    <?php endforeach; ?>
    </div>

    <?php if (count($task_time['done']) > 0): ?>
      <div id="task-done-group" class="task-group">
        <p class="text-xs font-bold mb-2 text-[var(--faint)] flex items-center gap-1.5">
          <i data-lucide="check-circle-2" class="w-3.5 h-3.5"></i>เสร็จแล้ว · <?php echo task_count_tree($task_time['done']); ?>
        </p>
        <ul class="space-y-2">
          <?php echo render_task_rows_html($task_time['done'], 0, $prio_meta, $today_str); ?>
        </ul>
      </div>
    <?php endif; ?>

    </div>

    <p id="task-page-hint" class="text-[10px] text-center text-[var(--faint)] pb-2">
      แตะงาน → รายละเอียด · ⋯ → ตัวเลือก · กดค้างลาก → ย้าย/เป็นงานย่อย
    </p>
  </section>

  <!-- เมนู ⋯ ตัวเลือกงาน (แบบ TickTick) -->
  <div id="task-context-menu" class="hidden fixed z-[88] bg-[var(--card)] border border-[var(--border)] rounded-2xl shadow-xl py-2 text-sm overflow-hidden">
    <div class="px-3 py-2 border-b border-[var(--border)]">
      <p class="text-[10px] font-bold text-[var(--muted)] mb-2">วันที่</p>
      <div class="flex flex-wrap gap-1.5">
        <button type="button" class="task-ctx-date text-[10px] font-bold px-2 py-1 rounded-lg bg-[var(--surface)] border border-[var(--border)]" data-date="today">วันนี้</button>
        <button type="button" class="task-ctx-date text-[10px] font-bold px-2 py-1 rounded-lg bg-[var(--surface)] border border-[var(--border)]" data-date="tomorrow">พรุ่งนี้</button>
        <button type="button" class="task-ctx-date text-[10px] font-bold px-2 py-1 rounded-lg bg-[var(--surface)] border border-[var(--border)]" data-date="nextweek">+7 วัน</button>
        <button type="button" class="task-ctx-date text-[10px] font-bold px-2 py-1 rounded-lg bg-[var(--surface)] border border-[var(--border)]" data-date="pick">เลือกวัน</button>
        <button type="button" class="task-ctx-date text-[10px] font-bold px-2 py-1 rounded-lg bg-[var(--surface)] border border-[var(--border)] text-[var(--muted)]" data-date="clear">ลบวัน</button>
      </div>
    </div>
    <div class="px-3 py-2 border-b border-[var(--border)]">
      <p class="text-[10px] font-bold text-[var(--muted)] mb-2">ความสำคัญ</p>
      <div class="flex gap-1.5">
        <button type="button" class="task-ctx-prio w-8 h-8 rounded-lg border border-[var(--border)] flex items-center justify-center" data-priority="1" title="ทำทันที"><i data-lucide="flame" class="w-3.5 h-3.5 text-red-400"></i></button>
        <button type="button" class="task-ctx-prio w-8 h-8 rounded-lg border border-[var(--border)] flex items-center justify-center" data-priority="2" title="วางแผนทำ"><i data-lucide="calendar-check" class="w-3.5 h-3.5 text-[var(--accent-text)]"></i></button>
        <button type="button" class="task-ctx-prio w-8 h-8 rounded-lg border border-[var(--border)] flex items-center justify-center" data-priority="3" title="มอบหมาย"><i data-lucide="send" class="w-3.5 h-3.5 text-amber-500"></i></button>
        <button type="button" class="task-ctx-prio w-8 h-8 rounded-lg border border-[var(--border)] flex items-center justify-center" data-priority="4" title="ทำทีหลัง"><i data-lucide="coffee" class="w-3.5 h-3.5 text-[var(--muted)]"></i></button>
        <button type="button" class="task-ctx-prio w-8 h-8 rounded-lg border border-[var(--border)] flex items-center justify-center" data-priority="0" title="ไม่ระบุ"><i data-lucide="minus" class="w-3.5 h-3.5 text-[var(--faint)]"></i></button>
      </div>
    </div>
    <button type="button" id="task-ctx-unnest" class="hidden w-full text-left px-4 py-2.5 text-xs font-bold flex items-center gap-2 hover:bg-[var(--surface)]">
      <i data-lucide="corner-up-left" class="w-4 h-4 text-[var(--accent-text)]"></i>ยกออกจากงานหลัก
    </button>
    <button type="button" id="task-ctx-delete" class="w-full text-left px-4 py-2.5 text-xs font-bold flex items-center gap-2 text-red-400 hover:bg-red-500/10">
      <i data-lucide="trash-2" class="w-4 h-4"></i>ลบงาน
    </button>
    <input type="hidden" id="task-ctx-date-picker" value="">
  </div>

  <!-- เลือกวันที่แบบ custom (ไม่ใช้ native picker) -->
  <div id="task-date-sheet" class="fixed inset-0 z-[96] hidden">
    <div id="task-date-sheet-backdrop" class="absolute inset-0 bg-black/60"></div>
    <div class="app-sheet app-sheet--modal absolute bottom-0 left-1/2 -translate-x-1/2 w-full max-w-md bg-[var(--card)] border-t border-[var(--border)] rounded-t-3xl p-5 pb-6">
      <div class="w-10 h-1 rounded-full bg-[var(--border-2)] mx-auto mb-4"></div>
      <div class="flex items-center justify-between mb-3">
        <p id="picker-cal-title" class="font-bold text-sm flex items-center gap-2">
          <i data-lucide="calendar" class="w-4 h-4 text-[var(--accent-text)]"></i>
          <span></span>
        </p>
        <div class="flex items-center gap-1">
          <button type="button" id="picker-cal-prev" class="w-8 h-8 rounded-lg flex items-center justify-center text-[var(--muted)] hover:bg-[var(--surface)]">
            <i data-lucide="chevron-left" class="w-4 h-4"></i>
          </button>
          <button type="button" id="picker-cal-next" class="w-8 h-8 rounded-lg flex items-center justify-center text-[var(--muted)] hover:bg-[var(--surface)]">
            <i data-lucide="chevron-right" class="w-4 h-4"></i>
          </button>
        </div>
      </div>
      <div class="grid grid-cols-7 gap-1 mb-2">
        <?php foreach (['อา','จ','อ','พ','พฤ','ศ','ส'] as $wd): ?>
          <span class="text-center text-[10px] font-bold text-[var(--faint)] py-1"><?php echo $wd; ?>.</span>
        <?php endforeach; ?>
      </div>
      <div id="picker-cal-grid" class="grid grid-cols-7 gap-y-1.5 gap-x-0.5 mb-4"></div>
      <div class="flex gap-2">
        <button type="button" id="picker-cal-clear" class="flex-1 text-xs font-bold py-2.5 rounded-xl border border-[var(--border)] bg-[var(--surface)] text-[var(--muted)]">ล้าง</button>
        <button type="button" id="picker-cal-today" class="flex-1 text-xs font-bold py-2.5 rounded-xl bg-[#E2E800] text-[#141414]">วันนี้</button>
      </div>
    </div>
  </div>

  <!-- รายละเอียดงาน (แตะงาน) -->
  <div id="task-detail-sheet" class="fixed inset-0 z-[75] hidden">
    <div id="task-detail-backdrop" class="absolute inset-0 bg-black/60"></div>
    <div class="app-sheet app-sheet--modal absolute bottom-0 left-1/2 -translate-x-1/2 w-full max-w-md bg-[var(--card)] border-t border-[var(--border)] rounded-t-3xl p-5 pb-[calc(1.5rem+env(safe-area-inset-bottom))] max-h-[85vh] overflow-y-auto">
      <div class="w-10 h-1 rounded-full bg-[var(--border-2)] mx-auto mb-4"></div>
      <div class="flex items-start justify-between gap-3 mb-4">
        <h2 class="text-sm font-bold text-[var(--muted)]">รายละเอียดงาน</h2>
        <button type="button" id="task-detail-close" class="w-8 h-8 rounded-full bg-[var(--surface)] border border-[var(--border)] flex items-center justify-center">
          <i data-lucide="x" class="w-4 h-4"></i>
        </button>
      </div>
      <textarea id="task-detail-title" rows="3" aria-label="ชื่องาน"
        class="w-full bg-[var(--surface)] border border-[var(--border)] rounded-xl text-base font-bold px-3.5 py-3 mb-3 focus:outline-none focus:border-[#E2E800]"></textarea>
      <div class="space-y-2 text-xs">
        <p class="flex items-center gap-2 text-[var(--text-2)]"><i data-lucide="calendar" class="w-4 h-4 text-[var(--accent-text)]"></i><span id="task-detail-due">—</span></p>
        <p class="flex items-center gap-2 text-[var(--text-2)]"><i data-lucide="flag" class="w-4 h-4 text-[var(--accent-text)]"></i><span id="task-detail-prio">—</span></p>
        <p id="task-detail-lead-wrap" class="hidden flex items-center gap-2 text-[var(--muted)]"><i data-lucide="user" class="w-4 h-4"></i><span id="task-detail-lead"></span></p>
        <p id="task-detail-parent-wrap" class="hidden flex items-center gap-2 text-[var(--muted)]"><i data-lucide="corner-down-right" class="w-4 h-4"></i><span>งานย่อย</span></p>
      </div>
      <button type="button" id="task-detail-save" class="mt-5 w-full bg-[#E2E800] text-[#141414] text-sm font-bold py-3 rounded-xl active:scale-[0.98] transition">บันทึก</button>
    </div>
  </div>

  <!-- Toast บันทึกสำเร็จ (ใช้ทั้งตาราง Lead และรายละเอียด) -->
  <div id="app-save-toast" class="hidden fixed left-0 right-0 z-[90] px-4 pointer-events-none" style="bottom: calc(5.25rem + env(safe-area-inset-bottom, 0px));" role="status">
    <div class="max-w-md mx-auto bg-[var(--card)] border border-[var(--border)] rounded-2xl px-4 py-3 shadow-lg flex items-center gap-2 text-sm font-bold pointer-events-none">
      <i data-lucide="check-circle-2" class="w-5 h-5 shrink-0 text-[var(--accent-text)]"></i>
      <span id="app-save-toast-msg">บันทึกสำเร็จ</span>
    </div>
  </div>

  <!-- Toast ยกเลิกหลังลบ (แบบ TickTick) -->
  <div id="task-undo-toast" class="hidden fixed left-0 right-0 z-[85] px-4 pointer-events-none" style="bottom: calc(5.25rem + env(safe-area-inset-bottom, 0px));">
    <div class="max-w-md mx-auto bg-[var(--card)] border border-[var(--border)] rounded-2xl px-4 py-3 shadow-lg flex items-center gap-3 pointer-events-auto">
      <i data-lucide="rotate-ccw" class="w-4 h-4 text-[var(--accent-text)] shrink-0"></i>
      <span id="task-undo-toast-msg" class="flex-1 text-xs font-bold truncate">ลบงานแล้ว</span>
      <button type="button" id="task-undo-toast-btn" class="text-xs font-bold px-2.5 py-1 rounded-lg bg-[#E2E800] text-[#141414] shrink-0">ยกเลิก</button>
      <button type="button" id="task-undo-toast-dismiss" class="text-[var(--faint)] p-1 shrink-0" aria-label="ปิด">
        <i data-lucide="x" class="w-3.5 h-3.5"></i>
      </button>
    </div>
  </div>

  <!-- ยืนยันออกก่อนบันทึกข้อความ (Salt & Pepper) -->
  <div id="unsaved-leave-sheet" class="fixed inset-0 z-[95] hidden" role="dialog" aria-labelledby="unsaved-leave-title" aria-modal="true">
    <div id="unsaved-leave-backdrop" class="absolute inset-0 bg-black/60"></div>
    <div class="app-sheet app-sheet--modal absolute bottom-0 left-1/2 -translate-x-1/2 w-full max-w-md bg-[var(--card)] border-t border-[var(--border)] rounded-t-3xl p-5 pb-6 shadow-2xl">
      <div class="w-10 h-1 rounded-full bg-[var(--border-2)] mx-auto mb-4"></div>
      <div class="flex items-start gap-3 mb-5">
        <span class="w-10 h-10 rounded-xl bg-[#E2E800]/15 border border-[#E2E800]/40 flex items-center justify-center shrink-0" aria-hidden="true">
          <i data-lucide="alert-triangle" class="w-5 h-5 text-[var(--accent-text)]"></i>
        </span>
        <div class="min-w-0">
          <p id="unsaved-leave-title" class="font-bold text-sm">ยังพิมพ์ไม่เสร็จ</p>
          <p class="text-xs text-[var(--muted)] mt-1 leading-relaxed">มีข้อความที่ยังไม่ได้บันทึก ถ้าออกตอนนี้จะหายไป</p>
        </div>
      </div>
      <div class="flex gap-2">
        <button type="button" id="unsaved-leave-stay" class="flex-1 py-3 rounded-xl border border-[var(--border)] bg-[var(--surface)] text-sm font-bold active:scale-[0.98] transition">อยู่ต่อ</button>
        <button type="button" id="unsaved-leave-go" class="flex-1 py-3 rounded-xl bg-[#E2E800] text-[#141414] text-sm font-bold active:scale-[0.98] transition">ออกโดยไม่บันทึก</button>
      </div>
    </div>
  </div>

  <!-- ===== หน้า Pipeline: วางแผนรายได้ + funnel ถอยหลัง ===== -->
  <section id="page-pipeline" class="page px-5 space-y-4 hidden pb-8">
    <div class="space-y-3">
      <div>
        <h1 class="text-xl font-bold">Pipeline</h1>
        <p class="text-xs text-[var(--muted)]">วางแผนเป้ารายได้ · <?php echo $pipeline_month_label; ?></p>
      </div>
      <div class="space-y-1">
        <p class="text-[10px] font-bold text-[var(--muted)]">เดือนเป้า</p>
        <div class="flex items-center bg-[var(--card)] border border-[var(--border)] rounded-xl overflow-hidden">
          <button type="button" id="pl-month-prev" aria-label="เดือนก่อนหน้า"
                  class="w-10 h-10 flex items-center justify-center text-[var(--muted)] active:bg-[var(--surface)] shrink-0">
            <i data-lucide="chevron-left" class="w-4 h-4"></i>
          </button>
          <button type="button" id="pl-month-open"
                  class="flex-1 min-w-0 py-2.5 px-1 text-xs font-bold text-center truncate active:bg-[var(--surface)]">
            <span class="inline-flex items-center justify-center gap-1.5">
              <i data-lucide="calendar" class="w-3.5 h-3.5 text-[var(--accent-text)]"></i>
              <span id="pl-month-label-text"><?php echo htmlspecialchars($pipeline_month_label); ?></span>
            </span>
          </button>
          <button type="button" id="pl-month-next" aria-label="เดือนถัดไป"
                  class="w-10 h-10 flex items-center justify-center text-[var(--muted)] active:bg-[var(--surface)] shrink-0">
            <i data-lucide="chevron-right" class="w-4 h-4"></i>
          </button>
        </div>
      </div>
    </div>

    <!-- Bottom sheet เลือกเดือนเป้า -->
    <div id="pl-month-sheet" class="fixed inset-0 z-[70] hidden">
      <div id="pl-month-sheet-backdrop" class="absolute inset-0 bg-black/60"></div>
      <div class="app-sheet app-sheet--modal absolute inset-x-0 bottom-0 left-1/2 -translate-x-1/2 w-full max-w-md bg-[var(--bg)] border-t border-[var(--border)] rounded-t-3xl px-5 pt-4 pb-6 max-h-[70vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-sm font-bold">เลือกเดือนเป้า</h3>
          <button type="button" id="pl-month-sheet-close" class="w-8 h-8 rounded-full bg-[var(--card)] border border-[var(--border)] flex items-center justify-center">
            <i data-lucide="x" class="w-4 h-4"></i>
          </button>
        </div>
        <div class="flex items-center justify-between mb-4 bg-[var(--card)] border border-[var(--border)] rounded-xl px-3 py-2">
          <button type="button" id="pl-sheet-year-prev" class="w-9 h-9 flex items-center justify-center rounded-lg active:bg-[var(--surface)]">
            <i data-lucide="chevron-left" class="w-4 h-4"></i>
          </button>
          <span id="pl-sheet-year" class="text-sm font-bold tabular-nums"><?php echo $py; ?></span>
          <button type="button" id="pl-sheet-year-next" class="w-9 h-9 flex items-center justify-center rounded-lg active:bg-[var(--surface)]">
            <i data-lucide="chevron-right" class="w-4 h-4"></i>
          </button>
        </div>
        <div id="pl-month-grid" class="grid grid-cols-3 gap-2">
          <?php for ($mi = 1; $mi <= 12; $mi++): ?>
            <button type="button" class="pl-month-chip text-xs font-bold py-2.5 rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--muted)] transition"
                    data-month="<?php echo $mi; ?>">
              <?php echo $thai_m_short[$mi]; ?>
            </button>
          <?php endfor; ?>
        </div>
        <div class="mt-4">
          <button type="button" id="pl-month-this" class="w-full text-xs font-bold py-2.5 rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--text-2)]">
            เดือนนี้
          </button>
        </div>
      </div>
    </div>
    <input type="hidden" id="pl-month-value" value="<?php echo htmlspecialchars($pipeline['target_month']); ?>">

    <form id="pipeline-form" class="space-y-4">
      <!-- ① ตั้งเป้ารายได้ก่อน -->
      <div class="bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4 space-y-3">
        <p class="text-xs font-bold text-[var(--accent-text)]">① เป้ารายได้เดือนนี้</p>
        <div class="flex items-center gap-2">
          <span class="text-sm text-[var(--muted)]">฿</span>
          <input id="pl-monthly-target" name="monthly_target" type="text" inputmode="numeric" autocomplete="off"
                 value="<?php echo number_format((int)$pipeline['monthly_target']); ?>"
                 class="fmt-num flex-1 bg-[var(--surface)] border border-[var(--border)] rounded-xl text-lg font-bold px-3 py-2.5 focus:outline-none focus:border-[#E2E800]">
        </div>
        <div class="h-2 rounded-full bg-[var(--surface)] overflow-hidden">
          <div class="h-full bg-[#E2E800] rounded-full transition-all" style="width:<?php echo $revenue_pct; ?>%"></div>
        </div>
        <p class="text-[11px] text-[var(--faint)]">
          ทำได้แล้ว <b class="text-[var(--text-2)]">฿<?php echo number_format($actual_revenue); ?></b>
          จาก Win <?php echo $actual_win_month; ?> เคส
          · เป้า <?php echo $revenue_pct; ?>%
        </p>
        <div class="grid grid-cols-2 gap-2">
          <div>
            <label class="text-[10px] font-bold text-[var(--muted)]">คอมเฉลี่ย/ดีล (฿)</label>
            <input id="pl-commission" name="commission_per_deal" type="text" inputmode="numeric" autocomplete="off"
                   value="<?php echo number_format((int)$pipeline['commission_per_deal']); ?>"
                   class="fmt-num w-full mt-1 bg-[var(--surface)] border border-[var(--border)] rounded-lg text-sm px-2.5 py-2 focus:outline-none focus:border-[#E2E800]">
          </div>
          <div>
            <label class="text-[10px] font-bold text-[var(--muted)]">ราคาโปรเจกต์เป้า (฿)</label>
            <input id="pl-project-price" name="project_target_price" type="text" inputmode="numeric" autocomplete="off"
                   value="<?php echo number_format((int)$pipeline['project_target_price']); ?>"
                   class="fmt-num w-full mt-1 bg-[var(--surface)] border border-[var(--border)] rounded-lg text-sm px-2.5 py-2 focus:outline-none focus:border-[#E2E800]">
          </div>
        </div>
        <p class="text-[10px] text-[var(--faint)]">โปรเจกต์เป้า ~<?php echo fmt_baht_short($pipeline['project_target_price']); ?> · ต้องปิด <?php echo $pipeline_need['win']; ?> ดีลเพื่อถึงเป้า</p>
        <input type="hidden" name="target_month" value="<?php echo htmlspecialchars($pipeline['target_month']); ?>">
      </div>

      <!-- สะสมรายปี (YTD) -->
      <div class="bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4 space-y-2">
        <p class="text-xs font-bold text-[var(--muted)] flex items-center gap-1.5">
          <i data-lucide="calendar-range" class="w-3.5 h-3.5 text-[var(--accent-text)]"></i>
          สะสมปี <?php echo $ytd_year; ?>
        </p>
        <div class="grid grid-cols-2 gap-3 pipeline-ytd-grid">
          <div class="bg-[var(--surface)] border border-[var(--border)] rounded-xl px-3 py-2.5">
            <p class="text-[10px] text-[var(--faint)]">Commission YTD</p>
            <p class="text-lg font-bold text-[var(--accent-text)]">฿<?php echo number_format($ytd_commission); ?></p>
            <p class="text-[10px] text-[var(--faint)] mt-0.5">Win <?php echo $ytd_win_count; ?> ดีล × ฿<?php echo number_format((int)$pipeline['commission_per_deal']); ?></p>
          </div>
          <div class="bg-[var(--surface)] border border-[var(--border)] rounded-xl px-3 py-2.5">
            <p class="text-[10px] text-[var(--faint)]">มูลค่าปิดดีลสะสม</p>
            <p class="text-lg font-bold">฿<?php echo $ytd_gmv > 0 ? number_format((int)round($ytd_gmv)) : '0'; ?></p>
            <p class="text-[10px] text-[var(--faint)] mt-0.5">รวมราคาปิดจาก Lead Win</p>
          </div>
        </div>
        <p class="text-[10px] text-[var(--faint)] flex items-center gap-1">
          <i data-lucide="info" class="w-3 h-3 shrink-0"></i>
          นับจากวันที่จบดีล (win_date) ในปี <?php echo $ytd_year; ?>
        </p>
      </div>

      <!-- ② เป้าจำนวนแต่ละขั้น + เทียบของจริง -->
      <div class="bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4 space-y-3">
        <p class="text-xs font-bold text-[var(--accent-text)] flex items-center gap-1">
          ② เป้าจำนวนแต่ละขั้น
          <button type="button" class="tip-link text-[var(--faint)] hover:text-[var(--accent-text)] transition" data-tip="pipeline-funnel" title="อันนี้คืออะไร?">
            <i data-lucide="help-circle" class="w-3.5 h-3.5"></i>
          </button>
        </p>
        <p class="text-[10px] text-[var(--faint)]">ใส่จำนวนที่ต้องมี — Win คำนวณจากเป้ารายได้ด้านบนอัตโนมัติ</p>
        <div class="space-y-2 pipeline-stages-grid">
          <?php foreach ($pipeline_stages as $si => $s):
            $gap = $s['need'] - $s['actual'];
            $pct_bar = $s['need'] > 0 ? min(100, round($s['actual'] / $s['need'] * 100)) : 0;
            $gap_class = $gap <= 0 ? 'text-status-yes' : 'text-amber-500';
            $field = 'need_' . $s['key'];
          ?>
          <div class="rounded-xl bg-[var(--surface)] border border-[var(--border)] px-3 py-2.5">
            <div class="flex items-center gap-2 mb-2">
              <i data-lucide="<?php echo $s['icon']; ?>" class="w-4 h-4 shrink-0 text-[var(--accent-text)]"></i>
              <span class="text-sm font-bold flex-1"><?php echo $s['label']; ?></span>
              <?php if ($s['editable']): ?>
                <input name="<?php echo $field; ?>" type="text" inputmode="numeric" autocomplete="off"
                       value="<?php echo (int)$s['need']; ?>"
                       class="need-count w-20 text-right bg-[var(--card)] border border-[var(--border)] rounded-lg text-sm font-bold px-2 py-1.5 focus:outline-none focus:border-[#E2E800]">
                <span class="text-[11px] text-[var(--faint)]">รายการ</span>
              <?php else: ?>
                <input id="need-win-display" type="text" readonly tabindex="-1"
                       value="<?php echo (int)$s['need']; ?>"
                       class="w-20 text-right bg-[var(--chip)] border border-[var(--border)] rounded-lg text-sm font-bold px-2 py-1.5 text-[var(--text-2)]">
                <span class="text-[11px] text-[var(--faint)]">ดีล</span>
              <?php endif; ?>
            </div>
            <div class="flex items-center justify-between text-[11px] mb-1">
              <span class="text-[var(--faint)]">มีจริง <b class="text-[var(--text-2)]"><?php echo $s['actual']; ?></b></span>
              <?php if ($gap <= 0): ?>
                <span class="text-xs font-bold text-status-yes flex items-center gap-1"><i data-lucide="check" class="w-3 h-3"></i>ครบแล้ว</span>
              <?php else: ?>
                <span class="text-xs font-bold text-amber-500">ขาด <?php echo $gap; ?></span>
              <?php endif; ?>
            </div>
            <div class="h-1.5 rounded-full bg-[var(--chip)] overflow-hidden">
              <div class="h-full rounded-full <?php echo $gap <= 0 ? 'bg-status-yes border border-status-yes' : 'bg-[#E2E800]'; ?>" style="width:<?php echo $pct_bar; ?>%"></div>
            </div>
          </div>
          <?php if ($si < count($pipeline_stages) - 1): ?>
          <div class="pipeline-stage-arrow flex justify-center text-[var(--faint)]"><i data-lucide="arrow-up" class="w-3 h-3"></i></div>
          <?php endif; ?>
          <?php endforeach; ?>
        </div>
      </div>

      <?php if ($matrix_analytics['matrix_leads'] > 0): ?>
      <div class="bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4 space-y-3">
        <p class="text-xs font-bold text-[var(--muted)] flex items-center gap-1.5">
          <i data-lucide="grid-3x3" class="w-3.5 h-3.5 text-[var(--accent-text)]"></i>
          สถิติ Stage Matrix
        </p>
        <p class="text-[10px] text-[var(--faint)] leading-relaxed">
          จาก Lead ที่บันทึกผลรายขั้นแล้ว <b class="text-[var(--text-2)]"><?php echo (int)$matrix_analytics['matrix_leads']; ?></b> ราย
          · ดึงกลับมา <b class="text-[var(--text-2)]"><?php echo (int)$matrix_analytics['revivals']; ?></b> เคส
        </p>
        <div class="space-y-2">
          <?php foreach (lead_funnel_statuses() as $mst):
            $minfo = $matrix_analytics['stages'][$mst] ?? ['yes' => 0, 'drop' => 0, 'conv_pct' => null];
            if (($minfo['yes'] + $minfo['drop']) === 0) continue;
            $mlabel = $pl_matrix_stage_labels[$mst] ?? $mst;
          ?>
          <div class="rounded-xl bg-[var(--surface)] border border-[var(--border)] px-3 py-2 flex items-center gap-2 text-[11px]">
            <span class="font-bold w-16 shrink-0"><?php echo htmlspecialchars($mlabel); ?></span>
            <span class="text-[var(--text-2)] flex items-center gap-1">
              <i data-lucide="check" class="w-3 h-3 text-[var(--accent-text)]"></i>
              Yes <?php echo (int)$minfo['yes']; ?>
            </span>
            <span class="text-[var(--muted)] flex items-center gap-1">
              <i data-lucide="user-x" class="w-3 h-3"></i>
              Lose <?php echo (int)$minfo['drop']; ?>
            </span>
            <?php if ($minfo['conv_pct'] !== null): ?>
            <span class="ml-auto font-bold text-[var(--text-2)]"><?php echo $minfo['conv_pct']; ?>%</span>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- ③ สรุปว่าทำอะไรไปแล้ว / ควรโฟกัสอะไร -->
      <div class="bg-[#E2E800]/10 border border-[#E2E800]/30 rounded-2xl p-4 space-y-2">
        <p class="text-xs font-bold text-[var(--accent-text)]">③ สรุป &amp; โฟกัส</p>
        <?php if ($actual_win_month > 0): ?>
          <p class="text-sm text-[var(--text-2)]">เดือนนี้ปิด Win แล้ว <b><?php echo $actual_win_month; ?></b> เคส (฿<?php echo number_format($actual_revenue); ?>)</p>
        <?php else: ?>
          <p class="text-sm text-[var(--text-2)]">เดือนนี้ยังไม่มี Win — ต้องปิดอีก <b><?php echo $pipeline_need['win']; ?></b> เคส</p>
        <?php endif; ?>
        <?php if (count($pipeline_focus) > 0): ?>
          <p class="text-xs text-[var(--muted)] leading-relaxed">
            ช่องที่ขาดมากสุด:
            <?php foreach (array_slice($pipeline_focus, 0, 3) as $i => $f): ?>
              <b class="text-[var(--text-2)]"><?php echo $f['label']; ?> +<?php echo $f['gap']; ?></b><?php echo $i < min(2, count($pipeline_focus) - 1) ? ' · ' : ''; ?>
            <?php endforeach; ?>
          </p>
        <?php else: ?>
          <p class="text-xs text-status-yes font-bold flex items-center gap-1"><i data-lucide="check-circle" class="w-3.5 h-3.5"></i>ทุกช่องใน funnel ครบตามเป้าแล้ว</p>
        <?php endif; ?>
      </div>

      <button type="submit" class="w-full bg-[#E2E800] text-[#141414] text-sm font-bold py-3 rounded-xl active:scale-[0.98] transition">
        บันทึก &amp; คำนวณใหม่
      </button>
    </form>

    <!-- ④ ตาราง Pipeline Lead — อัปเดต Yes/Lose/Reject แบบชีท -->
    <div id="pl-matrix-section" class="bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4 space-y-3">
      <div class="flex items-start justify-between gap-2">
        <div>
          <p class="text-xs font-bold text-[var(--accent-text)] flex items-center gap-1.5">
            <i data-lucide="table" class="w-3.5 h-3.5"></i>
            ④ Pipeline Lead — ตารางอัปเดต
          </p>
          <p class="text-[10px] text-[var(--faint)] mt-1 leading-relaxed">
            กดเซลล์เลือกผลรายขั้น · สี + ไอคอน + ข้อความกำกับทุกช่อง
          </p>
        </div>
        <span id="pl-matrix-count" class="text-[10px] font-bold text-[var(--muted)] shrink-0"><?php echo count($leads); ?> รายการ</span>
      </div>
      <div class="flex flex-wrap gap-2 items-center">
        <div class="relative flex-1 min-w-[10rem]">
          <i data-lucide="search" class="w-3.5 h-3.5 absolute left-3 top-1/2 -translate-y-1/2 text-[var(--faint)] pointer-events-none"></i>
          <input id="pl-matrix-search" type="search" placeholder="ค้นหา Lead…" autocomplete="off"
                 class="w-full pl-9 pr-3 py-2 bg-[var(--surface)] border border-[var(--border)] rounded-xl text-xs placeholder-[var(--faint)] focus:outline-none focus:ring-2 focus:ring-[#E2E800]">
        </div>
        <select id="pl-matrix-stage-filter" class="app-select app-select--compact app-select--surface" aria-label="กรองขั้นตอน">
          <option value="">ทุกขั้น</option>
          <?php foreach (lead_funnel_statuses() as $mst): ?>
          <option value="<?php echo htmlspecialchars($mst); ?>"><?php echo htmlspecialchars($pl_matrix_stage_labels[$mst] ?? $mst); ?></option>
          <?php endforeach; ?>
        </select>
        <select id="pl-matrix-outcome-filter" class="app-select app-select--compact app-select--surface" aria-label="กรองผลลัพธ์">
          <option value="">ทุกผล</option>
          <option value="yes">Yes</option>
          <option value="lose">Lose</option>
          <option value="reject">Reject</option>
          <option value="hold">Hold</option>
          <option value="__empty">ยังไม่กรอก</option>
        </select>
        <label class="inline-flex items-center gap-1.5 text-[10px] font-bold text-[var(--muted)] shrink-0 cursor-pointer">
          <input type="checkbox" id="pl-matrix-month-only" class="rounded border-[var(--border)]">
          เฉพาะเดือนเป้า
        </label>
      </div>
      <div id="pl-matrix-root"></div>
      <p class="text-[10px] text-[var(--faint)] flex items-center gap-1 lg:hidden">
        <i data-lucide="smartphone" class="w-3 h-3 shrink-0"></i>
        มือถือ: เลื่อนซ้าย-ขวาดูคอลัมน์ · ชื่อ Lead ติดซ้าย
      </p>
    </div>
  </section>

  <!-- ===== หน้าเอกสาร / คำนวน (LINE Quick Reply เอกสาร) ===== -->
  <section id="page-documents" class="page px-5 space-y-4 hidden pb-28">
    <div>
      <h1 class="text-xl font-bold">เอกสาร / คำนวน</h1>
      <p class="text-xs text-[var(--muted)] mt-1">เครื่องมือเอกสารวันโอน · เปิดจาก LINE Quick Reply หรือแท็บนี้</p>
    </div>

    <article id="documents-land" class="bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4 space-y-3 scroll-mt-24">
      <h2 class="text-sm font-bold flex items-center gap-2">
        <i data-lucide="calculator" class="w-4 h-4 text-[var(--accent-text)]"></i>
        คำนวนค่าใช้จ่ายกรมที่ดิน
      </h2>
      <p class="text-xs text-[var(--text-2)] leading-relaxed">พิมพ์รหัสทรัพย์หรือชื่อโครงการใน LINE → ตรวจสอบข้อมูลทรัพย์ → copy ค่าใช้จ่ายจากกรมที่ดินมาวางในแชท → ระบบคำนวณ Net สรุป Owner / Buyer</p>
      <p class="text-[11px] text-[var(--faint)]">ขั้นตอนเต็ม: ใช้เมนู LINE ▸ เอกสาร/คำนวน ▸ คำนวนกรมที่ดิน</p>
      <button type="button" onclick="switchTab('report')" class="text-xs font-bold px-3 py-2 rounded-xl bg-[var(--surface)] border border-[var(--border)] active:scale-[0.98] transition">
        เปิดเครื่องมือวิเคราะห์ราคา (Dashboard)
      </button>
    </article>

    <article id="documents-spouse" class="bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4 space-y-3 scroll-mt-24">
      <h2 class="text-sm font-bold flex items-center gap-2">
        <i data-lucide="file-signature" class="w-4 h-4 text-[var(--accent-text)]"></i>
        หนังสือยินยอมคู่สมรส
      </h2>
      <p class="text-xs text-[var(--text-2)] leading-relaxed">แบบฟอร์มอ้างอิงกรมที่ดิน · กรอกข้อมูลในหน้านี้แล้วส่ง PDF ให้ลูกค้า (กำลังเตรียมอัปโหลดไฟล์แม่แบบ)</p>
      <div class="rounded-xl bg-[var(--surface)] border border-dashed border-[var(--border)] p-4 text-center text-xs text-[var(--muted)]">
        ฟอร์มกรอกข้อมูล — เร็วๆ นี้
      </div>
    </article>

    <article id="documents-poa" class="bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4 space-y-3 scroll-mt-24">
      <h2 class="text-sm font-bold flex items-center gap-2">
        <i data-lucide="file-pen" class="w-4 h-4 text-[var(--accent-text)]"></i>
        หนังสือมอบอำนาจ
      </h2>
      <p class="text-xs text-[var(--text-2)] leading-relaxed">ดาวน์โหลดแบบฟอร์ม · กรอกใน Dashboard · ส่ง PDF ให้ลูกค้า</p>
      <div class="rounded-xl bg-[var(--surface)] border border-dashed border-[var(--border)] p-4 text-center text-xs text-[var(--muted)]">
        ฟอร์มกรอกข้อมูล — เร็วๆ นี้
      </div>
    </article>

    <article id="documents-transfer" class="bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4 space-y-4 scroll-mt-24">
      <h2 class="text-sm font-bold flex items-center gap-2">
        <i data-lucide="clipboard-list" class="w-4 h-4 text-[var(--accent-text)]"></i>
        สิ่งที่ต้องเตรียม ณ วันโอน
      </h2>
      <p class="text-xs text-[var(--muted)]">แพทเทิร์นพร้อม copy ส่งลูกค้า — แยกฝั่ง Owner / Buyer</p>
      <div class="grid gap-3 sm:grid-cols-2">
        <div class="rounded-xl bg-[var(--surface)] border border-[var(--border)] p-3">
          <p class="text-[11px] font-bold text-[var(--muted)] mb-2">Owner</p>
          <ul class="text-xs text-[var(--text-2)] space-y-1.5 leading-relaxed list-disc pl-4">
            <li>บัตรประชาชนตัวจริง (ชื่อตรงโฉนด)</li>
            <li>ทะเบียนบ้านตัวจริง</li>
            <li>โฉนดตัวจริง (ติดจำนองนัดธนาคาร)</li>
            <li>ใบเปลี่ยนชื่อ (ถ้ามี)</li>
            <li>ทะเบียนสมรส + คู่สมรส (ถ้ามี)</li>
            <li>บิลค่าน้ำ / ค่าไฟล่าสุด</li>
          </ul>
        </div>
        <div class="rounded-xl bg-[var(--surface)] border border-[var(--border)] p-3">
          <p class="text-[11px] font-bold text-[var(--muted)] mb-2">Buyer</p>
          <ul class="text-xs text-[var(--text-2)] space-y-1.5 leading-relaxed list-disc pl-4">
            <li>บัตรประชาชนตัวจริง</li>
            <li>ทะเบียนบ้านตัวจริง</li>
            <li>ใบเปลี่ยนชื่อ (ถ้ามี)</li>
            <li>ทะเบียนสมรส + คู่สมรส (ถ้ามี)</li>
          </ul>
        </div>
      </div>
    </article>
  </section>

  <!-- ===== หน้า Map: แผนที่บน + แผงรายละเอียดล่าง ===== -->
  <section id="page-map" class="page hidden flex flex-col min-h-0 overflow-hidden">
    <div id="map-toolbar" class="px-4 pt-2 pb-1 shrink-0 space-y-1 lg:px-0">
      <div class="flex items-center justify-between">
        <h1 class="text-lg font-bold">แผนที่</h1>
        <p class="text-[10px] text-[var(--faint)] flex items-center gap-1 lg:hidden">
          <i data-lucide="map-pin" class="w-3 h-3"></i>
          กดจุดบนแผนที่ดูรายละเอียดด้านล่าง
        </p>
      </div>
      <div class="flex gap-2 flex-wrap">
        <button type="button" id="map-chip-owner" data-on="1"
                class="map-chip flex-1 min-w-[30%] flex items-center justify-center gap-1.5 py-2 rounded-xl border text-xs font-bold transition border-[#E2E800] bg-[#E2E800]/15 text-[var(--accent-text)]">
          <i data-lucide="building-2" class="w-3.5 h-3.5"></i>
          Owner <span class="text-[10px] opacity-80">(<?php echo (int)($map_payload['owner_total'] ?? 0); ?>)</span>
        </button>
        <button type="button" id="map-chip-lead" data-on="0"
                class="map-chip flex-1 min-w-[30%] flex items-center justify-center gap-1.5 py-2 rounded-xl border text-xs font-bold transition border-[var(--border)] bg-[var(--card)] text-[var(--muted)]">
          <i data-lucide="users" class="w-3.5 h-3.5"></i>
          Lead <span id="map-lead-count" class="text-[10px] opacity-80">(<?php echo (int)($map_payload['lead_total'] ?? 0); ?>)</span>
        </button>
        <button type="button" id="map-chip-project" data-on="0"
                class="map-chip flex-1 min-w-[30%] flex items-center justify-center gap-1.5 py-2 rounded-xl border text-xs font-bold transition border-[var(--border)] bg-[var(--card)] text-[var(--muted)]">
          <i data-lucide="landmark" class="w-3.5 h-3.5"></i>
          Project <span class="text-[10px] opacity-80">(<?php echo count($map_payload['projects']); ?>)</span>
        </button>
      </div>
      <div class="relative">
        <i data-lucide="search" class="w-3.5 h-3.5 absolute left-3 top-1/2 -translate-y-1/2 text-[var(--faint)] pointer-events-none"></i>
        <input id="map-search" type="search" placeholder="ค้นหา CRM — Project, รหัส, ชื่อ, โซน…" autocomplete="off"
               class="w-full pl-9 pr-3 py-2 bg-[var(--card)] border border-[var(--border)] rounded-xl text-xs placeholder-[var(--faint)] focus:outline-none focus:ring-2 focus:ring-[#E2E800]">
      </div>
      <p id="map-filter-summary" class="text-[10px] text-[var(--faint)] hidden"></p>
      <div id="map-owner-filter" class="space-y-1">
        <div class="flex items-center justify-between gap-2">
          <p class="text-[10px] text-[var(--faint)] flex items-center gap-1">
            <i data-lucide="flame" class="w-3 h-3 shrink-0"></i>
            เกรดความรีบ
          </p>
          <button type="button" id="map-owner-reset"
                  class="shrink-0 inline-flex items-center gap-1 px-2 py-1 rounded-lg border border-[var(--border)] bg-[var(--card)] text-[10px] font-bold text-[var(--muted)]">
            <i data-lucide="rotate-ccw" class="w-3 h-3"></i>ล้างค่า
          </button>
        </div>
        <div class="flex flex-wrap gap-1.5">
        <button type="button" class="map-owner-grade px-2.5 py-1 rounded-lg border border-[#E2E800] bg-[#E2E800]/15 text-[10px] font-bold text-[var(--accent-text)]" data-grade="" data-on="1">เกรดทั้งหมด</button>
        <button type="button" class="map-owner-grade px-2.5 py-1 rounded-lg border border-[var(--border)] bg-[var(--card)] text-[10px] font-bold text-[var(--muted)] inline-flex items-center gap-1" data-grade="A"><i data-lucide="flame" class="w-3 h-3"></i>A·Hot</button>
        <button type="button" class="map-owner-grade px-2.5 py-1 rounded-lg border border-[var(--border)] bg-[var(--card)] text-[10px] font-bold text-[var(--muted)] inline-flex items-center gap-1" data-grade="B"><i data-lucide="thermometer" class="w-3 h-3"></i>B·Warm</button>
        <button type="button" class="map-owner-grade px-2.5 py-1 rounded-lg border border-[var(--border)] bg-[var(--card)] text-[10px] font-bold text-[var(--muted)] inline-flex items-center gap-1" data-grade="C"><i data-lucide="snowflake" class="w-3 h-3"></i>C·Cold</button>
        </div>
      </div>
      <div id="map-project-filter" class="hidden space-y-2">
        <div class="flex items-center justify-between gap-2">
          <p class="text-[10px] text-[var(--faint)] flex items-center gap-1">
            <i data-lucide="layers" class="w-3 h-3 shrink-0"></i>
            Segment
          </p>
          <button type="button" id="map-project-reset"
                  class="shrink-0 inline-flex items-center gap-1 px-2 py-1 rounded-lg border border-[var(--border)] bg-[var(--card)] text-[10px] font-bold text-[var(--muted)]">
            <i data-lucide="rotate-ccw" class="w-3 h-3"></i>ล้างค่า
          </button>
        </div>
        <div class="flex gap-1.5 overflow-x-auto no-scrollbar -mx-4 px-4 pb-0.5">
          <button type="button" class="map-proj-segment shrink-0 px-2.5 py-1 rounded-lg border border-[#E2E800] bg-[#E2E800]/15 text-[10px] font-bold text-[var(--accent-text)]" data-segment="" data-on="1">ทั้งหมด</button>
          <?php foreach (map_project_segment_options() as $seg): ?>
          <button type="button" class="map-proj-segment shrink-0 px-2.5 py-1 rounded-lg border border-[var(--border)] bg-[var(--card)] text-[10px] font-bold text-[var(--muted)]" data-segment="<?php echo htmlspecialchars($seg); ?>"><?php echo htmlspecialchars($seg); ?></button>
          <?php endforeach; ?>
        </div>
        <div class="grid grid-cols-2 gap-3 pt-0.5">
          <div class="space-y-1">
            <div class="flex items-center justify-between text-[10px] text-[var(--muted)]">
              <span class="inline-flex items-center gap-1"><i data-lucide="bed-double" class="w-3 h-3"></i>ห้องนอน ขั้นต่ำ</span>
              <span id="map-proj-bed-val" class="font-bold text-[var(--text)]">ทั้งหมด</span>
            </div>
            <input type="range" id="map-proj-bed" min="0" max="6" step="1" value="0"
                   class="w-full h-1.5 rounded-full appearance-none bg-[var(--border)] accent-[#E2E800] cursor-pointer"
                   aria-label="ห้องนอนขั้นต่ำ">
          </div>
          <div class="space-y-1">
            <div class="flex items-center justify-between text-[10px] text-[var(--muted)]">
              <span class="inline-flex items-center gap-1"><i data-lucide="bath" class="w-3 h-3"></i>ห้องน้ำ ขั้นต่ำ</span>
              <span id="map-proj-bath-val" class="font-bold text-[var(--text)]">ทั้งหมด</span>
            </div>
            <input type="range" id="map-proj-bath" min="0" max="6" step="1" value="0"
                   class="w-full h-1.5 rounded-full appearance-none bg-[var(--border)] accent-[#E2E800] cursor-pointer"
                   aria-label="ห้องน้ำขั้นต่ำ">
          </div>
        </div>
      </div>
      <div id="map-lead-filter" class="hidden space-y-1">
        <div class="flex items-center justify-between gap-2">
          <p class="text-[10px] text-[var(--faint)] flex items-center gap-1 min-w-0">
            <i data-lucide="calendar" class="w-3 h-3 shrink-0"></i>
            กรองวันที่ติดต่อเข้ามา (contact_date)
          </p>
          <button type="button" id="map-lead-reset"
                  class="shrink-0 inline-flex items-center gap-1 px-2 py-1 rounded-lg border border-[var(--border)] bg-[var(--card)] text-[10px] font-bold text-[var(--muted)]">
            <i data-lucide="rotate-ccw" class="w-3 h-3"></i>ล้างค่า
          </button>
        </div>
        <div class="flex flex-wrap gap-1.5">
          <button type="button" class="map-lead-date-preset px-2.5 py-1 rounded-lg border border-[var(--border)] bg-[var(--card)] text-[10px] font-bold text-[var(--muted)]" data-preset="7">7 วัน</button>
          <button type="button" class="map-lead-date-preset px-2.5 py-1 rounded-lg border border-[#E2E800] bg-[#E2E800]/15 text-[10px] font-bold text-[var(--accent-text)]" data-preset="30" data-on="1">30 วัน</button>
          <button type="button" class="map-lead-date-preset px-2.5 py-1 rounded-lg border border-[var(--border)] bg-[var(--card)] text-[10px] font-bold text-[var(--muted)]" data-preset="90">90 วัน</button>
          <button type="button" class="map-lead-date-preset px-2.5 py-1 rounded-lg border border-[var(--border)] bg-[var(--card)] text-[10px] font-bold text-[var(--muted)]" data-preset="all">ทั้งหมด</button>
        </div>
        <div class="flex flex-wrap items-center gap-2 text-[10px] text-[var(--muted)]">
          <label class="flex items-center gap-1">จาก <input type="date" id="map-lead-date-from" class="px-2 py-1 rounded-lg border border-[var(--border)] bg-[var(--bg)] text-[var(--text)] text-[10px]"></label>
          <label class="flex items-center gap-1">ถึง <input type="date" id="map-lead-date-to" class="px-2 py-1 rounded-lg border border-[var(--border)] bg-[var(--bg)] text-[var(--text)] text-[10px]"></label>
          <button type="button" id="map-lead-date-apply" class="px-2.5 py-1 rounded-lg border border-[var(--border)] bg-[var(--card)] font-bold text-[var(--text-2)]">ช่วงกำหนดเอง</button>
        </div>
        <p id="map-lead-filter-summary" class="text-[10px] text-[var(--faint)]"></p>
      </div>
      <p class="text-[10px] text-[var(--faint)] flex items-center gap-1">
        <i data-lucide="info" class="w-3 h-3 shrink-0"></i>
        เลือกชั้นข้อมูลทีละ 1 · Owner/Lead = CRM · Project = Survey
      </p>
    </div>
    <div id="map-stage" class="flex-1 min-h-0 flex flex-col gap-0">
      <div id="map-canvas-wrap" class="relative w-full min-h-0 border-b border-[var(--border)]" style="flex: var(--map-flex, 3) 1 0%;">
        <div id="map-canvas" class="absolute inset-0 bg-[var(--surface)]"></div>
        <button type="button" id="map-my-location" aria-label="ตำแหน่งปัจจุบัน"
                class="absolute z-20 left-3 bottom-3 w-10 h-10 rounded-xl bg-[var(--card)] border border-[var(--border)] shadow-md flex items-center justify-center text-[var(--text-2)] active:scale-95 transition disabled:opacity-50">
          <i data-lucide="locate-fixed" class="w-4 h-4"></i>
        </button>
        <p id="map-geo-hint" class="hidden absolute z-20 left-3 right-3 bottom-14 mx-auto max-w-xs text-center text-[10px] px-2.5 py-1.5 rounded-lg bg-[var(--card)] border border-[var(--border)] text-[var(--muted)] shadow pointer-events-none"></p>
        <p id="map-loading" class="absolute inset-0 z-10 flex items-center justify-center text-xs text-[var(--muted)] gap-2 pointer-events-none">
          <i data-lucide="loader-circle" class="w-4 h-4 animate-spin"></i>
          กำลังโหลดแผนที่…
        </p>
      </div>
      <div id="map-detail-sheet" class="shrink-0 flex flex-col bg-[var(--card)] overflow-hidden border-t border-[var(--border)]" data-state="idle">
        <button type="button" id="map-sheet-handle" aria-label="ลากปรับความสูงแผงรายละเอียด"
                class="shrink-0 py-1.5 flex justify-center touch-none cursor-grab active:cursor-grabbing">
          <span class="w-10 h-1 rounded-full bg-[var(--border)]"></span>
        </button>
        <div id="map-detail-scroll" class="flex-1 overflow-y-auto px-4 pb-1 min-h-0">
          <div id="map-detail-empty" class="text-center py-1.5 text-xs text-[var(--muted)]">
            <i data-lucide="map-pin" class="w-4 h-4 mx-auto mb-1 opacity-50"></i>
            <span class="lg:hidden">เลือกจุดบนแผนที่เพื่อดูรายละเอียด</span>
            <span class="hidden lg:block">เลือกจุดบนแผนที่<br>รายละเอียดจะแสดงที่นี่</span>
          </div>
          <div id="map-detail-panel" class="hidden space-y-2"></div>
        </div>
      </div>
    </div>
    <div id="map-load-err" class="hidden shrink-0 px-4 py-2 text-xs text-red-400 text-center leading-relaxed"></div>
    <p id="map-api-hint" class="hidden shrink-0 px-4 pb-2 text-[10px] text-[var(--faint)] text-center leading-relaxed">
      ถ้าแผนที่ว่าง: เปิด <b class="text-[var(--text-2)]">Maps JavaScript API</b> ใน Google Cloud แล้วเพิ่ม Referrer
      <span class="font-mono">localhost/*</span> และ <span class="font-mono">*.ngrok-free.dev/*</span>
    </p>
  </section>

  <!-- ===== หน้า Price Report / รายงานเมทัลชีท ===== -->
  <section id="page-report" class="page hidden flex flex-col min-h-0 overflow-hidden <?php echo $is_metal_sheet ? 'p-0' : 'p-0'; ?>">
    <?php if ($is_metal_sheet): ?>
    <div class="flex-1 min-h-0 overflow-y-auto px-4 py-4 space-y-4 pb-28">
      <div class="flex items-center justify-between gap-3">
        <div>
          <h1 class="text-lg font-bold">รายงานการทำงาน</h1>
          <p class="text-xs text-[var(--muted)]">สรุปรายวันและรายเดือน — สาขาเมทัลชีท</p>
        </div>
        <input id="ms-report-date" type="date" value="<?php echo htmlspecialchars($ms_report_date); ?>"
               class="text-xs bg-[var(--card)] border border-[var(--border)] rounded-lg px-2 py-1.5">
      </div>

      <div class="bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4 space-y-2">
        <label class="text-[11px] font-bold text-[var(--muted)] flex items-center gap-1.5" for="ms-sales-name">
          <i data-lucide="user" class="w-3.5 h-3.5"></i> ชื่อเซลล์ในรายงาน
        </label>
        <div class="flex gap-2">
          <input id="ms-sales-name" type="text" value="<?php echo htmlspecialchars($ms_sales_name); ?>"
                 class="flex-1 text-sm bg-[var(--surface)] border border-[var(--border)] rounded-xl px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-[#E2E800]">
          <button type="button" id="ms-save-name-btn"
                  class="shrink-0 px-3 py-2 rounded-xl bg-[#E2E800] text-[#141414] text-xs font-bold active:scale-95 transition">บันทึก</button>
        </div>
        <p class="text-[10px] text-[var(--faint)]">ดึงจากชื่อจริงที่ลงทะเบียน — แก้ได้ตามที่ใช้ในรายงานจริง</p>
      </div>

      <div class="bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4 space-y-3">
        <h2 class="text-sm font-bold flex items-center gap-1.5">
          <i data-lucide="bar-chart-3" class="w-4 h-4 text-[var(--accent-text)]"></i> สรุปจำนวนวันนี้
        </h2>
        <div class="grid grid-cols-2 gap-2 text-xs">
          <?php
          $ms_fields = [
              ['deposit_count', 'มัดจำ (เคาท์ดาวน์)', 'users'],
              ['survey_count', 'นัดสำรวจหน้างาน', 'map-pin'],
              ['collection_count', 'ส่งงานเก็บเงิน', 'truck'],
              ['chat_line_count', 'ตอบแชท LINE', 'message-circle'],
              ['chat_facebook_count', 'ตอบแชท Facebook', 'facebook'],
              ['chat_tel_count', 'ตอบแชท Tel', 'phone'],
          ];
          foreach ($ms_fields as [$fname, $flabel, $ficon]):
          ?>
          <label class="block">
            <span class="text-[var(--faint)] flex items-center gap-1 mb-1"><i data-lucide="<?php echo $ficon; ?>" class="w-3 h-3"></i><?php echo $flabel; ?></span>
            <input type="number" min="0" class="ms-stat-inp w-full bg-[var(--surface)] border border-[var(--border)] rounded-lg px-2.5 py-2" data-field="<?php echo $fname; ?>" value="<?php echo (int)($ms_stats[$fname] ?? 0); ?>">
          </label>
          <?php endforeach; ?>
        </div>
        <button type="button" id="ms-save-stats-btn" class="w-full py-2.5 rounded-xl bg-[var(--surface)] border border-[var(--border)] text-xs font-bold active:scale-95 transition">บันทึกสรุปจำนวน</button>
      </div>

      <div class="bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4 space-y-3">
        <h2 class="text-sm font-bold flex items-center gap-1.5">
          <i data-lucide="plus-circle" class="w-4 h-4 text-[var(--accent-text)]"></i> บันทึกมัดจำ / ส่งมอบงาน
        </h2>
        <div class="flex gap-2">
          <button type="button" class="ms-entry-type-btn flex-1 py-2 rounded-lg text-xs font-bold border border-[#E2E800] bg-[#E2E800]/15 text-[var(--accent-text)]" data-type="deposit">มัดจำ</button>
          <button type="button" class="ms-entry-type-btn flex-1 py-2 rounded-lg text-xs font-bold border border-[var(--border)] bg-[var(--surface)] text-[var(--muted)]" data-type="delivery">ส่งมอบงาน</button>
        </div>
        <input type="hidden" id="ms-entry-type" value="deposit">
        <div class="space-y-2 text-xs">
          <input id="ms-customer-name" type="text" placeholder="ชื่อลูกค้า" class="w-full bg-[var(--surface)] border border-[var(--border)] rounded-lg px-3 py-2.5">
          <input id="ms-location" type="text" placeholder="อยู่ที่ไหน / หน้างาน" class="w-full bg-[var(--surface)] border border-[var(--border)] rounded-lg px-3 py-2.5">
          <div class="grid grid-cols-2 gap-2">
            <input id="ms-amount" type="text" inputmode="numeric" autocomplete="off" placeholder="ยอดเงิน (บาท)" class="fmt-num bg-[var(--surface)] border border-[var(--border)] rounded-lg px-3 py-2.5">
            <input id="ms-work-date" type="date" class="bg-[var(--surface)] border border-[var(--border)] rounded-lg px-3 py-2.5">
          </div>
          <p class="text-[10px] text-[var(--faint)]">วันลงงาน = วันที่เริ่มติดตั้ง / ส่งมอบ</p>
        </div>
        <button type="button" id="ms-add-entry-btn" class="w-full py-2.5 rounded-xl bg-[#E2E800] text-[#141414] text-xs font-bold active:scale-95 transition">เพิ่มรายการ</button>
        <ul id="ms-entry-list" class="space-y-2">
          <?php foreach ($ms_entries as $ent): ?>
          <li class="text-xs bg-[var(--surface)] rounded-lg px-3 py-2 border border-[var(--border)]">
            <span class="font-bold"><?php echo $ent['entry_type'] === 'delivery' ? 'ส่งมอบ' : 'มัดจำ'; ?></span>
            · <?php echo htmlspecialchars($ent['customer_name'] ?: '-'); ?>
            · <?php echo htmlspecialchars($ent['location'] ?: '-'); ?>
            · ฿<?php echo number_format($ent['amount'], 0); ?>
            <?php if ($ent['work_date']): ?> · ลงงาน <?php echo branch_thai_short_date($ent['work_date']); ?><?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>

      <div class="bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4 space-y-2">
        <div class="flex items-center justify-between">
          <h2 class="text-sm font-bold flex items-center gap-1.5">
            <i data-lucide="file-text" class="w-4 h-4 text-[var(--accent-text)]"></i> ตัวอย่างรายงาน
          </h2>
          <button type="button" id="ms-copy-report-btn" class="text-[11px] font-bold text-[var(--accent-text)]">คัดลอก</button>
        </div>
        <pre id="ms-report-preview" class="text-xs whitespace-pre-wrap leading-relaxed text-[var(--text-2)] bg-[var(--surface)] rounded-xl p-3 border border-[var(--border)] font-sans"><?php echo htmlspecialchars($ms_preview_text); ?></pre>
      </div>

      <div class="bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4">
        <h2 class="text-sm font-bold mb-2 flex items-center gap-1.5">
          <i data-lucide="calendar-range" class="w-4 h-4 text-[var(--accent-text)]"></i> สรุปเดือนนี้
        </h2>
        <p class="text-xs text-[var(--muted)]">มัดจำ <b class="text-[var(--text-2)]"><?php echo (int)$ms_month['deposit_count']; ?> คน</b> · ฿<?php echo number_format($ms_month['deposit_total'], 0); ?></p>
        <p class="text-xs text-[var(--muted)] mt-1">ส่งมอบงาน <b class="text-[var(--text-2)]"><?php echo (int)$ms_month['delivery_count']; ?> คน</b> · ฿<?php echo number_format($ms_month['delivery_total'], 0); ?></p>
      </div>
    </div>
    <?php else: ?>
    <div class="px-4 pt-2 pb-2.5 shrink-0 border-b border-[var(--border)] bg-[var(--bg)]">
      <p class="text-xs text-[var(--muted)] leading-relaxed">สร้างรายงานเปรียบเทียบราคาตลาด consult เจ้าของก่อนปรับราคาหรือลงประกาศ</p>
    </div>
    <iframe id="price-report-frame" data-src="price-report/index.html" src="about:blank" title="รายงานราคาขายบ้าน"
            class="flex-1 min-h-0 w-full border-0 bg-[var(--bg)]"></iframe>
    <?php endif; ?>
  </section>

</div>

<!-- ===== Product infowindow — รายละเอียดทรัพย์เต็ม ===== -->
<div id="product-detail" class="fixed inset-0 z-[75] hidden">
  <div id="product-detail-backdrop" class="absolute inset-0 bg-black/60"></div>
  <div class="app-sheet app-sheet--infowin absolute inset-x-0 bottom-0 top-8 left-1/2 -translate-x-1/2 w-full max-w-md bg-[var(--bg)] border-t border-[var(--border)] rounded-t-3xl flex flex-col max-h-[92vh]">
    <div class="flex items-center gap-3 px-5 py-4 border-b border-[var(--border)] shrink-0">
      <button id="product-detail-close" type="button" class="w-9 h-9 rounded-full bg-[var(--card)] border border-[var(--border)] flex items-center justify-center text-[var(--text-2)] active:scale-95 transition">
        <i data-lucide="arrow-left" class="w-4 h-4"></i>
      </button>
      <div class="min-w-0 flex-1">
        <h2 id="pd-title" class="infowin-header-title text-sm font-bold truncate leading-snug">รายละเอียดทรัพย์</h2>
        <p class="flex flex-wrap items-center gap-x-2 gap-y-0.5 mt-0.5">
          <span id="pd-code" class="text-[11px] text-[var(--faint)] font-mono"></span>
          <span id="pd-header-price" class="infowin-desktop-only text-sm font-bold text-[var(--accent-text)]"></span>
        </p>
      </div>
      <span id="pd-urgency" class="hidden text-[10px] font-bold px-2 py-1 rounded-full shrink-0"></span>
    </div>
    <div id="product-detail-body" class="overflow-y-auto flex-1 px-5 py-4 text-sm">
      <div class="infowin-layout space-y-5 lg:space-y-0">

      <div class="infowin-aside space-y-4">
      <!-- รูปภาพ -->
      <section>
        <h3 class="text-[11px] font-bold text-[var(--muted)] mb-2 flex items-center gap-1.5">
          <i data-lucide="images" class="w-3.5 h-3.5"></i> รูปภาพ
        </h3>
        <div id="pd-cover-wrap" class="infowin-cover-desktop rounded-xl overflow-hidden bg-[var(--surface)] border border-[var(--border)] aspect-video flex items-center justify-center mb-2">
          <img id="pd-cover" src="" alt="" class="w-full h-full object-cover hidden">
          <div id="pd-cover-ph" class="flex flex-col items-center text-[var(--faint)] py-8">
            <i data-lucide="building-2" class="w-10 h-10 mb-2"></i>
            <span class="text-xs">ยังไม่มีรูปปก</span>
          </div>
        </div>
        <a id="pd-photos-link" href="#" target="_blank" rel="noopener"
           class="hidden w-full flex items-center justify-center gap-2 py-2.5 rounded-xl border border-[var(--border)] bg-[var(--card)] text-xs font-bold text-[var(--accent-text)] active:scale-[0.98] transition">
          <i data-lucide="folder-open" class="w-4 h-4"></i> ดูรูปทั้งหมด (Google Drive)
        </a>
      </section>

      <!-- ราคา (sidebar บน desktop) -->
      <section class="bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4 space-y-2 infowin-aside-price">
        <h3 class="text-[11px] font-bold text-[var(--muted)] flex items-center gap-1.5">
          <i data-lucide="banknote" class="w-3.5 h-3.5"></i> ราคา
        </h3>
        <div class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-xs">
          <span class="text-[var(--faint)]">ราคาขาย (เรา)</span><span id="pd-price" class="font-bold text-base">-</span>
          <span class="text-[var(--faint)]">ราคาเช่า</span><span id="pd-rent">-</span>
          <span class="text-[var(--faint)]">ราคาเจ้าของตั้ง</span><span id="pd-owner-price">-</span>
          <span class="text-[var(--faint)]">ค่าธรรมเนียมโอน</span><span id="pd-transfer">-</span>
          <span class="text-[var(--faint)]">สถานะ (EN)</span><span id="pd-sales-en" class="font-mono text-[10px]">-</span>
        </div>
      </section>

      <!-- ที่ตั้ง (sidebar บน desktop) -->
      <section class="bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4 infowin-aside-map">
        <h3 class="text-[11px] font-bold text-[var(--muted)] mb-2 flex items-center gap-1.5">
          <i data-lucide="map-pin" class="w-3.5 h-3.5"></i> ที่ตั้ง
        </h3>
        <a id="pd-map-link" href="#" target="_blank" rel="noopener"
           class="hidden w-full flex items-center justify-center gap-2 py-2.5 rounded-xl bg-[#E2E800] text-[#141414] text-xs font-bold active:scale-[0.98] transition">
          <i data-lucide="external-link" class="w-4 h-4"></i> เปิดแผนที่
        </a>
        <p id="pd-map-none" class="text-xs text-[var(--faint)]">ยังไม่มีลิงก์แผนที่</p>
      </section>
      </div>

      <div class="infowin-main space-y-5 lg:space-y-0">
      <!-- เจ้าของ -->
      <section class="bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4 space-y-2">
        <h3 class="text-[11px] font-bold text-[var(--muted)] flex items-center gap-1.5">
          <i data-lucide="user" class="w-3.5 h-3.5"></i> เจ้าของทรัพย์
        </h3>
        <div class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-xs">
          <span class="text-[var(--faint)]">ชื่อ</span><span id="pd-owner-name" class="font-medium">-</span>
          <span class="text-[var(--faint)]">เบอร์</span>
          <span id="pd-phone-wrap" class="inline-flex items-center gap-1">-</span>
          <span class="text-[var(--faint)]">Line ID</span>
          <span id="pd-line-wrap" class="inline-flex items-center gap-1">-</span>
        </div>
      </section>

      <section id="pd-linked-leads-section" class="hidden bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4 space-y-2">
        <h3 class="text-[11px] font-bold text-[var(--muted)] flex items-center gap-1.5">
          <i data-lucide="users" class="w-3.5 h-3.5"></i> ลูกค้าที่สนใจทรัพย์นี้
          <span id="pd-linked-leads-count" class="text-[10px] font-normal text-[var(--faint)]"></span>
        </h3>
        <ul id="pd-linked-leads" class="space-y-2"></ul>
      </section>

      <!-- ที่อยู่ -->
      <section class="bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4 space-y-2">
        <h3 class="text-[11px] font-bold text-[var(--muted)] flex items-center gap-1.5">
          <i data-lucide="map-pin" class="w-3.5 h-3.5"></i> ที่อยู่
        </h3>
        <div class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-xs">
          <span class="text-[var(--faint)]">ซอย</span><span id="pd-soi">-</span>
          <span class="text-[var(--faint)]" id="pd-unit-label">เลขที่</span><span id="pd-unit-no" class="font-medium">-</span>
          <span id="pd-floor-label" class="text-[var(--faint)] hidden">ชั้น</span>
          <span id="pd-floor" class="hidden">-</span>
          <span class="text-[var(--faint)]" id="pd-direction-label">ทิศ</span>
          <span id="pd-direction" class="inline-flex items-center gap-1">-</span>
        </div>
      </section>

      <!-- แหล่งที่มา Listing -->
      <section class="bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4 space-y-2">
        <h3 class="text-[11px] font-bold text-[var(--muted)] flex items-center gap-1.5">
          <i data-lucide="inbox" class="w-3.5 h-3.5"></i> ข้อมูล Listing
        </h3>
        <div class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-xs">
          <span class="text-[var(--faint)]">ช่องทาง</span><span id="pd-source">-</span>
          <span class="text-[var(--faint)]">วันที่ได้ Listing</span><span id="pd-listing-date">-</span>
          <span class="text-[var(--faint)]">วันที่ทำการตลาด</span><span id="pd-marketing-date">-</span>
          <span class="text-[var(--faint)]">โฉนด</span><span id="pd-deed" class="inline-flex items-center gap-1">-</span>
          <span class="text-[var(--faint)]">สถานะการตลาด</span><span id="pd-mkt-status">-</span>
        </div>
        <p id="pd-incomplete-wrap" class="hidden mt-2 text-[11px] text-amber-400/90 flex items-start gap-1.5">
          <i data-lucide="alert-triangle" class="w-3.5 h-3.5 shrink-0 mt-0.5"></i>
          <span><strong>ข้อมูลที่ยังขาด:</strong> <span id="pd-incomplete"></span></span>
        </p>
      </section>

      <!-- เกรดความเร่งด่วน -->
      <section id="pd-urgency-section" class="hidden bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4">
        <h3 class="text-[11px] font-bold text-[var(--muted)] mb-2 flex items-center gap-1.5">
          <i data-lucide="gauge" class="w-3.5 h-3.5"></i> เกรดความเร่งด่วน
        </h3>
        <div id="pd-urgency-body" class="flex items-start gap-3">
          <span id="pd-urgency-badge" class="text-xs font-bold px-2.5 py-1.5 rounded-full shrink-0 inline-flex items-center gap-1">-</span>
          <div class="min-w-0 text-xs space-y-0.5">
            <p id="pd-urgency-timeline" class="font-bold">-</p>
            <p id="pd-urgency-desc" class="text-[var(--muted)] leading-snug">-</p>
          </div>
        </div>
      </section>

      <!-- ติดต่อ & Follow -->
      <section class="infowin-span-2 bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4 space-y-3">
        <h3 class="text-[11px] font-bold text-[var(--muted)] flex items-center gap-1.5">
          <i data-lucide="phone-call" class="w-3.5 h-3.5"></i> ติดต่อ &amp; Follow
        </h3>
        <div class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-xs">
          <span class="text-[var(--faint)]">ล่าสุดติดต่อ</span><span id="pd-last-contact" class="font-medium">-</span>
          <span class="text-[var(--faint)]">ผลการติดต่อ</span><span id="pd-contact-summary" class="leading-snug">-</span>
          <span class="text-[var(--faint)]">Consult ราคา</span><span id="pd-price-consult" class="leading-snug">-</span>
        </div>
        <div>
          <p class="text-[10px] font-bold text-[var(--faint)] mb-2 flex items-center gap-1">
            <i data-lucide="history" class="w-3 h-3"></i> ประวัติการติดต่อ
          </p>
          <ul id="pd-contact-history" class="space-y-2 text-xs"></ul>
          <p id="pd-contact-empty" class="hidden text-xs text-[var(--faint)]">ยังไม่มีประวัติการติดต่อ</p>
        </div>
      </section>

      <!-- ประวัติปรับราคา -->
      <section class="infowin-span-2 bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4 space-y-2">
        <h3 class="text-[11px] font-bold text-[var(--muted)] flex items-center gap-1.5">
          <i data-lucide="trending-down" class="w-3.5 h-3.5"></i> ประวัติปรับราคา
        </h3>
        <ul id="pd-price-history" class="space-y-2 text-xs"></ul>
        <p id="pd-price-empty" class="text-xs text-[var(--faint)]">ยังไม่มีประวัติปรับราคา</p>
      </section>

      <!-- ขายแล้ว -->
      <section id="pd-sold-section" class="hidden bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4 space-y-2">
        <h3 class="text-[11px] font-bold text-[var(--muted)] flex items-center gap-1.5">
          <i data-lucide="check-circle" class="w-3.5 h-3.5"></i> ขายแล้ว
        </h3>
        <div class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-xs">
          <span class="text-[var(--faint)]">วันที่ขายได้</span><span id="pd-sold-date">-</span>
          <span class="text-[var(--faint)]">ใครขาย</span><span id="pd-sold-by">-</span>
          <span class="text-[var(--faint)]">ราคาจบ</span><span id="pd-sold-price" class="font-bold">-</span>
        </div>
      </section>

      <!-- โครงการ -->
      <section class="bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4 space-y-2">
        <h3 class="text-[11px] font-bold text-[var(--muted)] flex items-center gap-1.5">
          <i data-lucide="building-2" class="w-3.5 h-3.5"></i> โครงการ
        </h3>
        <div class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-xs">
          <span class="text-[var(--faint)]">ชื่อ EN</span><span id="pd-name-en" class="font-medium">-</span>
          <span class="text-[var(--faint)]">ชื่อ TH</span><span id="pd-name-th">-</span>
          <span class="text-[var(--faint)]">ประเภท</span><span id="pd-type">-</span>
          <span class="text-[var(--faint)]">โซน</span><span id="pd-zone">-</span>
          <span class="text-[var(--faint)]">สถานะ</span>
          <span id="pd-status" class="inline-flex items-center gap-1 font-bold">-</span>
          <span class="text-[var(--faint)]">รหัส</span><span id="pd-code2" class="font-mono">-</span>
        </div>
      </section>

      <!-- พื้นที่ & ห้อง -->
      <section class="bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4 space-y-2">
        <h3 class="text-[11px] font-bold text-[var(--muted)] flex items-center gap-1.5">
          <i data-lucide="layout-grid" class="w-3.5 h-3.5"></i> พื้นที่ &amp; ห้อง
        </h3>
        <div class="grid grid-cols-2 gap-2 text-xs">
          <div class="bg-[var(--surface)] rounded-xl p-2.5 text-center">
            <p class="text-[var(--faint)] text-[10px]">ไร่·งาน·ตร.ว.</p>
            <p id="pd-area-land" class="font-bold mt-0.5">-</p>
          </div>
          <div class="bg-[var(--surface)] rounded-xl p-2.5 text-center">
            <p class="text-[var(--faint)] text-[10px]">ตร.ม.</p>
            <p id="pd-area-sqm" class="font-bold mt-0.5">-</p>
          </div>
          <div class="bg-[var(--surface)] rounded-xl p-2.5 text-center">
            <p class="text-[var(--faint)] text-[10px] flex items-center justify-center gap-1"><i data-lucide="bed-double" class="w-3 h-3"></i>นอน</p>
            <p id="pd-bed" class="font-bold mt-0.5">-</p>
          </div>
          <div class="bg-[var(--surface)] rounded-xl p-2.5 text-center">
            <p class="text-[var(--faint)] text-[10px] flex items-center justify-center gap-1"><i data-lucide="bath" class="w-3 h-3"></i>น้ำ</p>
            <p id="pd-bath" class="font-bold mt-0.5">-</p>
          </div>
          <div class="bg-[var(--surface)] rounded-xl p-2.5 text-center">
            <p class="text-[var(--faint)] text-[10px] flex items-center justify-center gap-1"><i data-lucide="sparkles" class="w-3 h-3"></i>แม่บ้าน</p>
            <p id="pd-maid" class="font-bold mt-0.5">-</p>
          </div>
          <div class="bg-[var(--surface)] rounded-xl p-2.5 text-center">
            <p class="text-[var(--faint)] text-[10px] flex items-center justify-center gap-1"><i data-lucide="car" class="w-3 h-3"></i>จอดรถ</p>
            <p id="pd-parking" class="font-bold mt-0.5">-</p>
          </div>
        </div>
      </section>

      </div>
      </div>
    </div>
    <div class="shrink-0 border-t border-[var(--border)] px-5 py-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))] flex gap-2">
      <button type="button" id="product-edit-open" class="flex-1 flex items-center justify-center gap-2 py-2.5 rounded-xl bg-[#E2E800] text-[#141414] text-xs font-bold active:scale-[0.98] transition">
        <i data-lucide="pencil" class="w-4 h-4"></i> แก้ไขข้อมูล
      </button>
    </div>
  </div>
</div>

<!-- ===== Product edit sheet ===== -->
<div id="product-edit" class="fixed inset-0 z-[80] hidden">
  <div id="product-edit-backdrop" class="absolute inset-0 bg-black/60"></div>
  <div class="app-sheet app-sheet--drawer absolute inset-x-0 bottom-0 top-6 left-1/2 -translate-x-1/2 w-full max-w-md bg-[var(--bg)] border-t border-[var(--border)] rounded-t-3xl flex flex-col max-h-[94vh]">
    <div class="flex items-center gap-3 px-5 py-4 border-b border-[var(--border)] shrink-0">
      <button type="button" id="product-edit-close" class="w-9 h-9 rounded-full bg-[var(--card)] border border-[var(--border)] flex items-center justify-center">
        <i data-lucide="x" class="w-4 h-4"></i>
      </button>
      <div class="min-w-0 flex-1">
        <h2 class="text-sm font-bold">แก้ไขทรัพย์</h2>
        <p id="edit-code-label" class="text-[11px] text-[var(--faint)] font-mono"></p>
      </div>
    </div>
    <form id="product-edit-form" class="overflow-y-auto flex-1 px-5 py-4 space-y-4 text-sm pb-4">
      <input type="hidden" id="edit-owner-id" name="owner_id" value="">
      <p class="text-[11px] text-[var(--muted)]">แก้ในเว็บได้เลย ไม่ต้องกลับไปแก้ใน LINE</p>

      <div class="rounded-2xl border border-[rgba(226,232,0,0.35)] bg-[rgba(226,232,0,0.06)] p-3 space-y-2">
        <label for="owner-magic-paste" class="text-[11px] font-bold flex items-center gap-1.5 text-[var(--text-2)]">
          <i data-lucide="sparkles" class="w-3.5 h-3.5 text-[var(--accent-text)]" aria-hidden="true"></i>
          วางข้อความดิบให้ AI กรอกออโต้
        </label>
        <textarea id="owner-magic-paste" class="edit-inp min-h-[88px] text-xs" rows="4"
                  placeholder="เช่น ขายบ้านเพฟ รามอินทรา 35 ตร.ว. 3 นอน 2 น้ำ 5 ล้าน ติดต่อคุณต้อง 081…"></textarea>
        <button type="button" id="owner-magic-fill"
                class="w-full py-2.5 rounded-xl bg-[#141414] text-[#E2E800] text-xs font-bold active:scale-[0.98] transition">
          ⚡ AI กรอกฟอร์ม (เฉพาะช่องว่าง)
        </button>
        <p id="owner-magic-status" class="text-[10px] text-[var(--faint)] leading-snug" role="status"></p>
      </div>

      <fieldset class="space-y-2">
        <legend class="text-[11px] font-bold text-[var(--muted)]">เจ้าของ</legend>
        <input id="edit-owner-name" class="edit-inp" placeholder="ชื่อเจ้าของ">
        <div class="grid grid-cols-2 gap-2">
          <input id="edit-phone" class="edit-inp" placeholder="เบอร์โทร">
          <input id="edit-line-id" class="edit-inp" placeholder="Line ID">
        </div>
      </fieldset>

      <fieldset class="space-y-2">
        <legend class="text-[11px] font-bold text-[var(--muted)]">โครงการ</legend>
        <input id="edit-name-en" class="edit-inp" placeholder="ชื่อ EN">
        <input id="edit-name-th" class="edit-inp" placeholder="ชื่อ TH">
        <div class="grid grid-cols-2 gap-2">
          <input id="edit-type" class="edit-inp" placeholder="ประเภท">
          <input id="edit-zone" class="edit-inp" placeholder="โซน">
        </div>
      </fieldset>

      <fieldset class="space-y-2">
        <legend class="text-[11px] font-bold text-[var(--muted)]">ที่อยู่</legend>
        <input id="edit-soi" class="edit-inp" placeholder="ซอย">
        <div class="grid grid-cols-2 gap-2">
          <input id="edit-unit" class="edit-inp" placeholder="เลขที่/ห้อง">
          <input id="edit-floor" class="edit-inp" placeholder="ชั้น">
        </div>
        <input id="edit-direction" class="edit-inp" placeholder="ทิศ (ระเบียง/หน้าบ้าน)">
        <input id="edit-map" class="edit-inp" placeholder="ลิงก์แผนที่">
      </fieldset>

      <fieldset class="space-y-2">
        <legend class="text-[11px] font-bold text-[var(--muted)]">ราคา &amp; สถานะ</legend>
        <input id="edit-price" class="edit-inp fmt-num" inputmode="numeric" placeholder="ราคาขาย (ตัวเลขเต็ม)">
        <input id="edit-rent" class="edit-inp fmt-num" inputmode="numeric" placeholder="ราคาเช่า/เดือน">
        <input id="edit-owner-price" class="edit-inp fmt-num" inputmode="numeric" placeholder="ราคาเจ้าของตั้ง">
        <select id="edit-sales-status" class="edit-inp">
          <option value="Sale">Sale</option>
          <option value="sale&available">Sale &amp; Rent (ขายและเช่า)</option>
          <option value="sale with tenant">Sale With Tenant (ขายพร้อมผู้เช่า)</option>
          <option value="rent">Rent</option>
          <option value="cancel">Cancel</option>
          <option value="sold">Sold</option>
        </select>
        <select id="edit-urgency" class="edit-inp">
          <option value="">— ยังไม่ระบุ —</option>
          <option value="A">A · Hot</option>
          <option value="B">B · Warm</option>
          <option value="C">C · Cold</option>
        </select>
        <input id="edit-transfer" class="edit-inp" placeholder="ค่าธรรมเนียมโอน">
      </fieldset>

      <fieldset class="space-y-2">
        <legend class="text-[11px] font-bold text-[var(--muted)]">ติดต่อ &amp; Listing</legend>
        <input id="edit-last-contact" type="date" class="edit-inp">
        <textarea id="edit-contact-summary" class="edit-inp min-h-[72px]" placeholder="ผลการติดต่อล่าสุด"></textarea>
        <textarea id="edit-price-consult" class="edit-inp min-h-[56px]" placeholder="Consult ราคา"></textarea>
        <select id="edit-listing-source" class="edit-inp">
          <option value="">— ช่องทาง —</option>
          <option value="survey">Survey</option>
          <option value="fb">Facebook</option>
          <option value="livinginsider">LivingInsider</option>
          <option value="other">เว็บอื่นๆ</option>
        </select>
        <select id="edit-marketing-status" class="edit-inp">
          <option value="ลงการตลาดแล้ว">ลงการตลาดแล้ว</option>
          <option value="ข้อมูลยังไม่ครบ">ข้อมูลยังไม่ครบ</option>
        </select>
        <textarea id="edit-incomplete" class="edit-inp min-h-[56px]" placeholder="ข้อมูลที่ยังขาด"></textarea>
        <select id="edit-has-deed" class="edit-inp">
          <option value="">— โฉนด —</option>
          <option value="1">มีโฉนด</option>
          <option value="0">ไม่มีโฉนด</option>
        </select>
      </fieldset>

      <fieldset class="space-y-2">
        <legend class="text-[11px] font-bold text-[var(--muted)]">รูปภาพ (ลิงก์ Drive)</legend>
        <a id="edit-drive-open" href="#" target="_blank" rel="noopener"
           class="w-full flex items-center justify-center gap-2 py-2.5 rounded-xl border border-[var(--border)] bg-[var(--card)] text-xs font-bold text-[var(--accent-text)]">
          <i data-lucide="upload" class="w-4 h-4"></i> เปิดโฟลเดอร์ Drive อัปโหลดรูป
        </a>
        <p id="edit-drive-hint" class="text-[10px] text-[var(--faint)] leading-snug"></p>
        <input id="edit-cover-url" class="edit-inp" placeholder="ลิงก์รูปปก (URL รูปหรือ Drive)">
        <input id="edit-photos-link" class="edit-inp" placeholder="ลิงก์โฟลเดอร์รูปทั้งหมด">
      </fieldset>
    </form>
    <div class="shrink-0 border-t border-[var(--border)] px-5 py-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))]">
      <button type="submit" form="product-edit-form" class="w-full py-3 rounded-xl bg-[#E2E800] text-[#141414] text-sm font-bold active:scale-[0.98] transition">บันทึก</button>
    </div>
  </div>
</div>

<!-- ===== Lead infowindow ===== -->
<div id="lead-detail" class="fixed inset-0 z-[75] hidden">
  <div id="lead-detail-backdrop" class="absolute inset-0 bg-black/60"></div>
  <div class="app-sheet app-sheet--infowin relative absolute inset-x-0 bottom-0 top-8 left-1/2 -translate-x-1/2 w-full max-w-md bg-[var(--bg)] border-t border-[var(--border)] rounded-t-3xl flex flex-col max-h-[92vh]">
    <div class="flex items-center gap-3 px-5 py-4 border-b border-[var(--border)] shrink-0">
      <button id="lead-detail-close" type="button" class="w-9 h-9 rounded-full bg-[var(--card)] border border-[var(--border)] flex items-center justify-center active:scale-95 transition">
        <i data-lucide="arrow-left" class="w-4 h-4"></i>
      </button>
      <div class="min-w-0 flex-1">
        <h2 id="ld-title" class="infowin-header-title text-sm font-bold truncate leading-snug">รายละเอียด Lead</h2>
        <p class="flex flex-wrap items-center gap-x-2 gap-y-0.5 mt-0.5">
          <span id="ld-code" class="text-[11px] text-[var(--faint)] truncate hidden"></span>
          <span id="ld-header-budget" class="infowin-desktop-only text-sm font-bold text-[var(--accent-text)]"></span>
        </p>
      </div>
      <button type="button" id="ld-group-badge" aria-expanded="false" title="กดดูเคสอื่นในกลุ่มเดียวกัน"
              class="hidden text-[10px] font-bold px-2 py-1 rounded-full shrink-0 border border-[rgba(226,232,0,0.45)] bg-[rgba(226,232,0,0.1)] text-[var(--accent-text)] items-center gap-1">
        <i data-lucide="link" class="w-3 h-3"></i>
        <span id="ld-group-badge-text"></span>
        <i data-lucide="chevron-down" class="w-3 h-3 ld-group-badge-chevron" aria-hidden="true"></i>
      </button>
      <span id="ld-grade" class="text-[10px] font-bold px-2 py-1 rounded-full shrink-0"></span>
    </div>
    <div id="ld-group-panel" class="hidden border-b border-[var(--border)] bg-[var(--surface)] shrink-0">
      <div class="px-5 py-3">
        <p class="text-[10px] font-bold text-[var(--muted)] mb-2 flex items-center gap-1.5">
          <i data-lucide="users" class="w-3.5 h-3.5"></i>
          เคสอื่นในกลุ่มเดียวกัน · เบอร์เดียวกัน
        </p>
        <div id="ld-group-list" class="space-y-2"></div>
      </div>
    </div>
    <div id="lead-detail-body" class="overflow-y-auto flex-1 px-5 py-4 text-sm">
      <div class="infowin-layout space-y-5 lg:space-y-0">

      <div class="infowin-aside space-y-4">
      <section>
        <h3 class="text-[11px] font-bold text-[var(--muted)] mb-2 flex items-center gap-1.5">
          <i data-lucide="images" class="w-3.5 h-3.5"></i> รูปแชท / ทรัพย์ที่ลูกค้าสนใจ
        </h3>
        <div id="ld-cover-wrap" class="infowin-cover-desktop rounded-xl overflow-hidden bg-[var(--surface)] border border-[var(--border)] aspect-video flex items-center justify-center mb-2">
          <img id="ld-cover" src="" alt="" class="w-full h-full object-cover hidden">
          <div id="ld-cover-ph" class="flex flex-col items-center text-[var(--faint)] py-8">
            <i data-lucide="message-square" class="w-10 h-10 mb-2"></i>
            <span class="text-xs">ยังไม่มีรูปแชท</span>
          </div>
        </div>
        <a id="ld-photos-link" href="#" target="_blank" rel="noopener"
           class="hidden w-full flex items-center justify-center gap-2 py-2.5 rounded-xl border border-[var(--border)] bg-[var(--card)] text-xs font-bold text-[var(--accent-text)]">
          <i data-lucide="folder-open" class="w-4 h-4"></i> ดูรูปทั้งหมด (Google Drive)
        </a>
      </section>

      <section class="bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4 space-y-2">
        <h3 class="text-[11px] font-bold text-[var(--muted)] flex items-center gap-1.5">
          <i data-lucide="contact" class="w-3.5 h-3.5 shrink-0 text-[var(--muted)]"></i> ข้อมูล
        </h3>
        <div class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-xs">
          <span class="text-[var(--faint)]">ชื่อลูกค้า</span><span id="ld-name" class="font-bold">-</span>
          <span class="text-[var(--faint)]">โครงการ</span><span id="ld-project" class="font-medium">-</span>
          <span class="text-[var(--faint)]">รหัสทรัพย์</span><span id="ld-owner-code-wrap" class="font-mono font-medium">-</span>
          <p id="ld-owner-link-hint" class="hidden col-span-2 text-[10px] text-[var(--muted)] leading-snug flex items-start gap-1.5 rounded-lg border border-[var(--border)] bg-[var(--surface)] px-2.5 py-1.5">
            <i data-lucide="link-2" class="w-3.5 h-3.5 shrink-0 mt-px" aria-hidden="true"></i>
            <span id="ld-owner-link-hint-text"></span>
          </p>
          <span id="ld-units-sent-aside-label" class="text-[var(--faint)] hidden">เสนอไปแล้ว</span><span id="ld-units-sent-aside" class="font-medium hidden">-</span>
          <span id="ld-offered-aside-label" class="text-[var(--faint)] hidden">รายการที่เสนอ</span><span id="ld-offered-aside" class="hidden text-[11px] leading-snug whitespace-pre-wrap">-</span>
          <span id="ld-product-price-label" class="text-[var(--faint)] hidden">ราคาทรัพย์</span><span id="ld-product-price" class="font-bold hidden">-</span>
          <span id="ld-phone-label" class="text-[var(--faint)] hidden">เบอร์</span>
          <span id="ld-phone-wrap" class="font-medium hidden"></span>
          <div id="ld-phone-warn" class="hidden col-span-2 text-[10px] text-[var(--muted)] leading-snug flex items-start gap-1.5 rounded-lg border border-[var(--border)] bg-[var(--surface)] px-2.5 py-1.5">
            <i data-lucide="alert-triangle" class="w-3.5 h-3.5 shrink-0 mt-px"></i>
            <span>เบอร์อาจจะไม่ถูกต้อง กรุณาตรวจสอบอีกครั้ง</span>
          </div>
          <span id="ld-line-label" class="text-[var(--faint)] hidden">LINE</span>
          <span id="ld-line-wrap" class="font-medium hidden"></span>
          <span id="ld-budget-aside-label" class="text-[var(--faint)] hidden">งบประมาณ</span><span id="ld-budget-aside" class="font-bold hidden"></span>
          <span id="ld-inbound-aside-label" class="text-[var(--faint)] hidden">วันที่เข้ามา</span><span id="ld-inbound-aside" class="hidden"></span>
        </div>
      </section>
      </div>

      <div class="infowin-main space-y-5 lg:space-y-0">
      <section id="ld-case-section" class="infowin-span-2 bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4 space-y-3">
        <div class="flex items-start justify-between gap-2">
          <h3 class="text-[11px] font-bold text-[var(--muted)] flex items-center gap-1.5">
            <i data-lucide="clipboard-list" class="w-3.5 h-3.5"></i> ข้อมูลเคส
            <span class="text-[10px] font-normal text-[var(--faint)]">· Potential, Pain, งบ ฯลฯ</span>
          </h3>
          <button type="button" id="ld-case-edit-btn" class="hidden shrink-0">
            <i data-lucide="pen-line" class="w-3 h-3"></i> แก้ไข
          </button>
        </div>
        <div id="ld-case-view-panel" class="ld-case-grid ld-case-grid--wide gap-3 text-xs hidden">
          <div class="ld-case-view-field ld-case-span-3">
            <p class="text-[10px] font-bold text-[var(--accent-text)] mb-1 flex items-center gap-1"><i data-lucide="gauge" class="w-3 h-3"></i> POTENTIAL</p>
            <div id="ld-case-view-potential" class="ld-case-view-val"></div>
          </div>
          <div class="ld-case-view-field ld-case-span-3">
            <p class="text-[10px] font-bold text-[var(--accent-text)] mb-1 flex items-center gap-1"><i data-lucide="tag" class="w-3 h-3"></i> สำหรับ Lead สถานะอื่นๆ</p>
            <div id="ld-case-view-aux-tag" class="ld-case-view-val"></div>
            <div id="ld-case-view-agent-wrap" class="hidden mt-2 pt-2 border-t border-[var(--border)]">
              <p class="text-[10px] font-bold text-[var(--muted)] mb-1.5 flex items-center gap-1">
                <i data-lucide="user-plus" class="w-3 h-3"></i> ลูกค้าเอเจนต์
              </p>
              <div class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">
                <span class="text-[var(--faint)]">ชื่อลูกค้า</span><span id="ld-case-view-agent-name" class="font-medium">-</span>
                <span class="text-[var(--faint)]">เบอร์ท้าย 4</span><span id="ld-case-view-agent-phone" class="font-mono font-bold">-</span>
              </div>
            </div>
          </div>
          <div class="ld-case-view-field">
            <p class="text-[10px] font-bold text-[var(--accent-text)] mb-1">BACKGROUND</p>
            <div id="ld-case-view-background" class="ld-case-view-val"></div>
          </div>
          <div class="ld-case-view-field">
            <p class="text-[10px] font-bold text-[var(--accent-text)] mb-1">PAIN POINT</p>
            <div id="ld-case-view-pain" class="ld-case-view-val"></div>
          </div>
          <div class="ld-case-view-field">
            <p class="text-[10px] font-bold text-[var(--accent-text)] mb-1">REQUIREMENT</p>
            <div id="ld-case-view-requirement" class="ld-case-view-val"></div>
          </div>
          <div class="ld-case-view-field">
            <p class="text-[10px] font-bold text-[var(--accent-text)] mb-1">FINANCIAL</p>
            <div id="ld-case-view-financial" class="ld-case-view-val"></div>
          </div>
          <div class="ld-case-view-field">
            <p class="text-[10px] font-bold text-[var(--accent-text)] mb-1">TIMELINE</p>
            <div id="ld-case-view-timeline" class="ld-case-view-val"></div>
          </div>
          <div class="ld-case-view-field">
            <p class="text-[10px] font-bold text-[var(--accent-text)] mb-1">BUDGET</p>
            <div id="ld-case-view-budget" class="ld-case-view-val"></div>
          </div>
          <div id="ld-case-view-offer-wrap" class="ld-case-view-field ld-case-span-3 hidden">
            <p class="text-[10px] font-bold text-[var(--accent-text)] mb-1 flex items-center gap-1"><i data-lucide="building-2" class="w-3 h-3"></i> ทรัพย์ &amp; การเสนอ</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
              <div>
                <p class="text-[10px] text-[var(--faint)] mb-0.5">รหัสทรัพย์ที่สนใจ</p>
                <div id="ld-case-view-owner-code" class="ld-case-view-val font-mono">-</div>
              </div>
              <div>
                <p class="text-[10px] text-[var(--faint)] mb-0.5">เสนอไปกี่หลังแล้ว</p>
                <div id="ld-case-view-units-sent" class="ld-case-view-val">-</div>
              </div>
            </div>
            <div class="mt-2">
              <p class="text-[10px] text-[var(--faint)] mb-0.5">เสนอที่ไหนบ้าง</p>
              <div id="ld-case-view-offered-listings" class="ld-case-view-val whitespace-pre-wrap">-</div>
            </div>
          </div>
          <div id="ld-case-next-wrap" class="ld-case-span-3 bg-[var(--surface)] border border-[var(--border)] rounded-xl px-3 py-2">
            <p class="text-[10px] font-bold text-[var(--accent-text)] mb-0.5 flex items-center gap-1">
              <i data-lucide="corner-down-right" class="w-3 h-3"></i> NEXT STEP
              <span class="text-[var(--faint)] font-normal">· แก้ในส่วน「อัปเดตสถานะ」ด้านล่าง</span>
            </p>
            <p id="ld-next-plan" class="leading-snug whitespace-pre-wrap font-medium text-[var(--text-2)]">-</p>
            <p id="ld-next-date" class="text-[10px] text-[var(--muted)] mt-0.5"></p>
          </div>
        </div>
        <form id="lead-case-form" class="text-xs hidden">
          <input type="hidden" id="ld-case-code" name="lead_code" value="">
          <div class="rounded-2xl border border-[rgba(226,232,0,0.35)] bg-[rgba(226,232,0,0.06)] p-3 space-y-2 mb-3">
            <label for="lead-magic-paste" class="text-[10px] font-bold flex items-center gap-1.5 text-[var(--text-2)]">
              <i data-lucide="sparkles" class="w-3.5 h-3.5 text-[var(--accent-text)]" aria-hidden="true"></i>
              วางข้อความดิบให้ AI กรอกออโต้
            </label>
            <textarea id="lead-magic-paste" class="edit-inp w-full min-h-[72px]" rows="3"
                      placeholder="วางแชทลูกค้า / โน้ตดิบ…"></textarea>
            <button type="button" id="lead-magic-fill"
                    class="w-full py-2 rounded-xl bg-[#141414] text-[#E2E800] text-[10px] font-bold active:scale-[0.98] transition">
              ⚡ AI กรอกเคส (เฉพาะช่องว่าง)
            </button>
            <p id="lead-magic-status" class="text-[10px] text-[var(--faint)] leading-snug" role="status"></p>
          </div>
          <div class="ld-case-grid ld-case-grid--wide gap-3">
            <div class="ld-case-field ld-case-field--potential ld-case-span-3 bg-[var(--surface)] border border-[var(--border)] rounded-xl px-3 py-2">
              <label for="ld-case-potential" class="text-[10px] font-bold text-[var(--accent-text)] mb-1 flex items-center gap-1">
                <i data-lucide="gauge" class="w-3 h-3"></i> POTENTIAL
              </label>
              <select id="ld-case-potential" name="potential" class="edit-inp w-full">
                <option value="">— ยังไม่ระบุ —</option>
                <?php foreach (['A', 'B', 'C'] as $pg):
                    $pm = lead_potential_meta($pg);
                    if (!$pm) continue;
                ?>
                <option value="<?php echo $pg; ?>"><?php echo htmlspecialchars($pg . ' — ' . $pm['desc'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
              </select>
              <p id="ld-case-potential-hint" class="mt-1.5 text-[10px] text-[var(--muted)] leading-snug"></p>
            </div>
            <div class="ld-case-field ld-case-span-3 bg-[var(--surface)] border border-[var(--border)] rounded-xl px-3 py-2">
              <p class="text-[10px] font-bold text-[var(--accent-text)] mb-0.5 flex items-center gap-1">
                <i data-lucide="tag" class="w-3 h-3"></i> สำหรับ Lead สถานะอื่นๆ
              </p>
              <p class="text-[10px] text-[var(--faint)] mb-2 leading-snug">เคสที่เซลล์ไม่ได้ focus หลัก — เลือกได้ 1 อย่าง · แสดงป้ายหน้าชื่อ</p>
              <div id="ld-aux-tag-picker" class="ld-aux-tag-picker" role="radiogroup" aria-label="สำหรับ Lead สถานะอื่นๆ">
                <label class="ld-aux-tag-opt">
                  <input type="radio" name="lead_aux_tag" value="" checked>
                  <span class="ld-aux-tag-opt__inner"><i data-lucide="minus" class="w-3.5 h-3.5" aria-hidden="true"></i>ไม่ระบุ</span>
                </label>
                <?php foreach (lead_aux_tags() as $auxKey):
                    $auxMeta = lead_aux_tag_meta($auxKey);
                    if (!$auxMeta) continue;
                ?>
                <label class="ld-aux-tag-opt">
                  <input type="radio" name="lead_aux_tag" value="<?php echo htmlspecialchars($auxKey, ENT_QUOTES, 'UTF-8'); ?>">
                  <span class="ld-aux-tag-opt__inner"><i data-lucide="<?php echo htmlspecialchars($auxMeta['icon'], ENT_QUOTES, 'UTF-8'); ?>" class="w-3.5 h-3.5" aria-hidden="true"></i><?php echo htmlspecialchars($auxMeta['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                </label>
                <?php endforeach; ?>
              </div>
              <div id="ld-agent-fields" class="hidden mt-3 pt-3 border-t border-[var(--border)] space-y-2">
                <p class="text-[10px] font-bold text-[var(--muted)] flex items-center gap-1">
                  <i data-lucide="user-plus" class="w-3 h-3"></i> ลงทะเบียนลูกค้าเอเจนต์
                </p>
                <p class="text-[10px] text-[var(--faint)] leading-snug">ยังไม่ทราบชื่อหรือเบอร์ ใส่ <b class="text-[var(--muted)]">-</b> ได้</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                  <div>
                    <label for="ld-agent-client-name" class="text-[10px] font-bold text-[var(--faint)] mb-1 block">ชื่อลูกค้า</label>
                    <input type="text" id="ld-agent-client-name" name="agent_client_name" class="edit-inp w-full" placeholder="ชื่อลูกค้าจริง หรือ -" autocomplete="off">
                  </div>
                  <div>
                    <label for="ld-agent-phone-last4" class="text-[10px] font-bold text-[var(--faint)] mb-1 block">เบอร์ 4 ตัวท้าย</label>
                    <input type="text" id="ld-agent-phone-last4" name="agent_client_phone_last4" class="edit-inp w-full" inputmode="text" maxlength="4" placeholder="เช่น 9394 หรือ -" autocomplete="off">
                  </div>
                </div>
              </div>
            </div>
            <div class="ld-case-field ld-case-span-3 bg-[var(--surface)] border border-[var(--border)] rounded-xl px-3 py-2">
              <p class="text-[10px] font-bold text-[var(--accent-text)] mb-0.5 flex items-center gap-1">
                <i data-lucide="building-2" class="w-3 h-3"></i> ทรัพย์ &amp; การเสนอ
              </p>
              <p class="text-[10px] text-[var(--faint)] mb-2 leading-snug">ผูกรหัสทรัพย์กับ Owner ในระบบ · บันทึกว่าเสนอที่อื่นไปกี่หลังและรายการอะไรบ้าง</p>
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                <div>
                  <label for="ld-case-owner-code" class="text-[10px] font-bold text-[var(--faint)] mb-1 block">รหัสทรัพย์ที่สนใจ (Listing Code)</label>
                  <input type="text" id="ld-case-owner-code" name="owner_code" class="edit-inp w-full font-mono" list="ld-owner-code-options" placeholder="เช่น TAN123" autocomplete="off">
                </div>
                <div>
                  <label for="ld-case-units-sent" class="text-[10px] font-bold text-[var(--faint)] mb-1 block">เสนอไปกี่หลังแล้ว</label>
                  <input type="number" id="ld-case-units-sent" name="units_sent" class="edit-inp w-full" min="0" step="1" inputmode="numeric" placeholder="เช่น 3">
                </div>
              </div>
              <div class="mt-2">
                <label for="ld-case-offered-listings" class="text-[10px] font-bold text-[var(--faint)] mb-1 block">เสนอที่ไหนบ้าง (โครงการ / รหัส)</label>
                <textarea id="ld-case-offered-listings" name="offered_listings" class="edit-inp w-full" rows="3" placeholder="เช่น&#10;The City วัชรพล · TAN045&#10;Life ลาดพร้าว · TAN112"></textarea>
              </div>
            </div>
            <datalist id="ld-owner-code-options"></datalist>
            <div class="ld-case-field bg-[var(--surface)] border border-[var(--border)] rounded-xl px-3 py-2">
              <label for="ld-case-background" class="text-[10px] font-bold text-[var(--accent-text)] mb-1 block">BACKGROUND</label>
              <textarea id="ld-case-background" name="background" class="edit-inp w-full" rows="3" placeholder="พื้นหลังลูกค้า / บริบท"></textarea>
            </div>
            <div class="ld-case-field bg-[var(--surface)] border border-[var(--border)] rounded-xl px-3 py-2">
              <label for="ld-case-pain" class="text-[10px] font-bold text-[var(--accent-text)] mb-1 block">PAIN POINT</label>
              <textarea id="ld-case-pain" name="pain_point" class="edit-inp w-full" rows="3" placeholder="ปัญหา / ความกังวล"></textarea>
            </div>
            <div class="ld-case-field bg-[var(--surface)] border border-[var(--border)] rounded-xl px-3 py-2">
              <label for="ld-case-requirement" class="text-[10px] font-bold text-[var(--accent-text)] mb-1 block">REQUIREMENT</label>
              <textarea id="ld-case-requirement" name="requirement" class="edit-inp w-full" rows="3" placeholder="ความต้องการทรัพย์"></textarea>
            </div>
            <div class="ld-case-field bg-[var(--surface)] border border-[var(--border)] rounded-xl px-3 py-2">
              <label for="ld-case-financial" class="text-[10px] font-bold text-[var(--accent-text)] mb-1 block">FINANCIAL</label>
              <textarea id="ld-case-financial" name="financials" class="edit-inp w-full" rows="3" placeholder="สถานะการเงิน / กู้"></textarea>
            </div>
            <div class="ld-case-field bg-[var(--surface)] border border-[var(--border)] rounded-xl px-3 py-2">
              <label for="ld-case-timeline" class="text-[10px] font-bold text-[var(--accent-text)] mb-1 block">TIMELINE</label>
              <textarea id="ld-case-timeline" name="timeline" class="edit-inp w-full" rows="2" placeholder="กำหนดเวลา / เป้าหมายย้ายเข้า"></textarea>
            </div>
            <div class="ld-case-field bg-[var(--surface)] border border-[var(--border)] rounded-xl px-3 py-2">
              <label for="ld-case-budget" class="text-[10px] font-bold text-[var(--accent-text)] mb-1 block">BUDGET</label>
              <input type="text" id="ld-case-budget" name="budget" class="edit-inp w-full fmt-num" inputmode="numeric" autocomplete="off" placeholder="เช่น 6,500,000">
            </div>
          </div>
          <div class="ld-case-edit-actions">
            <button type="button" id="ld-case-cancel-btn">ยกเลิก</button>
            <button type="submit" id="ld-case-save-btn" class="ld-section-save-btn ld-section-save-btn--case">
              <i data-lucide="clipboard-list" class="w-4 h-4"></i> บันทึกข้อมูลเคส
            </button>
          </div>
        </form>
      </section>

      <section id="ld-overview-section" class="infowin-span-2 bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4 space-y-3">
        <h3 class="text-[11px] font-bold text-[var(--muted)] flex items-center gap-1.5">
          <i data-lucide="activity" class="w-3.5 h-3.5"></i> ภาพรวมการอัปเดตเคส
        </h3>
        <div class="bg-[var(--surface)] border border-[var(--border)] rounded-xl px-3 py-2.5">
          <p class="text-[10px] font-bold text-[var(--faint)] mb-1 flex items-center gap-1">
            <i data-lucide="message-square" class="w-3 h-3"></i> อัปเดตล่าสุด
          </p>
          <p id="ld-overview-current" class="text-xs leading-snug whitespace-pre-wrap text-[var(--text-2)]">ยังไม่มีข้อมูลอัปเดต</p>
        </div>
        <div id="ld-overview-next-wrap" class="hidden bg-[var(--surface)] border border-[var(--border)] rounded-xl px-3 py-2.5">
          <p class="text-[10px] font-bold text-[var(--faint)] mb-1 flex items-center gap-1">
            <i data-lucide="corner-down-right" class="w-3 h-3"></i> แผนถัดไป
          </p>
          <p id="ld-overview-next" class="text-xs leading-snug whitespace-pre-wrap font-medium text-[var(--text-2)]"></p>
          <p id="ld-overview-next-date" class="text-[10px] text-[var(--muted)] mt-0.5"></p>
        </div>
        <div id="ld-history-block">
          <p class="text-[10px] font-bold text-[var(--faint)] mb-2 flex items-center gap-1">
            <i data-lucide="history" class="w-3 h-3"></i> ประวัติการอัปเดต
          </p>
          <ul id="ld-status-history" class="space-y-2"></ul>
          <p id="ld-status-empty" class="text-xs text-[var(--faint)] text-center py-3">ยังไม่มีประวัติสถานะ</p>
          <button type="button" id="ld-history-more" class="ld-history-more-btn hidden">
            <i data-lucide="chevrons-down" class="w-3.5 h-3.5"></i>
            <span id="ld-history-more-text">ดูเพิ่มเติม</span>
          </button>
          <div id="ld-history-pager" class="ld-history-pager hidden">
            <button type="button" id="ld-history-prev" class="ld-history-pager-btn" aria-label="หน้าก่อน">
              <i data-lucide="chevron-left" class="w-3.5 h-3.5"></i>
              <span>ก่อน</span>
            </button>
            <span id="ld-history-page-info" class="text-[10px] font-bold text-[var(--muted)] text-center flex-1"></span>
            <button type="button" id="ld-history-next" class="ld-history-pager-btn" aria-label="หน้าถัดไป">
              <span>ถัดไป</span>
              <i data-lucide="chevron-right" class="w-3.5 h-3.5"></i>
            </button>
          </div>
        </div>
      </section>

      <section class="infowin-span-2 bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4">
        <h3 class="text-[11px] font-bold text-[var(--muted)] mb-3 flex items-center gap-1.5">
          <i data-lucide="git-branch" class="w-3.5 h-3.5"></i> สถานะ Pipeline
        </h3>
        <div id="ld-pipeline" class="flex gap-1 overflow-x-auto no-scrollbar pb-1"></div>
        <p id="ld-status-now" class="mt-2 text-xs font-bold flex items-center gap-1.5"></p>
        <div id="ld-mini-matrix-wrap" class="hidden mt-3">
          <p class="text-[10px] font-bold text-[var(--faint)] mb-1.5 flex items-center gap-1">
            <i data-lucide="grid-3x3" class="w-3 h-3"></i> ผลลัพธ์รายขั้น (ล่าสุด)
          </p>
          <div id="ld-mini-matrix" class="grid grid-cols-3 gap-1.5"></div>
        </div>
      </section>

      <section class="infowin-span-2 bg-[var(--card)] border border-[var(--border)] rounded-2xl p-4" id="ld-update-section">
        <h3 class="text-[11px] font-bold text-[var(--muted)] mb-3 flex items-center gap-1.5">
          <i data-lucide="pen-line" class="w-3.5 h-3.5"></i> อัปเดตสถานะ &amp; ติดตาม
          <span class="text-[10px] font-normal text-[var(--faint)]">· บันทึกลงประวัติ + Pipeline</span>
        </h3>
        <form id="lead-update-form" class="space-y-3 text-xs">
          <input type="hidden" id="ld-edit-code" name="lead_code" value="">
          <div class="ld-form-matrix-row">
          <div>
            <label for="ld-edit-stage" class="block text-[10px] font-bold text-[var(--faint)] mb-1">ขั้นตอน (Stage)</label>
            <select id="ld-edit-stage" name="stage" class="edit-inp w-full"></select>
          </div>
          <div>
            <label for="ld-edit-outcome" class="block text-[10px] font-bold text-[var(--faint)] mb-1">ผลลัพธ์ (Outcome)</label>
            <select id="ld-edit-outcome" name="outcome" class="edit-inp w-full"></select>
          </div>
          <div>
            <label for="ld-edit-event-date" class="block text-[10px] font-bold text-[var(--faint)] mb-1">วันที่บันทึกผล</label>
            <input type="date" id="ld-edit-event-date" name="event_date" class="edit-inp w-full">
          </div>
          </div>
          <div>
            <label for="ld-edit-update" class="block text-[10px] font-bold text-[var(--faint)] mb-1">หมายเหตุ / สรุปวันนี้</label>
            <textarea id="ld-edit-update" name="current_update" class="edit-inp w-full min-h-[88px]" placeholder="สรุปที่คุย / ดูบ้าน / ติดตาม — ขึ้นในประวัติและอัปเดตล่าสุด"></textarea>
          </div>
          <div id="ld-next-plan-wrap" class="space-y-3">
            <div>
              <label for="ld-edit-next-plan" class="block text-[10px] font-bold text-[var(--faint)] mb-1">แผนถัดไป (Next Step)</label>
              <textarea id="ld-edit-next-plan" name="next_plan_action" class="edit-inp w-full min-h-[56px]" placeholder="จะทำอะไรต่อ"></textarea>
            </div>
            <div>
              <label for="ld-edit-next-date" class="block text-[10px] font-bold text-[var(--faint)] mb-1">กำหนดทำ</label>
              <input type="date" id="ld-edit-next-date" name="next_plan_date" class="edit-inp w-full">
              <p id="ld-next-plan-hint" class="text-[10px] text-[var(--faint)] mt-1 flex items-start gap-1 leading-snug">
                <i data-lucide="corner-down-right" class="w-3 h-3 shrink-0 mt-0.5"></i>
                <span>บันทึกแล้วไป「แผนถัดไป」ด้านบน + งานในแท็บ Tasks · วันที่บันทึกผลจะใช้วันนี้เมื่อกลับมาอัปเดต</span>
              </p>
            </div>
          </div>
          <p id="ld-next-plan-terminal-hint" class="hidden text-[10px] text-[var(--muted)] leading-relaxed flex items-start gap-1.5 bg-[var(--surface)] border border-[var(--border)] rounded-xl px-3 py-2">
            <i data-lucide="circle-off" class="w-3.5 h-3.5 shrink-0 mt-0.5"></i>
            <span>เคสจบที่ขั้นนี้ (Lose / Reject) — ไม่ต้องตั้งแผนถัดไป · งาน Follow Lead ปิดอัตโนมัติ</span>
          </p>
          <p id="ld-auto-task-hint" class="hidden text-[10px] text-[var(--muted)] leading-relaxed flex items-start gap-1.5">
            <i data-lucide="bell" class="w-3.5 h-3.5 shrink-0 mt-0.5 text-[var(--accent-text)]"></i>
            <span id="ld-auto-task-hint-text"></span>
          </p>
          <div class="ld-section-save">
            <button type="submit" id="ld-save-btn" class="ld-section-save-btn ld-section-save-btn--update">บันทึกการอัปเดต</button>
          </div>
        </form>
      </section>

      <section id="ld-win-section" class="hidden infowin-span-2 bg-[var(--card)] border border-status-yes rounded-2xl p-4 space-y-2">
        <h3 class="text-[11px] font-bold text-status-yes flex items-center gap-1.5">
          <i data-lucide="trophy" class="w-3.5 h-3.5"></i> ปิดดีลแล้ว (Win)
        </h3>
        <div class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-xs">
          <span class="text-[var(--faint)]">ปิดที่</span>
          <span id="ld-win-scope" class="font-bold inline-flex items-center gap-1">-</span>
          <span id="ld-win-other-project-label" class="text-[var(--faint)] hidden">โครงการที่ปิด</span>
          <span id="ld-win-other-project" class="font-medium hidden">-</span>
          <span id="ld-win-other-code-label" class="text-[var(--faint)] hidden">รหัสทรัพย์ที่ปิด</span>
          <span id="ld-win-other-code" class="font-mono font-bold hidden">-</span>
          <span id="ld-win-open-price-label" class="text-[var(--faint)] hidden">ราคาเปิด</span>
          <span id="ld-win-open-price" class="font-medium hidden">-</span>
          <span class="text-[var(--faint)]">วันที่โอน</span><span id="ld-win-date" class="font-medium">-</span>
          <span class="text-[var(--faint)]">ราคาปิด</span><span id="ld-win-price" class="font-bold">-</span>
          <span id="ld-win-payment-label" class="text-[var(--faint)] hidden">ชำระแบบ</span><span id="ld-win-payment" class="font-medium hidden">-</span>
          <span id="ld-win-revenue-label" class="text-[var(--faint)] hidden">ค่าคอมมิชชั่น</span><span id="ld-win-revenue" class="font-bold hidden">-</span>
        </div>
      </section>

      </div>
      </div>
    </div>
    <div id="ld-action-bar" class="hidden shrink-0 border-t border-[var(--border)] px-5 py-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))] ld-action-bar--revive-only">
      <button type="button" id="ld-revive-btn" class="hidden w-full py-2.5 rounded-xl border border-[#E2E800] bg-[#E2E800]/10 text-[var(--accent-text)] text-sm font-bold flex items-center justify-center gap-2 active:scale-[0.98] transition">
        <i data-lucide="refresh-cw" class="w-4 h-4"></i> ดึงกลับมาติดตาม (Follow)
      </button>
    </div>
    <div id="ld-saving-overlay" class="hidden absolute inset-0 z-30 bg-[var(--bg)]/90 backdrop-blur-[2px] flex flex-col items-center justify-center gap-3 rounded-t-3xl" aria-live="polite" aria-busy="true">
      <i data-lucide="loader-circle" class="w-9 h-9 animate-spin text-[var(--accent-text)]"></i>
      <p id="ld-saving-msg" class="text-sm font-bold">กำลังบันทึก…</p>
    </div>
  </div>
</div>

<!-- Bottom sheet เลือกผล Pipeline ต่อเซลล์ (ตารางชีท) -->
<div id="matrix-cell-picker" class="fixed inset-0 z-[85] hidden">
  <div id="matrix-picker-backdrop" class="absolute inset-0 bg-black/60"></div>
  <div class="app-sheet app-sheet--modal absolute inset-x-0 bottom-0 left-1/2 -translate-x-1/2 w-full max-w-md bg-[var(--bg)] border-t border-[var(--border)] rounded-t-3xl px-5 pt-4 pb-6 max-h-[90vh] overflow-y-auto overflow-x-visible">
    <div class="flex items-center justify-between mb-3">
      <h3 id="matrix-picker-title" class="text-sm font-bold truncate pr-2">เลือกผล</h3>
      <button type="button" id="matrix-picker-close" class="w-8 h-8 rounded-full bg-[var(--card)] border border-[var(--border)] flex items-center justify-center shrink-0">
        <i data-lucide="x" class="w-4 h-4"></i>
      </button>
    </div>
    <div id="matrix-picker-history-wrap" class="hidden mb-3 rounded-xl border border-[var(--border)] bg-[var(--surface)] px-3 py-2.5">
      <p class="text-[10px] font-bold text-[var(--faint)] mb-2 flex items-center gap-1">
        <i data-lucide="history" class="w-3 h-3"></i> ประวัติขั้นนี้
      </p>
      <ul id="matrix-picker-history" class="space-y-2 max-h-36 overflow-y-auto text-xs"></ul>
    </div>
    <p class="text-[10px] font-bold text-[var(--muted)] mb-2 flex items-center gap-1">
      <i data-lucide="plus-circle" class="w-3 h-3"></i> บันทึกครั้งใหม่
    </p>
    <p class="text-[10px] text-[var(--faint)] mb-3 flex items-center gap-1">
      <i data-lucide="info" class="w-3 h-3 shrink-0"></i>
      เลือกผลด้านล่าง + หมายเหตุครั้งนี้ · แสดงค่าล่าสุดในตาราง
    </p>
    <div id="matrix-picker-options" class="grid grid-cols-2 gap-2 mb-3"></div>
    <div class="space-y-2">
      <div>
        <label for="matrix-picker-date" id="matrix-picker-date-label" class="block text-[10px] font-bold text-[var(--faint)] mb-1">วันที่</label>
        <input type="date" id="matrix-picker-date" class="edit-inp w-full">
      </div>
      <div id="matrix-picker-win-fields" class="hidden space-y-2 pt-2 border-t border-[var(--border)]">
        <div id="matrix-picker-win-scope-wrap">
          <p class="text-[10px] font-bold text-[var(--faint)] mb-1.5 flex items-center gap-1">
            <i data-lucide="home" class="w-3 h-3"></i> ปิดที่หลังไหน
          </p>
          <div id="matrix-picker-win-scope" class="ld-aux-tag-picker" role="radiogroup" aria-label="ปิดที่หลังไหน">
            <label class="ld-aux-tag-opt">
              <input type="radio" name="win_close_scope" value="this" checked>
              <span class="ld-aux-tag-opt__inner"><i data-lucide="home" class="w-3.5 h-3.5" aria-hidden="true"></i>จบหลังนี้</span>
            </label>
            <label class="ld-aux-tag-opt">
              <input type="radio" name="win_close_scope" value="other">
              <span class="ld-aux-tag-opt__inner"><i data-lucide="map-pin" class="w-3.5 h-3.5" aria-hidden="true"></i>หลังอื่น</span>
            </label>
          </div>
          <p id="matrix-picker-win-this-hint" class="mt-2 text-[10px] text-[var(--muted)] leading-snug flex items-start gap-1.5 rounded-lg border border-[var(--border)] bg-[var(--surface)] px-2.5 py-1.5">
            <i data-lucide="link-2" class="w-3.5 h-3.5 shrink-0 mt-px" aria-hidden="true"></i>
            <span id="matrix-picker-win-this-hint-text">ทรัพย์ที่ผูกในเคส</span>
          </p>
        </div>
        <div id="matrix-picker-win-other-fields" class="hidden space-y-2 rounded-xl border border-[var(--border)] bg-[var(--surface)] px-3 py-2">
          <p class="text-[10px] font-bold text-[var(--accent-text)] flex items-center gap-1">
            <i data-lucide="map-pin" class="w-3 h-3"></i> ทรัพย์ที่ปิดจริง
          </p>
          <div>
            <label for="matrix-picker-close-project" class="block text-[10px] font-bold text-[var(--faint)] mb-1">โครงการ</label>
            <input type="text" id="matrix-picker-close-project" class="edit-inp w-full" placeholder="ชื่อโครงการที่ปิด" autocomplete="off">
          </div>
          <div>
            <label for="matrix-picker-close-owner-code" class="block text-[10px] font-bold text-[var(--faint)] mb-1">รหัสทรัพย์</label>
            <input type="text" id="matrix-picker-close-owner-code" class="edit-inp w-full font-mono" list="ld-owner-code-options" placeholder="เช่น TAN123" autocomplete="off">
          </div>
          <div>
            <label for="matrix-picker-close-open-price" class="block text-[10px] font-bold text-[var(--faint)] mb-1">ราคาเปิด (บาท)</label>
            <input type="text" id="matrix-picker-close-open-price" class="edit-inp w-full fmt-num" inputmode="numeric" placeholder="เช่น 10,500,000" autocomplete="off">
          </div>
        </div>
        <div>
          <label for="matrix-picker-transfer-date" class="block text-[10px] font-bold text-[var(--faint)] mb-1">วันที่โอน</label>
          <input type="date" id="matrix-picker-transfer-date" class="edit-inp w-full">
        </div>
        <div>
          <label for="matrix-picker-win-price" class="block text-[10px] font-bold text-[var(--faint)] mb-1">ราคาปิด (บาท)</label>
          <input type="text" id="matrix-picker-win-price" class="edit-inp w-full fmt-num" inputmode="numeric" placeholder="เช่น 9,990,000" autocomplete="off">
        </div>
        <div>
          <label for="matrix-picker-payment" class="block text-[10px] font-bold text-[var(--faint)] mb-1">ชำระแบบ</label>
          <select id="matrix-picker-payment" class="edit-inp w-full app-select--form">
            <option value="">— เลือก —</option>
            <option value="cash">เงินสด</option>
            <option value="loan">กู้ธนาคาร</option>
          </select>
        </div>
        <div>
          <label for="matrix-picker-revenue" class="block text-[10px] font-bold text-[var(--faint)] mb-1">ค่าคอมมิชชั่น (บาท)</label>
          <input type="text" id="matrix-picker-revenue" class="edit-inp w-full fmt-num" inputmode="numeric" placeholder="เช่น 299,700" autocomplete="off">
        </div>
      </div>
      <div>
        <label for="matrix-picker-note" class="block text-[10px] font-bold text-[var(--faint)] mb-1">หมายเหตุครั้งนี้ (ไม่บังคับ)</label>
        <input type="text" id="matrix-picker-note" class="edit-inp w-full" placeholder="เช่น ลูกค้าไม่รับสาย · นัดดูบ้านอีกรอบ">
      </div>
    </div>
    <p id="matrix-picker-selected" class="hidden mt-3 text-[11px] font-bold text-[var(--muted)] flex items-center gap-1.5">
      <i data-lucide="mouse-pointer-click" class="w-3.5 h-3.5 shrink-0"></i>
      <span id="matrix-picker-selected-text">เลือกผลลัพธ์ด้านบน</span>
    </p>
    <div class="grid grid-cols-2 gap-2 mt-4 pt-3 border-t border-[var(--border)]">
      <button type="button" id="matrix-picker-cancel" class="py-3 rounded-xl border border-[var(--border)] bg-[var(--card)] text-sm font-bold active:scale-[0.98] transition">ยกเลิก</button>
      <button type="button" id="matrix-picker-save" class="py-3 rounded-xl bg-[#E2E800] text-[#141414] text-sm font-bold active:scale-[0.98] transition disabled:opacity-40 disabled:pointer-events-none" disabled>บันทึก</button>
    </div>
  </div>
</div>

<!-- ===== หน้าเกร็ดความรู้ (Tips) — เปิดจากไอคอน ? ตามจุดต่างๆ รองรับเพิ่มบทความใหม่ได้ ===== -->
<div id="tips-overlay" class="fixed inset-0 z-[80] hidden">
  <div id="tips-backdrop" class="absolute inset-0 bg-black/60"></div>
  <div class="app-sheet app-sheet--modal absolute inset-x-0 bottom-0 top-10 left-1/2 -translate-x-1/2 w-full max-w-md bg-[var(--bg)] border-t border-[var(--border)] rounded-t-3xl flex flex-col">
    <!-- หัวหน้าเกร็ด -->
    <div class="flex items-center gap-3 px-5 py-4 border-b border-[var(--border)] shrink-0">
      <button id="tips-close" class="w-9 h-9 rounded-full bg-[var(--card)] border border-[var(--border)] flex items-center justify-center text-[var(--text-2)] active:scale-95 transition">
        <i data-lucide="arrow-left" class="w-4 h-4"></i>
      </button>
      <div>
        <h2 class="text-sm font-bold">เกร็ดความรู้</h2>
        <p class="text-[11px] text-[var(--faint)]">Tips &amp; How-to</p>
      </div>
    </div>

    <!-- เนื้อหาบทความ (เลื่อนได้) -->
    <div class="overflow-y-auto px-5 py-5 space-y-5">

      <article data-tip-id="eisenhower" class="space-y-5">
        <header>
          <p class="text-[11px] font-bold text-[var(--accent-text)] mb-1">จัดลำดับความสำคัญ</p>
          <h3 class="text-lg font-bold leading-snug">Eisenhower Matrix คืออะไร?</h3>
        </header>

        <p class="text-sm text-[var(--text-2)] leading-relaxed">
          เครื่องมือจัดลำดับงานที่คิดโดย <b>ดไวต์ ดี. ไอเซนฮาวร์</b> ประธานาธิบดีสหรัฐฯ
          ผู้ต้องตัดสินใจเรื่องสำคัญวันละหลายสิบเรื่อง หลักการของเขาเรียบง่ายมาก:
        </p>
        <blockquote class="border-l-2 border-[#E2E800] pl-3 text-sm italic text-[var(--muted)]">
          "สิ่งที่สำคัญมักไม่เร่งด่วน และสิ่งที่เร่งด่วนมักไม่สำคัญ"
        </blockquote>
        <p class="text-sm text-[var(--text-2)] leading-relaxed">
          วิธีใช้คือถามตัวเอง 2 คำถามกับทุกงาน — <b>"ด่วนไหม?"</b> และ <b>"สำคัญไหม?"</b>
          คำตอบจะแบ่งงานออกเป็น 4 ช่อง:
        </p>

        <!-- ตาราง 2x2 แบบ Eisenhower จริงๆ: แกนบน = ด่วน/ไม่ด่วน, แกนข้าง = สำคัญ/ไม่สำคัญ -->
        <div class="grid gap-1.5" style="grid-template-columns: 1.25rem 1fr 1fr;">
          <div></div>
          <p class="text-center text-xs font-bold text-[var(--text-2)] pb-0.5">ด่วน</p>
          <p class="text-center text-xs font-bold text-[var(--text-2)] pb-0.5">ไม่ด่วน</p>

          <div class="flex items-center justify-center">
            <span class="-rotate-90 whitespace-nowrap text-xs font-bold text-[var(--text-2)]">สำคัญ</span>
          </div>
          <div class="rounded-xl p-3 bg-red-500/15 border border-red-500/30">
            <p class="flex items-center gap-1 text-[13px] font-bold text-red-400"><i data-lucide="flame" class="w-3.5 h-3.5 shrink-0"></i>ทำทันที</p>
            <p class="text-[11px] text-[var(--muted)] mt-1 leading-relaxed">งานปล่อยไว้แล้วเสียหาย — ลูกค้านัดดูห้องวันนี้, เอกสารกู้ที่ธนาคารรอ</p>
          </div>
          <div class="rounded-xl p-3 bg-[#E2E800]/15 border border-[#E2E800]/40">
            <p class="flex items-center gap-1 text-[13px] font-bold text-[var(--accent-text)]"><i data-lucide="calendar-check" class="w-3.5 h-3.5 shrink-0"></i>วางแผนทำ</p>
            <p class="text-[11px] text-[var(--muted)] mt-1 leading-relaxed">งานสร้างอนาคต — หา listing ใหม่, ตาม lead เก่า, ทำคอนเทนต์</p>
          </div>

          <div class="flex items-center justify-center">
            <span class="-rotate-90 whitespace-nowrap text-xs font-bold text-[var(--text-2)]">ไม่สำคัญ</span>
          </div>
          <div class="rounded-xl p-3 bg-amber-500/15 border border-amber-500/30">
            <p class="flex items-center gap-1 text-[13px] font-bold text-amber-500"><i data-lucide="send" class="w-3.5 h-3.5 shrink-0"></i>มอบหมาย</p>
            <p class="text-[11px] text-[var(--muted)] mt-1 leading-relaxed">งานจุกจิกใครทำแทนได้ — ส่งเอกสารซ้ำๆ, ตอบคำถามทั่วไป</p>
          </div>
          <div class="rounded-xl p-3 bg-[var(--chip)] border border-[var(--border-2)]">
            <p class="flex items-center gap-1 text-[13px] font-bold text-[var(--muted)]"><i data-lucide="coffee" class="w-3.5 h-3.5 shrink-0"></i>ทำทีหลัง</p>
            <p class="text-[11px] text-[var(--muted)] mt-1 leading-relaxed">ตัดทิ้งได้ไม่เสียหาย — จัดรูปเก่า, แต่งโปรไฟล์รอบที่สิบ</p>
          </div>
        </div>

        <div>
          <h4 class="text-sm font-bold mb-2">ช่วยแก้ปัญหาอะไรในการทำงาน?</h4>
          <ul class="space-y-2 text-sm text-[var(--text-2)]">
            <li class="flex gap-2"><i data-lucide="check" class="w-4 h-4 shrink-0 text-emerald-500 mt-0.5"></i><span><b>หายจมกองงาน</b> — เลิกไล่ทำงานตามลำดับที่นึกออก แล้วทำตามลำดับที่ควรทำจริง</span></li>
            <li class="flex gap-2"><i data-lucide="check" class="w-4 h-4 shrink-0 text-emerald-500 mt-0.5"></i><span><b>เลิกพลาดเรื่องใหญ่เพราะเรื่องจิ๊บจ๊อย</b> — งานด่วนปลอมๆ จะถูกแยกออกจากงานที่เงินอยู่ตรงนั้นจริง</span></li>
            <li class="flex gap-2"><i data-lucide="check" class="w-4 h-4 shrink-0 text-emerald-500 mt-0.5"></i><span><b>มีเวลาให้ช่อง 2 มากขึ้น</b> — คนส่วนใหญ่ใช้เวลาทั้งวันกับช่อง 1 และ 3 ทั้งที่ช่อง 2 คือตัวสร้างดีลใหม่</span></li>
            <li class="flex gap-2"><i data-lucide="check" class="w-4 h-4 shrink-0 text-emerald-500 mt-0.5"></i><span><b>ตัดสินใจไวขึ้น</b> — งานใหม่เข้ามา ถาม 2 คำถาม รู้ทันทีว่าต้องทำตอนนี้ นัดไว้ ส่งต่อ หรือทิ้ง</span></li>
          </ul>
        </div>

        <div class="bg-[#E2E800]/10 border border-[#E2E800]/30 rounded-xl p-3.5">
          <p class="text-xs text-[var(--text-2)] leading-relaxed">
            <b>วิธีใช้ในแอปนี้:</b> ตอนเพิ่มงาน เลือก 1 ใน 4 ระดับ ระบบจะติดป้ายสีพร้อมไอคอนให้
            และเรียงงานในแต่ละวันให้อัตโนมัติ — งานมีเวลานัดมาก่อน ตามด้วยระดับความสำคัญ
          </p>
        </div>
      </article>

      <article data-tip-id="pipeline-funnel" class="space-y-5 hidden">
        <header>
          <p class="text-[11px] font-bold text-[var(--accent-text)] mb-1">เป้า Pipeline</p>
          <h3 class="text-lg font-bold leading-snug">เป้าจำนวนแต่ละขั้นคิดยังไง?</h3>
        </header>

        <p class="text-sm text-[var(--text-2)] leading-relaxed">
          ส่วนนี้ช่วยตอบคำถามว่า <b>"ถ้าอยากได้เงินเดือนนี้เท่านี้ ต้องมีของในแต่ละขั้นกี่รายการ?"</b>
          ระบบเริ่มจากเป้าเงินด้านบน แล้วถอยหลังลงมาให้เอง
        </p>

        <div class="bg-[var(--card)] border border-[var(--border)] rounded-xl p-3.5 space-y-2">
          <p class="text-xs font-bold flex items-center gap-1.5"><i data-lucide="target" class="w-3.5 h-3.5 text-[var(--accent-text)]"></i>ขั้นที่ 1 — หา Win ก่อน</p>
          <p class="text-sm text-[var(--text-2)] leading-relaxed">
            <b>Win</b> = จำนวนดีลที่ต้องปิดให้ได้<br>
            คำนวณจาก <b>เป้ารายได้เดือน ÷ คอมเฉลี่ยต่อดีล</b> (ปัดขึ้น)<br>
            ตัวอย่าง: อยากได้ ฿500,000 คอมดีลละ ฿50,000 → ต้องปิด <b>10 Win</b>
          </p>
        </div>

        <div>
          <p class="text-xs font-bold mb-2 flex items-center gap-1.5"><i data-lucide="arrow-up" class="w-3.5 h-3.5 text-[var(--accent-text)]"></i>ขั้นที่ 2 — ถอยหลังจาก Win</p>
          <p class="text-sm text-[var(--text-2)] leading-relaxed mb-3">
            คนซื้อไม่ปิดทุกคนที่คุย — เลยต้องมีคนมากกว่า Win ในขั้นบนๆ
            ระบบใช้สัดส่วนตัวอย่างนี้ (ครั้งแรกยังไม่เคยบันทึกเอง):
          </p>
          <ul class="space-y-2 text-sm text-[var(--text-2)]">
            <li class="flex gap-2 rounded-lg bg-[var(--surface)] border border-[var(--border)] px-3 py-2">
              <i data-lucide="building-2" class="w-4 h-4 shrink-0 text-[var(--accent-text)] mt-0.5"></i>
              <span><b>Project</b> — ทรัพย์ที่ยังขายอยู่ ประมาณ 4 อัน → ได้ Lead 1</span>
            </li>
            <li class="flex gap-2 rounded-lg bg-[var(--surface)] border border-[var(--border)] px-3 py-2">
              <i data-lucide="users" class="w-4 h-4 shrink-0 text-[var(--accent-text)] mt-0.5"></i>
              <span><b>Lead</b> — ลีดที่บริษัทส่งมาแล้ว ประมาณ 2 อัน → ได้นัด 1</span>
            </li>
            <li class="flex gap-2 rounded-lg bg-[var(--surface)] border border-[var(--border)] px-3 py-2">
              <i data-lucide="calendar" class="w-4 h-4 shrink-0 text-[var(--accent-text)] mt-0.5"></i>
              <span><b>App.</b> — นัดดูห้อง ประมาณ 10 นัด → ไปดูจริง 6</span>
            </li>
            <li class="flex gap-2 rounded-lg bg-[var(--surface)] border border-[var(--border)] px-3 py-2">
              <i data-lucide="eye" class="w-4 h-4 shrink-0 text-[var(--accent-text)] mt-0.5"></i>
              <span><b>Showing</b> — ดูห้องแล้ว ประมาณ 2 ครั้ง → เจรจา 1</span>
            </li>
            <li class="flex gap-2 rounded-lg bg-[var(--surface)] border border-[var(--border)] px-3 py-2">
              <i data-lucide="file-signature" class="w-4 h-4 shrink-0 text-[var(--accent-text)] mt-0.5"></i>
              <span><b>Nego</b> — เจรจา ประมาณ 10 เคส → ปิด Win 4</span>
            </li>
          </ul>
        </div>

        <div>
          <p class="text-xs font-bold mb-2 flex items-center gap-1.5"><i data-lucide="bar-chart-2" class="w-3.5 h-3.5 text-[var(--accent-text)]"></i>มีจริง กับ ขาด คืออะไร?</p>
          <ul class="space-y-2 text-sm text-[var(--text-2)]">
            <li class="flex gap-2"><i data-lucide="check" class="w-4 h-4 shrink-0 text-emerald-500 mt-0.5"></i><span><b>เป้า</b> = ตัวเลขที่ควรมี (ในช่องขวา)</span></li>
            <li class="flex gap-2"><i data-lucide="check" class="w-4 h-4 shrink-0 text-emerald-500 mt-0.5"></i><span><b>มีจริง</b> = นับจากข้อมูลใน CRM ตอนนี้ (Win นับเฉพาะเดือนเป้าที่เลือก)</span></li>
            <li class="flex gap-2"><i data-lucide="check" class="w-4 h-4 shrink-0 text-emerald-500 mt-0.5"></i><span><b>ขาด</b> = เป้า − มีจริง ถ้าครบแล้วจะขึ้น "ครบแล้ว"</span></li>
          </ul>
        </div>

        <div class="bg-[#E2E800]/10 border border-[#E2E800]/30 rounded-xl p-3.5">
          <p class="text-xs text-[var(--text-2)] leading-relaxed">
            <b>วิธีใช้ในแอปนี้:</b> แก้ตัวเลขแต่ละขั้นได้ถ้าสัดส่วนของคุณไม่เหมือนค่าเริ่มต้น
            แล้วกด <b>บันทึก &amp; คำนวณใหม่</b> — ลูกศร ↑ แปลว่าต้องมีของบนให้พอ ถึงจะไหลลงมาปิด Win ได้
          </p>
        </div>
      </article>

    </div>
  </div>
</div>

<!-- ===== Bottom Navigation (ชิดขอบล่าง — กว้างเท่า content max-w-md) ===== -->
<nav id="bottom-nav" class="fixed bottom-0 left-0 right-0 z-50 flex justify-center pointer-events-none" aria-label="เมนูหลัก">
  <div class="bottom-nav-dock w-full max-w-md border-t border-[var(--border)] bg-[var(--nav-dock-bg)] pointer-events-auto" style="padding-bottom: env(safe-area-inset-bottom);">
    <div id="bottom-nav-scroll" class="no-scrollbar">
      <div id="bottom-nav-track">
      <?php
      $nav = $nav_items;
      foreach ($nav as $tab => $n): ?>
      <button type="button" onclick="switchTab('<?php echo $tab; ?>')" data-tab="<?php echo $tab; ?>"
              class="nav-btn" aria-label="<?php echo htmlspecialchars($n['label']); ?>">
        <i data-lucide="<?php echo $n['icon']; ?>" class="nav-icon" aria-hidden="true"></i>
        <span class="nav-label"><?php echo $n['label']; ?></span>
      </button>
      <?php endforeach; ?>
      </div>
    </div>
  </div>
</nav>

<script>
lucide.createIcons();
window.onbeforeunload = null;

// ===== ป้องกันออกหน้าขณะพิมพ์ค้าง (เฉพาะช่องข้อความที่ยังไม่บันทึก) =====
const unsavedGuard = {
  contexts: {},
  pendingLeave: null,

  _norm(v) { return String(v ?? '').trim(); },

  beginContext(ctxId, elements) {
    const fields = (elements || []).filter(Boolean).map(el => ({
      el,
      snap: this._norm(el.value),
      touched: false,
      _bound: false,
    }));
    this.contexts[ctxId] = { fields };
    fields.forEach(f => this._bindField(f));
  },

  endContext(ctxId) {
    delete this.contexts[ctxId];
  },

  clearAll() {
    this.contexts = {};
  },

  _markTouched(f) {
    if (!f.touched) {
      f.touched = true;
    }
  },

  _bindField(f) {
    if (!f.el || f._bound) return;
    f._bound = true;
    const mark = () => this._markTouched(f);
    f.el.addEventListener('keydown', (e) => {
      if (!e.isTrusted) return;
      if (e.ctrlKey || e.metaKey || e.altKey) return;
      if (['Tab', 'Escape', 'Enter', 'Shift', 'Control', 'Alt', 'Meta'].includes(e.key)) return;
      if (e.key.startsWith('Arrow') || e.key.startsWith('Page') || e.key === 'Home' || e.key === 'End') return;
      mark();
    });
    f.el.addEventListener('paste', (e) => { if (e.isTrusted) mark(); });
    f.el.addEventListener('cut', (e) => { if (e.isTrusted) mark(); });
  },

  isDirty() {
    for (const ctx of Object.values(this.contexts)) {
      for (const f of ctx.fields) {
        if (!f.el || !document.contains(f.el)) continue;
        if (f.touched && this._norm(f.el.value) !== f.snap) return true;
      }
    }
    return false;
  },

  _openSheet(opts) {
    const goBtn = document.getElementById('unsaved-leave-go');
    const title = document.getElementById('unsaved-leave-title');
    if (goBtn) goBtn.textContent = (opts && opts.goLabel) || 'ออกโดยไม่บันทึก';
    if (title) title.textContent = (opts && opts.title) || 'ยังพิมพ์ไม่เสร็จ';
    document.getElementById('unsaved-leave-sheet')?.classList.remove('hidden');
    if (window.lucide) lucide.createIcons();
  },

  _closeSheet() {
    document.getElementById('unsaved-leave-sheet')?.classList.add('hidden');
    this.pendingLeave = null;
  },

  confirmLeave(action, opts) {
    if (!this.isDirty()) {
      action();
      return;
    }
    this.pendingLeave = action;
    this._openSheet(opts);
  },

  discardAndLeave() {
    const action = this.pendingLeave;
    this.pendingLeave = null;
    this.clearAll();
    this._closeSheet();
    unloadReportFrame();
    if (action) action();
  },
};

document.getElementById('unsaved-leave-stay')?.addEventListener('click', () => unsavedGuard._closeSheet());
document.getElementById('unsaved-leave-backdrop')?.addEventListener('click', () => unsavedGuard._closeSheet());
document.getElementById('unsaved-leave-go')?.addEventListener('click', () => unsavedGuard.discardAndLeave());

function ensurePipelineUnsavedGuard() {
  const form = document.getElementById('pipeline-form');
  if (!form || form.dataset.guardReady === '1') return;
  form.dataset.guardReady = '1';
  form.addEventListener('focusin', (e) => {
    const el = e.target;
    if (!e.isTrusted || !el.matches('input[type="text"]:not([readonly]), textarea')) return;
    if (unsavedGuard.contexts.pipeline) return;
    const fields = [...form.querySelectorAll('input[type="text"]:not([readonly])')];
    if (fields.length) unsavedGuard.beginContext('pipeline', fields);
  });
}

function initProductEditUnsavedGuard() {
  const fields = [...document.querySelectorAll('#product-edit-form .edit-inp')];
  unsavedGuard.endContext('product-edit');
  if (fields.length) unsavedGuard.beginContext('product-edit', fields);
}

ensurePipelineUnsavedGuard();

function loadReportFrame() {
  const frame = document.getElementById('price-report-frame');
  if (!frame || frame.dataset.loaded === '1') return;
  frame.src = frame.dataset.src || 'price-report/index.html';
  frame.dataset.loaded = '1';
}

function unloadReportFrame() {
  const frame = document.getElementById('price-report-frame');
  if (!frame || frame.dataset.loaded !== '1') return;
  frame.src = 'about:blank';
  frame.dataset.loaded = '0';
}

function reloadDashboard() {
  unloadReportFrame();
  location.reload();
}

// ถอด iframe รายงานราคาก่อนรีเฟรช/ออกหน้า (กัน beforeunload จาก React app ใน iframe)
window.addEventListener('beforeunload', () => { unloadReportFrame(); });

// รีเฟรช (F5 / Ctrl+R) — ใช้ modal ของแอป ไม่ใช้ popup เบราว์เซอร์
window.addEventListener('keydown', (e) => {
  const isReloadKey = e.key === 'F5' || ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'r');
  if (!isReloadKey) return;
  if (!unsavedGuard.isDirty()) return;
  e.preventDefault();
  unsavedGuard.confirmLeave(() => reloadDashboard(), {
    title: 'รีเฟรชหน้านี้?',
    goLabel: 'รีเฟรชโดยไม่บันทึก',
  });
}, true);

// ===== สลับแท็บ =====
const VALID_TABS = <?php echo json_encode(array_keys($nav_items)); ?>;
const IS_METAL_SHEET = <?php echo $is_metal_sheet ? 'true' : 'false'; ?>;
function switchTab(tab, force) {
  const rawHash = (location.hash || '').slice(1);
  let docAnchor = '';
  if (tab === 'documents' && rawHash.startsWith('documents-')) {
    docAnchor = rawHash;
  }
  if (!VALID_TABS.includes(tab)) tab = 'home';
  const current = (location.hash || '#home').slice(1);
  if (!force && tab !== current && unsavedGuard.isDirty()) {
    unsavedGuard.confirmLeave(() => switchTab(tab, true));
    return;
  }
  document.querySelectorAll('.page').forEach(p => p.classList.add('hidden'));
  const page = document.getElementById('page-' + tab);
  if (page) page.classList.remove('hidden');
  document.querySelectorAll('.nav-btn').forEach(b => {
    const active = b.dataset.tab === tab;
    b.classList.toggle('is-active', active);
    b.setAttribute('aria-current', active ? 'page' : 'false');
  });
  const activeNav = document.querySelector('.nav-btn[data-tab="' + tab + '"]');
  const navScroll = document.getElementById('bottom-nav-scroll');
  if (activeNav && navScroll) {
    activeNav.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
  }
  history.replaceState(null, '', '#' + (docAnchor || tab));
  const shell = document.getElementById('app-shell');
  const fullBleed = tab === 'map' || tab === 'report';
  shell?.classList.toggle('shell-full-bleed', fullBleed);
  if (tab === 'report' && !IS_METAL_SHEET) loadReportFrame();
  else if (tab !== 'report') unloadReportFrame();
  if (tab === 'map' || tab === 'report') {
    document.documentElement.style.overflow = 'hidden';
    document.body.style.overflow = 'hidden';
    window.scrollTo({ top: 0 });
    if (tab === 'map') requestAnimationFrame(() => setTimeout(() => window.mapPageInit?.(), 120));
  } else {
    document.documentElement.style.overflow = '';
    document.body.style.overflow = '';
  }
  if (tab !== 'map' && tab !== 'report') window.scrollTo({ top: 0 });
  if (tab === 'pipeline' || tab === 'leads') {
    requestAnimationFrame(() => {
      if (typeof renderAllPipelineMatrices === 'function') renderAllPipelineMatrices();
    });
  }
  if (tab === 'documents' && docAnchor) {
    requestAnimationFrame(() => {
      const el = document.getElementById(docAnchor);
      if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  }
}
const rawInitialHash = location.hash.slice(1);
const initialTab = rawInitialHash.startsWith('documents')
  ? 'documents'
  : (VALID_TABS.includes(rawInitialHash) ? rawInitialHash : 'home');
switchTab(initialTab);

// ===== รายงานเมทัลชีท =====
if (IS_METAL_SHEET) {
  let msEntryType = 'deposit';

  async function msPost(action, data) {
    const fd = new FormData();
    fd.append('ajax', action);
    Object.entries(data || {}).forEach(([k, v]) => fd.append(k, v ?? ''));
    const res = await fetch('dashboard.php', { method: 'POST', body: fd });
    return res.json();
  }

  async function msRefreshPreview() {
    const date = document.getElementById('ms-report-date')?.value || '';
    const sales = document.getElementById('ms-sales-name')?.value?.trim() || '';
    const stats = {};
    document.querySelectorAll('.ms-stat-inp').forEach(inp => {
      stats[inp.dataset.field] = inp.value;
    });
    const r = await msPost('ms_report_preview', { stat_date: date, sales_display_name: sales, ...stats });
    if (r.success) {
      const pre = document.getElementById('ms-report-preview');
      if (pre) pre.textContent = r.text || '';
    }
  }

  document.querySelectorAll('.ms-entry-type-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      msEntryType = btn.dataset.type || 'deposit';
      document.getElementById('ms-entry-type').value = msEntryType;
      document.querySelectorAll('.ms-entry-type-btn').forEach(b => {
        const on = b.dataset.type === msEntryType;
        b.classList.toggle('border-[#E2E800]', on);
        b.classList.toggle('bg-[#E2E800]/15', on);
        b.classList.toggle('text-[var(--accent-text)]', on);
        b.classList.toggle('border-[var(--border)]', !on);
        b.classList.toggle('bg-[var(--surface)]', !on);
        b.classList.toggle('text-[var(--muted)]', !on);
      });
    });
  });

  document.getElementById('ms-save-name-btn')?.addEventListener('click', async () => {
    const name = document.getElementById('ms-sales-name')?.value?.trim() || '';
    const r = await msPost('ms_save_sales_name', { sales_display_name: name });
    if (r.success) msRefreshPreview();
  });

  document.getElementById('ms-save-stats-btn')?.addEventListener('click', async () => {
    const date = document.getElementById('ms-report-date')?.value || '';
    const payload = { stat_date: date };
    document.querySelectorAll('.ms-stat-inp').forEach(inp => {
      payload[inp.dataset.field] = inp.value;
    });
    await msPost('ms_save_stats', payload);
    msRefreshPreview();
  });

  document.getElementById('ms-add-entry-btn')?.addEventListener('click', async () => {
    const date = document.getElementById('ms-report-date')?.value || '';
    const r = await msPost('ms_add_entry', {
      entry_type: msEntryType,
      entry_date: date,
      customer_name: document.getElementById('ms-customer-name')?.value?.trim() || '',
      location: document.getElementById('ms-location')?.value?.trim() || '',
      amount: stripNum(document.getElementById('ms-amount')?.value || '') || '0',
      work_date: document.getElementById('ms-work-date')?.value || '',
    });
    if (r.success) location.reload();
  });

  document.getElementById('ms-report-date')?.addEventListener('change', () => {
    const d = document.getElementById('ms-report-date')?.value;
    if (d) location.href = 'dashboard.php?report_date=' + encodeURIComponent(d) + '#report';
  });

  document.getElementById('ms-copy-report-btn')?.addEventListener('click', async () => {
    const text = document.getElementById('ms-report-preview')?.textContent || '';
    try {
      await navigator.clipboard.writeText(text);
    } catch (_) {
      const ta = document.createElement('textarea');
      ta.value = text;
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      ta.remove();
    }
  });

  document.querySelectorAll('.ms-stat-inp').forEach(inp => {
    inp.addEventListener('change', () => msRefreshPreview());
  });
}

// ===== เปิดหน้าโปรไฟล (รองรับ LIFF / iframe) =====
(function () {
  const profileUrl = <?php echo json_encode($profile_url, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
  const navigateProfile = () => {
    unloadReportFrame();
    if (window.liff && typeof liff.isInClient === 'function' && liff.isInClient()) {
      liff.openWindow({ url: profileUrl, external: false });
      return;
    }
    if (window.top !== window.self) {
      window.top.location.href = profileUrl;
      return;
    }
    window.location.href = profileUrl;
  };
  const goProfile = (e) => {
    e.preventDefault();
    unsavedGuard.confirmLeave(navigateProfile);
  };
  document.querySelectorAll('a[href*="profile.php"]').forEach((a) => {
    a.addEventListener('click', goProfile);
  });
})();

// ===== ตัวเลขใส่ลูกน้ำอัตโนมัติ (ช่อง .fmt-num) =====
function stripNum(s) { return String(s).replace(/\D/g, ''); }
function fmtNum(s) {
  const raw = stripNum(s);
  if (!raw) return '';
  return parseInt(raw, 10).toLocaleString('en-US');
}
mountAllFmtNums();

// ===== Pipeline: เลือกเดือนเป้า (custom picker) =====
const plMonthValueEl = document.getElementById('pl-month-value');
const plMonthLabelText = document.getElementById('pl-month-label-text');
const plMonthSheet = document.getElementById('pl-month-sheet');
const plSheetYearEl = document.getElementById('pl-sheet-year');
let plSheetYear = parseLeadYm(plMonthValueEl?.value).y;

function navigatePipelineMonth(ym) {
  const base = window.location.pathname;
  const params = new URLSearchParams(window.location.search);
  params.set('pl_month', ym);
  const qs = params.toString();
  window.location.href = base + (qs ? '?' + qs : '') + '#pipeline';
}

function syncPlMonthChips(selY, selM) {
  document.querySelectorAll('.pl-month-chip').forEach(chip => {
    const cm = parseInt(chip.dataset.month, 10);
    const active = selY === plSheetYear && cm === selM;
    chip.classList.toggle('bg-[#E2E800]', active);
    chip.classList.toggle('text-[#141414]', active);
    chip.classList.toggle('border-[#E2E800]', active);
    chip.classList.toggle('bg-[var(--card)]', !active);
    chip.classList.toggle('text-[var(--muted)]', !active);
    chip.classList.toggle('border-[var(--border)]', !active);
  });
}

function setPipelineMonth(ym, navigate) {
  const { y, m } = parseLeadYm(ym);
  if (plMonthValueEl) plMonthValueEl.value = ym;
  if (plMonthLabelText) plMonthLabelText.textContent = formatLeadMonthLabel(y, m);
  const hidden = document.querySelector('#pipeline-form input[name="target_month"]');
  if (hidden) hidden.value = ym;
  plSheetYear = y;
  if (plSheetYearEl) plSheetYearEl.textContent = String(y);
  syncPlMonthChips(y, m);
  if (navigate) navigatePipelineMonth(ym);
}

function shiftPipelineMonth(delta) {
  const { y, m } = parseLeadYm(plMonthValueEl?.value);
  let nm = m + delta, ny = y;
  if (nm < 1) { nm = 12; ny--; }
  if (nm > 12) { nm = 1; ny++; }
  navigatePipelineMonth(formatLeadYm(ny, nm));
}

function openPlMonthSheet() {
  const { y, m } = parseLeadYm(plMonthValueEl?.value);
  plSheetYear = y;
  if (plSheetYearEl) plSheetYearEl.textContent = String(y);
  syncPlMonthChips(y, m);
  plMonthSheet?.classList.remove('hidden');
  if (window.lucide) lucide.createIcons();
}
function closePlMonthSheet() {
  plMonthSheet?.classList.add('hidden');
}

document.getElementById('pl-month-prev')?.addEventListener('click', () => shiftPipelineMonth(-1));
document.getElementById('pl-month-next')?.addEventListener('click', () => shiftPipelineMonth(1));
document.getElementById('pl-month-open')?.addEventListener('click', openPlMonthSheet);
document.getElementById('pl-month-sheet-close')?.addEventListener('click', closePlMonthSheet);
document.getElementById('pl-month-sheet-backdrop')?.addEventListener('click', closePlMonthSheet);
document.getElementById('pl-sheet-year-prev')?.addEventListener('click', () => {
  plSheetYear--;
  if (plSheetYearEl) plSheetYearEl.textContent = String(plSheetYear);
  const { m } = parseLeadYm(plMonthValueEl?.value);
  syncPlMonthChips(plSheetYear, m);
});
document.getElementById('pl-sheet-year-next')?.addEventListener('click', () => {
  plSheetYear++;
  if (plSheetYearEl) plSheetYearEl.textContent = String(plSheetYear);
  const { m } = parseLeadYm(plMonthValueEl?.value);
  syncPlMonthChips(plSheetYear, m);
});
document.querySelectorAll('.pl-month-chip').forEach(chip => {
  chip.addEventListener('click', () => {
    const ym = formatLeadYm(plSheetYear, parseInt(chip.dataset.month, 10));
    closePlMonthSheet();
    navigatePipelineMonth(ym);
  });
});
document.getElementById('pl-month-this')?.addEventListener('click', () => {
  const now = new Date();
  closePlMonthSheet();
  navigatePipelineMonth(formatLeadYm(now.getFullYear(), now.getMonth() + 1));
});

// ===== Pipeline: อัปเดต Win อัตโนมัติเมื่อเปลี่ยนเป้ารายได้/คอม =====
function updateWinDisplay() {
  const el = document.getElementById('need-win-display');
  if (!el) return;
  const target = parseInt(stripNum(document.getElementById('pl-monthly-target').value), 10) || 0;
  const comm = parseInt(stripNum(document.getElementById('pl-commission').value), 10) || 1000;
  el.value = target > 0 ? Math.max(1, Math.ceil(target / comm)) : 0;
}
['pl-monthly-target', 'pl-commission'].forEach(id => {
  const el = document.getElementById(id);
  if (el) el.addEventListener('input', updateWinDisplay);
});
document.querySelectorAll('.need-count').forEach(el => {
  el.addEventListener('input', () => { el.value = stripNum(el.value); });
});

// ===== Pipeline: บันทึกเป้า =====
document.getElementById('pipeline-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const fd = new FormData(e.target);
  const params = { ajax: 'pipeline_save' };
  fd.forEach((v, k) => {
    const el = e.target.elements[k];
    if (el && el.classList.contains('fmt-num')) params[k] = stripNum(v);
    else if (el && el.classList.contains('need-count')) params[k] = stripNum(v) || '0';
    else params[k] = v;
  });
  const r = await taskApi(params);
  if (r.success) {
    unsavedGuard.endContext('pipeline');
    unloadReportFrame();
    location.hash = '#pipeline';
    reloadDashboard();
  }
  else alert('บันทึกไม่สำเร็จ');
});

// ===== ค้นหาในลิสต์ =====
function filterList(listName, query) {
  query = query.trim().toLowerCase();
  document.querySelectorAll('#list-' + listName + ' > li').forEach(li => {
    li.classList.toggle('hidden', query !== '' && !li.dataset.search.includes(query));
  });
}

// ===== กรอง Product ตามเกรด + ค้นหา =====
let productGradeFilter = '';
function applyProductFilters() {
  const q = (document.getElementById('search-products')?.value || '').trim().toLowerCase();
  document.querySelectorAll('#list-products > li').forEach(li => {
    const okSearch = !q || (li.dataset.search || '').includes(q);
    const okGrade  = !productGradeFilter || li.dataset.grade === productGradeFilter;
    li.classList.toggle('hidden', !(okSearch && okGrade));
  });
}
function filterProductGrade(btn, grade) {
  productGradeFilter = grade;
  document.querySelectorAll('.product-grade-filter').forEach(b => {
    b.classList.remove('bg-[#E2E800]', 'text-[#141414]', 'border-[#E2E800]');
    b.classList.add('bg-[var(--card)]', 'text-[var(--muted)]', 'border-[var(--border)]');
  });
  btn.classList.add('bg-[#E2E800]', 'text-[#141414]', 'border-[#E2E800]');
  btn.classList.remove('bg-[var(--card)]', 'text-[var(--muted)]', 'border-[var(--border)]');
  applyProductFilters();
}

// ===== กรอง Lead ตามสถานะ + เดือน =====
let leadStatusFilter = '';
let leadMonthFilter = <?php echo json_encode($lead_month_all ? '' : $lead_filter_month); ?>;
let leadMonthAll = <?php echo $lead_month_all ? 'true' : 'false'; ?>;

const LEAD_CHIP_KEYS = {
  all: [''],
  Call: ['Call'],
  Follow: ['Follow'],
  Appointment: ['Appointment'],
  Show: ['Show'],
  Reserve: ['Reserve'],
  Nego: ['Nego', 'Close', 'Bank'],
  Win: ['Win'],
  Lose: ['Lose'],
  Reject: ['Rejected', 'Hold_Reject'],
};

function leadMatchesStatus(status, allowed) {
  if (allowed === null) return true;
  return allowed.includes(status);
}

function updateLeadChipCounts(month) {
  const counts = { all: 0, Call: 0, Follow: 0, Appointment: 0, Show: 0, Reserve: 0, Nego: 0, Win: 0, Lose: 0, Reject: 0 };
  Object.values(leadDetails).forEach(d => {
    const okMonth = !month || (d.filter_month || '') === month;
    if (!okMonth) return;
    const st = d.status;
    counts.all++;
    if (st === 'Call') counts.Call++;
    else if (st === 'Follow') counts.Follow++;
    else if (st === 'Appointment') counts.Appointment++;
    else if (st === 'Show') counts.Show++;
    else if (st === 'Reserve') counts.Reserve++;
    else if (['Nego', 'Close', 'Bank'].includes(st)) counts.Nego++;
    else if (st === 'Win') counts.Win++;
    else if (st === 'Lose') counts.Lose++;
    else if (['Rejected', 'Hold_Reject'].includes(st)) counts.Reject++;
  });
  document.querySelectorAll('.lead-filter').forEach(btn => {
    const key = btn.dataset.countKey;
    const span = btn.querySelector('span.tabular-nums');
    if (span && counts[key] !== undefined) span.textContent = '(' + counts[key] + ')';
  });
}

function applyLeadFilters() {
  plMatrixResetPage('lead-matrix-root');
  if (typeof renderPipelineMatrix === 'function') renderPipelineMatrix('lead-matrix-root');
}

function filterLeadStatus(btn, statuses) {
  leadStatusFilter = statuses;
  document.querySelectorAll('.lead-filter').forEach(b => {
    b.classList.remove('bg-[#E2E800]', 'text-[#141414]', 'border-[#E2E800]');
    b.classList.add('bg-[var(--card)]', 'text-[var(--muted)]', 'border-[var(--border)]');
  });
  btn.classList.add('bg-[#E2E800]', 'text-[#141414]', 'border-[#E2E800]');
  btn.classList.remove('bg-[var(--card)]', 'text-[var(--muted)]', 'border-[var(--border)]');
  applyLeadFilters();
}

const THAI_MONTHS_FULL = ['','มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];

function parseLeadYm(ym) {
  const [y, m] = (ym || '').split('-').map(Number);
  return { y: y || new Date().getFullYear(), m: m || (new Date().getMonth() + 1) };
}
function formatLeadYm(y, m) {
  return y + '-' + String(m).padStart(2, '0');
}
function formatLeadMonthLabel(y, m) {
  return (THAI_MONTHS_FULL[m] || m) + ' ' + y;
}

const leadMonthValueEl = document.getElementById('lead-month-value');
const leadMonthLabelText = document.getElementById('lead-month-label-text');
const leadMonthAllBtn = document.getElementById('lead-month-all');
const leadMonthSheet = document.getElementById('lead-month-sheet');
const leadSheetYearEl = document.getElementById('lead-sheet-year');
let leadSheetYear = parseLeadYm(leadMonthValueEl?.value).y;

function syncLeadMonthAllBtn() {
  if (!leadMonthAllBtn) return;
  leadMonthAllBtn.classList.toggle('lead-matrix-toolbar-btn--active', leadMonthAll);
}

function syncLeadMonthThisBtn() {
  const btn = document.getElementById('lead-month-this-toolbar');
  if (!btn) return;
  const now = new Date();
  const thisYm = formatLeadYm(now.getFullYear(), now.getMonth() + 1);
  btn.classList.toggle('lead-matrix-toolbar-btn--active', !leadMonthAll && leadMonthFilter === thisYm);
}

function setLeadMonth(ym, allMode) {
  const { y, m } = parseLeadYm(ym);
  leadMonthFilter = allMode ? '' : ym;
  leadMonthAll = allMode;
  if (leadMonthValueEl) leadMonthValueEl.value = ym;
  if (leadMonthLabelText) leadMonthLabelText.textContent = formatLeadMonthLabel(y, m);
  leadSheetYear = y;
  if (leadSheetYearEl) leadSheetYearEl.textContent = String(y);
  syncLeadMonthAllBtn();
  syncLeadMonthThisBtn();
  syncLeadMonthChips(y, m);
  updateLeadChipCounts(leadMonthFilter);
  applyLeadFilters();
  if (typeof renderAllPipelineMatrices === 'function') renderAllPipelineMatrices();
}

function syncLeadMonthChips(selY, selM) {
  document.querySelectorAll('.lead-month-chip').forEach(chip => {
    const cm = parseInt(chip.dataset.month, 10);
    const active = !leadMonthAll && selY === leadSheetYear && cm === selM;
    chip.classList.toggle('bg-[#E2E800]', active);
    chip.classList.toggle('text-[#141414]', active);
    chip.classList.toggle('border-[#E2E800]', active);
    chip.classList.toggle('bg-[var(--card)]', !active);
    chip.classList.toggle('text-[var(--muted)]', !active);
    chip.classList.toggle('border-[var(--border)]', !active);
  });
}

function setLeadMonthThis() {
  const now = new Date();
  setLeadMonth(formatLeadYm(now.getFullYear(), now.getMonth() + 1), false);
}

function shiftLeadMonth(delta) {
  const { y, m } = parseLeadYm(leadMonthValueEl?.value);
  let nm = m + delta, ny = y;
  if (nm < 1) { nm = 12; ny--; }
  if (nm > 12) { nm = 1; ny++; }
  setLeadMonth(formatLeadYm(ny, nm), false);
}

function openLeadMonthSheet() {
  const { y, m } = parseLeadYm(leadMonthValueEl?.value);
  leadSheetYear = y;
  if (leadSheetYearEl) leadSheetYearEl.textContent = String(y);
  syncLeadMonthChips(y, m);
  leadMonthSheet?.classList.remove('hidden');
  if (window.lucide) lucide.createIcons();
}
function closeLeadMonthSheet() {
  leadMonthSheet?.classList.add('hidden');
}

document.getElementById('lead-month-prev')?.addEventListener('click', () => shiftLeadMonth(-1));
document.getElementById('lead-month-next')?.addEventListener('click', () => shiftLeadMonth(1));
document.getElementById('lead-month-open')?.addEventListener('click', openLeadMonthSheet);
document.getElementById('lead-month-sheet-close')?.addEventListener('click', closeLeadMonthSheet);
document.getElementById('lead-month-sheet-backdrop')?.addEventListener('click', closeLeadMonthSheet);
document.getElementById('lead-sheet-year-prev')?.addEventListener('click', () => {
  leadSheetYear--;
  if (leadSheetYearEl) leadSheetYearEl.textContent = String(leadSheetYear);
  const { m } = parseLeadYm(leadMonthValueEl?.value);
  syncLeadMonthChips(leadSheetYear, m);
});
document.getElementById('lead-sheet-year-next')?.addEventListener('click', () => {
  leadSheetYear++;
  if (leadSheetYearEl) leadSheetYearEl.textContent = String(leadSheetYear);
  const { m } = parseLeadYm(leadMonthValueEl?.value);
  syncLeadMonthChips(leadSheetYear, m);
});
document.querySelectorAll('.lead-month-chip').forEach(chip => {
  chip.addEventListener('click', () => {
    const m = parseInt(chip.dataset.month, 10);
    setLeadMonth(formatLeadYm(leadSheetYear, m), false);
    closeLeadMonthSheet();
  });
});
document.getElementById('lead-month-this')?.addEventListener('click', () => {
  setLeadMonthThis();
  closeLeadMonthSheet();
});
document.getElementById('lead-month-this-toolbar')?.addEventListener('click', setLeadMonthThis);
document.getElementById('lead-month-clear')?.addEventListener('click', () => {
  setLeadMonth(leadMonthValueEl?.value || formatLeadYm(new Date().getFullYear(), new Date().getMonth() + 1), true);
  closeLeadMonthSheet();
});
if (leadMonthAllBtn) {
  leadMonthAllBtn.addEventListener('click', () => {
    setLeadMonth(leadMonthValueEl?.value || formatLeadYm(new Date().getFullYear(), new Date().getMonth() + 1), true);
  });
}
const searchLeadsEl = document.getElementById('search-leads');
if (searchLeadsEl) {
  const origFilterList = window.filterList;
  searchLeadsEl.addEventListener('input', () => applyLeadFilters());
}
function bootLeadPageUi() {
  mountAllAppSelects();
  mountAllAppDateInputs();
  initOwnerCodeDatalist();
  const { y, m } = parseLeadYm(leadMonthValueEl?.value);
  syncLeadMonthAllBtn();
  syncLeadMonthThisBtn();
  syncLeadMonthChips(y, m);
  if (!leadMonthAll && leadMonthFilter) updateLeadChipCounts(leadMonthFilter);
  applyLeadFilters();
}
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', bootLeadPageUi);
} else {
  bootLeadPageUi();
}

const taskDates = <?php echo json_encode($task_dates); ?>;
const TODAY_STR = '<?php echo $today_str; ?>';

async function refreshTaskList() {
  const r = await taskApi({ ajax: 'task_list_html' });
  if (!r.success) return;
  const anchor = document.getElementById('task-list-anchor');
  if (anchor) anchor.innerHTML = r.html || '';
  if (r.task_dates) {
    Object.keys(taskDates).forEach(k => { delete taskDates[k]; });
    Object.assign(taskDates, r.task_dates);
  }
  const cnt = document.getElementById('tasks-pending-count');
  if (cnt && r.pending !== undefined) cnt.textContent = r.pending + ' งานค้าง';
  bindTaskInteractions();
  renderCalendar();
  if (window.lucide) lucide.createIcons();
}

async function updateTaskDueDate(taskId, due, oldDue, title) {
  const r = await taskApi({ ajax: 'task_update', id: taskId, due_date: due });
  closeTaskContextMenu();
  closeTaskDateSheet();
  if (r.success) {
    const label = due ? 'กำหนดวันแล้ว' : 'ลบวันแล้ว';
    pushTaskHistory({
      type: 'update', id: taskId, field: 'due_date', oldVal: oldDue ?? '', newVal: due,
      undoLabel: 'ย้อนการเปลี่ยนวันแล้ว', redoLabel: 'เปลี่ยนวันอีกครั้ง',
      refreshOnUndo: true, refreshOnRedo: true,
    });
    await refreshTaskList();
    showTaskUndoToast(label + ' "' + (title || 'งาน') + '"');
  } else alert(r.message || 'อัปเดตไม่สำเร็จ');
  return r.success;
}

// ===== Tasks: toggle / เพิ่ม / ลบ / nest / undo-redo =====
async function taskApi(params) {
  try {
    const res = await fetch('dashboard.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams(params)
    });
    return await res.json();
  } catch (e) {
    return { success: false, message: 'เชื่อมต่อไม่สำเร็จ' };
  }
}

const taskHistory = { undo: [], redo: [], max: 40 };
const TASK_UNDO_KEY = 'task_undo_stack_v2';
const TASK_REDO_KEY = 'task_redo_stack_v2';
let taskUndoToastTimer = null;

function taskLiById(id) {
  return document.querySelector('.task-item[data-task-id="' + id + '"]');
}

function buildTaskHistoryEntry(data) {
  const base = {
    undoLabel: data.undoLabel || 'ย้อนกลับแล้ว',
    redoLabel: data.redoLabel || 'ทำซ้ำแล้ว',
    reloadOnUndo: !!data.reloadOnUndo,
    reloadOnRedo: !!data.reloadOnRedo,
    refreshOnUndo: !!data.refreshOnUndo,
    refreshOnRedo: !!data.refreshOnRedo,
    serializable: data,
  };
  if (data.type === 'delete' || data.type === 'add') {
    const snapJson = JSON.stringify(data.snapshot);
    base.undo = async () => {
      if (data.type === 'delete') {
        const u = await taskApi({ ajax: 'task_restore', snapshot: snapJson });
        if (u.success) await refreshTaskList();
        return !!u.success;
      }
      const u = await taskApi({ ajax: 'task_delete', id: data.snapshot.task.id });
      if (u.success) await refreshTaskList();
      return !!(u.success && u.snapshot);
    };
    base.redo = async () => {
      if (data.type === 'delete') {
        const u = await taskApi({ ajax: 'task_delete', id: data.snapshot.task.id });
        if (u.success) await refreshTaskList();
        return !!(u.success && u.snapshot);
      }
      const u = await taskApi({ ajax: 'task_restore', snapshot: snapJson });
      if (u.success) await refreshTaskList();
      return !!u.success;
    };
  } else if (data.type === 'toggle') {
    base.undo = async () => {
      const u = await taskApi({ ajax: 'task_toggle', id: data.id, is_completed: data.wasDone });
      if (u.success) applyTaskToggleUi(taskLiById(data.id), data.wasDone === 1);
      return !!u.success;
    };
    base.redo = async () => {
      const u = await taskApi({ ajax: 'task_toggle', id: data.id, is_completed: data.newDone });
      if (u.success) applyTaskToggleUi(taskLiById(data.id), data.newDone === 1);
      return !!u.success;
    };
  } else if (data.type === 'nest') {
    base.undo = async () => {
      const u = await taskApi({ ajax: 'task_nest', child_id: data.childId, parent_id: data.oldParent });
      if (u.success) await refreshTaskList();
      return !!u.success;
    };
    base.redo = async () => {
      const u = await taskApi({ ajax: 'task_nest', child_id: data.childId, parent_id: data.newParent });
      if (u.success) await refreshTaskList();
      return !!u.success;
    };
  } else if (data.type === 'update') {
    base.undo = async () => {
      const u = await taskApi({ ajax: 'task_update', id: data.id, [data.field]: data.oldVal });
      if (u.success) await refreshTaskList();
      return !!u.success;
    };
    base.redo = async () => {
      const u = await taskApi({ ajax: 'task_update', id: data.id, [data.field]: data.newVal });
      if (u.success) await refreshTaskList();
      return !!u.success;
    };
  }
  return base;
}

function persistTaskHistory() {
  try {
    sessionStorage.setItem(TASK_UNDO_KEY, JSON.stringify(taskHistory.undo.map(e => e.serializable)));
    sessionStorage.setItem(TASK_REDO_KEY, JSON.stringify(taskHistory.redo.map(e => e.serializable)));
  } catch (e) { /* quota */ }
}

function loadTaskHistory() {
  try {
    const undoRaw = JSON.parse(sessionStorage.getItem(TASK_UNDO_KEY) || '[]');
    const redoRaw = JSON.parse(sessionStorage.getItem(TASK_REDO_KEY) || '[]');
    taskHistory.undo = Array.isArray(undoRaw) ? undoRaw.map(buildTaskHistoryEntry) : [];
    taskHistory.redo = Array.isArray(redoRaw) ? redoRaw.map(buildTaskHistoryEntry) : [];
  } catch (e) {
    taskHistory.undo = [];
    taskHistory.redo = [];
  }
  updateTaskUndoButtons();
}

function updateTaskUndoButtons() {
  const undoBtn = document.getElementById('task-undo-btn');
  const redoBtn = document.getElementById('task-redo-btn');
  if (undoBtn) undoBtn.disabled = taskHistory.undo.length === 0;
  if (redoBtn) redoBtn.disabled = taskHistory.redo.length === 0;
}

function pushTaskHistory(data) {
  const entry = buildTaskHistoryEntry(data);
  taskHistory.undo.push(entry);
  if (taskHistory.undo.length > taskHistory.max) taskHistory.undo.shift();
  taskHistory.redo = [];
  persistTaskHistory();
  updateTaskUndoButtons();
}

function hideTaskUndoToast() {
  clearTimeout(taskUndoToastTimer);
  document.getElementById('task-undo-toast')?.classList.add('hidden');
}

function showTaskUndoToast(message, onUndoClick) {
  const toast = document.getElementById('task-undo-toast');
  const msg = document.getElementById('task-undo-toast-msg');
  const btn = document.getElementById('task-undo-toast-btn');
  if (!toast || !msg || !btn) return;
  msg.textContent = message;
  toast.classList.remove('hidden');
  if (window.lucide) lucide.createIcons();
  clearTimeout(taskUndoToastTimer);
  const handler = async () => {
    btn.removeEventListener('click', handler);
    hideTaskUndoToast();
    if (typeof onUndoClick === 'function') await onUndoClick();
    else await runTaskUndo();
  };
  btn.addEventListener('click', handler);
  taskUndoToastTimer = setTimeout(hideTaskUndoToast, 6500);
}

function applyTaskToggleUi(li, isDone) {
  if (!li) return;
  const toggle = li.querySelector('.task-toggle');
  const title = li.querySelector('.text-sm');
  if (isDone) {
    li.classList.add('opacity-70');
    title?.classList.add('line-through', 'text-[var(--faint)]');
    if (toggle) {
      toggle.dataset.done = '1';
      toggle.className = 'task-toggle w-5 h-5 rounded-full border-2 shrink-0 flex items-center justify-center bg-emerald-500 border-emerald-500';
      toggle.innerHTML = '<i data-lucide="check" class="w-3 h-3 text-white"></i>';
    }
  } else {
    li.classList.remove('opacity-70');
    title?.classList.remove('line-through', 'text-[var(--faint)]');
    if (toggle) {
      toggle.dataset.done = '0';
      toggle.className = 'task-toggle w-5 h-5 rounded-full border-2 shrink-0 flex items-center justify-center transition border-[var(--border-2)] active:border-[#E2E800]';
      toggle.innerHTML = '';
    }
  }
  if (window.lucide) lucide.createIcons();
}

function removeTaskFromDom(li) {
  if (!li) return;
  const groupEl = li.closest('.task-group');
  li.remove();
  if (groupEl) updateTaskGroupCount(groupEl);
}

async function runTaskUndo() {
  const entry = taskHistory.undo.pop();
  if (!entry) return;
  persistTaskHistory();
  updateTaskUndoButtons();
  const ok = await entry.undo();
  if (ok) {
    taskHistory.redo.push(entry);
    persistTaskHistory();
    updateTaskUndoButtons();
    if (entry.refreshOnUndo) { await refreshTaskList(); showTaskUndoToast(entry.undoLabel || 'ย้อนกลับแล้ว'); return; }
    if (entry.reloadOnUndo) { reloadDashboard(); return; }
    showTaskUndoToast(entry.undoLabel || 'ย้อนกลับแล้ว');
  } else {
    taskHistory.undo.push(entry);
    persistTaskHistory();
    alert('ย้อนกลับไม่สำเร็จ');
  }
  updateTaskUndoButtons();
}

async function runTaskRedo() {
  const entry = taskHistory.redo.pop();
  if (!entry) return;
  persistTaskHistory();
  updateTaskUndoButtons();
  const ok = await entry.redo();
  if (ok) {
    taskHistory.undo.push(entry);
    persistTaskHistory();
    updateTaskUndoButtons();
    if (entry.refreshOnRedo) { await refreshTaskList(); showTaskUndoToast(entry.redoLabel || 'ทำซ้ำแล้ว'); return; }
    if (entry.reloadOnRedo) { reloadDashboard(); return; }
    showTaskUndoToast(entry.redoLabel || 'ทำซ้ำแล้ว');
  } else {
    taskHistory.redo.push(entry);
    persistTaskHistory();
    alert('ทำซ้ำไม่สำเร็จ');
  }
  updateTaskUndoButtons();
}

document.getElementById('task-undo-btn')?.addEventListener('click', runTaskUndo);
document.getElementById('task-redo-btn')?.addEventListener('click', runTaskRedo);
document.getElementById('task-undo-toast-dismiss')?.addEventListener('click', hideTaskUndoToast);

document.addEventListener('keydown', (e) => {
  if (e.target.matches('input, textarea, select, [contenteditable="true"]')) return;
  const onTasks = document.getElementById('page-tasks') && !document.getElementById('page-tasks').classList.contains('hidden');
  if (!onTasks) return;
  const mod = e.ctrlKey || e.metaKey;
  if (!mod) return;
  if (e.key === 'z' && !e.shiftKey) {
    e.preventDefault();
    runTaskUndo();
  } else if (e.key === 'y' || (e.key === 'z' && e.shiftKey)) {
    e.preventDefault();
    runTaskRedo();
  }
});

updateTaskUndoButtons();
loadTaskHistory();

document.addEventListener('click', async (e) => {
  const inCtxMenu = e.target.closest('#task-context-menu');
  const isMenuBtn = e.target.closest('.task-menu-btn');
  const inDateSheet = e.target.closest('#task-date-sheet');
  if (!inCtxMenu && !isMenuBtn && !inDateSheet) closeTaskContextMenu();

  const toggle = e.target.closest('.task-toggle');
  if (toggle) {
    const li = toggle.closest('.task-item');
    const taskId = li.dataset.taskId;
    const wasDone = toggle.dataset.done === '1' ? 1 : 0;
    const newDone = wasDone ? 0 : 1;
    const r = await taskApi({ ajax: 'task_toggle', id: taskId, is_completed: newDone });
    if (r.success) {
      applyTaskToggleUi(li, newDone === 1);
      li.dataset.done = String(newDone);
      const title = li.querySelector('.text-sm')?.textContent?.trim() || 'งาน';
      pushTaskHistory({
        type: 'toggle', id: taskId, wasDone, newDone,
        undoLabel: newDone ? 'ยกเลิกทำเสร็จแล้ว' : 'ทำเสร็จอีกครั้ง',
        redoLabel: newDone ? 'ทำเสร็จอีกครั้ง' : 'ยกเลิกทำเสร็จแล้ว',
        refreshOnUndo: true, refreshOnRedo: true,
      });
      showTaskUndoToast(newDone ? 'ทำเสร็จ "' + title + '"' : 'ยกเลิกทำเสร็จ "' + title + '"');
    }
    return;
  }

  const menuBtn = e.target.closest('.task-menu-btn');
  if (menuBtn) {
    e.stopPropagation();
    openTaskContextMenu(menuBtn, menuBtn.closest('.task-item'));
    return;
  }

  const openArea = e.target.closest('.task-open');
  if (openArea && !suppressTaskClick && !dragState) {
    openTaskDetail(openArea.closest('.task-item'));
    return;
  }
});

// ===== Task: รายละเอียด · เมนู ⋯ · ลากย้าย =====
const PRIO_LABELS = { 0: 'ไม่ระบุ', 1: 'ทำทันที', 2: 'วางแผนทำ', 3: 'มอบหมาย', 4: 'ทำทีหลัง' };
let taskDetailId = null;
let dragState = null;
let suppressTaskClick = false;
let dragHoldTimer = null;
const DRAG_HOLD_MS = 320;

function taskIsDescendant(ancestorId, childId) {
  let el = document.querySelector('.task-item[data-task-id="' + childId + '"]');
  while (el) {
    const pid = parseInt(el.dataset.parentId || '0', 10);
    if (pid === ancestorId) return true;
    if (!pid) break;
    el = document.querySelector('.task-item[data-task-id="' + pid + '"]');
  }
  return false;
}

function addDaysToDateStr(base, days) {
  const d = new Date((base || TODAY_STR) + 'T12:00:00');
  d.setDate(d.getDate() + days);
  return d.toISOString().slice(0, 10);
}

function closeTaskContextMenu() {
  const menu = document.getElementById('task-context-menu');
  if (!menu) return;
  menu.classList.add('hidden');
  delete menu.dataset.taskId;
  delete menu.dataset.due;
  delete menu.dataset.priority;
  delete menu.dataset.parentId;
  delete menu.dataset.title;
}

function openTaskContextMenu(btn, li) {
  const menu = document.getElementById('task-context-menu');
  if (!menu || !li) return;
  menu.dataset.taskId = li.dataset.taskId || '';
  menu.dataset.due = li.dataset.due || '';
  menu.dataset.priority = li.dataset.priority || '0';
  menu.dataset.parentId = li.dataset.parentId || '0';
  menu.dataset.title = li.querySelector('.text-sm')?.textContent?.trim() || 'งาน';
  const unnest = document.getElementById('task-ctx-unnest');
  if (unnest) unnest.classList.toggle('hidden', !(parseInt(menu.dataset.parentId || '0', 10) > 0));
  menu.classList.remove('hidden');
  const rect = btn.getBoundingClientRect();
  let top = rect.bottom + 6;
  let left = Math.min(rect.right - 260, window.innerWidth - 272);
  if (top + 280 > window.innerHeight) top = Math.max(8, rect.top - 280);
  if (left < 8) left = 8;
  menu.style.top = top + 'px';
  menu.style.left = left + 'px';
  if (window.lucide) lucide.createIcons();
}

async function deleteTaskLi(li) {
  if (!li) return;
  const title = li.querySelector('.text-sm')?.textContent?.trim() || 'งาน';
  const r = await taskApi({ ajax: 'task_delete', id: li.dataset.taskId });
  if (r.success && r.snapshot) {
    removeTaskFromDom(li);
    pushTaskHistory({
      type: 'delete', snapshot: r.snapshot,
        undoLabel: 'กู้คืนงานแล้ว', redoLabel: 'ลบงานอีกครั้ง',
        refreshOnUndo: true, refreshOnRedo: true,
    });
    showTaskUndoToast('ลบ "' + title + '" แล้ว');
  } else if (!r.success) {
    alert(r.message || 'ลบไม่สำเร็จ');
  }
}

function closeTaskDetailSheet(force) {
  const doClose = () => {
    unsavedGuard.endContext('task-detail');
    document.getElementById('task-detail-sheet')?.classList.add('hidden');
    taskDetailId = null;
  };
  if (force) { doClose(); return; }
  unsavedGuard.confirmLeave(doClose);
}

function leadCodeForDisplay(code) {
  if (!code) return '';
  return String(code).replace(/-R\d+$/i, '');
}
function leadTitleForDisplay(title) {
  if (!title) return '';
  return String(title).replace(/(LEAD-[A-Za-z0-9][\w.-]*)-R\d+/gi, '$1');
}

function syncTaskDetailTitleHeight(el) {
  el = el || document.getElementById('task-detail-title');
  if (!el) return;
  const maxPx = Math.max(120, Math.floor(window.innerHeight * 0.4));
  el.style.height = 'auto';
  el.style.height = Math.min(el.scrollHeight, maxPx) + 'px';
}

function fillTaskDetailSheet(t) {
  taskDetailId = t.id;
  const titleEl = document.getElementById('task-detail-title');
  if (titleEl) {
    titleEl.value = leadTitleForDisplay(t.title || '');
    syncTaskDetailTitleHeight(titleEl);
  }
  unsavedGuard.beginContext('task-detail', [titleEl]);
  const dueParts = [];
  if (t.due_date) dueParts.push(t.due_date);
  if (t.due_time) dueParts.push(t.due_time + ' น.');
  document.getElementById('task-detail-due').textContent = dueParts.length ? dueParts.join(' · ') : 'ยังไม่กำหนด';
  document.getElementById('task-detail-prio').textContent = PRIO_LABELS[t.priority] || 'ไม่ระบุ';
  const leadWrap = document.getElementById('task-detail-lead-wrap');
  if (t.lead_code) {
    leadWrap?.classList.remove('hidden');
    document.getElementById('task-detail-lead').textContent = leadCodeForDisplay(t.lead_code) + (t.owner_code ? ' · ' + t.owner_code : '');
  } else {
    leadWrap?.classList.add('hidden');
  }
  document.getElementById('task-detail-parent-wrap')?.classList.toggle('hidden', !(t.parent_id > 0));
}

async function openTaskDetail(li) {
  if (!li) return;
  const r = await taskApi({ ajax: 'task_detail', id: li.dataset.taskId });
  if (!r.success || !r.task) { alert(r.message || 'โหลดไม่สำเร็จ'); return; }
  fillTaskDetailSheet(r.task);
  document.getElementById('task-detail-sheet')?.classList.remove('hidden');
  const titleEl = document.getElementById('task-detail-title');
  syncTaskDetailTitleHeight(titleEl);
  setTimeout(() => {
    titleEl?.focus();
    const len = titleEl?.value?.length || 0;
    if (titleEl && typeof titleEl.setSelectionRange === 'function') {
      titleEl.setSelectionRange(len, len);
    }
  }, 50);
  if (window.lucide) lucide.createIcons();
}

const taskDetailTitleEl = document.getElementById('task-detail-title');
if (taskDetailTitleEl && taskDetailTitleEl.dataset.bound !== '1') {
  taskDetailTitleEl.dataset.bound = '1';
  taskDetailTitleEl.addEventListener('input', () => syncTaskDetailTitleHeight(taskDetailTitleEl));
}

document.getElementById('task-detail-close')?.addEventListener('click', closeTaskDetailSheet);
document.getElementById('task-detail-backdrop')?.addEventListener('click', closeTaskDetailSheet);
document.getElementById('task-detail-save')?.addEventListener('click', async () => {
  if (!taskDetailId) return;
  const title = document.getElementById('task-detail-title').value.trim();
  if (!title) return;
  const r = await taskApi({ ajax: 'task_update', id: taskDetailId, title });
  if (r.success) {
    const li = taskLiById(taskDetailId);
    if (li) li.querySelector('.text-sm').textContent = title;
    closeTaskDetailSheet(true);
    showTaskUndoToast('บันทึกงานแล้ว');
  } else alert(r.message || 'บันทึกไม่สำเร็จ');
});

function initTaskContextMenu() {
  const menu = document.getElementById('task-context-menu');
  if (!menu || menu.dataset.bound === '1') return;
  menu.dataset.bound = '1';

  menu.addEventListener('click', async (e) => {
    e.stopPropagation();
    const taskId = menu.dataset.taskId;
    if (!taskId) return;
    const oldDue = menu.dataset.due || '';
    const oldPrio = parseInt(menu.dataset.priority || '0', 10);
    const title = menu.dataset.title || 'งาน';

    const dateBtn = e.target.closest('.task-ctx-date');
    if (dateBtn) {
      const kind = dateBtn.dataset.date;
      if (kind === 'today') {
        await updateTaskDueDate(taskId, TODAY_STR, oldDue, title);
      } else if (kind === 'tomorrow') {
        await updateTaskDueDate(taskId, addDaysToDateStr(TODAY_STR, 1), oldDue, title);
      } else if (kind === 'nextweek') {
        await updateTaskDueDate(taskId, addDaysToDateStr(TODAY_STR, 7), oldDue, title);
      } else if (kind === 'pick') {
        openTaskDateSheet(oldDue || TODAY_STR, async (ds) => {
          await updateTaskDueDate(taskId, ds, oldDue, title);
        });
      } else if (kind === 'clear') {
        await updateTaskDueDate(taskId, '', oldDue, title);
      }
      return;
    }

    const prioBtn = e.target.closest('.task-ctx-prio');
    if (prioBtn) {
      const newPrio = parseInt(prioBtn.dataset.priority || '0', 10);
      const r = await taskApi({ ajax: 'task_update', id: taskId, priority: newPrio });
      closeTaskContextMenu();
      if (r.success) {
        pushTaskHistory({
          type: 'update', id: taskId, field: 'priority', oldVal: oldPrio, newVal: newPrio,
          undoLabel: 'ย้อนการเปลี่ยนความสำคัญแล้ว', redoLabel: 'เปลี่ยนความสำคัญอีกครั้ง',
          refreshOnUndo: true, refreshOnRedo: true,
        });
        await refreshTaskList();
        showTaskUndoToast('เปลี่ยนความสำคัญ "' + title + '"');
      } else alert(r.message || 'อัปเดตไม่สำเร็จ');
      return;
    }

    if (e.target.closest('#task-ctx-delete')) {
      closeTaskContextMenu();
      const li = taskLiById(taskId);
      await deleteTaskLi(li);
      return;
    }

    if (e.target.closest('#task-ctx-unnest')) {
      const childId = parseInt(taskId, 10);
      const oldParent = parseInt(menu.dataset.parentId || '0', 10);
      closeTaskContextMenu();
      const r = await taskApi({ ajax: 'task_nest', child_id: childId, parent_id: 0 });
      if (r.success) {
        pushTaskHistory({
          type: 'nest', childId, oldParent, newParent: 0,
          undoLabel: 'ย้อนการยกออกแล้ว', redoLabel: 'ยกออกอีกครั้ง',
          refreshOnUndo: true, refreshOnRedo: true,
        });
        await refreshTaskList();
      } else alert(r.message || 'ยกออกไม่สำเร็จ');
    }
  });
}
initTaskContextMenu();

function clearDropHints() {
  document.querySelectorAll('.task-item').forEach(el => {
    el.classList.remove('task-drop-before', 'task-drop-after', 'task-drop-child');
  });
}

function getDropMode(targetLi, clientY) {
  if (!targetLi || targetLi === dragState?.li) return null;
  const rect = targetLi.getBoundingClientRect();
  const y = clientY - rect.top;
  const h = rect.height || 1;
  if (y < h * 0.28) return 'before';
  if (y > h * 0.72) return 'after';
  return 'child';
}

function pointerXY(e) {
  if (e.touches && e.touches[0]) return { x: e.touches[0].clientX, y: e.touches[0].clientY };
  return { x: e.clientX, y: e.clientY };
}

function startTaskDrag(li, x, y) {
  dragState = { li, moved: false, dropTarget: null, dropMode: null };
  const ghost = li.cloneNode(true);
  ghost.classList.add('task-drag-ghost');
  ghost.style.width = li.offsetWidth + 'px';
  ghost.style.left = (x - 24) + 'px';
  ghost.style.top = (y - 24) + 'px';
  document.body.appendChild(ghost);
  dragState.ghost = ghost;
  li.classList.add('task-dragging');
  if (navigator.vibrate) navigator.vibrate(18);
}

function updateTaskDrag(x, y) {
  if (!dragState) return;
  dragState.moved = true;
  if (dragState.ghost) {
    dragState.ghost.style.left = (x - 24) + 'px';
    dragState.ghost.style.top = (y - 24) + 'px';
  }
  clearDropHints();
  const under = document.elementFromPoint(x, y)?.closest('.task-item');
  if (!under || under === dragState.li) return;
  const mode = getDropMode(under, y);
  dragState.dropTarget = under;
  dragState.dropMode = mode;
  if (mode === 'before') under.classList.add('task-drop-before');
  else if (mode === 'after') under.classList.add('task-drop-after');
  else under.classList.add('task-drop-child');
}

async function endTaskDrag() {
  if (!dragState) return;
  const { li, dropTarget, dropMode, moved, ghost } = dragState;
  ghost?.remove();
  li.classList.remove('task-dragging');
  clearDropHints();
  const taskId = parseInt(li.dataset.taskId, 10);
  if (moved && dropTarget && dropMode) {
    const targetId = parseInt(dropTarget.dataset.taskId, 10);
    if (!taskIsDescendant(taskId, targetId)) {
      const r = await taskApi({ ajax: 'task_move', id: taskId, target_id: targetId, mode: dropMode });
      if (r.success) await refreshTaskList();
      else alert(r.message || 'ย้ายไม่สำเร็จ');
    }
  }
  if (moved) {
    suppressTaskClick = true;
    setTimeout(() => { suppressTaskClick = false; }, 450);
  }
  dragState = null;
}

function bindTaskDrag() {
  document.querySelectorAll('.task-item').forEach(li => {
    if (li.dataset.dragBound === '1') return;
    li.dataset.dragBound = '1';

    const onDown = (e) => {
      if (e.target.closest('.task-toggle, .task-menu-btn, button, input, a')) return;
      const { x, y } = pointerXY(e);
      let movedEarly = false;
      clearTimeout(dragHoldTimer);

      const onMove = (ev) => {
        const p = pointerXY(ev);
        if (!dragState && (Math.abs(p.x - x) > 10 || Math.abs(p.y - y) > 10)) movedEarly = true;
        if (dragState) {
          ev.preventDefault();
          updateTaskDrag(p.x, p.y);
        }
      };

      const onUp = async () => {
        clearTimeout(dragHoldTimer);
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
        document.removeEventListener('touchmove', onMove);
        document.removeEventListener('touchend', onUp);
        if (dragState) await endTaskDrag();
      };

      dragHoldTimer = setTimeout(() => {
        if (!movedEarly) startTaskDrag(li, x, y);
      }, DRAG_HOLD_MS);

      document.addEventListener('mousemove', onMove);
      document.addEventListener('mouseup', onUp);
      document.addEventListener('touchmove', onMove, { passive: false });
      document.addEventListener('touchend', onUp);
    };

    li.addEventListener('mousedown', onDown);
    li.addEventListener('touchstart', onDown, { passive: true });
  });
}

function bindTaskInteractions() {
  bindTaskDrag();
}
bindTaskInteractions();

// ===== Bottom sheet เพิ่มงาน (เปิดจากปุ่ม + ลอย) =====
const taskSheet = document.getElementById('task-sheet');

function openTaskSheet() {
  taskSheet.classList.remove('hidden');
  updatePickLabels();
  const titleEl = document.getElementById('new-task-title');
  const submitBtn = document.getElementById('add-task-submit');
  if (submitBtn) submitBtn.disabled = !(titleEl?.value || '').trim();
  unsavedGuard.beginContext('task-add', [titleEl]);
  setTimeout(() => titleEl?.focus(), 50);
  if (window.lucide) lucide.createIcons();
}
function closeTaskSheet(force) {
  const doClose = () => {
    unsavedGuard.endContext('task-add');
    taskSheet.classList.add('hidden');
    const tp = document.getElementById('time-panel');
    tp.classList.add('hidden');
    tp.classList.remove('flex');
  };
  if (force) { doClose(); return; }
  unsavedGuard.confirmLeave(doClose);
}

function resetAddTaskForm() {
  const titleEl = document.getElementById('new-task-title');
  if (titleEl) titleEl.value = '';
  unsavedGuard.endContext('task-add');
  if (taskSheet && !taskSheet.classList.contains('hidden') && titleEl) {
    unsavedGuard.beginContext('task-add', [titleEl]);
  }
  const submitBtn = document.getElementById('add-task-submit');
  if (submitBtn) submitBtn.disabled = true;
  document.getElementById('new-task-priority').value = '0';
  if (timeInput) timeInput.value = '';
  const tp = document.getElementById('time-panel');
  if (tp) { tp.classList.add('hidden'); tp.classList.remove('flex'); }
  document.querySelectorAll('.prio-chip').forEach(c => c.classList.remove('border-[#E2E800]', 'bg-[#E2E800]/10'));
  updatePickLabels();
}

const TASK_GROUP_LABELS = {
  overdue: 'เกินกำหนด',
  today: 'วันนี้',
  upcoming: 'กำลังจะถึง',
  no_date: 'ยังไม่กำหนดวัน',
};

function updateTaskGroupCount(groupEl) {
  const n = groupEl.querySelectorAll('.task-group-list > .task-item').length;
  const cnt = groupEl.querySelector('.task-group-count');
  if (cnt) cnt.textContent = String(n);
  groupEl.classList.toggle('hidden', n === 0);
}

function appendNewTaskToDom(html, groupKey) {
  if (!html) return;
  document.getElementById('tasks-empty-state')?.remove();
  let groupEl = document.querySelector('.task-group[data-group="' + groupKey + '"]');
  if (!groupEl) return;
  const list = groupEl.querySelector('.task-group-list');
  if (!list) return;
  list.insertAdjacentHTML('beforeend', html);
  groupEl.classList.remove('hidden');
  updateTaskGroupCount(groupEl);
  bindTaskInteractions();
  if (window.lucide) lucide.createIcons();
}

function bumpTaskCalendar(due, done) {
  if (!due || !taskDates) return;
  if (!taskDates[due]) taskDates[due] = { pending: 0, done: 0 };
  taskDates[due][done ? 'done' : 'pending']++;
  renderCalendar();
}

let addTaskSubmitting = false;

async function submitNewTask() {
  if (addTaskSubmitting) return;
  const titleEl = document.getElementById('new-task-title');
  const title = (titleEl?.value || '').trim();
  const due = dateInput.value;
  if (!title) return;
  addTaskSubmitting = true;
  const submitBtn = document.getElementById('add-task-submit');
  if (submitBtn) submitBtn.disabled = true;
  const r = await taskApi({
    ajax: 'task_add', title: title, due_date: due,
    due_time: timeInput.value || '',
    priority: document.getElementById('new-task-priority').value
  });
  addTaskSubmitting = false;
  if (r.success) {
    if (r.snapshot) {
      pushTaskHistory({
        type: 'add',
        snapshot: r.snapshot,
        group: r.group || 'today',
        undoLabel: 'ยกเลิกการเพิ่มงาน',
        redoLabel: 'เพิ่มงานอีกครั้ง',
        refreshOnUndo: true, refreshOnRedo: true,
      });
    }
    appendNewTaskToDom(r.html, r.group || 'today');
    bumpTaskCalendar(due, false);
    resetAddTaskForm();
    showTaskUndoToast('เพิ่มงาน "' + title + '" แล้ว');
    setTimeout(() => document.getElementById('new-task-title')?.focus(), 30);
  } else {
    alert(r.message || 'เพิ่มงานไม่สำเร็จ');
    if (submitBtn) submitBtn.disabled = !(titleEl?.value || '').trim();
  }
}

document.getElementById('fab-add-task').addEventListener('click', openTaskSheet);
document.getElementById('task-sheet-backdrop').addEventListener('click', closeTaskSheet);

// ===== หน้าเกร็ดความรู้ (Tips) =====
const tipsOverlay = document.getElementById('tips-overlay');

function openTips(tipId) {
  // โชว์เฉพาะบทความที่ถูกเรียก (เผื่ออนาคตมีหลายเรื่อง)
  tipsOverlay.querySelectorAll('article').forEach(a => {
    a.classList.toggle('hidden', a.dataset.tipId !== tipId);
  });
  tipsOverlay.classList.remove('hidden');
  if (window.lucide) lucide.createIcons();
}
function closeTips() { tipsOverlay.classList.add('hidden'); }

document.querySelectorAll('.tip-link').forEach(btn => {
  btn.addEventListener('click', () => openTips(btn.dataset.tip));
});
document.getElementById('tips-close').addEventListener('click', closeTips);
document.getElementById('tips-backdrop').addEventListener('click', closeTips);

// ===== Product infowindow =====
const ownerDetails = <?php echo json_encode($owner_details_map, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
const leadDetails = <?php echo json_encode((object)$lead_details_map, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
const leadsByOwnerCode = <?php echo json_encode($leads_by_owner_code, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
const USER_DRIVE_ID = <?php echo json_encode($user['google_drive_id'] ?? ''); ?>;
let currentProductId = null;
const productDetail = document.getElementById('product-detail');
const productEdit = document.getElementById('product-edit');
const pdShortMonths = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];

function pdFmtDate(iso) {
  if (!iso) return '-';
  const p = String(iso).split('-');
  if (p.length < 3) return iso;
  const day = String(parseInt(p[2], 10)).padStart(2, '0');
  const mon = String(parseInt(p[1], 10)).padStart(2, '0');
  return day + '/' + mon + '/' + p[0];
}

function isoToDdMmYyyy(iso) {
  if (!iso || !/^\d{4}-\d{2}-\d{2}$/.test(String(iso))) return '';
  const [y, m, d] = String(iso).split('-');
  return d + '/' + m + '/' + y;
}

function dateDisplayDigits(txt) {
  return String(txt || '').replace(/\D/g, '').slice(0, 8);
}

function formatDdMmYyyyMask(digits) {
  const d = dateDisplayDigits(digits);
  if (!d) return '';
  if (d.length <= 2) return d;
  if (d.length <= 4) return d.slice(0, 2) + '/' + d.slice(2);
  return d.slice(0, 2) + '/' + d.slice(2, 4) + '/' + d.slice(4);
}

function caretPosAfterDateDigits(formatted, digitCount) {
  if (digitCount <= 0) return 0;
  let seen = 0;
  for (let i = 0; i < formatted.length; i++) {
    if (/\d/.test(formatted[i])) {
      seen++;
      if (seen >= digitCount) return i + 1;
    }
  }
  return formatted.length;
}

function applyAppDateMaskToDisplay(display, digits, caretDigitPos) {
  const d = dateDisplayDigits(digits);
  const formatted = formatDdMmYyyyMask(d);
  display.value = formatted;
  display.dataset.prevDigits = d;
  const pos = caretDigitPos == null
    ? formatted.length
    : caretPosAfterDateDigits(formatted, caretDigitPos);
  try { display.setSelectionRange(pos, pos); } catch (_) {}
}

function onAppDateDisplayInput(display) {
  const wrap = display.closest('.app-date-wrap');
  const selStart = display.selectionStart ?? display.value.length;
  const digitsBefore = dateDisplayDigits(display.value.slice(0, selStart));
  const allDigits = dateDisplayDigits(display.value);
  applyAppDateMaskToDisplay(display, allDigits, digitsBefore.length);
  syncAppDateFromDisplay(wrap);
}

function ddMmYyyyToIso(txt) {
  const m = String(txt).trim().match(/^(\d{2})\/(\d{2})\/(\d{2}|\d{4})$/);
  if (!m) return null;
  const day = parseInt(m[1], 10);
  const mon = parseInt(m[2], 10);
  let yr = parseInt(m[3], 10);
  if (yr < 100) yr += 2000;
  if (mon < 1 || mon > 12 || day < 1 || day > 31 || yr < 1900 || yr > 2100) return null;
  return yr + '-' + String(mon).padStart(2, '0') + '-' + String(day).padStart(2, '0');
}

function syncAppDateFromDisplay(wrap) {
  const isoEl = wrap?.querySelector('.app-date-iso-native');
  const display = wrap?.querySelector('.app-date-display');
  if (!isoEl || !display) return;
  const iso = ddMmYyyyToIso(display.value);
  if (iso) isoEl.value = iso;
}

function syncAllAppDateInputs() {
  document.querySelectorAll('.app-date-wrap').forEach(syncAppDateFromDisplay);
}

function setAppDateValue(el, iso) {
  if (!el) return;
  if (el.dataset.appDateMounted === '1') {
    el.value = iso || '';
    const display = el.closest('.app-date-wrap')?.querySelector('.app-date-display');
    if (display) {
      if (iso) applyAppDateMaskToDisplay(display, isoToDdMmYyyy(iso).replace(/\D/g, ''));
      else applyAppDateMaskToDisplay(display, '');
    }
  } else {
    el.value = iso || '';
  }
}

function mountAppDateInput(el) {
  if (!el || el.dataset.appDateMounted === '1' || el.type !== 'date') return;
  el.dataset.appDateMounted = '1';

  const wrap = document.createElement('div');
  wrap.className = 'app-date-wrap';
  if (el.classList.contains('w-full')) wrap.classList.add('w-full');

  const display = document.createElement('input');
  display.type = 'text';
  display.className = el.className.replace(/\bw-full\b/g, '').trim() + ' app-date-display w-full';
  if (!display.className.includes('edit-inp')) display.classList.add('edit-inp');
  display.placeholder = 'dd/mm/yyyy';
  display.setAttribute('inputmode', 'numeric');
  display.setAttribute('autocomplete', 'off');
  display.setAttribute('maxlength', '10');
  display.setAttribute('spellcheck', 'false');
  const label = el.id ? document.querySelector('label[for="' + el.id + '"]') : null;
  if (label?.textContent) display.setAttribute('aria-label', label.textContent.trim());

  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'app-date-cal-btn';
  btn.setAttribute('aria-label', 'เปิดปฏิทิน');
  btn.innerHTML = '<i data-lucide="calendar" class="w-4 h-4"></i>';

  el.classList.add('app-date-iso-native');
  el.tabIndex = -1;

  function syncFromIso() {
    applyAppDateMaskToDisplay(display, isoToDdMmYyyy(el.value).replace(/\D/g, ''));
  }

  display.addEventListener('input', () => onAppDateDisplayInput(display));
  display.addEventListener('paste', (e) => {
    e.preventDefault();
    const paste = (e.clipboardData || window.clipboardData)?.getData('text') || '';
    const selStart = display.selectionStart ?? 0;
    const selEnd = display.selectionEnd ?? selStart;
    const before = display.value.slice(0, selStart);
    const after = display.value.slice(selEnd);
    const merged = before + paste + after;
    const caretDigits = dateDisplayDigits(before + paste).length;
    applyAppDateMaskToDisplay(display, dateDisplayDigits(merged), caretDigits);
    syncAppDateFromDisplay(wrap);
  });
  display.addEventListener('blur', () => {
    onAppDateDisplayInput(display);
    syncAppDateFromDisplay(wrap);
  });
  display.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      syncAppDateFromDisplay(wrap);
      display.blur();
    }
  });
  btn.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    const current = el.value || '';
    openAppDatePicker(current || TODAY_STR, (iso) => {
      el.value = iso || '';
      syncFromIso();
      syncAppDateFromDisplay(wrap);
      el.dispatchEvent(new Event('change', { bubbles: true }));
    }, { wrap });
  });
  el.addEventListener('change', syncFromIso);

  const parent = el.parentNode;
  parent.insertBefore(wrap, el);
  wrap.appendChild(display);
  wrap.appendChild(btn);
  wrap.appendChild(el);
  syncFromIso();
  if (window.lucide) lucide.createIcons({ nodes: [btn] });
}

function mountAllAppDateInputs(root) {
  (root || document).querySelectorAll('input[type="date"]:not([data-app-date-skip])').forEach(mountAppDateInput);
}
function pdSet(id, val) {
  const el = document.getElementById(id);
  if (el) el.textContent = (val !== '' && val != null) ? val : '-';
}
function pdSetHtml(id, html) {
  const el = document.getElementById(id);
  if (!el) return;
  if (html !== '' && html != null) el.innerHTML = html;
  else el.textContent = '-';
}
function pdVal(val) {
  const text = (val !== '' && val != null) ? String(val).trim() : '';
  return text;
}
function pdSetGridPair(labelId, valueId, val) {
  const label = document.getElementById(labelId);
  const value = document.getElementById(valueId);
  if (!label || !value) return;
  const text = pdVal(val);
  const show = text !== '';
  label.classList.toggle('hidden', !show);
  value.classList.toggle('hidden', !show);
  value.textContent = show ? text : '';
}
function pdSetCaseBlock(wrapId, textId, val, opts) {
  const wrap = document.getElementById(wrapId);
  const el = document.getElementById(textId);
  if (!wrap || !el) return;
  const text = pdVal(val);
  const alwaysShow = !!(opts && opts.alwaysShow);
  const emptyLabel = (opts && opts.emptyLabel) || 'ยังไม่มีข้อมูล';
  if (text) {
    wrap.classList.remove('hidden');
    el.textContent = text;
    el.classList.remove('text-[var(--faint)]', 'italic');
  } else if (alwaysShow) {
    wrap.classList.remove('hidden');
    el.textContent = emptyLabel;
    el.classList.add('text-[var(--faint)]', 'italic');
  } else {
    wrap.classList.add('hidden');
    el.textContent = '';
    el.classList.remove('text-[var(--faint)]', 'italic');
  }
}
function pdShowLink(id, url, hideId) {
  const a = document.getElementById(id);
  const none = hideId ? document.getElementById(hideId) : null;
  if (url) {
    a.href = url;
    a.classList.remove('hidden');
    if (none) none.classList.add('hidden');
  } else {
    a.classList.add('hidden');
    if (none) none.classList.remove('hidden');
  }
}
function pdEsc(s) {
  return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// ===== Custom select (แทน native dropdown) =====
let appSelectOpenWrap = null;

function closeAppSelectWrap(wrap) {
  if (!wrap) return;
  wrap.classList.remove('app-select-wrap--open');
  const menu = wrap._appSelectMenu;
  const trigger = wrap._appSelectTrigger;
  if (menu) menu.classList.add('hidden');
  if (trigger) trigger.setAttribute('aria-expanded', 'false');
  if (appSelectOpenWrap === wrap) appSelectOpenWrap = null;
}

function closeAllAppSelects() {
  document.querySelectorAll('.app-select-wrap--open').forEach(closeAppSelectWrap);
}

function positionAppSelectMenu(wrap) {
  const trigger = wrap._appSelectTrigger;
  const menu = wrap._appSelectMenu;
  if (!trigger || !menu) return;
  menu.style.top = 'calc(100% + 4px)';
  menu.style.bottom = 'auto';
  menu.style.left = '';
  menu.style.width = '';
  const r = trigger.getBoundingClientRect();
  const spaceBelow = window.innerHeight - r.bottom - 16;
  const spaceAbove = r.top - 16;
  menu.style.maxHeight = Math.min(256, Math.max(spaceBelow, spaceAbove, 120)) + 'px';
  if (spaceBelow < 140 && spaceAbove > spaceBelow) {
    menu.style.top = 'auto';
    menu.style.bottom = 'calc(100% + 4px)';
  }
}

function mountAppSelect(select) {
  if (!select || select.dataset.appSelectMounted === '1') return;
  select.dataset.appSelectMounted = '1';
  select.classList.add('app-select-native');

  const wrap = document.createElement('div');
  wrap.className = 'app-select-wrap';
  if (select.classList.contains('w-full')) wrap.classList.add('w-full');
  if (select.classList.contains('app-select--compact')) wrap.classList.add('app-select-wrap--compact');
  if (select.classList.contains('app-select--sort')) wrap.classList.add('app-select-wrap--sort');
  if (select.classList.contains('app-select--surface')) wrap.classList.add('app-select-wrap--surface');
  if (select.classList.contains('edit-inp')) wrap.classList.add('app-select-wrap--form');
  if (select.disabled) wrap.classList.add('app-select-wrap--disabled');

  const parent = select.parentNode;
  parent.insertBefore(wrap, select);
  wrap.appendChild(select);

  const trigger = document.createElement('button');
  trigger.type = 'button';
  trigger.className = 'app-select-trigger';
  trigger.setAttribute('aria-haspopup', 'listbox');
  trigger.setAttribute('aria-expanded', 'false');
  const ariaLabel = select.getAttribute('aria-label');
  if (ariaLabel) trigger.setAttribute('aria-label', ariaLabel);

  const menu = document.createElement('div');
  menu.className = 'app-select-menu hidden';
  menu.setAttribute('role', 'listbox');
  wrap._appSelectTrigger = trigger;
  wrap._appSelectMenu = menu;

  function syncTrigger() {
    const opt = select.options[select.selectedIndex];
    trigger.innerHTML = '<span class="truncate">' + pdEsc(opt?.textContent || '') + '</span>'
      + '<i data-lucide="chevron-down" class="w-4 h-4 shrink-0 text-[var(--faint)]"></i>';
    if (window.lucide) lucide.createIcons({ nodes: [trigger] });
  }

  function buildMenu() {
    menu.innerHTML = '';
    [...select.options].forEach((opt, i) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'app-select-opt' + (opt.selected ? ' app-select-opt--selected' : '');
      btn.setAttribute('role', 'option');
      btn.setAttribute('aria-selected', opt.selected ? 'true' : 'false');
      btn.textContent = opt.textContent || '';
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        if (opt.disabled) return;
        select.selectedIndex = i;
        select.dispatchEvent(new Event('change', { bubbles: true }));
        closeAppSelectWrap(wrap);
        syncTrigger();
        buildMenu();
      });
      menu.appendChild(btn);
    });
  }

  menu.addEventListener('click', (e) => e.stopPropagation());
  trigger.addEventListener('click', (e) => {
    e.stopPropagation();
    if (select.disabled) return;
    if (wrap.classList.contains('app-select-wrap--open')) {
      closeAppSelectWrap(wrap);
      return;
    }
    closeAllAppSelects();
    buildMenu();
    syncTrigger();
    wrap.classList.add('app-select-wrap--open');
    menu.classList.remove('hidden');
    trigger.setAttribute('aria-expanded', 'true');
    positionAppSelectMenu(wrap);
    appSelectOpenWrap = wrap;
  });

  wrap.appendChild(trigger);
  wrap.appendChild(menu);
  syncTrigger();
  buildMenu();

  select.addEventListener('change', () => { syncTrigger(); buildMenu(); });
  const mo = new MutationObserver(() => { buildMenu(); syncTrigger(); });
  mo.observe(select, { childList: true, subtree: true, attributes: true, attributeFilter: ['selected', 'disabled'] });
}

function mountAllAppSelects(root) {
  (root || document).querySelectorAll('select:not([data-app-select-skip])').forEach(sel => {
    if (sel.dataset.appSelectMounted !== '1') mountAppSelect(sel);
  });
}

document.addEventListener('click', (e) => {
  if (e.target.closest('.app-select-wrap--open')) return;
  closeAllAppSelects();
});
window.addEventListener('scroll', closeAllAppSelects, true);
window.addEventListener('resize', closeAllAppSelects);

function pdSetLinkOrText(wrapId, text, href, icon) {
  const wrap = document.getElementById(wrapId);
  if (!wrap) return;
  if (text && href) {
    const isTel = href.startsWith('tel:');
    const extra = isTel ? '' : ' target="_blank" rel="noopener"';
    wrap.innerHTML = '<a href="' + pdEsc(href) + '"' + extra + ' class="font-medium text-[var(--accent-text)] inline-flex items-center gap-1 active:opacity-70"><i data-lucide="' + icon + '" class="w-3.5 h-3.5"></i>' + pdEsc(text) + '</a>';
  } else {
    wrap.textContent = (text !== '' && text != null) ? text : '-';
  }
}
function pdRenderContactHistory(logs) {
  const ul = document.getElementById('pd-contact-history');
  const empty = document.getElementById('pd-contact-empty');
  if (!ul) return;
  ul.innerHTML = '';
  if (!logs.length) {
    empty.classList.remove('hidden');
    return;
  }
  empty.classList.add('hidden');
  logs.forEach(item => {
    const li = document.createElement('li');
    li.className = 'bg-[var(--surface)] border border-[var(--border)] rounded-xl px-3 py-2';
    li.innerHTML = '<p class="text-[10px] text-[var(--faint)] flex items-center gap-1 mb-0.5"><i data-lucide="calendar" class="w-3 h-3"></i>' + pdFmtDate(item.date) + '</p><p class="leading-snug">' + pdEsc(item.note || '-') + '</p>';
    ul.appendChild(li);
  });
}
function pdRenderPriceHistory(logs) {
  const ul = document.getElementById('pd-price-history');
  const empty = document.getElementById('pd-price-empty');
  if (!ul) return;
  ul.innerHTML = '';
  if (!logs.length) {
    empty.classList.remove('hidden');
    return;
  }
  empty.classList.add('hidden');
  logs.forEach(item => {
    const li = document.createElement('li');
    li.className = 'bg-[var(--surface)] border border-[var(--border)] rounded-xl px-3 py-2';
    let html = '<p class="text-[10px] text-[var(--faint)] flex items-center gap-1 mb-0.5"><i data-lucide="calendar" class="w-3 h-3"></i>' + pdFmtDate(item.date) + ' · <i data-lucide="user" class="w-3 h-3"></i>' + pdEsc(item.changed_by || '-') + '</p>';
    html += '<p class="font-bold">' + pdEsc(item.old_price ? item.old_price + ' → ' : '') + pdEsc(item.new_price || '-') + '</p>';
    if (item.note) html += '<p class="text-[var(--muted)] mt-0.5 leading-snug">' + pdEsc(item.note) + '</p>';
    li.innerHTML = html;
    ul.appendChild(li);
  });
}

function driveFolderUrl(d) {
  const link = d.photos_link || '';
  const m = link.match(/\/folders\/([a-zA-Z0-9_-]+)/);
  if (m) return 'https://drive.google.com/drive/folders/' + m[1];
  if (USER_DRIVE_ID) return 'https://drive.google.com/drive/folders/' + USER_DRIVE_ID;
  return null;
}
function driveUploadHint(d) {
  const name = (d.name_en || d.name_th || 'อัลบั้ม').trim();
  if (!USER_DRIVE_ID && !d.photos_link) return 'ยังไม่ได้ผูกโฟลเดอร์ Drive — ตั้งค่าได้ที่หน้าลงทะเบียน';
  return 'แนะนำสร้างโฟลเดอร์ชื่อ «' + (d.code || '') + ' - ' + name + '» แล้ววางลิงก์โฟลเดอร์ด้านล่าง';
}
function setVal(id, val) {
  const el = document.getElementById(id);
  if (el) el.value = (val != null && val !== undefined) ? val : '';
}
function fillIfEmpty(id, val) {
  const el = document.getElementById(id);
  if (!el || val == null || String(val).trim() === '') return;
  if (String(el.value || '').trim() !== '') return;
  el.value = val;
}
async function runOwnerMagicFill() {
  const raw = document.getElementById('owner-magic-paste')?.value?.trim() || '';
  const btn = document.getElementById('owner-magic-fill');
  const status = document.getElementById('owner-magic-status');
  if (!raw) {
    if (status) status.textContent = 'วางข้อความจากเจ้าของทรัพย์ก่อน';
    return;
  }
  if (btn) btn.disabled = true;
  if (status) status.textContent = 'กำลังให้ AI อ่านข้อมูล…';
  const fd = new FormData();
  fd.append('ajax', 'owner_ai_fill');
  fd.append('raw_text', raw);
  try {
    const res = await fetch(window.location.pathname + window.location.search, { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.success) throw new Error(data.message || 'อ่านข้อมูลไม่ได้');
    const f = data.fields || {};
    fillIfEmpty('edit-owner-name', f.owner_name);
    fillIfEmpty('edit-phone', f.phone);
    fillIfEmpty('edit-line-id', f.line_id);
    fillIfEmpty('edit-name-en', f.name_en);
    fillIfEmpty('edit-name-th', f.name_th);
    fillIfEmpty('edit-type', f.property_type);
    fillIfEmpty('edit-zone', f.zone);
    fillIfEmpty('edit-soi', f.soi);
    fillIfEmpty('edit-unit', f.unit_no);
    fillIfEmpty('edit-floor', f.floor);
    fillIfEmpty('edit-direction', f.direction);
    fillIfEmpty('edit-map', f.map_url);
    fillIfEmpty('edit-price', f.price);
    fillIfEmpty('edit-rent', f.rent);
    fillIfEmpty('edit-owner-price', f.owner_price);
    if (f.sales_status) fillIfEmpty('edit-sales-status', f.sales_status);
    if (f.owner_urgency) fillIfEmpty('edit-urgency', f.owner_urgency);
    fillIfEmpty('edit-transfer', f.transfer_fee);
    fillIfEmpty('edit-photos-link', f.photos_link);
    fillIfEmpty('edit-contact-summary', f.contact_summary);
    mountAllFmtNums(document.getElementById('product-edit-form'));
    const warn = (data.warnings || []).filter(Boolean);
    if (status) {
      status.textContent = warn.length
        ? '✓ เติมช่องว่างแล้ว · ยังขาด: ' + warn.join(', ')
        : '✓ เติมช่องว่างแล้ว — ตรวจสอบก่อนกดบันทึก';
    }
    lucide.createIcons();
  } catch (e) {
    if (status) status.textContent = e.message || 'อ่านข้อมูลไม่ได้';
  } finally {
    if (btn) btn.disabled = false;
  }
}
async function runLeadMagicFill() {
  const raw = document.getElementById('lead-magic-paste')?.value?.trim() || '';
  const btn = document.getElementById('lead-magic-fill');
  const status = document.getElementById('lead-magic-status');
  if (!raw) {
    if (status) status.textContent = 'วางข้อความจากลูกค้าก่อน';
    return;
  }
  if (btn) btn.disabled = true;
  if (status) status.textContent = 'กำลังให้ AI อ่านข้อมูล…';
  const fd = new FormData();
  fd.append('ajax', 'lead_ai_fill');
  fd.append('raw_text', raw);
  try {
    const res = await fetch(window.location.pathname + window.location.search, { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.success) throw new Error(data.message || 'อ่านข้อมูลไม่ได้');
    const f = data.fields || {};
    fillIfEmpty('ld-case-background', f.background);
    fillIfEmpty('ld-case-pain', f.pain_point);
    fillIfEmpty('ld-case-requirement', f.requirement);
    fillIfEmpty('ld-case-financial', f.financials);
    fillIfEmpty('ld-case-timeline', f.timeline);
    fillIfEmpty('ld-case-budget', f.budget);
    if (f.potential) fillIfEmpty('ld-case-potential', f.potential);
    fillIfEmpty('ld-case-owner-code', f.owner_code);
    mountAllFmtNums(document.getElementById('lead-case-form'));
    if (status) status.textContent = '✓ เติมช่องว่างแล้ว — ตรวจสอบก่อนบันทึก';
    lucide.createIcons();
  } catch (e) {
    if (status) status.textContent = e.message || 'อ่านข้อมูลไม่ได้';
  } finally {
    if (btn) btn.disabled = false;
  }
}
document.getElementById('owner-magic-fill')?.addEventListener('click', runOwnerMagicFill);
document.getElementById('lead-magic-fill')?.addEventListener('click', runLeadMagicFill);
function openProductEdit() {
  if (!currentProductId) return;
  const d = ownerDetails[currentProductId];
  if (!d) return;
  setVal('edit-owner-id', currentProductId);
  document.getElementById('edit-code-label').textContent = d.code || '';
  setVal('edit-owner-name', d.owner_name);
  setVal('edit-phone', d.phone_raw || d.phone || '');
  setVal('edit-line-id', d.line_id);
  setVal('edit-name-en', d.name_en);
  setVal('edit-name-th', d.name_th);
  setVal('edit-type', d.property_type);
  setVal('edit-zone', d.zone);
  setVal('edit-soi', d.soi);
  setVal('edit-unit', d.unit_no);
  setVal('edit-floor', d.floor);
  setVal('edit-direction', d.direction);
  setVal('edit-map', d.map_url);
  setVal('edit-price', priceDigitsForInput(d.price_raw || ''));
  setVal('edit-rent', priceDigitsForInput(d.rent_raw || ''));
  setVal('edit-owner-price', priceDigitsForInput(d.owner_price_raw || ''));
  setVal('edit-sales-status', d.sales_status || 'Sale');
  setVal('edit-urgency', d.urgency || '');
  setVal('edit-transfer', d.transfer_fee || '');
  setVal('edit-last-contact', d.last_contact || '');
  setVal('edit-contact-summary', d.contact_summary);
  setVal('edit-price-consult', d.price_consult);
  setVal('edit-listing-source', d.listing_source_raw || '');
  setVal('edit-marketing-status', d.marketing_status || 'ลงการตลาดแล้ว');
  setVal('edit-incomplete', d.incomplete);
  const deedVal = d.has_deed_val;
  setVal('edit-has-deed', deedVal === null || deedVal === '' ? '' : String(deedVal));
  setVal('edit-cover-url', d.cover_image_url || '');
  setVal('edit-photos-link', d.photos_link || '');
  setVal('owner-magic-paste', '');
  const magicSt = document.getElementById('owner-magic-status');
  if (magicSt) magicSt.textContent = '';
  const driveA = document.getElementById('edit-drive-open');
  const driveHint = document.getElementById('edit-drive-hint');
  const folderUrl = driveFolderUrl(d);
  if (folderUrl) {
    driveA.href = folderUrl;
    driveA.classList.remove('opacity-50', 'pointer-events-none');
  } else {
    driveA.href = '#';
    driveA.classList.add('opacity-50', 'pointer-events-none');
  }
  driveHint.textContent = driveUploadHint(d);
  productEdit.classList.remove('hidden');
  mountAllFmtNums(document.getElementById('product-edit-form'));
  initProductEditUnsavedGuard();
  lucide.createIcons();
}
function closeProductEdit(force) {
  const doClose = () => {
    unsavedGuard.endContext('product-edit');
    productEdit.classList.add('hidden');
  };
  if (force) { doClose(); return; }
  unsavedGuard.confirmLeave(doClose);
}

function openProductDetail(id) {
  const d = ownerDetails[id];
  if (!d) return;
  currentProductId = id;

  pdSet('pd-title', d.name_en || d.name_th || 'รายละเอียดทรัพย์');
  pdSet('pd-code', d.code);
  pdSet('pd-code2', d.code);
  const hdrPrice = document.getElementById('pd-header-price');
  if (hdrPrice) {
    const priceTxt = (d.price && d.price !== '-') ? d.price : '';
    hdrPrice.textContent = priceTxt;
    hdrPrice.classList.toggle('hidden', !priceTxt);
  }

  const urg = document.getElementById('pd-urgency');
  const urgSection = document.getElementById('pd-urgency-section');
  const um = d.urgency_meta || null;
  if (um && d.urgency) {
    urg.classList.remove('hidden');
    urg.innerHTML = '<i data-lucide="' + um.icon + '" class="w-3 h-3"></i> ' + d.urgency;
    urg.className = 'text-[10px] font-bold px-2 py-1 rounded-full shrink-0 inline-flex items-center gap-1 ' + (um.class || d.urgency_class || '');
    if (urgSection) urgSection.classList.remove('hidden');
    const urgBadge = document.getElementById('pd-urgency-badge');
    if (urgBadge) {
      urgBadge.innerHTML = '<i data-lucide="' + um.icon + '" class="w-3.5 h-3.5"></i> ' + d.urgency + ' · ' + um.label;
      urgBadge.className = 'text-xs font-bold px-2.5 py-1.5 rounded-full shrink-0 inline-flex items-center gap-1 ' + (um.class || '');
      pdSet('pd-urgency-timeline', um.timeline || '-');
      pdSet('pd-urgency-desc', um.desc || '-');
    }
  } else {
    urg.classList.add('hidden');
    urg.innerHTML = '';
    if (urgSection) urgSection.classList.add('hidden');
  }

  const coverImg = document.getElementById('pd-cover');
  const coverPh  = document.getElementById('pd-cover-ph');
  if (d.cover_url) {
    coverImg.src = d.cover_url;
    coverImg.classList.remove('hidden');
    coverPh.classList.add('hidden');
    coverImg.onerror = () => { coverImg.classList.add('hidden'); coverPh.classList.remove('hidden'); };
  } else {
    coverImg.classList.add('hidden');
    coverPh.classList.remove('hidden');
  }
  pdShowLink('pd-photos-link', d.photos_link);

  pdSet('pd-owner-name', d.owner_name);
  pdSetLinkOrText('pd-phone-wrap', d.phone, d.phone_tel, 'phone');
  pdSetLinkOrText('pd-line-wrap', d.line_id, d.line_url, 'message-circle');
  renderPdLinkedLeads(d.code);
  pdSet('pd-source', d.listing_source);
  pdSet('pd-listing-date', pdFmtDate(d.listing_date));
  pdSet('pd-marketing-date', pdFmtDate(d.marketing_date));
  const deedEl = document.getElementById('pd-deed');
  if (d.has_deed === 'มี') {
    deedEl.innerHTML = '<i data-lucide="file-check" class="w-3.5 h-3.5"></i> มีโฉนด';
  } else if (d.has_deed === 'ไม่มี') {
    deedEl.innerHTML = '<i data-lucide="file-x" class="w-3.5 h-3.5"></i> ไม่มีโฉนด';
  } else {
    deedEl.textContent = '-';
  }
  pdSet('pd-mkt-status', d.marketing_status);

  pdSet('pd-last-contact', pdFmtDate(d.last_contact));
  pdSet('pd-contact-summary', d.contact_summary);
  pdSet('pd-price-consult', d.price_consult);
  pdRenderContactHistory(d.contact_logs || []);
  pdRenderPriceHistory(d.price_logs || []);

  const incWrap = document.getElementById('pd-incomplete-wrap');
  if (d.marketing_status === 'ข้อมูลยังไม่ครบ' || d.incomplete) {
    incWrap.classList.remove('hidden');
    pdSet('pd-incomplete', d.incomplete || 'ยังไม่ระบุรายละเอียด');
  } else {
    incWrap.classList.add('hidden');
  }

  const soldSec = document.getElementById('pd-sold-section');
  const showSold = d.status_th === 'ขายแล้ว' || d.sold_date || d.sold_by || d.sold_price;
  soldSec.classList.toggle('hidden', !showSold);
  if (showSold) {
    pdSet('pd-sold-date', pdFmtDate(d.sold_date));
    pdSet('pd-sold-by', d.sold_by);
    pdSet('pd-sold-price', d.sold_price);
  }

  pdSet('pd-name-en', d.name_en);
  pdSet('pd-name-th', d.name_th);
  pdSet('pd-type', d.property_type);
  pdSet('pd-zone', d.zone);

  pdSet('pd-soi', d.soi);
  pdSet('pd-unit-no', d.unit_no);
  const unitLabel = document.getElementById('pd-unit-label');
  if (unitLabel) unitLabel.textContent = d.is_condo ? 'เลขที่ห้อง' : 'เลขที่บ้าน';
  const floorLabel = document.getElementById('pd-floor-label');
  const floorVal = document.getElementById('pd-floor');
  if (floorLabel && floorVal) {
    floorLabel.classList.toggle('hidden', !d.is_condo);
    floorVal.classList.toggle('hidden', !d.is_condo);
    if (d.is_condo) pdSet('pd-floor', d.floor);
  }
  const dirLabel = document.getElementById('pd-direction-label');
  if (dirLabel) dirLabel.textContent = d.direction_label || 'ทิศ';
  const dirEl = document.getElementById('pd-direction');
  if (dirEl) {
    if (d.direction) {
      dirEl.innerHTML = '<i data-lucide="compass" class="w-3.5 h-3.5"></i> ' + pdEsc(d.direction);
    } else {
      dirEl.textContent = '-';
    }
  }
  const stIcon = d.status_th === 'ขายแล้ว' ? 'check-circle'
    : (d.status_th === 'ยกเลิก' ? 'ban'
    : (d.status_th === 'ขาย·เช่า' ? 'key'
    : (d.status_th === 'เช่า' ? 'key'
    : (d.status_th === 'ขายพร้อมผู้เช่า' ? 'users' : 'tag'))));
  document.getElementById('pd-status').innerHTML = '<i data-lucide="' + stIcon + '" class="w-3.5 h-3.5"></i> ' + (d.status_th || '-');

  const landParts = [d.area_rai, d.area_ngan, d.area_sqwa].filter(Boolean);
  pdSet('pd-area-land', landParts.length ? landParts.join(' · ') : '-');
  pdSet('pd-area-sqm', d.area_sqm);
  pdSet('pd-bed', d.bed);
  pdSet('pd-bath', d.bath);
  pdSet('pd-maid', d.maid);
  pdSet('pd-parking', d.parking);

  pdSet('pd-price', d.price);
  pdSet('pd-rent', d.rent);
  pdSet('pd-owner-price', d.owner_price);
  pdSet('pd-transfer', d.transfer_fee);
  pdSet('pd-sales-en', d.sales_status);

  pdShowLink('pd-map-link', d.map_url, 'pd-map-none');

  productDetail.classList.remove('hidden');
  lucide.createIcons();
  document.body.style.overflow = 'hidden';
}
function closeProductDetail() {
  productDetail.classList.add('hidden');
  document.body.style.overflow = '';
}
document.getElementById('product-detail-close').addEventListener('click', closeProductDetail);
document.getElementById('product-detail-backdrop').addEventListener('click', closeProductDetail);

// ===== Lead infowindow =====
const leadDetail = document.getElementById('lead-detail');
const LD_PIPELINE_META = <?php
  $ld_pipe_meta = [];
  foreach (array_merge(lead_funnel_statuses(), lead_terminal_statuses()) as $st) {
      $ld_pipe_meta[$st] = lead_status_step_meta($st);
  }
  echo json_encode($ld_pipe_meta, JSON_UNESCAPED_UNICODE);
?>;

const LEAD_FUNNEL_STEPS = <?php echo json_encode(lead_funnel_statuses(), JSON_UNESCAPED_UNICODE); ?>;
const LEAD_TERMINAL_STEPS = <?php echo json_encode(lead_terminal_statuses(), JSON_UNESCAPED_UNICODE); ?>;
const LD_AUX_TAG_META = <?php
  $aux_meta_js = [];
  foreach (lead_aux_tags() as $ak) {
      $aux_meta_js[$ak] = lead_aux_tag_meta($ak);
  }
  echo json_encode($aux_meta_js, JSON_UNESCAPED_UNICODE);
?>;
let currentLeadId = null;
let ldGroupPanelOpen = false;

function leadGroupKey(d) {
  const gid = parseInt(d?.customer_group_id, 10);
  if (gid > 0) return gid;
  return parseInt(d?.id, 10) || 0;
}

function leadAuxTagHtml(meta, compact) {
  if (!meta) return '';
  const cls = 'ld-aux-tag' + (compact ? ' ld-aux-tag--compact' : '');
  return '<span class="' + cls + '" title="' + pdEsc(meta.label) + '">'
    + '<i data-lucide="' + pdEsc(meta.icon) + '" class="w-3 h-3" aria-hidden="true"></i>'
    + '<span>' + pdEsc(meta.short) + '</span></span>';
}

function leadNameHtml(d, compact) {
  const name = d?.name || d?.code || '';
  const tag = leadAuxTagHtml(d?.aux_tag_meta, compact);
  if (!tag) return pdEsc(name);
  return tag + ' <span class="ld-name-text">' + pdEsc(name) + '</span>';
}

function setLdAuxTagForm(tag) {
  const val = tag || '';
  document.querySelectorAll('#lead-case-form input[name="lead_aux_tag"]').forEach(inp => {
    inp.checked = inp.value === val;
  });
  updateLdAgentFieldsVisibility();
}

function getLdAuxTagSelected() {
  const checked = document.querySelector('#lead-case-form input[name="lead_aux_tag"]:checked');
  return checked?.value || '';
}

function ldAgentDisplayVal(raw) {
  const t = ldCaseVal(raw);
  if (!t || t === '-') return 'ยังไม่ทราบ (-)';
  return t;
}

function ldAgentPhoneInputNormalize(el) {
  if (!el) return;
  let v = String(el.value || '').trim();
  if (v === '' || v === '-' || v === '—' || v === '–') {
    el.value = v === '' ? '' : '-';
    return;
  }
  el.value = v.replace(/\D/g, '').slice(0, 4);
}

function ldAgentFieldsValid() {
  const name = document.getElementById('ld-agent-client-name')?.value?.trim() || '';
  const phoneRaw = document.getElementById('ld-agent-phone-last4')?.value?.trim() || '';
  if (!name) return { ok: false, msg: 'เคส Agent กรุณาระบุชื่อลูกค้า (หรือ - ถ้ายังไม่ทราบ)' };
  if (phoneRaw === '' ) return { ok: false, msg: 'เคส Agent กรุณาระบุเบอร์ 4 ตัวท้าย (หรือ - ถ้ายังไม่ทราบ)' };
  if (phoneRaw === '-' || phoneRaw === '—' || phoneRaw === '–') return { ok: true };
  const phone4 = phoneRaw.replace(/\D/g, '');
  if (phone4.length !== 4) return { ok: false, msg: 'เคส Agent กรุณาระบุเบอร์ 4 ตัวท้าย (หรือ - ถ้ายังไม่ทราบ)' };
  return { ok: true };
}

function updateLdAgentFieldsVisibility() {
  const isAgent = getLdAuxTagSelected() === 'agent';
  const wrap = document.getElementById('ld-agent-fields');
  const nameEl = document.getElementById('ld-agent-client-name');
  const phoneEl = document.getElementById('ld-agent-phone-last4');
  if (wrap) wrap.classList.toggle('hidden', !isAgent);
  if (nameEl) nameEl.required = isAgent;
  if (phoneEl) phoneEl.required = isAgent;
}

function leadGroupSiblings(d) {
  if (!d) return [];
  const gk = leadGroupKey(d);
  return Object.values(leadDetails)
    .filter(x => leadGroupKey(x) === gk)
    .sort((a, b) => {
      const da = a.inbound_date || '';
      const db = b.inbound_date || '';
      if (da !== db) return da < db ? -1 : 1;
      return (a.id || 0) - (b.id || 0);
    });
}

function leadPipelineStatusLine(d) {
  const sm = d.status_meta || {};
  const latest = d.stage_outcome_latest || {};
  const steps = d.pipeline || [];
  let furthest = '';
  for (let i = steps.length - 1; i >= 0; i--) {
    if (latest[steps[i]]) { furthest = steps[i]; break; }
  }
  let detail = sm.label || d.status || '—';
  if (furthest && latest[furthest]) {
    const om = plMatrixOutcomeMeta(latest[furthest], furthest);
    detail = (LD_PIPELINE_META[furthest]?.label || furthest) + ' · ' + om.label;
  }
  const note = leadLatestHistoryNote(d) || pdVal(d.current_update) || pdVal(d.next_plan);
  return { icon: sm.icon || 'circle', detail, note, statusClass: d.status_class || '' };
}

function renderLeadGroupList(current, siblings) {
  const list = document.getElementById('ld-group-list');
  if (!list) return;
  list.innerHTML = siblings.map(s => {
    const isCurrent = s.id === current.id;
    const dateTxt = pdFmtDate(s.inbound_date);
    const st = leadPipelineStatusLine(s);
    const projParts = [];
    if (s.owner_code) projParts.push(s.owner_code);
    if (s.project) projParts.push(s.project);
    const proj = projParts.join(' · ') || '—';
    return '<button type="button" class="ld-group-case text-left rounded-xl border px-3 py-2.5 transition '
      + (isCurrent
        ? 'border-[rgba(226,232,0,0.5)] bg-[rgba(226,232,0,0.08)]'
        : 'border-[var(--border)] bg-[var(--card)] active:opacity-85')
      + '" data-lead-id="' + s.id + '"' + (isCurrent ? ' aria-current="true"' : '') + '>'
      + '<div class="flex items-start justify-between gap-2">'
      + '<div class="min-w-0 flex-1">'
      + '<p class="text-xs font-bold truncate">' + leadNameHtml(s, true)
      + (isCurrent ? ' <span class="text-[10px] font-normal text-[var(--faint)]">(กำลังดู)</span>' : '')
      + '</p>'
      + '<p class="text-[10px] text-[var(--faint)] truncate mt-0.5">' + pdEsc(proj) + '</p>'
      + '</div>'
      + '<span class="text-[10px] font-bold text-[var(--muted)] shrink-0 flex items-center gap-1">'
      + '<i data-lucide="calendar" class="w-3 h-3"></i>' + pdEsc(dateTxt) + '</span>'
      + '</div>'
      + '<p class="text-[10px] font-bold mt-1.5 flex items-center gap-1 ' + pdEsc(st.statusClass) + '">'
      + '<i data-lucide="' + pdEsc(st.icon) + '" class="w-3 h-3"></i>' + pdEsc(st.detail) + '</p>'
      + (st.note ? '<p class="text-[10px] text-[var(--faint)] mt-1 leading-snug">' + pdEsc(st.note) + '</p>' : '')
      + '</button>';
  }).join('');
}

function setLeadGroupPanelOpen(open) {
  const panel = document.getElementById('ld-group-panel');
  const badge = document.getElementById('ld-group-badge');
  const chevron = badge?.querySelector('.ld-group-badge-chevron');
  ldGroupPanelOpen = !!open;
  panel?.classList.toggle('hidden', !ldGroupPanelOpen);
  badge?.setAttribute('aria-expanded', ldGroupPanelOpen ? 'true' : 'false');
  if (chevron) chevron.setAttribute('data-lucide', ldGroupPanelOpen ? 'chevron-up' : 'chevron-down');
}

function toggleLeadGroupPanel() {
  const d = currentLeadId ? leadDetails[currentLeadId] : null;
  if (!d || leadGroupSiblings(d).length <= 1) return;
  setLeadGroupPanelOpen(!ldGroupPanelOpen);
  if (ldGroupPanelOpen) {
    renderLeadGroupList(d, leadGroupSiblings(d));
    if (window.lucide) lucide.createIcons();
  }
}

function updateLeadGroupUi(d) {
  const groupBadge = document.getElementById('ld-group-badge');
  const groupBadgeText = document.getElementById('ld-group-badge-text');
  const siblings = leadGroupSiblings(d);
  const gs = siblings.length || d.group_size || 1;
  if (groupBadge && groupBadgeText) {
    if (gs > 1) {
      groupBadgeText.textContent = 'ผูกกลุ่ม ' + gs + ' เคส';
      groupBadge.classList.remove('hidden');
      groupBadge.classList.add('inline-flex');
    } else {
      groupBadge.classList.add('hidden');
      groupBadge.classList.remove('inline-flex');
      setLeadGroupPanelOpen(false);
    }
  }
  if (ldGroupPanelOpen && gs > 1) {
    renderLeadGroupList(d, siblings);
  } else if (gs <= 1) {
    setLeadGroupPanelOpen(false);
  }
}

const LEAD_MATRIX_STAGES = LEAD_FUNNEL_STEPS; // stage: Call..Win
const LEAD_MATRIX_OUTCOME_OPTS = [
  { v: 'yes', label: 'Yes' },
  { v: 'lose', label: 'Lose' },
  { v: 'reject', label: 'Reject' },
  { v: 'hold', label: 'Hold' },
];

function isMatrixStage(s) {
  return LEAD_MATRIX_STAGES.includes(s);
}

function mapLeadStatusToOutcome(status) {
  if (status === 'Lose') return 'lose';
  if (status === 'Rejected') return 'reject';
  if (status === 'Hold_Reject') return 'hold';
  return 'yes';
}

function mapLeadStatusToStage(status, pipelineCurrentStage) {
  if (status === 'Win') return 'Win';
  if (isMatrixStage(status)) return status;
  if (pipelineCurrentStage && isMatrixStage(pipelineCurrentStage)) return pipelineCurrentStage;
  return 'Call'; // fallback: unknown terminal stage
}

function populateLeadStageSelect(currentStage) {
  const sel = document.getElementById('ld-edit-stage');
  if (!sel) return;
  sel.innerHTML = '';
  LEAD_MATRIX_STAGES.forEach(st => {
    const meta = LD_PIPELINE_META[st] || { label: st };
    const opt = document.createElement('option');
    opt.value = st;
    opt.textContent = meta.label || st;
    if (st === currentStage) opt.selected = true;
    sel.appendChild(opt);
  });
}

function populateLeadOutcomeSelect(stage, currentOutcome) {
  const sel = document.getElementById('ld-edit-outcome');
  if (!sel) return;
  sel.innerHTML = '';
  const opts = (stage === 'Win')
    ? [{ v: 'yes', label: 'Win' }]
    : LEAD_MATRIX_OUTCOME_OPTS;

  opts.forEach(o => {
    const opt = document.createElement('option');
    opt.value = o.v;
    opt.textContent = o.label;
    if (o.v === currentOutcome) opt.selected = true;
    sel.appendChild(opt);
  });
}

function deriveStageOutcomeFromLead(d) {
  const stage = mapLeadStatusToStage(d.status, d.pipeline_current_stage);
  const outcome = (stage === 'Win') ? 'yes' : mapLeadStatusToOutcome(d.status);
  return { stage, outcome };
}

function ldDefaultEventDate(d) {
  const today = new Date().toISOString().slice(0, 10);
  const planned = ldCaseVal(d?.next_plan_date);
  if (planned && planned <= today) return planned;
  return today;
}

function populateLeadEditForm(d) {
  currentLeadId = d.id;
  document.getElementById('ld-edit-code').value = d.code || '';
  document.getElementById('ld-edit-update').value = '';

  const { stage, outcome } = deriveStageOutcomeFromLead(d);
  populateLeadStageSelect(stage);
  populateLeadOutcomeSelect(stage, outcome);

  const np = document.getElementById('ld-edit-next-plan');
  const nd = document.getElementById('ld-edit-next-date');
  const ed = document.getElementById('ld-edit-event-date');
  if (np) np.value = (d.next_plan && d.next_plan !== '-') ? d.next_plan : '';
  if (nd) setAppDateValue(nd, d.next_plan_date || '');
  if (ed) setAppDateValue(ed, ldDefaultEventDate(d));
  ldNextPlanDraft = {
    text: (d.next_plan && d.next_plan !== '-') ? String(d.next_plan).trim() : '',
    date: d.next_plan_date || '',
  };

  const reviveBtn = document.getElementById('ld-revive-btn');
  const actionBar = document.getElementById('ld-action-bar');
  if (reviveBtn) {
    if (d.is_terminal) reviveBtn.classList.remove('hidden');
    else reviveBtn.classList.add('hidden');
  }
  if (actionBar) {
    actionBar.classList.toggle('hidden', !d.is_terminal);
  }
  updateLdTaskHint();
}

let ldSaveToastTimer = null;
let ldSavingActive = false;

function setLdSaving(on, message) {
  ldSavingActive = on;
  const overlay = document.getElementById('ld-saving-overlay');
  const msgEl = document.getElementById('ld-saving-msg');
  const btn = document.getElementById('ld-save-btn');
  const caseBtn = document.getElementById('ld-case-save-btn');
  const revive = document.getElementById('ld-revive-btn');
  if (overlay) overlay.classList.toggle('hidden', !on);
  if (msgEl) msgEl.textContent = message || 'กำลังบันทึก…';
  if (btn) {
    btn.disabled = on;
    btn.textContent = on ? 'กำลังบันทึก…' : 'บันทึกการอัปเดต';
  }
  if (caseBtn) {
    caseBtn.disabled = on;
    if (on) {
      caseBtn.textContent = 'กำลังบันทึก…';
    } else {
      caseBtn.innerHTML = '<i data-lucide="clipboard-list" class="w-4 h-4"></i> บันทึกข้อมูลเคส';
    }
  }
  if (revive) revive.disabled = on;
  if (on) lucide.createIcons();
}

function ldCaseVal(raw) {
  const t = (raw != null && raw !== '-') ? String(raw).trim() : '';
  return t;
}

function ldCaseHasData(d) {
  if (!d) return false;
  return !!(
    ldCaseVal(d.potential) || ldCaseVal(d.aux_tag) || ldCaseVal(d.agent_client_name)
    || ldCaseVal(d.agent_client_phone_last4) || ldCaseVal(d.owner_code)
    || (d.units_sent != null && d.units_sent !== '') || ldCaseVal(d.offered_listings)
    || ldCaseVal(d.background) || ldCaseVal(d.pain_point)
    || ldCaseVal(d.requirement) || ldCaseVal(d.financials) || ldCaseVal(d.timeline) || ldCaseVal(d.budget)
  );
}

function ldCaseHasOfferData(d) {
  if (!d) return false;
  return !!(ldCaseVal(d.owner_code) || (d.units_sent != null && d.units_sent !== '') || ldCaseVal(d.offered_listings));
}

function initOwnerCodeDatalist() {
  const dl = document.getElementById('ld-owner-code-options');
  if (!dl) return;
  dl.innerHTML = '';
  Object.values(ownerDetails).forEach(o => {
    if (!o || !o.code) return;
    const opt = document.createElement('option');
    opt.value = o.code;
    const proj = o.name_en || o.name_th || '';
    opt.label = proj ? (proj + ' · ' + o.code) : o.code;
    dl.appendChild(opt);
  });
}

function navigateToOwner(ownerId) {
  if (!ownerId) return;
  closeLeadDetail();
  switchTab('products');
  openProductDetail(ownerId);
}

function renderLdOwnerLink(d) {
  const wrap = document.getElementById('ld-owner-code-wrap');
  const hint = document.getElementById('ld-owner-link-hint');
  const hintText = document.getElementById('ld-owner-link-hint-text');
  const code = pdVal(d.owner_code);
  if (!wrap) return;
  if (code && d.owner_id) {
    wrap.innerHTML = '<button type="button" class="ld-owner-jump font-mono font-bold text-[var(--accent-text)] underline-offset-2 hover:underline inline-flex items-center gap-1" data-owner-id="' + d.owner_id + '">'
      + '<i data-lucide="link-2" class="w-3 h-3" aria-hidden="true"></i><span>' + pdEsc(code) + '</span></button>';
    if (hint && hintText) {
      hintText.textContent = 'เชื่อมกับทรัพย์: ' + (d.owner_project || code);
      hint.classList.remove('hidden');
    }
  } else if (code) {
    wrap.textContent = code;
    if (hint && hintText) {
      hintText.textContent = 'รหัสนี้ยังไม่ตรงกับทรัพย์ในระบบ — ตรวจใน「ข้อมูลเคส › ทรัพย์ & การเสนอ」';
      hint.classList.remove('hidden');
    }
  } else {
    wrap.textContent = '-';
    hint?.classList.add('hidden');
  }
  wrap.querySelector('.ld-owner-jump')?.addEventListener('click', (e) => {
    e.preventDefault();
    navigateToOwner(parseInt(e.currentTarget.dataset.ownerId, 10));
  });
}

function renderLdOfferAside(d) {
  const unitsN = d.units_sent != null && d.units_sent !== '' ? Number(d.units_sent) : null;
  const unitsTxt = unitsN != null && !Number.isNaN(unitsN) ? (unitsN + ' หลัง') : '';
  pdSetGridPair('ld-units-sent-aside-label', 'ld-units-sent-aside', unitsTxt);
  pdSetGridPair('ld-offered-aside-label', 'ld-offered-aside', pdVal(d.offered_listings));
}

function renderPdLinkedLeads(code) {
  const section = document.getElementById('pd-linked-leads-section');
  const list = document.getElementById('pd-linked-leads');
  const countEl = document.getElementById('pd-linked-leads-count');
  if (!section || !list) return;
  const items = (code && leadsByOwnerCode[code]) ? leadsByOwnerCode[code] : [];
  if (!items.length) {
    section.classList.add('hidden');
    list.innerHTML = '';
    if (countEl) countEl.textContent = '';
    return;
  }
  section.classList.remove('hidden');
  if (countEl) countEl.textContent = '(' + items.length + ')';
  list.innerHTML = items.map(item => {
    const sm = item.status_meta || {};
    const icon = sm.icon || 'circle';
    const label = sm.label || item.status || '-';
    return '<li><button type="button" class="pd-linked-lead-btn w-full text-left rounded-xl border border-[var(--border)] bg-[var(--surface)] px-3 py-2 active:scale-[0.99] transition" data-lead-id="' + item.id + '">'
      + '<span class="flex items-center justify-between gap-2">'
      + '<span class="font-bold text-xs truncate">' + pdEsc(item.name || item.code) + '</span>'
      + '<span class="shrink-0 inline-flex items-center gap-1 text-[10px] font-bold text-[var(--muted)]"><i data-lucide="' + pdEsc(icon) + '" class="w-3 h-3" aria-hidden="true"></i>' + pdEsc(label) + '</span>'
      + '</span>'
      + '<span class="text-[10px] text-[var(--faint)] font-mono mt-0.5 block">' + pdEsc(leadCodeForDisplay(item.code)) + '</span>'
      + '</button></li>';
  }).join('');
  list.querySelectorAll('.pd-linked-lead-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const lid = parseInt(btn.dataset.leadId, 10);
      if (!lid) return;
      closeProductDetail();
      switchTab('leads');
      openLeadDetail(lid);
    });
  });
}

function ldCaseSetViewField(id, val, emptyLabel) {
  const el = document.getElementById(id);
  if (!el) return;
  const t = ldCaseVal(val);
  if (t) {
    el.textContent = t;
    el.classList.remove('ld-case-empty');
    el.classList.add('ld-case-view-val', 'whitespace-pre-wrap');
  } else {
    el.textContent = emptyLabel || 'ยังไม่มีข้อมูล';
    el.classList.add('ld-case-empty');
    el.classList.remove('ld-case-view-val', 'whitespace-pre-wrap');
  }
}

function renderLdCaseView(d) {
  const pot = ldCaseVal(d.potential);
  const potEl = document.getElementById('ld-case-view-potential');
  if (potEl) {
    if (pot) {
      potEl.textContent = pot + ' — ' + (LD_POTENTIAL_DESC[pot] || '');
      potEl.classList.remove('ld-case-empty');
      potEl.classList.add('ld-case-view-val');
    } else {
      potEl.textContent = 'ยังไม่ระบุ';
      potEl.classList.add('ld-case-empty');
      potEl.classList.remove('ld-case-view-val');
    }
  }
  const auxEl = document.getElementById('ld-case-view-aux-tag');
  if (auxEl) {
    if (d.aux_tag_meta) {
      auxEl.innerHTML = leadAuxTagHtml(d.aux_tag_meta) + ' <span class="text-[var(--text-2)] font-medium">' + pdEsc(d.aux_tag_meta.label) + '</span>';
      auxEl.classList.remove('ld-case-empty');
      auxEl.classList.add('ld-case-view-val');
    } else {
      auxEl.textContent = 'ไม่ระบุ';
      auxEl.classList.add('ld-case-empty');
      auxEl.classList.remove('ld-case-view-val');
    }
  }
  const agentWrap = document.getElementById('ld-case-view-agent-wrap');
  const agentNameEl = document.getElementById('ld-case-view-agent-name');
  const agentPhoneEl = document.getElementById('ld-case-view-agent-phone');
  const isAgentCase = d.aux_tag === 'agent' || d.is_agent;
  if (agentWrap) {
    agentWrap.classList.toggle('hidden', !isAgentCase);
    if (isAgentCase) {
      if (agentNameEl) agentNameEl.textContent = ldAgentDisplayVal(d.agent_client_name);
      if (agentPhoneEl) agentPhoneEl.textContent = ldAgentDisplayVal(d.agent_client_phone_last4);
    }
  }
  ldCaseSetViewField('ld-case-view-background', d.background);
  ldCaseSetViewField('ld-case-view-pain', d.pain_point);
  ldCaseSetViewField('ld-case-view-requirement', d.requirement);
  ldCaseSetViewField('ld-case-view-financial', d.financials);
  ldCaseSetViewField('ld-case-view-timeline', d.timeline);
  ldCaseSetViewField('ld-case-view-budget', d.budget_fmt || d.budget);
  const offerWrap = document.getElementById('ld-case-view-offer-wrap');
  if (offerWrap) {
    const hasOffer = ldCaseHasOfferData(d);
    offerWrap.classList.toggle('hidden', !hasOffer);
    if (hasOffer) {
      const ownerView = document.getElementById('ld-case-view-owner-code');
      const code = pdVal(d.owner_code);
      if (ownerView) {
        if (code && d.owner_id) {
          ownerView.innerHTML = '<button type="button" class="ld-owner-jump font-mono font-bold text-[var(--accent-text)] underline-offset-2 hover:underline inline-flex items-center gap-1" data-owner-id="' + d.owner_id + '">'
            + '<i data-lucide="link-2" class="w-3 h-3" aria-hidden="true"></i><span>' + pdEsc(code) + '</span></button>';
          ownerView.querySelector('.ld-owner-jump')?.addEventListener('click', (e) => {
            e.preventDefault();
            navigateToOwner(parseInt(e.currentTarget.dataset.ownerId, 10));
          });
        } else {
          ownerView.textContent = code || 'ยังไม่ระบุ';
        }
        ownerView.classList.toggle('ld-case-empty', !code);
      }
      const unitsN = d.units_sent != null && d.units_sent !== '' ? Number(d.units_sent) : null;
      const unitsTxt = unitsN != null && !Number.isNaN(unitsN) ? (unitsN + ' หลัง') : '';
      ldCaseSetViewField('ld-case-view-units-sent', unitsTxt, 'ยังไม่ระบุ');
      ldCaseSetViewField('ld-case-view-offered-listings', d.offered_listings, 'ยังไม่ระบุ');
    }
  }
}

function setLdCaseMode(mode) {
  const viewPanel = document.getElementById('ld-case-view-panel');
  const form = document.getElementById('lead-case-form');
  const editBtn = document.getElementById('ld-case-edit-btn');
  const editing = mode === 'edit';
  if (editing) {
    viewPanel?.classList.add('hidden');
    form?.classList.remove('hidden');
    editBtn?.classList.add('hidden');
    mountAllFmtNums(form);
    updateLdAgentFieldsVisibility();
  } else {
    viewPanel?.classList.remove('hidden');
    form?.classList.add('hidden');
    editBtn?.classList.toggle('hidden', !ldCaseHasData(leadDetails[currentLeadId]));
  }
  lucide.createIcons();
}

function updateLdPotentialHint() {
  const sel = document.getElementById('ld-case-potential');
  const hint = document.getElementById('ld-case-potential-hint');
  if (!sel || !hint) return;
  const desc = sel.value ? (LD_POTENTIAL_DESC[sel.value] || '') : '';
  if (desc) {
    hint.textContent = desc;
    hint.classList.remove('italic', 'text-[var(--faint)]');
  } else {
    hint.textContent = 'เลือก A / B / C เพื่อบอกความพร้อมซื้อของลูกค้า';
    hint.classList.add('italic', 'text-[var(--faint)]');
  }
}

function populateLeadCaseForm(d) {
  const codeEl = document.getElementById('ld-case-code');
  if (codeEl) codeEl.value = d.code || '';
  const pot = document.getElementById('ld-case-potential');
  if (pot) {
    pot.value = d.potential || '';
    pot.dispatchEvent(new Event('change', { bubbles: true }));
  }
  const setField = (id, val) => {
    const el = document.getElementById(id);
    if (el) el.value = ldCaseVal(val);
  };
  setField('ld-case-background', d.background);
  setField('ld-case-pain', d.pain_point);
  setField('ld-case-requirement', d.requirement);
  setField('ld-case-financial', d.financials);
  setField('ld-case-timeline', d.timeline);
  const budgetEl = document.getElementById('ld-case-budget');
  if (budgetEl) {
    budgetEl.value = priceDigitsForInput(d.budget_fmt || d.budget || '');
    bindFmtNumInput(budgetEl);
  }
  setLdAuxTagForm(d.aux_tag || '');
  setField('ld-agent-client-name', d.agent_client_name);
  setField('ld-agent-phone-last4', d.agent_client_phone_last4);
  setField('ld-case-owner-code', d.owner_code);
  const unitsEl = document.getElementById('ld-case-units-sent');
  if (unitsEl) {
    unitsEl.value = (d.units_sent != null && d.units_sent !== '') ? String(d.units_sent) : '';
  }
  setField('ld-case-offered-listings', d.offered_listings);
  updateLdAgentFieldsVisibility();
  updateLdPotentialHint();
  renderLdCaseView(d);
  setLdCaseMode(ldCaseHasData(d) ? 'view' : 'edit');
}

function showSaveToast(msg) {
  const toast = document.getElementById('app-save-toast');
  const msgEl = document.getElementById('app-save-toast-msg');
  if (!toast) return;
  if (msgEl) msgEl.textContent = msg || 'บันทึกสำเร็จ';
  toast.classList.remove('hidden');
  lucide.createIcons();
  clearTimeout(ldSaveToastTimer);
  ldSaveToastTimer = setTimeout(() => toast.classList.add('hidden'), 2800);
}

function ldHistoryDeleteBtn(kind, itemId) {
  if (!itemId) return '';
  return '<button type="button" class="ld-history-del shrink-0 flex items-center gap-1 text-[10px] font-bold text-[var(--muted)] px-2 py-1 rounded-lg border border-[var(--border)] bg-[var(--card)] active:scale-95 transition" data-kind="' + pdEsc(kind) + '" data-item-id="' + itemId + '" aria-label="ลบรายการนี้">'
    + '<i data-lucide="trash-2" class="w-3 h-3"></i>ลบ</button>';
}

const LD_HISTORY_COLLAPSED = 3;
const LD_HISTORY_PAGE_SIZE = 10;
let ldHistoryExpanded = false;
let ldHistoryPage = 0;
let ldHistoryLeadId = null;

function ldHistoryOutcomeIcon(outcome) {
  if (outcome === 'lose') return (LD_PIPELINE_META.Lose || {}).icon || 'user-x';
  if (outcome === 'reject') return (LD_PIPELINE_META.Rejected || {}).icon || 'ban';
  if (outcome === 'hold') return (LD_PIPELINE_META.Hold_Reject || {}).icon || 'pause-circle';
  return 'check';
}

function ldHistoryOutcomeLabel(outcome) {
  if (outcome === 'yes') return 'Yes';
  if (outcome === 'lose') return 'Lose';
  if (outcome === 'reject') return 'Reject';
  if (outcome === 'hold') return 'Hold';
  return outcome;
}

function buildLeadHistoryItems(d) {
  const stageEvents = d.stage_events || [];
  const logs = d.status_logs || [];
  if (stageEvents.length) {
    return stageEvents.slice().reverse().map(ev => {
      const stage = ev.stage || '-';
      const meta = LD_PIPELINE_META[stage] || { label: stage, icon: 'circle' };
      const icon = ev.outcome === 'yes' ? meta.icon : ldHistoryOutcomeIcon(ev.outcome);
      const label = meta.label + ' · ' + ldHistoryOutcomeLabel(ev.outcome);
      return {
        kind: ev.kind || 'stage_event',
        id: ev.id,
        date: ev.date,
        icon,
        label,
        note: ev.note || '',
      };
    });
  }
  return logs.map(item => ({
    kind: item.kind || 'status_log',
    id: item.id,
    date: item.date,
    icon: item.icon || 'circle',
    label: item.label || item.status,
    note: item.note || '',
  }));
}

function ldHistoryItemHtml(item) {
  return '<div class="flex items-start justify-between gap-2">'
    + '<div class="min-w-0 flex-1">'
    + '<p class="text-[10px] text-[var(--faint)] flex flex-wrap items-center gap-1 mb-0.5">'
    + '<i data-lucide="calendar" class="w-3 h-3"></i>' + pdFmtDate(item.date)
    + ' · <i data-lucide="' + pdEsc(item.icon) + '" class="w-3 h-3"></i>' + pdEsc(item.label) + '</p>'
    + (item.note ? '<p class="leading-snug">' + pdEsc(item.note) + '</p>' : '')
    + '</div>'
    + ldHistoryDeleteBtn(item.kind, item.id)
    + '</div>';
}

function renderLdHistory(d) {
  const histUl = document.getElementById('ld-status-history');
  const histEmpty = document.getElementById('ld-status-empty');
  const moreBtn = document.getElementById('ld-history-more');
  const moreText = document.getElementById('ld-history-more-text');
  const pager = document.getElementById('ld-history-pager');
  const pageInfo = document.getElementById('ld-history-page-info');
  const prevBtn = document.getElementById('ld-history-prev');
  const nextBtn = document.getElementById('ld-history-next');
  if (!histUl || !histEmpty) return;

  if (ldHistoryLeadId !== d.id) {
    ldHistoryExpanded = false;
    ldHistoryPage = 0;
    ldHistoryLeadId = d.id;
  }

  const items = buildLeadHistoryItems(d);
  histUl.innerHTML = '';

  if (!items.length) {
    histEmpty.classList.remove('hidden');
    moreBtn?.classList.add('hidden');
    pager?.classList.add('hidden');
    return;
  }

  histEmpty.classList.add('hidden');
  const total = items.length;
  let visible = items;

  if (!ldHistoryExpanded) {
    visible = items.slice(0, LD_HISTORY_COLLAPSED);
    if (moreBtn) {
      if (total > LD_HISTORY_COLLAPSED) {
        moreBtn.classList.remove('hidden');
        moreBtn.querySelector('[data-lucide]')?.setAttribute('data-lucide', 'chevrons-down');
        if (moreText) moreText.textContent = 'ดูเพิ่มเติม (' + (total - LD_HISTORY_COLLAPSED) + ')';
      } else {
        moreBtn.classList.add('hidden');
      }
    }
    pager?.classList.add('hidden');
  } else {
    const pageCount = Math.ceil(total / LD_HISTORY_PAGE_SIZE);
    if (ldHistoryPage >= pageCount) ldHistoryPage = Math.max(0, pageCount - 1);
    const start = ldHistoryPage * LD_HISTORY_PAGE_SIZE;
    visible = items.slice(start, start + LD_HISTORY_PAGE_SIZE);
    if (moreBtn) {
      moreBtn.classList.remove('hidden');
      moreBtn.querySelector('[data-lucide]')?.setAttribute('data-lucide', 'chevrons-up');
      if (moreText) moreText.textContent = 'ย่อเหลือ ' + LD_HISTORY_COLLAPSED + ' รายการล่าสุด';
    }
    if (pager && pageCount > 1) {
      pager.classList.remove('hidden');
      if (pageInfo) pageInfo.textContent = 'หน้า ' + (ldHistoryPage + 1) + ' / ' + pageCount + ' · ' + total + ' รายการ';
      if (prevBtn) prevBtn.disabled = ldHistoryPage <= 0;
      if (nextBtn) nextBtn.disabled = ldHistoryPage >= pageCount - 1;
    } else {
      pager?.classList.add('hidden');
    }
  }

  visible.forEach(item => {
    const li = document.createElement('li');
    li.className = 'bg-[var(--surface)] border border-[var(--border)] rounded-xl px-3 py-2';
    li.innerHTML = ldHistoryItemHtml(item);
    histUl.appendChild(li);
  });
}

async function deleteLeadHistoryItem(kind, itemId, leadId) {
  if (!itemId || !leadId || ldSavingActive) return;
  if (!confirm('ลบรายการนี้จากประวัติ?')) return;
  setLdSaving(true, 'กำลังลบ…');
  const fd = new FormData();
  fd.append('ajax', 'lead_delete_history');
  fd.append('lead_id', String(leadId));
  fd.append('kind', kind);
  fd.append('item_id', String(itemId));
  try {
    const res = await fetch('dashboard.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success && data.lead) {
      applyLeadDataUpdate(data.lead);
      showSaveToast(data.message || 'ลบรายการแล้ว');
    } else {
      alert(data.message || 'ลบไม่สำเร็จ');
    }
  } catch (err) {
    alert('เชื่อมต่อไม่สำเร็จ');
  } finally {
    setLdSaving(false);
  }
}

function updateLdTaskHint() {
  const stageEl = document.getElementById('ld-edit-stage');
  const outcomeEl = document.getElementById('ld-edit-outcome');
  const hint = document.getElementById('ld-auto-task-hint');
  const hintText = document.getElementById('ld-auto-task-hint-text');
  if (!stageEl || !outcomeEl || !hint || !hintText) return;
  const st = stageEl.value;
  const out = outcomeEl.value;
  const d = currentLeadId ? (leadDetails[currentLeadId] || {}) : {};

  let text = '';
  if (out === 'yes' && (st === 'Close' || st === 'Bank')) {
    text = 'สร้างงานติดตามรายวันในกลุ่ม "รอปิดดีล" จนกว่าจะ Win';
  } else if (out === 'yes' && st === 'Reserve') {
    text = 'สร้างงานติดตามจองรายวัน จนกว่าจะโอน';
  } else if (['lose', 'reject', 'hold'].includes(out) && d.owner_code) {
    text = 'สร้างงานแจ้งเจ้าของทรัพย์ ' + d.owner_code + ' อัตโนมัติ';
  }

  if (text) {
    hintText.textContent = text;
    hint.classList.remove('hidden');
  } else {
    hint.classList.add('hidden');
  }
  updateLdFollowFields();
}

let ldNextPlanDraft = { text: '', date: '' };

function updateLdFollowFields() {
  const outcomeEl = document.getElementById('ld-edit-outcome');
  const np = document.getElementById('ld-edit-next-plan');
  const nd = document.getElementById('ld-edit-next-date');
  const wrap = document.getElementById('ld-next-plan-wrap');
  const termHint = document.getElementById('ld-next-plan-terminal-hint');
  if (!outcomeEl) return;

  const terminal = ['lose', 'reject'].includes(outcomeEl.value);
  if (wrap) wrap.classList.toggle('hidden', terminal);
  if (termHint) termHint.classList.toggle('hidden', !terminal);

  if (terminal) {
    if ((np?.value || '').trim() || nd?.value) {
      ldNextPlanDraft = { text: (np?.value || '').trim(), date: nd?.value || '' };
    }
    if (np) np.value = '';
    if (nd) setAppDateValue(nd, '');
  } else if (ldNextPlanDraft.text || ldNextPlanDraft.date) {
    if (np && !np.value.trim()) np.value = ldNextPlanDraft.text;
    if (nd && !nd.value && ldNextPlanDraft.date) setAppDateValue(nd, ldNextPlanDraft.date);
  }
}

// ===== Lead Potential (A/B/C) =====
const LD_POTENTIAL_DESC = <?php
  $pd = [];
  foreach (['A', 'B', 'C'] as $g) {
      $m = lead_potential_meta($g);
      if ($m) $pd[$g] = $m['desc'];
  }
  echo json_encode($pd, JSON_UNESCAPED_UNICODE);
?>;

// ===== Lead Pipeline Matrix (Sheet UI) =====
const PL_MATRIX_SHORT = <?php
  $pl_short = [];
  foreach (lead_funnel_statuses() as $s) {
      $pl_short[$s] = lead_status_step_meta($s)['label'];
  }
  echo json_encode($pl_short, JSON_UNESCAPED_UNICODE);
?>;
const PL_MATRIX_ROOTS = {
  'lead-matrix-root': { searchId: 'search-leads', stageId: 'lead-matrix-stage-filter', outcomeId: 'lead-matrix-outcome-filter', countId: 'lead-matrix-count', monthMode: 'lead' },
  'pl-matrix-root': { searchId: 'pl-matrix-search', stageId: 'pl-matrix-stage-filter', outcomeId: 'pl-matrix-outcome-filter', countId: 'pl-matrix-count', monthMode: 'pipeline' },
};
const PL_MATRIX_PAGE_SIZE = 20;
const plMatrixPage = { 'lead-matrix-root': 1, 'pl-matrix-root': 1 };
const plMatrixSortState = { 'lead-matrix-root': 'date_desc', 'pl-matrix-root': 'date_desc' };

function plMatrixGroupKey(d) {
  const gid = parseInt(d.customer_group_id, 10);
  if (gid > 0) return gid;
  return parseInt(d.id, 10) || 0;
}

function plMatrixMergeOutcomes(a, b) {
  const out = { ...(a || {}) };
  Object.entries(b || {}).forEach(([stage, val]) => {
    if (val && !out[stage]) out[stage] = val;
  });
  return out;
}

function plMatrixMergeGroupLead(rep, d) {
  const merged = { ...rep };
  merged.group_size = Math.max(rep.group_size || 1, d.group_size || 1);
  merged.group_member_ids = [...(rep.group_member_ids || [rep.id]), d.id];
  if ((d.inbound_date || '') > (merged.inbound_date || '')) {
    merged.inbound_date = d.inbound_date;
    merged.name = d.name || merged.name;
  }
  merged.stage_outcome_latest = plMatrixMergeOutcomes(rep.stage_outcome_latest, d.stage_outcome_latest);
  merged.stage_events = [...(rep.stage_events || []), ...(d.stage_events || [])];
  return merged;
}

function plMatrixDedupeByGroup(leads) {
  const map = new Map();
  leads.forEach(d => {
    const g = plMatrixGroupKey(d);
    if (!map.has(g)) {
      map.set(g, { ...d, group_member_ids: [d.id] });
    } else {
      map.set(g, plMatrixMergeGroupLead(map.get(g), d));
    }
  });
  return [...map.values()];
}

function plMatrixResetPage(rootId) {
  if (rootId) plMatrixPage[rootId] = 1;
  else Object.keys(PL_MATRIX_ROOTS).forEach(id => { plMatrixPage[id] = 1; });
}
let matrixPickerLeadId = null;
let matrixPickerStage = null;
let matrixPickerOutcome = null;

function syncLeadsByOwnerCodeEntry(lead) {
  if (!lead || !lead.id) return;
  Object.keys(leadsByOwnerCode).forEach(code => {
    leadsByOwnerCode[code] = (leadsByOwnerCode[code] || []).filter(item => item.id !== lead.id);
    if (!leadsByOwnerCode[code].length) delete leadsByOwnerCode[code];
  });
  const oc = pdVal(lead.owner_code);
  if (!oc) return;
  if (!leadsByOwnerCode[oc]) leadsByOwnerCode[oc] = [];
  const sm = lead.status_meta || {};
  const existing = leadsByOwnerCode[oc].findIndex(item => item.id === lead.id);
  const row = {
    id: lead.id,
    code: lead.code,
    name: lead.name || lead.code,
    status: lead.status,
    status_meta: sm,
  };
  if (existing >= 0) leadsByOwnerCode[oc][existing] = row;
  else leadsByOwnerCode[oc].push(row);
}

function applyLeadDataUpdate(lead) {
  if (!lead || !lead.id) return;
  leadDetails[lead.id] = lead;
  if (Array.isArray(leadDetails)) {
    const idx = leadDetails.findIndex(d => d && d.id === lead.id);
    if (idx >= 0) leadDetails[idx] = lead;
  }
  syncLeadsByOwnerCodeEntry(lead);
  renderAllPipelineMatrices();
  updateLeadChipCounts(leadMonthFilter);
  if (currentLeadId === lead.id) renderLeadDetail(lead);
}

function plMatrixOutcomeMeta(outcome, stage) {
  if (stage === 'Win' && outcome === 'yes') return { label: 'Win', icon: 'trophy', cls: 'pl-matrix-cell--yes' };
  if (outcome === 'yes') return { label: 'Yes', icon: 'check', cls: 'pl-matrix-cell--yes' };
  if (outcome === 'lose') return { label: 'Lose', icon: 'user-x', cls: 'pl-matrix-cell--lose' };
  if (outcome === 'reject') return { label: 'Reject', icon: 'ban', cls: 'pl-matrix-cell--reject' };
  if (outcome === 'hold') return { label: 'Hold', icon: 'pause-circle', cls: 'pl-matrix-cell--hold' };
  return { label: '—', icon: 'minus', cls: 'pl-matrix-cell--empty' };
}

function plMatrixDateIso(d) {
  const iso = (d.inbound_date || '').trim();
  if (!iso || iso === '0000-00-00') return '';
  return iso;
}

function plMatrixFmtDateCol(iso) {
  if (!iso) return '—';
  const fmt = pdFmtDate(iso);
  return fmt === '-' ? '—' : fmt;
}

function plMatrixGetSort(rootId) {
  return plMatrixSortState[rootId] || 'date_desc';
}

function plMatrixSetSort(rootId, value) {
  plMatrixSortState[rootId] = value;
}

function plMatrixSortLeads(leads, sortKey) {
  const parts = String(sortKey || 'date_desc').split('_');
  const field = parts[0] || 'date';
  const dir = parts[1] === 'asc' ? 'asc' : 'desc';
  const mul = dir === 'desc' ? -1 : 1;
  return [...leads].sort((a, b) => {
    let cmp = 0;
    if (field === 'date') {
      cmp = (plMatrixDateIso(a) || '0000-00-00').localeCompare(plMatrixDateIso(b) || '0000-00-00');
    } else if (field === 'name') {
      cmp = (a.name || a.code || '').localeCompare(b.name || b.code || '', 'th');
    } else if (field === 'project') {
      cmp = (a.project || '').localeCompare(b.project || '', 'th');
    } else if (field === 'status') {
      cmp = (a.status || '').localeCompare(b.status || '', 'th');
    } else {
      cmp = (plMatrixDateIso(a) || '0000-00-00').localeCompare(plMatrixDateIso(b) || '0000-00-00');
    }
    if (cmp === 0) {
      cmp = (a.name || a.code || '').localeCompare(b.name || b.code || '', 'th');
    }
    return cmp * mul;
  });
}

function plMatrixToggleSortField(rootId, field) {
  if (!PL_MATRIX_ROOTS[rootId]) return;
  const cur = plMatrixGetSort(rootId);
  const [f, dir] = cur.split('_');
  const next = (f === field)
    ? field + '_' + (dir === 'desc' ? 'asc' : 'desc')
    : field + '_' + (field === 'date' ? 'desc' : 'asc');
  plMatrixSetSort(rootId, next);
  plMatrixResetPage(rootId);
  renderPipelineMatrix(rootId);
}

function plMatrixSortBtnHtml(field, label, sortKey) {
  const [f, dir] = String(sortKey || 'date_desc').split('_');
  const active = f === field;
  const icon = active ? (dir === 'desc' ? 'arrow-down' : 'arrow-up') : 'chevrons-up-down';
  const hint = active ? (dir === 'desc' ? ' (มาก→น้อย)' : ' (น้อย→มาก)') : '';
  return '<button type="button" class="pl-matrix-sort-btn' + (active ? ' pl-matrix-sort-btn--active' : '')
    + '" data-pl-matrix-sort="' + pdEsc(field) + '" aria-label="เรียงตาม' + pdEsc(label) + hint + '">'
    + '<span>' + pdEsc(label) + '</span><i data-lucide="' + icon + '" class="w-3 h-3 shrink-0" aria-hidden="true"></i></button>';
}

function plMatrixLeadPassesMonth(d, monthMode) {
  if (monthMode === 'lead') {
    if (!leadMonthFilter) return true;
    return (d.filter_month || '') === leadMonthFilter;
  }
  const plMonthOnly = document.getElementById('pl-matrix-month-only');
  if (!plMonthOnly?.checked) return true;
  const plMonth = document.getElementById('pl-month-value')?.value;
  return !!(plMonth && (d.filter_month || '') === plMonth);
}

function plMatrixLeadPassesFilters(d, cfg, q, stageF, outcomeF) {
  if (!plMatrixLeadPassesMonth(d, cfg.monthMode)) return false;
  if (q) {
    const blob = [d.code, d.name, d.project, d.owner_code, d.status, d.phone, d.line_id, d.inbound_date].join(' ').toLowerCase();
    if (!blob.includes(q)) return false;
  }
  const latest = d.stage_outcome_latest || {};
  if (stageF || outcomeF) {
    const st = stageF || 'Call';
    const val = latest[st] || '';
    if (outcomeF === '__empty') return !val;
    if (outcomeF) return val === outcomeF;
    if (stageF) return !!val;
  }
  return true;
}

function plMatrixCountSummary(leads, stages) {
  const counts = {};
  stages.forEach(s => { counts[s] = { yes: 0, drop: 0 }; });
  leads.forEach(d => {
    const latest = d.stage_outcome_latest || {};
    stages.forEach(s => {
      const o = latest[s];
      if (o === 'yes') counts[s].yes++;
      else if (['lose', 'reject', 'hold'].includes(o)) counts[s].drop++;
    });
  });
  return counts;
}

function renderPipelineMatrix(rootId) {
  const root = document.getElementById(rootId);
  const cfg = PL_MATRIX_ROOTS[rootId];
  if (!root || !cfg) return;

  const q = (document.getElementById(cfg.searchId)?.value || '').trim().toLowerCase();
  const stageF = document.getElementById(cfg.stageId)?.value || '';
  const outcomeF = document.getElementById(cfg.outcomeId)?.value || '';
  const stages = LEAD_MATRIX_STAGES;
  const sortKey = plMatrixGetSort(rootId);

  const allLeads = plMatrixDedupeByGroup(plMatrixSortLeads(Object.values(leadDetails), sortKey));
  const visible = allLeads.filter(d => {
    if (cfg.monthMode === 'lead' && leadStatusFilter) {
      const allowed = leadStatusFilter.split(',');
      if (!leadMatchesStatus(d.status, allowed)) return false;
    }
    return plMatrixLeadPassesFilters(d, cfg, q, stageF, outcomeF);
  });
  const summary = plMatrixCountSummary(visible, stages);

  const total = visible.length;
  const pageSize = PL_MATRIX_PAGE_SIZE;
  const totalPages = Math.max(1, Math.ceil(total / pageSize));
  let page = plMatrixPage[rootId] || 1;
  if (page > totalPages) page = totalPages;
  if (page < 1) page = 1;
  plMatrixPage[rootId] = page;
  const pageStart = (page - 1) * pageSize;
  const pageLeads = visible.slice(pageStart, pageStart + pageSize);

  const pageHintStr = total > pageSize ? ' · หน้า ' + page + '/' + totalPages : '';

  let html = '<div class="pl-matrix-wrap" data-pl-matrix-root="' + pdEsc(rootId) + '"><table class="pl-matrix-table"><thead>';
  html += '<tr class="pl-matrix-summary-row">';
  html += '<th class="pl-matrix-sticky-col pl-matrix-sticky-col--date pl-matrix-th-date pl-matrix-summary-date" scope="col">';
  if (rootId === 'lead-matrix-root') {
    html += '<span class="pl-matrix-head-count">' + total + ' รายการ' + pageHintStr + '</span>';
  } else {
    html += '<span aria-hidden="true">—</span>';
  }
  html += '</th>';
  html += '<th class="pl-matrix-sticky-col pl-matrix-sticky-col--lead pl-matrix-th-lead" scope="col"><span class="inline-flex items-center gap-1"><i data-lucide="sigma" class="w-3 h-3"></i>Yes</span></th>';
  stages.forEach(s => {
    const y = summary[s]?.yes || 0;
    html += '<th scope="col" class="pl-matrix-th-stage"><span class="inline-flex items-center justify-center gap-0.5 tabular-nums"><i data-lucide="check" class="w-3 h-3"></i>' + y + '</span></th>';
  });
  html += '</tr><tr class="pl-matrix-head-row">';
  html += '<th class="pl-matrix-sticky-col pl-matrix-sticky-col--date pl-matrix-th-date" scope="col">' + plMatrixSortBtnHtml('date', 'วันที่', sortKey) + '</th>';
  html += '<th class="pl-matrix-sticky-col pl-matrix-sticky-col--lead pl-matrix-th-lead" scope="col">' + plMatrixSortBtnHtml('name', 'Lead', sortKey) + '</th>';
  stages.forEach(s => {
    html += '<th scope="col" class="pl-matrix-th-stage">' + pdEsc(PL_MATRIX_SHORT[s] || s) + '</th>';
  });
  html += '</tr></thead><tbody>';

  if (!pageLeads.length) {
    html += '<tr><td colspan="' + (stages.length + 2) + '" class="pl-matrix-empty">ไม่มี Lead ตรงตัวกรอง</td></tr>';
  } else {
    pageLeads.forEach(d => {
      const latest = d.stage_outcome_latest || {};
      html += '<tr data-lead-id="' + d.id + '">';
      html += '<td class="pl-matrix-sticky-col pl-matrix-sticky-col--date pl-matrix-td-date"><span class="pl-matrix-date-text" title="' + pdEsc(pdFmtDate(d.inbound_date)) + '">' + pdEsc(plMatrixFmtDateCol(plMatrixDateIso(d))) + '</span></td>';
      html += '<td class="pl-matrix-sticky-col pl-matrix-sticky-col--lead pl-matrix-td-lead"><div class="pl-matrix-lead-btn" role="button" tabindex="0" data-lead-id="' + d.id + '">';
      html += '<span class="pl-matrix-lead-name">' + leadNameHtml(d, true) + '</span>';
      if ((d.group_size || 1) > 1) {
        html += '<span class="pl-matrix-lead-chip pl-matrix-lead-chip--group" title="ผูกกลุ่มลูกค้า ' + (d.group_size) + ' เคส">'
          + '<i data-lucide="link" class="w-3 h-3 inline"></i> ' + d.group_size + ' เคส</span>';
      }
      if (d.project) html += '<span class="pl-matrix-lead-chip" title="' + pdEsc(d.project) + '">' + pdEsc(d.project) + '</span>';
      html += '</div></td>';
      stages.forEach(s => {
        const out = latest[s] || '';
        const meta = plMatrixOutcomeMeta(out, s);
        html += '<td class="pl-matrix-td-cell"><button type="button" class="pl-matrix-cell ' + meta.cls + '" data-lead-id="' + d.id + '" data-stage="' + pdEsc(s) + '" aria-label="' + pdEsc((PL_MATRIX_SHORT[s] || s) + ' ' + meta.label) + '">';
        html += '<i data-lucide="' + meta.icon + '" class="w-3.5 h-3.5 shrink-0"></i><span>' + pdEsc(meta.label) + '</span></button></td>';
      });
      html += '</tr>';
    });
  }
  html += '</tbody></table></div>';

  if (total > pageSize) {
    const from = total ? pageStart + 1 : 0;
    const to = Math.min(pageStart + pageSize, total);
    html += '<div class="pl-matrix-pager" role="navigation" aria-label="เปลี่ยนหน้ารายการ Lead">';
    html += '<button type="button" class="pl-matrix-pager-btn" data-pl-matrix-prev data-root="' + pdEsc(rootId) + '"'
      + (page <= 1 ? ' disabled aria-disabled="true"' : '') + '>';
    html += '<i data-lucide="chevron-left" class="w-3.5 h-3.5"></i><span>ก่อนหน้า</span></button>';
    html += '<span class="pl-matrix-pager-info">หน้า ' + page + ' / ' + totalPages
      + ' · แสดง ' + from + '–' + to + ' จาก ' + total + '</span>';
    html += '<button type="button" class="pl-matrix-pager-btn" data-pl-matrix-next data-root="' + pdEsc(rootId) + '"'
      + (page >= totalPages ? ' disabled aria-disabled="true"' : '') + '>';
    html += '<span>ถัดไป</span><i data-lucide="chevron-right" class="w-3.5 h-3.5"></i></button>';
    html += '</div>';
  }

  root.innerHTML = html;

  const countEl = document.getElementById(cfg.countId);
  if (countEl) {
    const pageHint = total > pageSize ? ' · หน้า ' + page + '/' + totalPages : '';
    countEl.textContent = total + ' รายการ' + pageHint;
  }
  if (rootId === 'lead-matrix-root') {
    const lbl = document.getElementById('lead-count-label');
    if (lbl) {
      const pageHint = total > pageSize ? ' · หน้า ' + page + '/' + totalPages : '';
      lbl.textContent = total + ' รายการ' + pageHint;
    }
  }

  lucide.createIcons();
}

function renderAllPipelineMatrices() {
  Object.keys(PL_MATRIX_ROOTS).forEach(renderPipelineMatrix);
}

function matrixLatestStageEvent(d, stage) {
  const events = (d.stage_events || []).filter(ev => ev.stage === stage);
  if (!events.length) return null;
  return events[events.length - 1];
}

function renderMatrixPickerHistory(d, stage) {
  const wrap = document.getElementById('matrix-picker-history-wrap');
  const ul = document.getElementById('matrix-picker-history');
  if (!wrap || !ul) return;
  const events = (d.stage_events || []).filter(ev => ev.stage === stage);
  if (!events.length) {
    wrap.classList.add('hidden');
    ul.innerHTML = '';
    return;
  }
  wrap.classList.remove('hidden');
  const items = events.slice().reverse();
  ul.innerHTML = items.map((ev, idx) => {
    const meta = LD_PIPELINE_META[stage] || { label: stage, icon: 'circle' };
    const icon = ev.outcome === 'yes' ? meta.icon : ldHistoryOutcomeIcon(ev.outcome);
    const label = ldHistoryOutcomeLabel(ev.outcome);
    const latest = idx === 0
      ? ' <span class="text-[var(--accent-text)] font-bold">· ล่าสุด</span>'
      : '';
    return '<li class="border-b border-[var(--border)] pb-2">'
      + '<p class="text-[10px] text-[var(--faint)] flex flex-wrap items-center gap-1 mb-0.5">'
      + '<i data-lucide="calendar" class="w-3 h-3"></i>' + pdFmtDate(ev.date)
      + ' · <i data-lucide="' + pdEsc(icon) + '" class="w-3 h-3"></i> ' + pdEsc(label) + latest + '</p>'
      + (ev.note
        ? '<p class="leading-snug text-[var(--text-2)]">' + pdEsc(ev.note) + '</p>'
        : '<p class="text-[var(--faint)] italic text-[11px]">ไม่มีหมายเหตุ</p>')
      + '</li>';
  }).join('');
}

function openMatrixCellPicker(leadId, stage) {
  const d = leadDetails[leadId];
  if (!d) return;
  matrixPickerLeadId = leadId;
  matrixPickerStage = stage;
  matrixPickerOutcome = null;
  const picker = document.getElementById('matrix-cell-picker');
  const title = document.getElementById('matrix-picker-title');
  const stageMeta = LD_PIPELINE_META[stage] || { label: stage };
  if (title) title.innerHTML = leadNameHtml(d) + ' · ' + pdEsc(stageMeta.label || stage);

  renderMatrixPickerHistory(d, stage);

  const optsWrap = document.getElementById('matrix-picker-options');
  if (!optsWrap) return;
  const outcomes = (stage === 'Win')
    ? [{ v: 'yes', label: 'Win', icon: 'trophy', cls: 'matrix-picker-opt--yes' }]
    : [
        { v: 'yes', label: 'Yes', icon: 'check', cls: 'matrix-picker-opt--yes' },
        { v: 'lose', label: 'Lose', icon: 'user-x', cls: 'matrix-picker-opt--lose' },
        { v: 'reject', label: 'Reject', icon: 'ban', cls: 'matrix-picker-opt--reject' },
        { v: 'hold', label: 'Hold', icon: 'pause-circle', cls: 'matrix-picker-opt--hold' },
      ];

  optsWrap.innerHTML = '';
  outcomes.forEach(o => {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'matrix-picker-opt flex items-center justify-center gap-2 py-3 rounded-xl border border-[var(--border)] bg-[var(--surface)] text-sm font-bold ' + (o.cls || '');
    btn.dataset.outcome = o.v;
    btn.innerHTML = '<i data-lucide="' + o.icon + '" class="w-4 h-4"></i>' + pdEsc(o.label);
    btn.addEventListener('click', () => selectMatrixPickerOutcome(o.v, o.label, o.icon));
    optsWrap.appendChild(btn);
  });

  const latestOutcome = (d.stage_outcome_latest || {})[stage] || '';
  const latestEv = matrixLatestStageEvent(d, stage);
  const dateEl = document.getElementById('matrix-picker-date');
  const defaultDate = latestEv?.date || new Date().toISOString().slice(0, 10);
  if (dateEl) setAppDateValue(dateEl, defaultDate);
  const noteEl = document.getElementById('matrix-picker-note');
  if (noteEl) noteEl.value = '';
  const saveBtn = document.getElementById('matrix-picker-save');
  const selWrap = document.getElementById('matrix-picker-selected');
  const selText = document.getElementById('matrix-picker-selected-text');

  clearMatrixPickerWinFields();

  if (latestOutcome) {
    const picked = outcomes.find(o => o.v === latestOutcome) || outcomes[0];
    selectMatrixPickerOutcome(picked.v, picked.label, picked.icon);
    if (stage === 'Win') fillMatrixPickerWinFields(d);
  } else if (stage === 'Win') {
    selectMatrixPickerOutcome('yes', 'Win', 'trophy');
    fillMatrixPickerWinFields(d);
  } else {
    matrixPickerOutcome = null;
    if (saveBtn) saveBtn.disabled = true;
    if (selWrap) selWrap.classList.add('hidden');
    if (selText) selText.textContent = 'เลือกผลลัพธ์ด้านบน';
    toggleMatrixPickerWinFields(stage, '');
  }

  mountAllAppDateInputs(picker);
  picker?.classList.remove('hidden');
  document.body.style.overflow = 'hidden';
  lucide.createIcons();
}

function toggleMatrixPickerWinFields(stage, outcome) {
  const wrap = document.getElementById('matrix-picker-win-fields');
  const dateLbl = document.getElementById('matrix-picker-date-label');
  const show = stage === 'Win' && outcome === 'yes';
  wrap?.classList.toggle('hidden', !show);
  if (dateLbl) dateLbl.textContent = show ? 'วันที่บันทึกผล' : 'วันที่';
  if (show) {
    const d = matrixPickerLeadId ? leadDetails[matrixPickerLeadId] : null;
    updateMatrixWinScopeUi(d);
  }
}

function getMatrixWinCloseScope() {
  const checked = document.querySelector('#matrix-picker-win-scope input[name="win_close_scope"]:checked');
  return checked?.value === 'other' ? 'other' : 'this';
}

function matrixWinThisHintText(d) {
  if (!d) return 'ทรัพย์ที่ผูกในเคส';
  const parts = [];
  if (pdVal(d.owner_code)) parts.push(d.owner_code);
  const proj = pdVal(d.owner_project) || pdVal(d.project);
  if (proj) parts.push(proj);
  if (!parts.length) {
    return 'ยังไม่ผูกรหัสทรัพย์ในเคส — ใช้เมื่อปิดตามทรัพย์ที่ลูกค้าสนใจในเคสนี้';
  }
  return 'ทรัพย์ในเคส: ' + parts.join(' · ');
}

function updateMatrixWinScopeUi(d) {
  const scope = getMatrixWinCloseScope();
  const otherWrap = document.getElementById('matrix-picker-win-other-fields');
  const thisHint = document.getElementById('matrix-picker-win-this-hint');
  const thisHintText = document.getElementById('matrix-picker-win-this-hint-text');
  otherWrap?.classList.toggle('hidden', scope !== 'other');
  thisHint?.classList.toggle('hidden', scope !== 'this');
  if (thisHintText) thisHintText.textContent = matrixWinThisHintText(d);
}

function setMatrixWinCloseScope(scope) {
  const val = scope === 'other' ? 'other' : 'this';
  const input = document.querySelector('#matrix-picker-win-scope input[name="win_close_scope"][value="' + val + '"]');
  if (input) input.checked = true;
}

function priceDigitsForInput(val) {
  return fmtNum(val);
}

function bindFmtNumInput(el) {
  if (!el || el.dataset.fmtNumBound === '1') return;
  el.dataset.fmtNumBound = '1';
  el.addEventListener('input', () => {
    const pos = el.selectionStart;
    const before = el.value.length;
    el.value = fmtNum(el.value);
    const after = el.value.length;
    const newPos = Math.max(0, pos + (after - before));
    try { el.setSelectionRange(newPos, newPos); } catch (_) {}
  });
  el.addEventListener('blur', () => { if (el.value) el.value = fmtNum(el.value); });
}

function mountAllFmtNums(root) {
  (root || document).querySelectorAll('.fmt-num').forEach(bindFmtNumInput);
}

function stripFmtNumFormData(fd, root) {
  (root || document).querySelectorAll('.fmt-num[name]').forEach(el => {
    if (el.name && fd.has(el.name)) fd.set(el.name, stripNum(fd.get(el.name)));
  });
}

function fillMatrixPickerWinFields(d) {
  const transfer = document.getElementById('matrix-picker-transfer-date');
  const price = document.getElementById('matrix-picker-win-price');
  const pay = document.getElementById('matrix-picker-payment');
  const rev = document.getElementById('matrix-picker-revenue');
  const closeProject = document.getElementById('matrix-picker-close-project');
  const closeCode = document.getElementById('matrix-picker-close-owner-code');
  const closeOpen = document.getElementById('matrix-picker-close-open-price');
  setMatrixWinCloseScope(d.win_close_scope || 'this');
  if (closeProject) closeProject.value = pdVal(d.close_project);
  if (closeCode) closeCode.value = pdVal(d.close_owner_code);
  if (closeOpen) {
    closeOpen.value = priceDigitsForInput(d.close_open_price_fmt || d.close_open_price || '');
    bindFmtNumInput(closeOpen);
  }
  if (transfer) setAppDateValue(transfer, d.win_date || '');
  if (price) {
    const raw = d.win_price && d.win_price !== '-' ? d.win_price : (d.budget_fmt || d.budget || '');
    price.value = priceDigitsForInput(raw);
  }
  if (pay) pay.value = d.win_payment_method || '';
  if (rev) rev.value = priceDigitsForInput(d.revenue && d.revenue !== '-' ? d.revenue : '');
  bindFmtNumInput(price);
  bindFmtNumInput(rev);
  const winWrap = document.getElementById('matrix-picker-win-fields');
  if (winWrap) {
    mountAllAppDateInputs(winWrap);
    mountAllAppSelects(winWrap);
    mountAllFmtNums(winWrap);
  }
  updateMatrixWinScopeUi(d);
}

function clearMatrixPickerWinFields() {
  ['matrix-picker-transfer-date', 'matrix-picker-win-price', 'matrix-picker-revenue'].forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    if (el.type === 'date' || el.classList.contains('app-date-iso-native')) setAppDateValue(el, '');
    else el.value = '';
  });
  ['matrix-picker-close-project', 'matrix-picker-close-owner-code', 'matrix-picker-close-open-price'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.value = '';
  });
  const pay = document.getElementById('matrix-picker-payment');
  if (pay) pay.value = '';
  setMatrixWinCloseScope('this');
  toggleMatrixPickerWinFields('', '');
}

function selectMatrixPickerOutcome(outcome, label, icon) {
  matrixPickerOutcome = outcome;
  document.querySelectorAll('.matrix-picker-opt').forEach(btn => {
    btn.classList.toggle('matrix-picker-opt--selected', btn.dataset.outcome === outcome);
  });
  const saveBtn = document.getElementById('matrix-picker-save');
  if (saveBtn) saveBtn.disabled = false;
  const selWrap = document.getElementById('matrix-picker-selected');
  const selText = document.getElementById('matrix-picker-selected-text');
  if (selWrap) selWrap.classList.remove('hidden');
  if (selText) {
    selText.innerHTML = 'เลือก: <i data-lucide="' + pdEsc(icon) + '" class="w-3.5 h-3.5 inline-block align-text-bottom"></i> ' + pdEsc(label);
  }
  toggleMatrixPickerWinFields(matrixPickerStage, outcome);
  if (matrixPickerStage === 'Win' && outcome === 'yes') {
    const ld = leadDetails[matrixPickerLeadId];
    if (ld) fillMatrixPickerWinFields(ld);
  }
  lucide.createIcons();
}

function closeMatrixCellPicker() {
  document.getElementById('matrix-cell-picker')?.classList.add('hidden');
  const ldOpen = leadDetail && !leadDetail.classList.contains('hidden');
  if (!ldOpen) document.body.style.overflow = '';
  clearMatrixPickerWinFields();
  matrixPickerLeadId = null;
  matrixPickerStage = null;
  matrixPickerOutcome = null;
}

async function submitMatrixPicker() {
  const leadId = matrixPickerLeadId;
  const stage = matrixPickerStage;
  const outcome = matrixPickerOutcome;
  if (!leadId || !stage || !outcome) return;
  const d = leadDetails[leadId];
  if (!d) return;

  const eventDate = (() => {
    syncAllAppDateInputs();
    const el = document.getElementById('matrix-picker-date');
    return el?.value || new Date().toISOString().slice(0, 10);
  })();
  const note = document.getElementById('matrix-picker-note')?.value || '';
  const saveBtn = document.getElementById('matrix-picker-save');
  if (saveBtn) saveBtn.disabled = true;

  const fd = new FormData();
  fd.append('ajax', 'lead_save');
  fd.append('lead_code', d.code);
  fd.append('stage', stage);
  fd.append('outcome', outcome);
  fd.append('event_date', eventDate);
  if (note) fd.append('current_update', note);
  if (d.owner_code) fd.append('owner_code', d.owner_code);
  if (stage === 'Win' && outcome === 'yes') {
    syncAllAppDateInputs();
    const transferEl = document.getElementById('matrix-picker-transfer-date');
    const transferDate = transferEl?.value || eventDate;
    const winScope = getMatrixWinCloseScope();
    const winPrice = document.getElementById('matrix-picker-win-price')?.value?.replace(/,/g, '').trim() || '';
    const revenue = document.getElementById('matrix-picker-revenue')?.value?.replace(/,/g, '').trim() || '';
    const payment = document.getElementById('matrix-picker-payment')?.value || '';
    if (winScope === 'other') {
      const closeProject = document.getElementById('matrix-picker-close-project')?.value?.trim() || '';
      const closeCode = document.getElementById('matrix-picker-close-owner-code')?.value?.trim() || '';
      const closeOpen = document.getElementById('matrix-picker-close-open-price')?.value?.replace(/,/g, '').trim() || '';
      if (!closeProject || !closeCode) {
        alert('หลังอื่น — กรุณาระบุโครงการและรหัสทรัพย์');
        if (saveBtn) saveBtn.disabled = false;
        return;
      }
      if (!closeOpen || !winPrice) {
        alert('หลังอื่น — กรุณาระบุราคาเปิดและราคาปิด');
        if (saveBtn) saveBtn.disabled = false;
        return;
      }
      fd.append('close_project', closeProject);
      fd.append('close_owner_code', closeCode);
      fd.append('close_open_price', closeOpen);
    }
    fd.append('win_close_scope', winScope);
    fd.append('win_transfer_date', transferDate);
    if (winPrice) fd.append('win_price', winPrice);
    if (revenue) fd.append('revenue', revenue);
    if (payment) fd.append('win_payment_method', payment);
  }

  try {
    const res = await fetch('dashboard.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success && data.lead) {
      applyLeadDataUpdate(data.lead);
      closeMatrixCellPicker();
      showSaveToast(data.message || 'บันทึกสำเร็จ');
    } else {
      alert(data.message || 'บันทึกไม่สำเร็จ');
      if (saveBtn) saveBtn.disabled = false;
    }
  } catch (err) {
    alert('เชื่อมต่อไม่สำเร็จ');
    if (saveBtn) saveBtn.disabled = false;
  }
}

document.getElementById('matrix-picker-backdrop')?.addEventListener('click', closeMatrixCellPicker);
document.getElementById('matrix-picker-close')?.addEventListener('click', closeMatrixCellPicker);
document.getElementById('matrix-picker-cancel')?.addEventListener('click', closeMatrixCellPicker);
document.getElementById('matrix-picker-win-scope')?.addEventListener('change', () => {
  const d = matrixPickerLeadId ? leadDetails[matrixPickerLeadId] : null;
  updateMatrixWinScopeUi(d);
  lucide.createIcons();
});
document.getElementById('matrix-picker-save')?.addEventListener('click', () => submitMatrixPicker());

document.addEventListener('click', (e) => {
  const sortBtn = e.target.closest('.pl-matrix-sort-btn');
  if (sortBtn) {
    e.preventDefault();
    const wrap = sortBtn.closest('[data-pl-matrix-root]');
    const rootId = wrap?.dataset.plMatrixRoot;
    const field = sortBtn.dataset.plMatrixSort;
    if (rootId && field) {
      plMatrixToggleSortField(rootId, field);
    }
    return;
  }
  const prevBtn = e.target.closest('[data-pl-matrix-prev]');
  if (prevBtn && !prevBtn.disabled) {
    e.preventDefault();
    const rootId = prevBtn.dataset.root;
    plMatrixPage[rootId] = Math.max(1, (plMatrixPage[rootId] || 1) - 1);
    renderPipelineMatrix(rootId);
    document.getElementById(rootId)?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    return;
  }
  const nextBtn = e.target.closest('[data-pl-matrix-next]');
  if (nextBtn && !nextBtn.disabled) {
    e.preventDefault();
    const rootId = nextBtn.dataset.root;
    const page = plMatrixPage[rootId] || 1;
    plMatrixPage[rootId] = page + 1;
    renderPipelineMatrix(rootId);
    document.getElementById(rootId)?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    return;
  }
  const cell = e.target.closest('.pl-matrix-cell');
  if (cell) {
    e.preventDefault();
    e.stopPropagation();
    openMatrixCellPicker(parseInt(cell.dataset.leadId, 10), cell.dataset.stage);
    return;
  }
  const leadBtn = e.target.closest('.pl-matrix-lead-btn');
  if (leadBtn) {
    e.preventDefault();
    e.stopPropagation();
    openLeadDetail(parseInt(leadBtn.dataset.leadId, 10));
  }
});

document.addEventListener('keydown', (e) => {
  if (e.key !== 'Enter' && e.key !== ' ') return;
  const leadBtn = e.target.closest('.pl-matrix-lead-btn');
  if (!leadBtn) return;
  e.preventDefault();
  openLeadDetail(parseInt(leadBtn.dataset.leadId, 10));
});

['lead-matrix-stage-filter', 'lead-matrix-outcome-filter'].forEach(id => {
  const el = document.getElementById(id);
  el?.addEventListener('input', () => { plMatrixResetPage('lead-matrix-root'); renderPipelineMatrix('lead-matrix-root'); });
  el?.addEventListener('change', () => { plMatrixResetPage('lead-matrix-root'); renderPipelineMatrix('lead-matrix-root'); });
});
['pl-matrix-search', 'pl-matrix-stage-filter', 'pl-matrix-outcome-filter'].forEach(id => {
  const el = document.getElementById(id);
  el?.addEventListener('input', () => { plMatrixResetPage('pl-matrix-root'); renderPipelineMatrix('pl-matrix-root'); });
  el?.addEventListener('change', () => { plMatrixResetPage('pl-matrix-root'); renderPipelineMatrix('pl-matrix-root'); });
});
document.getElementById('pl-matrix-month-only')?.addEventListener('change', () => {
  plMatrixResetPage('pl-matrix-root');
  renderPipelineMatrix('pl-matrix-root');
});

(function initPipelineMatrixOnLoad() {
  function boot() {
    renderAllPipelineMatrices();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();

function leadLatestHistoryNote(d) {
  const items = buildLeadHistoryItems(d);
  for (let i = 0; i < items.length; i++) {
    const n = pdVal(items[i].note);
    if (n) return n;
  }
  return '';
}

function renderLeadDetail(d) {
  const titleEl = document.getElementById('ld-title');
  if (titleEl) titleEl.innerHTML = leadNameHtml(d) || 'รายละเอียด Lead';
  const ldSub = document.getElementById('ld-code');
  if (ldSub) {
    const subParts = [];
    if (d.project) subParts.push(d.project);
    if (d.owner_code) subParts.push(d.owner_code);
    if (subParts.length) {
      ldSub.textContent = subParts.join(' · ');
      ldSub.classList.remove('hidden');
    } else {
      ldSub.textContent = '';
      ldSub.classList.add('hidden');
    }
  }
  pdSetHtml('ld-name', leadNameHtml(d) || '-');
  updateLeadGroupUi(d);
  pdSet('ld-project', d.project);
  const productPrice = pdVal(d.product_price);
  renderLdOwnerLink(d);
  renderLdOfferAside(d);
  pdSetGridPair('ld-product-price-label', 'ld-product-price', productPrice);
  const inboundFmt = pdFmtDate(d.inbound_date);
  const inboundTxt = inboundFmt !== '-' ? inboundFmt : '';
  pdSetGridPair('ld-inbound-aside-label', 'ld-inbound-aside', inboundTxt);
  const budgetTxt = d.budget_fmt || d.budget || '';
  pdSetGridPair('ld-budget-aside-label', 'ld-budget-aside', budgetTxt);
  const hdrBudget = document.getElementById('ld-header-budget');
  if (hdrBudget) {
    const hdrTxt = budgetTxt || d.product_price || '';
    hdrBudget.textContent = hdrTxt;
    hdrBudget.classList.toggle('hidden', !hdrTxt);
  }
  const phoneLabel = document.getElementById('ld-phone-label');
  const phoneWrap = document.getElementById('ld-phone-wrap');
  const phoneWarn = document.getElementById('ld-phone-warn');
  if (phoneWrap) {
    const hasPhone = !!pdVal(d.phone);
    phoneLabel?.classList.toggle('hidden', !hasPhone);
    phoneWrap.classList.toggle('hidden', !hasPhone);
    if (hasPhone && d.phone_tel) {
      phoneWrap.innerHTML = '<a href="' + pdEsc(d.phone_tel) + '" class="text-[var(--accent-text)] underline-offset-2 hover:underline">' + pdEsc(d.phone) + '</a>';
    } else if (hasPhone) {
      phoneWrap.textContent = d.phone;
    } else {
      phoneWrap.textContent = '';
    }
    if (phoneWarn) {
      phoneWarn.classList.toggle('hidden', !hasPhone || !d.phone_suspect);
    }
  }
  const lineLabel = document.getElementById('ld-line-label');
  const lineWrap = document.getElementById('ld-line-wrap');
  if (lineWrap) {
    const hasLine = !!pdVal(d.line_id);
    lineLabel?.classList.toggle('hidden', !hasLine);
    lineWrap.classList.toggle('hidden', !hasLine);
    if (hasLine && d.line_url) {
      lineWrap.innerHTML = '<a href="' + pdEsc(d.line_url) + '" target="_blank" rel="noopener" class="font-medium text-[var(--accent-text)] inline-flex items-center gap-1 active:opacity-70"><i data-lucide="message-circle" class="w-3.5 h-3.5"></i>' + pdEsc(d.line_id) + '</a>';
    } else if (hasLine) {
      lineWrap.textContent = d.line_id;
    } else {
      lineWrap.textContent = '';
    }
  }
  populateLeadCaseForm(d);
  const nextPlanTxt = pdVal(d.next_plan);
  const nextDateTxt = d.next_plan_date ? ('กำหนด ' + pdFmtDate(d.next_plan_date)) : '';
  const nextWrap = document.getElementById('ld-case-next-wrap');
  const nextDateEl = document.getElementById('ld-next-date');
  if (nextWrap) {
    if (nextPlanTxt || nextDateTxt) {
      nextWrap.classList.remove('hidden');
      pdSet('ld-next-plan', nextPlanTxt);
      if (nextDateEl) nextDateEl.textContent = nextDateTxt;
    } else {
      nextWrap.classList.add('hidden');
      pdSet('ld-next-plan', '');
      if (nextDateEl) nextDateEl.textContent = '';
    }
  }

  const overviewCurrent = document.getElementById('ld-overview-current');
  if (overviewCurrent) {
    const upd = leadLatestHistoryNote(d) || pdVal(d.current_update);
    overviewCurrent.textContent = upd || 'ยังไม่มีข้อมูลอัปเดต';
    overviewCurrent.classList.toggle('text-[var(--faint)]', !upd);
    overviewCurrent.classList.toggle('italic', !upd);
  }
  const overviewNextWrap = document.getElementById('ld-overview-next-wrap');
  const overviewNext = document.getElementById('ld-overview-next');
  const overviewNextDate = document.getElementById('ld-overview-next-date');
  if (overviewNextWrap && overviewNext) {
    if (nextPlanTxt || nextDateTxt) {
      overviewNextWrap.classList.remove('hidden');
      overviewNext.textContent = nextPlanTxt || '—';
      if (overviewNextDate) overviewNextDate.textContent = nextDateTxt;
    } else {
      overviewNextWrap.classList.add('hidden');
      overviewNext.textContent = '';
      if (overviewNextDate) overviewNextDate.textContent = '';
    }
  }

  const grade = document.getElementById('ld-grade');
  const pm = d.potential_meta;
  if (grade) {
    if (pm) {
      grade.classList.remove('hidden');
      grade.innerHTML = '<i data-lucide="' + pdEsc(pm.icon) + '" class="w-3 h-3"></i><span>' + pdEsc(pm.grade) + '</span>';
      grade.className = 'inline-flex items-center gap-1 text-[10px] font-bold px-2 py-1 rounded-full shrink-0 ' + (pm.class || '');
    } else {
      grade.classList.remove('hidden');
      grade.innerHTML = '<i data-lucide="gauge" class="w-3 h-3"></i><span>Potential · ยังไม่ระบุ</span>';
      grade.className = 'inline-flex items-center gap-1 text-[10px] font-bold px-2 py-1 rounded-full shrink-0 bg-[var(--card)] text-[var(--muted)] border border-[var(--border)]';
    }
  }

  const coverImg = document.getElementById('ld-cover');
  const coverPh = document.getElementById('ld-cover-ph');
  if (d.chat_url) {
    coverImg.src = d.chat_url;
    coverImg.classList.remove('hidden');
    coverPh.classList.add('hidden');
    coverImg.onerror = () => { coverImg.classList.add('hidden'); coverPh.classList.remove('hidden'); };
  } else {
    coverImg.classList.add('hidden');
    coverPh.classList.remove('hidden');
  }
  pdShowLink('ld-photos-link', d.chat_photos_link);

  const pipeEl = document.getElementById('ld-pipeline');
  pipeEl.innerHTML = '';
  const steps = (d.pipeline || []);
  const latestOut = d.stage_outcome_latest || {};
  const currentStage = (steps.includes(d.status) ? d.status : (steps.includes(d.pipeline_current_stage) ? d.pipeline_current_stage : ''));
  const curIdx = currentStage ? steps.indexOf(currentStage) : -1;
  steps.forEach((step, i) => {
    const meta = LD_PIPELINE_META[step] || { label: step, icon: 'circle' };
    const isCur = step === currentStage;
    const isPast = curIdx >= 0 && i < curIdx && (d.has_stage_events ? latestOut[step] === 'yes' : true);
    const cls = isCur
      ? ('border-[var(--border)] bg-[var(--surface)] font-bold ' + (d.status_class || ''))
      : (isPast ? 'border-[var(--border)] bg-[var(--surface)] text-[var(--text-2)]' : 'border-[var(--border)] bg-[var(--card)] text-[var(--faint)]');
    const el = document.createElement('div');
    el.className = 'shrink-0 flex flex-col items-center gap-0.5 px-2 py-1.5 rounded-lg border text-[9px] min-w-[52px] ' + cls;
    el.innerHTML = '<i data-lucide="' + meta.icon + '" class="w-3.5 h-3.5"></i><span>' + pdEsc(meta.label) + '</span>';
    pipeEl.appendChild(el);
  });
  const sm = d.status_meta || {};
  const statusNow = document.getElementById('ld-status-now');
  if (d.is_terminal) {
    statusNow.innerHTML = '<i data-lucide="' + (sm.icon || 'ban') + '" class="w-3.5 h-3.5"></i> สถานะ: ' + pdEsc(sm.label || d.status);
  } else {
    statusNow.innerHTML = '<i data-lucide="' + (sm.icon || 'circle') + '" class="w-3.5 h-3.5"></i> อยู่ที่: ' + pdEsc(sm.label || d.status);
  }
  statusNow.className = 'mt-2 text-xs font-bold flex items-center gap-1.5 ' + (d.status_class || '');

  const matrixWrap = document.getElementById('ld-mini-matrix-wrap');
  const matrixEl = document.getElementById('ld-mini-matrix');
  if (matrixWrap && matrixEl) {
    matrixEl.innerHTML = '';
    const hasMatrix = d.has_stage_events && Object.keys(latestOut).length > 0;
    if (hasMatrix) {
      matrixWrap.classList.remove('hidden');
      steps.forEach(step => {
        if (!latestOut[step]) return;
        const out = latestOut[step];
        const meta = LD_PIPELINE_META[step] || { label: step, icon: 'circle' };
        let outLabel = 'Yes';
        let outIcon = 'check';
        if (out === 'lose') { outLabel = 'Lose'; outIcon = 'user-x'; }
        else if (out === 'reject') { outLabel = 'Reject'; outIcon = 'ban'; }
        else if (out === 'hold') { outLabel = 'Hold'; outIcon = 'pause-circle'; }
        else if (step === 'Win') { outLabel = 'Win'; outIcon = 'trophy'; }
        const cell = document.createElement('div');
        cell.className = 'rounded-lg border border-[var(--border)] bg-[var(--surface)] px-2 py-1.5 text-[9px]';
        cell.innerHTML = '<p class="font-bold flex items-center gap-1 mb-0.5">'
          + '<i data-lucide="' + pdEsc(meta.icon) + '" class="w-3 h-3"></i>' + pdEsc(meta.label) + '</p>'
          + '<p class="flex items-center gap-1 text-[var(--text-2)]">'
          + '<i data-lucide="' + outIcon + '" class="w-3 h-3"></i>' + pdEsc(outLabel) + '</p>';
        matrixEl.appendChild(cell);
      });
    } else {
      matrixWrap.classList.add('hidden');
    }
  }

  const winSec = document.getElementById('ld-win-section');
  if (d.is_win || d.win_date || d.win_price) {
    winSec.classList.remove('hidden');
    const scopeMeta = d.win_close_scope_meta || { label: 'จบหลังนี้', icon: 'home' };
    const scopeEl = document.getElementById('ld-win-scope');
    if (scopeEl) {
      scopeEl.innerHTML = '<i data-lucide="' + pdEsc(scopeMeta.icon || 'home') + '" class="w-3.5 h-3.5" aria-hidden="true"></i> '
        + pdEsc(scopeMeta.label || 'จบหลังนี้');
    }
    const isOther = d.win_close_scope === 'other';
    pdSetGridPair('ld-win-other-project-label', 'ld-win-other-project', isOther ? pdVal(d.close_project) : '');
    pdSetGridPair('ld-win-other-code-label', 'ld-win-other-code', isOther ? pdVal(d.close_owner_code) : '');
    const openTxt = isOther ? (d.close_open_price_fmt || d.close_open_price || '') : '';
    pdSetGridPair('ld-win-open-price-label', 'ld-win-open-price', openTxt);
    pdSet('ld-win-date', pdFmtDate(d.win_date));
    pdSet('ld-win-price', d.win_price || '-');
    const payLbl = document.getElementById('ld-win-payment-label');
    const payVal = document.getElementById('ld-win-payment');
    const payTxt = d.win_payment_label || '';
    if (payLbl && payVal) {
      const showPay = payTxt !== '';
      payLbl.classList.toggle('hidden', !showPay);
      payVal.classList.toggle('hidden', !showPay);
      payVal.textContent = showPay ? payTxt : '-';
    }
    const revLbl = document.getElementById('ld-win-revenue-label');
    const revVal = document.getElementById('ld-win-revenue');
    const revTxt = pdVal(d.revenue);
    if (revLbl && revVal) {
      const showRev = revTxt !== '';
      revLbl.classList.toggle('hidden', !showRev);
      revVal.classList.toggle('hidden', !showRev);
      revVal.textContent = showRev ? revTxt : '-';
    }
  } else {
    winSec.classList.add('hidden');
  }

  renderLdHistory(d);

  populateLeadEditForm(d);
  lucide.createIcons();
}

function openLeadDetail(id) {
  const d = leadDetails[id];
  if (!d) return;
  renderLeadDetail(d);
  leadDetail.classList.remove('hidden');
  document.body.style.overflow = 'hidden';
}

function openLeadDetailInGroup(id) {
  openLeadDetail(id);
  if (ldGroupPanelOpen) {
    const d = leadDetails[id];
    if (d) renderLeadGroupList(d, leadGroupSiblings(d));
    if (window.lucide) lucide.createIcons();
  }
}

function closeLeadDetail() {
  leadDetail.classList.add('hidden');
  document.body.style.overflow = '';
  setLeadGroupPanelOpen(false);
}
document.getElementById('lead-detail-close').addEventListener('click', closeLeadDetail);
document.getElementById('lead-detail-backdrop').addEventListener('click', closeLeadDetail);
document.getElementById('ld-group-badge')?.addEventListener('click', toggleLeadGroupPanel);
document.getElementById('ld-group-list')?.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-lead-id]');
  if (!btn) return;
  const id = parseInt(btn.dataset.leadId, 10);
  if (!id || id === currentLeadId) return;
  openLeadDetailInGroup(id);
});

document.getElementById('ld-case-potential')?.addEventListener('change', updateLdPotentialHint);

document.getElementById('ld-aux-tag-picker')?.addEventListener('change', () => {
  updateLdAgentFieldsVisibility();
  lucide.createIcons();
});

document.getElementById('ld-agent-phone-last4')?.addEventListener('input', (e) => {
  ldAgentPhoneInputNormalize(e.target);
});

document.getElementById('ld-case-edit-btn')?.addEventListener('click', () => {
  if (!currentLeadId) return;
  setLdCaseMode('edit');
});

document.getElementById('ld-case-cancel-btn')?.addEventListener('click', () => {
  if (!currentLeadId) return;
  const d = leadDetails[currentLeadId];
  if (d) populateLeadCaseForm(d);
  else setLdCaseMode('view');
});

document.getElementById('ld-edit-stage')?.addEventListener('change', () => {
  // outcome options depend on stage
  const stageEl = document.getElementById('ld-edit-stage');
  const outcomeEl = document.getElementById('ld-edit-outcome');
  const d = currentLeadId ? (leadDetails[currentLeadId] || {}) : {};
  const { stage, outcome } = deriveStageOutcomeFromLead(d);
  if (stageEl) populateLeadStageSelect(stageEl.value);
  if (stageEl && outcomeEl) {
    populateLeadOutcomeSelect(stageEl.value, outcomeEl.value || outcome);
  }
  updateLdTaskHint();
});
document.getElementById('ld-edit-outcome')?.addEventListener('change', updateLdTaskHint);

document.getElementById('ld-edit-next-date')?.addEventListener('change', function () {
  const val = this.value;
  if (!val) return;
  const today = new Date().toISOString().slice(0, 10);
  if (val <= today) {
    const ed = document.getElementById('ld-edit-event-date');
    if (ed) setAppDateValue(ed, val);
  }
});

document.getElementById('ld-revive-btn')?.addEventListener('click', () => {
  const stageEl = document.getElementById('ld-edit-stage');
  const outcomeEl = document.getElementById('ld-edit-outcome');
  const note = document.getElementById('ld-edit-update');
  if (stageEl) {
    stageEl.value = 'Follow';
    populateLeadOutcomeSelect('Follow', 'yes');
  }
  if (outcomeEl) outcomeEl.value = 'yes';
  ldNextPlanDraft = { text: '', date: '' };
  if (note) {
    note.placeholder = 'เหตุผลที่ดึงกลับมา (เช่น ลูกค้ากลับมาตอบ / กู้ผ่านแล้ว)';
    note.focus();
  }
  updateLdTaskHint();
});

document.getElementById('ld-history-more')?.addEventListener('click', () => {
  if (!currentLeadId) return;
  const d = leadDetails[currentLeadId];
  if (!d) return;
  ldHistoryExpanded = !ldHistoryExpanded;
  if (ldHistoryExpanded) ldHistoryPage = 0;
  renderLdHistory(d);
  lucide.createIcons();
});

document.getElementById('ld-history-prev')?.addEventListener('click', () => {
  if (!currentLeadId || ldHistoryPage <= 0) return;
  ldHistoryPage--;
  const d = leadDetails[currentLeadId];
  if (d) {
    renderLdHistory(d);
    lucide.createIcons();
  }
});

document.getElementById('ld-history-next')?.addEventListener('click', () => {
  if (!currentLeadId) return;
  const d = leadDetails[currentLeadId];
  if (!d) return;
  const total = buildLeadHistoryItems(d).length;
  const pageCount = Math.ceil(total / LD_HISTORY_PAGE_SIZE);
  if (ldHistoryPage >= pageCount - 1) return;
  ldHistoryPage++;
  renderLdHistory(d);
  lucide.createIcons();
});

document.getElementById('ld-status-history')?.addEventListener('click', (e) => {
  const btn = e.target.closest('.ld-history-del');
  if (!btn || ldSavingActive) return;
  const kind = btn.dataset.kind;
  const itemId = parseInt(btn.dataset.itemId || '0', 10);
  if (!currentLeadId || !itemId || !kind) return;
  deleteLeadHistoryItem(kind, itemId, currentLeadId);
});

document.getElementById('lead-case-form')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  if (ldSavingActive) return;
  if (getLdAuxTagSelected() === 'agent') {
    const check = ldAgentFieldsValid();
    if (!check.ok) {
      alert(check.msg);
      return;
    }
  }
  setLdSaving(true, 'กำลังบันทึกข้อมูลเคส…');
  const fd = new FormData(e.target);
  stripFmtNumFormData(fd, e.target);
  fd.append('ajax', 'lead_save_case');
  try {
    const res = await fetch('dashboard.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success && data.lead) {
      applyLeadDataUpdate(data.lead);
      setLdCaseMode('view');
      showSaveToast(data.message || 'บันทึกข้อมูลเคสแล้ว');
    } else {
      alert(data.message || 'บันทึกไม่สำเร็จ');
    }
  } catch (err) {
    alert('เชื่อมต่อไม่สำเร็จ');
  } finally {
    setLdSaving(false);
  }
});

document.getElementById('lead-update-form')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  if (ldSavingActive) return;
  syncAllAppDateInputs();
  setLdSaving(true);
  const fd = new FormData(e.target);
  fd.append('ajax', 'lead_save');
  try {
    const res = await fetch('dashboard.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success && data.lead) {
      applyLeadDataUpdate(data.lead);
      showSaveToast(data.message || 'บันทึกสำเร็จ');
    } else {
      alert(data.message || 'บันทึกไม่สำเร็จ');
    }
  } catch (err) {
    alert('เชื่อมต่อไม่สำเร็จ');
  } finally {
    setLdSaving(false);
  }
});

document.getElementById('product-edit-open').addEventListener('click', openProductEdit);
document.getElementById('product-edit-close').addEventListener('click', closeProductEdit);
document.getElementById('product-edit-backdrop').addEventListener('click', closeProductEdit);

document.getElementById('product-edit-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  if (!currentProductId) return;
  const btn = document.querySelector('button[form="product-edit-form"]');
  const orig = btn.textContent;
  btn.disabled = true;
  btn.textContent = 'กำลังบันทึก…';
  const fd = new FormData();
  fd.append('ajax', 'owner_save');
  fd.append('owner_id', currentProductId);
  fd.append('owner_name', document.getElementById('edit-owner-name').value);
  fd.append('name_en', document.getElementById('edit-name-en').value);
  fd.append('name_th', document.getElementById('edit-name-th').value);
  fd.append('phone', document.getElementById('edit-phone').value);
  fd.append('line_id', document.getElementById('edit-line-id').value);
  fd.append('property_type', document.getElementById('edit-type').value);
  fd.append('zone', document.getElementById('edit-zone').value);
  fd.append('soi', document.getElementById('edit-soi').value);
  fd.append('unit_no', document.getElementById('edit-unit').value);
  fd.append('floor', document.getElementById('edit-floor').value);
  fd.append('direction', document.getElementById('edit-direction').value);
  fd.append('map_url', document.getElementById('edit-map').value);
  fd.append('price', stripNum(document.getElementById('edit-price').value));
  fd.append('rent', stripNum(document.getElementById('edit-rent').value));
  fd.append('owner_price', stripNum(document.getElementById('edit-owner-price').value));
  fd.append('sales_status', document.getElementById('edit-sales-status').value);
  fd.append('owner_urgency', document.getElementById('edit-urgency').value);
  fd.append('transfer_fee', document.getElementById('edit-transfer').value);
  fd.append('last_contact', document.getElementById('edit-last-contact').value);
  fd.append('contact_summary', document.getElementById('edit-contact-summary').value);
  fd.append('price_consult', document.getElementById('edit-price-consult').value);
  fd.append('listing_source', document.getElementById('edit-listing-source').value);
  fd.append('marketing_status', document.getElementById('edit-marketing-status').value);
  fd.append('incomplete', document.getElementById('edit-incomplete').value);
  fd.append('has_deed', document.getElementById('edit-has-deed').value);
  fd.append('cover_image_url', document.getElementById('edit-cover-url').value);
  fd.append('photos_link', document.getElementById('edit-photos-link').value);
  try {
    const res = await fetch('dashboard.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success && data.owner) {
      ownerDetails[currentProductId] = data.owner;
      closeProductEdit(true);
      openProductDetail(currentProductId);
    } else {
      alert(data.message || 'บันทึกไม่สำเร็จ');
    }
  } catch (err) {
    alert('เชื่อมต่อไม่สำเร็จ');
  } finally {
    btn.disabled = false;
    btn.textContent = orig;
  }
});

const dateInput = document.getElementById('new-task-date');
const timeInput = document.getElementById('new-task-time');

function updatePickLabels() {
  const ds = dateInput.value;
  const dateLabel = document.getElementById('pick-date-label');
  if (ds === TODAY_STR) { dateLabel.textContent = 'วันนี้'; }
  else if (ds) {
    const [y, m, d] = ds.split('-').map(Number);
    dateLabel.textContent = d + ' ' + thaiMonths[m - 1];
  } else { dateLabel.textContent = 'วันนี้'; }

  const timeLabel = document.getElementById('pick-time-label');
  const clearBtn = document.getElementById('clear-time-btn');
  if (timeInput.value) {
    timeLabel.textContent = timeInput.value + ' น.';
    timeLabel.classList.replace('text-[var(--faint)]', 'text-[var(--text-2)]');
    clearBtn.classList.remove('hidden');
  } else {
    timeLabel.textContent = 'เวลาแจ้งเตือน';
    timeLabel.classList.replace('text-[var(--text-2)]', 'text-[var(--faint)]');
    clearBtn.classList.add('hidden');
  }
}
document.getElementById('pick-date-btn').addEventListener('click', () => {
  openTaskDateSheet(dateInput.value || TODAY_STR, (ds) => {
    dateInput.value = ds || TODAY_STR;
    updatePickLabels();
  });
});
dateInput.addEventListener('change', updatePickLabels);

// แผงเลือกเวลา 24 ชม. — พิมพ์ตัวเลขได้เลย (มือถือเด้งแป้นตัวเลข) เคาะครบ 2 หลักเด้งไปช่องนาที
const timePanel = document.getElementById('time-panel');
const timeHour  = document.getElementById('time-hour');
const timeMin   = document.getElementById('time-min');

// อ่านค่าจากช่องพิมพ์ บีบให้อยู่ในช่วงที่ถูกต้อง (ชม. 0-23, นาที 0-59)
function normTime() {
  let h = parseInt(timeHour.value, 10); if (isNaN(h) || h < 0) h = 0; if (h > 23) h = 23;
  let m = parseInt(timeMin.value, 10);  if (isNaN(m) || m < 0) m = 0; if (m > 59) m = 59;
  return [String(h).padStart(2, '0'), String(m).padStart(2, '0')];
}
function applyTimeFromInputs() {
  const [h, m] = normTime();
  timeInput.value = h + ':' + m;
  updatePickLabels();
}
document.getElementById('pick-time-btn').addEventListener('click', () => {
  const opening = timePanel.classList.contains('hidden');
  timePanel.classList.toggle('hidden', !opening);
  timePanel.classList.toggle('flex', opening);
  if (opening) {
    if (timeInput.value) { // มีค่าเดิม ให้ตั้งช่องตาม
      const [h, m] = timeInput.value.split(':');
      timeHour.value = h;
      timeMin.value  = m;
    }
    applyTimeFromInputs();
    timeHour.focus();
    timeHour.select();
  }
});
timeHour.addEventListener('input', () => {
  timeHour.value = timeHour.value.replace(/\D/g, ''); // รับเฉพาะตัวเลข
  if (timeHour.value.length >= 2) { timeMin.focus(); timeMin.select(); }
  applyTimeFromInputs();
});
timeMin.addEventListener('input', () => {
  timeMin.value = timeMin.value.replace(/\D/g, '');
  applyTimeFromInputs();
});
[timeHour, timeMin].forEach(el => {
  el.addEventListener('focus', () => el.select()); // แตะแล้วเลือกทั้งหมด พิมพ์ทับได้เลย
  el.addEventListener('blur', () => { const [h, m] = normTime(); timeHour.value = h; timeMin.value = m; });
});
document.getElementById('time-ok').addEventListener('click', () => {
  const [h, m] = normTime();
  timeHour.value = h;
  timeMin.value  = m;
  applyTimeFromInputs();
  timePanel.classList.add('hidden');
  timePanel.classList.remove('flex');
});
document.getElementById('clear-time-btn').addEventListener('click', () => {
  timeInput.value = '';
  timePanel.classList.add('hidden');
  timePanel.classList.remove('flex');
  updatePickLabels();
});

// เลือกระดับความสำคัญ (Eisenhower) — กดซ้ำเพื่อยกเลิก
document.querySelectorAll('.prio-chip').forEach(chip => {
  chip.addEventListener('click', () => {
    const hidden = document.getElementById('new-task-priority');
    const isActive = chip.classList.contains('border-[#E2E800]');
    document.querySelectorAll('.prio-chip').forEach(c => c.classList.remove('border-[#E2E800]', 'bg-[#E2E800]/10'));
    if (isActive) { hidden.value = '0'; }
    else {
      chip.classList.add('border-[#E2E800]', 'bg-[#E2E800]/10');
      hidden.value = chip.dataset.priority;
    }
  });
});

document.getElementById('add-task-form').addEventListener('submit', (e) => e.preventDefault());
document.getElementById('add-task-submit')?.addEventListener('click', submitNewTask);
document.getElementById('new-task-title')?.addEventListener('input', (e) => {
  const btn = document.getElementById('add-task-submit');
  if (btn) btn.disabled = !e.target.value.trim();
});
document.getElementById('new-task-title')?.addEventListener('keydown', (e) => {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    submitNewTask();
  }
});

// ===== ปฏิทินหน้า Tasks (สไตล์ TickTick) =====
const thaiMonths = ['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];

let calYear  = parseInt(TODAY_STR.slice(0, 4), 10);
let calMonth = parseInt(TODAY_STR.slice(5, 7), 10) - 1; // 0-11
let selectedDate = null;
let calCollapsed = false;
let calWeekAnchor = TODAY_STR;

const thaiMonthsShort = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];

function isTasksTabActive() {
  const page = document.getElementById('page-tasks');
  return page && !page.classList.contains('hidden');
}

function getWeekStart(ds) {
  const d = new Date(ds + 'T12:00:00');
  d.setDate(d.getDate() - d.getDay());
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return y + '-' + m + '-' + day;
}

function formatWeekRangeTitle(anchorDs) {
  const start = getWeekStart(anchorDs);
  const end = addDaysToDateStr(start, 6);
  const [sy, sm, sd] = start.split('-').map(Number);
  const [ey, em, ed] = end.split('-').map(Number);
  const smLabel = thaiMonthsShort[sm - 1];
  const emLabel = thaiMonthsShort[em - 1];
  if (sm === em && sy === ey) return sd + '–' + ed + ' ' + smLabel + ' ' + sy;
  if (sy === ey) return sd + ' ' + smLabel + ' – ' + ed + ' ' + emLabel + ' ' + sy;
  return sd + ' ' + smLabel + ' ' + sy + ' – ' + ed + ' ' + emLabel + ' ' + ey;
}

function syncCalGrabUi() {
  const grab = document.getElementById('task-cal-grab');
  if (!grab) return;
  grab.setAttribute('aria-expanded', calCollapsed ? 'false' : 'true');
  const hint = grab.querySelector('span');
  if (hint) hint.textContent = calCollapsed ? 'ปัดขึ้นเพื่อขยาย' : 'ปัดลงเพื่อย่อ';
}

function setCalCollapsed(next) {
  next = !!next;
  if (calCollapsed === next) return;
  calCollapsed = next;
  const el = document.getElementById('task-cal');
  if (el) el.classList.toggle('task-cal--collapsed', calCollapsed);
  if (calCollapsed) calWeekAnchor = selectedDate || TODAY_STR;
  syncCalGrabUi();
  renderCalendar();
}

function calNavPrev() {
  if (calCollapsed) {
    calWeekAnchor = addDaysToDateStr(calWeekAnchor, -7);
    calYear = parseInt(calWeekAnchor.slice(0, 4), 10);
    calMonth = parseInt(calWeekAnchor.slice(5, 7), 10) - 1;
  } else {
    calMonth--; if (calMonth < 0) { calMonth = 11; calYear--; }
  }
  renderCalendar();
}

function calNavNext() {
  if (calCollapsed) {
    calWeekAnchor = addDaysToDateStr(calWeekAnchor, 7);
    calYear = parseInt(calWeekAnchor.slice(0, 4), 10);
    calMonth = parseInt(calWeekAnchor.slice(5, 7), 10) - 1;
  } else {
    calMonth++; if (calMonth > 11) { calMonth = 0; calYear++; }
  }
  renderCalendar();
}

function calGoToday() {
  calYear  = parseInt(TODAY_STR.slice(0, 4), 10);
  calMonth = parseInt(TODAY_STR.slice(5, 7), 10) - 1;
  calWeekAnchor = TODAY_STR;
  renderCalendar();
}

function buildCalDayButton(d, y, m, faded) {
  const ds = dateStr(y, m, d);
  const isToday    = ds === TODAY_STR;
  const isSelected = ds === selectedDate;
  const info = taskDates[ds];

  const btn = document.createElement('button');
  btn.type = 'button';
  btn.dataset.date = ds;
  btn.className = 'relative w-10 h-10 mx-auto flex items-center justify-center text-xs rounded-xl transition '
    + (isToday ? 'bg-[#E2E800] text-[#141414] font-bold shadow-sm '
      : isSelected ? 'bg-[var(--surface)] text-[var(--text)] font-bold ring-2 ring-[var(--accent-text)] '
      : faded ? 'text-[var(--border-2)] '
      : 'text-[var(--text-2)] hover:bg-[var(--surface)] active:scale-95 ');
  btn.textContent = d;

  if (info) {
    const dot = document.createElement('span');
    dot.className = 'absolute bottom-1 left-1/2 -translate-x-1/2 flex gap-0.5';
    if (info.pending > 0) {
      const p = document.createElement('span');
      p.className = 'w-1 h-1 rounded-full ' + (isToday ? 'bg-[#141414]' : 'bg-[#E2E800]');
      p.title = 'ค้าง ' + info.pending;
      dot.appendChild(p);
    }
    if (info.done > 0) {
      const d2 = document.createElement('span');
      d2.className = 'w-1 h-1 rounded-full bg-emerald-500';
      d2.title = 'เสร็จ ' + info.done;
      dot.appendChild(d2);
    }
    btn.appendChild(dot);
  }

  btn.addEventListener('click', () => selectCalDate(ds));
  return btn;
}

let pickerCalYear = calYear;
let pickerCalMonth = calMonth;
let pickerSelected = null;
let pickerOnSelect = null;
let appDatePickerActiveWrap = null;

function openAppDatePicker(initialDate, onSelect, opts) {
  if (opts?.wrap) {
    appDatePickerActiveWrap = opts.wrap;
    appDatePickerActiveWrap.classList.add('app-date-wrap--open');
  }
  openTaskDateSheet(initialDate, (iso) => {
    if (appDatePickerActiveWrap) {
      appDatePickerActiveWrap.classList.remove('app-date-wrap--open');
      appDatePickerActiveWrap = null;
    }
    if (typeof onSelect === 'function') onSelect(iso);
  });
}

function closeTaskDateSheet() {
  document.getElementById('task-date-sheet')?.classList.add('hidden');
  if (appDatePickerActiveWrap) {
    appDatePickerActiveWrap.classList.remove('app-date-wrap--open');
    appDatePickerActiveWrap = null;
  }
  pickerOnSelect = null;
}

function openTaskDateSheet(initialDate, onSelect) {
  pickerOnSelect = onSelect;
  const ds = initialDate || TODAY_STR;
  pickerCalYear = parseInt(ds.slice(0, 4), 10);
  pickerCalMonth = parseInt(ds.slice(5, 7), 10) - 1;
  pickerSelected = ds;
  renderPickerCalendar();
  document.getElementById('task-date-sheet')?.classList.remove('hidden');
  if (window.lucide) lucide.createIcons();
}

function renderPickerCalendar() {
  const titleEl = document.querySelector('#picker-cal-title span');
  if (titleEl) titleEl.textContent = thaiMonths[pickerCalMonth] + ' ' + pickerCalYear;
  const grid = document.getElementById('picker-cal-grid');
  if (!grid) return;
  grid.innerHTML = '';
  const firstDow = new Date(pickerCalYear, pickerCalMonth, 1).getDay();
  const daysInMonth = new Date(pickerCalYear, pickerCalMonth + 1, 0).getDate();
  const daysInPrev = new Date(pickerCalYear, pickerCalMonth, 0).getDate();
  const totalCells = Math.ceil((firstDow + daysInMonth) / 7) * 7;
  for (let i = 0; i < totalCells; i++) {
    let d, y = pickerCalYear, m = pickerCalMonth, faded = false;
    if (i < firstDow) {
      d = daysInPrev - firstDow + 1 + i;
      m = pickerCalMonth - 1; if (m < 0) { m = 11; y--; }
      faded = true;
    } else if (i >= firstDow + daysInMonth) {
      d = i - firstDow - daysInMonth + 1;
      m = pickerCalMonth + 1; if (m > 11) { m = 0; y++; }
      faded = true;
    } else {
      d = i - firstDow + 1;
    }
    const ds = dateStr(y, m, d);
    const isToday = ds === TODAY_STR;
    const isSel = ds === pickerSelected;
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'relative w-10 h-10 mx-auto flex items-center justify-center text-xs rounded-xl transition '
      + (isSel ? 'bg-[#E2E800] text-[#141414] font-bold ring-2 ring-[#E2E800]/40 '
        : isToday ? 'bg-[var(--surface)] text-[var(--accent-text)] font-bold border border-[#E2E800]/50 '
        : faded ? 'text-[var(--border-2)] '
        : 'text-[var(--text-2)] hover:bg-[var(--surface)] active:bg-[var(--surface)]');
    btn.textContent = d;
    if (!faded) {
      btn.addEventListener('click', async () => {
        pickerSelected = ds;
        if (typeof pickerOnSelect === 'function') {
          const fn = pickerOnSelect;
          closeTaskDateSheet();
          await fn(ds);
        }
      });
    }
    grid.appendChild(btn);
  }
}

document.getElementById('task-date-sheet-backdrop')?.addEventListener('click', closeTaskDateSheet);
document.getElementById('picker-cal-prev')?.addEventListener('click', () => {
  pickerCalMonth--; if (pickerCalMonth < 0) { pickerCalMonth = 11; pickerCalYear--; }
  renderPickerCalendar();
});
document.getElementById('picker-cal-next')?.addEventListener('click', () => {
  pickerCalMonth++; if (pickerCalMonth > 11) { pickerCalMonth = 0; pickerCalYear++; }
  renderPickerCalendar();
});
document.getElementById('picker-cal-today')?.addEventListener('click', async () => {
  if (typeof pickerOnSelect === 'function') {
    const fn = pickerOnSelect;
    closeTaskDateSheet();
    await fn(TODAY_STR);
  }
});
document.getElementById('picker-cal-clear')?.addEventListener('click', async () => {
  if (typeof pickerOnSelect === 'function') {
    const fn = pickerOnSelect;
    closeTaskDateSheet();
    await fn('');
  }
});

function dateStr(y, m, d) {
  return y + '-' + String(m + 1).padStart(2, '0') + '-' + String(d).padStart(2, '0');
}

function renderCalendar() {
  const weekStartFocus = getWeekStart(calCollapsed ? calWeekAnchor : (selectedDate || TODAY_STR));
  document.getElementById('cal-title').textContent = calCollapsed
    ? formatWeekRangeTitle(calWeekAnchor)
    : (thaiMonths[calMonth] + ' ' + calYear);
  const grid = document.getElementById('cal-grid');
  grid.innerHTML = '';

  const firstDow = new Date(calYear, calMonth, 1).getDay();      // 0 = อาทิตย์
  const daysInMonth = new Date(calYear, calMonth + 1, 0).getDate();
  const daysInPrev  = new Date(calYear, calMonth, 0).getDate();
  const totalCells  = Math.ceil((firstDow + daysInMonth) / 7) * 7;

  let week = [];
  for (let i = 0; i < totalCells; i++) {
    let d, y = calYear, m = calMonth, faded = false;
    if (i < firstDow) {
      d = daysInPrev - firstDow + 1 + i;
      m = calMonth - 1; if (m < 0) { m = 11; y--; }
      faded = true;
    } else if (i >= firstDow + daysInMonth) {
      d = i - firstDow - daysInMonth + 1;
      m = calMonth + 1; if (m > 11) { m = 0; y++; }
      faded = true;
    } else {
      d = i - firstDow + 1;
    }

    week.push({ d, y, m, faded });
    if (week.length === 7) {
      const inFocusWeek = week.some(cell => getWeekStart(dateStr(cell.y, cell.m, cell.d)) === weekStartFocus);
      const row = document.createElement('div');
      row.className = 'cal-week-row grid grid-cols-7 gap-x-0.5' + (calCollapsed && !inFocusWeek ? ' hidden' : '');
      week.forEach(cell => row.appendChild(buildCalDayButton(cell.d, cell.y, cell.m, cell.faded)));
      grid.appendChild(row);
      week = [];
    }
  }
  syncCalGrabUi();
}

function selectCalDate(ds) {
  selectedDate = (selectedDate === ds) ? null : ds;
  if (selectedDate) {
    calYear  = parseInt(ds.slice(0, 4), 10);
    calMonth = parseInt(ds.slice(5, 7), 10) - 1;
    calWeekAnchor = ds;
    document.getElementById('new-task-date').value = ds;
  } else {
    document.getElementById('new-task-date').value = TODAY_STR;
  }
  updatePickLabels();
  renderCalendar();
  applyCalFilter();
}

function applyCalFilter() {
  document.querySelectorAll('.task-item').forEach(li => {
    li.classList.toggle('hidden', selectedDate !== null && li.dataset.due !== selectedDate);
  });
  // ซ่อนหัวข้อกลุ่มที่ไม่เหลือรายการให้แสดง
  document.querySelectorAll('.task-group').forEach(g => {
    const visible = g.querySelectorAll('.task-item:not(.hidden)').length;
    g.classList.toggle('hidden', visible === 0);
  });
}

document.getElementById('cal-prev').addEventListener('click', calNavPrev);
document.getElementById('cal-next').addEventListener('click', calNavNext);
document.getElementById('cal-today').addEventListener('click', calGoToday);

const taskCalEl = document.getElementById('task-cal');
const taskCalGrab = document.getElementById('task-cal-grab');
let taskCalTouchY = 0;

taskCalGrab?.addEventListener('click', () => setCalCollapsed(!calCollapsed));

taskCalEl?.addEventListener('touchstart', (e) => {
  taskCalTouchY = e.touches[0].clientY;
}, { passive: true });

taskCalEl?.addEventListener('touchend', (e) => {
  const dy = e.changedTouches[0].clientY - taskCalTouchY;
  if (dy > 36) setCalCollapsed(true);
  else if (dy < -36) setCalCollapsed(false);
}, { passive: true });

window.addEventListener('scroll', () => {
  if (!isTasksTabActive()) return;
  const y = window.scrollY;
  if (y > 56) setCalCollapsed(true);
  else if (y <= 8) setCalCollapsed(false);
}, { passive: true });

renderCalendar();

// ===== สลับธีม Dark / Light =====
const themeToggle = document.getElementById('theme-toggle');
// checked = Dark (ฝั่งพระจันทร์), ไม่ checked = Light (ฝั่งพระอาทิตย์)
themeToggle.checked = !document.documentElement.classList.contains('light');
themeToggle.addEventListener('change', () => {
  const dark = themeToggle.checked;
  document.documentElement.classList.toggle('light', !dark);
  localStorage.setItem('theme', dark ? 'dark' : 'light');
  refreshChartTheme();
});

// ===== กราฟ Pipeline (Chart.js) =====
let pipelineChart = null;

function chartThemeColors() {
  const s = getComputedStyle(document.documentElement);
  return {
    tickX: s.getPropertyValue('--muted').trim(),
    tickY: s.getPropertyValue('--faint').trim(),
    grid:  s.getPropertyValue('--border').trim()
  };
}

function refreshChartTheme() {
  if (!pipelineChart) return;
  const c = chartThemeColors();
  pipelineChart.options.scales.x.ticks.color = c.tickX;
  pipelineChart.options.scales.y.ticks.color = c.tickY;
  pipelineChart.options.scales.y.grid.color = c.grid;
  pipelineChart.update();
}

<?php if ($counts['leads'] > 0): ?>
(() => {
  const c = chartThemeColors();
  pipelineChart = new Chart(document.getElementById('pipelineChart'), {
    type: 'bar',
    data: {
      labels: <?php echo json_encode(array_keys($status_counts)); ?>,
      datasets: [{
        data: <?php echo json_encode(array_values($status_counts)); ?>,
        backgroundColor: <?php echo json_encode(array_map(
            fn($s) => $s === 'Win' ? 'rgba(52, 211, 153, 0.9)' : ($s === 'Call' || $s === 'Follow' ? 'rgba(226, 232, 0, 0.9)' : 'rgba(181, 181, 181, 0.55)'),
            array_keys($status_counts)
        )); ?>,
        borderRadius: 6,
        maxBarThickness: 22
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        x: {
          grid: { display: false },
          ticks: { color: c.tickX, font: { size: 9 } }
        },
        y: {
          beginAtZero: true,
          grid: { color: c.grid },
          ticks: { color: c.tickY, font: { size: 10 }, precision: 0 }
        }
      }
    }
  });
})();
<?php endif; ?>
</script>
<script>
window.MAP_BOOT = {
  data: <?php echo json_encode($map_payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>,
  apiKey: <?php echo json_encode(defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : ''); ?>,
  mapId: ''
};
</script>
<script src="map-page.js?v=<?php echo @filemtime(__DIR__ . '/map-page.js') ?: time(); ?>"></script>
<script>
if (document.getElementById('page-map') && !document.getElementById('page-map').classList.contains('hidden')) {
  requestAnimationFrame(function () { window.mapPageInit?.(); });
}
</script>
</body>
</html>
