<?php
/**
 * Production overrides — คัดลอกเป็น config.local.php บน GCP (อย่า commit ไฟล์จริง)
 *
 *   cp config.local.example.php config.local.php
 *   nano config.local.php
 */
define('DB_HOST', 'localhost');
define('DB_USER', 'antigravity');
define('DB_PASS', 'CHANGE_ME_STRONG_PASSWORD');
define('DB_NAME', 'antigravity_db');

define('LINE_ACCESS_TOKEN', 'YOUR_LINE_CHANNEL_ACCESS_TOKEN');
define('OPENAI_API_KEY', 'YOUR_OPENAI_KEY');
define('LINE_LIFF_ID', 'YOUR_LIFF_ID');
// LIFF ค้นหาชื่อโครงการ (แยกจาก register) — Endpoint: https://YOUR_DOMAIN/liff/project_search.php
define('LINE_LIFF_PROJECT_SEARCH_ID', 'YOUR_LIFF_PROJECT_SEARCH_ID');
// LIFF คู่มืออัปรูป — Endpoint: https://YOUR_DOMAIN/liff/project_photos.php
define('LINE_LIFF_PROJECT_PHOTOS_ID', 'YOUR_LIFF_PROJECT_PHOTOS_ID');
define('LINE_LOGIN_CHANNEL_ID', 'YOUR_LINE_LOGIN_CHANNEL_ID');
define('LINE_LOGIN_CHANNEL_SECRET', 'YOUR_LINE_LOGIN_CHANNEL_SECRET');
define('LINE_OA_BASIC_ID', '');
// หลังรัน create_rich_menu.php ใส่ ID ที่นี่ (หรือใช้ rich_menu_id.txt อัตโนมัติ)
// define('LINE_RICH_MENU_ID', 'richmenu-xxxxxxxx');

define('GOOGLE_MAPS_API_KEY', 'YOUR_MAPS_JS_API_KEY');
define('GOOGLE_MAP_ID', '');
define('GOOGLE_DRIVE_API_KEY', '');
