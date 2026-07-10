<?php
// api_leads.php
require_once 'config.php';
require_once 'task_helpers.php';
require_once __DIR__ . '/lib/subscription.php';

task_ensure_schema($conn);
subscription_ensure_schema($conn);
lead_stage_events_ensure_schema($conn);
require_once __DIR__ . '/lib/lead_sheet_schema.php';
require_once __DIR__ . '/lib/lead_customer_group.php';
lead_sheet_ensure_schema($conn);
lead_customer_group_ensure_schema($conn);

header('Content-Type: application/json; charset=utf-8');

// รับ LINE User ID จาก Parameter หรือ Header (ในการใช้งานจริงแนะนำให้ส่ง ID Token ไปคุยหลังบ้าน)
$line_id = isset($_REQUEST['line_id']) ? trim($_REQUEST['line_id']) : '';

if (empty($line_id)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing line_id']);
    exit();
}

// ค้นหาข้อมูลผู้ใช้งานในระบบ เพื่อตรวจสอบสิทธิ์
$stmt = $conn->prepare("SELECT id, encryption_key, google_sheet_id, trial_ends_at, is_subscribed, is_lifetime_free, line_user_id FROM users WHERE line_user_id = ? LIMIT 1");
$stmt->bind_param("s", $line_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'User not registered']);
    exit();
}

$deny = subscription_deny_payload($user);
if ($deny) {
    http_response_code(402);
    echo json_encode($deny);
    exit();
}

$encryption_key = $user['encryption_key'];
$user_id = $user['id'];
$method = $_SERVER['REQUEST_METHOD'];

/** แปลง stage events เป็น payload อ่านง่าย */
function api_format_stage_events(array $events, string $encryption_key): array {
    $out = [];
    foreach ($events as $e) {
        $note = '';
        if (!empty($e['note_enc'])) {
            $note = decrypt_data($e['note_enc'], $encryption_key) ?: '';
        }
        $out[] = [
            'id' => (int)($e['id'] ?? 0),
            'stage' => $e['stage'] ?? '',
            'outcome' => $e['outcome'] ?? '',
            'event_date' => $e['event_date'] ?? '',
            'note' => $note,
            'created_at' => $e['created_at'] ?? '',
        ];
    }
    return $out;
}

/** สร้าง lead payload พร้อม resolved status + matrix */
function api_lead_payload(array $row, string $encryption_key, array $stage_events = []): array {
    $resolved = lead_resolve_from_stage_events($row, $stage_events);
    $status = $resolved['status'] ?? ($row['status'] ?? 'Call');
    return [
        'id' => $row['id'],
        'lead_code' => $row['lead_code'],
        'lead_name' => decrypt_data($row['lead_name_enc'], $encryption_key),
        'project' => decrypt_data($row['project_enc'], $encryption_key),
        'phone' => decrypt_data($row['phone_enc'], $encryption_key),
        'line_id' => decrypt_data($row['line_id_enc'], $encryption_key),
        'budget' => decrypt_data($row['budget_enc'], $encryption_key),
        'potential' => $row['potential'],
        'occupation' => decrypt_data($row['occupation_enc'], $encryption_key),
        'contact_date' => $row['contact_date'],
        'target_date' => decrypt_data($row['target_date_enc'], $encryption_key),
        'pain_point' => decrypt_data($row['pain_point_enc'], $encryption_key),
        'requirement' => decrypt_data($row['requirement_enc'], $encryption_key),
        'financials' => decrypt_data($row['financials_enc'], $encryption_key),
        'residents_count' => decrypt_data($row['residents_count_enc'], $encryption_key),
        'parking_count' => decrypt_data($row['parking_count_enc'], $encryption_key),
        'background' => decrypt_data($row['background_enc'], $encryption_key),
        'current_update' => decrypt_data($row['current_update_enc'], $encryption_key),
        'status' => $status,
        'legacy_status' => $row['status'] ?? '',
        'pipeline_current_stage' => $resolved['current_stage'] ?? $status,
        'stage_outcome_latest' => $resolved['stage_outcome_latest'] ?? [],
        'has_stage_events' => !empty($stage_events),
        'stage_events' => api_format_stage_events($resolved['stage_events_sorted'] ?? $stage_events, $encryption_key),
        'next_plan_action' => decrypt_data($row['next_plan_action_enc'], $encryption_key),
        'next_plan_date' => $row['next_plan_date'],
        'owner_code' => $row['owner_code'] ?? '',
        'reserve_date' => $row['reserve_date'] ?? '',
        'win_date' => $row['win_date'] ?? '',
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
    ];
}

if ($method === 'GET') {
    $lead_code = isset($_GET['lead_code']) ? trim($_GET['lead_code']) : '';
    $events_map = lead_stage_events_map_for_user($conn, $user_id);

    if (!empty($lead_code)) {
        $stmt = $conn->prepare("SELECT * FROM leads WHERE user_id = ? AND lead_code = ? LIMIT 1");
        $stmt->bind_param("is", $user_id, $lead_code);
    } else {
        $stmt = $conn->prepare("SELECT * FROM leads WHERE user_id = ? ORDER BY updated_at DESC");
        $stmt->bind_param("i", $user_id);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $leads = [];

    while ($row = $result->fetch_assoc()) {
        $lid = (int)$row['id'];
        $leads[] = api_lead_payload($row, $encryption_key, $events_map[$lid] ?? []);
    }
    $stmt->close();

    echo json_encode(['success' => true, 'data' => (!empty($lead_code) ? ($leads[0] ?? null) : $leads)]);
    exit();

} elseif ($method === 'POST') {
    $input_raw = file_get_contents('php://input');
    $data = json_decode($input_raw, true);
    if (!$data) {
        $data = $_POST;
    }

    $lead_code = trim($data['lead_code'] ?? '');
    $lead_name = trim($data['lead_name'] ?? '');
    $project = trim($data['project'] ?? '');
    $phone = trim($data['phone'] ?? '');
    $line_id_field = trim($data['line_id'] ?? '');
    $budget = trim($data['budget'] ?? '');
    $potential = trim($data['potential'] ?? 'B');
    $occupation = trim($data['occupation'] ?? '');
    $contact_date = !empty($data['contact_date']) ? $data['contact_date'] : date('Y-m-d');
    $target_date = trim($data['target_date'] ?? '');
    $pain_point = trim($data['pain_point'] ?? '');
    $requirement = trim($data['requirement'] ?? '');
    $financials = trim($data['financials'] ?? '');
    $residents_count = trim($data['residents_count'] ?? '');
    $parking_count = trim($data['parking_count'] ?? '');
    $background = trim($data['background'] ?? '');
    $current_update = trim($data['current_update'] ?? '');
    $owner_code = trim($data['owner_code'] ?? '');
    $next_plan_action = trim($data['next_plan_action'] ?? '');
    $next_plan_date = !empty($data['next_plan_date']) ? $data['next_plan_date'] : null;
    $reserve_date = !empty($data['reserve_date']) ? $data['reserve_date'] : null;
    $status_note = trim($data['status_note'] ?? '');

    $stage = trim($data['stage'] ?? '');
    $outcome = trim($data['outcome'] ?? '');
    $status = '';
    $used_matrix = false;

    if ($stage !== '' && $outcome !== '') {
        $valid_stages = lead_funnel_statuses();
        $valid_outcomes = ['yes', 'lose', 'reject', 'hold'];
        if (!in_array($stage, $valid_stages, true) || !in_array($outcome, $valid_outcomes, true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid stage or outcome']);
            exit();
        }
        if ($stage === 'Win' && $outcome !== 'yes') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Win requires outcome=yes']);
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
        $status = trim($data['status'] ?? 'Call');
    }

    if (empty($lead_code) || empty($lead_name)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Lead Code and Lead Name are required']);
        exit();
    }

    $lead_name_enc = encrypt_data($lead_name, $encryption_key);
    $project_enc = encrypt_data($project, $encryption_key);
    $phone_enc = encrypt_data($phone, $encryption_key);
    $line_id_enc = encrypt_data($line_id_field, $encryption_key);
    $budget_enc = encrypt_data($budget, $encryption_key);
    $occupation_enc = encrypt_data($occupation, $encryption_key);
    $target_date_enc = encrypt_data($target_date, $encryption_key);
    $pain_point_enc = encrypt_data($pain_point, $encryption_key);
    $requirement_enc = encrypt_data($requirement, $encryption_key);
    $financials_enc = encrypt_data($financials, $encryption_key);
    $residents_count_enc = encrypt_data($residents_count, $encryption_key);
    $parking_count_enc = encrypt_data($parking_count, $encryption_key);
    $background_enc = encrypt_data($background, $encryption_key);
    $current_update_enc = encrypt_data($current_update, $encryption_key);
    $next_plan_action_enc = encrypt_data($next_plan_action, $encryption_key);

    $stmt = $conn->prepare("SELECT id, status FROM leads WHERE user_id = ? AND lead_code = ? LIMIT 1");
    $stmt->bind_param("is", $user_id, $lead_code);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $old_status = $existing['status'] ?? '';

    $event_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['event_date'] ?? '') ? $data['event_date'] : date('Y-m-d');
    $win_date_sql = ($used_matrix && $stage === 'Win' && $outcome === 'yes') ? $event_date : null;

    if ($existing) {
        if ($win_date_sql) {
            $stmt = $conn->prepare("UPDATE leads SET 
                lead_name_enc = ?, project_enc = ?, phone_enc = ?, line_id_enc = ?, budget_enc = ?, potential = ?, 
                occupation_enc = ?, contact_date = ?, target_date_enc = ?, pain_point_enc = ?, requirement_enc = ?, 
                financials_enc = ?, residents_count_enc = ?, parking_count_enc = ?, background_enc = ?, 
                current_update_enc = ?, status = ?, next_plan_action_enc = ?, next_plan_date = ?,
                owner_code = ?, reserve_date = COALESCE(?, reserve_date), win_date = ?
                WHERE id = ?");
            $stmt->bind_param("sssssssssssssssssssssi",
                $lead_name_enc, $project_enc, $phone_enc, $line_id_enc, $budget_enc, $potential,
                $occupation_enc, $contact_date, $target_date_enc, $pain_point_enc, $requirement_enc,
                $financials_enc, $residents_count_enc, $parking_count_enc, $background_enc,
                $current_update_enc, $status, $next_plan_action_enc, $next_plan_date,
                $owner_code, $reserve_date, $win_date_sql, $existing['id']
            );
        } else {
            $stmt = $conn->prepare("UPDATE leads SET 
                lead_name_enc = ?, project_enc = ?, phone_enc = ?, line_id_enc = ?, budget_enc = ?, potential = ?, 
                occupation_enc = ?, contact_date = ?, target_date_enc = ?, pain_point_enc = ?, requirement_enc = ?, 
                financials_enc = ?, residents_count_enc = ?, parking_count_enc = ?, background_enc = ?, 
                current_update_enc = ?, status = ?, next_plan_action_enc = ?, next_plan_date = ?,
                owner_code = ?, reserve_date = COALESCE(?, reserve_date)
                WHERE id = ?");
            $stmt->bind_param("ssssssssssssssssssssi",
                $lead_name_enc, $project_enc, $phone_enc, $line_id_enc, $budget_enc, $potential,
                $occupation_enc, $contact_date, $target_date_enc, $pain_point_enc, $requirement_enc,
                $financials_enc, $residents_count_enc, $parking_count_enc, $background_enc,
                $current_update_enc, $status, $next_plan_action_enc, $next_plan_date,
                $owner_code, $reserve_date, $existing['id']
            );
        }
    } else {
        $stmt = $conn->prepare("INSERT INTO leads (
            user_id, lead_code, lead_name_enc, project_enc, phone_enc, line_id_enc, budget_enc, potential, 
            occupation_enc, contact_date, target_date_enc, pain_point_enc, requirement_enc, 
            financials_enc, residents_count_enc, parking_count_enc, background_enc, 
            current_update_enc, status, next_plan_action_enc, next_plan_date, owner_code, reserve_date, win_date
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $win_insert = $win_date_sql ?: null;
        $stmt->bind_param("isssssssssssssssssssssss",
            $user_id, $lead_code, $lead_name_enc, $project_enc, $phone_enc, $line_id_enc, $budget_enc, $potential,
            $occupation_enc, $contact_date, $target_date_enc, $pain_point_enc, $requirement_enc,
            $financials_enc, $residents_count_enc, $parking_count_enc, $background_enc,
            $current_update_enc, $status, $next_plan_action_enc, $next_plan_date, $owner_code, $reserve_date, $win_insert
        );
    }

    if ($stmt->execute()) {
        $lead_id = $existing ? (int)$existing['id'] : (int)$conn->insert_id;

        if ($used_matrix && $lead_id > 0) {
            $note_enc = $status_note !== '' ? encrypt_data($status_note, $encryption_key) : '';
            $se = $conn->prepare("INSERT INTO lead_stage_events (user_id, lead_id, stage, outcome, note_enc, event_date)
                VALUES (?, ?, ?, ?, ?, ?)");
            $se->bind_param("iissss", $user_id, $lead_id, $stage, $outcome, $note_enc, $event_date);
            $se->execute();
            $se->close();
        }

        if ($status !== $old_status && $lead_id > 0) {
            $revived = in_array($old_status, lead_terminal_statuses(), true)
                && in_array($status, lead_funnel_statuses(), true);
            $note_text = ($revived ? 'ดึงกลับมาติดตาม · ' : '')
                . "เปลี่ยนจาก {$old_status} เป็น {$status}";
            if ($status_note !== '') $note_text .= ": {$status_note}";
            log_lead_status($conn, $user_id, $lead_id, $status, encrypt_data($note_text, $encryption_key), $event_date);
        }

        if (!empty($next_plan_action) && !empty($next_plan_date)) {
            sync_lead_plan_task($conn, $user_id, $encryption_key, $lead_code, $lead_name, $next_plan_action, $next_plan_date, $owner_code);
        }
        handle_lead_status_side_effects(
            $conn, $user_id, $encryption_key, $lead_code, $lead_name, $status, $owner_code, $reserve_date, $old_status, $status_note,
            $used_matrix ? $stage : null,
            $used_matrix ? $outcome : null
        );

        if (!empty($user['google_sheet_id'])) {
            sync_lead_to_sheet($user['google_sheet_id'], [
                'lead_code' => $lead_code,
                'lead_name' => $lead_name,
                'project' => $project,
                'phone' => $phone,
                'line_id' => $line_id_field,
                'budget' => $budget,
                'potential' => $potential,
                'occupation' => $occupation,
                'contact_date' => $contact_date,
                'target_date' => $target_date,
                'pain_point' => $pain_point,
                'requirement' => $requirement,
                'financials' => $financials,
                'residents_count' => $residents_count,
                'parking_count' => $parking_count,
                'background' => $background,
                'current_update' => $current_update,
                'status' => $status,
                'stage' => $used_matrix ? $stage : '',
                'outcome' => $used_matrix ? $outcome : '',
                'next_plan_action' => $next_plan_action,
                'next_plan_date' => $next_plan_date,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $stmt2 = $conn->prepare("SELECT * FROM leads WHERE id = ? AND user_id = ? LIMIT 1");
        $stmt2->bind_param("ii", $lead_id, $user_id);
        $stmt2->execute();
        $updated = $stmt2->get_result()->fetch_assoc();
        $stmt2->close();
        $events = [];
        $se2 = $conn->prepare("SELECT * FROM lead_stage_events WHERE user_id = ? AND lead_id = ? ORDER BY event_date ASC, id ASC");
        $se2->bind_param("ii", $user_id, $lead_id);
        $se2->execute();
        $events = $se2->get_result()->fetch_all(MYSQLI_ASSOC);
        $se2->close();

        $identity = lead_sync_phone_identity($conn, $user_id, $encryption_key, $lead_id, $phone);
        $dup_http = 0;
        if (!empty($identity['linked'])) {
            $dup_http = lead_notify_duplicate_phone($conn, $user_id, $identity, $updated, $encryption_key);
        }

        $stmt3 = $conn->prepare("SELECT * FROM leads WHERE id = ? AND user_id = ? LIMIT 1");
        $stmt3->bind_param("ii", $lead_id, $user_id);
        $stmt3->execute();
        $updated = $stmt3->get_result()->fetch_assoc();
        $stmt3->close();

        $resp = [
            'success' => true,
            'message' => 'Lead saved successfully',
            'data' => api_lead_payload($updated, $encryption_key, $events),
        ];
        if (!empty($identity['linked'])) {
            $resp['duplicate_phone'] = true;
            $resp['duplicate_matched'] = $identity['matched'];
            $resp['customer_group_id'] = $identity['group_id'];
            $resp['line_notify_http'] = $dup_http;
        }
        echo json_encode($resp);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    }
    $stmt->close();
    exit();
}

/**
 * ฟังก์ชันส่งซิงก์ข้อมูลลีดไปยัง Google Apps Script Web App
 */
function sync_lead_to_sheet($sheet_id, $data) {
    $apps_script_url = "https://script.google.com/macros/s/YOUR_SCRIPT_ID/exec";

    $payload = [
        "spreadsheetId" => $sheet_id,
        "sheetName" => "Leads",
        "action" => "upsert_lead",
        "keyColumn" => "lead_code",
        "data" => $data
    ];

    $ch = curl_init($apps_script_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}
