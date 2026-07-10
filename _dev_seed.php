<?php
// _dev_seed.php — seed ข้อมูลเดโม่สำหรับทดสอบ dashboard บน localhost เท่านั้น (ลบทิ้งหลังใช้)
// เรียก ?action=seed เพื่อใส่ข้อมูล / ?action=clean เพื่อลบข้อมูลเดโม่ทั้งหมด
require_once 'config.php';
require_once 'task_helpers.php';

task_ensure_schema($conn);

$host = $_SERVER['HTTP_HOST'] ?? '';
if (strpos($host, 'localhost') === false && strpos($host, '127.0.0.1') === false) {
    http_response_code(403);
    die('local only');
}

$uid_param = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
if ($uid_param > 0) {
    $stmt = $conn->prepare("SELECT id, encryption_key FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $uid_param);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} else {
    // ค่าเริ่มต้น: user ล่าสุด (บัญชี LINE จริง) ไม่ใช่ demo user เก่า
    $res = $conn->query("SELECT id, encryption_key FROM users ORDER BY id DESC LIMIT 1");
    $u = $res ? $res->fetch_assoc() : null;
}
if (!$u) die('no user');
$uid = (int)$u['id'];
$k = $u['encryption_key'];

$action = $_GET['action'] ?? 'seed';

if ($action === 'clean') {
    $conn->query("DELETE l FROM lead_status_logs l INNER JOIN leads ld ON ld.id = l.lead_id WHERE ld.user_id = $uid AND ld.lead_code LIKE 'DEMO%'");
    $conn->query("DELETE FROM leads WHERE user_id = $uid AND lead_code LIKE 'DEMO%'");
    $conn->query("DELETE FROM owners WHERE user_id = $uid AND code_list LIKE 'DEMO%'");
    $conn->query("DELETE FROM tasks WHERE user_id = $uid AND (lead_code LIKE 'DEMO%' OR owner_code LIKE 'DEMO%')");
    $conn->query("DELETE FROM project_surveys WHERE user_id = $uid AND project_slug LIKE 'demo-%'");
    die('cleaned');
}

// อัปเกรดคอลัมน์ leads + ตารางประวัติสถานะ (ให้ตรง dashboard.php)
$lead_cols = [
    "customer_insight_enc TEXT DEFAULT NULL",
    "deal_context_enc TEXT DEFAULT NULL",
    "priority_score TINYINT DEFAULT 3",
    "owner_code VARCHAR(50) DEFAULT NULL",
    "chat_image_url VARCHAR(512) DEFAULT NULL",
    "chat_photos_link_enc TEXT DEFAULT NULL",
    "product_price_enc TEXT DEFAULT NULL",
    "win_date DATE DEFAULT NULL",
    "win_price_enc TEXT DEFAULT NULL",
    "visited_unit_enc TEXT DEFAULT NULL",
    "lat DECIMAL(10,7) DEFAULT NULL",
    "lng DECIMAL(10,7) DEFAULT NULL",
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

// ลบเดโม่เก่าก่อน seed ใหม่ (กันซ้ำ + ให้ UPDATE เคสทำงานเสมอ)
$conn->query("DELETE l FROM lead_status_logs l INNER JOIN leads ld ON ld.id = l.lead_id WHERE ld.user_id = $uid AND ld.lead_code LIKE 'DEMO%'");
$conn->query("DELETE FROM leads WHERE user_id = $uid AND lead_code LIKE 'DEMO%'");
$conn->query("DELETE FROM owners WHERE user_id = $uid AND code_list LIKE 'DEMO%'");
$conn->query("DELETE FROM tasks WHERE user_id = $uid AND (lead_code LIKE 'DEMO%' OR owner_code LIKE 'DEMO%')");
$conn->query("DELETE FROM project_surveys WHERE user_id = $uid AND project_slug LIKE 'demo-%'");

// ---- Leads ----
$leads = [
    ['DEMO-L01', 'คุณสมชาย ใจดี', 'Noble Around 33', '3.5 ลบ.', 'A', 'Follow',  'โทรตามผลธนาคาร', '+2 days',  '-9 days'],
    ['DEMO-L02', 'คุณแพรว',       'Ideo Q Sukhumvit','5 ลบ.',   'A', 'Nego',    'นัดเซ็นสัญญา',     '+1 days',  '-1 days'],
    ['DEMO-L03', 'คุณบอย',        'Life Asoke',      '2.8 ลบ.', 'B', 'Call',    'โทรแนะนำตัวรอบสอง','+4 days', '-14 days'],
    ['DEMO-L04', 'คุณฝน',         'Ashton Asoke',    '7 ลบ.',   'B', 'Show',    'พาดูห้องจริง',     '+3 days',  '-3 days'],
    ['DEMO-L05', 'คุณเจมส์',      'The Line 101',    '4.2 ลบ.', 'C', 'Win',     '',                 null,       '-20 days'],
    ['DEMO-L06', 'คุณนิว',        'Rhythm Ekkamai',  '3 ลบ.',   'C', 'Rejected','',                 null,       '-30 days'],
    ['DEMO-L07', 'คุณใจดี',       'Noble Around 33', '3.8 ลบ.', 'A', 'Reserve', 'ติดตามเอกสารโอน',  '+0 days',  '-2 days'],
    ['DEMO-L08', 'คุณต้น',        'Life Asoke',      '2.5 ลบ.', 'B', 'Lose',    '',                 null,       '-21 days'],
];
$stmt = $conn->prepare("INSERT INTO leads (user_id, lead_code, lead_name_enc, project_enc, budget_enc, potential, status, next_plan_action_enc, next_plan_date, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,NOW(),?)");
foreach ($leads as $L) {
    [$code, $name, $proj, $budget, $pot, $status, $next, $next_off, $upd_off] = $L;
    $name_e = encrypt_data($name, $k);
    $proj_e = encrypt_data($proj, $k);
    $bud_e  = encrypt_data($budget, $k);
    $next_e = $next !== '' ? encrypt_data($next, $k) : null;
    $next_date = $next_off ? date('Y-m-d', strtotime($next_off)) : null;
    $updated = date('Y-m-d H:i:s', strtotime($upd_off));
    $stmt->bind_param("isssssssss", $uid, $code, $name_e, $proj_e, $bud_e, $pot, $status, $next_e, $next_date, $updated);
    $stmt->execute();
}
$stmt->close();

// อัปเดตฟิลด์เคส + infowindow ต่อรหัสลีด
$lead_extras = [
    'DEMO-L01' => [
        'contact_date' => date('Y-m-d', strtotime('-9 days')),
        'owner_code' => 'DEMO-O01',
        'visited_unit' => '1205',
        'background' => 'ลูกค้าเดิมอยู่คอนโดย่านอโศก อยากย้ายมาใกล้ BTS พร้อมพงษ์',
        'requirement' => 'คอนโด 1-2 นอน ใกล้ BTS งบไม่เกิน 4 ลบ.',
        'pain_point' => 'กลัวดอกเบี้ยขึ้น อยากล็อกราคาก่อนปลายปี',
        'timeline' => 'พร้อมซื้อภายใน 2 เดือน',
        'current_update' => 'รอผลอนุมัติสินเชื่อจากธนาคารกรุงศรี',
        'chat_image' => 'https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?w=400',
        'chat_photos' => 'https://drive.google.com/drive/folders/demo-l01-chat',
    ],
    'DEMO-L02' => [
        'contact_date' => date('Y-m-d', strtotime('-12 days')),
        'owner_code' => 'DEMO-O04',
        'visited_unit' => '2508',
        'background' => 'เดิมอยู่แถวเลียบด่วน ทาวน์โฮม มีญาติอยู่ในนี้อยากย้ายมาอยู่ด้วย อยากให้อาม่าได้มีพื้นที่',
        'requirement' => 'คอนโด 2 นอน ใกล้ BTS สุขุมวิท โซน Ideo Q หรือเทียบเท่า',
        'pain_point' => 'พื้นที่ living ใหญ่ ให้อาม่าได้ใช้ชีวิตสบาย',
        'timeline' => 'พร้อมซื้อ',
        'current_update' => 'ต่อราคาแล้ว จาก 5.5 จะเอา 5.3',
        'next_plan' => 'นัดเซ็นสัญญา 13 มิ.ย.',
        'customer_insight' => 'ลูกค้า A grade ตัดสินใจเร็ว มีเงินดาวน์พร้อม',
        'chat_image' => 'https://images.unsplash.com/photo-1502672260266-1c1ef2d93688?w=400',
        'chat_photos' => 'https://drive.google.com/drive/folders/demo-l02-chat',
    ],
    'DEMO-L03' => [
        'contact_date' => date('Y-m-d', strtotime('-14 days')),
        'background' => 'ทัก LINE มาจากประกาศ Facebook',
        'requirement' => 'คอนโด 1 นอน อโศก-พหลโยธิน งบ 3 ลบ.',
        'pain_point' => 'ยังไม่มั่นใจทำเล อยากให้ช่วยเปรียบเทียบ',
        'timeline' => 'ดูข้อมูลก่อน ยังไม่รีบ',
        'current_update' => 'โทรรอบแรกแล้ว นัดโทรรอบสอง',
        'chat_image' => 'https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?w=400',
    ],
    'DEMO-L04' => [
        'contact_date' => date('Y-m-d', strtotime('-5 days')),
        'background' => 'ลูกค้าทำงานฝั่งสุขุมวิท ต้องการคอนโดหรูใกล้ที่ทำงาน',
        'requirement' => 'คอนโด 2-3 นอน Ashton หรือเทียบเท่า งบ 7 ลบ.',
        'pain_point' => 'เคยดูห้องจากโบรชัวร์แล้ว อยากดูของจริงก่อนตัดสินใจ',
        'timeline' => 'ภายใน 1 เดือน',
        'current_update' => 'นัดพาดูห้องจริงสัปดาห์หน้า',
        'chat_image' => 'https://images.unsplash.com/photo-1493809842364-78817add7ffb?w=400',
    ],
    'DEMO-L05' => [
        'contact_date' => date('Y-m-d', strtotime('-45 days')),
        'owner_code' => 'DEMO-O02',
        'visited_unit' => '805',
        'background' => 'ลูกค้าชาวต่างชาติ ทำงานไอที ต้องการคอนโดใกล้ BTS',
        'requirement' => 'คอนโด 1 นอน The Line 101 หรือใกล้เคียง',
        'pain_point' => 'ไม่มีเวลามาดูบ่อย อยากให้สรุปข้อมูลเป็นภาษาอังกฤษ',
        'timeline' => 'พร้อมซื้อทันทีเมื่อผ่านธนาคาร',
        'current_update' => 'ปิดดีลแล้ว โอนกรรมสิทธิ์เสร็จ',
        'win_date' => date('Y-m-d', strtotime('-20 days')),
        'win_price' => '4.15 ลบ.',
        'chat_image' => 'https://images.unsplash.com/photo-1545324418-cc1a3fa10c00?w=400',
    ],
    'DEMO-L06' => [
        'contact_date' => date('Y-m-d', strtotime('-30 days')),
        'background' => 'ลูกค้าสนใจคอนโดเอกมัย แต่เปลี่ยนใจย้ายต่างจังหวัด',
        'requirement' => 'คอนโด 2 นอน เอกมัย',
        'pain_point' => 'งบไม่พอหลังเปลี่ยนงาน',
        'timeline' => 'เลื่อนออกไปก่อน',
        'current_update' => 'ลูกค้าขอ Hold ไม่ซื้อในระยะนี้',
    ],
    'DEMO-L07' => [
        'contact_date' => date('Y-m-d', strtotime('-15 days')),
        'owner_code' => 'DEMO-O01',
        'visited_unit' => '1205',
        'background' => 'ลูกค้าวางจองแล้ว รอโอนกรรมสิทธิ์',
        'requirement' => 'คอนโด Noble Around 33',
        'current_update' => 'จองแล้ว รอธนาคารปล่อยเงินและนัดโอน',
        'reserve_date' => date('Y-m-d', strtotime('-5 days')),
        'lat' => 13.7234,
        'lng' => 100.5854,
    ],
    'DEMO-L08' => [
        'contact_date' => date('Y-m-d', strtotime('-25 days')),
        'background' => 'ลูกค้าเงียบหลังนัดดูห้อง — ไม่ตอบ LINE',
        'current_update' => 'โทร 3 ครั้งไม่รับ น่าจะหลุด',
    ],
];
$upd_lead = $conn->prepare("UPDATE leads SET contact_date=?, owner_code=?, background_enc=?, requirement_enc=?, pain_point_enc=?, target_date_enc=?, current_update_enc=?, next_plan_action_enc=?, customer_insight_enc=?, chat_image_url=?, chat_photos_link_enc=?, win_date=?, win_price_enc=?, visited_unit_enc=?, lat=?, lng=?, reserve_date=? WHERE user_id=? AND lead_code=?");
foreach ($lead_extras as $code => $ex) {
    $bg_e  = encrypt_data($ex['background'] ?? '', $k);
    $req_e = encrypt_data($ex['requirement'] ?? '', $k);
    $pain_e= encrypt_data($ex['pain_point'] ?? '', $k);
    $tl_e  = encrypt_data($ex['timeline'] ?? '', $k);
    $cur_e = encrypt_data($ex['current_update'] ?? '', $k);
    $next_e= isset($ex['next_plan']) ? encrypt_data($ex['next_plan'], $k) : null;
    $ins_e = isset($ex['customer_insight']) ? encrypt_data($ex['customer_insight'], $k) : null;
    $pics_e= isset($ex['chat_photos']) ? encrypt_data($ex['chat_photos'], $k) : null;
    $win_pr= isset($ex['win_price']) ? encrypt_data($ex['win_price'], $k) : null;
    $chat  = $ex['chat_image'] ?? null;
    $cd    = $ex['contact_date'] ?? null;
    $oc    = $ex['owner_code'] ?? null;
    $wd    = $ex['win_date'] ?? null;
    $vu_e  = isset($ex['visited_unit']) ? encrypt_data($ex['visited_unit'], $k) : null;
    $lat   = $ex['lat'] ?? null;
    $lng   = $ex['lng'] ?? null;
    $rd    = $ex['reserve_date'] ?? null;
    $upd_lead->bind_param("ssssssssssssssddsis", $cd, $oc, $bg_e, $req_e, $pain_e, $tl_e, $cur_e, $next_e, $ins_e, $chat, $pics_e, $wd, $win_pr, $vu_e, $lat, $lng, $rd, $uid, $code);
    $upd_lead->execute();
}
$upd_lead->close();

$lead_coords = [
    'DEMO-L01' => [13.7238, 100.5858],
    'DEMO-L02' => [13.7234, 100.5854],
    'DEMO-L03' => [13.7373, 100.5608],
    'DEMO-L04' => [13.7308, 100.5645],
    'DEMO-L05' => [13.9107, 100.5309],
    'DEMO-L07' => [13.7238, 100.5858],
];
$lstmt = $conn->prepare("UPDATE leads SET lat=?, lng=? WHERE user_id=? AND lead_code=?");
foreach ($lead_coords as $code => [$lat, $lng]) {
    $lstmt->bind_param("ddis", $lat, $lng, $uid, $code);
    $lstmt->execute();
}
$lstmt->close();

// ประวัติอัปเดตสถานะลีด
$lead_id_by_code = [];
$res = $conn->query("SELECT id, lead_code FROM leads WHERE user_id=$uid AND lead_code LIKE 'DEMO%'");
while ($row = $res->fetch_assoc()) $lead_id_by_code[$row['lead_code']] = (int)$row['id'];

$status_logs = [
    ['DEMO-L02', 'Call',        '-12 days', 'ลูกค้าทัก LINE สนใจ Ideo Q จากประกาศ'],
    ['DEMO-L02', 'Follow',      '-10 days', 'โทรสอบถามงบและไทม์ไลน์ — พร้อมซื้อ'],
    ['DEMO-L02', 'Appointment', '-7 days',  'นัดดูห้องตัวอย่างที่โครงการ'],
    ['DEMO-L02', 'Show',        '-5 days',  'พาดูห้องจริง ชอบวิวและทำเล'],
    ['DEMO-L02', 'Nego',        '-2 days',  'ต่อราคาแล้ว จาก 5.5 จะเอา 5.3 — รอเจ้าของยืนยัน'],
    ['DEMO-L01', 'Call',        '-9 days',  'โทรครั้งแรก — สนใจ Noble Around 33'],
    ['DEMO-L01', 'Follow',      '-4 days',  'ส่งเอกสารกู้ธนาคารให้ลูกค้าแล้ว'],
    ['DEMO-L05', 'Call',        '-45 days', 'ลูกค้าทักมาสนใจ The Line 101'],
    ['DEMO-L05', 'Follow',      '-40 days', 'ส่งรายละเอียดห้อง + ผ่อนคร่าวๆ'],
    ['DEMO-L05', 'Appointment', '-35 days', 'นัดเซ็นจองห้อง'],
    ['DEMO-L05', 'Nego',        '-28 days', 'ต่อราคาจาก 4.5 เป็น 4.15 ลบ.'],
    ['DEMO-L05', 'Close',       '-25 days', 'เซ็นสัญญาจะซื้อจะขาย'],
    ['DEMO-L05', 'Bank',        '-22 days', 'ยื่นกู้ธนาคาร — อนุมัติแล้ว'],
    ['DEMO-L05', 'Win',         '-20 days', 'โอนกรรมสิทธิ์เสร็จ ปิดดีลสำเร็จ'],
];
$stmt = $conn->prepare("INSERT INTO lead_status_logs (lead_id, user_id, status, note_enc, log_date) VALUES (?,?,?,?,?)");
foreach ($status_logs as [$code, $st, $off, $note]) {
    if (!isset($lead_id_by_code[$code])) continue;
    $note_e = encrypt_data($note, $k);
    $d = date('Y-m-d', strtotime($off));
    $lid = $lead_id_by_code[$code];
    $stmt->bind_param("iisss", $lid, $uid, $st, $note_e, $d);
    $stmt->execute();
}
$stmt->close();

// อัปเกรดคอลัมน์ owners (กรณียังไม่เคยเปิด dashboard)
$owner_cols = [
    "project_name_en_enc TEXT DEFAULT NULL",
    "project_name_th_enc TEXT DEFAULT NULL",
    "cover_image_url VARCHAR(512) DEFAULT NULL",
    "photos_link_enc TEXT DEFAULT NULL",
    "listing_source VARCHAR(50) DEFAULT NULL",
    "marketing_date DATE DEFAULT NULL",
    "has_deed TINYINT DEFAULT NULL",
    "owner_asking_price_enc TEXT DEFAULT NULL",
    "sold_date DATE DEFAULT NULL",
    "maid_enc TEXT DEFAULT NULL",
    "last_contact_date DATE DEFAULT NULL",
    "contact_summary_enc TEXT DEFAULT NULL",
    "price_consult_enc TEXT DEFAULT NULL",
    "soi_enc TEXT DEFAULT NULL",
    "lat DECIMAL(10,7) DEFAULT NULL",
    "lng DECIMAL(10,7) DEFAULT NULL",
];
$conn->query("CREATE TABLE IF NOT EXISTS owner_contact_logs (
    id INT AUTO_INCREMENT PRIMARY KEY, owner_id INT NOT NULL, user_id INT NOT NULL,
    contact_date DATE NOT NULL, note_enc TEXT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_id) REFERENCES owners(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$conn->query("CREATE TABLE IF NOT EXISTS owner_price_logs (
    id INT AUTO_INCREMENT PRIMARY KEY, owner_id INT NOT NULL, user_id INT NOT NULL,
    log_date DATE NOT NULL, old_price_enc TEXT DEFAULT NULL, new_price_enc TEXT NOT NULL,
    changed_by VARCHAR(20) DEFAULT 'owner', note_enc TEXT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_id) REFERENCES owners(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

foreach ($owner_cols as $col_def) {
    $col_name = preg_match('/^(\w+)/', $col_def, $m) ? $m[1] : '';
    if ($col_name === '') continue;
    $chk = $conn->query("SHOW COLUMNS FROM owners LIKE '$col_name'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query("ALTER TABLE owners ADD COLUMN $col_def");
    }
}

// ---- Owners ----
$owners = [
    ['DEMO-O01', 'คุณวิชัย',  'Noble Around 33',  'โนเบิล อราวน์ 33', 'คอนโด', 'พร้อมพงษ์', '3.9 ลบ.', '18,000/ด.', 'ยังขายอยู่', 'Sale', 'A', 'ลงการตลาดแล้ว', '', 'livinginsider', 1, '081-111-2233', 'wichai.noble', 'https://images.unsplash.com/photo-1545324418-cc1a3fa10c00?w=400', '2', '1', '1', '35', '4.2 ลบ.'],
    ['DEMO-O02', 'คุณมะลิ',   'The Line 101',     'เดอะ ไลน์ 101',     'คอนโด', 'ปุณณวิถี',  '4.5 ลบ.', '',          'ยังขายอยู่', 'Sale', 'B', 'ข้อมูลยังไม่ครบ', 'ขาดรูปห้องน้ำ + โฉนด', 'survey', 0, '082-333-4455', 'mali.line101', 'https://images.unsplash.com/photo-1502672260266-1c1ef2d93688?w=400', '1', '1', '0', '28', '4.8 ลบ.'],
    ['DEMO-O03', 'คุณสมศักดิ์','บ้านเดี่ยว ลาดพร้าว','บ้านเดี่ยว ลาดพร้าว','บ้านเดี่ยว','ลาดพร้าว 71','12 ลบ.', '', 'ขายได้แล้ว', 'sold', 'C', 'ลงการตลาดแล้ว', '', 'fb', 1, '089-777-8899', 'somsak.home', 'https://images.unsplash.com/photo-1564013799919-ab600027ffc6?w=400', '4', '3', '2', '2', '13 ลบ.'],
    ['DEMO-O04', 'คุณสุรชัย',  'Ideo Q Sukhumvit', 'ไอดีโอ คิว สุขุมวิท', 'คอนโด', 'พระโขนง', '5.5 ลบ.', '', 'ยังขายอยู่', 'Sale', 'A', 'ลงการตลาดแล้ว', '', 'livinginsider', 1, '081-555-6677', 'surachai.ideoq', 'https://images.unsplash.com/photo-1502672260266-1c1ef2d93688?w=400', '2', '2', '0', '65', '5.8 ลบ.'],
];
$ins = $conn->prepare("INSERT INTO owners (user_id, code_list, owner_name_enc, project_enc, property_type_enc, zone_enc, asking_price_enc, rental_price_enc, availability_status, sales_status, owner_urgency, marketing_status, incomplete_details_enc, listing_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,CURDATE())");
$upd = $conn->prepare("UPDATE owners SET project_name_en_enc=?, project_name_th_enc=?, listing_source=?, has_deed=?, phone_enc=?, line_id_enc=?, cover_image_url=?, bed_enc=?, bath_enc=?, maid_enc=?, area_sqm_enc=?, owner_asking_price_enc=?, marketing_date=?, sold_date=?, sold_by_enc=?, sold_price_enc=?, photos_link_enc=?, map_url_enc=?, soi_enc=?, unit_no_enc=?, floor_enc=?, direction_enc=? WHERE user_id=? AND code_list=?");
$addr_demo = [
    'DEMO-O01' => ['ซอยสุขุมวิท 33', '1205', 'ชั้น 12', 'ทิศใต้'],
    'DEMO-O02' => ['ซอยสุขุมวิท 101', '805', 'ชั้น 8', 'ทิศตะวันออก'],
    'DEMO-O03' => ['ซอยลาดพร้าว 71 แยก 3', '88/22', '', 'ทิศเหนือ'],
    'DEMO-O04' => ['ซอยสุขุมวิท 46', '2508', 'ชั้น 25', 'ทิศตะวันตก'],
];
foreach ($owners as $O) {
    [$code, $oname, $proj_en, $proj_th, $ptype, $zone, $price, $rent, $avail, $sstat, $urg, $mkt, $missing, $src, $deed, $phone, $lineid, $cover, $bed, $bath, $maid, $sqm, $owner_price] = $O;
    $oname_e = encrypt_data($oname, $k);
    $proj_e  = encrypt_data($proj_en, $k);
    $ptype_e = encrypt_data($ptype, $k);
    $zone_e  = encrypt_data($zone, $k);
    $price_e = encrypt_data($price, $k);
    $rent_e  = $rent !== '' ? encrypt_data($rent, $k) : null;
    $miss_e  = $missing !== '' ? encrypt_data($missing, $k) : null;
    $ins->bind_param("issssssssssss", $uid, $code, $oname_e, $proj_e, $ptype_e, $zone_e, $price_e, $rent_e, $avail, $sstat, $urg, $mkt, $miss_e);
    $ins->execute();

    $pen_e   = encrypt_data($proj_en, $k);
    $pth_e   = encrypt_data($proj_th, $k);
    $phone_e = encrypt_data($phone, $k);
    $line_e  = encrypt_data($lineid, $k);
    $bed_e   = encrypt_data($bed, $k);
    $bath_e  = encrypt_data($bath, $k);
    $maid_e  = encrypt_data($maid, $k);
    $sqm_e   = encrypt_data($sqm, $k);
    $op_e    = encrypt_data($owner_price, $k);
    $mkt_date = date('Y-m-d', strtotime('-7 days'));
    $sold_date = ($avail === 'ขายได้แล้ว') ? date('Y-m-d', strtotime('-14 days')) : null;
    $sold_by_e = ($avail === 'ขายได้แล้ว') ? encrypt_data('คุณเจมส์ (เอเจนต์)', $k) : null;
    $sold_pr_e = ($avail === 'ขายได้แล้ว') ? encrypt_data('11.5 ลบ.', $k) : null;
    $pics_e    = encrypt_data('https://drive.google.com/drive/folders/demo-' . substr($code, -3), $k);
    $map_e     = encrypt_data('https://maps.google.com/?q=' . urlencode($zone), $k);
    [$soi, $unit, $floor, $dir] = $addr_demo[$code] ?? ['', '', '', ''];
    $soi_e   = $soi !== '' ? encrypt_data($soi, $k) : null;
    $unit_e  = $unit !== '' ? encrypt_data($unit, $k) : null;
    $floor_e = $floor !== '' ? encrypt_data($floor, $k) : null;
    $dir_e   = $dir !== '' ? encrypt_data($dir, $k) : null;
    $upd->bind_param("sssissssssssssssssssssis", $pen_e, $pth_e, $src, $deed, $phone_e, $line_e, $cover, $bed_e, $bath_e, $maid_e, $sqm_e, $op_e, $mkt_date, $sold_date, $sold_by_e, $sold_pr_e, $pics_e, $map_e, $soi_e, $unit_e, $floor_e, $dir_e, $uid, $code);
    $upd->execute();

    $res = $conn->query("SELECT id FROM owners WHERE user_id=$uid AND code_list='" . $conn->real_escape_string($code) . "' LIMIT 1");
    $owner_row = $res ? $res->fetch_assoc() : null;
    if (!$owner_row) continue;
    $oid = (int)$owner_row['id'];

    $contact_meta = [
        'DEMO-O01' => ['summary' => 'โทรตามเมื่อ 3 วันก่อน — ยังรอผู้ซื้อที่งบตรง', 'consult' => 'แนะนำลดจาก 4.2 เป็น 3.9 ลบ. เจ้าของยอมแล้ว', 'last' => '-3 days'],
        'DEMO-O02' => ['summary' => 'ทัก LINE ถามรูปห้องน้ำ — ยังไม่ส่ง', 'consult' => 'ราคา 4.5 ลบ. แพงกว่าตลาดเล็กน้อย แนะนำ 4.2', 'last' => '-5 days'],
        'DEMO-O03' => ['summary' => 'ปิดดีลแล้ว ไม่ต้องติดต่อเพิ่ม', 'consult' => '-', 'last' => '-14 days'],
        'DEMO-O04' => ['summary' => 'ลูกค้าคุณแพรวสนใจ — กำลังต่อราคา 5.5→5.3', 'consult' => 'แนะนำยอมลดเล็กน้อยเพื่อปิดดีลเร็ว', 'last' => '-2 days'],
    ];
    $cm = $contact_meta[$code] ?? null;
    if ($cm) {
        $sum_e = encrypt_data($cm['summary'], $k);
        $con_e = $cm['consult'] !== '-' ? encrypt_data($cm['consult'], $k) : null;
        $last_d = date('Y-m-d', strtotime($cm['last']));
        $stmt2 = $conn->prepare("UPDATE owners SET last_contact_date=?, contact_summary_enc=?, price_consult_enc=? WHERE id=?");
        $stmt2->bind_param("sssi", $last_d, $sum_e, $con_e, $oid);
        $stmt2->execute();
        $stmt2->close();
    }
}
$ins->close();
$upd->close();

$owner_coords = [
    'DEMO-O01' => [13.7238, 100.5858],  // Noble Around 33 · พร้อมพงษ์
    'DEMO-O02' => [13.9107, 100.5309],  // The Line 101 · ปทุมธานี
    'DEMO-O03' => [13.8160, 100.6030],  // ลาดพร้าว
    'DEMO-O04' => [13.7234, 100.5854],  // Ideo Q · พระโขนง
];
$stmt = $conn->prepare("UPDATE owners SET lat=?, lng=? WHERE user_id=? AND code_list=?");
foreach ($owner_coords as $code => [$lat, $lng]) {
    $stmt->bind_param("ddis", $lat, $lng, $uid, $code);
    $stmt->execute();
}
$stmt->close();

// ---- ประวัติติดต่อ & ปรับราคา (Owners) ----
$owner_id_by_code = [];
$res = $conn->query("SELECT id, code_list FROM owners WHERE user_id=$uid AND code_list LIKE 'DEMO%'");
while ($row = $res->fetch_assoc()) $owner_id_by_code[$row['code_list']] = (int)$row['id'];

$contact_logs = [
    ['DEMO-O01', '-3 days', 'โทรตาม — เจ้าของบอกยังไม่มีคนสนใจ ให้ลองลดราคาอีกนิด'],
    ['DEMO-O01', '-10 days', 'นัดดูห้องที่โครงการ — ชอบทำเล แต่บ่นเรื่องเสียงรบกวน'],
    ['DEMO-O02', '-5 days', 'ทัก LINE ขอรูปห้องน้ำ + โฉนด — ยังไม่ตอบ'],
    ['DEMO-O02', '-12 days', 'โทรครั้งแรก — สนใจลงประกาศ แต่ข้อมูลยังไม่ครบ'],
];
$stmt = $conn->prepare("INSERT INTO owner_contact_logs (owner_id, user_id, contact_date, note_enc) VALUES (?,?,?,?)");
foreach ($contact_logs as [$code, $off, $note]) {
    if (!isset($owner_id_by_code[$code])) continue;
    $note_e = encrypt_data($note, $k);
    $d = date('Y-m-d', strtotime($off));
    $oid = $owner_id_by_code[$code];
    $stmt->bind_param("iiss", $oid, $uid, $d, $note_e);
    $stmt->execute();
}
$stmt->close();

$price_logs = [
    ['DEMO-O01', '-14 days', '4.2 ลบ.', '3.9 ลบ.', 'owner', 'เจ้าของยอมลดหลังไม่มีคนสนใจ 2 สัปดาห์'],
    ['DEMO-O01', '-20 days', '', '4.2 ลบ.', 'owner', 'ราคาเปิดตัวครั้งแรก'],
    ['DEMO-O01', '-8 days', '3.9 ลบ.', '3.9 ลบ.', 'agent', 'Consult แนะนำ 3.8 แต่เจ้าของยังไม่ยอม'],
    ['DEMO-O02', '-7 days', '4.8 ลบ.', '4.5 ลบ.', 'owner', 'ลดเล็กน้อยหลังดูตลาดเปรียบเทียบ'],
];
$stmt = $conn->prepare("INSERT INTO owner_price_logs (owner_id, user_id, log_date, old_price_enc, new_price_enc, changed_by, note_enc) VALUES (?,?,?,?,?,?,?)");
foreach ($price_logs as [$code, $off, $old, $new, $by, $note]) {
    if (!isset($owner_id_by_code[$code])) continue;
    $old_e = $old !== '' ? encrypt_data($old, $k) : null;
    $new_e = encrypt_data($new, $k);
    $note_e = encrypt_data($note, $k);
    $d = date('Y-m-d', strtotime($off));
    $oid = $owner_id_by_code[$code];
    $stmt->bind_param("iisssss", $oid, $uid, $d, $old_e, $new_e, $by, $note_e);
    $stmt->execute();
}
$stmt->close();

// ---- Tasks (TickTick grouping) ----
$tasks = [
    ['ติดตามลีด DEMO-L01 (คุณสมชาย ใจดี): โทรตามผลธนาคาร', '+2 days', 0, 'DEMO-L01', null, 'Follow Lead', 'lead:DEMO-L01', 'lead_plan', 'คุณสมชาย ใจดี'],
    ['ติดตามลีด DEMO-L02 (คุณแพรว): นัดเซ็นสัญญา', '+1 days', 0, 'DEMO-L02', null, 'Follow Lead', 'lead:DEMO-L02', 'lead_plan', 'คุณแพรว'],
    ['ติดตาม Owner DEMO-O03 (คุณมะลิ): โทรถามความพร้อมลดราคา', '+3 days', 0, null, 'DEMO-O03', 'Follow Owner', 'owner:DEMO-O03', 'owner_follow', 'คุณมะลิ'],
    ['ติดตามจองรอโอน · คุณใจดี (DEMO-L07)', '+0 days', 0, 'DEMO-L07', 'DEMO-O01', 'จองรอโอน', 'reserve:DEMO-L07', 'reserve_daily', 'คุณใจดี · DEMO-O01'],
    ['พาคุณฝนดูห้อง Ashton ตึก B', '+3 days', 0, 'DEMO-L04', null, 'Follow Lead', 'lead:DEMO-L04', 'manual', 'คุณฝน'],
    ['ส่งสรุปรายงานประจำสัปดาห์', '-2 days', 1, 'DEMO-L01', null, 'Inbox', null, 'manual', ''],
];
$stmt = $conn->prepare("INSERT INTO tasks (user_id, title_enc, due_date, is_completed, lead_code, owner_code, list_name, group_key, task_kind, group_label_enc) VALUES (?,?,?,?,?,?,?,?,?,?)");
foreach ($tasks as $T) {
    [$title, $off, $done, $lc, $oc, $list, $gk, $kind, $gl] = $T;
    $title_e = encrypt_data($title, $k);
    $gl_e = $gl !== '' ? encrypt_data($gl, $k) : null;
    $due = date('Y-m-d', strtotime($off));
    $stmt->bind_param("issiisssss", $uid, $title_e, $due, $done, $lc, $oc, $list, $gk, $kind, $gl_e);
    $stmt->execute();
}
$stmt->close();

// งานย่อย (sub-task) ใต้งานแรกของ DEMO-L01
$res = $conn->query("SELECT id FROM tasks WHERE user_id=$uid AND lead_code='DEMO-L01' ORDER BY id ASC LIMIT 1");
$parent_task = $res ? $res->fetch_assoc() : null;
$res2 = $conn->query("SELECT id FROM tasks WHERE user_id=$uid AND lead_code='DEMO-L01' ORDER BY id DESC LIMIT 1");
$child_task = $res2 ? $res2->fetch_assoc() : null;
if ($parent_task && $child_task && (int)$parent_task['id'] !== (int)$child_task['id']) {
    $pid = (int)$parent_task['id'];
    $cid = (int)$child_task['id'];
    $conn->query("UPDATE tasks SET parent_id=$pid, list_name='Follow Lead', group_key='lead:DEMO-L01' WHERE id=$cid AND user_id=$uid");
}

// ---- Project Surveys (แผนที่โครงการ) ----
$projects = [
    [
        'slug' => 'demo-noble-around-33',
        'name_th' => 'โนเบิล อะราวด์ 33',
        'name_en' => 'Noble Around 33',
        'developer' => 'Noble Development',
        'segment' => 'Main class',
        'total_units' => 292,
        'phases' => 1,
        'launch_year' => 2024,
        'built_year' => 2025,
        'common_fee' => 45,
        'property_type' => 'Condo',
        'lat' => 13.7238,
        'lng' => 100.5858,
        'cover' => 'https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?w=800',
        'amenities' => ['สระว่ายน้ำ', 'ฟิตเนส', 'Co-working', 'Lobby'],
        'nearby' => [['name' => 'BTS พร้อมพงษ์', 'distance' => '350 ม.'], ['name' => 'เซ็นทรัลพระโขนง', 'distance' => '1.2 กม.']],
        'units' => [['name' => '1 นอน Type A', 'land' => '-', 'living' => '35 ตร.ม.', 'bed' => 1, 'bath' => 1, 'price_open' => 4200000]],
    ],
    [
        'slug' => 'demo-narasiri-phahol',
        'name_th' => 'นาราศิริ พหล-วัชรพล',
        'name_en' => 'Narasiri Phahol-Watcharapol',
        'developer' => 'Sansiri',
        'segment' => 'Luxury class',
        'total_units' => 20,
        'phases' => 0,
        'launch_year' => 2024,
        'built_year' => 2025,
        'common_fee' => 40,
        'property_type' => 'House',
        'lat' => 13.8265,
        'lng' => 100.6555,
        'cover' => 'https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?w=800',
        'amenities' => ['คลับเฮ้าส์', 'สนามเทนนิส', 'สระว่ายน้ำ', 'CCTV'],
        'nearby' => [['name' => 'ฉลองรัช', 'distance' => '2 กม.'], ['name' => 'รามคำแหง', 'distance' => '3 กม.']],
        'units' => [['name' => 'นารา', 'land' => '100 ตร.ว.', 'living' => '350 ตร.ม.', 'bed' => 3, 'bath' => 3, 'price_open' => 40000000]],
    ],
    [
        'slug' => 'demo-the-line-101',
        'name_th' => 'เดอะ ไลน์ 101',
        'name_en' => 'The Line 101',
        'developer' => 'Sansiri',
        'segment' => 'Economy class',
        'total_units' => 1800,
        'phases' => 2,
        'launch_year' => 2022,
        'built_year' => 2024,
        'common_fee' => 38,
        'property_type' => 'Condo',
        'lat' => 13.9107,
        'lng' => 100.5309,
        'cover' => 'https://images.unsplash.com/photo-1545324418-cc1a3fa10c00?w=800',
        'amenities' => ['สระว่ายน้ำ', 'ฟิตเนส', 'Sky lounge', 'Shuttle'],
        'nearby' => [['name' => 'BTS สะพานใหม่', 'distance' => '1.5 กม.'], ['name' => 'เซ็นทรัล ปทุมธานี', 'distance' => '4 กม.']],
        'units' => [['name' => '1 นอน', 'land' => '-', 'living' => '28 ตร.ม.', 'bed' => 1, 'bath' => 1, 'price_open' => 3900000]],
    ],
];
$pstmt = $conn->prepare("INSERT INTO project_surveys (user_id, project_slug, name_en, name_th, developer, segment, total_units, phases, launch_year, built_year, common_fee, fee_period, property_type, amenities_json, cover_image_url, lat, lng, nearby_json, units_json) VALUES (?,?,?,?,?,?,?,?,?,?,?, 'yearly', ?, ?, ?, ?, ?, ?, ?)");
foreach ($projects as $pr) {
    $amen_j = json_encode($pr['amenities'], JSON_UNESCAPED_UNICODE);
    $near_j = json_encode($pr['nearby'], JSON_UNESCAPED_UNICODE);
    $unit_j = json_encode($pr['units'], JSON_UNESCAPED_UNICODE);
    $pstmt->bind_param("isssssiiiidsssddss",
        $uid, $pr['slug'], $pr['name_en'], $pr['name_th'], $pr['developer'], $pr['segment'],
        $pr['total_units'], $pr['phases'], $pr['launch_year'], $pr['built_year'], $pr['common_fee'],
        $pr['property_type'], $amen_j, $pr['cover'], $pr['lat'], $pr['lng'], $near_j, $unit_j
    );
    $pstmt->execute();
}
$pstmt->close();

echo "seeded user_id=$uid";
