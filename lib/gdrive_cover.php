<?php
/** แปลง cover_image_url / Drive link → URL แสดงใน dashboard */

function gdrive_file_id_from_url(string $url): ?string
{
    if (preg_match('#/file/d/([a-zA-Z0-9_-]+)#', $url, $m)) {
        return $m[1];
    }
    if (preg_match('#/d/([a-zA-Z0-9_-]+)#', $url, $m)) {
        return $m[1];
    }
    if (preg_match('#[?&]id=([a-zA-Z0-9_-]+)#', $url, $m)) {
        return $m[1];
    }
    return null;
}

/** URL ใน <img src> — ผ่าน proxy ของเรา (Google บล็อก hotlink จาก localhost) */
function gdrive_cover_display_url(string $url): string
{
    if ($url === '') {
        return '';
    }
    $id = gdrive_file_id_from_url($url);
    if ($id === null) {
        return $url;
    }
    return 'cover.php?id=' . rawurlencode($id);
}

/**
 * URL รูปสำหรับ LINE Flex — ต้องเป็น https สาธารณะที่ LINE server โหลดได้โดยตรง
 * ลิงก์ Drive แบบ /file/d/.../view ใช้ไม่ได้; ngrok/cover.php มักโดนบล็อก
 * ใช้ thumbnail ของ Drive เมื่อไฟล์ตั้งค่าแชร์ "Anyone with the link"
 */
function gdrive_line_image_url(string $url): string
{
    if ($url === '') {
        return '';
    }
    $id = gdrive_file_id_from_url($url);
    if ($id !== null) {
        return 'https://drive.google.com/thumbnail?id=' . rawurlencode($id) . '&sz=w800';
    }
    if (preg_match('#^https://#i', $url)) {
        return $url;
    }
    return '';
}

/** ดึงรูปจาก Google Drive (ใช้ใน cover.php) */
function gdrive_fetch_image(string $fileId): ?array
{
    if (!preg_match('/^[a-zA-Z0-9_-]{10,}$/', $fileId)) {
        return null;
    }

    $sources = [
        "https://drive.google.com/uc?export=view&id=$fileId",
        "https://drive.google.com/thumbnail?id=$fileId&sz=w800",
        "https://lh3.googleusercontent.com/d/$fileId=w800",
    ];

    foreach ($sources as $src) {
        $ch = curl_init($src);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; LUEPiN/1.0)',
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($code === 200 && is_string($body) && $body !== '' && strpos($type, 'image/') === 0) {
            return ['body' => $body, 'type' => $type];
        }
    }
    return null;
}
