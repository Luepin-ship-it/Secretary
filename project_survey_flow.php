<?php
// project_survey_flow.php — แชท LINE เพิ่มโครงการ Survey ทีละข้อความ/รูป/พิกัด

require_once __DIR__ . '/config.php';

/** สร้างตาราง draft ถ้ายังไม่มี */
function project_flow_ensure_schema($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS project_survey_drafts (
        user_id INT NOT NULL PRIMARY KEY,
        step VARCHAR(40) NOT NULL DEFAULT 'name_th',
        data_json TEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function project_flow_steps() {
    return [
        'name_th'       => ['prompt' => "เริ่มเพิ่มโครงการใหม่\n\nขั้นที่ 1/12 — ชื่อโครงการ (ภาษาไทย)\nตัวอย่าง: เดอะ ไลน์ 101", 'type' => 'text'],
        'name_en'       => ['prompt' => "ขั้นที่ 2/12 — ชื่อภาษาอังกฤษ\nพิมพ์ชื่อ หรือพิมพ์ *ข้าม* ได้", 'type' => 'text', 'skippable' => true],
        'developer'     => ['prompt' => "ขั้นที่ 3/12 — ผู้พัฒนา (Developer)\nตัวอย่าง: Sansiri", 'type' => 'text'],
        'segment'       => ['prompt' => "ขั้นที่ 4/12 — Segment\nกดปุ่มด้านล่างเลือก class", 'type' => 'choice', 'choices' => [
            'Economy class', 'Main class', 'Upper class', 'High class', 'Luxury class', 'Super Luxury class',
        ]],
        'property_type' => ['prompt' => "ขั้นที่ 5/12 — ประเภททรัพย์\nกดปุ่ม Condo หรือ House", 'type' => 'choice', 'choices' => ['Condo', 'House']],
        'location'      => ['prompt' => "ขั้นที่ 6/12 — ตำแหน่งโครงการ\nกดปุ่ม 📍 ส่งตำแหน่ง (Location) ในแชท LINE", 'type' => 'location'],
        'cover'         => ['prompt' => "ขั้นที่ 7/12 — รูปปกโครงการ\nส่งรูปภาพ หรือพิมพ์ *ข้าม*", 'type' => 'image', 'skippable' => true],
        'total_units'   => ['prompt' => "ขั้นที่ 8/12 — จำนวนยูนิตทั้งหมด\nตัวอย่าง: 1800", 'type' => 'number'],
        'phases'        => ['prompt' => "ขั้นที่ 9/12 — จำนวนเฟส\nพิมพ์ตัวเลข หรือ *ข้าม*", 'type' => 'number', 'skippable' => true],
        'launch_year'   => ['prompt' => "ขั้นที่ 10/12 — ปีเปิดตัว\nตัวอย่าง: 2024 หรือ *ข้าม*", 'type' => 'number', 'skippable' => true],
        'common_fee'    => ['prompt' => "ขั้นที่ 11/12 — ค่าส่วนกลาง (บาท/ปี)\nตัวอย่าง: 38 หรือ *ข้าม*", 'type' => 'number', 'skippable' => true],
        'amenities'     => ['prompt' => "ขั้นที่ 12/12 — สิ่งอำนวยความสะดวก\nพิมพ์คั่นด้วยจุลภาค เช่น สระว่ายน้ำ, ฟิตเนส\nเมื่อครบแล้วพิมพ์ *พอแล้ว*", 'type' => 'amenities'],
        'nearby'        => ['prompt' => "เพิ่มเติม — สถานที่ใกล้เคียง (ไม่บังคับ)\nรูปแบบ: ชื่อ|ระยะทาง คั่นด้วยจุลภาค\nตัวอย่าง: BTS พร้อมพงษ์|350 ม., เซ็นทรัล|1.2 กม.\nหรือพิมพ์ *ข้าม*", 'type' => 'nearby', 'skippable' => true],
        'unit_hint'     => ['prompt' => "เพิ่มเติม — แบบบ้าน/ยูนิตหลัก (ไม่บังคับ)\nตัวอย่าง: 1 นอน 28 ตร.ม. ราคาเปิด 3.9 ล้าน\nหรือพิมพ์ *ข้าม*", 'type' => 'text', 'skippable' => true],
        'confirm'       => ['prompt' => '', 'type' => 'confirm'],
    ];
}

function project_flow_step_order() {
    return array_keys(project_flow_steps());
}

function project_flow_is_trigger($text) {
    $t = mb_strtolower(trim((string)$text), 'UTF-8');
    $triggers = ['เพิ่มโครงการ', 'โครงการใหม่', 'เพิ่ม survey', 'add project', 'เพิ่มโครงการใหม่'];
    foreach ($triggers as $kw) {
        if ($t === $kw || mb_strpos($t, $kw) === 0) return true;
    }
    return false;
}

function project_flow_is_cancel($text) {
    $t = mb_strtolower(trim((string)$text), 'UTF-8');
    return in_array($t, ['ยกเลิก', 'cancel', 'ยกเลิกโครงการ'], true);
}

function project_flow_is_skip($text) {
    $t = mb_strtolower(trim((string)$text), 'UTF-8');
    return in_array($t, ['ข้าม', 'skip', '-'], true);
}

function project_flow_get_draft($conn, $user_id) {
    project_flow_ensure_schema($conn);
    $stmt = $conn->prepare("SELECT step, data_json FROM project_survey_drafts WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;
    $data = json_decode($row['data_json'] ?? '{}', true);
    if (!is_array($data)) $data = [];
    return ['step' => $row['step'], 'data' => $data];
}

function project_flow_save_draft($conn, $user_id, $step, $data) {
    project_flow_ensure_schema($conn);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    $stmt = $conn->prepare("INSERT INTO project_survey_drafts (user_id, step, data_json) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE step = VALUES(step), data_json = VALUES(data_json)");
    $stmt->bind_param("iss", $user_id, $step, $json);
    $stmt->execute();
    $stmt->close();
}

function project_flow_clear_draft($conn, $user_id) {
    project_flow_ensure_schema($conn);
    $stmt = $conn->prepare("DELETE FROM project_survey_drafts WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->close();
}

function project_flow_has_draft($conn, $user_id) {
    return project_flow_get_draft($conn, $user_id) !== null;
}

function project_flow_next_step($current) {
    $order = project_flow_step_order();
    $idx = array_search($current, $order, true);
    if ($idx === false) return 'name_th';
    return $order[$idx + 1] ?? 'confirm';
}

function project_flow_choice_buttons($field, $choices) {
    $items = [];
    foreach ($choices as $c) {
        $items[] = [
            'type' => 'action',
            'action' => [
                'type' => 'postback',
                'label' => $c,
                'data' => 'action=project_set&field=' . urlencode($field) . '&value=' . urlencode($c),
                'displayText' => $c,
            ],
        ];
    }
    $items[] = [
        'type' => 'action',
        'action' => [
            'type' => 'postback',
            'label' => 'ยกเลิก',
            'data' => 'action=project_cancel',
            'displayText' => 'ยกเลิก',
        ],
    ];
    return ['items' => $items];
}

function project_flow_send_prompt($replyToken, $step) {
    $steps = project_flow_steps();
    $def = $steps[$step] ?? null;
    if (!$def || $step === 'confirm') return;

    $msg = ['type' => 'text', 'text' => $def['prompt']];
    if (($def['type'] ?? '') === 'choice' && !empty($def['choices'])) {
        $msg['quickReply'] = project_flow_choice_buttons($step, $def['choices']);
    }
    project_flow_reply($replyToken, [$msg]);
}

function project_flow_reply($replyToken, $messages) {
    if (function_exists('quick_reply_attach')) {
        $last = count($messages) - 1;
        if ($last >= 0 && empty($messages[$last]['quickReply'])) {
            $messages = quick_reply_attach($messages, 'main');
        }
    }
    $payload = ['replyToken' => $replyToken, 'messages' => $messages];
    $ch = curl_init('https://api.line.me/v2/bot/message/reply');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . LINE_ACCESS_TOKEN,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function project_flow_send_text($replyToken, $text) {
    project_flow_reply($replyToken, [['type' => 'text', 'text' => $text]]);
}

function project_flow_download_line_image($message_id) {
    $dir = __DIR__ . '/uploads/projects';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $ch = curl_init('https://api-data.line.me/v2/bot/message/' . rawurlencode($message_id) . '/content');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . LINE_ACCESS_TOKEN]);
    $bin = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$bin) return '';

    $fname = 'line_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $message_id) . '.jpg';
    $path = $dir . '/' . $fname;
    file_put_contents($path, $bin);

    $base = project_flow_public_base();
    return $base . '/uploads/projects/' . rawurlencode($fname);
}

function project_flow_public_base() {
    if (function_exists('build_public_base_url')) {
        return build_public_base_url();
    }
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = trim($dir, '/');
    $enc = $dir !== '' ? '/' . implode('/', array_map('rawurlencode', explode('/', $dir))) : '';
    return rtrim($proto . '://' . $host . $enc, '/');
}

function project_flow_slug($name_th, $user_id) {
    $base = preg_replace('/\s+/u', '-', trim((string)$name_th));
    $base = preg_replace('/[^\p{L}\p{N}\-]+/u', '', $base);
    if ($base === '') $base = 'project';
    return mb_strtolower(mb_substr($base, 0, 60), 'UTF-8') . '-' . $user_id . '-' . substr(md5(uniqid('', true)), 0, 6);
}

function project_flow_parse_nearby($text) {
    $out = [];
    foreach (preg_split('/[,，]/u', $text) as $part) {
        $part = trim($part);
        if ($part === '') continue;
        if (strpos($part, '|') !== false) {
            [$name, $dist] = array_map('trim', explode('|', $part, 2));
        } elseif (preg_match('/^(.+?)\s+([\d.]+\s*(?:ม\.|กม\.|km|m).*)$/u', $part, $m)) {
            $name = trim($m[1]);
            $dist = trim($m[2]);
        } else {
            $name = $part;
            $dist = '';
        }
        if ($name !== '') $out[] = ['name' => $name, 'distance' => $dist];
    }
    return $out;
}

function project_flow_parse_unit_hint($text) {
    $name = trim($text);
    $price = null;
    if (preg_match('/([\d,.]+)\s*(ล้าน|ลบ\.?|million)/iu', $text, $m)) {
        $num = (float)str_replace(',', '', $m[1]);
        $price = (int)round($num * 1000000);
    } elseif (preg_match('/([\d,]+)\s*บาท/u', $text, $m)) {
        $price = (int)str_replace(',', '', $m[1]);
    }
    $unit = ['name' => $name ?: 'ยูนิตหลัก'];
    if ($price) $unit['price_open'] = $price;
    if (preg_match('/([\d.]+)\s*ตร\.?ม/u', $text, $m)) $unit['living'] = $m[1] . ' ตร.ม.';
    if (preg_match('/(\d+)\s*นอน/u', $text, $m)) $unit['bed'] = (int)$m[1];
    return $unit;
}

function project_flow_build_summary($data) {
    $lines = ["สรุปโครงการก่อนบันทึก:"];
    $lines[] = '• ชื่อไทย: ' . ($data['name_th'] ?? '-');
    if (!empty($data['name_en'])) $lines[] = '• ชื่อ EN: ' . $data['name_en'];
    $lines[] = '• Developer: ' . ($data['developer'] ?? '-');
    $lines[] = '• Segment: ' . ($data['segment'] ?? '-');
    $lines[] = '• ประเภท: ' . ($data['property_type'] ?? '-');
    if (!empty($data['lat'])) $lines[] = '• พิกัด: ' . $data['lat'] . ', ' . $data['lng'];
    $lines[] = '• ยูนิต: ' . ($data['total_units'] ?? 0);
    if (!empty($data['phases'])) $lines[] = '• เฟส: ' . $data['phases'];
    if (!empty($data['launch_year'])) $lines[] = '• ปีเปิด: ' . $data['launch_year'];
    if (!empty($data['common_fee'])) $lines[] = '• ค่าส่วนกลาง: ' . $data['common_fee'] . ' บ./ปี';
    if (!empty($data['amenities'])) $lines[] = '• สิ่งอำนวยความสะดวก: ' . implode(', ', $data['amenities']);
    $lines[] = "\nกด บันทึก หรือ ยกเลิก";
    return implode("\n", $lines);
}

function project_flow_send_confirm($replyToken, $data) {
    project_flow_reply($replyToken, [[
        'type' => 'text',
        'text' => project_flow_build_summary($data),
        'quickReply' => [
            'items' => [
                ['type' => 'action', 'action' => ['type' => 'postback', 'label' => 'บันทึก', 'data' => 'action=project_confirm', 'displayText' => 'บันทึกโครงการ']],
                ['type' => 'action', 'action' => ['type' => 'postback', 'label' => 'ยกเลิก', 'data' => 'action=project_cancel', 'displayText' => 'ยกเลิก']],
            ],
        ],
    ]]);
}

function project_flow_advance($conn, $user_id, $draft, $replyToken) {
    $next = project_flow_next_step($draft['step']);
    project_flow_save_draft($conn, $user_id, $next, $draft['data']);
    if ($next === 'confirm') {
        project_flow_send_confirm($replyToken, $draft['data']);
    } else {
        project_flow_send_prompt($replyToken, $next);
    }
    return true;
}

function project_flow_start($conn, $user_id, $replyToken) {
    project_flow_save_draft($conn, $user_id, 'name_th', []);
    project_flow_send_prompt($replyToken, 'name_th');
    return true;
}

/** @return bool handled */
function project_flow_handle_text($conn, $user, $text, $replyToken) {
    $user_id = (int)$user['id'];
    $draft = project_flow_get_draft($conn, $user_id);

    if (project_flow_is_cancel($text)) {
        if ($draft) project_flow_clear_draft($conn, $user_id);
        project_flow_send_text($replyToken, 'ยกเลิกการเพิ่มโครงการแล้ว');
        return (bool)$draft || project_flow_is_trigger($text);
    }

    if (!$draft && project_flow_is_trigger($text)) {
        return project_flow_start($conn, $user_id, $replyToken);
    }

    if ($draft && project_flow_is_trigger($text)) {
        project_flow_clear_draft($conn, $user_id);
        return project_flow_start($conn, $user_id, $replyToken);
    }

    if (!$draft) return false;

    $step = $draft['step'];
    $data = $draft['data'];
    $def = project_flow_steps()[$step] ?? null;

    if ($step === 'amenities') {
        if (project_flow_is_skip($text) || mb_strtolower($text, 'UTF-8') === 'พอแล้ว') {
            if (empty($data['amenities'])) $data['amenities'] = [];
            $draft['data'] = $data;
            return project_flow_advance($conn, $user_id, $draft, $replyToken);
        }
        $parts = preg_split('/[,，]/u', $text);
        $list = $data['amenities'] ?? [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '' && mb_strtolower($p, 'UTF-8') !== 'พอแล้ว') $list[] = $p;
        }
        $data['amenities'] = array_values(array_unique($list));
        $draft['data'] = $data;
        project_flow_save_draft($conn, $user_id, $step, $data);
        project_flow_send_text($replyToken, "บันทึกแล้ว " . count($data['amenities']) . " รายการ\nเพิ่มต่อได้ หรือพิมพ์ *พอแล้ว* เพื่อไปขั้นถัดไป");
        return true;
    }

    if (!empty($def['skippable']) && project_flow_is_skip($text)) {
        return project_flow_advance($conn, $user_id, $draft, $replyToken);
    }

    switch ($step) {
        case 'name_th':
            if (mb_strlen(trim($text)) < 2) {
                project_flow_send_text($replyToken, 'กรุณาพิมพ์ชื่อโครงการภาษาไทยอย่างน้อย 2 ตัวอักษร');
                return true;
            }
            $data['name_th'] = trim($text);
            break;
        case 'name_en':
            if (!project_flow_is_skip($text)) $data['name_en'] = trim($text);
            break;
        case 'developer':
            $data['developer'] = trim($text);
            break;
        case 'segment':
        case 'property_type':
        case 'location':
        case 'cover':
            project_flow_send_text($replyToken, 'ขั้นนี้ใช้ปุ่มเลือก / ส่งตำแหน่ง / ส่งรูป ตามคำแนะนำด้านบน');
            return true;
        case 'total_units':
        case 'phases':
        case 'launch_year':
        case 'common_fee':
            if (!preg_match('/^\d+(\.\d+)?$/', str_replace(',', '', trim($text)))) {
                project_flow_send_text($replyToken, 'กรุณาพิมพ์ตัวเลข หรือพิมพ์ *ข้าม* (ถ้าขั้นนี้ข้ามได้)');
                return true;
            }
            $val = str_replace(',', '', trim($text));
            if ($step === 'common_fee') $data[$step] = (float)$val;
            elseif ($step === 'total_units' || $step === 'phases') $data[$step] = (int)$val;
            else $data[$step] = (int)$val;
            break;
        case 'nearby':
            $data['nearby'] = project_flow_parse_nearby($text);
            break;
        case 'unit_hint':
            if (!project_flow_is_skip($text)) $data['units'] = [project_flow_parse_unit_hint($text)];
            break;
        default:
            return false;
    }

    $draft['data'] = $data;
    return project_flow_advance($conn, $user_id, $draft, $replyToken);
}

/** @return bool handled */
function project_flow_handle_image($conn, $user, $message, $replyToken) {
    $draft = project_flow_get_draft($conn, (int)$user['id']);
    if (!$draft || $draft['step'] !== 'cover') return false;

    $mid = $message['id'] ?? '';
    if ($mid === '') {
        project_flow_send_text($replyToken, 'รับรูปไม่สำเร็จ ลองส่งใหม่อีกครั้ง');
        return true;
    }

    $url = project_flow_download_line_image($mid);
    if ($url === '') {
        project_flow_send_text($replyToken, 'ดาวน์โหลดรูปจาก LINE ไม่สำเร็จ — ลองใหม่หรือพิมพ์ *ข้าม*');
        return true;
    }

    $draft['data']['cover_image_url'] = $url;
    return project_flow_advance($conn, (int)$user['id'], $draft, $replyToken);
}

/** @return bool handled */
function project_flow_handle_location($conn, $user, $message, $replyToken) {
    $draft = project_flow_get_draft($conn, (int)$user['id']);
    if (!$draft || $draft['step'] !== 'location') return false;

    $lat = $message['latitude'] ?? null;
    $lng = $message['longitude'] ?? null;
    if ($lat === null || $lng === null) {
        project_flow_send_text($replyToken, 'ไม่พบพิกัด — กดปุ่มส่งตำแหน่ง (Location) ในแชท');
        return true;
    }

    $draft['data']['lat'] = (float)$lat;
    $draft['data']['lng'] = (float)$lng;
    if (!empty($message['address'])) $draft['data']['address'] = $message['address'];
    return project_flow_advance($conn, (int)$user['id'], $draft, $replyToken);
}

/** @return bool handled */
function project_flow_handle_postback($conn, $user, $params, $replyToken) {
    $action = $params['action'] ?? '';
    $user_id = (int)$user['id'];

    if ($action === 'project_cancel') {
        project_flow_clear_draft($conn, $user_id);
        project_flow_send_text($replyToken, 'ยกเลิกการเพิ่มโครงการแล้ว');
        return true;
    }

    if ($action === 'project_set') {
        $draft = project_flow_get_draft($conn, $user_id);
        if (!$draft) {
            project_flow_send_text($replyToken, 'ไม่พบร่างโครงการ — พิมพ์ *เพิ่มโครงการ* เพื่อเริ่มใหม่');
            return true;
        }
        $field = $params['field'] ?? '';
        $value = $params['value'] ?? '';
        if ($draft['step'] !== $field) {
            project_flow_send_text($replyToken, 'ขั้นตอนไม่ตรงกัน — พิมพ์ *เพิ่มโครงการ* เพื่อเริ่มใหม่');
            return true;
        }
        $draft['data'][$field] = $value;
        return project_flow_advance($conn, $user_id, $draft, $replyToken);
    }

    if ($action === 'project_confirm') {
        $draft = project_flow_get_draft($conn, $user_id);
        if (!$draft || empty($draft['data']['name_th'])) {
            project_flow_send_text($replyToken, 'ไม่พบข้อมูลครบ — พิมพ์ *เพิ่มโครงการ* เพื่อเริ่มใหม่');
            return true;
        }
        $d = $draft['data'];
        if (empty($d['lat']) || empty($d['lng'])) {
            project_flow_send_text($replyToken, 'ยังไม่มีพิกัด — เริ่มใหม่และส่ง Location ในขั้นตอนที่ 6');
            return true;
        }

        $slug = project_flow_slug($d['name_th'], $user_id);
        $amen_j = json_encode($d['amenities'] ?? [], JSON_UNESCAPED_UNICODE);
        $near_j = json_encode($d['nearby'] ?? [], JSON_UNESCAPED_UNICODE);
        $unit_j = json_encode($d['units'] ?? [], JSON_UNESCAPED_UNICODE);

        $stmt = $conn->prepare("INSERT INTO project_surveys
            (user_id, project_slug, name_en, name_th, developer, segment, total_units, phases, launch_year, built_year,
             common_fee, fee_period, property_type, amenities_json, cover_image_url, lat, lng, nearby_json, units_json)
            VALUES (?,?,?,?,?,?,?,?,?,?,?, 'yearly', ?, ?, ?, ?, ?, ?, ?)");
        $name_en = $d['name_en'] ?? '';
        $developer = $d['developer'] ?? '';
        $segment = $d['segment'] ?? '';
        $total_units = (int)($d['total_units'] ?? 0);
        $phases = (int)($d['phases'] ?? 0);
        $launch_year = !empty($d['launch_year']) ? (int)$d['launch_year'] : null;
        $built_year = null;
        $common_fee = isset($d['common_fee']) ? (float)$d['common_fee'] : null;
        $property_type = $d['property_type'] ?? 'House';
        $cover = $d['cover_image_url'] ?? '';
        $lat = (float)$d['lat'];
        $lng = (float)$d['lng'];
        $name_th = $d['name_th'];

        $stmt->bind_param(
            'isssssiiiidsssddss',
            $user_id, $slug, $name_en, $name_th, $developer, $segment,
            $total_units, $phases, $launch_year, $built_year, $common_fee,
            $property_type, $amen_j, $cover, $lat, $lng, $near_j, $unit_j
        );
        $ok = $stmt->execute();
        $stmt->close();

        project_flow_clear_draft($conn, $user_id);

        if (!$ok) {
            project_flow_send_text($replyToken, 'บันทึกไม่สำเร็จ — ลองใหม่อีกครั้ง');
            return true;
        }

        $base = project_flow_public_base();
        project_flow_send_text($replyToken,
            "บันทึกโครงการ \"{$name_th}\" เรียบร้อย\n"
            . "• พิกัด: {$lat}, {$lng}\n"
            . "• ดูบนแผนที่: {$base}/dashboard.php#map\n\n"
            . "เปิดแท็บ โครงการ บนแผนที่เพื่อดูจุดใหม่"
        );
        return true;
    }

    return false;
}
