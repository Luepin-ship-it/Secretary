<?php
/**
 * รหัส Lead — สร้างตอน import และแสดงผล (ตัด suffix -R{แถว} เก่าออกจาก UI)
 */
require_once __DIR__ . '/contact_normalize.php';

/** สร้าง lead_code ตอน import (ไม่ใช้เลขแถว Excel) */
function lead_import_make_code(string $ownerCode, string $phone): string
{
    $ownerCode = trim($ownerCode);
    $digits = preg_replace('/\D/', '', normalize_phone_string($phone));
    if (strlen($digits) === 9 && ($digits[0] ?? '') !== '0') {
        $digits = '0' . $digits;
    }
    if ($ownerCode !== '' && strlen($digits) >= 4) {
        return 'LEAD-' . $ownerCode . '-' . substr($digits, -4);
    }
    if ($ownerCode !== '') {
        return 'LEAD-' . $ownerCode;
    }
    if (strlen($digits) >= 8) {
        return 'LEAD-' . substr($digits, -8);
    }
    if (strlen($digits) >= 4) {
        return 'LEAD-' . $digits;
    }
    return 'LEAD-' . strtoupper(bin2hex(random_bytes(3)));
}

/** ตัด suffix -R141 (เลขแถว import เก่า) ก่อนแสดงใน UI */
function lead_code_for_display(string $code): string
{
    $c = trim($code);
    if ($c === '') {
        return '';
    }
    $out = preg_replace('/-R\d+$/i', '', $c);
    return $out !== '' ? $out : $c;
}

/** ตัด -R{แถว} ในชื่องานที่อ้าง lead_code เก่า */
function lead_title_for_display(string $title): string
{
    if ($title === '') {
        return '';
    }
    return preg_replace('/(LEAD-[A-Za-z0-9][\w.-]*)-R\d+/i', '$1', $title);
}
