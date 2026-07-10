<?php
/**
 * Lead identity ตามเบอร์โทร — customer_group_id + auto-link + แจ้ง LINE
 */
require_once __DIR__ . '/contact_normalize.php';
require_once __DIR__ . '/flex_theme.php';

function lead_customer_group_ensure_schema($conn): void
{
    $cols = [
        'phone_norm_hash' => "VARCHAR(64) DEFAULT NULL COMMENT 'SHA256(user_id+เบอร์10หลัก) สำหรับค้นซ้ำ'",
        'customer_group_id' => "INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'กลุ่มลูกค้าเดียวกัน — นับ lead เป็น 1'",
    ];
    foreach ($cols as $col => $def) {
        $chk = $conn->query("SHOW COLUMNS FROM leads LIKE '{$col}'");
        if ($chk && $chk->num_rows === 0) {
            $conn->query("ALTER TABLE leads ADD COLUMN {$col} {$def}");
        }
    }
    $idx = $conn->query("SHOW INDEX FROM leads WHERE Key_name = 'idx_lead_phone_hash'");
    if ($idx && $idx->num_rows === 0) {
        $conn->query('CREATE INDEX idx_lead_phone_hash ON leads (user_id, phone_norm_hash)');
    }
    $idx2 = $conn->query("SHOW INDEX FROM leads WHERE Key_name = 'idx_lead_customer_group'");
    if ($idx2 && $idx2->num_rows === 0) {
        $conn->query('CREATE INDEX idx_lead_customer_group ON leads (user_id, customer_group_id)');
    }
}

/** เบอร์ 10 หลัก (0xxxxxxxxx) หรือ null */
function lead_phone_digits_10(string $phone_raw): ?string
{
    $norm = normalize_phone_string($phone_raw);
    $digits = preg_replace('/\D/', '', $norm);
    if ($digits === '') {
        return null;
    }
    if (strlen($digits) === 11 && str_starts_with($digits, '66')) {
        $rest = substr($digits, 2);
        if (preg_match('/^[689]\d{8}$/', $rest)) {
            $digits = '0' . $rest;
        }
    }
    if (strlen($digits) === 9 && preg_match('/^[689]/', $digits)) {
        $digits = '0' . $digits;
    }
    if (strlen($digits) === 10 && $digits[0] === '0') {
        return $digits;
    }
    return null;
}

function lead_phone_norm_hash(int $user_id, string $phone_raw): ?string
{
    $d10 = lead_phone_digits_10($phone_raw);
    if ($d10 === null) {
        return null;
    }
    return hash('sha256', $user_id . ':' . $d10);
}

function lead_effective_group_id(array $row): int
{
    $gid = (int)($row['customer_group_id'] ?? 0);
    $id = (int)($row['id'] ?? 0);
    return $gid > 0 ? $gid : $id;
}

function lead_format_phone_display(string $phone_raw): string
{
    $d10 = lead_phone_digits_10($phone_raw);
    if ($d10 === null) {
        return trim($phone_raw);
    }
    return substr($d10, 0, 3) . '-' . substr($d10, 3, 3) . '-' . substr($d10, 6, 4);
}

/** นับจำนวน lead ในแต่ละกลุ่ม */
function lead_customer_group_sizes($conn, int $user_id): array
{
    $sizes = [];
    $stmt = $conn->prepare('SELECT id, customer_group_id FROM leads WHERE user_id = ?');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $gid = lead_effective_group_id($row);
        $sizes[$gid] = ($sizes[$gid] ?? 0) + 1;
    }
    $stmt->close();
    return $sizes;
}

function lead_customer_group_backfill_user($conn, int $user_id, string $encryption_key): int
{
    $stmt = $conn->prepare('SELECT id, phone_enc, customer_group_id FROM leads WHERE user_id = ? ORDER BY id ASC');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $byHash = [];
    $updated = 0;

    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $phone = '';
        if (!empty($row['phone_enc'])) {
            $phone = decrypt_data($row['phone_enc'], $encryption_key) ?: '';
        }
        $hash = lead_phone_norm_hash($user_id, $phone);

        if ($hash === null) {
            $upd = $conn->prepare('UPDATE leads SET phone_norm_hash = NULL, customer_group_id = ? WHERE id = ? AND user_id = ?');
            $upd->bind_param('iii', $id, $id, $user_id);
            $upd->execute();
            $upd->close();
            $updated++;
            continue;
        }

        if (!isset($byHash[$hash])) {
            $byHash[$hash] = [];
        }
        $byHash[$hash][] = $id;
    }

    foreach ($byHash as $hash => $ids) {
        sort($ids, SORT_NUMERIC);
        $groupId = $ids[0];
        $ph = $hash;
        $upd = $conn->prepare('UPDATE leads SET phone_norm_hash = ?, customer_group_id = ? WHERE user_id = ? AND id = ?');
        foreach ($ids as $lid) {
            $upd->bind_param('siii', $ph, $groupId, $user_id, $lid);
            $upd->execute();
            $updated++;
        }
        $upd->close();
    }

    return $updated;
}

function lead_customer_group_maybe_backfill($conn, int $user_id, string $encryption_key): void
{
    static $done = [];
    if (!empty($done[$user_id])) {
        return;
    }
    $stmt = $conn->prepare(
        'SELECT id FROM leads WHERE user_id = ? AND phone_enc IS NOT NULL AND phone_enc != \'\' 
         AND (phone_norm_hash IS NULL OR phone_norm_hash = \'\' OR customer_group_id = 0) LIMIT 1'
    );
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $needs = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($needs) {
        lead_customer_group_backfill_user($conn, $user_id, $encryption_key);
    }
    $done[$user_id] = true;
}

/**
 * หลังบันทึกเบอร์ — ตั้ง hash, ผูกกลุ่ม, คืนข้อมูล duplicate
 * @return array{linked:bool,group_id:int,matched: ?array,phone_display:string}
 */
function lead_sync_phone_identity(
    $conn,
    int $user_id,
    string $encryption_key,
    int $lead_id,
    string $phone_plain
): array {
    $hash = lead_phone_norm_hash($user_id, $phone_plain);
    $phone_display = lead_format_phone_display($phone_plain);
    $result = [
        'linked' => false,
        'group_id' => $lead_id,
        'matched' => null,
        'phone_display' => $phone_display,
    ];

    if ($hash === null) {
        $upd = $conn->prepare('UPDATE leads SET phone_norm_hash = NULL, customer_group_id = ? WHERE id = ? AND user_id = ?');
        $upd->bind_param('iii', $lead_id, $lead_id, $user_id);
        $upd->execute();
        $upd->close();
        $result['group_id'] = $lead_id;
        return $result;
    }

    $stmt = $conn->prepare(
        'SELECT id, lead_code, lead_name_enc, project_enc, owner_code, customer_group_id 
         FROM leads WHERE user_id = ? AND phone_norm_hash = ? AND id <> ? ORDER BY id ASC LIMIT 1'
    );
    $stmt->bind_param('isi', $user_id, $hash, $lead_id);
    $stmt->execute();
    $matched = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($matched) {
        $groupId = lead_effective_group_id($matched);
        $result['linked'] = true;
        $result['group_id'] = $groupId;
        $result['matched'] = [
            'id' => (int)$matched['id'],
            'lead_code' => $matched['lead_code'],
            'name' => decrypt_data($matched['lead_name_enc'] ?? '', $encryption_key) ?: '',
            'project' => decrypt_data($matched['project_enc'] ?? '', $encryption_key) ?: '',
            'owner_code' => $matched['owner_code'] ?? '',
        ];
        $upd = $conn->prepare(
            'UPDATE leads SET phone_norm_hash = ?, customer_group_id = ? WHERE user_id = ? AND phone_norm_hash = ?'
        );
        $upd->bind_param('siis', $hash, $groupId, $user_id, $hash);
        $upd->execute();
        $upd->close();
        $upd2 = $conn->prepare('UPDATE leads SET phone_norm_hash = ?, customer_group_id = ? WHERE id = ? AND user_id = ?');
        $upd2->bind_param('siii', $hash, $groupId, $lead_id, $user_id);
        $upd2->execute();
        $upd2->close();
    } else {
        $upd = $conn->prepare('UPDATE leads SET phone_norm_hash = ?, customer_group_id = ? WHERE id = ? AND user_id = ?');
        $upd->bind_param('siii', $hash, $lead_id, $lead_id, $user_id);
        $upd->execute();
        $upd->close();
        $result['group_id'] = $lead_id;
    }

    return $result;
}

function lead_row_brief(array $row, string $encryption_key): array
{
    return [
        'id' => (int)($row['id'] ?? 0),
        'lead_code' => $row['lead_code'] ?? '',
        'name' => decrypt_data($row['lead_name_enc'] ?? '', $encryption_key) ?: '',
        'project' => decrypt_data($row['project_enc'] ?? '', $encryption_key) ?: '',
        'owner_code' => $row['owner_code'] ?? '',
    ];
}

function lead_line_push_messages(string $line_user_id, array $messages): int
{
    if ($line_user_id === '' || empty($messages) || !defined('LINE_ACCESS_TOKEN')) {
        return 0;
    }
    $payload = [
        'to' => $line_user_id,
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
    curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $http;
}

/** Flex แจ้ง Lead ซ้ำ (เบอร์ตรง) */
function lead_build_duplicate_flex(
    array $matched,
    array $incoming,
    string $phone_display,
    int $group_size = 2
): array {
    $c = flex_theme_colors();
    $matched_label = trim(($matched['name'] ?? '') . ' · ' . ($matched['owner_code'] ?: $matched['project'] ?: $matched['lead_code']));
    $incoming_label = trim(($incoming['name'] ?? '') . ' · ' . ($incoming['owner_code'] ?: $incoming['project'] ?: $incoming['lead_code']));
    $matched_code = $matched['lead_code'] ?? '';

    return [
        'type' => 'flex',
        'altText' => 'Lead ซ้ำ — เบอร์ ' . $phone_display . ' ตรงกับเคส ' . $matched_code,
        'contents' => [
            'type' => 'bubble',
            'size' => 'mega',
            'header' => flex_theme_header_box('Lead ซ้ำ', 'เบอร์ ' . $phone_display . ' มีในระบบแล้ว', 'brown'),
            'styles' => array_merge(flex_theme_bubble_styles(), ['header' => ['backgroundColor' => $c['brown']]]),
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'spacing' => 'md',
                'paddingAll' => '16px',
                'contents' => [
                    flex_theme_text('เคสเดิม', 'xs', 'muted', 'none', true),
                    flex_theme_text($matched_label, 'sm', 'dark', 'none', true),
                    ['type' => 'separator', 'margin' => 'md', 'color' => $c['border']],
                    flex_theme_text('ที่กำลังบันทึก', 'xs', 'muted', 'none', true),
                    flex_theme_text($incoming_label, 'sm', 'text'),
                    flex_theme_text('ผูกกลุ่มอัตโนมัติแล้ว · นับ Lead เป็น 1 (รวม ' . $group_size . ' เคส)', 'xs', 'muted', 'md'),
                ],
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'spacing' => 'sm',
                'paddingAll' => '16px',
                'contents' => [
                    [
                        'type' => 'button',
                        'style' => 'primary',
                        'color' => $c['green'],
                        'action' => [
                            'type' => 'message',
                            'label' => 'อัปเดตเคสเดิม',
                            'text' => 'อัปเดต ' . $matched_code,
                        ],
                    ],
                    [
                        'type' => 'button',
                        'style' => 'secondary',
                        'action' => [
                            'type' => 'message',
                            'label' => 'เพิ่มหมายเหตุเคสเดิม',
                            'text' => 'หมายเหตุ ' . $matched_code . ' ',
                        ],
                    ],
                ],
            ],
        ],
    ];
}

/**
 * ส่งแจ้ง LINE เมื่อเจอเบอร์ซ้ำ
 * @return int HTTP status 0 = ไม่ส่ง
 */
function lead_notify_duplicate_phone(
    $conn,
    int $user_id,
    array $identity_result,
    array $incoming_row,
    string $encryption_key
): int {
    if (empty($identity_result['linked']) || empty($identity_result['matched'])) {
        return 0;
    }
    $stmt = $conn->prepare('SELECT line_user_id FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $line_uid = trim($u['line_user_id'] ?? '');
    if ($line_uid === '') {
        return 0;
    }

    $sizes = lead_customer_group_sizes($conn, $user_id);
    $gid = (int)($identity_result['group_id'] ?? 0);
    $group_size = $sizes[$gid] ?? 2;

    $incoming = lead_row_brief($incoming_row, $encryption_key);
    $flex = lead_build_duplicate_flex(
        $identity_result['matched'],
        $incoming,
        $identity_result['phone_display'] ?? '',
        $group_size
    );

    return lead_line_push_messages($line_uid, [$flex]);
}

/** chip counts — นับตาม customer_group_id */
function lead_chip_counts_deduped($conn, int $user_id, ?string $month, array $events_map): array
{
    $counts = [
        'all' => 0,
        'Call' => 0,
        'Follow' => 0,
        'Appointment' => 0,
        'Show' => 0,
        'Reserve' => 0,
        'Nego' => 0,
        'Win' => 0,
        'Reject' => 0,
        'Lose' => 0,
    ];
    $seen = [];
    $stmt = $conn->prepare(
        'SELECT * FROM leads WHERE user_id = ? ORDER BY COALESCE(NULLIF(customer_group_id, 0), id) ASC, id ASC'
    );
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $gid = lead_effective_group_id($row);
        if (isset($seen[$gid])) {
            continue;
        }
        $seen[$gid] = true;

        $lid = (int)$row['id'];
        $st = lead_resolved_status_for_row($row, $events_map[$lid] ?? []);
        $row['status'] = $st;
        $fm = lead_filter_month_for_row($row);
        if ($month !== null && $month !== '' && $fm !== $month) {
            continue;
        }
        $counts['all']++;
        if ($st === 'Call') {
            $counts['Call']++;
        } elseif ($st === 'Follow') {
            $counts['Follow']++;
        } elseif ($st === 'Appointment') {
            $counts['Appointment']++;
        } elseif ($st === 'Show') {
            $counts['Show']++;
        } elseif ($st === 'Reserve') {
            $counts['Reserve']++;
        } elseif (in_array($st, ['Nego', 'Close', 'Bank'], true)) {
            $counts['Nego']++;
        } elseif ($st === 'Win') {
            $counts['Win']++;
        } elseif (in_array($st, ['Rejected', 'Hold_Reject'], true)) {
            $counts['Reject']++;
        } elseif ($st === 'Lose') {
            $counts['Lose']++;
        }
    }
    $stmt->close();
    return $counts;
}
