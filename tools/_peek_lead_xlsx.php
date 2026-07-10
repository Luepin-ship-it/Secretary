<?php
require_once dirname(__DIR__) . '/lib/xlsx_reader.php';

$path = $argv[1] ?? '';
if ($path === '' || !is_readable($path)) {
    fwrite(STDERR, "Usage: php tools/_peek_lead_xlsx.php <xlsx>\n");
    exit(1);
}

$r = new XlsxReader($path);
$sheet = $r->sheetNames()[0] ?? '';
$rows = $r->readSheet($sheet);
echo "Sheet: $sheet\n";
echo "Rows: " . count($rows) . "\n\n";

foreach ([1, 2, 3, 4, 5, 6, 7, 8] as $rn) {
    if (!isset($rows[$rn])) continue;
    echo "=== Row $rn ===\n";
    $cells = $rows[$rn];
    ksort($cells);
    foreach ($cells as $col => $val) {
        $v = trim((string)$val);
        if ($v !== '') echo "  $col: $v\n";
    }
    echo "\n";
}
