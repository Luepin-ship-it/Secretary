<?php
// branch_config.php — กำหนดสาขาสายงานขายและเมนู Dashboard ต่อสาขา

function branch_definitions() {
    return [
        'real_estate' => [
            'label'         => 'ที่ปรึกษาอสังหาริมทรัพย์',
            'business_type' => 'Real Estate',
        ],
        'metal_sheet' => [
            'label'         => 'พนักงานเมทัลชีท',
            'business_type' => 'Metal Sheet',
        ],
    ];
}

function branch_is_valid($key) {
    return isset(branch_definitions()[$key]);
}

/** สาขาที่เลือกได้ตอนลงทะเบียน (ตัดเมทัลชีทออกชั่วคราว) */
function branch_registration_options() {
    $defs = branch_definitions();
    unset($defs['metal_sheet']);
    return $defs;
}

function branch_from_user($user) {
    if (!is_array($user)) {
        return 'real_estate';
    }
    $sb = trim($user['sales_branch'] ?? '');
    if ($sb !== '' && branch_is_valid($sb)) {
        return $sb;
    }
    if (($user['business_type'] ?? '') === 'Metal Sheet') {
        return 'metal_sheet';
    }
    return 'real_estate';
}

function branch_is_metal_sheet($user) {
    return branch_from_user($user) === 'metal_sheet';
}

function branch_label($key) {
    $defs = branch_definitions();
    return $defs[$key]['label'] ?? $key;
}

function branch_business_type($key) {
    $defs = branch_definitions();
    return $defs[$key]['business_type'] ?? 'Real Estate';
}

function branch_nav_items($user) {
    if (branch_is_metal_sheet($user)) {
        return [
            'home'   => ['icon' => 'layout-dashboard', 'label' => 'หลัก'],
            'leads'  => ['icon' => 'users',            'label' => 'Lead'],
            'tasks'  => ['icon' => 'check-square',     'label' => 'งาน'],
            'report' => ['icon' => 'file-text',        'label' => 'รายงาน'],
        ];
    }
    return [
        'home'     => ['icon' => 'layout-dashboard', 'label' => 'หลัก'],
        'products' => ['icon' => 'building-2',       'label' => 'ทรัพย์'],
        'leads'    => ['icon' => 'users',            'label' => 'Lead'],
        'tasks'    => ['icon' => 'check-square',     'label' => 'งาน'],
        'pipeline' => ['icon' => 'target',           'label' => 'เป้า'],
        'map'      => ['icon' => 'map',              'label' => 'แผนที่'],
        'documents'=> ['icon' => 'files',            'label' => 'เอกสาร'],
        'report'   => ['icon' => 'file-text',        'label' => 'ราคา'],
    ];
}

/** ข้อมูลตัวอย่างสำหรับ mini preview ใน onboarding (ไม่ใช่ข้อมูลจริง) */
function branch_intro_preview_data($branch_key = 'real_estate') {
    $sets = [
        'real_estate' => [
            'home' => [
                'desc' => 'ภาพรวมงานวันนี้ — ลีดค้าง Follow และงานใกล้ครบ',
                'rows' => [
                    ['label' => 'ลีดรอ Follow', 'value' => '12 ราย'],
                    ['label' => 'งานวันนี้', 'value' => '3 ชิ้น'],
                    ['label' => 'Win เดือนนี้', 'value' => '2 ดีล'],
                ],
            ],
            'products' => [
                'desc' => 'ทรัพย์ฝากขาย (Owner) — สถานะ ราคา และรูป',
                'rows' => [
                    ['label' => 'NING001', 'value' => '฿9.5M · Active'],
                    ['label' => 'NING002', 'value' => '฿6.2M · Active'],
                ],
            ],
            'leads' => [
                'desc' => 'ลูกค้าเป้าหมาย — Pipeline ตั้งแต่ Call ถึง Win',
                'rows' => [
                    ['label' => 'L-1042 · Follow', 'value' => 'โทรกลับวศ.'],
                    ['label' => 'L-1038 · Show', 'value' => 'นัดดูทรัพย์'],
                ],
            ],
            'tasks' => [
                'desc' => 'งานที่ต้องทำ — Eisenhower และ due date',
                'rows' => [
                    ['label' => 'ทำทันที', 'value' => 'ส่งใบเสนอราคา'],
                    ['label' => 'วางแผน', 'value' => 'อัปเดต Listing'],
                ],
            ],
            'pipeline' => [
                'desc' => 'เป้ายอดขาย — ติดตามความคืบหน้ารายเดือน',
                'rows' => [
                    ['label' => 'เป้า Q2', 'value' => '฿12M'],
                    ['label' => 'ทำได้แล้ว', 'value' => '฿4.2M'],
                ],
            ],
            'map' => [
                'desc' => 'แผนที่ CRM — ทรัพย์และโครงการบนแผนที่',
                'rows' => [
                    ['label' => 'โครงการ A', 'value' => '12 ทรัพย์'],
                    ['label' => 'โครงการ B', 'value' => '8 ทรัพย์'],
                ],
            ],
            'report' => [
                'desc' => 'รายงานราคา / ปรับราคา — สรุปและส่งต่อทีม',
                'rows' => [
                    ['label' => 'ทรัพย์ NING001', 'value' => 'รอปรับราคา'],
                    ['label' => 'รายงานล่าสุด', 'value' => '5 มิ.ย. 69'],
                ],
            ],
        ],
    ];
    return $sets[$branch_key] ?? [];
}

function branch_default_sales_name($user) {
    $first = trim($user['first_name'] ?? '');
    $last  = trim($user['last_name'] ?? '');
    $full  = trim($first . ' ' . $last);
    if ($full !== '') {
        return $full;
    }
    return trim($user['user_name'] ?? '') ?: 'เซลล์';
}

function branch_sales_display_name($user) {
    $custom = trim($user['sales_display_name'] ?? '');
    if ($custom !== '') {
        return $custom;
    }
    return branch_default_sales_name($user);
}

function branch_thai_short_date($date_str = null) {
    $ts = $date_str ? strtotime($date_str) : time();
    if (!$ts) {
        $ts = time();
    }
    $be = ((int)date('Y', $ts) + 543) % 100;
    return (int)date('j', $ts) . '/' . (int)date('n', $ts) . '/' . $be;
}
