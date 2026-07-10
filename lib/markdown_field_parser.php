<?php
/**
 * แปลงข้อความแบบแพทเทิร์น (label: value) จาก LINE เป็นฟิลด์
 */

/** @return array<string,string> */
function mdf_parse_labeled_text(string $text): array
{
    $text = str_replace(["\r\n", "\r"], "\n", trim($text));
    if ($text === '') {
        return [];
    }

    $out = [];
    $lines = explode("\n", $text);
    $currentKey = null;
    $buf = [];

    $flush = static function () use (&$out, &$currentKey, &$buf): void {
        if ($currentKey === null) {
            return;
        }
        $val = trim(implode("\n", $buf));
        if ($val !== '') {
            $out[$currentKey] = $val;
        }
        $currentKey = null;
        $buf = [];
    };

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || preg_match('/^[-=]{3,}$/u', $line)) {
            $flush();
            continue;
        }

        $line = preg_replace('/^\*\*(.+?)\*\*:?\s*/u', '$1: ', $line);
        $line = preg_replace('/^#+\s*/u', '', $line);

        if (preg_match('/^(.{2,80}?)\s*[:：]\s*(.*)$/u', $line, $m)) {
            $flush();
            $currentKey = mdf_normalize_key($m[1]);
            $rest = trim($m[2]);
            if ($rest !== '') {
                $buf[] = $rest;
            }
            continue;
        }

        if ($currentKey !== null) {
            $buf[] = $line;
        }
    }
    $flush();

    return $out;
}

function mdf_normalize_key(string $label): string
{
    $k = mb_strtolower(trim($label), 'UTF-8');
    $k = preg_replace('/\s+/u', ' ', $k);
    $k = str_replace(['_', '-'], ' ', $k);
    return trim($k);
}

/** @param array<string,string> $fields @param list<string> $aliases */
function mdf_pick(array $fields, array $aliases): string
{
    foreach ($aliases as $alias) {
        $key = mdf_normalize_key($alias);
        if (isset($fields[$key]) && trim($fields[$key]) !== '') {
            return trim($fields[$key]);
        }
    }
    return '';
}

function mdf_normalize_date(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw)) {
        return $raw;
    }
    if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})$/', $raw, $m)) {
        $d = (int)$m[1];
        $mo = (int)$m[2];
        $y = (int)$m[3];
        if ($y < 100) {
            $y += 2000;
        }
        if (checkdate($mo, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
    }
    if (preg_match('/^(\d{1,2})\s+(ม\.?ค\.?|ก\.?พ\.?|มี\.?ค\.?|เม\.?ย\.?|พ\.?ค\.?|มิ\.?ย\.?|ก\.?ค\.?|ส\.?ค\.?|ก\.?ย\.?|ต\.?ค\.?|พ\.?ย\.?|ธ\.?ค\.?)\s+(\d{2,4})$/u', $raw, $m)) {
        static $thMonths = [
            'มค' => 1, 'ม.ค' => 1, 'ม.ค.' => 1,
            'กพ' => 2, 'ก.พ' => 2, 'ก.พ.' => 2,
            'มีค' => 3, 'มี.ค' => 3, 'มี.ค.' => 3,
            'เมย' => 4, 'เม.ย' => 4, 'เม.ย.' => 4,
            'พค' => 5, 'พ.ค' => 5, 'พ.ค.' => 5,
            'มิย' => 6, 'มิ.ย' => 6, 'มิ.ย.' => 6,
            'กค' => 7, 'ก.ค' => 7, 'ก.ค.' => 7,
            'สค' => 8, 'ส.ค' => 8, 'ส.ค.' => 8,
            'กย' => 9, 'ก.ย' => 9, 'ก.ย.' => 9,
            'ตค' => 10, 'ต.ค' => 10, 'ต.ค.' => 10,
            'พย' => 11, 'พ.ย' => 11, 'พ.ย.' => 11,
            'ธค' => 12, 'ธ.ค' => 12, 'ธ.ค.' => 12,
        ];
        $monKey = preg_replace('/\s+/u', '', $m[2]);
        $mo = $thMonths[$monKey] ?? 0;
        $y = (int)$m[3];
        if ($y < 100) {
            $y += 2500 - 543;
        } elseif ($y > 2400) {
            $y -= 543;
        }
        $d = (int)$m[1];
        if ($mo > 0 && checkdate($mo, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
    }
    if (preg_match('/อีก\s*(\d+)\s*(วัน|สัปดาห์|อาทิตย์|เดือน)/u', $raw, $m)) {
        $n = max(1, (int)$m[1]);
        $unit = $m[2];
        try {
            $dt = new DateTime('today');
            if (strpos($unit, 'สัปดาห์') !== false || strpos($unit, 'อาทิตย์') !== false) {
                $dt->modify("+{$n} week");
            } elseif (strpos($unit, 'เดือน') !== false) {
                $dt->modify("+{$n} month");
            } else {
                $dt->modify("+{$n} day");
            }
            return $dt->format('Y-m-d');
        } catch (Exception $e) {
            return null;
        }
    }
    return null;
}

function mdf_normalize_price(string $raw): string
{
    $raw = trim(str_replace([',', ' '], '', $raw));
    if ($raw === '') {
        return '';
    }
    if (function_exists('normalize_price_string')) {
        return normalize_price_string($raw);
    }
    return $raw;
}

function mdf_normalize_potential(string $raw): string
{
    $u = strtoupper(trim($raw));
    if (in_array($u, ['A', 'B', 'C'], true)) {
        return $u;
    }
    if (preg_match('/\b([abc])\b/i', $raw, $m)) {
        return strtoupper($m[1]);
    }
    return 'B';
}

function mdf_normalize_sales_status(string $raw): string
{
    $s = strtolower(str_replace(' ', '', trim($raw)));
    if ($s === '') {
        return 'Sale';
    }
    if (strpos($s, 'sale&rent') !== false || strpos($s, 'sale&available') !== false || strpos($s, 'ขายและเช่า') !== false) {
        return 'sale&available';
    }
    if (strpos($s, 'withtenant') !== false || strpos($s, 'salewithtenant') !== false || strpos($s, 'พร้อมผู้เช่า') !== false) {
        return 'sale with tenant';
    }
    if ($s === 'rent' || $s === 'rental' || strpos($s, 'เช่า') !== false) {
        return 'rent';
    }
    return 'Sale';
}
