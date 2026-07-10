<?php
require_once dirname(__DIR__) . '/lib/xlsx_reader.php';
require_once dirname(__DIR__) . '/lib/tan_workbook_import.php';
$path = $argv[1] ?? 'c:/Users/ningp/Downloads/Tan New List.xlsx';
$r = new XlsxReader($path);
$rows = $r->readSheet('Tan');
$ning = 0; $tan = 0;
foreach ($rows as $n => $cells) {
    if ($n < 4) continue;
    foreach ($cells as $v) {
        $cd = TanWorkbookImport::normalizeCode($v);
        if ($cd === 'NING350') $ning++;
        if ($cd && preg_match('/^TAN\d+$/', $cd)) $tan++;
    }
}
echo "NING350 cell hits: $ning\nTAN* cell hits: $tan\n";
