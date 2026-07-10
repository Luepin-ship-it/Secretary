<?php
// openai_agent.php
// ฟังก์ชันติดต่อกับ OpenAI Chat Completion API (Project Antigravity)

/**
 * เรียกใช้งาน OpenAI API (Chat Completion) ด้วย cURL
 *
 * @param array $messages โครงสร้างข้อความแชท (System Prompt, User Prompt)
 * @param bool $json_mode กำหนดให้คืนค่าผลลัพธ์เป็นโครงสร้าง JSON หรือไม่
 * @param float $temperature ระดับความสร้างสรรค์ของการตอบ (0.0=แม่นยำ, 1.0=สร้างสรรค์)
 * @return array|bool ผลลัพธ์จากการตอบกลับของ OpenAI หรือ false หากเกิดข้อผิดพลาด
 */
function call_openai_chat($messages, $json_mode = false, $temperature = 0.2) {
    $url = 'https://api.openai.com/v1/chat/completions';

    $payload = [
        "model"       => "gpt-4o-mini",
        "messages"    => $messages,
        "temperature" => $temperature
    ];

    if ($json_mode) {
        $payload["response_format"] = ["type" => "json_object"];
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer " . OPENAI_API_KEY
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        error_log('OpenAI cURL Error: ' . curl_error($ch));
        curl_close($ch);
        return false;
    }

    curl_close($ch);

    $result = json_decode($response, true);
    if (isset($result['choices'][0]['message']['content'])) {
        return $result;
    }

    error_log('OpenAI API Error Response: ' . $response);
    return false;
}

/**
 * สร้าง System Prompt สำหรับบุคลิกเลขา AI ของผู้ใช้
 * - รองรับ 3 สไตล์บุคลิก
 * - ใช้บริบทงาน ทีม พื้นที่ดูแล และข้อมูลลีด/งานที่ค้างอยู่เป็นบริบทให้บอทรู้จักผู้ใช้ดี
 *
 * @param array $user   ข้อมูลผู้ใช้จากตาราง users
 * @param array $context  ข้อมูลบริบทเพิ่มเติม (ลีดที่กำลังคุยถึง, งานค้าง ฯลฯ)
 * @return string System Prompt สำหรับส่งให้ OpenAI
 */
function build_secretary_system_prompt($user, $context = []) {
    $bot_name    = $user['bot_name'] ?? 'เลขา AI';
    $user_name   = $user['user_name'] ?? 'ท่าน';
    $job_title   = $user['job_title'] ?? 'ผู้ใช้งาน';
    $work_ctx    = $user['work_context'] ?? '';
    $biz_type    = $user['business_type'] ?? 'Real Estate';
    $has_team    = (int)($user['has_teammates'] ?? 0);
    $team_roles  = $user['teammate_roles'] ?? '';
    $agent_areas = $user['agent_areas'] ?? '';
    $persona     = $user['persona_style'] ?? 'formal_polite';

    // ---- บุคลิกและสไตล์การพูด ----
    $persona_map = [
        'formal_polite' => [
            'style_desc' => 'สุภาพ เรียบร้อย ใช้คำพูดสุภาพ ลงท้ายด้วย "ค่ะ" หรือ "ครับ" เสมอ ไม่ใช้ภาษาปาก',
            'argue_style' => 'หากไม่เห็นด้วย จะยกเหตุผลอย่างสุภาพ เริ่มด้วย "ขออนุญาตแย้งนิดนึงนะคะ..." หรือ "มีข้อมูลที่อยากแชร์ให้พิจารณาค่ะ..."',
        ],
        'casual_friendly' => [
            'style_desc' => 'เป็นกันเอง พูดสั้นๆ ตรงประเด็น ใช้ "นะ" "เลย" "น้า" บ้าง เหมือนเพื่อนร่วมงานที่ไว้ใจได้',
            'argue_style' => 'หากไม่เห็นด้วย พูดตรงๆ เป็นกันเอง เช่น "เฮ้ รอก่อนนะ ฉันว่า..." หรือ "อย่าเพิ่งนะ มีอีกมุมที่น่าคิด..."',
        ],
        'assertive_professional' => [
            'style_desc' => 'มืออาชีพ ตรงไปตรงมา กระชับ มั่นใจ วิเคราะห์ข้อมูลก่อนพูดเสมอ ไม่อ้อมค้อม',
            'argue_style' => 'หากไม่เห็นด้วย จะแย้งด้วยข้อมูลทันที เช่น "ไม่เห็นด้วยครับ เหตุผลคือ..." หรือ "ข้อมูลบอกว่า... ลองทบทวนอีกครั้งไหมครับ"',
        ],
    ];

    $pdata = $persona_map[$persona] ?? $persona_map['formal_polite'];

    // ---- บริบททีมงาน ----
    $team_block = '';
    if ($has_team) {
        $team_block = "
## ทีมงานร่วม
- บทบาทของเพื่อนร่วมทีม: {$team_roles}
- พื้นที่ดูแลของทีม: {$agent_areas}
เมื่อผู้ใช้ต้องการความช่วยเหลือที่เกินขอบเขต ให้แนะนำให้ประสานกับทีมงานข้างต้น";
    }

    // ---- ข้อมูลบริบทเพิ่มเติม (ลีด, งาน ฯลฯ) ----
    $ctx_block = '';
    if (!empty($context['current_lead'])) {
        $l = $context['current_lead'];
        $ctx_block .= "\n## ข้อมูลลีดที่กำลังพูดถึง\n";
        $ctx_block .= "- รหัส: {$l['lead_code']} | ชื่อ: {$l['lead_name']} | สถานะ: {$l['status']}\n";
        $ctx_block .= "- งบประมาณ: {$l['budget']} | ความรีบ: เกรด {$l['potential']}\n";
        if (!empty($l['current_update'])) {
            $ctx_block .= "- อัปเดตล่าสุด: {$l['current_update']}\n";
        }
        if (!empty($l['next_plan_action'])) {
            $ctx_block .= "- แผนงานถัดไป: {$l['next_plan_action']} (วันที่: {$l['next_plan_date']})\n";
        }
    }

    if (!empty($context['pending_tasks'])) {
        $ctx_block .= "\n## งานที่ยังค้างอยู่ (Pending Tasks)\n";
        foreach ($context['pending_tasks'] as $t) {
            $ctx_block .= "- [{$t['due_date']}] {$t['title']}\n";
        }
    }

    if (!empty($context['capacity'])) {
        $cap = $context['capacity'];
        $ctx_block .= "\n## ความจุงานของผู้ใช้ (Capacity)\n";
        $ctx_block .= "- Lead ที่กำลังดูแล: {$cap['active_leads']} / {$cap['max_active_leads']} (โหลด {$cap['load_pct']}%)\n";
        $ctx_block .= "- งานค้างวันนี้: {$cap['tasks_due_today']} / ความจุแนะนำ {$cap['daily_task_capacity']} งาน/วัน\n";
        $ctx_block .= "- เคสจองรอโอน: {$cap['reserve_cases']} เคส\n";
        if ($cap['load_pct'] >= 85) {
            $ctx_block .= "- ⚠️ โหลดงานสูง — แนะนำจัดลำดับก่อนสร้าง task ใหม่\n";
        }
    }

    $today = date('l, d F Y', time()); // วันที่วันนี้

    $system = <<<PROMPT
# บทบาทของคุณ
คุณคือ **{$bot_name}** เลขา AI ส่วนตัวของ **{$user_name}** ({$job_title}) ในธุรกิจ **{$biz_type}**
วันที่วันนี้: {$today}

## บุคลิกและสไตล์การพูด
{$pdata['style_desc']}

## ความสามารถพิเศษ: เถียงได้และวิจารณ์ได้
{$pdata['argue_style']}
คุณ**ไม่ใช่บอทที่ตอบรับทุกอย่าง** — หากผู้ใช้จะตัดสินใจผิดพลาดหรือจะปล่อยดีลดีๆ ทิ้ง ให้แย้งด้วยเหตุผลและข้อมูลจากบริบทที่มี

## บริบทการทำงาน
{$work_ctx}
{$team_block}
{$ctx_block}

## ข้อจำกัดสำคัญ
- ตอบเป็นภาษาไทยเสมอ เว้นแต่ผู้ใช้พูดภาษาอื่น
- ห้ามเปิดเผยข้อมูลของผู้ใช้รายอื่น
- ห้ามแกล้งทำเป็นว่ารู้ข้อมูลที่ไม่มีในบริบท ให้บอกว่า "ไม่มีข้อมูลนี้ในระบบ"
- ตอบกระชับ ตรงประเด็น ไม่เยิ่นเย้อ (ไม่เกิน 3-4 ย่อหน้า)
- หากผู้ใช้ถามงาน/ลีด/รายงาน ให้ดึงข้อมูลจากบริบทที่ได้รับมาก่อน ไม่ต้องถามซ้ำ
PROMPT;

    return $system;
}

/**
 * เรียกให้เลขา AI ตอบแบบสนทนาธรรมชาติ (Free-chat Secretary Mode)
 * ใช้บุคลิกและบริบทของผู้ใช้เต็มๆ
 *
 * @param array  $user          ข้อมูลผู้ใช้จาก DB
 * @param string $user_message  ข้อความที่ผู้ใช้ส่งมา
 * @param array  $context       บริบทเพิ่มเติม (ลีดปัจจุบัน, งานค้าง ฯลฯ)
 * @param array  $history       ประวัติการสนทนา [['role'=>'user','content'=>'...'], ...]
 * @return string ข้อความตอบกลับของเลขา AI
 */
function call_openai_secretary($user, $user_message, $context = [], $history = []) {
    $system_prompt = build_secretary_system_prompt($user, $context);

    $messages = [
        ["role" => "system", "content" => $system_prompt]
    ];

    // ใส่ประวัติการสนทนา (ถ้ามี) สูงสุด 6 รอบล่าสุด
    $recent_history = array_slice($history, -12);
    foreach ($recent_history as $h) {
        $messages[] = $h;
    }

    $messages[] = ["role" => "user", "content" => $user_message];

    // temperature สูงขึ้นเล็กน้อย (0.7) เพื่อให้การตอบดูเป็นธรรมชาติมากขึ้น
    $result = call_openai_chat($messages, false, 0.7);

    if (!$result) {
        return "ขออภัยค่ะ ระบบเลขา AI มีปัญหาชั่วคราว กรุณาลองใหม่อีกสักครู่";
    }

    return trim($result['choices'][0]['message']['content'] ?? 'ขออภัย ไม่สามารถประมวลผลได้ในขณะนี้');
}

/**
 * วิเคราะห์ข้อความดิบจากผู้ใช้ว่าเป็นการอัปเดตลีด/งาน หรือแค่คุยทั่วไป
 * คืนค่าเป็น JSON เสมอ
 *
 * @param string $text ข้อความที่ผู้ใช้ส่งมา
 * @param array  $user_reject_cases รายการเคสการ Reject ที่ผู้ใช้กำหนดเอง
 * @return array ผลการวิเคราะห์
 */
function analyze_user_message($text, $user_reject_cases = []) {
    $reject_cases_list = implode('", "', array_map('addslashes', $user_reject_cases));

    $system = <<<PROMPT
คุณคือ AI วิเคราะห์ข้อความแชทภาษาไทย หน้าที่คือสกัดข้อมูลและจัดหมวดหมู่ข้อความ ส่งกลับ **เฉพาะ JSON** เท่านั้น ห้ามมีคำอธิบายอื่นใดนอกจาก JSON

รูปแบบ JSON ที่ต้องส่งกลับ:
{
  "intent": "UPDATE_LEAD | UPDATE_OWNER | QUERY_REPORT | REJECT_ATTEMPT | FREE_CHAT",
  "target_code": "รหัสลีด (เช่น L042) หรือรหัส Owner (เช่น O055) ที่พบในข้อความ หรือ null",
  "target_type": "lead | owner | null",
  "reject_case": "ชื่อเคสการ Reject ที่ตรงกับที่ผู้ใช้กำหนดไว้ (จากรายการ: ["{$reject_cases_list}"]) หรือ null",
  "reject_reason_raw": "สาเหตุการ Reject ที่สกัดได้จากข้อความ หรือ null",
  "update_summary": "สรุปสั้นๆ ว่าผู้ใช้อัปเดตอะไร (ภาษาไทย) หรือ null",
  "extracted_fields": {
    "status": "สถานะดีลใหม่ถ้าพบ: Call|Follow|Appointment|Show|Nego|Close|Bank|Win หรือ null",
    "next_plan_action": "แผนงานถัดไปถ้าพบ หรือ null",
    "next_plan_date": "วันที่แผนถัดไป (YYYY-MM-DD) ถ้าพบ หรือ null",
    "current_update": "ข้อความอัปเดตสถานะดีล หรือ null"
  }
}

คำนิยาม intent:
- UPDATE_LEAD: ข้อความเกี่ยวกับการอัปเดต/บันทึกข้อมูลลีดลูกค้า มีรหัสลีด (เช่น L042) หรือชื่อลีด
- UPDATE_OWNER: ข้อความเกี่ยวกับการอัปเดตข้อมูลทรัพย์สิน/เจ้าของ มีรหัส Owner (เช่น O055)
- QUERY_REPORT: ถามดูรายงาน, ยอดขาย, สรุปงาน, Dashboard
- REJECT_ATTEMPT: ผู้ใช้แสดงเจตนาจะปล่อยดีล/ไม่สนลีด/เทงาน/ปฏิเสธลูกค้า
- FREE_CHAT: คุยทั่วไป ถามคำถาม ต้องการความเห็น ไม่ได้อัปเดตข้อมูลใดๆ
PROMPT;

    $result = call_openai_chat([
        ["role" => "system", "content" => $system],
        ["role" => "user", "content" => $text]
    ], true, 0.1);

    if (!$result) {
        return ['intent' => 'FREE_CHAT', 'target_code' => null, 'target_type' => null];
    }

    $parsed = json_decode($result['choices'][0]['message']['content'] ?? '{}', true);
    return $parsed ?: ['intent' => 'FREE_CHAT', 'target_code' => null, 'target_type' => null];
}

/**
 * สร้างข้อความตอบแบบ Defensive เมื่อ User จะ Reject ดีล
 * บอทจะเถียงและเสนอทางออกก่อน พร้อม tip จาก reject_reasons ที่ผู้ใช้กำหนดเอง
 *
 * @param array  $user          ข้อมูลผู้ใช้
 * @param string $reject_case   ชื่อเคสที่ตรงกัน
 * @param string $reject_reason สาเหตุดิบ
 * @param string $lead_code     รหัสลีด
 * @param string $hint_question คำถามแนะนำที่ผู้ใช้กำหนดเองสำหรับเคสนี้
 * @return string ข้อความตอบกลับ
 */
function generate_defensive_response($user, $reject_case, $reject_reason, $lead_code, $hint_question = '') {
    $bot_name   = $user['bot_name'] ?? 'เลขา AI';
    $biz_type   = $user['business_type'] ?? 'Real Estate';
    $persona    = $user['persona_style'] ?? 'formal_polite';

    $persona_map = [
        'formal_polite'          => 'สุภาพ อ่อนน้อม ใช้ "ค่ะ" ลงท้ายเสมอ',
        'casual_friendly'        => 'เป็นกันเอง สบายๆ ใช้ภาษาปากน้อยๆ',
        'assertive_professional' => 'มั่นใจ ตรงประเด็น ยกข้อมูลสนับสนุน',
    ];
    $persona_desc = $persona_map[$persona] ?? $persona_map['formal_polite'];

    $hint_block = $hint_question
        ? "\nคำถามแนะนำที่ควรถามผู้ใช้ก่อน: \"{$hint_question}\""
        : '';

    $system = <<<PROMPT
คุณคือ {$bot_name} เลขา AI ในธุรกิจ {$biz_type} บุคลิก: {$persona_desc}

ผู้ใช้กำลังจะ**ปล่อยดีล/Reject** ลีด {$lead_code} เพราะเหตุผล: "{$reject_reason}" (เคส: {$reject_case})
{$hint_block}

งานของคุณ: **เถียงกลับ** อย่างชาญฉลาดก่อนที่จะยอมให้ Reject จริง โดย:
1. ยอมรับปัญหา แต่ตั้งคำถามว่า "ลองแนวทาง X แล้วหรือยัง?" (ใช้ hint ที่ให้มาถ้ามี)
2. เสนอทางออกที่เป็นรูปธรรม 1-2 ข้อ เหมาะกับธุรกิจ {$biz_type}
3. บอกว่าถ้ายังจะ Reject จริงๆ ก็กดปุ่มด้านล่างได้

เขียนเฉพาะข้อความที่จะส่งในแชท LINE เท่านั้น ไม่ต้องมีคำนำหรือหัวข้อ ตอบให้สั้นกระชับ ไม่เกิน 5-6 บรรทัด
PROMPT;

    $result = call_openai_chat([
        ["role" => "system", "content" => $system],
        ["role" => "user", "content" => "สร้างข้อความ Defensive Response ที่ดีที่สุด"]
    ], false, 0.75);

    if (!$result) {
        return "ก่อนจะตัดสินใจ ลองดูทางออกอื่นก่อนนะคะ ดีลนี้อาจยังมีโอกาสอยู่";
    }

    return trim($result['choices'][0]['message']['content'] ?? '');
}
