<?php
// api_owners.php
// API สำหรับจัดการข้อมูลฝั่งเจ้าของทรัพย์สิน (Owners) ของผู้ใช้งานระบบ (Project Antigravity)
require_once 'config.php';
require_once __DIR__ . '/lib/subscription.php';
require_once __DIR__ . '/lib/map_coords.php';

subscription_ensure_schema($conn);

header('Content-Type: application/json; charset=utf-8');

$line_id = isset($_REQUEST['line_id']) ? trim($_REQUEST['line_id']) : '';

if (empty($line_id)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing line_id']);
    exit();
}

$stmt = $conn->prepare("SELECT id, encryption_key, google_sheet_id, trial_ends_at, is_subscribed, is_lifetime_free FROM users WHERE line_user_id = ? LIMIT 1");
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

if ($method === 'GET') {
    // ------------------ ดึงรายการ Owner ------------------
    $code_list = isset($_GET['code_list']) ? trim($_GET['code_list']) : '';
    
    if (!empty($code_list)) {
        $stmt = $conn->prepare("SELECT * FROM owners WHERE user_id = ? AND code_list = ? LIMIT 1");
        $stmt->bind_param("is", $user_id, $code_list);
    } else {
        $stmt = $conn->prepare("SELECT * FROM owners WHERE user_id = ? ORDER BY updated_at DESC");
        $stmt->bind_param("i", $user_id);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $owners = [];
    
    while ($row = $result->fetch_assoc()) {
        $owners[] = [
            'id' => $row['id'],
            'code_list' => $row['code_list'],
            'owner_name' => decrypt_data($row['owner_name_enc'], $encryption_key),
            'project' => decrypt_data($row['project_enc'], $encryption_key),
            'listing_date' => $row['listing_date'],
            'marketing_status' => $row['marketing_status'],
            'incomplete_details' => decrypt_data($row['incomplete_details_enc'], $encryption_key),
            'property_type' => decrypt_data($row['property_type_enc'], $encryption_key),
            'phone' => decrypt_data($row['phone_enc'], $encryption_key),
            'line_id' => decrypt_data($row['line_id_enc'], $encryption_key),
            'zone' => decrypt_data($row['zone_enc'], $encryption_key),
            'area' => decrypt_data($row['area_enc'], $encryption_key),
            'location_grade' => decrypt_data($row['location_grade_enc'], $encryption_key),
            'bts_mrt_srt' => decrypt_data($row['bts_mrt_srt_enc'], $encryption_key),
            'arl' => decrypt_data($row['arl_enc'], $encryption_key),
            'bed' => decrypt_data($row['bed_enc'], $encryption_key),
            'bath' => decrypt_data($row['bath_enc'], $encryption_key),
            'unit_no' => decrypt_data($row['unit_no_enc'], $encryption_key),
            'area_rai' => decrypt_data($row['area_rai_enc'], $encryption_key),
            'area_ngan' => decrypt_data($row['area_ngan_enc'], $encryption_key),
            'area_sqwa' => decrypt_data($row['area_sqwa_enc'], $encryption_key),
            'area_sqm' => decrypt_data($row['area_sqm_enc'], $encryption_key),
            'floor' => decrypt_data($row['floor_enc'], $encryption_key),
            'parking' => decrypt_data($row['parking_enc'], $encryption_key),
            'direction' => decrypt_data($row['direction_enc'], $encryption_key),
            'asking_price' => decrypt_data($row['asking_price_enc'], $encryption_key),
            'rental_price' => decrypt_data($row['rental_price_enc'], $encryption_key),
            'selling_condition' => $row['selling_condition'],
            'map_url' => decrypt_data($row['map_url_enc'], $encryption_key),
            'availability_status' => $row['availability_status'],
            'sold_by' => decrypt_data($row['sold_by_enc'], $encryption_key),
            'sold_price' => decrypt_data($row['sold_price_enc'], $encryption_key),
            'sales_status' => $row['sales_status'],
            'owner_urgency' => $row['owner_urgency'],
            'selling_reason' => decrypt_data($row['selling_reason_enc'], $encryption_key),
            'selling_timeline' => decrypt_data($row['selling_timeline_enc'], $encryption_key),
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at']
        ];
    }
    $stmt->close();
    
    echo json_encode(['success' => true, 'data' => (!empty($code_list) ? ($owners[0] ?? null) : $owners)]);
    exit();

} elseif ($method === 'POST') {
    // ------------------ เพิ่ม หรือ อัปเดตข้อมูล Owner ------------------
    $input_raw = file_get_contents('php://input');
    $data = json_decode($input_raw, true);
    
    if (!$data) {
        $data = $_POST;
    }
    
    $code_list = trim($data['code_list'] ?? '');
    $owner_name = trim($data['owner_name'] ?? '');
    $project = trim($data['project'] ?? '');
    $listing_date = !empty($data['listing_date']) ? $data['listing_date'] : date('Y-m-d');
    $marketing_status = trim($data['marketing_status'] ?? 'ลงการตลาดแล้ว');
    $incomplete_details = trim($data['incomplete_details'] ?? '');
    $property_type = trim($data['property_type'] ?? '');
    $phone = trim($data['phone'] ?? '');
    $line_id_field = trim($data['line_id'] ?? '');
    $zone = trim($data['zone'] ?? '');
    $area = trim($data['area'] ?? '');
    $location_grade = trim($data['location_grade'] ?? '');
    $bts_mrt_srt = trim($data['bts_mrt_srt'] ?? '');
    $arl = trim($data['arl'] ?? '');
    $bed = trim($data['bed'] ?? '');
    $bath = trim($data['bath'] ?? '');
    $unit_no = trim($data['unit_no'] ?? '');
    $area_rai = trim($data['area_rai'] ?? '');
    $area_ngan = trim($data['area_ngan'] ?? '');
    $area_sqwa = trim($data['area_sqwa'] ?? '');
    $area_sqm = trim($data['area_sqm'] ?? '');
    $floor = trim($data['floor'] ?? '');
    $parking = trim($data['parking'] ?? '');
    $direction = trim($data['direction'] ?? '');
    $asking_price = trim($data['asking_price'] ?? '');
    $rental_price = trim($data['rental_price'] ?? '');
    $selling_condition = trim($data['selling_condition'] ?? '50/50 Transfer Fee');
    $map_url = trim($data['map_url'] ?? '');
    $availability_status = trim($data['availability_status'] ?? 'ยังขายอยู่');
    $sold_by = trim($data['sold_by'] ?? '');
    $sold_price = trim($data['sold_price'] ?? '');
    $sales_status = trim($data['sales_status'] ?? 'Sale');
    $owner_urgency = trim($data['owner_urgency'] ?? 'B');
    $selling_reason = trim($data['selling_reason'] ?? '');
    $selling_timeline = trim($data['selling_timeline'] ?? '');

    if (empty($code_list) || empty($owner_name)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Code list and Owner Name are required']);
        exit();
    }

    // เข้ารหัสฟิลด์ข้อมูล
    $owner_name_enc = encrypt_data($owner_name, $encryption_key);
    $project_enc = encrypt_data($project, $encryption_key);
    $incomplete_details_enc = encrypt_data($incomplete_details, $encryption_key);
    $property_type_enc = encrypt_data($property_type, $encryption_key);
    $phone_enc = encrypt_data($phone, $encryption_key);
    $line_id_enc = encrypt_data($line_id_field, $encryption_key);
    $zone_enc = encrypt_data($zone, $encryption_key);
    $area_enc = encrypt_data($area, $encryption_key);
    $location_grade_enc = encrypt_data($location_grade, $encryption_key);
    $bts_mrt_srt_enc = encrypt_data($bts_mrt_srt, $encryption_key);
    $arl_enc = encrypt_data($arl, $encryption_key);
    $bed_enc = encrypt_data($bed, $encryption_key);
    $bath_enc = encrypt_data($bath, $encryption_key);
    $unit_no_enc = encrypt_data($unit_no, $encryption_key);
    $area_rai_enc = encrypt_data($area_rai, $encryption_key);
    $area_ngan_enc = encrypt_data($area_ngan, $encryption_key);
    $area_sqwa_enc = encrypt_data($area_sqwa, $encryption_key);
    $area_sqm_enc = encrypt_data($area_sqm, $encryption_key);
    $floor_enc = encrypt_data($floor, $encryption_key);
    $parking_enc = encrypt_data($parking, $encryption_key);
    $direction_enc = encrypt_data($direction, $encryption_key);
    $asking_price_enc = encrypt_data($asking_price, $encryption_key);
    $rental_price_enc = encrypt_data($rental_price, $encryption_key);
    $map_url_enc = encrypt_data($map_url, $encryption_key);
    [$owner_lat, $owner_lng] = map_coords_for_import($map_url);
    $sold_by_enc = encrypt_data($sold_by, $encryption_key);
    $sold_price_enc = encrypt_data($sold_price, $encryption_key);
    $selling_reason_enc = encrypt_data($selling_reason, $encryption_key);
    $selling_timeline_enc = encrypt_data($selling_timeline, $encryption_key);

    // ตรวจสอบว่ามีข้อมูลนี้อยู่แล้วหรือไม่
    $stmt = $conn->prepare("SELECT id FROM owners WHERE user_id = ? AND code_list = ? LIMIT 1");
    $stmt->bind_param("is", $user_id, $code_list);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        $stmt = $conn->prepare("UPDATE owners SET 
            owner_name_enc = ?, project_enc = ?, listing_date = ?, marketing_status = ?, incomplete_details_enc = ?, 
            property_type_enc = ?, phone_enc = ?, line_id_enc = ?, zone_enc = ?, area_enc = ?, location_grade_enc = ?, 
            bts_mrt_srt_enc = ?, arl_enc = ?, bed_enc = ?, bath_enc = ?, unit_no_enc = ?, area_rai_enc = ?, 
            area_ngan_enc = ?, area_sqwa_enc = ?, area_sqm_enc = ?, floor_enc = ?, parking_enc = ?, 
            direction_enc = ?, asking_price_enc = ?, rental_price_enc = ?, selling_condition = ?, map_url_enc = ?, 
            availability_status = ?, sold_by_enc = ?, sold_price_enc = ?, sales_status = ?, owner_urgency = ?, 
            selling_reason_enc = ?, selling_timeline_enc = ?, lat = ?, lng = ? WHERE id = ?");
        
        $stmt->bind_param("sssssssssssssssssssssssssssssssssddi", 
            $owner_name_enc, $project_enc, $listing_date, $marketing_status, $incomplete_details_enc,
            $property_type_enc, $phone_enc, $line_id_enc, $zone_enc, $area_enc, $location_grade_enc,
            $bts_mrt_srt_enc, $arl_enc, $bed_enc, $bath_enc, $unit_no_enc, $area_rai_enc,
            $area_ngan_enc, $area_sqwa_enc, $area_sqm_enc, $floor_enc, $parking_enc,
            $direction_enc, $asking_price_enc, $rental_price_enc, $selling_condition, $map_url_enc,
            $availability_status, $sold_by_enc, $sold_price_enc, $sales_status, $owner_urgency,
            $selling_reason_enc, $selling_timeline_enc, $owner_lat, $owner_lng, $existing['id']
        );
    } else {
        $stmt = $conn->prepare("INSERT INTO owners (
            user_id, code_list, owner_name_enc, project_enc, listing_date, marketing_status, incomplete_details_enc, 
            property_type_enc, phone_enc, line_id_enc, zone_enc, area_enc, location_grade_enc, 
            bts_mrt_srt_enc, arl_enc, bed_enc, bath_enc, unit_no_enc, area_rai_enc, 
            area_ngan_enc, area_sqwa_enc, area_sqm_enc, floor_enc, parking_enc, 
            direction_enc, asking_price_enc, rental_price_enc, selling_condition, map_url_enc, 
            availability_status, sold_by_enc, sold_price_enc, sales_status, owner_urgency, 
            selling_reason_enc, selling_timeline_enc, lat, lng
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $stmt->bind_param("issssssssssssssssssssssssssssssssssssdd",
            $user_id, $code_list, $owner_name_enc, $project_enc, $listing_date, $marketing_status, $incomplete_details_enc,
            $property_type_enc, $phone_enc, $line_id_enc, $zone_enc, $area_enc, $location_grade_enc,
            $bts_mrt_srt_enc, $arl_enc, $bed_enc, $bath_enc, $unit_no_enc, $area_rai_enc,
            $area_ngan_enc, $area_sqwa_enc, $area_sqm_enc, $floor_enc, $parking_enc,
            $direction_enc, $asking_price_enc, $rental_price_enc, $selling_condition, $map_url_enc,
            $availability_status, $sold_by_enc, $sold_price_enc, $sales_status, $owner_urgency,
            $selling_reason_enc, $selling_timeline_enc, $owner_lat, $owner_lng
        );
    }

    if ($stmt->execute()) {
        owner_sync_lead_coords($conn, $user_id, $code_list, $owner_lat, $owner_lng);
        // ซิงก์ข้อมูลเจ้าของไปยัง Google Sheets ของผู้ใช้งาน
        if (!empty($user['google_sheet_id'])) {
            sync_owner_to_sheet($user['google_sheet_id'], [
                'code_list' => $code_list,
                'owner_name' => $owner_name,
                'project' => $project,
                'listing_date' => $listing_date,
                'marketing_status' => $marketing_status,
                'incomplete_details' => $incomplete_details,
                'property_type' => $property_type,
                'phone' => $phone,
                'line_id' => $line_id_field,
                'zone' => $zone,
                'area' => $area,
                'location_grade' => $location_grade,
                'bts_mrt_srt' => $bts_mrt_srt,
                'arl' => $arl,
                'bed' => $bed,
                'bath' => $bath,
                'unit_no' => $unit_no,
                'area_rai' => $area_rai,
                'area_ngan' => $area_ngan,
                'area_sqwa' => $area_sqwa,
                'area_sqm' => $area_sqm,
                'floor' => $floor,
                'parking' => $parking,
                'direction' => $direction,
                'asking_price' => $asking_price,
                'rental_price' => $rental_price,
                'selling_condition' => $selling_condition,
                'map_url' => $map_url,
                'availability_status' => $availability_status,
                'sold_by' => $sold_by,
                'sold_price' => $sold_price,
                'sales_status' => $sales_status,
                'owner_urgency' => $owner_urgency,
                'selling_reason' => $selling_reason,
                'selling_timeline' => $selling_timeline,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        }

        echo json_encode(['success' => true, 'message' => 'Owner saved successfully']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    }
    $stmt->close();
    exit();
}

/**
 * ฟังก์ชันส่งซิงก์ข้อมูลเจ้าของไปยัง Google Apps Script Web App
 */
function sync_owner_to_sheet($sheet_id, $data) {
    $apps_script_url = "https://script.google.com/macros/s/YOUR_SCRIPT_ID/exec";
    
    $payload = [
        "spreadsheetId" => $sheet_id,
        "sheetName" => "Owners",
        "action" => "upsert_owner",
        "keyColumn" => "code_list",
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
