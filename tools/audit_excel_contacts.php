<?php
require __DIR__ . '/../lib/xlsx_reader.php';

$path = $argv[1] ?? 'c:/Users/ningp/Downloads/แก้.xlsx';
$filter = $argv[2] ?? '';

$reader = new XlsxReader($path);
$rows = null;
foreach ($reader->sheetNames() as $sn) {
    $r = $reader->readSheet($sn);
    if (trim($r[1]['A'] ?? '') === 'รหัส') {
        $rows = $r;
        echo "Sheet: $sn\n\n";
        break;
    }
}
if (!$rows) { echo "no clean sheet\n"; exit(1); }

foreach ($rows as $rn => $c) {
    if ($rn < 2) continue;
    $code = trim($c['A'] ?? '');
    if ($code === '' || preg_match('/^DIT/i', $code)) continue;
    if ($filter !== '' && stripos($code . ' ' . ($c['I'] ?? ''), $filter) === false) continue;

    $name = trim($c['I'] ?? '');
    $phone = trim($c['J'] ?? '');
    $line = trim($c['K'] ?? '');
    $bed = trim($c['M'] ?? '');
    $park = trim($c['U'] ?? '');

    $flag = [];
    if ($line !== '' && preg_match('/^\d{1,4}(\.0+)?$/', $line)) $flag[] = "K=$line (short num)";
    if ($line !== '' && preg_match('/transfer|fee/i', $line)) $flag[] = "K=$line (fee text)";
    if ($phone !== '' && !preg_match('/^[\d\s\-+().eE]+$/', $phone) && !preg_match('/^\d{9}/', preg_replace('/\D/', '', $phone))) {
        if (!preg_match('/^0\d/', preg_replace('/\D/', '', $phone))) $flag[] = "J=$phone (not phone?)";
    }
    if ($phone !== '' && preg_match('/^\d{1,3}(\.0+)?$/', $phone)) $flag[] = "J=$phone (tiny num)";

    if ($flag || $filter !== '') {
        echo "$code R$rn name=$name | J=$phone | K=$line | M=$bed U=$park";
        if ($flag) echo " << " . implode('; ', $flag);
        echo "\n";
    }
}
