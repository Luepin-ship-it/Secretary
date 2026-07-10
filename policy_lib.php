<?php
// policy_lib.php — เวอร์ชันนโยบาย + schema + URL หน้ากฎหมาย

define('LUEPIN_POLICY_VERSION', '2026-06-12');

function policy_ensure_schema($conn) {
    $cols = [
        'policy_version VARCHAR(20) DEFAULT NULL COMMENT \'เวอร์ชันนโยบายที่ยอมรับ\'',
        'policy_accepted_at DATETIME DEFAULT NULL COMMENT \'วันที่ยอมรับนโยบายล่าสุด\'',
    ];
    foreach ($cols as $col_def) {
        $col_name = preg_match('/^(\w+)/', $col_def, $m) ? $m[1] : '';
        if ($col_name === '') continue;
        $chk = $conn->query("SHOW COLUMNS FROM users LIKE '$col_name'");
        if ($chk && $chk->num_rows === 0) {
            $conn->query("ALTER TABLE users ADD COLUMN $col_def");
        }
    }
}

function policy_page_urls() {
    if (!function_exists('auth_base_url')) {
        return ['privacy' => 'privacy.php', 'terms' => 'terms.php'];
    }
    $base = auth_base_url();
    return [
        'privacy' => $base . '/privacy.php',
        'terms'   => $base . '/terms.php',
    ];
}

function policy_quiz_options() {
    return [
        'owner'  => 'ของฉัน — แยกตามบัญชี LINE ของฉัน',
        'luepin' => 'ของ LUEPiN / บริษัท',
        'shared' => 'แชร์กับผู้ใช้อื่นในระบบ',
    ];
}

function policy_quiz_is_correct($answer) {
    return trim($answer) === 'owner';
}
