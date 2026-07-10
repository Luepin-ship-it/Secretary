<?php
require __DIR__ . '/../lib/xlsx_reader.php';

$path = $argv[1] ?? 'c:/Users/ningp/Downloads/แก้.xlsx';
$codeFilter = $argv[2] ?? '';

$reader = new XlsxReader($path);
$sheets = $reader->sheetNames();
$sheet = $sheets[0];
foreach ($sheets as $sn) {
    $rows = $reader->readSheet($sn);
    if (!empty($rows[1]['A']) && stripos($rows[1]['A'], 'code') !== false) {
        $sheet = $sn;
        break;
    }
}
$rows = $reader->readSheet($sheet);
echo "Sheet: $sheet\n\n";

$badRent = 0;
$badDir = 0;
$badUnit = 0;

foreach ($rows as $r => $cells) {
    if ($r < 2) continue;
    $code = trim($cells['A'] ?? '');
    if ($code === '' || preg_match('/^DIT/i', $code)) continue;
    if ($codeFilter !== '' && stripos($code, $codeFilter) === false) continue;

    $unit = trim($cells['O'] ?? '');
    $dir = trim($cells['W'] ?? '');
    $ask = trim($cells['X'] ?? '');
    $rent = trim($cells['Y'] ?? '');

    $issues = [];
    if (preg_match('/transfer|fee/i', $rent)) $issues[] = "Y(rent)=$rent";
    if (preg_match('/^[0-9.,Ee+\-]+$/', $dir) && (float)str_replace(',', '', $dir) > 1000) $issues[] = "W(dir)=$dir";
    if (preg_match('/^\d+\.\d+$/', $unit) && (float)$unit < 100 && strpos($unit, '.') !== false) $issues[] = "O(unit)=$unit";
    if ($ask === '' && preg_match('/^[0-9.,Ee+\-]+$/', $dir)) $issues[] = "X(ask) empty";

    if ($issues) {
        echo "$code R$r: " . implode(' | ', $issues) . "\n";
        echo "  O=$unit W=$dir X=$ask Y=$rent\n\n";
        if (preg_match('/transfer|fee/i', $rent)) $badRent++;
        if (preg_match('/^[0-9.,Ee+\-]+$/', $dir) && (float)str_replace(',', '', $dir) > 1000) $badDir++;
        if (preg_match('/^\d+\.\d+$/', $unit) && (float)$unit < 100) $badUnit++;
    }
}

echo "--- totals: badRent=$badRent badDir=$badDir badUnit=$badUnit ---\n";
