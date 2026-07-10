<?php
require_once dirname(__DIR__) . '/lib/lead_csv_import.php';
$path = $argv[1] ?? 'c:\\Users\\ningp\\Downloads\\lead - ชีต1.csv';
$needle = $argv[2] ?? 'ไข่มุก';
$d = LeadCsvImport::load($path);
foreach ($d['leads'] as $l) {
    if (str_contains($l['lead_name'], $needle) || str_contains($l['owner_code'], $needle)) {
        echo json_encode($l, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    }
}
