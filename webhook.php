<?php
// webhook.php
// ไฟล์หลักรับ Webhook จาก LINE Messaging API และประมวลผลข้อมูลร่วมกับ OpenAI และ MySQL (Project Antigravity)

// โหลดไฟล์กำหนดค่าระบบและการเชื่อมต่อฐานข้อมูล
require_once 'config.php';
require_once 'openai_agent.php';
require_once 'task_helpers.php';
require_once 'project_survey_flow.php';
require_once 'auth.php';
require_once 'branch_config.php';
require_once 'policy_lib.php';
require_once 'welcome_flex_lib.php';
require_once __DIR__ . '/lib/flex_theme.php';
require_once __DIR__ . '/lib/listing_flex_lib.php';
require_once __DIR__ . '/lib/line_messaging.php';
require_once __DIR__ . '/lib/quick_reply_lib.php';
require_once __DIR__ . '/lib/owner_line_flow.php';
require_once __DIR__ . '/lib/lead_line_flow.php';

task_line_ensure_schema($conn);
owner_line_ensure_schema($conn);
lead_line_ensure_schema($conn);
listing_search_stats_ensure_schema($conn);

policy_ensure_schema($conn);
task_ensure_schema($conn);
project_flow_ensure_schema($conn);
lead_stage_events_ensure_schema($conn);

function webhook_user_is_ready($user) {
    return is_array($user) && auth_registration_complete($user);
}

// ดึง JSON Payload จาก LINE Webhook
$payload_raw = file_get_contents('php://input');
$payload = json_decode($payload_raw, true);

// บันทึก Log สำหรับตรวจสอบความถูกต้อง (สามารถนำออกในสภาวะ Production จริงได้)
file_put_contents('line_webhook_debug.log', $payload_raw . PHP_EOL, FILE_APPEND);

// LINE ต้องได้ 200 เสมอ — แม้ PHP error กลางทาง
http_response_code(200);
register_shutdown_function(static function (): void {
    if (http_response_code() === false || (int)http_response_code() < 200) {
        http_response_code(200);
    }
});

function webhook_menu_escape_text(string $text): bool
{
    return in_array(mb_strtolower(trim($text), 'UTF-8'), ['menu', 'เมนู', 'main'], true);
}

function webhook_force_menu_reply(mysqli $conn, array $user, string $replyToken): void
{
    if (function_exists('owner_line_clear_draft')) {
        owner_line_clear_draft($conn, (int)$user['id']);
    }
    if (function_exists('quick_reply_dispatch')) {
        quick_reply_dispatch($conn, $user, 'menu', [], $replyToken);
        return;
    }
    if (function_exists('quick_reply_send')) {
        quick_reply_send($replyToken, [[
            'type' => 'text',
            'text' => quick_reply_main_prompt(),
        ]], 'main');
    }
}

// ตรวจสอบโครงสร้างคำขอว่ามาจาก LINE หรือไม่
if (!$payload || !isset($payload['events'])) {
    http_response_code(200); // ต้องตอบกลับ 200 OK เสมอเพื่อไม่ให้ LINE ปิดช่องทาง
    exit();
}

// วนลูปประมวลผลแต่ละ Event ที่เข้ามา (LINE อาจส่งหลาย Event มาในรอบเดียว)
foreach ($payload['events'] as $event) {
    // 1. ดึงและตรวจสอบสิทธิ์ผู้ใช้งานผ่าน LINE User ID
    $line_user_id = $event['source']['userId'] ?? null;
    if (!$line_user_id) {
        continue;
    }
    
    // ค้นหาโปรไฟล์ผู้ใช้จากฐานข้อมูล
    $stmt = $conn->prepare("SELECT * FROM users WHERE line_user_id = ? LIMIT 1");
    $stmt->bind_param("s", $line_user_id);
    $stmt->execute();
    $user_result = $stmt->get_result();
    $user = $user_result->fetch_assoc();
    $stmt->close();
    
    $replyToken = $event['replyToken'] ?? null;
    $event_type = $event['type'] ?? '';

    // เพื่อนใหม่ Add OA
    if ($event_type === 'follow') {
        if ($replyToken) {
            send_welcome_flex_message($replyToken, $line_user_id);
        }
        if (function_exists('line_link_user_rich_menu')) {
            line_link_user_rich_menu($line_user_id);
        }
        continue;
    }

    // ยังไม่ลงทะเบียนครบ — ส่ง carousel ต้อนรับ / ปรับโครงสร้าง / ลิงก์ login
    if (!$user || !webhook_user_is_ready($user)) {
        if ($replyToken) {
            if ($event_type === 'postback') {
                $postback_data = $event['postback']['data'] ?? '';
                parse_str($postback_data, $pb_params);
                if (($pb_params['action'] ?? '') === 'qr') {
                    send_welcome_flex_message($replyToken, $line_user_id);
                    continue;
                }
            }
            if ($event_type === 'message' && ($event['message']['type'] ?? '') === 'text') {
                $text = trim($event['message']['text'] ?? '');
                $lower = mb_strtolower($text, 'UTF-8');
                if ($lower === 'ปรับโครงสร้าง' || $lower === 'ปรับโครงการ') {
                    send_customize_structure_ack($replyToken);
                    continue;
                }
            }
            send_welcome_flex_message($replyToken, $line_user_id);
        }
        continue;
    }
    
    // คีย์สำหรับเข้ารหัสลับเฉพาะของผู้ใช้รายนี้
    $encryption_key = $user['encryption_key'];
    
    // 2. ตรวจจับประเภท Event ของ LINE
    
    if ($event_type === 'message') {
        $message_type = $event['message']['type'] ?? '';
        if ($message_type === 'text') {
            $text = trim($event['message']['text'] ?? '');
            $lower_text = mb_strtolower($text, 'UTF-8');
            if ($replyToken) {
                if (webhook_menu_escape_text($text)) {
                    webhook_force_menu_reply($conn, $user, $replyToken);
                    continue;
                }

                if (line_wait_style_handle_text($conn, $user, $text, $replyToken)) {
                    continue;
                }

                if (task_line_handle_text($conn, $user, $text, $replyToken)) {
                    continue;
                }

                if (owner_line_handle_text($conn, $user, $text, $replyToken)) {
                    continue;
                }

                if (lead_line_handle_text($conn, $user, $text, $replyToken)) {
                    continue;
                }

                if (quick_reply_handle_text($conn, $user, $text, $replyToken)) {
                    continue;
                }

                if (project_flow_has_draft($conn, (int)$user['id']) || project_flow_is_trigger($text) || project_flow_is_cancel($text)) {
                    if (project_flow_handle_text($conn, $user, $text, $replyToken)) {
                        continue;
                    }
                }

                if ($lower_text === 'ปรับโครงสร้าง' || $lower_text === 'ปรับโครงการ') {
                    send_customize_structure_ack($replyToken);
                    continue;
                }

                if ($lower_text === 'register') {
                    send_welcome_flex_message($replyToken, $line_user_id);
                    continue;
                }

                if ($lower_text === 'สวัสดี' || $lower_text === 'ลงทะเบียน' || $lower_text === 'กรอกข้อมูลที่ยังไม่ครบ' || strpos($lower_text, 'เชื่อมต่อ google spreadsheet') !== false || strpos($lower_text, 'เชื่อมต่อ backup') !== false) {
                    send_welcome_flex_message($replyToken, $line_user_id);
                    continue;
                }

                $listing_request = extract_listing_request($text);
                if ($listing_request) {
                    line_begin_slow_work($line_user_id, 'listing', $user);
                    send_listing_flex_message($conn, $user, $replyToken, $listing_request);
                    continue;
                } elseif ($lower_text === 'รายงาน' || $lower_text === 'report' || $lower_text === 'ดูรายงาน' || $lower_text === 'ดูรีพอร์ต' || $lower_text === 'dashboard') {
                    line_begin_slow_work($line_user_id, 'report', $user);
                    send_leads_report_carousel($conn, $user, $replyToken);
                } else {
                    line_begin_slow_work($line_user_id, 'lead', $user);
                    handle_text_message($conn, $user, $text, $replyToken);
                }
            }
        } elseif ($message_type === 'image') {
            if ($replyToken && project_flow_handle_image($conn, $user, $event['message'], $replyToken)) {
                continue;
            }
        } elseif ($message_type === 'location') {
            if ($replyToken && owner_line_handle_location($conn, $user, $event['message'], $replyToken)) {
                continue;
            }
            if ($replyToken && project_flow_handle_location($conn, $user, $event['message'], $replyToken)) {
                continue;
            }
        }
    } elseif ($event_type === 'postback') {
        $postback_data = $event['postback']['data'] ?? '';
        $postback_line_params = $event['postback']['params'] ?? [];
        if ($replyToken) {
            parse_str($postback_data, $pb_params);
            try {
                if (task_line_handle_postback($conn, $user, $pb_params, $postback_line_params, $replyToken)) {
                    continue;
                }
                if (quick_reply_handle_postback($conn, $user, $pb_params, $replyToken)) {
                    continue;
                }
                if (owner_line_handle_postback($conn, $user, $pb_params, $replyToken, $postback_line_params)) {
                    continue;
                }
                if (lead_line_handle_postback($conn, $user, $pb_params, $replyToken)) {
                    continue;
                }
                if (strpos($postback_data, 'action=project_') !== false) {
                    if (project_flow_handle_postback($conn, $user, $pb_params, $replyToken)) {
                        continue;
                    }
                }
                handle_postback($conn, $user, $postback_data, $replyToken);
            } catch (Throwable $e) {
                @file_put_contents(
                    __DIR__ . '/line_webhook_debug.log',
                    '[webhook postback] ' . date('Y-m-d H:i:s') . ' | ' . $e->getMessage() . "\n",
                    FILE_APPEND
                );
                if (($pb_params['action'] ?? '') === 'owner_line' && ($pb_params['op'] ?? '') === 'confirm') {
                    quick_reply_send($replyToken, [[
                        'type' => 'text',
                        'text' => "บันทึกไม่สำเร็จชั่วคราว — ลองกด「▸ ✅ บันทึก」อีกครั้ง\nหรือพิมพ์ menu",
                        'quickReply' => owner_line_confirm_quick_reply(),
                    ]], 'project_sub');
                } else {
                    webhook_force_menu_reply($conn, $user, $replyToken);
                }
            }
        }
    }
}

// ส่งสถานะตอบรับให้ฝั่ง LINE ทราบ
http_response_code(200);
exit();

// ==========================================
// ส่วนฟังก์ชันย่อยการทำงานหลัก (Core Logics)
// ==========================================

/**
 * จัดการประมวลผลข้อความแชทที่เป็นตัวอักษร
 */
function handle_text_message($conn, $user, $text, $replyToken) {
    $encryption_key = $user['encryption_key'];
    
    // กำหนด System Prompt สำหรับใช้วิเคราะห์สกัดตัวแปรข้อมูลดิบ
    $prompt_parser_system = "คุณคือ AI ผู้ช่วยวิเคราะห์ข้อมูลลีดภาษาไทย หน้าที่ของคุณคือสกัดข้อมูลจากข้อความดิบที่ได้รับและแปลงเป็น JSON เสมอ โดยห้ามมีข้อความอธิบายอื่นนอกเหนือจาก JSON รูปแบบ JSON ที่ต้องส่งกลับเท่านั้น:
{
  \"target_code\": \"รหัสลีด เช่น L042 หรือ null หากไม่พบ\",
  \"update_type\": \"วิเคราะห์ประเภทการอัปเดต: 'REJECT_ATTEMPT' (หากลูกค้าหรือเอเจนต์มีแนวโน้มต้องการปฏิเสธ/เทดีล/แจ้งปัญหาปฏิเสธ) หรือ 'NORMAL' (การอัปเดตข้อมูลทั่วไป)\",
  \"priority_score\": \"คะแนนความสำคัญ 1-5 (จำนวนเต็ม) วิเคราะห์จากความเร่งด่วนหรือศักยภาพของดีล\",
  \"customer_insight\": \"สรุปจุดเจ็บปวด ความต้องการเด่นๆ หรือข้อมูลเชิงลึกของลูกค้า (เก็บเป็นภาษาไทย)\",
  \"deal_context\": \"สรุปบริบทของดีลหรือความคืบหน้าล่าสุด (เก็บเป็นภาษาไทย)\",
  \"reject_reason\": \"ระบุสาเหตุที่ต้องการปฏิเสธดีลนี้ (หาก update_type เป็น 'REJECT_ATTEMPT') หรือ null\",
  \"lose_attempt\": \"true หากเซลล์ทำลูกค้าหลุด/เงียบ/ไม่ติดตาม (ไม่ใช่ reject ชัด) มิฉะนั้น false\",
  \"status\": \"สถานะลีดที่เหมาะสม: Call, Follow, Appointment, Show, Nego, Reserve, Close, Bank, Win, Lose หรือ null ถ้าไม่เปลี่ยน\",
  \"next_plan_action\": \"แผนงานถัดไปที่ต้องทำ (ภาษาไทย) หรือ null\",
  \"next_plan_date\": \"วันที่แผนงาน YYYY-MM-DD หรือ null\",
  \"owner_code\": \"รหัสทรัพย์ที่ลูกค้าสนใจ เช่น O055 หรือ null\"
}";

    // ส่งข้อความไปวิเคราะห์ที่ OpenAI Chat Completions API
    $messages = [
        ["role" => "system", "content" => $prompt_parser_system],
        ["role" => "user", "content" => $text]
    ];
    
    $openai_res = call_openai_chat($messages, true);
    $content = $openai_res['choices'][0]['message']['content'] ?? '';
    
    // แปลงผลลัพธ์เป็น Array
    $parsed = json_decode($content, true);
    
    if (!$parsed) {
        send_line_text_reply($replyToken, "ขออภัย ระบบขัดข้องในการทำความเข้าใจข้อความ กรุณาลองส่งใหม่อีกครั้ง");
        return;
    }
    
    $target_code = $parsed['target_code'] ?? null;
    $update_type = $parsed['update_type'] ?? 'NORMAL';
    $priority_score = (int)($parsed['priority_score'] ?? 3);
    $customer_insight = $parsed['customer_insight'] ?? '';
    $deal_context = $parsed['deal_context'] ?? '';
    $reject_reason = $parsed['reject_reason'] ?? '';
    $lose_attempt = !empty($parsed['lose_attempt']) && $parsed['lose_attempt'] !== 'false';
    $new_status = trim($parsed['status'] ?? '');
    $next_plan_action = trim($parsed['next_plan_action'] ?? '');
    $next_plan_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $parsed['next_plan_date'] ?? '') ? $parsed['next_plan_date'] : null;
    $parsed_owner_code = trim($parsed['owner_code'] ?? '');
    
    // หากสแกนหารหัสลีดไม่พบ
    if (empty($target_code)) {
        send_line_text_reply($replyToken, "เลขา AI ไม่พบรหัสลีด (เช่น L042) ในข้อความของท่าน กรุณาระบุรหัสลีดเพื่อให้เลขาอัปเดตข้อมูลได้อย่างถูกต้องค่ะ");
        return;
    }
    
    // ค้นหาข้อมูลลีดปัจจุบัน
    $stmt = $conn->prepare("SELECT * FROM leads WHERE user_id = ? AND lead_code = ? LIMIT 1");
    $stmt->bind_param("is", $user['id'], $target_code);
    $stmt->execute();
    $lead_result = $stmt->get_result();
    $lead = $lead_result->fetch_assoc();
    $stmt->close();
    
    // กรณีที่ 1: ตรวจพบความต้องการยกเลิกดีล (REJECT_ATTEMPT) — ไม่ใช่ Lose
    if ($update_type === 'REJECT_ATTEMPT' && !$lose_attempt) {
        // เข้ารหัสข้อความดิบเพื่อบันทึกไว้เป็นหลักฐานชั่วคราว
        $raw_message_enc = encrypt_data($text, $encryption_key);
        $customer_insight_enc = encrypt_data($customer_insight, $encryption_key);
        
        if ($lead) {
            // อัปเดตสถานะเป็น Hold_Reject และฝากข้อความดิบไว้ที่ deal_context_enc
            $stmt = $conn->prepare("UPDATE leads SET status = 'Hold_Reject', deal_context_enc = ?, customer_insight_enc = ?, priority_score = ? WHERE id = ?");
            $stmt->bind_param("ssiii", $raw_message_enc, $customer_insight_enc, $priority_score, $lead['id']);
            $stmt->execute();
            $stmt->close();
        } else {
            // หากยังไม่มีลีดนี้ ให้สร้างลีดใหม่ในสถานะ Hold_Reject
            $lead_name_enc = encrypt_data("ลีดใหม่ " . $target_code, $encryption_key);
            $status = 'Hold_Reject';
            $stmt = $conn->prepare("INSERT INTO leads (user_id, lead_code, lead_name_enc, priority_score, customer_insight_enc, deal_context_enc, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ississs", $user['id'], $target_code, $lead_name_enc, $priority_score, $customer_insight_enc, $raw_message_enc, $status);
            $stmt->execute();
            $stmt->close();
        }
        
        // ดึงสไตล์ของบอทและประเภทธุรกิจเพื่อส่งต่อให้ OpenAI
        $persona_templates = [
            'formal_polite' => 'มีความสุภาพ นอบน้อม เป็นทางการ ใช้คำว่า "ขออภัยอย่างสูง" "ทางเราขอเรียนชี้แจง" และลงท้ายอย่างสุภาพเสมอ',
            'casual_friendly' => 'เป็นกันเอง สบายๆ เหมือนเพื่อนคู่คิด เป็นมิตร ใช้ภาษาที่เข้าใจง่ายและอบอุ่น',
            'assertive_professional' => 'เป็นมืออาชีพ ตรงไปตรงมา กระชับ มั่นใจ และเสนอทางเลือกอย่างเป็นขั้นตอนชัดเจน'
        ];
        
        $business_templates = [
            'Real Estate' => 'ธุรกิจอสังหาริมทรัพย์ (การซื้อขาย/เช่า บ้าน คอนโด ที่ดิน)',
            'Metal Sheet' => 'ธุรกิจเมทัลชีท / หลังคา (มัดจำ สำรวจหน้างาน ส่งมอบงาน)',
            'Automotive' => 'ธุรกิจยานยนต์ (การซื้อขาย/เช่า รถยนต์)',
            'Financial' => 'ธุรกิจการเงิน สินเชื่อ และการลงทุน'
        ];
        
        $persona_desc = $persona_templates[$user['persona_style']] ?? $persona_templates['formal_polite'];
        $business_desc = $business_templates[$user['business_type']] ?? $user['business_type'];
        
        // กำหนด Gatekeeper System Prompt
        $prompt_gatekeeper_system = "คุณคือเลขา AI ชื่อ '{$user['bot_name']}' ทำงานให้ธุรกิจประเภท: {$business_desc}
คุณต้องสวมบทบาทตอบกลับลูกค้าตามบุคลิกนี้: {$persona_desc}

ขณะนี้เกิดสถานการณ์ที่ลูกค้าหรือผู้เกี่ยวข้องส่งสัญญาณขอยกเลิกหรือปฏิเสธดีล (Reject Attempt) ด้วยเหตุผล: \"{$reject_reason}\" สำหรับดีลรหัส \"{$target_code}\"
หน้าที่ของคุณคือร่างคำพูดตอบกลับเพื่อส่งแชทไปถึงลูกค้าเพื่อปกป้องความสัมพันธ์/ดีล (Defensive Response) โดยการชะลอการยกเลิก เสนอข้อเสนอพิเศษเพิ่มเติม หรือเสนอตัวช่วยพาร์ทเนอร์/ร่วมทุน เพื่อรักษาลูกค้าไว้ก่อน
คำแนะนำ: เขียนเฉพาะบทสนทนาที่จะนำไปพิมพ์ตอบกลับลูกค้าเท่านั้น ใช้ภาษาที่เป็นธรรมชาติ ตรงตามบุคลิก ไม่สั้นหรือยาวเกินไป ห้ามเขียนคำสั่งสอนหรือเกริ่นนอกเรื่อง";

        $gatekeeper_messages = [
            ["role" => "system", "content" => $prompt_gatekeeper_system],
            ["role" => "user", "content" => "สร้างข้อความตอบกลับเพื่อรักษาดีลที่ดีที่สุด"]
        ];
        
        // เรียกใช้ OpenAI ครั้งที่สองเพื่อดึง Defensive Response
        $gatekeeper_res = call_openai_chat($gatekeeper_messages, false);
        $defensive_response = $gatekeeper_res['choices'][0]['message']['content'] ?? 'ระบบตรวจพบบริบทการยกเลิกการซื้อขาย กรุณายืนยันการดำเนินการต่อไป';
        
        // ส่ง Flex Message กลับไปที่ LINE พร้อมปุ่มกดดำเนินการแบบ Postback
        send_defensive_flex_message($replyToken, $target_code, $reject_reason, $defensive_response);
        
    } else {
        // กรณีที่ 2: อัปเดตดีลแบบปกติ หรือ Lose (เซลล์ทำหลุด)
        $customer_insight_enc = encrypt_data($customer_insight, $encryption_key);
        $deal_context_enc = encrypt_data($deal_context, $encryption_key);
        $next_plan_enc = $next_plan_action !== '' ? encrypt_data($next_plan_action, $encryption_key) : null;
        
        if ($lose_attempt || $new_status === 'Lose') {
            $new_status = 'Lose';
        } elseif ($new_status === '') {
            $new_status = $lead ? ($lead['status'] ?? 'Follow') : 'Call';
        }
        $valid_statuses = array_merge(lead_funnel_statuses(), lead_terminal_statuses(), ['Active', 'Pending_Cobroker']);
        if (!in_array($new_status, $valid_statuses, true)) {
            $new_status = $lead ? ($lead['status'] ?? 'Follow') : 'Call';
        }
        $old_status = $lead['status'] ?? '';
        $owner_code_val = $parsed_owner_code !== '' ? $parsed_owner_code : ($lead['owner_code'] ?? null);
        
        if ($lead) {
            $stmt = $conn->prepare("UPDATE leads SET customer_insight_enc = ?, deal_context_enc = ?, priority_score = ?, status = ?,
                next_plan_action_enc = COALESCE(?, next_plan_action_enc), next_plan_date = COALESCE(?, next_plan_date),
                owner_code = COALESCE(?, owner_code), updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("ssissssi", $customer_insight_enc, $deal_context_enc, $priority_score, $new_status,
                $next_plan_enc, $next_plan_date, $owner_code_val, $lead['id']);
            $stmt->execute();
            $stmt->close();
            $lead_name = decrypt_data($lead['lead_name_enc'], $encryption_key);
        } else {
            $lead_name = "ลีด " . $target_code;
            $lead_name_enc = encrypt_data($lead_name, $encryption_key);
            $stmt = $conn->prepare("INSERT INTO leads (user_id, lead_code, lead_name_enc, priority_score, customer_insight_enc, deal_context_enc, status, next_plan_action_enc, next_plan_date, owner_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ississssss", $user['id'], $target_code, $lead_name_enc, $priority_score, $customer_insight_enc, $deal_context_enc, $new_status, $next_plan_enc, $next_plan_date, $owner_code_val);
            $stmt->execute();
            $lead_id_new = (int)$conn->insert_id;
            $stmt->close();
            $old_status = '';
            $lead = ['id' => $lead_id_new];
        }
        
        if ($new_status !== $old_status && !empty($lead['id'])) {
            $note = encrypt_data($deal_context ?: "อัปเดตจาก LINE", $encryption_key);
            log_lead_status($conn, $user['id'], (int)$lead['id'], $new_status, $note);
        }
        if ($next_plan_action !== '' && $next_plan_date) {
            sync_lead_plan_task($conn, $user['id'], $encryption_key, $target_code, $lead_name, $next_plan_action, $next_plan_date, $owner_code_val ?: '');
        }
        handle_lead_status_side_effects($conn, $user['id'], $encryption_key, $target_code, $lead_name, $new_status, $owner_code_val ?: '');
        
        sync_to_google_sheet($user['google_sheet_id'], [
            'lead_code' => $target_code,
            'lead_name' => $lead_name,
            'priority_score' => $priority_score,
            'customer_insight' => $customer_insight,
            'deal_context' => $deal_context,
            'status' => $new_status,
            'next_plan_action' => $next_plan_action,
            'next_plan_date' => $next_plan_date,
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        
        $task_note = ($next_plan_action && $next_plan_date) ? "\n- สร้าง/อัปเดต Task: {$next_plan_action} ({$next_plan_date})" : '';
        send_line_text_reply($replyToken, "เลขา AI บันทึกลีด {$target_code} แล้ว:\n- สถานะ: {$new_status}\n- คะแนน: {$priority_score}/5{$task_note}\n- ข้อมูลเข้ารหัสในฐานข้อมูลแล้ว");
    }
}

/**
 * จัดการการตอบกลับเมื่อผู้ใช้อินเตอร์เฟซกดปุ่มจาก LINE Postback
 */
function handle_postback($conn, $user, $postback_data, $replyToken) {
    $encryption_key = $user['encryption_key'];
    
    // แปลงพารามิเตอร์จากคิวรี่สตริงของ Postback Data
    parse_str($postback_data, $params);
    $action = $params['action'] ?? '';
    $lead_code = $params['lead_code'] ?? '';
    $reason = $params['reason'] ?? '';
    
    if (empty($lead_code)) {
        send_line_text_reply($replyToken, "ขออภัย ระบบไม่พบรหัสลีดอ้างอิงสำหรับการดำเนินการ");
        return;
    }
    
    // ดึงข้อมูลลีดที่ต้องการดำเนินการ
    $stmt = $conn->prepare("SELECT * FROM leads WHERE user_id = ? AND lead_code = ? LIMIT 1");
    $stmt->bind_param("is", $user['id'], $lead_code);
    $stmt->execute();
    $lead = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$lead) {
        send_line_text_reply($replyToken, "ไม่พบข้อมูลลีดรหัส {$lead_code} ในฐานข้อมูล");
        return;
    }
    
    if ($action === 'confirm_reject') {
        // อัปเดตสถานะเป็น Rejected อย่างถาวร
        $status = 'Rejected';
        $stmt = $conn->prepare("UPDATE leads SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $status, $lead['id']);
        $stmt->execute();
        $stmt->close();
        close_lead_linked_tasks($conn, $user['id'], $lead_code);
        
        // ดึงข้อความดิบที่เราเก็บสำรองไว้ตอน REJECT_ATTEMPT
        $raw_message_enc = $lead['deal_context_enc'];
        
        // บันทึกประวัติการถูกปฏิเสธลงในตารางประวัติ reject_logs
        $stmt = $conn->prepare("INSERT INTO reject_logs (user_id, target_code, reject_reason, raw_message_enc) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isss", $user['id'], $lead_code, $reason, $raw_message_enc);
        $stmt->execute();
        $stmt->close();
        
        // ดึงและถอดรหัสข้อมูลเดิมเพื่อทำข้อมูลไปซิงก์บนชีต
        $lead_name = decrypt_data($lead['lead_name_enc'], $encryption_key);
        $customer_insight = decrypt_data($lead['customer_insight_enc'], $encryption_key);
        
        sync_to_google_sheet($user['google_sheet_id'], [
            'lead_code' => $lead_code,
            'lead_name' => $lead_name,
            'priority_score' => $lead['priority_score'],
            'customer_insight' => $customer_insight,
            'deal_context' => "ปฏิเสธดีล: " . $reason,
            'status' => 'Rejected',
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        
        // ส่งข้อความแจ้งสำเร็จแบบสวยงาม
        send_rejection_confirmed_flex($replyToken, $lead_code, $reason);
        
    } elseif ($action === 'seek_cobroker') {
        // อัปเดตสถานะเป็น Pending_Cobroker
        $status = 'Pending_Cobroker';
        $stmt = $conn->prepare("UPDATE leads SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $status, $lead['id']);
        $stmt->execute();
        $stmt->close();
        
        // ดึงและถอดรหัสข้อมูลเพื่อทำข้อมูลไปซิงก์บนชีต
        $lead_name = decrypt_data($lead['lead_name_enc'], $encryption_key);
        $customer_insight = decrypt_data($lead['customer_insight_enc'], $encryption_key);
        $deal_context = decrypt_data($lead['deal_context_enc'], $encryption_key);
        
        sync_to_google_sheet($user['google_sheet_id'], [
            'lead_code' => $lead_code,
            'lead_name' => $lead_name,
            'priority_score' => $lead['priority_score'],
            'customer_insight' => $customer_insight,
            'deal_context' => "หาทางเลือกเสริม/Co-broker: " . $deal_context,
            'status' => 'Pending_Cobroker',
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        
        // ส่งข้อความยืนยันการตั้งหา Co-broker
        send_cobroker_confirmed_flex($replyToken, $lead_code);
    }
}

// ==========================================
// ส่วนฟังก์ชันช่วยการส่ง HTTP APIs (Helper Functions)
// ==========================================


/**
 * ฟังก์ชันส่งตอบกลับไปยัง LINE Messaging API
 */
function send_line_reply($payload, string $qrKind = 'main') {
    if (function_exists('quick_reply_attach') && !empty($payload['messages'])) {
        $last = count($payload['messages']) - 1;
        if ($last >= 0 && empty($payload['messages'][$last]['quickReply'])) {
            $payload['messages'] = quick_reply_attach($payload['messages'], $qrKind);
        }
    }
    $ch = curl_init("https://api.line.me/v2/bot/message/reply");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer " . LINE_ACCESS_TOKEN
    ]);
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // บันทึก Log การตอบกลับ
    file_put_contents(
        __DIR__ . '/line_webhook_debug.log', 
        "[LINE Reply] " . date('Y-m-d H:i:s') . " | HTTP: " . $httpCode . " | Response: " . $result . " | Payload: " . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n", 
        FILE_APPEND
    );
    
    return (int)$httpCode;
}

/**
 * ส่งข้อความตัวอักษรธรรมดาตอบกลับทาง LINE
 */
function send_line_text_reply($replyToken, $text) {
    $payload = [
        "replyToken" => $replyToken,
        "messages" => [
            [
                "type" => "text",
                "text" => $text
            ]
        ]
    ];
    return send_line_reply($payload);
}

function send_line_push_text(string $lineUserId, string $text, bool $withQuickReply = false, string $qrKind = 'main'): int
{
    $messages = [['type' => 'text', 'text' => $text]];
    if ($withQuickReply && function_exists('quick_reply_attach')) {
        $messages = quick_reply_attach($messages, $qrKind);
    }
    $payload = [
        'to' => $lineUserId,
        'messages' => $messages,
    ];
    $ch = curl_init('https://api.line.me/v2/bot/message/push');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . LINE_ACCESS_TOKEN,
    ]);
    $result = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    file_put_contents(
        __DIR__ . '/line_webhook_debug.log',
        '[LINE Push] ' . date('Y-m-d H:i:s') . " | HTTP: {$httpCode} | Response: {$result} | Payload: "
        . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n",
        FILE_APPEND
    );

    return $httpCode;
}

function build_public_base_url() {
    $http_host = $_SERVER['HTTP_HOST'] ?? 'testlekha.free.nf';
    $is_local = strpos($http_host, 'localhost') !== false || strpos($http_host, '127.0.0.1') !== false;
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strpos($http_host, 'ngrok') !== false
        ? 'https://'
        : 'http://';

    if (!$is_local && strpos($http_host, 'ngrok') === false) {
        $protocol = 'https://';
    }

    $script_dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $script_dir = trim($script_dir, '/');
    $encoded_dir = '';
    if ($script_dir !== '') {
        $parts = array_map('rawurlencode', explode('/', $script_dir));
        $encoded_dir = '/' . implode('/', $parts);
    }

    return rtrim($protocol . $http_host . $encoded_dir, '/');
}

/**
 * ส่งการ์ดต้อนรับให้ผู้ใช้สมัครบริการ (Flex Message)
 */
function send_welcome_flex_message($replyToken, $line_user_id) {
    quick_reply_send($replyToken, [
        build_welcome_onboarding_carousel(),
    ], 'main');
}

/**
 * ส่งคำแนะนำรับมือเพื่อรักษาความสัมพันธ์กับลีด (Flex Message) พร้อมปุ่มตอบกลับ (Postback)
 */
function send_defensive_flex_message($replyToken, $lead_code, $reject_reason, $defensive_response, $reject_cases = [], $hint_question = '') {
    $c = flex_theme_colors();
    $flex_payload = [
        "replyToken" => $replyToken,
        "messages" => [
            [
                "type" => "flex",
                "altText" => "ผู้ช่วยเตือน: ดีล {$lead_code} มีแนวโน้มการปฏิเสธ",
                "contents" => [
                    "type" => "bubble",
                    "header" => flex_theme_header_box("ตรวจพบบริบทปฏิเสธดีล ({$lead_code})", null, 'brown'),
                    "styles" => array_merge(flex_theme_bubble_styles(), ["header" => ["backgroundColor" => $c['brown']]]),
                    "body" => [
                        "type" => "box",
                        "layout" => "vertical",
                        "spacing" => "md",
                        "paddingAll" => "16px",
                        "contents" => [
                            flex_theme_text("บทสนทนาแนะนำเชิงรับมือโดย AI:", "xs", "muted", "none", true),
                            flex_theme_text($defensive_response, "sm", "dark", "none", true),
                            ["type" => "separator", "color" => $c['border']],
                            flex_theme_text("สาเหตุการเท: " . $reject_reason, "xs", "faint"),
                        ]
                    ],
                    "footer" => [
                        "type" => "box",
                        "layout" => "vertical",
                        "spacing" => "sm",
                        "contents" => (function() use ($lead_code, $reject_cases, $defensive_response, $c) {
                            $btns = [];
                            $copyLead = line_flex_clipboard_button('📋 คัดลอกรหัส ' . $lead_code, $lead_code);
                            $copyReply = line_flex_clipboard_button('📋 คัดลอกข้อความตอบ', $defensive_response);
                            if ($copyLead) {
                                $btns[] = $copyLead;
                            }
                            if ($copyReply) {
                                $btns[] = $copyReply;
                            }
                            $cases_to_show = !empty($reject_cases) ? $reject_cases : ['ปล่อยดีล'];
                            foreach (array_slice($cases_to_show, 0, 3) as $case) {
                                $label = mb_substr('🚫 ' . $case, 0, 20);
                                $btns[] = [
                                    "type"   => "button",
                                    "style"  => "primary",
                                    "color"  => $c['brown'],
                                    "height" => "sm",
                                    "action" => [
                                        "type"  => "postback",
                                        "label" => $label,
                                        "data"  => "action=confirm_reject&lead_code=" . urlencode($lead_code) . "&reason=" . urlencode($case)
                                    ]
                                ];
                            }
                            $btns[] = [
                                "type"   => "button",
                                "style"  => "secondary",
                                "height" => "sm",
                                "action" => [
                                    "type"  => "postback",
                                    "label" => "🤝 หา Co-broker แทน",
                                    "data"  => "action=seek_cobroker&lead_code=" . urlencode($lead_code)
                                ]
                            ];
                            return $btns;
                        })()
                    ]
                ]
            ]
        ]
    ];
    send_line_reply($flex_payload);
}

/**
 * ส่งคำยืนยันปิดดีลแบบปฏิเสธสำเร็จ (Flex Message)
 */
function send_rejection_confirmed_flex($replyToken, $lead_code, $reason) {
    $c = flex_theme_colors();
    $flex_payload = [
        "replyToken" => $replyToken,
        "messages" => [
            [
                "type" => "flex",
                "altText" => "ยกเลิกดีล {$lead_code} สำเร็จ",
                "contents" => [
                    "type" => "bubble",
                    "header" => flex_theme_header_box("ปิดสถานะดีลสำเร็จ", null, 'neutral'),
                    "styles" => array_merge(flex_theme_bubble_styles(), ["header" => ["backgroundColor" => $c['text_muted']]]),
                    "body" => [
                        "type" => "box",
                        "layout" => "vertical",
                        "spacing" => "xs",
                        "paddingAll" => "16px",
                        "contents" => [
                            flex_theme_text("ระบบปิดงานดีลรหัส {$lead_code} เป็นสถานะ 'Rejected'", "sm", "dark", "none", true),
                            flex_theme_text("เหตุผล: " . $reason, "xs", "faint", "md"),
                        ]
                    ]
                ]
            ]
        ]
    ];
    send_line_reply($flex_payload);
}

/**
 * ส่งคำยืนยันการขอจับคู่พาร์ทเนอร์/ร่วมทุนดีล (Flex Message)
 */
function send_cobroker_confirmed_flex($replyToken, $lead_code) {
    $c = flex_theme_colors();
    $flex_payload = [
        "replyToken" => $replyToken,
        "messages" => [
            [
                "type" => "flex",
                "altText" => "ส่งต่อดีล {$lead_code} ให้ Co-broker",
                "contents" => [
                    "type" => "bubble",
                    "header" => flex_theme_header_box("ส่งต่อสำเร็จ", "รหัส {$lead_code}", 'green'),
                    "styles" => array_merge(flex_theme_bubble_styles(), ["header" => ["backgroundColor" => $c['green']]]),
                    "body" => [
                        "type" => "box",
                        "layout" => "vertical",
                        "paddingAll" => "16px",
                        "contents" => [
                            flex_theme_text("เปลี่ยนสถานะลีด {$lead_code} เป็น 'Pending_Cobroker' เพื่อรอหาทางเลือกกับเอเจนต์/พาร์ทเนอร์ภายนอก", "sm", "dark", "none", true),
                        ]
                    ]
                ]
            ]
        ]
    ];
    send_line_reply($flex_payload);
}

/**
 * ซิงก์ข้อมูลนำเสนอไปยัง Google Sheets ของผู้ใช้งานโดยระบุ ID
 */
function sync_to_google_sheet($google_sheet_id, $data) {
    if (empty($google_sheet_id)) {
        return false;
    }
    
    // ตั้งค่า URL ของ Google Apps Script ที่ Deploy เป็น Web App
    // (เป็นวิธีการเชื่อมต่อที่ได้รับความนิยมสูงสำหรับ Native PHP เพราะไม่ต้องลง SDK ขนาดใหญ่)
    $apps_script_url = "https://script.google.com/macros/s/YOUR_SCRIPT_ID/exec";
    
    $payload = [
        "spreadsheetId" => $google_sheet_id,
        "sheetName" => "Leads",
        "data" => [
            $data['lead_code'] ?? '',
            $data['lead_name'] ?? '',
            $data['priority_score'] ?? 3,
            $data['customer_insight'] ?? '',
            $data['deal_context'] ?? '',
            $data['status'] ?? 'Active',
            $data['updated_at'] ?? date('Y-m-d H:i:s')
        ]
    ];
    
    $ch = curl_init($apps_script_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $http_code === 200;
}

/**
 * ดึงข้อมูลลีดจากฐานข้อมูลมาเข้ารหัส/ถอดรหัสเพื่อสร้าง LINE Flex Carousel Report ส่งกลับให้ผู้ใช้
 */
function send_leads_report_carousel($conn, $user, $replyToken) {
    $encryption_key = $user['encryption_key'];
    
    // ดึงข้อมูลลีดล่าสุด 10 รายการของผู้ใช้รายนี้ (LINE Carousel ใส่ได้สูงสุด 10 ใบ)
    $stmt = $conn->prepare("SELECT * FROM leads WHERE user_id = ? ORDER BY updated_at DESC LIMIT 10");
    $stmt->bind_param("i", $user['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $bubbles = [];
    $today_str = date('d M Y');
    
    while ($row = $result->fetch_assoc()) {
        $lead_code = htmlspecialchars($row['lead_code'] ?? '');
        
        // ถอดรหัสข้อมูลลีด
        $lead_name = htmlspecialchars(decrypt_data($row['lead_name_enc'], $encryption_key) ?? 'ไม่ระบุชื่อ');
        $customer_insight = htmlspecialchars(decrypt_data($row['customer_insight_enc'], $encryption_key) ?? 'ไม่มีข้อมูลเชิงลึก');
        $deal_context = htmlspecialchars(decrypt_data($row['deal_context_enc'], $encryption_key) ?? 'ไม่มีข้อมูลความคืบหน้า');
        $priority_score = (int)($row['priority_score'] ?? 3);
        $status = $row['status'] ?? 'Active';

        $c = flex_theme_colors();
        $pct = $priority_score * 20;
        $width_str = $pct . "%";
        $grade_text = flex_theme_priority_grade($priority_score);
        $grade_color = flex_theme_priority_color($priority_score);
        $status_badge = flex_theme_lead_status_badge($status);
        $badge_text = $status_badge['label'];
        $badge_bg_color = $status_badge['bg'];
        $badge_text_color = $status_badge['text'];
        $status_glow_color = $status_badge['accent'];
        
        // พยายามสกัดเบอร์โทรศัพท์จากข้อมูลลีด
        $phone = 'ไม่ระบุ';
        $tel_uri = 'tel:';
        if (preg_match('/(0[0-9]{1,2}-[0-9]{3,4}-[0-9]{4}|0[0-9]{8,9})/', $lead_name . ' ' . $customer_insight . ' ' . $deal_context, $matches)) {
            $phone = $matches[1];
            $clean_phone = preg_replace('/[^0-9]/', '', $phone);
            $tel_uri = 'tel:' . $clean_phone;
        }
        
        $sheet_id = $user['google_sheet_id'] ? htmlspecialchars($user['google_sheet_id']) : '';
        $sheet_uri = !empty($sheet_id) 
            ? "https://docs.google.com/spreadsheets/d/" . $sheet_id 
            : "https://docs.google.com";

        // กำหนดวันที่ติดตามครั้งถัดไป
        $follow_up_date = "ไม่มีกำหนดการ";
        if (!empty($row['next_follow_up'])) {
            $follow_up_date = date('d M Y H:i', strtotime($row['next_follow_up'])) . " น.";
        }
        
        // สร้าง bubble 1 ใบ
        $bubble = [
            "type" => "bubble",
            "size" => "mega",
            "header" => [
                "type" => "box",
                "layout" => "vertical",
                "contents" => [
                    [
                        "type" => "box",
                        "layout" => "horizontal",
                        "contents" => [
                            [
                                "type" => "text",
                                "text" => "📊 REPORT TODAY",
                                "weight" => "bold",
                                "color" => $status_glow_color,
                                "size" => "xs"
                            ],
                            [
                                "type" => "text",
                                "text" => $today_str,
                                "color" => $c['text_muted'],
                                "size" => "xs",
                                "align" => "end",
                                "weight" => "bold"
                            ]
                        ]
                    ],
                    [
                        "type" => "text",
                        "text" => "สรุปข้อมูลลีดและอัปเดตสถานะ",
                        "weight" => "bold",
                        "size" => "xl",
                        "color" => $c['text_on_green'],
                        "margin" => "sm"
                    ]
                ]
            ],
            "body" => [
                "type" => "box",
                "layout" => "vertical",
                "paddingAll" => "20px",
                "contents" => [
                    [
                        "type" => "text",
                        "text" => "ข้อมูลลูกค้าหลัก (Customer Details)",
                        "weight" => "bold",
                        "size" => "sm",
                        "color" => $c['text']
                    ],
                    [
                        "type" => "box",
                        "layout" => "vertical",
                        "margin" => "md",
                        "paddingAll" => "12px",
                        "backgroundColor" => $c['surface'],
                        "cornerRadius" => "8px",
                        "borderWidth" => "1px",
                        "borderColor" => $c['border'],
                        "contents" => [
                            [
                                "type" => "box",
                                "layout" => "horizontal",
                                "contents" => [
                                    [
                                        "type" => "text",
                                        "text" => "🔑 รหัสดีล",
                                        "size" => "sm",
                                        "color" => $c['text_muted'],
                                        "flex" => 2
                                    ],
                                    [
                                        "type" => "text",
                                        "text" => $lead_code,
                                        "size" => "sm",
                                        "color" => $c['text'],
                                        "weight" => "bold",
                                        "flex" => 4
                                    ]
                                ]
                            ],
                            [
                                "type" => "box",
                                "layout" => "horizontal",
                                "margin" => "sm",
                                "contents" => [
                                    [
                                        "type" => "text",
                                        "text" => "👤 ชื่อลูกค้า",
                                        "size" => "sm",
                                        "color" => $c['text_muted'],
                                        "flex" => 2
                                    ],
                                    [
                                        "type" => "text",
                                        "text" => $lead_name,
                                        "size" => "sm",
                                        "color" => $c['text'],
                                        "weight" => "bold",
                                        "flex" => 4,
                                        "wrap" => true
                                    ]
                                ]
                            ],
                            [
                                "type" => "box",
                                "layout" => "horizontal",
                                "margin" => "sm",
                                "contents" => [
                                    [
                                        "type" => "text",
                                        "text" => "📞 เบอร์ติดต่อ",
                                        "size" => "sm",
                                        "color" => $c['text_muted'],
                                        "flex" => 2
                                    ],
                                    [
                                        "type" => "text",
                                        "text" => $phone,
                                        "size" => "sm",
                                        "color" => ($phone !== 'ไม่ระบุ' ? $c['brown'] : $c['text']),
                                        "weight" => "bold",
                                        "flex" => 4
                                    ]
                                ]
                            ],
                            [
                                "type" => "box",
                                "layout" => "horizontal",
                                "margin" => "sm",
                                "contents" => [
                                    [
                                        "type" => "text",
                                        "text" => "⚡ สถานะดีล",
                                        "size" => "sm",
                                        "color" => $c['text_muted'],
                                        "flex" => 2
                                    ],
                                    [
                                        "type" => "box",
                                        "layout" => "vertical",
                                        "flex" => 4,
                                        "contents" => [
                                            [
                                                "type" => "box",
                                                "layout" => "vertical",
                                                "backgroundColor" => $badge_bg_color,
                                                "cornerRadius" => "20px",
                                                "paddingStart" => "8px",
                                                "paddingEnd" => "8px",
                                                "paddingTop" => "2px",
                                                "paddingBottom" => "2px",
                                                "width" => ($status === 'Pending_Cobroker' ? "110px" : ($status === 'Hold_Reject' ? "85px" : "65px")),
                                                "contents" => [
                                                    [
                                                        "type" => "text",
                                                        "text" => $badge_text,
                                                        "size" => "xxs",
                                                        "color" => $badge_text_color,
                                                        "weight" => "bold",
                                                        "align" => "center"
                                                    ]
                                                ]
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ],
                    [
                        "type" => "text",
                        "text" => "ระดับความน่าจะปิดดีลได้ (Grade / Priority)",
                        "weight" => "bold",
                        "size" => "sm",
                        "color" => $c['text'],
                        "margin" => "lg"
                    ],
                    [
                        "type" => "box",
                        "layout" => "vertical",
                        "margin" => "sm",
                        "contents" => [
                            [
                                "type" => "box",
                                "layout" => "horizontal",
                                "contents" => [
                                    [
                                        "type" => "text",
                                        "text" => "เกรดความเร่งด่วน / คะแนนลีด",
                                        "size" => "xs",
                                        "color" => $c['text_muted'],
                                        "flex" => 3
                                    ],
                                    [
                                        "type" => "text",
                                        "text" => $priority_score . " / 5 (เกรด " . $grade_text . ")",
                                        "size" => "xs",
                                        "color" => $grade_color,
                                        "weight" => "bold",
                                        "align" => "end",
                                        "flex" => 2
                                    ]
                                ]
                            ],
                            [
                                "type" => "box",
                                "layout" => "horizontal",
                                "margin" => "sm",
                                "backgroundColor" => $c['border'],
                                "height" => "12px",
                                "cornerRadius" => "6px",
                                "paddingAll" => "2px",
                                "contents" => [
                                    [
                                        "type" => "box",
                                        "layout" => "vertical",
                                        "backgroundColor" => $grade_color,
                                        "height" => "8px",
                                        "cornerRadius" => "4px",
                                        "width" => $width_str
                                    ]
                                ]
                            ]
                        ]
                    ],
                    [
                        "type" => "separator",
                        "margin" => "lg",
                        "color" => $c['border']
                    ],
                    [
                        "type" => "box",
                        "layout" => "vertical",
                        "margin" => "lg",
                        "spacing" => "xs",
                        "contents" => [
                            [
                                "type" => "text",
                                "text" => "🎯 ข้อมูลเชิงลึกลูกค้า (Insight)",
                                "weight" => "bold",
                                "size" => "xs",
                                "color" => $grade_color
                            ],
                            [
                                "type" => "text",
                                "text" => $customer_insight,
                                "size" => "sm",
                                "color" => $c['text'],
                                "wrap" => true
                            ]
                        ]
                    ],
                    [
                        "type" => "box",
                        "layout" => "vertical",
                        "margin" => "md",
                        "spacing" => "xs",
                        "contents" => [
                            [
                                "type" => "text",
                                "text" => "💬 บริบท/ความคืบหน้าล่าสุด (Deal Context)",
                                "weight" => "bold",
                                "size" => "xs",
                                "color" => $grade_color
                            ],
                            [
                                "type" => "text",
                                "text" => $deal_context,
                                "size" => "sm",
                                "color" => $c['text'],
                                "wrap" => true
                            ]
                        ]
                    ],
                    [
                        "type" => "box",
                        "layout" => "vertical",
                        "margin" => "md",
                        "spacing" => "xs",
                        "contents" => [
                            [
                                "type" => "text",
                                "text" => "📅 ติดตามงานครั้งถัดไป (Follow Up)",
                                "weight" => "bold",
                                "size" => "xs",
                                "color" => $grade_color
                            ],
                            [
                                "type" => "text",
                                "text" => $follow_up_date,
                                "size" => "sm",
                                "color" => $c['text'],
                                "weight" => "bold"
                            ]
                        ]
                    ]
                ]
            ],
            "styles" => [
                "header" => [
                    "backgroundColor" => $c['green']
                ],
                "body" => [
                    "backgroundColor" => $c['white']
                ],
                "footer" => [
                    "backgroundColor" => $c['white'],
                    "separator" => true,
                    "separatorColor" => $c['border']
                ]
            ]
        ];

        // เพิ่ม action โทรหาลูกค้าเฉพาะเมื่อมีเบอร์
        if ($phone !== 'ไม่ระบุ') {
            $bubble["body"]["contents"][1]["contents"][2]["contents"][1]["action"] = [
                "type" => "uri",
                "label" => "Call",
                "uri" => $tel_uri
            ];
        }

        // เพิ่ม footer พร้อมปุ่มการกระทำต่างๆ
        $footer_contents = [];
        if ($phone !== 'ไม่ระบุ') {
            $footer_contents[] = [
                "type" => "button",
                "style" => "secondary",
                "color" => $c['btn_secondary'],
                "action" => [
                    "type" => "uri",
                    "label" => "โทร",
                    "uri" => $tel_uri,
                ],
            ];
        }

        $footer_contents[] = [
            "type" => "button",
            "style" => "primary",
            "color" => $c['btn_primary'],
            "action" => [
                "type" => "uri",
                "label" => "เปิด Sheet",
                "uri" => $sheet_uri,
            ],
        ];
        
        $bubble["footer"] = [
            "type" => "box",
            "layout" => "horizontal",
            "spacing" => "md",
            "paddingAll" => "16px",
            "contents" => $footer_contents
        ];
        
        $bubbles[] = $bubble;
    }
    
    $stmt->close();
    
    if (empty($bubbles)) {
        return send_line_text_reply($replyToken, "ขณะนี้คุณยังไม่มีข้อมูลลีดลูกค้าในระบบสำหรับสร้างรายงานค่ะ");
    }
    
    // ประกอบเป็น payload ส่งกลับ
    $payload = [
        "replyToken" => $replyToken,
        "messages" => [
            [
                "type" => "flex",
                "altText" => "รายงานสรุปลีดประจำวัน (Lead Carousel Report)",
                "contents" => [
                    "type" => "carousel",
                    "contents" => $bubbles
                ]
            ]
        ]
    ];
    
    return send_line_reply($payload);
}
