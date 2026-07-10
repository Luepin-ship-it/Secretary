<?php
/**
 * Import Lead Sheet จาก CSV — อ่านตามหัวคอลัมน์ตรงๆ (ไม่เดา heuristic)
 */
require_once __DIR__ . '/lead_sheet_schema.php';
require_once __DIR__ . '/lead_code.php';
require_once __DIR__ . '/contact_normalize.php';
require_once __DIR__ . '/tan_workbook_import.php';
require_once dirname(__DIR__) . '/task_helpers.php';

class LeadCsvImport
{
    /** @var array<string, int> */
    private array $col = [];
    /** @var array<string, int> stage => column index */
    private array $pipeCol = [];

    /** ลบ single quote ทุกตัวในเซลล์ */
    public static function stripQuotes(string $value): string
    {
        return str_replace("'", '', $value);
    }

    public static function sanitizeCell(?string $value): string
    {
        return self::stripQuotes(trim((string)$value));
    }

    /**
     * @return array{leads: array<string, array>, headers: string[], row_count: int}
     */
    public static function load(string $path): array
    {
        if (!is_readable($path)) {
            throw new RuntimeException("Cannot read CSV: $path");
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open CSV: $path");
        }

        $headers = fgetcsv($handle);
        if ($headers === false || $headers === [null]) {
            fclose($handle);
            throw new RuntimeException('CSV is empty or has no header row');
        }

        $headers = array_map(fn($h) => self::sanitizeCell($h), $headers);
        $parser = new self();
        $parser->buildColumnMap($headers);

        $leads = [];
        $rowNum = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;
            if ($row === [null] || self::rowIsEmpty($row)) {
                continue;
            }
            $parsed = $parser->parseRow($row, $rowNum);
            if ($parsed !== null) {
                $leads[$parsed['lead_code']] = $parsed;
            }
        }
        fclose($handle);

        return [
            'leads' => $leads,
            'headers' => $headers,
            'row_count' => $rowNum - 1,
        ];
    }

    private static function rowIsEmpty(array $row): bool
    {
        foreach ($row as $cell) {
            if (self::sanitizeCell($cell) !== '') {
                return false;
            }
        }
        return true;
    }

    private function buildColumnMap(array $headers): void
    {
        $aliases = [
            'contact_date' => ['date'],
            'project' => ['project interest', 'project'],
            'lead_name' => ['name'],
            'phone' => ['phone'],
            'line_id' => ['line id', 'lineid'],
            'owner_code' => ['listing code', 'listingcode'],
            'gender' => ['gender', 'เพศ'],
            'nationality' => ['ชาติ', 'nationality'],
            'pain_point_found' => ['pain point'],
            'potential' => ['potential'],
            'budget' => ['budget'],
            'revenue' => ['revenue'],
            'win_date' => ['closing date', 'closingdate'],
            'units_sent' => ['unit sent', 'unitsent'],
            'source' => ['source'],
            'contact_by' => ['contact by', 'contactby'],
            'intent_buy_rent' => ['buy or rent', 'buy/rent'],
            'unit_type' => ['unit type', 'unittype'],
            'listing_type' => ['listing type', 'listingtype'],
            'is_agent' => ['agent'],
            'agent_client_name' => ['ชื่อลูกค้า'],
            'agent_client_phone_last4' => ['เบอร์ 4 ตัวท้าย', 'เบอร์โทร 4 ตัวท้าย'],
            'age' => ['age', 'อายุ'],
            'occupation' => ['occupation', 'อาชีพ'],
            'work_area' => ['พื้นที่การทำงาน', 'พื้นที่การทำงานของลูกค้า'],
            'commute' => ['การเดินทาง'],
            'interest_area' => ['พื้นที่ที่ลูกค้าสนใจซื้อ', 'พื้นที่สนใจซื้อ'],
            'purchase_purpose' => ['จุดประสงค์การซื้อ', 'จุดประสงค์'],
            'close_project' => ['project close'],
            'close_owner_code' => ['listing code close', 'listingcodeclose'],
        ];

        $statusCols = [];
        $listingCodeCols = [];

        foreach ($headers as $i => $raw) {
            $h = lead_sheet_normalize_header($raw);
            if ($h === '' || $h === '*') {
                continue;
            }
            if ($h === 'status') {
                $statusCols[] = $i;
                continue;
            }
            if ($h === 'listingcode' || $h === 'listing code') {
                $listingCodeCols[] = $i;
            }
            if ($h === 'ชื่อลูกค้า') {
                $this->col['agent_client_name'] = $i;
                continue;
            }
            foreach ($aliases as $field => $keys) {
                if (isset($this->col[$field])) {
                    continue;
                }
                foreach ($keys as $k) {
                    if ($h === $k || str_contains($h, $k)) {
                        $this->col[$field] = $i;
                        break 2;
                    }
                }
            }
        }

        if (isset($statusCols[0])) {
            $this->col['sheet_status'] = $statusCols[0];
        }
        if (isset($statusCols[1]) && !isset($this->col['intent_buy_rent'])) {
            $this->col['intent_buy_rent'] = $statusCols[1];
        }
        if (isset($listingCodeCols[0]) && !isset($this->col['owner_code'])) {
            $this->col['owner_code'] = $listingCodeCols[0];
        }
        if (isset($listingCodeCols[1])) {
            $this->col['close_owner_code'] = $listingCodeCols[1];
        }

        $stageMap = [
            'call' => 'Call',
            'follow' => 'Follow',
            'app.' => 'Appointment',
            'app' => 'Appointment',
            'appointment' => 'Appointment',
            'show' => 'Show',
            'nego' => 'Nego',
            'close' => 'Close',
            'bank' => 'Bank',
            'win' => 'Win',
        ];
        foreach ($headers as $i => $raw) {
            $h = lead_sheet_normalize_header($raw);
            if (isset($stageMap[$h])) {
                $this->pipeCol[$stageMap[$h]] = $i;
            }
        }
    }

    private function get(array $row, string $field): string
    {
        if (!isset($this->col[$field])) {
            return '';
        }
        $idx = $this->col[$field];
        return self::sanitizeCell($row[$idx] ?? '');
    }

    private function parseRow(array $row, int $rowNum): ?array
    {
        $name = $this->get($row, 'lead_name');
        $project = $this->get($row, 'project');
        $phoneRaw = $this->get($row, 'phone');
        $lineRaw = $this->get($row, 'line_id');
        $owner = TanWorkbookImport::normalizeListingCode($this->get($row, 'owner_code'));

        if ($name === '' && $phoneRaw === '' && $lineRaw === '' && $owner === '') {
            return null;
        }

        $contacts = repair_owner_contacts([
            'phone' => $phoneRaw,
            'line_id' => $lineRaw,
        ]);

        if ($name === '' && $contacts['phone'] !== '') {
            $digits = preg_replace('/\D/', '', $contacts['phone']);
            $name = 'ลูกค้า ' . (strlen($digits) >= 4 ? substr($digits, -4) : $digits);
        }
        if ($name === '' && $owner !== '') {
            $name = 'ลูกค้า ' . $owner;
        }
        if ($name === '') {
            return null;
        }

        $contactDate = self::parseDate($this->get($row, 'contact_date'));

        $potential = strtoupper($this->get($row, 'potential'));
        if (!in_array($potential, ['A', 'B', 'C'], true)) {
            $potential = '';
        }

        $sheetStatus = $this->get($row, 'sheet_status');
        $status = lead_sheet_status_to_crm($sheetStatus);

        $budget = $this->get($row, 'budget');
        $revenue = lead_sheet_compute_revenue($budget, $this->get($row, 'revenue'));

        $winDate = self::parseDate($this->get($row, 'win_date'));
        if ($winDate === null && strtoupper($sheetStatus) === 'WIN') {
            $winDate = $contactDate;
        }

        $unitsRaw = $this->get($row, 'units_sent');
        $unitsSent = is_numeric($unitsRaw) ? (int)$unitsRaw : null;

        $stageEvents = $this->buildStageEvents($row, $contactDate, $sheetStatus);
        foreach ($stageEvents as $ev) {
            $out = $ev['outcome'];
            $stage = $ev['stage'];
            if ($out === 'yes' && $stage !== 'Win') {
                $status = $stage;
            } elseif ($out === 'yes' && $stage === 'Win') {
                $status = 'Win';
            } elseif (in_array($out, ['lose', 'reject', 'hold'], true)) {
                $status = lead_matrix_outcome_to_terminal_status($out);
            }
        }
        $status = TanWorkbookImport::normalizeLeadStatus($status);

        $intentRaw = $this->get($row, 'intent_buy_rent');
        $intentBuyRent = '';
        if (in_array(strtolower($intentRaw), ['buy', 'rent'], true)) {
            $intentBuyRent = ucfirst(strtolower($intentRaw));
        }

        $code = lead_import_make_code($owner, $contacts['phone']);

        return [
            'lead_code' => $code,
            'lead_name' => $name,
            'phone' => $contacts['phone'],
            'line_id' => $contacts['line_id'],
            'owner_code' => $owner,
            'project' => $project,
            'contact_date' => $contactDate,
            'potential' => $potential,
            'status' => $status,
            'sheet_status' => $sheetStatus,
            'pain_point_found' => $this->get($row, 'pain_point_found'),
            'budget' => $budget,
            'revenue' => $revenue,
            'win_date' => $winDate,
            'win_price' => $budget,
            'units_sent' => $unitsSent,
            'gender' => $this->get($row, 'gender'),
            'nationality' => $this->get($row, 'nationality'),
            'source' => $this->get($row, 'source'),
            'contact_by' => $this->get($row, 'contact_by'),
            'intent_buy_rent' => $intentBuyRent,
            'unit_type' => $this->get($row, 'unit_type'),
            'listing_type' => $this->get($row, 'listing_type'),
            'is_agent' => lead_sheet_truthy_agent($this->get($row, 'is_agent')),
            'agent_client_name' => $this->get($row, 'agent_client_name'),
            'agent_client_phone_last4' => $this->get($row, 'agent_client_phone_last4'),
            'age' => $this->get($row, 'age'),
            'occupation' => $this->get($row, 'occupation'),
            'work_area' => $this->get($row, 'work_area'),
            'commute' => $this->get($row, 'commute'),
            'interest_area' => $this->get($row, 'interest_area'),
            'purchase_purpose' => $this->get($row, 'purchase_purpose'),
            'close_project' => $this->get($row, 'close_project'),
            'close_owner_code' => TanWorkbookImport::normalizeListingCode($this->get($row, 'close_owner_code')),
            'stage_events' => $stageEvents,
            '_source_row' => $rowNum,
        ];
    }

    public static function parseDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (is_numeric($raw)) {
            return TanWorkbookImport::excelDate($raw);
        }
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $raw, $m)) {
            $a = (int)$m[1];
            $b = (int)$m[2];
            $y = (int)$m[3];
            if (checkdate($b, $a, $y)) {
                return sprintf('%04d-%02d-%02d', $y, $b, $a);
            }
            if (checkdate($a, $b, $y)) {
                return sprintf('%04d-%02d-%02d', $y, $a, $b);
            }
        }
        $ts = strtotime($raw);
        if ($ts !== false) {
            return date('Y-m-d', $ts);
        }
        return null;
    }

    /** @return array<int, array{stage:string,outcome:string,event_date:?string}> */
    private function buildStageEvents(array $row, ?string $contactDate, string $sheetStatus): array
    {
        $events = [];
        foreach ($this->pipeCol as $stage => $idx) {
            $out = lead_sheet_parse_outcome(self::sanitizeCell($row[$idx] ?? ''));
            if ($out !== null) {
                $events[] = ['stage' => $stage, 'outcome' => $out, 'event_date' => $contactDate];
            }
        }
        if (empty($events) && $contactDate) {
            $events = lead_sheet_synthesize_stage_events($sheetStatus, $contactDate);
        }
        return $events;
    }

}
