<?php
// welcome_flex_lib.php — Flex Carousel ต้อนรับเพื่อนใหม่ (สอดคล้อง onboarding เว็บ)

require_once __DIR__ . '/lib/flex_theme.php';

function welcome_flex_urls() {
    $base = function_exists('build_public_base_url')
        ? build_public_base_url()
        : (function_exists('auth_base_url') ? auth_base_url() : '');
    return [
        'login'      => $base . '/login.php',
        'structure'  => $base . '/login.php?slide=4',
        'privacy'    => $base . '/privacy.php',
    ];
}

function welcome_flex_text_block($text, $size = 'sm', $role = 'text', $margin = 'md') {
    return flex_theme_text($text, $size, $role, $margin);
}

function welcome_flex_bubble($step_label, $title, $body_lines, $buttons = []) {
    $c = flex_theme_colors();
    $body_contents = [
        welcome_flex_text_block($step_label, 'xs', 'muted', 'none'),
        welcome_flex_text_block($title, 'lg', 'dark', 'sm'),
    ];
    foreach ($body_lines as $line) {
        $body_contents[] = welcome_flex_text_block($line, 'sm', 'text', 'md');
    }

    $bubble = [
        'type' => 'bubble',
        'size' => 'kilo',
        'header' => flex_theme_header_box('เลขา AI', null, 'green'),
        'body' => [
            'type' => 'box',
            'layout' => 'vertical',
            'backgroundColor' => $c['white'],
            'paddingAll' => '16px',
            'contents' => $body_contents,
        ],
        'styles' => [
            'header' => ['backgroundColor' => $c['green']],
            'body' => ['backgroundColor' => $c['white']],
        ],
    ];

    if ($buttons) {
        $footer_contents = [];
        foreach ($buttons as $btn) {
            $footer_contents[] = [
                'type' => 'button',
                'style' => $btn['style'] ?? 'link',
                'height' => 'sm',
                'color' => $btn['color'] ?? $c['brown'],
                'action' => $btn['action'],
            ];
        }
        $bubble['footer'] = [
            'type' => 'box',
            'layout' => 'vertical',
            'spacing' => 'sm',
            'backgroundColor' => $c['surface'],
            'paddingAll' => '12px',
            'contents' => $footer_contents,
        ];
        $bubble['styles']['footer'] = ['backgroundColor' => $c['surface']];
    }

    return $bubble;
}

function build_welcome_onboarding_carousel() {
    $urls = welcome_flex_urls();
    $login = $urls['login'];
    $structure = $urls['structure'];
    $c = flex_theme_colors();

    $bubbles = [
        welcome_flex_bubble('1 / 6', 'เลขา AI คืออะไร?', [
            'ผู้ช่วย AI สำหรับงานขาย — ดูแล Lead งาน และเป้ายอด',
            'คุยผ่าน LINE แล้วเปิด Dashboard บนเว็บทำงานเต็มจอ',
        ]),
        welcome_flex_bubble('2 / 6', 'การเก็บข้อมูล', [
            'สมาชิกที่ใช้งาน/ต่ออายุ ดูย้อนหลังได้ 1 ปี',
            'ไม่ต่ออายุ ยังส่งออกข้อมูลได้ 3 เดือนก่อนลบจากเซิร์ฟเวอร์',
        ], [[
            'style' => 'link',
            'action' => ['type' => 'uri', 'label' => 'นโยบายข้อมูล', 'uri' => $urls['privacy']],
        ]]),
        welcome_flex_bubble('3 / 6', 'ข้อมูลของคุณ', [
            'Lead · Project · Task ที่คุณบันทึก เป็นของคุณ',
            'ทีมพัฒนาไม่เห็นข้อมูลจริง — เห็นเฉพาะส่วนที่ปิดบังแล้วเพื่อฝึก AI',
        ]),
        welcome_flex_bubble('4 / 6', 'ข้อมูลแยกตามบัญชี', [
            'แยกตาม LINE ID — ไม่ปนกับผู้ใช้อื่น',
            'คุณจัดการได้เฉพาะของตัวเอง ผ่าน Dashboard และ LINE',
        ]),
        welcome_flex_bubble('5 / 6', 'โครงสร้างเริ่มต้น', [
            'โครงสร้างและเครื่องมือนี้ เริ่มจากงานที่ปรึกษาอสังหาริมทรัพย์',
            'หากเป็นสาขาอาชีพอื่นและต้องการปรับโครงการใช้งาน พิมพ์「ปรับโครงสร้าง」ในแชทนี้ได้เลย',
            'แอดมินตอบภายใน 1 ชม. · 10:00–20:00 (ทุกวัน)',
        ], [
            [
                'style' => 'primary',
                'color' => $c['brown'],
                'action' => ['type' => 'message', 'label' => 'ปรับโครงสร้าง', 'text' => 'ปรับโครงสร้าง'],
            ],
            [
                'style' => 'link',
                'action' => ['type' => 'uri', 'label' => 'ดูโครงสร้างเบื้องต้น', 'uri' => $structure],
            ],
        ]),
        welcome_flex_bubble('6 / 6', 'พร้อมลงทะเบียน', [
            'กดปุ่มด้านล่าง → เข้าสู่ระบบด้วย LINE',
            'ครั้งแรกกรอกข้อมูลสั้นๆ แล้วเข้า Dashboard ได้เลย',
        ], [[
            'style' => 'primary',
            'color' => $c['green'],
            'action' => ['type' => 'uri', 'label' => 'ลงทะเบียน / เข้าใช้งาน', 'uri' => $login],
        ]]),
    ];

    return [
        'type' => 'flex',
        'altText' => 'ยินดีต้อนรับสู่ เลขา AI — เลื่อนดูการ์ดแล้วกดลงทะเบียน',
        'contents' => [
            'type' => 'carousel',
            'contents' => $bubbles,
        ],
    ];
}

function send_customize_structure_ack($replyToken) {
    $text = "รับคำขอ「ปรับโครงสร้าง」แล้ว\nแอดมินจะติดต่อกลับภายใน 1 ชั่วโมง (เวลาทำการ 10:00–20:00 ทุกวัน)\n\nถ้าพร้อมใช้งานมาตรฐาน (อสังหาริมทรัพย์) กดลงทะเบียนได้เลยที่ลิงก์ในการ์ดด้านบน";
    if (function_exists('quick_reply_send')) {
        quick_reply_send($replyToken, [['type' => 'text', 'text' => $text]], 'main');
        return;
    }
    if (!function_exists('send_line_reply')) {
        return;
    }
    send_line_reply([
        'replyToken' => $replyToken,
        'messages' => [['type' => 'text', 'text' => $text]],
    ]);
}
