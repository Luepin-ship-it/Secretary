<?php
/**
 * สร้าง leads_import_template.xlsx (Lead Sheet v2 — คอลัมน์ A–AJ)
 * Usage: php tools/generate_leads_template.php [output-path]
 */
require_once dirname(__DIR__) . '/lib/xlsx_writer.php';
require_once dirname(__DIR__) . '/lib/lead_sheet_schema.php';

$out = $argv[1] ?? dirname(__DIR__) . '/leads_import_template.xlsx';

$headers = [];
$example = [];
foreach (lead_sheet_column_spec() as $col => $spec) {
    if (str_starts_with($spec['field'], '_pipe_')) {
        continue;
    }
    $headers[$col] = $spec['header'];
}

$example = [
    'A'  => '2025-06-01',
    'B'  => 'โครงการตัวอย่าง',
    'C'  => 'คุณสมชาย',
    'D'  => '081-234-5678',
    'E'  => 'TAN889',
    'F'  => 'ชาย',
    'G'  => 'Thai',
    'H'  => 'New Lead',
    'I'  => 'ยังไม่เจอ',
    'J'  => 'B',
    'K'  => 'Yes',
    'L'  => '',
    'M'  => '',
    'N'  => '',
    'O'  => '',
    'P'  => '',
    'Q'  => '',
    'R'  => '',
    'S'  => '15000000',
    'T'  => '',
    'U'  => '',
    'V'  => '3',
    'W'  => 'Facebook',
    'X'  => 'LINE OA',
    'Y'  => 'Buy',
    'Z'  => 'House',
    'AA' => 'A list',
    'AB' => '',
    'AC' => '',
    'AD' => '',
    'AE' => '35',
    'AF' => 'พนักงานบริษัท',
    'AG' => 'สุขุมวิท',
    'AH' => 'รถยนต์ส่วนตัว',
    'AI' => 'รามอินทรา',
    'AJ' => 'อยู่อาศัย',
];

$importRows = [1 => $headers, 2 => $example];

$guideHeader = ['A' => 'คอลัมน์', 'B' => 'หัวตาราง', 'C' => 'ฟิลด์', 'D' => 'ใส่อะไร', 'E' => 'หมายเหตุ'];
$guideRows = [1 => $guideHeader];
$r = 2;
foreach (lead_sheet_column_spec() as $col => $spec) {
    if (str_starts_with($spec['field'], '_pipe_')) {
        continue;
    }
    $guideRows[$r++] = [
        'A' => $col,
        'B' => $spec['header'],
        'C' => $spec['field'],
        'D' => '',
        'E' => $spec['note'],
    ];
}
$guideRows[$r++] = ['A' => 'K–R', 'B' => 'Pipeline', 'C' => '_pipe_*', 'D' => 'Yes / Lose / Reject / Hold', 'E' => 'Call → Follow → App. → Show → Nego → Close → Bank → Win'];
$guideRows[$r++] = ['A' => 'T', 'B' => 'Revenue', 'C' => 'revenue', 'D' => 'ว่างได้', 'E' => 'ระบบคำนวณ Budget×3%'];
$guideRows[$r++] = ['A' => '', 'B' => 'Import', 'C' => 'php tools/import_leads_xlsx.php ไฟล์ --user-id=8', 'D' => '', 'E' => 'หรือ --line-id=Uxxx'];

$w = new XlsxWriter();
$w->addSheet('Import', $importRows);
$w->addSheet('คู่มือ', $guideRows);
$w->save($out);

echo "Created: $out\n";
