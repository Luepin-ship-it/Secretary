<?php
require __DIR__ . '/../lib/xlsx_reader.php';
require __DIR__ . '/../lib/tan_workbook_import.php';

$path = $argv[1] ?? '';
$reader = new XlsxReader($path);
$rows = $reader->readSheet('ชีต1');
if (!TanWorkbookImport::isCleanLeadsFormat($rows)) {
    foreach ($reader->sheetNames() as $sn) {
        $r = $reader->readSheet($sn);
        if (TanWorkbookImport::isCleanLeadsFormat($r)) {
            $rows = $r;
            echo "Using sheet: $sn\n";
            break;
        }
    }
}

$leads = TanWorkbookImport::parseCleanLeadsSheet($rows, [
    'default_status' => $argv[2] ?? 'Win',
    'lead_code_prefix' => $argv[3] ?? 'WIN',
]);
echo "Parsed: " . count($leads) . " leads\n\n";
foreach ($leads as $code => $l) {
    echo "$code | {$l['lead_name']} | owner={$l['owner_code']} | phone={$l['phone']} | line={$l['line_id']} | grade={$l['potential']} | status={$l['status']} | win={$l['win_price']} | budget={$l['budget']}\n";
}
