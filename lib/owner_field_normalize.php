<?php
/**
 * ทำความสะอาดฟิลด์ owner จาก Excel (ราคา sci notation, คอลัมน์ shift, transfer fee ใน rent)
 */

function is_scientific_price_notation(string $s): bool
{
    return (bool)preg_match('/^[+-]?[\d.,]+[eE][+-]?\d+$/', trim($s));
}

function normalize_price_string($raw): string
{
    $s = trim((string)$raw);
    if ($s === '') {
        return '';
    }
    if (is_scientific_price_notation($s)) {
        $n = (float)str_replace(',', '', $s);
        if ($n > 0) {
            return (string)(int)round($n);
        }
    }
    return $s;
}

function is_transfer_fee_text(string $s): bool
{
    return (bool)preg_match('/transfer\s*fee|ค่าโอ/i', $s);
}

function looks_like_compass_direction(string $s): bool
{
    $s = trim(mb_strtolower($s));
    if ($s === '') {
        return false;
    }
    static $needles = [
        'north', 'south', 'east', 'west',
        'northeast', 'northwest', 'southeast', 'southwest',
        'เหนือ', 'ใต้', 'ออก', 'ตก',
    ];
    foreach ($needles as $needle) {
        if (strpos($s, $needle) !== false) {
            return true;
        }
    }
    return (bool)preg_match('/\b(n|s|e|w|ne|nw|se|sw)\b/i', $s);
}

/** ค่าในคอลัมน์ทิศที่เป็นตัวเลขล้วน (มักเป็นราคาขายที่ shift มา) */
function is_numeric_direction_mismatch(string $s): bool
{
    $s = trim($s);
    if ($s === '' || looks_like_compass_direction($s)) {
        return false;
    }
    return (bool)preg_match('/^[+-]?[\d.,Ee+\-]+$/', $s);
}

function sanitize_rental_price(string $s): string
{
    $s = trim($s);
    if ($s === '' || is_transfer_fee_text($s)) {
        return '';
    }
    return $s;
}

function sanitize_direction(string $s): string
{
    $s = trim($s);
    if ($s === '' || is_numeric_direction_mismatch($s)) {
        return '';
    }
    return $s;
}

/** Excel บันทึกเลขห้องเป็นจำนวนเต็ม → 106.0, 132.0 */
function normalize_unit_no_string(string $s): string
{
    $s = trim($s);
    if ($s === '') {
        return '';
    }
    if (preg_match('/^\d+\.0+$/', $s)) {
        return (string)(int)(float)$s;
    }
    return $s;
}

/**
 * แก้แถว Excel ที่คอลัมน์ shift: ราคาขายไปอยู่ทิศ, transfer fee ไปอยู่ rent
 * ใช้เฉพาะกรณีชัดเจน — ไม่เดา unit_no ที่เพี้ยน
 */
function repair_shifted_owner_fields(array $row): array
{
    $asking = trim((string)($row['asking_price'] ?? ''));
    $direction = trim((string)($row['direction'] ?? ''));
    $rent = sanitize_rental_price((string)($row['rental_price'] ?? ''));
    $unit = normalize_unit_no_string((string)($row['unit_no'] ?? ''));

    if ($asking === '' && is_numeric_direction_mismatch($direction)) {
        $n = (float)str_replace(',', '', normalize_price_string($direction));
        if ($n >= 100000) {
            $asking = $direction;
            $direction = '';
        }
    }

    $direction = sanitize_direction($direction);

    $row['asking_price'] = $asking;
    $row['direction'] = $direction;
    $row['rental_price'] = $rent;
    $row['unit_no'] = $unit;

    return $row;
}

function fmt_price_full($raw): string
{
    $s = normalize_price_string($raw);
    if ($s === '') {
        return '';
    }
    if (preg_match('/^([\d.,]+)\s*ลบ\.?$/u', $s, $m)) {
        $n = (float)str_replace(',', '', $m[1]);
        return number_format((int)round($n * 1000000));
    }
    if (preg_match('/^([\d.,]+)\s*\/\s*(ด\.?|เดือน)/u', $s, $m)) {
        return number_format((int)str_replace(',', '', $m[1])) . '/เดือน';
    }
    if (preg_match('/^[\d.,]+$/', $s)) {
        return number_format((int)str_replace(',', '', $s));
    }
    return $s;
}
