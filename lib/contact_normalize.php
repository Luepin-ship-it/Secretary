<?php
/**
 * แปลง/กรองเบอร์/LINE จาก Excel (E-notation, ค่า shift เช่น 5.0 → bed, zone ในเบอร์)
 */

function is_scientific_number(string $s): bool
{
    return (bool)preg_match('/^[+-]?[\d.,]+[eE][+-]?\d+$/', trim($s));
}

/** Excel บันทึกจำนวนเต็มเป็น 3.0, 5.0, 744.0 */
function is_excel_whole_float(string $s): bool
{
    return (bool)preg_match('/^\d{1,4}\.0+$/', trim($s));
}

function looks_like_thai_mobile_digits(string $digits): bool
{
    return (bool)preg_match('/^\d{9,12}$/', $digits);
}

/** LINE handle / ID ตัวอักษร (ไม่ใช่เบอร์) */
function looks_like_line_handle(string $s): bool
{
    $s = trim($s);
    if ($s === '') {
        return false;
    }
    if ($s[0] === '@') {
        return strlen($s) >= 2;
    }
    if (preg_match('/\s/u', $s)) {
        return false;
    }
    if (!preg_match('/[a-zA-Z]/', $s)) {
        return false;
    }
    $digits = preg_replace('/\D/', '', $s);
    return !looks_like_thai_mobile_digits($digits);
}

/** ข้อความโซน/ที่อยู่ที่หลุดมาคอลัมน์เบอร์ */
function looks_like_zone_text(string $s): bool
{
    $s = trim($s);
    if ($s === '') {
        return false;
    }
    if (preg_match('/0[689]\d[\d\-]{7,}/', $s)) {
        return false;
    }
    if (preg_match('/[\x{0E00}-\x{0E7F}]/u', $s) && preg_match('/\s/u', $s)) {
        return true;
    }
    if (mb_strlen($s) > 20 && !looks_like_line_handle($s)) {
        return true;
    }
    return false;
}

function format_thai_mobile_digits(string $digits): string
{
    $digits = preg_replace('/\D/', '', $digits);
    if ($digits === '') {
        return '';
    }

    // รูปแบบสากล 66XXXXXXXXX (เช่น 66980164589 → 0980164589)
    if (strlen($digits) === 11 && str_starts_with($digits, '66')) {
        $rest = substr($digits, 2);
        if (preg_match('/^[689]\d{8}$/', $rest)) {
            return '0' . $rest;
        }
    }

    if (!looks_like_thai_mobile_digits($digits)) {
        return '';
    }
    if (strlen($digits) === 9 && preg_match('/^[689]/', $digits)) {
        $digits = '0' . $digits;
    }
    if (strlen($digits) === 10 && preg_match('/^0[689]\d{8}$/', $digits)) {
        return $digits;
    }
    return '';
}

function pick_first_phone_from_text(string $s): string
{
    if (preg_match_all('/0[689]\d[\d\-]{7,12}/', $s, $matches)) {
        foreach ($matches[0] as $m) {
            $out = format_thai_mobile_digits(preg_replace('/\D/', '', $m));
            if ($out !== '') {
                return $out;
            }
        }
    }
    return '';
}

function normalize_phone_string($raw): string
{
    $s = trim((string)$raw);
    if ($s === '') {
        return '';
    }

    if ($s !== '' && $s[0] === '@') {
        return $s;
    }

    if (looks_like_line_handle($s)) {
        return '';
    }

    if (preg_match('/0[689]\d/', $s)) {
        $picked = pick_first_phone_from_text($s);
        if ($picked !== '') {
            return $picked;
        }
    }

    if (looks_like_zone_text($s)) {
        return '';
    }

    if (is_scientific_number($s)) {
        $n = (float)str_replace(',', '', $s);
        if ($n > 0) {
            $s = (string)(int)round($n);
        }
    }

    if ($s !== '' && $s[0] === '@') {
        return $s;
    }

    if (is_excel_whole_float($s)) {
        return '';
    }

    $digits = preg_replace('/\D/', '', $s);
    if ($digits === '' || !looks_like_thai_mobile_digits($digits)) {
        return '';
    }

    return format_thai_mobile_digits($digits);
}

/** LINE ID — ไม่แปลงเลขสั้น (bed/parking) เป็นสตริงผิด เช่น 5.0 → 50 */
function normalize_line_id_string($raw): string
{
    $s = trim((string)$raw);
    if ($s === '') {
        return '';
    }
    if ($s[0] === '@') {
        return $s;
    }
    if (is_excel_whole_float($s) || preg_match('/^\d{1,4}$/', $s)) {
        return '';
    }
    if (preg_match('/transfer\s*fee|ค่าโอ/i', $s)) {
        return '';
    }
    if (preg_match('/0[689]\d/', $s)) {
        $asPhone = normalize_phone_string($s);
        if ($asPhone !== '') {
            return $asPhone;
        }
    }
    if (is_scientific_number($s) || (preg_match('/^\d+$/', $s) && strlen($s) >= 9)) {
        $asPhone = normalize_phone_string($s);
        return $asPhone;
    }
    if (looks_like_line_handle($s)) {
        return $s;
    }
    if (looks_like_zone_text($s)) {
        return '';
    }
    if (preg_match('/^\d+$/', $s)) {
        return '';
    }
    return $s;
}

/** กรอง + สลับ handle ที่อยู่คอลัมน์ผิด */
function repair_owner_contacts(array $fields): array
{
    $phone = trim((string)($fields['phone'] ?? ''));
    $line = trim((string)($fields['line_id'] ?? ''));
    $zone = trim((string)($fields['zone'] ?? ''));
    $trackZone = array_key_exists('zone', $fields);

    if ($phone !== '' && looks_like_line_handle($phone) && ($line === '' || is_excel_whole_float($line))) {
        $line = $phone;
        $phone = '';
    }

    if ($phone !== '' && looks_like_zone_text($phone)) {
        $phone = '';
    }

    if ($line !== '' && looks_like_zone_text($line)) {
        if ($trackZone && $zone === '') {
            $zone = $line;
        }
        $line = '';
    }

    $phoneNorm = normalize_phone_string($phone);
    $lineNorm = normalize_line_id_string($line);

    if ($lineNorm !== '' && $phoneNorm !== '' && $lineNorm === $phoneNorm) {
        $lineNorm = '';
    }

    if ($lineNorm !== '' && looks_like_thai_mobile_digits($lineNorm) && strlen($lineNorm) >= 9) {
        if ($phoneNorm === '') {
            $phoneNorm = $lineNorm;
        }
        $lineNorm = '';
    }

    $out = [
        'phone'   => $phoneNorm,
        'line_id' => $lineNorm,
    ];
    if ($trackZone) {
        $out['zone'] = $zone;
    }
    return $out;
}

function format_phone_display($raw): string
{
    $digits = normalize_phone_string($raw);
    if ($digits === '') {
        return '';
    }
    if (preg_match('/^0[689]\d{8}$/', $digits)) {
        return substr($digits, 0, 3) . '-' . substr($digits, 3, 3) . '-' . substr($digits, 6);
    }
    return $digits;
}

/** เบอร์ไทยที่ normalize แล้วต้องเป็น 0[689] + 8 หลัก */
function is_valid_thai_mobile(string $digits): bool
{
    return (bool)preg_match('/^0[689]\d{8}$/', $digits);
}

/**
 * แสดงเบอร์ + tel: + แจ้งเตือนถ้าข้อมูลต้นทางน่าสงสัย
 * @return array{display:string,tel:string,suspect:bool}
 */
function phone_contact_meta($raw): array
{
    $rawStr = trim((string)$raw);
    if ($rawStr === '') {
        return ['display' => '', 'tel' => '', 'suspect' => false];
    }
    $normalized = normalize_phone_string($rawStr);
    $display = format_phone_display($rawStr);
    $tel = '';
    if ($normalized !== '' && is_valid_thai_mobile($normalized)) {
        $tel = 'tel:+66' . substr($normalized, 1);
    }
    $rawDigits = preg_replace('/\D/', '', $rawStr);
    $suspect = $rawDigits !== '' && (
        $normalized === ''
        || !is_valid_thai_mobile($normalized)
        || !preg_match('/^\d{3}-\d{3}-\d{4}$/', $display)
    );
    if ($display === '' && $rawDigits !== '') {
        $display = $rawStr;
        $suspect = true;
    }
    return ['display' => $display, 'tel' => $tel, 'suspect' => $suspect];
}

function line_id_display($raw, $phoneNormalized = ''): string
{
    $contacts = repair_owner_contacts([
        'phone'   => $phoneNormalized,
        'line_id' => $raw,
    ]);
    return $contacts['line_id'];
}

function phone_display($raw, $lineRaw = ''): string
{
    $contacts = repair_owner_contacts([
        'phone'   => $raw,
        'line_id' => $lineRaw,
    ]);
    return format_phone_display($contacts['phone']);
}
