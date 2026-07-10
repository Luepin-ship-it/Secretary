<?php
/**
 * Lead Sheet v2 — คอลัมน์ A–AJ (+ AK/AL สำรอง) ตามชีทงานขาย
 */
require_once __DIR__ . '/tan_workbook_import.php';

function lead_sheet_pipeline_columns(): array
{
    return [
        'K' => 'Call',
        'L' => 'Follow',
        'M' => 'Appointment',
        'N' => 'Show',
        'O' => 'Nego',
        'P' => 'Close',
        'Q' => 'Bank',
        'R' => 'Win',
    ];
}

/** คอลัมน์ชีทมาตรฐาน (ไม่มีคอลัมน์ * คั่น) */
function lead_sheet_column_spec(): array
{
    return [
        'A'  => ['header' => 'Date', 'field' => 'contact_date', 'note' => 'วันที่ลูกค้าเข้า'],
        'B'  => ['header' => 'Project Interest', 'field' => 'project', 'note' => 'โครงการที่สนใจ'],
        'C'  => ['header' => 'Name', 'field' => 'lead_name', 'note' => 'ชื่อลูกค้า'],
        'D'  => ['header' => 'Phone', 'field' => 'phone', 'note' => 'xxx-xxx-xxxx'],
        'E'  => ['header' => 'Listing Code', 'field' => 'owner_code', 'note' => 'รหัสทรัพย์ที่สนใจ'],
        'F'  => ['header' => 'Gender', 'field' => 'gender', 'note' => 'เพศ'],
        'G'  => ['header' => 'ชาติ', 'field' => 'nationality', 'note' => 'Thai / Foreign'],
        'H'  => ['header' => 'Status', 'field' => 'sheet_status', 'note' => 'Win | Pending | weekly follow | New Lead'],
        'I'  => ['header' => 'Pain Point', 'field' => 'pain_point_found', 'note' => 'เจอ Pain แล้วหรือยัง — ต้องมี Action ถ้ายัง'],
        'J'  => ['header' => 'Potential', 'field' => 'potential', 'note' => 'A / B / C'],
        'K'  => ['header' => 'Call', 'field' => '_pipe_Call', 'note' => 'Yes / Lose / Reject / Hold'],
        'L'  => ['header' => 'Follow', 'field' => '_pipe_Follow', 'note' => ''],
        'M'  => ['header' => 'App.', 'field' => '_pipe_Appointment', 'note' => ''],
        'N'  => ['header' => 'Show', 'field' => '_pipe_Show', 'note' => ''],
        'O'  => ['header' => 'Nego', 'field' => '_pipe_Nego', 'note' => ''],
        'P'  => ['header' => 'Close', 'field' => '_pipe_Close', 'note' => ''],
        'Q'  => ['header' => 'Bank', 'field' => '_pipe_Bank', 'note' => ''],
        'R'  => ['header' => 'Win', 'field' => '_pipe_Win', 'note' => 'Win = Yes เท่านั้น'],
        'S'  => ['header' => 'Budget', 'field' => 'budget', 'note' => 'งบที่ลูกค้าแจ้ง'],
        'T'  => ['header' => 'Revenue', 'field' => 'revenue', 'note' => 'คอมมิชชั่น — ว่างได้ ระบบคำนวณ Budget×3%'],
        'U'  => ['header' => 'Closing Date', 'field' => 'win_date', 'note' => 'วันปิดดีล (Win)'],
        'V'  => ['header' => 'Unit Sent', 'field' => 'units_sent', 'note' => 'เสนอไปกี่หลัง/ยูนิตแล้ว'],
        'V2' => ['header' => 'Offered Listings', 'field' => 'offered_listings', 'note' => 'โครงการ/รหัสทรัพย์ที่เสนอไปแล้ว'],
        'W'  => ['header' => 'Source', 'field' => 'source', 'note' => 'ช่องทางที่เห็นโครงการ'],
        'X'  => ['header' => 'Contact by', 'field' => 'contact_by', 'note' => 'ช่องทางติดต่อเข้ามา'],
        'Y'  => ['header' => 'Buy or Rent', 'field' => 'intent_buy_rent', 'note' => 'Buy / Rent'],
        'Z'  => ['header' => 'Unit Type', 'field' => 'unit_type', 'note' => 'ประเภททรัพย์'],
        'AA' => ['header' => 'Listing Type', 'field' => 'listing_type', 'note' => 'Exclusive | A list | Common list'],
        'AB' => ['header' => 'Agent', 'field' => 'is_agent', 'note' => 'ติ๊ก/ใช่ = เอเจนต์ ไม่ใช่ลูกค้าจริง'],
        'AC' => ['header' => 'ชื่อลูกค้า', 'field' => 'agent_client_name', 'note' => 'ลงทะเบียนลูกค้าเอเจนต์'],
        'AD' => ['header' => 'เบอร์ 4 ตัวท้าย', 'field' => 'agent_client_phone_last4', 'note' => ''],
        'AE' => ['header' => 'อายุ', 'field' => 'age', 'note' => ''],
        'AF' => ['header' => 'อาชีพ', 'field' => 'occupation', 'note' => ''],
        'AG' => ['header' => 'พื้นที่การทำงาน', 'field' => 'work_area', 'note' => ''],
        'AH' => ['header' => 'การเดินทาง', 'field' => 'commute', 'note' => ''],
        'AI' => ['header' => 'พื้นที่สนใจซื้อ', 'field' => 'interest_area', 'note' => ''],
        'AJ' => ['header' => 'จุดประสงค์การซื้อ', 'field' => 'purchase_purpose', 'note' => 'อยู่อาศัย / ลงทุน / ทั้งคู่'],
        'AK' => ['header' => 'Project Close', 'field' => 'close_project', 'note' => 'โครงการที่ปิด (อนาคต)'],
        'AL' => ['header' => 'Listing Code Close', 'field' => 'close_owner_code', 'note' => 'รหัสทรัพย์ที่ปิด (อนาคต)'],
    ];
}

function lead_sheet_ensure_schema($conn): void
{
    $cols = [
        "gender_enc TEXT DEFAULT NULL",
        "nationality_enc TEXT DEFAULT NULL",
        "sheet_status VARCHAR(40) DEFAULT NULL COMMENT 'Win|Pending|weekly follow|New Lead'",
        "pain_point_found VARCHAR(40) DEFAULT NULL COMMENT 'สถานะเจอ Pain หรือยัง'",
        "revenue_enc TEXT DEFAULT NULL COMMENT 'คอมมิชชั่น'",
        "units_sent INT UNSIGNED DEFAULT NULL",
        "offered_listings_enc TEXT DEFAULT NULL COMMENT 'รายการโครงการ/รหัสทรัพย์ที่เสนอไปแล้ว'",
        "source_enc TEXT DEFAULT NULL",
        "contact_by_enc TEXT DEFAULT NULL",
        "intent_buy_rent VARCHAR(20) DEFAULT NULL",
        "unit_type_enc TEXT DEFAULT NULL",
        "listing_type_enc VARCHAR(40) DEFAULT NULL",
        "is_agent TINYINT UNSIGNED NOT NULL DEFAULT 0",
        "agent_client_name_enc TEXT DEFAULT NULL",
        "agent_client_phone_last4_enc VARCHAR(20) DEFAULT NULL",
        "age_enc TEXT DEFAULT NULL",
        "work_area_enc TEXT DEFAULT NULL",
        "commute_enc TEXT DEFAULT NULL",
        "interest_area_enc TEXT DEFAULT NULL",
        "purchase_purpose_enc TEXT DEFAULT NULL",
        "close_project_enc TEXT DEFAULT NULL",
        "close_owner_code VARCHAR(50) DEFAULT NULL",
        "win_close_scope VARCHAR(10) DEFAULT 'this' COMMENT 'this=จบหลังนี้ other=หลังอื่น'",
        "close_open_price_enc TEXT DEFAULT NULL COMMENT 'ราคาเปิดทรัพย์ที่ปิด (หลังอื่น)'",
    ];
    foreach ($cols as $col_def) {
        $col_name = preg_match('/^(\w+)/', $col_def, $m) ? $m[1] : '';
        if ($col_name === '') continue;
        $chk = $conn->query("SHOW COLUMNS FROM leads LIKE '$col_name'");
        if ($chk && $chk->num_rows === 0) {
            $conn->query("ALTER TABLE leads ADD COLUMN $col_def");
        }
    }
}

function lead_sheet_normalize_header(string $raw): string
{
    $s = strtolower(trim(preg_replace('/\s+/', ' ', str_replace(["\n", "\r"], ' ', $raw))));
    $s = str_replace(['(ใส่ตัวเลข)', '*'], '', $s);
    return trim($s);
}

/** แปลง Status ชีท → สถานะ CRM */
function lead_sheet_status_to_crm(string $raw): string
{
    $s = strtolower(trim($raw));
    if ($s === '') return 'Call';
    $map = [
        'win' => 'Win',
        'pending' => 'Follow',
        'weekly follow' => 'Follow',
        'weeklyfollow' => 'Follow',
        'new lead' => 'Call',
        'newlead' => 'Call',
        'found' => 'Follow',
    ];
    foreach ($map as $k => $v) {
        if ($s === $k || str_contains($s, $k)) return $v;
    }
    return TanWorkbookImport::normalizeLeadStatus($raw);
}

function lead_sheet_parse_outcome(string $raw): ?string
{
    $s = strtolower(trim($raw));
    if ($s === '' || $s === '—' || $s === '-') return null;
    if (in_array($s, ['yes', 'y', 'win', 'ผ่าน'], true)) return 'yes';
    if (in_array($s, ['lose', 'lost', 'หลุด'], true)) return 'lose';
    if (in_array($s, ['reject', 'rejected', 'ปฏิเสธ'], true)) return 'reject';
    if (in_array($s, ['hold', 'pending'], true)) return 'hold';
    if (str_contains($s, 'lose')) return 'lose';
    if (str_contains($s, 'reject')) return 'reject';
    return null;
}

function lead_sheet_truthy_agent(string $raw): int
{
    $s = strtolower(trim($raw));
    if ($s === '') return 0;
    return in_array($s, ['1', 'yes', 'y', 'true', 'agent', 'ใช่', 'x', '✓', 'check'], true) ? 1 : 0;
}

function lead_sheet_compute_revenue(string $budget, string $revenue): string
{
    $rev = preg_replace('/[^\d.]/', '', $revenue);
    if ($rev !== '' && is_numeric($rev) && (float)$rev > 0) {
        return (string)(int)round((float)$rev);
    }
    $b = preg_replace('/[^\d.]/', '', $budget);
    if ($b !== '' && is_numeric($b) && (float)$b >= 100000) {
        return (string)(int)round((float)$b * 0.03);
    }
    return '';
}

/** สร้าง stage events จาก Status ชีท เมื่อไม่มี Yes/Lose ในแต่ละขั้น */
function lead_sheet_synthesize_stage_events(string $sheetStatus, string $contactDate): array
{
    $s = strtolower(trim($sheetStatus));
    if ($s === '') {
        return [];
    }
    $events = [];
    $add = function (string $stage, string $outcome = 'yes') use (&$events, $contactDate) {
        $events[] = ['stage' => $stage, 'outcome' => $outcome, 'event_date' => $contactDate];
    };
    if ($s === 'win') {
        foreach (['Call', 'Follow', 'Appointment', 'Show', 'Nego', 'Close', 'Bank', 'Win'] as $st) {
            $add($st);
        }
    } elseif (str_contains($s, 'weekly')) {
        $add('Call');
        $add('Follow');
    } elseif ($s === 'pending') {
        foreach (['Call', 'Follow', 'Appointment'] as $st) {
            $add($st);
        }
    } elseif ($s === 'found') {
        $add('Call');
    } elseif ($s === 'reject') {
        $add('Call', 'reject');
    } elseif ($s === 'lose') {
        $add('Close', 'lose');
    } elseif ($s === 'new lead' || str_contains($s, 'newlead')) {
        return [];
    }
    return $events;
}
