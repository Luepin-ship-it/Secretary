<?php
require_once dirname(__DIR__) . '/lib/xlsx_reader.php';
require_once dirname(__DIR__) . '/lib/tan_workbook_import.php';
$path = $argv[1] ?? 'c:/Users/ningp/Downloads/Tan New List.xlsx';
$r = new XlsxReader($path);
$rows = $r->readSheet('Tan');
$codes = [];
foreach ($rows as $n => $c) {
    if ($n < 4) continue;
    $cd = TanWorkbookImport::normalizeCode($c['D'] ?? '');
    if ($cd) $codes[$cd][] = $n;
}
echo "unique D codes: " . count($codes) . PHP_EOL;
$all = [];
foreach ($rows as $n => $cells) {
    if ($n < 4) continue;
    foreach ($cells as $col => $v) {
        $cd = TanWorkbookImport::normalizeCode($v);
        if ($cd) $all[$cd][$col] = ($all[$cd][$col] ?? 0) + 1;
    }
}
echo "unique any-col codes: " . count($all) . PHP_EOL;
// best column per code
$bestCol = [];
foreach ($all as $code => $cols) {
    arsort($cols);
    $bestCol[$code] = array_key_first($cols);
}
$colFreq = [];
foreach ($bestCol as $code => $col) $colFreq[$col] = ($colFreq[$col] ?? 0) + 1;
arsort($colFreq);
echo "best code column distribution: " . json_encode(array_slice($colFreq, 0, 8, true)) . PHP_EOL;
