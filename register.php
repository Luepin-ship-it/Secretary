<?php
// register.php
// หน้าเว็บลงทะเบียนผู้ใช้งานระบบและตั้งค่าบอท (Project Antigravity Web Portal)
require_once 'config.php';
require_once 'auth.php';
require_once 'brand_mark.php';
require_once 'branch_config.php';
require_once 'ms_report_helpers.php';
require_once 'policy_lib.php';
require_once __DIR__ . '/lib/line_messaging.php';

ms_ensure_schema($conn);
policy_ensure_schema($conn);

// ฟังก์ชันส่งข้อความ Push Message ของ LINE (ใช้เมื่อลงทะเบียนเสร็จเพื่อส่ง Flex Checklist)
function send_line_push($line_user_id, $flex_contents) {
    $payload = [
        "to" => $line_user_id,
        "messages" => [
            [
                "type" => "flex",
                "altText" => "ตรวจสอบข้อมูลลงทะเบียน",
                "contents" => $flex_contents
            ]
        ]
    ];
    
    $ch = curl_init("https://api.line.me/v2/bot/message/push");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer " . LINE_ACCESS_TOKEN
    ]);
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // บันทึก Log การส่ง Push
    file_put_contents(
        __DIR__ . '/line_webhook_debug.log', 
        "[LINE Push Checklist] " . date('Y-m-d H:i:s') . " | HTTP: " . $httpCode . " | Response: " . $result . " | Payload: " . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n", 
        FILE_APPEND
    );
    
    return $result;
}

// ฟังก์ชันสร้าง Flex Checklist โดยดึงรูปแบบมาจาก flex check list register.json
function build_registration_checklist_flex($user) {
    $json_path = __DIR__ . '/flex check list register.json';
    if (!file_exists($json_path)) {
        return null;
    }
    $json_str = file_get_contents($json_path);
    $flex = json_decode($json_str, true);
    if (!$flex) {
        return null;
    }

    $line_id = $user['line_user_id'] ?? '';
    $first_name = $user['first_name'] ?? '';
    $last_name = $user['last_name'] ?? '';
    $user_name = trim($first_name . ' ' . $last_name);
    if ($user_name === '') {
        $user_name = $user['user_name'] ?? '';
    }
    $occupation = $user['job_title'] ?? '';
    $customer_id = $user['id'] ?? '';
    $google_sheet = $user['google_sheet_id'] ?? '';
    $google_drive = $user['google_drive_id'] ?? '';

    // ค้นหากล่อง Checklist ในตัวแปร Flex
    $checklist_index = -1;
    if (isset($flex['body']['contents']) && is_array($flex['body']['contents'])) {
        foreach ($flex['body']['contents'] as $idx => $content) {
            if ($content['type'] === 'box' && isset($content['spacing']) && $content['spacing'] === 'md') {
                $checklist_index = $idx;
                break;
            }
        }
    }

    if ($checklist_index !== -1) {
        $items = &$flex['body']['contents'][$checklist_index]['contents'];
        
        // 1. Line ID (index 0)
        if (isset($items[0]['contents'][1]['contents'][1])) {
            $items[0]['contents'][1]['contents'][1]['text'] = $line_id;
        }
        
        // 2. ชื่อ นามสกุล (index 1)
        if (isset($items[1]['contents'][1]['contents'][1])) {
            if ($user_name !== '') {
                $items[1]['contents'][1]['contents'][1]['text'] = $user_name;
                $items[1]['contents'][1]['contents'][1]['color'] = '#2B2B2B'; // สีข้อความปกติ
                $items[1]['contents'][0]['backgroundColor'] = '#10B981'; // เปลี่ยนสีวงกลมตัวเลขเป็นเขียว
                // เปลี่ยนปุ่มเป็นข้อความ "ครบ" สีเขียว
                $items[1]['contents'][2] = [
                    "type" => "text",
                    "text" => "ครบ",
                    "size" => "xs",
                    "weight" => "bold",
                    "color" => "#10B981",
                    "align" => "end",
                    "gravity" => "center",
                    "flex" => 3
                ];
            }
        }
        
        // 3. อาชีพ (index 2)
        if (isset($items[2]['contents'][1]['contents'][1])) {
            if ($occupation !== '') {
                $items[2]['contents'][1]['contents'][1]['text'] = $occupation;
                $items[2]['contents'][1]['contents'][1]['color'] = '#2B2B2B';
                $items[2]['contents'][0]['backgroundColor'] = '#10B981';
                $items[2]['contents'][2] = [
                    "type" => "text",
                    "text" => "ครบ",
                    "size" => "xs",
                    "weight" => "bold",
                    "color" => "#10B981",
                    "align" => "end",
                    "gravity" => "center",
                    "flex" => 3
                ];
            }
        }
        
        // 4. รหัสของลูกค้า (index 3) - รันอัตโนมัติโดยดึงจาก DB user_id ( id ในตาราง users )
        if (isset($items[3]['contents'][1]['contents'][1])) {
            if ($customer_id !== '') {
                $items[3]['contents'][1]['contents'][1]['text'] = (string)$customer_id;
                $items[3]['contents'][1]['contents'][1]['color'] = '#2B2B2B';
                $items[3]['contents'][0]['backgroundColor'] = '#10B981';
                $items[3]['contents'][2] = [
                    "type" => "text",
                    "text" => "ครบ",
                    "size" => "xs",
                    "weight" => "bold",
                    "color" => "#10B981",
                    "align" => "end",
                    "gravity" => "center",
                    "flex" => 3
                ];
            }
        }
    }

    // จัดการส่วนของการเชื่อมต่อ Google Sheet & Google Drive
    $backup_index = -1;
    if (isset($flex['body']['contents']) && is_array($flex['body']['contents'])) {
        foreach ($flex['body']['contents'] as $idx => $content) {
            if ($content['type'] === 'box' && isset($content['spacing']) && $content['spacing'] === 'sm') {
                $backup_index = $idx;
                break;
            }
        }
    }

    if ($backup_index !== -1) {
        $backup_items = &$flex['body']['contents'][$backup_index]['contents'];
        
        // Google Sheet (index 2 ในกล่อง Backup)
        if (isset($backup_items[2]['contents'][1])) {
            if ($google_sheet !== '') {
                $backup_items[2]['contents'][1]['text'] = "เชื่อมต่อแล้ว";
                $backup_items[2]['contents'][1]['color'] = '#10B981';
            } else {
                $backup_items[2]['contents'][1]['text'] = "ไม่ได้ใส่";
                $backup_items[2]['contents'][1]['color'] = '#5C4E4E';
            }
        }
        
        // Google Drive (index 3 ในกล่อง Backup)
        if (isset($backup_items[3]['contents'][1])) {
            if ($google_drive !== '') {
                $backup_items[3]['contents'][1]['text'] = "เชื่อมต่อแล้ว";
                $backup_items[3]['contents'][1]['color'] = '#10B981';
            } else {
                $backup_items[3]['contents'][1]['text'] = "ไม่ได้ใส่";
                $backup_items[3]['contents'][1]['color'] = '#5C4E4E';
            }
        }
    }

    return $flex;
}

/** ดึง Spreadsheet / Drive ID จาก URL หรือค่าที่วางมา */
function normalize_google_resource_id(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if (preg_match('#drive\.google\.com/drive(?:/u/\d+)?/folders/([a-zA-Z0-9_-]+)#', $raw, $m)) {
        return $m[1];
    }
    if (preg_match('#docs\.google\.com/spreadsheets/d/([a-zA-Z0-9_-]+)#', $raw, $m)) {
        return $m[1];
    }
    if (preg_match('#^[a-zA-Z0-9_-]{10,}$#', $raw)) {
        return $raw;
    }
    return $raw;
}

function fetch_line_profile($line_user_id) {
    if (empty($line_user_id) || empty(LINE_ACCESS_TOKEN)) {
        return null;
    }

    $ch = curl_init("https://api.line.me/v2/bot/profile/" . rawurlencode($line_user_id));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . LINE_ACCESS_TOKEN
    ]);
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$result) {
        return null;
    }

    $profile = json_decode($result, true);
    return is_array($profile) ? $profile : null;
}

// การประมวลผลเซฟข้อมูลเมื่อมีการส่งแบบ AJAX POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    
    $line_user_id = trim($_POST['line_user_id'] ?? '');
    if ($line_user_id === '' && auth_is_logged_in()) {
        $line_user_id = trim($_SESSION['line_user_id'] ?? '');
    }
    if (auth_is_logged_in()) {
        $session_line_id = trim($_SESSION['line_user_id'] ?? '');
        if ($session_line_id !== '' && $line_user_id !== '' && $line_user_id !== $session_line_id) {
            echo json_encode(['success' => false, 'message' => 'LINE ID ไม่ตรงกับบัญชีที่ล็อกอิน']);
            exit();
        }
    }
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $sales_branch = trim($_POST['sales_branch'] ?? '');
    $consent_optional = isset($_POST['consent_optional']) ? (int)$_POST['consent_optional'] : 0;
    $privacy_quiz = trim($_POST['privacy_quiz'] ?? '');
    $google_sheet_id = normalize_google_resource_id(trim($_POST['google_sheet_id'] ?? ''));
    $google_drive_id = normalize_google_resource_id(trim($_POST['google_drive_id'] ?? ''));
    
    if (empty($line_user_id)) {
        echo json_encode(['success' => false, 'message' => 'ไม่พบ LINE User ID กรุณาเข้าใช้งานผ่านไลน์บอท']);
        exit();
    }
    if (empty($first_name) || empty($last_name)) {
        echo json_encode(['success' => false, 'message' => 'กรุณากรอกชื่อและนามสกุลให้ครบถ้วน']);
        exit();
    }
    if (empty($phone)) {
        echo json_encode(['success' => false, 'message' => 'กรุณากรอกเบอร์โทรศัพท์']);
        exit();
    }
    if (empty($google_drive_id)) {
        echo json_encode(['success' => false, 'message' => 'กรุณากรอก Google Drive Folder ID']);
        exit();
    }

    $check_stmt = $conn->prepare("SELECT encryption_key, trial_ends_at, is_subscribed, policy_accepted_at FROM users WHERE line_user_id = ? LIMIT 1");
    $check_stmt->bind_param("s", $line_user_id);
    $check_stmt->execute();
    $user_exist = $check_stmt->get_result()->fetch_assoc();
    $check_stmt->close();

    $is_profile_edit = $user_exist && !empty($user_exist['policy_accepted_at']);

    if ($sales_branch === '' || $sales_branch === 'metal_sheet' || !isset(branch_registration_options()[$sales_branch])) {
        $sales_branch = 'real_estate';
    }
    if (!branch_is_valid($sales_branch)) {
        echo json_encode(['success' => false, 'message' => 'สาขาสายงานไม่ถูกต้อง']);
        exit();
    }

    if (!$is_profile_edit && !policy_quiz_is_correct($privacy_quiz)) {
        echo json_encode(['success' => false, 'message' => 'คำตอบยังไม่ถูกต้อง — ข้อมูลที่คุณบันทึกในระบบเป็นของคุณ แยกตามบัญชี LINE ของคุณ']);
        exit();
    }
    
    $user_name = $first_name . ' ' . $last_name;
    $branch_label = branch_label($sales_branch);
    
    if ($user_exist) {
        $encryption_key = $user_exist['encryption_key'];
        $trial_ends_at = $user_exist['trial_ends_at'];
        $is_subscribed = $user_exist['is_subscribed'];
    } else {
        $encryption_key = bin2hex(random_bytes(16));
        $trial_ends_at = date('Y-m-d H:i:s', strtotime('+15 days'));
        $is_subscribed = 0;
    }
    
    // ค่าเริ่มต้นของตัวช่วยระบบบอทดั้งเดิม (เนื่องจากไม่มีในฟอร์มใหม่แต่จำเป็นต้องมีค่า)
    $bot_name = 'เลขา AI';
    $persona_style = 'formal_polite';
    $business_type = branch_business_type($sales_branch);
    $reject_cases_arr = ["ติดต่อไม่ได้", "งบประมาณไม่ถึง", "กู้ไม่ผ่าน/บูโร"];
    $reject_reasons_arr = [
        "ติดต่อไม่ได้" => "ลองติดต่อช่วงเวลานอกเวลาทำงานหรือส่งไลน์เสริมหรือยัง?",
        "งบประมาณไม่ถึง" => "ลองเสนอทรัพย์นอกเมือง/โครงการมือสองที่ราคาถูกลงหรือยัง?",
        "กู้ไม่ผ่าน/บูโร" => "ลองเช็กเงื่อนไขการกู้ร่วมหรือปิดบัตรเครดิตใบเล็กดูก่อนหรือยัง?"
    ];
    $reject_cases_json = json_encode($reject_cases_arr, JSON_UNESCAPED_UNICODE);
    $reject_reasons_json = json_encode($reject_reasons_arr, JSON_UNESCAPED_UNICODE);
    $policy_version = LUEPIN_POLICY_VERSION;
    $policy_accepted_at = $is_profile_edit && !empty($user_exist['policy_accepted_at'])
        ? $user_exist['policy_accepted_at']
        : date('Y-m-d H:i:s');
    
    // บันทึกแบบ Upsert
    $stmt = $conn->prepare("INSERT INTO users (
        line_user_id, user_name, first_name, last_name, phone, job_title, sales_branch, sales_display_name,
        consent_required, consent_optional, google_sheet_id, google_drive_id, 
        bot_name, persona_style, business_type, reject_cases, reject_reasons, 
        encryption_key, trial_ends_at, is_subscribed, policy_version, policy_accepted_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE 
        user_name = VALUES(user_name),
        first_name = VALUES(first_name),
        last_name = VALUES(last_name),
        phone = VALUES(phone),
        job_title = VALUES(job_title),
        sales_branch = VALUES(sales_branch),
        sales_display_name = VALUES(sales_display_name),
        consent_optional = VALUES(consent_optional),
        google_sheet_id = VALUES(google_sheet_id),
        google_drive_id = VALUES(google_drive_id),
        business_type = VALUES(business_type),
        policy_version = VALUES(policy_version),
        policy_accepted_at = VALUES(policy_accepted_at)");
        
    $stmt->bind_param("ssssssssisssssssssiss", 
        $line_user_id, $user_name, $first_name, $last_name, $phone, $branch_label, $sales_branch, $user_name,
        $consent_optional, $google_sheet_id, $google_drive_id,
        $bot_name, $persona_style, $business_type, $reject_cases_json, $reject_reasons_json,
        $encryption_key, $trial_ends_at, $is_subscribed, $policy_version, $policy_accepted_at
    );
    
    if ($stmt->execute()) {
        // ดึงข้อมูลผู้ใช้เพื่อส่ง Flex Checklist และตอบกลับ
        $get_stmt = $conn->prepare("SELECT id, line_user_id, user_name, first_name, last_name, phone, job_title, google_sheet_id, google_drive_id FROM users WHERE line_user_id = ? LIMIT 1");
        $get_stmt->bind_param("s", $line_user_id);
        $get_stmt->execute();
        $user_data = $get_stmt->get_result()->fetch_assoc();
        $get_stmt->close();
        
        // สร้าง Flex checklist และยิง Push Message ไปที่ไลน์ผู้ใช้
        $flex_contents = build_registration_checklist_flex($user_data);
        if ($flex_contents) {
            send_line_push($line_user_id, $flex_contents);
        }
        if (function_exists('line_link_user_rich_menu')) {
            line_link_user_rich_menu($line_user_id);
        }
        
        echo json_encode(['success' => true, 'user_id' => $user_data['id']]);
    } else {
        echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาดของฐานข้อมูล: ' . $conn->error]);
    }
    $stmt->close();
    exit();
}

$from_dashboard = isset($_GET['from']) && $_GET['from'] === 'dashboard';
if ($from_dashboard) {
    auth_require_login();
} elseif (isset($_GET['line_id']) && trim($_GET['line_id']) !== '' && !auth_is_logged_in()) {
    header('Location: login.php');
    exit();
} elseif (!auth_is_logged_in()) {
    header('Location: login.php');
    exit();
}

$from_web_login = auth_is_logged_in() && !$from_dashboard;
$line_id_get = $_SESSION['line_user_id'] ?? '';
$line_profile_get = fetch_line_profile($line_id_get);
if (!$line_profile_get && auth_is_logged_in()) {
    $session_name = trim($_SESSION['line_display_name'] ?? '');
    $session_pic = trim($_SESSION['line_picture_url'] ?? '');
    if ($session_name !== '' || $session_pic !== '') {
        $line_profile_get = [
            'displayName' => $session_name,
            'pictureUrl' => $session_pic,
        ];
    }
}

$reg_user = null;
$reg_is_pro = false;
$reg_days_left = 0;
$reg_trial_expired = false;
if ($line_id_get !== '') {
    $rs = $conn->prepare("SELECT first_name, last_name, trial_ends_at, is_subscribed FROM users WHERE line_user_id = ? LIMIT 1");
    $rs->bind_param("s", $line_id_get);
    $rs->execute();
    $reg_user = $rs->get_result()->fetch_assoc();
    $rs->close();
    if ($reg_user) {
        $reg_is_pro = !empty($reg_user['is_subscribed']);
        if (!$reg_is_pro && !empty($reg_user['trial_ends_at'])) {
            $reg_trial_ts = strtotime($reg_user['trial_ends_at']);
            $reg_days_left = max(0, (int)ceil(($reg_trial_ts - time()) / 86400));
            $reg_trial_expired = $reg_trial_ts < time();
        }
    }
}
if ($from_web_login && !$from_dashboard) {
    $logged_user = auth_current_user($conn);
    if ($logged_user && auth_registration_complete($logged_user)) {
        header('Location: dashboard.php');
        exit();
    }
}
$reg_has_profile = $reg_user && trim(($reg_user['first_name'] ?? '') . ($reg_user['last_name'] ?? '')) !== '';
$prefill_branch = 'real_estate';
$branch_options = branch_registration_options();
$policy_urls = policy_page_urls();
$quiz_options = policy_quiz_options();
$logged_user_for_edit = auth_current_user($conn);
$show_policy_sections = !$from_dashboard && !($logged_user_for_edit && !empty($logged_user_for_edit['policy_accepted_at']));
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ลงทะเบียนผู้ใช้งาน</title>
  <!-- โหลด Tailwind CSS และ Lucide Icons -->
  <script src="https://cdn.tailwindcss.com/3.4.17"></script>
  <script src="https://cdn.jsdelivr.net/npm/lucide@0.263.0/dist/umd/lucide.min.js"></script>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&amp;display=swap" rel="stylesheet">
  <link href="<?php echo htmlspecialchars(brand_mark_font_link()); ?>" rel="stylesheet">
  <!-- โหลด LINE LIFF SDK -->
  <script charset="utf-8" src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
  
  <style>
    body { font-family: 'DM Sans', sans-serif; }
    .toast { animation: slideUp 0.3s ease-out; }
    @keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    
    /* Loading Overlay */
    #loading-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: #ffffff;
        z-index: 9999;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        transition: opacity 0.4s ease;
    }
    .spinner {
        width: 40px;
        height: 40px;
        border: 3px solid rgba(43, 43, 43, 0.15);
        border-radius: 50%;
        border-top-color: #2B2B2B;
        animation: spin 1s linear infinite;
        margin-bottom: 15px;
    }
    @keyframes spin {
        to { transform: rotate(360deg); }
    }
    <?php echo brand_mark_css(); ?>
  </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4 bg-gray-50">
  
  <!-- Loading Overlay -->
  <div id="loading-overlay" <?php echo $line_id_get !== '' ? 'style="display:none;"' : ''; ?>>
      <div class="spinner"></div>
      <p class="text-gray-500 text-sm">กำลังเชื่อมต่อ LINE LIFF...</p>
  </div>

  <!-- หน้า Login (แสดงก่อนเมื่อยังไม่ได้ login) -->
  <div id="login-screen" class="hidden w-full max-w-sm">
    <div class="bg-white rounded-2xl p-8 shadow-lg border border-gray-200 text-center">
      <div class="flex justify-center mb-5">
        <?php render_luepin_mark('lg', 'light'); ?>
      </div>
      <h1 class="font-bold text-gray-900 text-2xl mb-1">ยินดีต้อนรับสู่ เลขา AI</h1>
      <p class="text-gray-500 text-sm mb-6">ระบบผู้ช่วย AI สำหรับนักขายอสังหาฯ</p>
      <div class="bg-gray-50 rounded-xl p-5 border border-gray-200">
        <p class="text-gray-600 text-sm mb-4">เข้าสู่ระบบด้วยบัญชี LINE ของคุณเพื่อลงทะเบียนใช้งาน</p>
        <button id="line-login-btn" onclick="doLineLogin()" class="w-full py-3 rounded-xl bg-[#06C755] hover:bg-[#05b34c] text-white font-bold text-base transition-all active:scale-95 flex items-center justify-center gap-2">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="white"><path d="M12 2C6.48 2 2 6.03 2 11c0 4.42 3.66 8.12 8.65 8.88.34.06.8.18.92.42.11.22.07.56.04.78l-.15.9c-.05.28-.22 1.1.96.6 1.18-.5 6.38-3.76 8.7-6.44C22.7 14.1 22 12.6 22 11c0-4.97-4.48-9-10-9z"/></svg>
          เข้าสู่ระบบด้วย LINE
        </button>
      </div>
    </div>
  </div>

  <main class="w-full max-w-md <?php echo $line_id_get !== '' ? '' : 'hidden'; ?>" id="main-content">
    <div class="bg-white rounded-2xl p-8 shadow-lg border border-gray-200">

      <?php if ($from_dashboard): ?>
        <a href="profile.php" class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-900 mb-4 transition">
          <i data-lucide="arrow-left" style="width:16px;height:16px;"></i> กลับโปรไฟล
        </a>
      <?php endif; ?>

      <?php if ($reg_user && ($from_dashboard || $reg_has_profile)): ?>
        <div class="mb-6 p-4 rounded-xl border <?php echo $reg_is_pro ? 'bg-green-50 border-green-200' : ($reg_trial_expired ? 'bg-gray-50 border-gray-200' : 'bg-amber-50 border-amber-200'); ?>">
          <p class="text-xs font-bold text-gray-500 mb-1 flex items-center gap-1">
            <i data-lucide="<?php echo $reg_is_pro ? 'crown' : 'clock'; ?>" style="width:14px;height:14px;"></i>
            สถานะแพ็กเกจ
          </p>
          <?php if ($reg_is_pro): ?>
            <p class="font-bold text-green-700">Pro · ใช้งานเต็มรูปแบบ</p>
          <?php elseif (!$reg_trial_expired && $reg_days_left > 0): ?>
            <p class="font-bold text-amber-800">Free Trial · เหลือ <?php echo $reg_days_left; ?> วัน</p>
          <?php else: ?>
            <p class="font-bold text-gray-700">หมดเวลาทดลองใช้ฟรี</p>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      
      <!-- ฟอร์มลงทะเบียนหลัก -->
      <div id="form-container">
        <div class="text-center mb-8">
          <div id="profile-container" class="hidden mb-4 flex-col items-center justify-center">
            <img id="profile-img" src="" alt="Profile" class="w-16 h-16 rounded-full border-2 border-gray-900 object-cover mb-2">
            <span id="profile-name" class="font-bold text-gray-900 text-sm"></span>
          </div>
          <div id="logo-icon-container" class="mb-4">
            <?php render_luepin_mark('md', 'light'); ?>
          </div>
          <h1 class="font-bold text-gray-900 text-3xl" id="reg-page-title"><?php echo ($from_dashboard || $reg_has_profile) ? 'โปรไฟล &amp; ตั้งค่า' : 'ลงทะเบียน'; ?></h1>
          <p class="text-gray-500 mt-2 text-sm" id="reg-page-subtitle"><?php echo ($from_dashboard || $reg_has_profile) ? 'แก้ไขข้อมูลบัญชีและการเชื่อมต่อ Google' : 'กรอกข้อมูลเพื่อสมัครใช้งาน LUEPiN'; ?></p>
        </div>

        <form id="reg-form" class="space-y-4">
          <!-- Hidden LINE User ID -->
          <input type="hidden" id="line_user_id" name="line_user_id" value="<?php echo htmlspecialchars($line_id_get); ?>">

          <div class="hidden">
            <label class="block text-sm font-medium mb-1 text-gray-800" for="lineid">Line ID (LINE User ID)</label>
            <input id="lineid" type="text" readonly class="w-full px-3 py-2.5 rounded-lg bg-gray-100 border border-gray-300 text-gray-500 cursor-not-allowed focus:outline-none transition" placeholder="กำลังดึงข้อมูล Line ID อัตโนมัติ...">
          </div>
          
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-sm font-medium mb-1 text-gray-800" for="fname">ชื่อ</label>
              <input id="fname" type="text" required class="w-full px-3 py-2.5 rounded-lg bg-gray-50 border border-gray-300 text-gray-900 placeholder-gray-400 focus:outline-none focus:border-gray-900 transition">
            </div>
            <div>
              <label class="block text-sm font-medium mb-1 text-gray-800" for="lname">นามสกุล</label>
              <input id="lname" type="text" required class="w-full px-3 py-2.5 rounded-lg bg-gray-50 border border-gray-300 text-gray-900 placeholder-gray-400 focus:outline-none focus:border-gray-900 transition">
            </div>
          </div>
          
          <div>
            <label class="block text-sm font-medium mb-1 text-gray-800" for="phone">เบอร์โทรศัพท์</label>
            <input id="phone" type="tel" required class="w-full px-3 py-2.5 rounded-lg bg-gray-50 border border-gray-300 text-gray-900 placeholder-gray-400 focus:outline-none focus:border-gray-900 transition">
          </div>
          
          <div>
            <input type="hidden" id="sales_branch" name="sales_branch" value="real_estate">
            <p class="text-sm text-gray-600 rounded-lg bg-gray-50 border border-gray-200 px-3 py-2.5">
              <span class="font-medium text-gray-800">สาขาสายงานเริ่มต้น:</span>
              ที่ปรึกษาอสังหาริมทรัพย์
              <span class="block text-xs text-gray-400 mt-1">หากต้องการปรับโครงสร้างสำหรับสาขาอื่น พิมพ์「ปรับโครงสร้าง」ในแชทเลขา AI</span>
            </p>
          </div>

          <?php if ($show_policy_sections): ?>
          <!-- คำถามสั้น — ยืนยันความเข้าใจ -->
          <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 space-y-3">
            <p class="text-sm font-medium text-gray-800">คำถามสั้นๆ</p>
            <p class="text-sm text-gray-600">ข้อมูล Lead / งานที่คุณบันทึกในระบบ ใครเป็นเจ้าของ?</p>
            <div class="space-y-2">
              <?php foreach ($quiz_options as $val => $label): ?>
              <label class="flex items-start gap-2 cursor-pointer">
                <input type="radio" name="privacy_quiz" value="<?php echo htmlspecialchars($val); ?>" required class="mt-1 w-4 h-4 accent-gray-900" <?php echo $val === 'owner' ? '' : ''; ?>>
                <span class="text-sm leading-snug text-gray-600"><?php echo htmlspecialchars($label); ?></span>
              </label>
              <?php endforeach; ?>
            </div>
            <p id="privacy-quiz-hint" class="hidden text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
              คำตอบที่ถูก: <strong>ของฉัน</strong> — ข้อมูลแยกตามบัญชี LINE ของคุณ ไม่ปนกับคนอื่น
            </p>
          </div>

          <!-- Consent blocks -->
          <div class="space-y-3 pt-2">
            <label class="flex items-start gap-2 cursor-pointer">
              <input id="consent1" type="checkbox" required class="mt-1 w-4 h-4 accent-gray-900">
              <span class="text-sm leading-snug text-gray-600">ฉันอ่าน <a href="privacy.php" target="_blank" rel="noopener" class="underline text-gray-900 font-medium">นโยบายข้อมูล</a> และ <a href="terms.php" target="_blank" rel="noopener" class="underline text-gray-900 font-medium">ข้อตกลงการใช้งาน</a> แล้ว ยินยอมให้ระบบ LUEPiN จัดเก็บและประมวลผลข้อมูลที่ฉันบันทึก <strong>ภายในแอปส่วนตัวของฉันเท่านั้น</strong></span>
            </label>
            <label class="flex items-start gap-2 cursor-pointer">
              <input id="consent2" type="checkbox" class="mt-1 w-4 h-4 accent-gray-900">
              <span class="text-sm leading-snug text-gray-600">ฉันยินยอมให้ระบบส่งต่อข้อมูลข้อความที่ผ่านการตัดข้อมูลระบุตัวตน (Anonymized Data) ไปยังระบบ AI หลังบ้าน (เช่น Google Gemini API) เพื่อใช้ในการประมวลผล สรุปข้อมูล และช่วยโต้ตอบดึงสติในการทำงานของฉัน</span>
            </label>
          </div>
          <?php endif; ?>

          <!-- Google Integration Section -->
          <div class="mt-6 pt-6 border-t border-gray-200">
            <div class="mb-4">
              <h3 class="font-semibold text-lg text-gray-900">🔒 เชื่อมต่อระบบจัดเก็บข้อมูล (Google Integration)</h3>
              <p class="text-sm text-gray-500 mt-1">กรอกข้อมูลเพื่ออนุญาตให้ระบบ LUEPIN สามารถดึงข้อมูลและสำรองข้อมูลไปยังคลังส่วนตัวของคุณ</p>
            </div>
            <div class="space-y-4">
              <div>
                <label class="block text-sm font-medium mb-1 text-gray-800" for="google-sheet">Google Spreadsheet URL / ID <span class="text-gray-400 font-normal">(ไม่บังคับ)</span></label>
                <input id="google-sheet" type="text" class="w-full px-3 py-2.5 rounded-lg bg-gray-50 border border-gray-300 text-gray-900 placeholder-gray-400 focus:outline-none focus:border-gray-900 transition" placeholder="ระบุ Spreadsheet ID หรือลิงก์ URL (ข้ามได้)">
                <p class="text-xs text-gray-400 mt-1">ถ้ามี Sheets อยู่แล้ว ใส่เพื่อให้ AI ดึงทำเลและรหัสทรัพย์มาช่วยประมวลผล — ไม่ใส่ก็ใช้งานได้</p>
              </div>
              <div>
                <label class="block text-sm font-medium mb-1 text-gray-800" for="google-drive">Google Drive Folder ID <span class="text-red-600">*</span></label>
                <input id="google-drive" type="text" required class="w-full px-3 py-2.5 rounded-lg bg-gray-50 border border-gray-300 text-gray-900 placeholder-gray-400 focus:outline-none focus:border-gray-900 transition" placeholder="Folder ID หรือลิงก์ https://drive.google.com/...">
                <p class="text-xs text-gray-400 mt-1">ใส่ไอดีหรือลิงก์โฟลเดอร์ Google Drive ของคุณ สำหรับจัดเก็บและสำรองข้อมูลดิบแยกส่วนตัว</p>
              </div>
            </div>
          </div>

          <button id="submit-btn" type="submit" class="w-full py-3 rounded-lg font-semibold mt-6 transition-all duration-200 flex items-center justify-center gap-2 bg-gray-900 text-white hover:bg-gray-800">
            <?php echo ($from_dashboard || $reg_has_profile) ? 'บันทึกการเปลี่ยนแปลง' : 'ลงทะเบียน'; ?>
          </button>
        </form>
      </div>

      <!-- หน้าแสดงผลสำเร็จ (ซ่อนเป็นค่าเริ่มต้น) -->
      <div id="success-card" class="hidden text-center">
        <div class="mb-8">
          <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-green-50 mb-6">
            <i data-lucide="check-circle" style="width:32px;height:32px;color:#16a34a;"></i>
          </div>
          <h2 class="font-bold text-gray-900 text-2xl mb-2">ลงทะเบียนสำเร็จ! 🎉</h2>
          <p class="text-gray-500 text-sm">ขอบคุณที่ลงทะเบียน เราส่งรายงาน Flex Message ตรวจสอบข้อมูลลงทะเบียนให้คุณใน LINE แชทแล้ว</p>
        </div>
        <div class="bg-gray-50 rounded-xl p-6 mb-8 border border-gray-200">
          <p class="text-xs text-gray-500 mb-3 uppercase tracking-wider font-semibold">รหัสผู้ใช้ของคุณ (DB user_id):</p>
          <p id="user-id-display" class="font-mono text-3xl font-bold text-gray-900 break-all"></p>
        </div>
        <button id="close-btn" type="button" class="w-full py-3 rounded-lg font-semibold transition-all duration-200 flex items-center justify-center gap-2 bg-gray-900 text-white hover:bg-gray-800">
          <?php echo $from_web_login ? 'เข้า Dashboard' : 'ปิดและกลับไป LINE'; ?>
        </button>
      </div>

    </div>
  </main>

  <script>
    // เริ่มทำงาน Lucide Icons
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
      window.lucide.createIcons();
    }

    const form = document.getElementById('reg-form');
    const submitBtn = document.getElementById('submit-btn');
    const formContainer = document.getElementById('form-container');
    const successCard = document.getElementById('success-card');
    const closeBtn = document.getElementById('close-btn');
    const userIdDisplay = document.getElementById('user-id-display');
    const overlay = document.getElementById('loading-overlay');
    const initialLineProfile = <?php echo json_encode($line_profile_get ?: new stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const fromDashboard = <?php echo $from_dashboard ? 'true' : 'false'; ?>;
    const fromWebLogin = <?php echo $from_web_login ? 'true' : 'false'; ?>;
    const showPolicySections = <?php echo $show_policy_sections ? 'true' : 'false'; ?>;

    // LINE LIFF Initialization
    document.addEventListener("DOMContentLoaded", function() {
        const liffId = "<?php echo defined('LINE_LIFF_ID') ? LINE_LIFF_ID : ''; ?>";
        const urlParams = new URLSearchParams(window.location.search);
        const lineIdFromUrl = urlParams.get('line_id') || document.getElementById('line_user_id').value;
        applyLineId(lineIdFromUrl);
        applyLineProfile(initialLineProfile);

        if (lineIdFromUrl) {
            hideOverlay();
            showMainContent();
            loadUserData(lineIdFromUrl);
            return;
        }

        if (!liffId) {
            console.warn("ไม่ได้กำหนด LINE_LIFF_ID ใน config.php");
            hideOverlay();
            if (lineIdFromUrl) {
                showMainContent();
                loadUserData(lineIdFromUrl);
            }
            return;
        }

        if (!window.liff) {
            console.error("LIFF SDK not loaded");
            hideOverlay();
            if (lineIdFromUrl) {
                showMainContent();
                loadUserData(lineIdFromUrl);
            } else {
                showLoginScreen();
            }
            return;
        }

        const liffInit = window.liff.init({ liffId: liffId });
        const liffTimeout = new Promise((_, reject) => {
            setTimeout(() => reject(new Error("LIFF init timeout")), 10000);
        });

        Promise.race([liffInit, liffTimeout])
            .then(() => {
                if (window.liff.isLoggedIn()) {
                    // ✅ Login แล้ว → ดึงโปรไฟล์ และแสดงฟอร์มเลย
                    fetchUserProfile();
                } else {
                    // ❌ ยังไม่ Login → แสดงหน้าปุ่มกด "เข้าสู่ระบบด้วย LINE"
                    hideOverlay();
                    if (lineIdFromUrl) {
                        showMainContent();
                        loadUserData(lineIdFromUrl);
                    } else {
                        showLoginScreen();
                    }
                }
            })
            .catch((err) => {
                console.error("LIFF init error:", err);
                // Fallback: ถ้า LIFF ล้มเหลว (เช่น เปิดผ่าน URL ตรงที่ไม่ใช่ liff.line.me)
                hideOverlay();
                if (lineIdFromUrl) {
                    showMainContent();
                    loadUserData(lineIdFromUrl);
                } else {
                    showLoginScreen();
                }
            });

        window.doLineLogin = function() {
            if (window.liff) {
                window.liff.login();
            }
        };

        function showLoginScreen() {
            document.getElementById('login-screen').classList.remove('hidden');
            document.getElementById('main-content').classList.add('hidden');
        }

        function showMainContent() {
            document.getElementById('login-screen').classList.add('hidden');
            document.getElementById('main-content').classList.remove('hidden');
        }

        function applyLineId(lineId) {
            if (!lineId) return;
            document.getElementById('line_user_id').value = lineId;
            const lineIdInput = document.getElementById('lineid');
            if (lineIdInput) {
                lineIdInput.value = lineId;
            }
        }

        function applyLineProfile(profile) {
            if (!profile || typeof profile !== 'object') return;
            const displayName = profile.displayName || '';
            const pictureUrl = profile.pictureUrl || '';

            if (pictureUrl) {
                const img = document.getElementById('profile-img');
                const imgContainer = document.getElementById('profile-container');
                const logoContainer = document.getElementById('logo-icon-container');
                if (img && imgContainer && logoContainer) {
                    img.src = pictureUrl;
                    imgContainer.classList.remove('hidden');
                    imgContainer.classList.add('flex');
                    logoContainer.style.display = 'none';
                }
            }

            if (displayName) {
                const nameSpan = document.getElementById('profile-name');
                if (nameSpan) nameSpan.textContent = displayName;

                const nameParts = displayName.split(' ');
                const fnameInput = document.getElementById('fname');
                const lnameInput = document.getElementById('lname');
                if (fnameInput && !fnameInput.value) fnameInput.value = nameParts[0] || '';
                if (lnameInput && !lnameInput.value && nameParts.length > 1) lnameInput.value = nameParts.slice(1).join(' ');
            }
        }

        function fetchUserProfile() {
            window.liff.getProfile()
                .then(profile => {
                    const lineUserId = profile.userId;
                    document.getElementById('line_user_id').value = lineUserId;
                    
                    const lineIdInput = document.getElementById('lineid');
                    if (lineIdInput) {
                        lineIdInput.value = lineUserId;
                    }

                    if (profile.pictureUrl) {
                        const img = document.getElementById('profile-img');
                        const imgContainer = document.getElementById('profile-container');
                        const logoContainer = document.getElementById('logo-icon-container');
                        if (img && imgContainer && logoContainer) {
                            img.src = profile.pictureUrl;
                            imgContainer.classList.remove('hidden');
                            imgContainer.classList.add('flex');
                            logoContainer.style.display = 'none';
                        }
                    }

                    if (profile.displayName) {
                        const nameSpan = document.getElementById('profile-name');
                        if (nameSpan) nameSpan.textContent = profile.displayName;

                        // แยกชื่อ-นามสกุลออโต้จาก LINE Display Name
                        const nameParts = profile.displayName.split(' ');
                        const fnameInput = document.getElementById('fname');
                        const lnameInput = document.getElementById('lname');
                        if (fnameInput && !fnameInput.value) fnameInput.value = nameParts[0] || '';
                        if (lnameInput && !lnameInput.value && nameParts.length > 1) lnameInput.value = nameParts.slice(1).join(' ');
                    }

                    // ✅ แสดงหน้าฟอร์มเลย
                    showMainContent();
                    loadUserData(lineUserId);
                })
                .catch(err => {
                    console.error("GetProfile Error:", err);
                    if (lineIdFromUrl) {
                        showMainContent();
                        loadUserData(lineIdFromUrl);
                    } else {
                        hideOverlay();
                        showLoginScreen();
                    }
                });
        }

        function loadUserData(lineId) {
            fetch(`api_get_user.php?line_id=${encodeURIComponent(lineId)}`)
                .then(res => res.json())
                .then(resData => {
                    if (resData.success && resData.data) {
                        const user = resData.data;
                        // เติมเฉพาะไม่ทับชื่อที่ดึงมาจาก LINE Profile
                        if (!document.getElementById('fname').value) document.getElementById('fname').value = user.first_name || '';
                        if (!document.getElementById('lname').value) document.getElementById('lname').value = user.last_name || '';
                        document.getElementById('phone').value = user.phone || '';
                        if (user.sales_branch && user.sales_branch !== 'metal_sheet') {
                            document.getElementById('sales_branch').value = user.sales_branch;
                        }
                        document.getElementById('consent2').checked = (parseInt(user.consent_optional) === 1);
                        document.getElementById('google-sheet').value = user.google_sheet_id || '';
                        document.getElementById('google-drive').value = user.google_drive_id || '';
                        if (user.first_name || user.last_name) {
                            const title = document.getElementById('reg-page-title');
                            const sub = document.getElementById('reg-page-subtitle');
                            if (title) title.textContent = 'โปรไฟล & ตั้งค่า';
                            if (sub) sub.textContent = 'แก้ไขข้อมูลบัญชีและการเชื่อมต่อ Google';
                            if (submitBtn) submitBtn.textContent = 'บันทึกการเปลี่ยนแปลง';
                        }
                    }
                    hideOverlay();
                })
                .catch(err => {
                    console.error("Load user data error: ", err);
                    hideOverlay();
                });
        }

        function hideOverlay() {
            if (overlay) {
                overlay.style.opacity = '0';
                setTimeout(() => overlay.remove(), 400);
            }
        }
    });

    // จัดการ Submit ฟอร์มด้วย AJAX
    form.addEventListener('submit', async (e) => {
      e.preventDefault();

      const lineUserId = document.getElementById('line_user_id').value.trim();
      if (!lineUserId) {
        showInlineError('ไม่พบ LINE User ID กรุณาเข้าสู่ระบบผ่าน LINE แชทก่อนทำรายการ');
        return;
      }

      if (!document.getElementById('google-drive').value.trim()) {
        showInlineError('กรุณากรอก Google Drive Folder ID');
        return;
      }

      submitBtn.disabled = true;
      submitBtn.style.opacity = '0.6';
      submitBtn.textContent = (document.getElementById('reg-page-title')?.textContent?.includes('โปรไฟล') ? 'กำลังบันทึก...' : 'กำลังลงทะเบียน...');

      const params = new URLSearchParams();
      params.append('line_user_id', lineUserId);
      params.append('first_name', document.getElementById('fname').value.trim());
      params.append('last_name', document.getElementById('lname').value.trim());
      params.append('phone', document.getElementById('phone').value.trim());
      params.append('sales_branch', document.getElementById('sales_branch').value);
      if (showPolicySections) {
        const quizChecked = document.querySelector('input[name="privacy_quiz"]:checked');
        params.append('privacy_quiz', quizChecked ? quizChecked.value : '');
      } else {
        params.append('privacy_quiz', 'owner');
      }
      params.append('consent_optional', document.getElementById('consent2').checked ? '1' : '0');
      params.append('google_sheet_id', document.getElementById('google-sheet').value.trim());
      params.append('google_drive_id', document.getElementById('google-drive').value.trim());

      try {
        const response = await fetch('register.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
          },
          body: params.toString(),
          credentials: 'same-origin',
        });

        const raw = await response.text();
        let result;
        try {
          result = JSON.parse(raw);
        } catch (parseErr) {
          console.error('Register response (non-JSON):', raw);
          throw new Error('เซิร์ฟเวอร์ตอบกลับไม่ถูกต้อง — ลองรีเฟรชหน้าแล้วส่งใหม่');
        }

        submitBtn.disabled = false;
        submitBtn.style.opacity = '1';
        submitBtn.textContent = document.getElementById('reg-page-title')?.textContent?.includes('โปรไฟล') ? 'บันทึกการเปลี่ยนแปลง' : 'ลงทะเบียน';

        if (result.success) {
          if (fromDashboard) {
            window.location.href = 'profile.php?saved=1';
            return;
          }
          if (fromWebLogin) {
            window.location.href = 'dashboard.php';
            return;
          }
          const userId = result.user_id || 'N/A';
          userIdDisplay.textContent = userId;
          formContainer.classList.add('hidden');
          successCard.classList.remove('hidden');
        } else {
          const hint = document.getElementById('privacy-quiz-hint');
          if (hint && result.message && result.message.includes('คำตอบ')) {
            hint.classList.remove('hidden');
          }
          showInlineError(result.message || 'เกิดข้อผิดพลาด กรุณาลองใหม่');
        }
      } catch (err) {
        submitBtn.disabled = false;
        submitBtn.style.opacity = '1';
        submitBtn.textContent = 'ลงทะเบียน';
        showInlineError(err.message || 'การเชื่อมต่อล้มเหลว กรุณาลองใหม่อีกครั้ง');
      }
    });

    function showInlineError(message) {
      const errorDiv = document.createElement('div');
      errorDiv.className = 'mt-4 p-4 rounded-lg bg-red-50 border border-red-200 text-center text-red-600 text-sm toast';
      errorDiv.textContent = message;
      form.appendChild(errorDiv);
      setTimeout(() => errorDiv.remove(), 4000);
    }

    closeBtn.addEventListener('click', () => {
      if (fromDashboard) {
        window.location.href = 'profile.php';
        return;
      }
      if (fromWebLogin) {
        window.location.href = 'dashboard.php';
        return;
      }
      if (window.liff && window.liff.closeWindow) {
        window.liff.closeWindow();
      } else {
        // Fallback
        form.reset();
        formContainer.classList.remove('hidden');
        successCard.classList.add('hidden');
      }
    });
  </script>
</body>
</html>
