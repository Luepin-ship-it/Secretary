<?php
require dirname(__DIR__) . '/lib/tan_workbook_import.php';
$data = TanWorkbookImport::load('c:\\Users\\ningp\\Downloads\\แก้.xlsx');
foreach ($data['owners'] as $code => $o) {
    if ($code === 'TAN618' || strpos($o['rental_price'] ?? '', '50') === 0) {
        echo "$code\n";
        foreach (['unit_no','direction','asking_price','rental_price','floor','area_sqm','selling_condition','price_remark'] as $k) {
            echo "  $k: " . ($o[$k] ?? '') . PHP_EOL;
        }
        echo PHP_EOL;
        if ($code === 'TAN618') break;
    }
}
