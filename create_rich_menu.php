<?php
// create_rich_menu.php
// สคริปต์ลงทะเบียน Rich Menu ผ่าน LINE Messaging API (Project Antigravity)
// แถวบน 3 ช่อง = Quick Reply หลัก (Menu · ทรัพย์ · Task) — แสดงถาวรด้านล่างแชท

require_once 'config.php';

function rich_menu_qr_postback(string $label, string $cmd): array
{
    return [
        'type' => 'postback',
        'label' => $label,
        'data' => 'action=qr&cmd=' . rawurlencode($cmd),
        'displayText' => $label,
    ];
}

// ตรวจสอบการกรอก LINE ACCESS TOKEN
if (empty(LINE_ACCESS_TOKEN) || LINE_ACCESS_TOKEN === 'akrq9QbZ5...') {
    die("❌ กรุณาตั้งค่า LINE_ACCESS_TOKEN จริงใน config.php ก่อนรันสคริปต์นี้\n");
}

// 1. กำหนดโครงสร้างพื้นที่ Rich Menu (2 Rows x 3 Columns)
// ขนาดทั้งหมด: 2500 x 1686 px (แต่ละช่องกว้างประมาณ 833 px, สูง 843 px)
$richMenuData = [
    "size" => [
        "width" => 2500,
        "height" => 1686
    ],
    "selected" => true,
    "name" => "Premium AI Agent Menu",
    "chatBarText" => "💡 เมนูบอท AI",
    "areas" => [
        // แถวที่ 1 — ตรงกับ Quick Reply หลัก
        [
            "bounds" => ["x" => 0, "y" => 0, "width" => 833, "height" => 843],
            "action" => rich_menu_qr_postback('Menu', 'menu'),
        ],
        [
            "bounds" => ["x" => 833, "y" => 0, "width" => 833, "height" => 843],
            "action" => rich_menu_qr_postback('ทรัพย์', 'listing'),
        ],
        [
            "bounds" => ["x" => 1666, "y" => 0, "width" => 834, "height" => 843],
            "action" => rich_menu_qr_postback('Task', 'tasks'),
        ],
        // แถวที่ 2 — ทางลัดเพิ่มเติม
        [
            "bounds" => ["x" => 0, "y" => 843, "width" => 833, "height" => 843],
            "action" => [
                "type" => "message",
                "label" => "รายงาน",
                "text" => "รายงาน"
            ]
        ],
        [
            "bounds" => ["x" => 833, "y" => 843, "width" => 833, "height" => 843],
            "action" => [
                "type" => "message",
                "label" => "อัปเดตลูกค้า",
                "text" => "อัปเดตข้อมูลลูกค้า"
            ]
        ],
        [
            "bounds" => ["x" => 1666, "y" => 843, "width" => 834, "height" => 843],
            "action" => [
                "type" => "message",
                "label" => "ตั้งค่าระบบ",
                "text" => "register"
            ]
        ]
    ]
];

echo "1. กำลังส่งโครงสร้าง Rich Menu ไปยัง LINE...\n";
$richMenuId = createRichMenu($richMenuData);

if ($richMenuId) {
    echo "✅ สร้าง Rich Menu สำเร็จ! ID: " . $richMenuId . "\n\n";
    file_put_contents(__DIR__ . '/rich_menu_id.txt', $richMenuId);
    echo "📝 บันทึก ID ลง rich_menu_id.txt แล้ว (webhook จะผูกเมนูให้เพื่อนใหม่อัตโนมัติ)\n\n";

    // 2. อัปโหลดภาพพื้นหลัง Rich Menu
    $imagePath = __DIR__ . '/rich_menu_bg.png';
    if (!file_exists($imagePath)) {
        die("❌ ไม่พบไฟล์ภาพพื้นหลัง '$imagePath'\nกรุณานำไฟล์ภาพที่ระบบสร้างให้มาวางในโฟลเดอร์โครงการแล้วเปลี่ยนชื่อเป็น rich_menu_bg.png\n");
    }

    echo "2. กำลังอัปโหลดรูปภาพพื้นหลัง...\n";
    if (uploadRichMenuImage($richMenuId, $imagePath)) {
        echo "✅ อัปโหลดรูปภาพสำเร็จ!\n\n";

        // 3. กำหนดให้เป็น Rich Menu เริ่มต้น (Default) สำหรับทดสอบก่อนได้
        // (หรือจะนำ Rich Menu ID นี้ไปเซฟใน DB เพื่อใช้ผูกเฉพาะกับคนจ่ายเงินแล้วภายหลัง)
        echo "3. กำลังตั้งค่าให้เป็นเมนูเริ่มต้น (Default Rich Menu)...\n";
        if (setDefaultRichMenu($richMenuId)) {
            echo "🎉 เสร็จสิ้น! Rich Menu พร้อมใช้งานบนห้องแชท LINE แล้วครับ\n";
        } else {
            echo "⚠️ ไม่สามารถตั้งค่าเป็น Default เมนูหลักได้ แต่ตัวเมนูถูกสร้างเรียบร้อยแล้ว\n";
        }
    } else {
        echo "❌ อัปโหลดรูปภาพล้มเหลว\n";
    }
} else {
    echo "❌ สร้าง Rich Menu ล้มเหลว\n";
}


// ==========================================
// ฟังก์ชันทำงานร่วมกับ LINE API
// ==========================================

function createRichMenu($data) {
    $ch = curl_init("https://api.line.me/v2/bot/richmenu");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer " . LINE_ACCESS_TOKEN
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $resObj = json_decode($response, true);
        return $resObj['richMenuId'] ?? null;
    } else {
        echo "Error: HTTP Code $httpCode | Response: $response\n";
        return null;
    }
}

function uploadRichMenuImage($richMenuId, $imagePath) {
    $mimeType = mime_content_type($imagePath);
    if ($mimeType !== 'image/png' && $mimeType !== 'image/jpeg') {
        echo "Error: รูปภาพต้องเป็นไฟล์ PNG หรือ JPEG เท่านั้น (ได้รับ $mimeType)\n";
        return false;
    }

    $ch = curl_init("https://api-data.line.me/v2/bot/richmenu/{$richMenuId}/content");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents($imagePath));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: " . $mimeType,
        "Authorization: Bearer " . LINE_ACCESS_TOKEN
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $httpCode === 200;
}

function setDefaultRichMenu($richMenuId) {
    $ch = curl_init("https://api.line.me/v2/bot/user/all/richmenu/{$richMenuId}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . LINE_ACCESS_TOKEN
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $httpCode === 200;
}
