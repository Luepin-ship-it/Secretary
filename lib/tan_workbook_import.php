<?php
require_once __DIR__ . '/xlsx_reader.php';
require_once __DIR__ . '/owner_field_normalize.php';
require_once __DIR__ . '/contact_normalize.php';
require_once __DIR__ . '/lead_code.php';

/**
 * Parsers for "Tan New List.xlsx" workbook (Tan / Owner Focus / Tan UCP).
 */
class TanWorkbookImport
{
    public const CODE_RE = '/^(TAN|NING|DIT|AMPK|FEW)(\d{1,4})(\s*\([^)]+\))?$/i';

    /** รหัสทรัพย์/Listing (Lead รองรับ PINP, NAME, TPM ด้วย) */
    public const LISTING_CODE_RE = '/^(TAN|NING|DIT|AMPK|FEW|PINP|NAME|TPM)(\d{1,4})(\s*\([^)]+\))?$/i';

    public static function normalizeCode(string $raw): string
    {
        return self::normalizeListingCode($raw);
    }

    public static function normalizeListingCode(string $raw): string
    {
        $raw = trim($raw);
        if (preg_match(self::LISTING_CODE_RE, $raw, $m)) {
            return strtoupper($m[1]) . $m[2];
        }
        return '';
    }

    public static function excelDate(?string $serial): ?string
    {
        if ($serial === null || $serial === '' || !is_numeric($serial)) {
            return null;
        }
        $n = (float)$serial;
        if ($n < 30000 || $n > 60000) {
            return null;
        }
        $ts = (int)round(($n - 25569) * 86400);
        return gmdate('Y-m-d', $ts);
    }

    public static function looksLikePhone(string $v): bool
    {
        $d = preg_replace('/\D/', '', $v);
        return strlen($d) >= 9 && strlen($d) <= 12;
    }

    public static function looksLikeUrl(string $v): bool
    {
        return (bool)preg_match('#^https?://#i', trim($v));
    }

    public static function pickPhone(array $cells, array $cols): string
    {
        foreach ($cols as $c) {
            $v = trim($cells[$c] ?? '');
            if ($v !== '' && self::looksLikePhone($v)) {
                return $v;
            }
        }
        return '';
    }

    public static function pickName(array $cells, array $cols): string
    {
        foreach ($cols as $c) {
            $v = trim($cells[$c] ?? '');
            if ($v === '' || self::looksLikeUrl($v) || self::looksLikePhone($v)) {
                continue;
            }
            if (preg_match(self::CODE_RE, $v)) {
                continue;
            }
            if (preg_match('/^[\d.]+$/', $v)) {
                continue;
            }
            if (is_numeric($v) && (float)$v > 1000) {
                continue;
            }
            return $v;
        }
        return '';
    }

    /** Columns where listing codes appear (Tan sheet uses merged / multi-row layout). */
    private const OWNER_CODE_COLS = ['G', 'H', 'D', 'AR', 'AS', 'A', 'J', 'AK', 'T', 'AO'];

    public static function extractRowCode(array $cells): string
    {
        $found = [];
        foreach (self::OWNER_CODE_COLS as $col) {
            $code = self::normalizeCode($cells[$col] ?? '');
            if ($code !== '') {
                $found[$col] = $code;
            }
        }
        if (!$found) {
            return '';
        }
        $codes = array_unique(array_values($found));
        if (count($codes) === 1) {
            return $codes[0];
        }
        // NING350 is a dashboard filter artifact — prefer real TAN/DIT/AMPK codes on the same row.
        $real = array_values(array_filter($codes, fn($c) => $c !== 'NING350'));
        if (count($real) === 1) {
            return $real[0];
        }
        if (count($real) > 1) {
            foreach (self::OWNER_CODE_COLS as $col) {
                if (isset($found[$col]) && in_array($found[$col], $real, true)) {
                    return $found[$col];
                }
            }
        }
        if (in_array('NING350', $codes, true) && count($codes) > 1) {
            return '';
        }
        return $codes[0];
    }

    private static function rowRichness(array $cells): int
    {
        $score = 0;
        foreach ($cells as $v) {
            $v = trim($v);
            if ($v !== '' && $v !== '0' && !self::looksLikeUrl($v)) {
                $score++;
            }
        }
        return $score;
    }

    /** @return array<string, array> code_list => owner fields */
    public static function parseOwnersSheet(array $rows): array
    {
        $owners = [];
        foreach ($rows as $rowNum => $cells) {
            if ($rowNum < 4) {
                continue;
            }
            $code = self::extractRowCode($cells);
            if ($code === '' || $code === 'NING350') {
                continue;
            }
            $g = trim($cells['G'] ?? '');
            $salesStatus = 'Sale';
            $availability = 'ยังขายอยู่';
            $mapUrl = '';
            $incomplete = '';

            if (self::looksLikeUrl($g)) {
                $mapUrl = $g;
            } elseif (stripos($g, 'sold') !== false) {
                $salesStatus = 'sold';
                $availability = 'ขายได้แล้ว';
            } elseif (stripos($g, 'cancel') !== false) {
                $salesStatus = 'cancel';
                $availability = 'ยกเลิกการขาย';
            } elseif (stripos($g, 'sale with tenant') !== false) {
                $salesStatus = 'sale with tenant';
            } elseif (stripos($g, 'sale') !== false) {
                $salesStatus = 'Sale';
            } elseif ($g !== '' && !self::excelDate($g)) {
                $incomplete = $g;
            }

            $ownerName = self::pickName($cells, ['K', 'L', 'O', 'M']);
            if ($ownerName === '') {
                $ownerName = 'เจ้าของ ' . $code;
            }

            $phone = self::pickPhone($cells, ['M', 'O', 'L', 'K', 'T']);
            $lineId = trim($cells['N'] ?? '');
            if ($lineId === '' || self::looksLikePhone($lineId)) {
                $alt = trim($cells['T'] ?? '');
                if ($alt !== '' && !self::looksLikePhone($alt) && !is_numeric($alt)) {
                    $lineId = $alt;
                }
            }

            $asking = trim($cells['AE'] ?? $cells['AI'] ?? $cells['AH'] ?? '');
            if ($asking !== '' && is_numeric($asking)) {
                $asking = (string)(int)round((float)$asking);
            }

            $project = trim($cells['J'] ?? '');
            if ($project === '' || self::normalizeCode($project) !== '' || self::looksLikePhone($project)) {
                $project = trim($cells['I'] ?? '');
            }
            if ($project === '' || self::normalizeCode($project) !== '' || self::looksLikePhone($project)) {
                $project = '';
            }

            $record = [
                'code_list' => $code,
                'owner_name' => $ownerName,
                'project' => $project,
                'listing_date' => self::excelDate($cells['C'] ?? '') ?? date('Y-m-d'),
                'property_type' => trim($cells['H'] ?? ''),
                'phone' => $phone,
                'line_id' => $lineId,
                'zone' => self::pickName($cells, ['O', 'P']) && !self::looksLikePhone($cells['O'] ?? '') ? trim($cells['O'] ?? '') : '',
                'location_grade' => self::numericOrText($cells['P'] ?? $cells['S'] ?? ''),
                'bts_mrt_srt' => trim($cells['Q'] ?? $cells['AC'] ?? ''),
                'bed' => self::numericOrText($cells['S'] ?? $cells['T'] ?? ''),
                'bath' => self::numericOrText($cells['U'] ?? ''),
                'unit_no' => trim($cells['V'] ?? $cells['AG'] ?? $cells['AJ'] ?? ''),
                'area_sqwa' => self::numericOrText($cells['X'] ?? $cells['Y'] ?? ''),
                'area_sqm' => self::numericOrText($cells['Z'] ?? $cells['AE'] ?? ''),
                'floor' => self::numericOrText($cells['AA'] ?? ''),
                'parking' => trim($cells['AC'] ?? ''),
                'direction' => trim($cells['AG'] ?? $cells['AK'] ?? ''),
                'asking_price' => $asking,
                'rental_price' => self::numericOrText($cells['AJ'] ?? ''),
                'selling_condition' => trim($cells['AO'] ?? $cells['AM'] ?? '50/50 Transfer Fee'),
                'map_url' => $mapUrl !== '' ? $mapUrl : (self::looksLikeUrl($cells['B'] ?? '') ? $cells['B'] : ''),
                'availability_status' => $availability,
                'sales_status' => $salesStatus,
                'incomplete_details' => $incomplete,
                '_source_row' => $rowNum,
                '_richness' => self::rowRichness($cells),
            ];

            if (!isset($owners[$code]) || $record['_richness'] >= ($owners[$code]['_richness'] ?? 0)) {
                $owners[$code] = $record;
            }
        }
        foreach ($owners as &$o) {
            unset($o['_richness']);
        }
        unset($o);
        return $owners;
    }

    /** @return array<string, string> code => A|B|C */
    public static function parseOwnerFocusSheet(array $rows): array
    {
        $focus = [];
        foreach ($rows as $rowNum => $cells) {
            if ($rowNum < 2) {
                continue;
            }
            $grade = strtoupper(trim($cells['G'] ?? ''));
            if (!in_array($grade, ['A', 'B', 'C'], true)) {
                $g = trim($cells['G'] ?? '');
                if ($g === '25k' || stripos($g, 'คุณ') === 0) {
                    $grade = 'B';
                } else {
                    continue;
                }
            }
            foreach (['B', 'C', 'D', 'H'] as $col) {
                $code = self::normalizeCode($cells[$col] ?? '');
                if ($code !== '') {
                    $focus[$code] = $grade;
                }
            }
        }
        return $focus;
    }

    /** @return array<string, array> lead_code => lead fields */
    public static function parseLeadsSheet(array $rows): array
    {
        $leads = [];
        foreach ($rows as $rowNum => $cells) {
            if ($rowNum < 7) {
                continue;
            }
            if (self::rowIsFilter($cells)) {
                continue;
            }

            $marker = trim($cells['F'] ?? '');
            if ($marker === 'End :' || $marker === '') {
                // pivot-style sub-rows inside Tan UCP blocks
                if (!self::looksLikePhone($cells['E'] ?? '') && self::normalizeCode($cells['E'] ?? '') === '') {
                    continue;
                }
            }
            $statusRaw = trim($cells['H'] ?? '');
            $budget = trim($cells['V'] ?? $cells['P'] ?? '');
            if ($budget !== '' && is_numeric($budget)) {
                $bf = (float)$budget;
                if ($bf > 500000000) {
                    $budget = '';
                } else {
                    $budget = (string)(int)round($bf);
                }
            } elseif (!is_numeric(str_replace(['.', ','], '', $budget))) {
                $budget = '';
            }

            $potential = strtoupper(trim($cells['U'] ?? 'B'));
            if (!in_array($potential, ['A', 'B', 'C'], true)) {
                $potential = 'B';
            }

            $ownerCode = self::normalizeCode($cells['E'] ?? '');
            $leadCode = self::normalizeCode($cells['A'] ?? '');
            if ($leadCode === '') {
                $leadCode = self::normalizeCode($cells['E'] ?? '');
            }
            if ($leadCode === '' && self::looksLikePhone($cells['E'] ?? '')) {
                $digits = preg_replace('/\D/', '', $cells['E']);
                $leadCode = 'UCP-' . substr($digits, -8);
            }
            if ($leadCode === '') {
                $leadCode = 'UCP-R' . $rowNum;
            }

            $leadName = self::pickName($cells, ['B', 'D', 'C']);
            if ($leadName === '' || $leadName === '0' || preg_match('/^[\d.]+$/', $leadName)) {
                continue;
            }

            $phone = self::pickPhone($cells, ['E', 'B', 'D']);
            $project = trim($cells['C'] ?? '');
            if ($project === 'Listing Code' || $project === 'Phone' || $project === 'Budget') {
                $project = '';
            }

            $status = self::mapLeadStatus($marker, $statusRaw, trim($cells['P'] ?? ''));

            $leads[$leadCode] = [
                'lead_code' => $leadCode,
                'lead_name' => $leadName,
                'project' => $project,
                'phone' => $phone,
                'budget' => $budget,
                'potential' => $potential,
                'contact_date' => self::excelDate($cells['A'] ?? '') ?? date('Y-m-d'),
                'status' => $status,
                'owner_code' => $ownerCode,
                'pain_point' => trim($cells['Q'] ?? $cells['T'] ?? ''),
                'current_update' => trim($cells['W'] ?? $cells['B'] ?? ''),
                '_source_row' => $rowNum,
            ];
        }
        return $leads;
    }

    private static function rowIsFilter(array $cells): bool
    {
        foreach ($cells as $v) {
            if (strpos($v, 'Lead Insight') !== false || strpos($v, 'Filter') !== false) {
                return true;
            }
        }
        $c = trim($cells['C'] ?? '');
        if (in_array($c, ['Conver.', 'C %', 'C Com.', 'Action Score (Filter)', 'Pain Point %'], true)) {
            return true;
        }
        return false;
    }

    private static function mapLeadStatus(string $marker, string $h, string $p): string
    {
        $check = strtolower($marker . ' ' . $h . ' ' . $p);
        if (strpos($check, 'win') !== false) {
            return 'Win';
        }
        if (strpos($check, 'reject') !== false || strpos($check, 'lose') !== false) {
            return 'Rejected';
        }
        if (strpos($check, 'follow') !== false || strpos($check, 'scanning') !== false) {
            return 'Follow';
        }
        if (strpos($check, 'show') !== false) {
            return 'Show';
        }
        if (strpos($check, 'nego') !== false) {
            return 'Nego';
        }
        if (strpos($check, 'appoint') !== false) {
            return 'Appointment';
        }
        if (strpos($check, 'call') !== false || strpos($check, 'start') !== false) {
            return 'Call';
        }
        return 'Call';
    }

    private static function numericOrText(string $v): string
    {
        $v = trim($v);
        if ($v === '') {
            return '';
        }
        if (is_numeric($v)) {
            $f = (float)$v;
            if ($f == (int)$f) {
                return (string)(int)$f;
            }
            return rtrim(rtrim(sprintf('%.2f', $f), '0'), '.');
        }
        return $v;
    }

    public static function isCleanOwnersFormat(array $rows): bool
    {
        return trim($rows[1]['A'] ?? '') === 'รหัส';
    }

    public static function isCleanLeadsFormat(array $rows): bool
    {
        $a = trim($rows[1]['A'] ?? '');
        return $a === 'รหัส Lead' || $a === 'Lead Code';
    }

    /** สถานะ pipeline จาก Excel → ENUM ใน DB */
    public static function normalizeLeadStatus(string $raw): string
    {
        $s = strtolower(trim(str_replace([' ', '-'], ['', '_'], $raw)));
        if ($s === '') {
            return 'Call';
        }
        $map = [
            'call' => 'Call',
            'start' => 'Call',
            'follow' => 'Follow',
            'scanning' => 'Follow',
            'appoint' => 'Appointment',
            'appointment' => 'Appointment',
            'app' => 'Appointment',
            'show' => 'Show',
            'showing' => 'Show',
            'nego' => 'Nego',
            'negotiation' => 'Nego',
            'close' => 'Close',
            'bank' => 'Bank',
            'win' => 'Win',
            'lose' => 'Rejected',
            'lost' => 'Rejected',
            'reject' => 'Rejected',
            'rejected' => 'Rejected',
            'hold' => 'Hold_Reject',
            'hold_reject' => 'Hold_Reject',
        ];
        if (isset($map[$s])) {
            return $map[$s];
        }
        if (strpos($s, 'win') !== false) {
            return 'Win';
        }
        if (strpos($s, 'reject') !== false || strpos($s, 'lose') !== false) {
            return 'Rejected';
        }
        if (strpos($s, 'follow') !== false) {
            return 'Follow';
        }
        if (strpos($s, 'appoint') !== false) {
            return 'Appointment';
        }
        if (strpos($s, 'show') !== false) {
            return 'Show';
        }
        if (strpos($s, 'nego') !== false) {
            return 'Nego';
        }
        if (strpos($s, 'close') !== false) {
            return 'Close';
        }
        if (strpos($s, 'bank') !== false) {
            return 'Bank';
        }
        foreach (['Call', 'Follow', 'Appointment', 'Show', 'Nego', 'Close', 'Bank', 'Win', 'Rejected', 'Hold_Reject'] as $valid) {
            if (strcasecmp($raw, $valid) === 0) {
                return $valid;
            }
        }
        return 'Call';
    }

    /**
     * ชีต Lead แบบ clean — หัวแถว 1 ตรงคอลัมน์ A–T
     * @param array $opts default_status, lead_code_prefix
     * @return array<string, array>
     */
    public static function parseCleanLeadsSheet(array $rows, array $opts = []): array
    {
        $leads = [];
        foreach ($rows as $rowNum => $cells) {
            if ($rowNum < 2) {
                continue;
            }

            $name = trim(self::cell($cells, 'B'));
            if ($name === '') {
                continue;
            }

            $codeRaw = trim(self::cell($cells, 'A'));
            if (strtoupper($codeRaw) === 'DIT000') {
                continue;
            }

            $ownerCode = self::resolveLeadOwnerCode($cells);
            $hRaw = self::cell($cells, 'H');
            $potential = self::parseLeadGrade($hRaw);
            $winPrice = self::parseLeadWinPrice($cells, $hRaw);
            $budget = self::cell($cells, 'O');
            if ($budget !== '' && is_numeric(str_replace(',', '', $budget))) {
                $budget = (string)(int)round((float)str_replace(',', '', $budget));
            } else {
                $budget = $winPrice !== '' ? $winPrice : '';
            }

            $statusRaw = trim(self::cell($cells, 'I'));
            if ($statusRaw !== '') {
                $status = self::normalizeLeadStatus($statusRaw);
            } elseif (!empty($opts['default_status'])) {
                $status = self::normalizeLeadStatus((string)$opts['default_status']);
            } else {
                $status = 'Call';
            }

            $contact = self::cellExcelDate($cells, 'G') ?? date('Y-m-d');
            $nextDate = self::cellExcelDate($cells, 'Q');
            $winDate = self::cellExcelDate($cells, 'S');
            if ($winDate === null && $status === 'Win') {
                $winDate = $contact;
            }

            $phoneRaw = self::cell($cells, 'C');
            $lineRaw = self::cell($cells, 'D');
            if ($ownerCode !== '' && self::normalizeListingCode($lineRaw) === $ownerCode) {
                $lineRaw = '';
            }
            $contacts = repair_owner_contacts([
                'phone'   => $phoneRaw,
                'line_id' => $lineRaw,
            ]);

            $code = self::makeLeadCode($cells, $rowNum, $ownerCode, $opts);

            $leads[$code] = [
                'lead_code'         => $code,
                'lead_name'         => $name,
                'phone'             => $contacts['phone'],
                'line_id'           => $contacts['line_id'],
                'owner_code'        => $ownerCode,
                'project'           => self::cell($cells, 'F'),
                'contact_date'      => $contact,
                'potential'         => $potential,
                'status'            => $status,
                'background'        => self::cell($cells, 'J'),
                'pain_point'        => self::cell($cells, 'K'),
                'requirement'       => self::cell($cells, 'L'),
                'financials'        => self::cell($cells, 'M'),
                'target_date'       => self::cell($cells, 'N'),
                'budget'            => $budget,
                'next_plan_action'  => self::cell($cells, 'P'),
                'next_plan_date'    => $nextDate,
                'current_update'    => self::cell($cells, 'R'),
                'win_date'          => $winDate,
                'win_price'         => $winPrice !== '' ? $winPrice : $budget,
                '_source_row'       => $rowNum,
            ];
        }
        return $leads;
    }

    private static function resolveLeadOwnerCode(array $cells): string
    {
        $fromE = self::normalizeListingCode(self::cell($cells, 'E'));
        if ($fromE !== '') {
            return $fromE;
        }
        return self::normalizeListingCode(self::cell($cells, 'D'));
    }

    private static function parseLeadGrade(string $h): string
    {
        $g = strtoupper(trim($h));
        return in_array($g, ['A', 'B', 'C'], true) ? $g : 'B';
    }

    private static function parseLeadWinPrice(array $cells, string $hRaw): string
    {
        foreach (['T', 'O'] as $col) {
            $v = trim(self::cell($cells, $col));
            if ($v === '' || !is_numeric(str_replace(',', '', $v))) {
                continue;
            }
            $n = (float)str_replace(',', '', $v);
            if ($n >= 100000) {
                return (string)(int)round($n);
            }
        }
        $h = trim($hRaw);
        if ($h !== '' && is_numeric(str_replace(',', '', $h))) {
            $g = strtoupper($h);
            if (!in_array($g, ['A', 'B', 'C'], true)) {
                $n = (float)str_replace(',', '', $h);
                if ($n >= 100000) {
                    return (string)(int)round($n);
                }
            }
        }
        return '';
    }

    private static function makeLeadCode(array $cells, int $rowNum, string $ownerCode, array $opts): string
    {
        $a = trim(self::cell($cells, 'A'));
        if ($a !== '' && strtoupper($a) !== 'DIT000') {
            return lead_code_for_display($a);
        }
        $prefix = $opts['lead_code_prefix'] ?? 'LEAD';
        $code = lead_import_make_code($ownerCode, self::cell($cells, 'C'));
        if ($prefix !== 'LEAD') {
            $code = preg_replace('/^LEAD-/', $prefix . '-', $code, 1);
        }
        return $code;
    }

    /** ค่าในช่องตามที่อ่านจาก Excel — ไม่เดา ไม่สกัด */
    private static function cell(array $cells, string $col): string
    {
        return trim((string)($cells[$col] ?? ''));
    }

    /** แปลงเฉพาะเลข serial วันที่ของ Excel → Y-m-d */
    public static function cellExcelDate(array $cells, string $col): ?string
    {
        $v = self::cell($cells, $col);
        if ($v === '' || !is_numeric($v)) {
            return null;
        }
        return self::excelDate($v);
    }

    /** ข้ามแถว: ไม่มีรหัส หรือรหัส DIT (ไม่ใช่ทรัพย์ owner) */
    public static function shouldSkipImportRow(string $code): bool
    {
        if ($code === '') {
            return true;
        }
        return (bool)preg_match('/^DIT/i', $code);
    }

    /**
     * อ่านตามหัวตารางแถว 1 ตรงคอลัมน์ → ฟิลด์ dashboard
     * ไม่สแกนคอลัมน์อื่น ไม่เดาชื่อ/โครงการ
     */
    public static function parseCleanOwnersSheet(array $rows): array
    {
        $owners = [];
        foreach ($rows as $rowNum => $cells) {
            if ($rowNum < 2) {
                continue;
            }

            $code = self::cell($cells, 'A');
            if (self::shouldSkipImportRow($code)) {
                continue;
            }

            $owners[$code] = [
                'code_list'           => $code,
                'sales_status'        => self::cell($cells, 'B'),
                'listing_date'        => self::cellExcelDate($cells, 'C') ?? date('Y-m-d'),
                'marketing_status'    => self::cell($cells, 'D'),
                'marketing_date'      => self::cellExcelDate($cells, 'E'),
                'property_type'       => self::cell($cells, 'F'),
                'project_name_th'     => self::cell($cells, 'G'),
                'project_name_en'     => self::cell($cells, 'H'),
                'owner_name'          => self::cell($cells, 'I'),
                'phone'               => self::cell($cells, 'J'),
                'line_id'             => self::cell($cells, 'K'),
                'zone'                => self::cell($cells, 'L'),
                'bed'                 => self::cell($cells, 'M'),
                'bath'                => self::cell($cells, 'N'),
                'unit_no'             => self::cell($cells, 'O'),
                'area_rai'            => self::cell($cells, 'P'),
                'area_ngan'           => self::cell($cells, 'Q'),
                'area_sqwa'           => self::cell($cells, 'R'),
                'area_sqm'            => self::cell($cells, 'S'),
                'floor'               => self::cell($cells, 'T'),
                'parking'             => self::cell($cells, 'U'),
                'corner_unit'         => self::cell($cells, 'V'),
                'direction'           => self::cell($cells, 'W'),
                'asking_price'        => self::cell($cells, 'X'),
                'rental_price'        => self::cell($cells, 'Y'),
                'net_price'           => self::cell($cells, 'Z'),
                'price_remark'        => self::cell($cells, 'AA'),
                'photos_link'         => self::cell($cells, 'AB'),
                'map_url'             => self::cell($cells, 'AC'),
                'contact_summary'     => self::cell($cells, 'AD'),
                'last_contact_date'   => self::cellExcelDate($cells, 'AE'),
                'months_on_sale'      => self::cell($cells, 'AF'),
                'months_to_sold'      => self::cell($cells, 'AG'),
                'closing_project'     => self::cell($cells, 'AH'),
                'closing_price'       => self::cell($cells, 'AI'),
                'availability_status' => 'ยังขายอยู่',
                'selling_condition'   => '50/50 Transfer Fee',
                '_source_row'         => $rowNum,
            ];
            $owners[$code] = repair_shifted_owner_fields($owners[$code]);
            $contacts = repair_owner_contacts($owners[$code]);
            $owners[$code]['phone'] = $contacts['phone'];
            $owners[$code]['line_id'] = $contacts['line_id'];
        }
        return $owners;
    }

    public static function load(string $xlsxPath, array $opts = []): array
    {
        $reader = new XlsxReader($xlsxPath);
        $sheets = $reader->sheetNames();

        $owners = [];
        $leads = [];
        $ownerSheet = null;
        $leadSheet = null;
        $leadOpts = $opts['leads'] ?? [];

        foreach ($sheets as $sheetName) {
            $rows = $reader->readSheet($sheetName);
            if (self::isCleanOwnersFormat($rows)) {
                $owners = self::parseCleanOwnersSheet($rows);
                $ownerSheet = $sheetName;
            }
            if (self::isCleanLeadsFormat($rows)) {
                $leads = self::parseCleanLeadsSheet($rows, $leadOpts);
                $leadSheet = $sheetName;
            }
        }

        if ($owners !== [] || $leads !== []) {
            $format = 'clean_workbook';
            if ($owners !== [] && $leads === []) {
                $format = 'clean_owners';
            } elseif ($leads !== [] && $owners === []) {
                $format = 'clean_leads';
            }
            return [
                'owners' => $owners,
                'leads' => $leads,
                'focus_matched' => 0,
                'focus_total' => 0,
                'sheets' => $sheets,
                'format' => $format,
                'sheet' => $ownerSheet ?? $leadSheet,
                'owner_sheet' => $ownerSheet,
                'lead_sheet' => $leadSheet,
            ];
        }

        if (!in_array('Tan', $sheets, true)) {
            return [
                'owners' => [],
                'leads' => [],
                'focus_matched' => 0,
                'focus_total' => 0,
                'sheets' => $sheets,
                'format' => 'unknown',
            ];
        }

        $ownerRows = $reader->readSheet('Tan');
        $focusRows = $reader->readSheet('Owner Focus (OF)');
        $leadRows = $reader->readSheet('Tan UCP');

        $owners = self::parseOwnersSheet($ownerRows);
        $focus = self::parseOwnerFocusSheet($focusRows);
        foreach ($focus as $code => $grade) {
            if (isset($owners[$code])) {
                $owners[$code]['owner_urgency'] = $grade;
            }
        }

        $leads = self::parseLeadsSheet($leadRows);

        return [
            'owners' => $owners,
            'leads' => $leads,
            'focus_matched' => count(array_intersect_key($focus, $owners)),
            'focus_total' => count($focus),
            'sheets' => $sheets,
            'format' => 'tan_workbook',
        ];
    }
}
