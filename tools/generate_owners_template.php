<?php
/**
 * สร้าง owners_import_template.xlsx
 * Usage: php tools/generate_owners_template.php [output-path]
 */
require_once dirname(__DIR__) . '/lib/xlsx_writer.php';

$out = $argv[1] ?? dirname(__DIR__) . '/owners_import_template.xlsx';

/** หัวตารางแถว 1 — ตรงกับ parser (คอลัมน์ A–AI) */
$headers = [
    'A'  => 'รหัส',
    'B'  => 'สถานะ',
    'C'  => 'วันที่ได้ listing',
    'D'  => 'สถานะการลงตลาด',
    'E'  => 'วันที่ลงการตลาด',
    'F'  => 'ประเภท',
    'G'  => 'Property Name (TH)',
    'H'  => 'Property Name (EN)',
    'I'  => "Owner's Name",
    'J'  => "Owner's Tel",
    'K'  => "Owner's LINE",
    'L'  => 'zone',
    'M'  => 'Bed',
    'N'  => 'Bath',
    'O'  => 'Unit no.',
    'P'  => 'Rai ไร่',
    'Q'  => 'Ngan งาน',
    'R'  => 'sq.w. ตร.ว.',
    'S'  => 'sq.m. ตร.ม.',
    'T'  => 'จำนวนชั้น',
    'U'  => 'Parking',
    'V'  => 'Corner Unit',
    'W'  => 'Direction',
    'X'  => 'Asking Price',
    'Y'  => 'Rental Price',
    'Z'  => 'Net',
    'AA' => 'Price Remark',
    'AB' => 'Photos',
    'AC' => 'Map',
    'AD' => 'ประวัติการติดต่อ',
    'AE' => 'วันที่ติดต่อล่าสุด',
    'AF' => 'Months On Sale',
    'AG' => 'Months to Sold',
    'AH' => 'Closing Project',
    'AI' => 'Closing Price',
];

/** แถวตัวอย่าง — รหัส DIT000 จะถูกข้ามตอน import อัตโนมัติ */
$example = [
    'A'  => 'DIT000',
    'B'  => 'Sale',
    'C'  => '2024-01-15',
    'D'  => 'ลงการตลาดแล้ว',
    'E'  => '2024-02-01',
    'F'  => 'Condo',
    'G'  => 'ชื่อโครงการภาษาไทย',
    'H'  => 'Project Name EN',
    'I'  => 'คุณเจ้าของ',
    'J'  => '0812345678',
    'K'  => '@lineid',
    'L'  => 'รัชดา',
    'M'  => '2',
    'N'  => '2',
    'O'  => '1234/56',
    'P'  => '0',
    'Q'  => '0',
    'R'  => '45',
    'S'  => '65',
    'T'  => '12',
    'U'  => '1',
    'V'  => 'No',
    'W'  => 'N',
    'X'  => '5200000',
    'Y'  => '25000',
    'Z'  => '5000000',
    'AA' => 'รวมค่าโอน',
    'AB' => 'Tan036',
    'AC' => 'https://maps.app.goo.gl/example',
    'AD' => 'โทรถามราคา — รอตอบกลับ',
    'AE' => '2025-06-01',
    'AF' => '6',
    'AG'  => '',
    'AH' => '',
    'AI' => '',
];

$importRows = [
    1 => $headers,
    2 => $example,
];

$guideHeader = [
    'A' => 'คอลัมน์',
    'B' => 'หัวตาราง',
    'C' => 'ฟิลด์ในระบบ',
    'D' => 'ใส่อะไร',
    'E' => 'หมายเหตุ',
];
$guide = [
    ['A', 'รหัส', 'code_list', 'TAN036, NING350', 'ข้ามแถวว่าง และรหัสขึ้นต้น DIT'],
    ['B', 'สถานะ', 'sales_status', 'Sale / sale&available (ขาย+เช่า) / Sold / Cancel / Rent', ''],
    ['C', 'วันที่ได้ listing', 'listing_date', '2024-01-15 หรือเลขวันที่ Excel', ''],
    ['D', 'สถานะการลงตลาด', 'marketing_status', 'ลงการตลาดแล้ว', ''],
    ['E', 'วันที่ลงการตลาด', 'marketing_date', '2024-02-01', ''],
    ['F', 'ประเภท', 'property_type', 'Condo / House / Townhome', ''],
    ['G', 'Property Name (TH)', 'project_name_th', 'ชื่อโครงการไทย', 'ไม่ใส่ชื่อเจ้าของที่นี่'],
    ['H', 'Property Name (EN)', 'project_name_en', 'ชื่อโครงการ EN', 'ไม่ใส่ชื่อเจ้าของที่นี่'],
    ['I', "Owner's Name", 'owner_name', 'คุณเจ้าของ', 'ชื่อเจ้าของต้องอยู่คอลัมน์ I เท่านั้น'],
    ['J', "Owner's Tel", 'phone', '0812345678', ''],
    ['K', "Owner's LINE", 'line_id', '@lineid', ''],
    ['L', 'zone', 'zone', 'รัชดา / ลาดพร้าว', ''],
    ['M', 'Bed', 'bed', '2', ''],
    ['N', 'Bath', 'bath', '2', ''],
    ['O', 'Unit no.', 'unit_no', '1234/56', ''],
    ['P', 'Rai ไร่', 'area_rai', '0', ''],
    ['Q', 'Ngan งาน', 'area_ngan', '0', ''],
    ['R', 'sq.w. ตร.ว.', 'area_sqwa', '45', ''],
    ['S', 'sq.m. ตร.ม.', 'area_sqm', '65', ''],
    ['T', 'จำนวนชั้น', 'floor', '12', ''],
    ['U', 'Parking', 'parking', '1', ''],
    ['V', 'Corner Unit', 'corner_unit', 'No', 'อ่านได้ ยังไม่แสดงบนการ์ด'],
    ['W', 'Direction', 'direction', 'N / NE', ''],
    ['X', 'Asking Price', 'asking_price', '5200000', ''],
    ['Y', 'Rental Price', 'rental_price', '25000', ''],
    ['Z', 'Net', 'net_price', '5000000', ''],
    ['AA', 'Price Remark', 'price_remark', 'รวมค่าโอน', ''],
    ['AB', 'Photos', 'photos_link', 'Tan036 หรือลิงก์ Drive', 'ไม่ใส่ลิงก์แผนที่ที่นี่'],
    ['AC', 'Map', 'map_url', 'https://maps.app.goo.gl/...', 'ไม่ใส่เบอร์โทรที่นี่'],
    ['AD', 'ประวัติการติดต่อ', 'contact_summary', 'สรุปการคุยล่าสุด', ''],
    ['AE', 'วันที่ติดต่อล่าสุด', 'last_contact_date', '2025-06-01', ''],
    ['AF', 'Months On Sale', 'months_on_sale', '6', ''],
    ['AG', 'Months to Sold', 'months_to_sold', '', ''],
    ['AH', 'Closing Project', 'closing_project', '', ''],
    ['AI', 'Closing Price', 'closing_price', '', ''],
];

$guideRows = [1 => $guideHeader];
$r = 2;
foreach ($guide as $row) {
    $guideRows[$r] = [
        'A' => $row[0],
        'B' => $row[1],
        'C' => $row[2],
        'D' => $row[3],
        'E' => $row[4],
    ];
    $r++;
}
$guideRows[$r++] = ['A' => '', 'B' => 'วิธีใช้', 'C' => '', 'D' => '', 'E' => ''];
$guideRows[$r++] = ['A' => '1', 'B' => 'คัดลอกข้อมูลจาก Sheet เดิม', 'C' => 'ใส่ให้ตรงคอลัมน์ — 1 แถว = 1 ทรัพย์', 'D' => '', 'E' => ''];
$guideRows[$r++] = ['A' => '2', 'B' => 'แถว DIT000', 'C' => 'เป็นตัวอย่าง — import จะข้ามอัตโนมัติ', 'D' => '', 'E' => 'ลบหรือทับได้'];
$guideRows[$r++] = ['A' => '3', 'B' => 'Import', 'C' => 'php tools/import_tan_xlsx.php ไฟล์นี้ --line-id=Uxxx', 'D' => '', 'E' => ''];
$guideRows[$r++] = ['A' => '4', 'B' => 'คอลัมน์ AJ เป็นต้นไป', 'C' => 'ไม่ถูก import', 'D' => '', 'E' => 'ใส่ได้แต่ระบบไม่อ่าน'];

$w = new XlsxWriter();
$w->addSheet('Import', $importRows);
$w->addSheet('คู่มือ', $guideRows);
$w->save($out);

echo "Created: $out\n";
echo "Sheets: Import (หัวตาราง + แถวตัวอย่าง DIT000), คู่มือ\n";
