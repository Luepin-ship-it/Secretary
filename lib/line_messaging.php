<?php
/**
 * LINE Messaging API — รอประมวลผล (loading / ข้อความ), push และปุ่ม Flex แบบ clipboard
 *
 * หมายเหตุ: Webhook ไม่บอกว่า user ใช้มือถือหรือ Desktop
 * - loading API แสดงเฉพาะจอที่เปิดแชทอยู่ (มือถือรองรับดี)
 * - push ข้อความจะโผล่ในแชททุกอุปกรณ์ — ใช้โหมด text เฉพาะเมื่อใช้ LINE Desktop เป็นหลัก
 */

function line_loading_seconds(int $seconds): int
{
    $seconds = max(5, min(60, $seconds));
    return (int)(round($seconds / 5) * 5);
}

function line_slow_work_seconds(string $context): int
{
    return $context === 'lead' ? 60 : 20;
}

/** loading = ไอคอนรอ (ค่าเริ่มต้น, เหมาะมือถือ) | text = push ข้อความรอ (Desktop เป็นหลัก) */
function line_wait_style_for_user(?array $user): string
{
    $style = strtolower(trim((string)($user['line_wait_style'] ?? 'loading')));
    return $style === 'text' ? 'text' : 'loading';
}

function line_desktop_wait_text(string $context = 'default'): string
{
    return 'สักครู่น้าา....';
}

/** ปล่อย HTTP 200 ให้ LINE webhook ก่อนประมวลผลต่อ */
function webhook_release_line_connection(): void
{
    static $released = false;
    if ($released || headers_sent()) {
        return;
    }
    $released = true;

    http_response_code(200);
    header('Content-Length: 0');
    header('Connection: close');

    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    @flush();

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    ignore_user_abort(true);
}

/** Push ข้อความธรรมดาไปหา user (1:1) — $withQuickReply แนบเมนูลัด (Desktop / ระหว่างรอ) */
function line_push_text(string $lineUserId, string $text, bool $withQuickReply = false, string $qrKind = 'main'): int
{
    $lineUserId = trim($lineUserId);
    $text = trim($text);
    if ($lineUserId === '' || $text === '' || !defined('LINE_ACCESS_TOKEN') || LINE_ACCESS_TOKEN === '') {
        return 0;
    }

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
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $result = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $logPath = dirname(__DIR__) . '/line_webhook_debug.log';
    @file_put_contents(
        $logPath,
        '[LINE Push] ' . date('Y-m-d H:i:s') . " | HTTP: {$httpCode} | Response: {$result} | to: {$lineUserId}\n",
        FILE_APPEND
    );

    return $httpCode;
}

/**
 * แจ้งว่ากำลังประมวลผล แล้วปล่อย webhook
 * ค่าเริ่มต้น: loading (ไอคอน) — ตั้ง users.line_wait_style = 'text' ถ้าใช้ LINE Desktop เป็นหลัก
 *
 * @param string $context listing | report | lead
 */
function line_begin_slow_work(string $chatId, string $context = 'default', ?array $user = null): bool
{
    webhook_release_line_connection();

    $style = line_wait_style_for_user($user);
    if ($style === 'text') {
        $httpCode = line_push_text($chatId, line_desktop_wait_text($context), true);
        @file_put_contents(
            dirname(__DIR__) . '/line_webhook_debug.log',
            '[LINE Wait] ' . date('Y-m-d H:i:s') . " | mode: text | context: {$context} | chatId: {$chatId}\n",
            FILE_APPEND
        );
        return $httpCode >= 200 && $httpCode < 300;
    }

    $ok = line_start_loading($chatId, line_slow_work_seconds($context));
    @file_put_contents(
        dirname(__DIR__) . '/line_webhook_debug.log',
        '[LINE Wait] ' . date('Y-m-d H:i:s') . " | mode: loading | context: {$context} | chatId: {$chatId}\n",
        FILE_APPEND
    );
    return $ok;
}

/** แสดง loading animation ในแชท 1:1 (หายเมื่อส่งข้อความใหม่หรือครบเวลา) */
function line_start_loading(string $chatId, int $seconds = 20): bool
{
    $chatId = trim($chatId);
    if ($chatId === '' || !defined('LINE_ACCESS_TOKEN') || LINE_ACCESS_TOKEN === '') {
        return false;
    }

    $payload = [
        'chatId' => $chatId,
        'loadingSeconds' => line_loading_seconds($seconds),
    ];

    $ch = curl_init('https://api.line.me/v2/bot/chat/loading/start');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . LINE_ACCESS_TOKEN,
    ]);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $result = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $logPath = dirname(__DIR__) . '/line_webhook_debug.log';
    @file_put_contents(
        $logPath,
        '[LINE Loading] ' . date('Y-m-d H:i:s') . " | HTTP: {$httpCode} | Response: {$result} | chatId: {$chatId} | seconds: "
        . line_loading_seconds($seconds) . "\n",
        FILE_APPEND
    );

    return $httpCode >= 200 && $httpCode < 300;
}

function line_wait_style_save(mysqli $conn, int $userId, string $style): bool
{
    $style = $style === 'text' ? 'text' : 'loading';
    $stmt = $conn->prepare('UPDATE users SET line_wait_style = ? WHERE id = ?');
    $stmt->bind_param('si', $style, $userId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/** สลับโหมดรอด้วยข้อความในแชท (เพราะ LINE ไม่แยกมือถือ/Desktop ให้) */
function line_wait_style_handle_text(mysqli $conn, array $user, string $text, string $replyToken): bool
{
    $map = [
        'โหมดรอมือถือ' => 'loading',
        'โหมดรอเดสท็อป' => 'text',
        'โหมดรอ desktop' => 'text',
        'โหมดรอ loading' => 'loading',
    ];
    $lower = mb_strtolower(trim($text), 'UTF-8');
    if (!isset($map[$text]) && !isset($map[$lower])) {
        return false;
    }
    $style = $map[$text] ?? $map[$lower];
    line_wait_style_save($conn, (int)$user['id'], $style);
    $msg = $style === 'text'
        ? "ตั้งโหมดรอเป็นข้อความแล้ว — Desktop จะเห็น \"สักครู่น้าา....\" ขณะรอ\n(ข้อความโผล่ทุกอุปกรณ์ ถ้าใช้มือถือเป็นหลัก พิมพ์「โหมดรอมือถือ」)"
        : "ตั้งโหมดรอเป็นไอคอน loading แล้ว — เหมาะ LINE บนมือถือ\n(ถ้าใช้ Desktop เป็นหลัก พิมพ์「โหมดรอเดสท็อป」)";
    if (function_exists('quick_reply_send')) {
        quick_reply_send($replyToken, [['type' => 'text', 'text' => $msg]], 'main');
    } elseif (function_exists('send_line_text_reply')) {
        send_line_text_reply($replyToken, $msg);
    }
    return true;
}

/** ปุ่ม Flex ที่คัดลอกข้อความเข้า clipboard (LINE 14+) */
function line_flex_clipboard_button(string $label, string $text, string $style = 'secondary', string $height = 'sm'): array
{
    $text = trim($text);
    if ($text === '') {
        return [];
    }
    if (mb_strlen($text, 'UTF-8') > 1000) {
        $text = mb_substr($text, 0, 1000, 'UTF-8');
    }

    $label = trim($label);
    if ($label === '') {
        $label = 'คัดลอก';
    }
    if (mb_strlen($label, 'UTF-8') > 40) {
        $label = mb_substr($label, 0, 40, 'UTF-8');
    }

    return [
        'type' => 'button',
        'style' => $style,
        'height' => $height,
        'action' => [
            'type' => 'clipboard',
            'label' => $label,
            'clipboardText' => $text,
        ],
    ];
}

/** เพิ่มปุ่มท้าย footer ของ bubble (รองรับ footer แนวนอน/แนวตั้ง) */
function line_flex_append_footer_buttons(array $bubble, array $buttons): array
{
    $buttons = array_values(array_filter($buttons));
    if (!$buttons) {
        return $bubble;
    }

    if (!isset($bubble['footer'])) {
        $bubble['footer'] = [
            'type' => 'box',
            'layout' => 'vertical',
            'spacing' => 'sm',
            'contents' => [],
        ];
    }

    $footer = &$bubble['footer'];
    if (($footer['type'] ?? '') !== 'box') {
        return $bubble;
    }

    if (($footer['layout'] ?? '') === 'horizontal') {
        $existing = $footer['contents'] ?? [];
        $footer['layout'] = 'vertical';
        $footer['spacing'] = 'sm';
        $footer['contents'] = [
            [
                'type' => 'box',
                'layout' => 'horizontal',
                'spacing' => 'sm',
                'contents' => $existing,
            ],
            [
                'type' => 'box',
                'layout' => 'horizontal',
                'spacing' => 'sm',
                'contents' => $buttons,
            ],
        ];
    } else {
        foreach ($buttons as $btn) {
            $footer['contents'][] = $btn;
        }
    }

    return $bubble;
}

/** อ่าน Rich Menu ID จาก config หรือ rich_menu_id.txt (หลังรัน create_rich_menu.php) */
function line_rich_menu_id(): ?string
{
    if (defined('LINE_RICH_MENU_ID') && LINE_RICH_MENU_ID !== '') {
        return (string)LINE_RICH_MENU_ID;
    }
    $path = dirname(__DIR__) . '/rich_menu_id.txt';
    if (is_file($path)) {
        $id = trim((string)file_get_contents($path));
        return $id !== '' ? $id : null;
    }
    return null;
}

/** ผูก Rich Menu ถาวรให้ user (แสดง「💡 เมนูบอท AI」ด้านล่างแชท — มือถือ + Desktop) */
function line_link_user_rich_menu(string $lineUserId): bool
{
    $lineUserId = trim($lineUserId);
    $menuId = line_rich_menu_id();
    if ($lineUserId === '' || $menuId === null || !defined('LINE_ACCESS_TOKEN') || LINE_ACCESS_TOKEN === '') {
        return false;
    }

    $url = 'https://api.line.me/v2/bot/user/' . rawurlencode($lineUserId) . '/richmenu/' . rawurlencode($menuId);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . LINE_ACCESS_TOKEN,
    ]);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $result = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    @file_put_contents(
        dirname(__DIR__) . '/line_webhook_debug.log',
        '[LINE RichMenu Link] ' . date('Y-m-d H:i:s') . " | HTTP: {$httpCode} | user: {$lineUserId} | menu: {$menuId} | Response: {$result}\n",
        FILE_APPEND
    );

    return $httpCode >= 200 && $httpCode < 300;
}
