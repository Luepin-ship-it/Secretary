<?php
/**
 * Import Lead Sheet v2 — อ่านจากหัวคอลัมน์แถว 1 (รองรับชีทมีคอลัมน์ * คั่น)
 */
require_once __DIR__ . '/lead_sheet_schema.php';
require_once __DIR__ . '/lead_code.php';
require_once __DIR__ . '/contact_normalize.php';
require_once dirname(__DIR__) . '/task_helpers.php';

class LeadSheetImport
{
    /** @var array<string, string> field => col letter */
    private array $fieldCol = [];
    /** @var array<string, string> stage => col letter */
    private array $pipeCol = [];
    private int $headerRow = 1;
    private bool $legacySpacer = false;

    public static function isLeadSheetFormat(array $rows): bool
    {
        foreach ([1, 2] as $hr) {
            if (!isset($rows[$hr])) {
                continue;
            }
            $row = $rows[$hr];
            if (!self::rowHas($row, 'date')) {
                continue;
            }
            if (self::rowHas($row, 'name') || self::rowHas($row, 'project interest') || self::rowHas($row, 'phone')) {
                return true;
            }
        }
        return false;
    }

    private static function rowHas(array $row, string $needle): bool
    {
        foreach ($row as $val) {
            if (lead_sheet_normalize_header((string)$val) === $needle) {
                return true;
            }
        }
        return false;
    }

    public function __construct(array $rows)
    {
        $this->detectHeaderRow($rows);
        $this->buildColumnMap($rows[$this->headerRow] ?? []);
        $this->detectPipelineColumns($rows[$this->headerRow] ?? []);
    }

    private function detectHeaderRow(array $rows): void
    {
        foreach ([1, 2] as $hr) {
            $row = $rows[$hr] ?? [];
            if (self::rowHas($row, 'date') && (self::rowHas($row, 'name') || self::rowHas($row, 'phone'))) {
                $this->headerRow = $hr;
                return;
            }
        }
    }

    private function buildColumnMap(array $headerRow): void
    {
        $aliases = [
            'contact_date' => ['date'],
            'project' => ['project interest', 'project'],
            'lead_name' => ['name'],
            'phone' => ['phone'],
            'owner_code' => ['listing code', 'listingcode'],
            'gender' => ['gender', 'เพศ'],
            'nationality' => ['ชาติ', 'nationality'],
            'sheet_status' => ['status'],
            'pain_point_found' => ['pain point'],
            'potential' => ['potential'],
            'budget' => ['budget'],
            'revenue' => ['revenue'],
            'win_date' => ['closing date', 'closingdate'],
            'units_sent' => ['unit sent', 'unitsent'],
            'source' => ['source'],
            'contact_by' => ['contact by', 'contactby'],
            'intent_buy_rent' => ['buy or rent', 'statue', 'buy/rent'],
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

        $listingCodeCols = [];
        $nameCols = [];

        foreach ($headerRow as $col => $raw) {
            $h = lead_sheet_normalize_header((string)$raw);
            if ($h === '' || $h === '*') {
                $this->legacySpacer = true;
                continue;
            }
            if ($h === 'listingcode' || $h === 'listing code') {
                $listingCodeCols[] = $col;
            }
            if ($h === 'name') {
                $nameCols[] = $col;
            }
            if ($h === 'ชื่อลูกค้า') {
                $this->fieldCol['agent_client_name'] = $col;
                continue;
            }
            foreach ($aliases as $field => $keys) {
                if (isset($this->fieldCol[$field])) {
                    continue;
                }
                foreach ($keys as $k) {
                    if ($h === $k || str_contains($h, $k)) {
                        $this->fieldCol[$field] = $col;
                        break 2;
                    }
                }
            }
        }

        if (!isset($this->fieldCol['lead_name']) && $nameCols) {
            $this->fieldCol['lead_name'] = $nameCols[0];
        }
        if (count($listingCodeCols) >= 1 && !isset($this->fieldCol['owner_code'])) {
            $this->fieldCol['owner_code'] = $listingCodeCols[0];
        }
        if (count($listingCodeCols) >= 2) {
            $this->fieldCol['close_owner_code'] = $listingCodeCols[1];
        }
    }

    private function detectPipelineColumns(array $headerRow): void
    {
        $stageMap = [
            'call' => 'Call',
            'follow' => 'Follow',
            'app.' => 'Appointment',
            'app' => 'Appointment',
            'appointment' => 'Appointment',
            'show' => 'Show',
            'showing' => 'Show',
            'nego' => 'Nego',
            'close' => 'Close',
            'bank' => 'Bank',
            'win' => 'Win',
            'win!' => 'Win',
        ];
        foreach ($headerRow as $col => $raw) {
            $h = lead_sheet_normalize_header((string)$raw);
            if (isset($stageMap[$h])) {
                $this->pipeCol[$stageMap[$h]] = $col;
            }
        }
        if (empty($this->pipeCol)) {
            foreach (lead_sheet_pipeline_columns() as $col => $stage) {
                $this->pipeCol[$stage] = $col;
            }
        }
    }

    /**
     * @return array<string, array>
     */
    public function parseRows(array $rows): array
    {
        $leads = [];
        $startRow = $this->headerRow + 1;
        if ($this->legacySpacer) {
            $startRow = max($startRow, 5);
            if (isset($rows[$this->headerRow + 1])) {
                $sub = strtolower(implode(' ', array_map('strval', $rows[$this->headerRow + 1])));
                if (str_contains($sub, 'อาชีพ') || str_contains($sub, 'ดนัย')) {
                    $startRow = max($startRow, $this->headerRow + 2);
                }
            }
        } elseif (isset($rows[$startRow])) {
            $sub = strtolower(implode(' ', array_map('strval', $rows[$startRow])));
            if (str_contains($sub, 'อาชีพ') || str_contains($sub, 'ดนัย')) {
                $startRow++;
            }
        }

        foreach ($rows as $rowNum => $cells) {
            if ($rowNum < $startRow) {
                continue;
            }
            if ($this->isSkipRow($cells)) {
                continue;
            }

            $parsed = $this->parseRow($cells, $rowNum);
            if ($parsed === null) {
                continue;
            }
            $leads[$parsed['lead_code']] = $parsed;
        }
        return $leads;
    }

    private function isSkipRow(array $cells): bool
    {
        $joined = strtolower(implode(' ', array_map('strval', $cells)));
        if (str_contains($joined, 'อาชีพ') && str_contains($joined, 'พื้นที่การทำงาน')) {
            return true;
        }
        return false;
    }

    private function cell(array $cells, ?string $col): string
    {
        if ($col === null) {
            return '';
        }
        return trim((string)($cells[$col] ?? ''));
    }

    private function parseRow(array $cells, int $rowNum): ?array
    {
        $resolved = $this->resolveLeadIdentity($cells);
        $name = $resolved['name'];
        $project = $resolved['project'];
        $phone = $resolved['phone'];
        $owner = $resolved['owner_code'];

        if ($name === '' && $phone === '' && $owner === '') {
            return null;
        }
        if ($name === '' && $phone !== '') {
            $digits = preg_replace('/\D/', '', $phone);
            $name = 'ลูกค้า ' . (strlen($digits) >= 4 ? substr($digits, -4) : $digits);
        }
        if ($name === '' && $owner !== '') {
            $name = 'ลูกค้า ' . $owner;
        }
        if ($name === '') {
            return null;
        }

        $contacts = repair_owner_contacts([
            'phone' => $phone,
            'line_id' => $this->resolveLineHandle($cells, $name),
        ]);

        $dateCol = $this->fieldCol['contact_date'] ?? 'A';
        $contactDate = TanWorkbookImport::cellExcelDate($cells, $dateCol);

        $potential = strtoupper(trim($this->cell($cells, $this->fieldCol['potential'] ?? null)));
        if (!in_array($potential, ['A', 'B', 'C'], true)) {
            $potential = '';
        }

        $sheetStatus = $this->pickSheetStatus($cells);
        $status = lead_sheet_status_to_crm($sheetStatus);

        $budget = $this->resolveBudget($cells);
        $revenue = lead_sheet_compute_revenue($budget, $this->cell($cells, $this->fieldCol['revenue'] ?? 'X'));

        $winDate = null;
        $wdCol = $this->fieldCol['win_date'] ?? 'Z';
        $winDate = TanWorkbookImport::cellExcelDate($cells, $wdCol) ?? TanWorkbookImport::cellExcelDate($cells, 'U');
        if ($winDate === null && strtoupper($sheetStatus) === 'WIN') {
            $winDate = $contactDate;
        }

        $unitsRaw = $this->cell($cells, $this->fieldCol['units_sent'] ?? 'AA');
        $unitsSent = is_numeric($unitsRaw) ? (int)$unitsRaw : null;

        $stageEvents = $this->buildStageEvents($cells, $contactDate, $sheetStatus);
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
        $code = lead_import_make_code($owner, $contacts['phone']);

        $pain = $this->cell($cells, $this->fieldCol['pain_point_found'] ?? 'G');
        if ($pain === '' || strtolower($pain) === 'ดนัย') {
            $pain = $this->cell($cells, 'M');
        }

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
            'pain_point_found' => $pain,
            'budget' => $budget,
            'revenue' => $revenue,
            'win_date' => $winDate,
            'win_price' => $budget,
            'units_sent' => $unitsSent,
            'gender' => $this->cell($cells, $this->fieldCol['gender'] ?? 'I'),
            'nationality' => $this->pickNationality($cells),
            'source' => $this->cell($cells, $this->fieldCol['source'] ?? 'AB'),
            'contact_by' => $this->pickContactBy($cells),
            'intent_buy_rent' => $this->pickBuyRent($cells),
            'unit_type' => $this->pickUnitType($cells),
            'listing_type' => $this->pickListingType($cells),
            'is_agent' => lead_sheet_truthy_agent($this->cell($cells, $this->fieldCol['is_agent'] ?? 'AF')),
            'agent_client_name' => $this->cell($cells, $this->fieldCol['agent_client_name'] ?? 'AG'),
            'agent_client_phone_last4' => $this->cell($cells, $this->fieldCol['agent_client_phone_last4'] ?? 'AH'),
            'age' => $this->cell($cells, $this->fieldCol['age'] ?? 'AI'),
            'occupation' => $this->cell($cells, $this->fieldCol['occupation'] ?? null),
            'work_area' => $this->cell($cells, $this->fieldCol['work_area'] ?? null),
            'commute' => $this->cell($cells, $this->fieldCol['commute'] ?? 'V'),
            'interest_area' => $this->cell($cells, $this->fieldCol['interest_area'] ?? 'X'),
            'purchase_purpose' => $this->cell($cells, $this->fieldCol['purchase_purpose'] ?? 'AJ'),
            'close_project' => $this->cell($cells, $this->fieldCol['close_project'] ?? null),
            'close_owner_code' => TanWorkbookImport::normalizeListingCode($this->cell($cells, $this->fieldCol['close_owner_code'] ?? 'Y')),
            'stage_events' => $stageEvents,
            '_source_row' => $rowNum,
        ];
    }

    /**
     * แยกชื่อลูกค้า vs โครงการ — สแกนทุกคอลัมน์ที่เป็นไปได้ ไม่เดาจากตำแหน่งเดียว
     * @return array{name:string,project:string,phone:string,owner_code:string}
     */
    private function resolveLeadIdentity(array $cells): array
    {
        $nameCols = array_unique(array_filter([
            'C', 'E', 'B', 'AG', 'AF', 'Y',
            $this->fieldCol['lead_name'] ?? 'D',
        ]));
        $projectCols = array_unique(array_filter([
            $this->fieldCol['project'] ?? 'B',
            'D', 'C', 'H', 'I', 'J', 'AE', 'AH', 'AG', 'AC', 'X',
        ]));
        $phoneCols = ['C', 'D', 'B', 'F', 'Z', 'Y', 'E', $this->fieldCol['phone'] ?? 'F'];
        $ownerCols = ['D', 'K', 'I', 'H', 'E', 'B', 'AA', 'AG', 'AI', 'U', 'AC',
            $this->fieldCol['owner_code'] ?? 'H'];

        $name = '';
        foreach ($nameCols as $col) {
            $v = $this->cell($cells, $col);
            if ($this->looksLikePersonName($v)) {
                $name = $v;
                break;
            }
        }

        $project = '';
        foreach ($projectCols as $col) {
            $v = $this->cell($cells, $col);
            if ($v === $name) {
                continue;
            }
            if ($this->looksLikeProjectName($v)) {
                $project = $v;
                break;
            }
        }

        $owner = '';
        foreach ($ownerCols as $col) {
            $code = TanWorkbookImport::normalizeListingCode($this->cell($cells, $col));
            if ($code !== '') {
                $owner = $code;
                break;
            }
        }

        $phone = '';
        foreach ($phoneCols as $col) {
            $v = $this->cell($cells, $col);
            if ($v === $name || $v === $project) {
                continue;
            }
            if (TanWorkbookImport::looksLikePhone($v)) {
                $phone = $v;
                break;
            }
        }
        if ($phone === '') {
            $phone = TanWorkbookImport::pickPhone($cells, $phoneCols);
        }

        return [
            'name' => $name,
            'project' => $project,
            'phone' => $phone,
            'owner_code' => $owner,
        ];
    }

    private function resolveLineHandle(array $cells, string $leadName): string
    {
        foreach (['D', 'C', 'Y', 'AG'] as $col) {
            $v = $this->cell($cells, $col);
            if ($v === $leadName) {
                continue;
            }
            if ($this->looksLikeSocialHandle($v)) {
                return $v;
            }
        }
        return '';
    }

    private function looksLikeProjectName(string $raw): bool
    {
        $s = trim($raw);
        if ($s === '' || $this->isAgentNoise($s) || $this->isPlaceholderText($s)) {
            return false;
        }
        if (TanWorkbookImport::normalizeListingCode($s) !== '') {
            return false;
        }
        if (TanWorkbookImport::looksLikePhone($s)) {
            return false;
        }
        if (preg_match('/^\d+(\.\d+)?([eE][+]?\d+)?$/', $s)) {
            return false;
        }
        if ($this->looksLikeGarbageName($s)) {
            return false;
        }

        $low = strtolower($s);
        $keywords = [
            'bangkok', 'boulevard', 'boulevaed', 'boulervard', 'life',
            'วงแหวน', 'รามอินทรา', 'เกษตร', 'นวมินทร์', 'วัชรพล', 'พหล',
            'noble', 'geo', 'prestige', 'pave', 'pleno', 'lanceo', 'มัณฑนา',
            'ชัยพฤกษ์', 'บ้านกลางเมือง', 'trust', 'townhome', 'สุขาภิบาล',
            'นวลจันทร์', 'ที่ดิน', 'แฟชั่น', 'fashion', 'mantana', 'golden',
            'เศรษฐสิริ', 'station', 'exclusive', 'common list', 'a list',
            'พระราม', 'รังสิต', 'สายไหม', 'เอกมัย', 'ลำลูกกา', 'watcharapol',
        ];
        foreach ($keywords as $kw) {
            if (str_contains($low, $kw) || str_contains($s, $kw)) {
                return true;
            }
        }
        if (mb_strlen($s) >= 14 && preg_match('/\s/u', $s)) {
            return true;
        }
        return false;
    }

    private function looksLikePersonName(string $raw): bool
    {
        $s = trim($raw);
        if ($s === '' || $this->isAgentNoise($s) || $this->isPlaceholderText($s)) {
            return false;
        }
        if ($this->looksLikeGarbageName($s)) {
            return false;
        }
        if ($this->looksLikeProjectName($s)) {
            return false;
        }
        if (TanWorkbookImport::looksLikePhone($s)) {
            return false;
        }
        if (TanWorkbookImport::normalizeListingCode($s) !== '') {
            return false;
        }
        if (preg_match('/^\d+(\.\d+)?([eE][+]?\d+)?$/', $s)) {
            return false;
        }
        if (mb_strlen($s) > 50) {
            return false;
        }
        if (preg_match('/^(house|townhouse|condo|buy|rent|yes|no|found|reject|lose|thai|foreign|a|b|c)$/iu', $s)) {
            return false;
        }
        if ($this->looksLikeSocialHandle($s)) {
            return false;
        }
        return mb_strlen($s) >= 1;
    }

    /** LINE / IG / username — ไม่ใช่ชื่อลูกค้า (เช่น 8lackpearl, CHAII053) */
    private function looksLikeSocialHandle(string $raw): bool
    {
        $s = trim($raw);
        if ($s === '') {
            return false;
        }
        if (preg_match('/[\x{0E00}-\x{0E7F}]/u', $s)) {
            return false;
        }
        if (TanWorkbookImport::normalizeListingCode($s) !== '') {
            return false;
        }
        if (TanWorkbookImport::looksLikePhone($s)) {
            return false;
        }
        if (!preg_match('/^[a-zA-Z0-9_.@\-]+$/', $s)) {
            return false;
        }
        if (preg_match('/^\d+(\.\d+)?([eE][+]?\d+)?$/', $s)) {
            return true;
        }
        if (preg_match('/[a-zA-Z]/', $s) && preg_match('/\d/', $s)) {
            return true;
        }
        if (preg_match('/^[a-z][a-z0-9]{2,24}$/', $s)) {
            return true;
        }
        if (preg_match('/^[A-Z]{2,}\d+$/', $s)) {
            return true;
        }
        return false;
    }

    private function isPlaceholderText(string $s): bool
    {
        $low = strtolower($s);
        return str_contains($low, 'พื้นที่ที่ลูกค้า')
            || str_contains($low, 'จุดประสงค์')
            || str_contains($low, 'อายุ 20');
    }

    private function resolveBudget(array $cells): string
    {
        $cols = array_unique(array_filter([
            $this->fieldCol['budget'] ?? 'W',
            'W', 'S', 'O', 'M', 'N', 'L',
        ]));
        foreach ($cols as $col) {
            $raw = $this->cell($cells, $col);
            if ($raw === '' || !is_numeric(str_replace(',', '', $raw))) {
                continue;
            }
            $n = (float)str_replace(',', '', $raw);
            if ($n >= 100000) {
                return (string)(int)round($n);
            }
        }
        return '';
    }

    private function pickSheetStatus(array $cells): string
    {
        foreach (['L', 'E', 'C', 'H'] as $c) {
            $v = strtolower(trim($this->cell($cells, $c)));
            if ($v === '' || $v === 'ดนัย') {
                continue;
            }
            if (in_array($v, ['win', 'pending', 'weekly follow', 'new lead', 'found', 'reject', 'lose'], true)) {
                return $v;
            }
            if (str_contains($v, 'weekly')) {
                return 'weekly follow';
            }
            if (str_contains($v, 'pending')) {
                return 'pending';
            }
            if (str_contains($v, 'new lead')) {
                return 'new lead';
            }
        }
        return '';
    }

    private function pickNationality(array $cells): string
    {
        foreach (['K', 'D'] as $c) {
            $v = strtolower(trim($this->cell($cells, $c)));
            if (in_array($v, ['thai', 'foreign'], true)) {
                return ucfirst($v);
            }
        }
        return '';
    }

    private function pickContactBy(array $cells): string
    {
        foreach (['AJ', 'AB', 'AC'] as $c) {
            $v = trim($this->cell($cells, $c));
            if ($v === '' || strtolower($v) === 'lose' || strtolower($v) === 'ดนัย') {
                continue;
            }
            if (TanWorkbookImport::normalizeListingCode($v) !== '') {
                continue;
            }
            if ($this->looksLikeProjectName($v)) {
                continue;
            }
            return $v;
        }
        return '';
    }

    private function pickBuyRent(array $cells): string
    {
        foreach (['E', 'Y'] as $c) {
            $v = strtolower(trim($this->cell($cells, $c)));
            if (in_array($v, ['buy', 'rent'], true)) {
                return ucfirst($v);
            }
        }
        return '';
    }

    private function pickUnitType(array $cells): string
    {
        foreach (['AD', 'T', 'U', 'V', 'L'] as $c) {
            $v = strtolower(trim($this->cell($cells, $c)));
            if (in_array($v, ['house', 'townhouse', 'condo', 'apartment'], true)) {
                return ucfirst($v);
            }
        }
        return '';
    }

    private function pickListingType(array $cells): string
    {
        foreach (['AE', 'B', 'X'] as $c) {
            $v = strtolower(trim($this->cell($cells, $c)));
            if (str_contains($v, 'exclusive')) {
                return 'Exclusive';
            }
            if (str_contains($v, 'common')) {
                return 'Common list';
            }
            if (str_contains($v, 'a list')) {
                return 'A list';
            }
        }
        return '';
    }

    private function isAgentNoise(string $v): bool
    {
        $s = strtolower(trim($v));
        return in_array($s, ['เชอร์รี่', 'เชอรี่', 'เชอร์รี', 'เชอรรี่', 'เอิร์ธ', 'ดนัย', 'ยู', 'sherry', 'earth', 'นวมินทร์'], true);
    }

    /** @return array<int, array{stage:string,outcome:string,event_date:string}> */
    private function buildStageEvents(array $cells, string $contactDate, string $sheetStatus): array
    {
        $events = [];
        foreach ($this->pipeCol as $stage => $col) {
            $out = lead_sheet_parse_outcome($this->cell($cells, $col));
            if ($out !== null) {
                $events[] = ['stage' => $stage, 'outcome' => $out, 'event_date' => $contactDate];
            }
        }

        $ac = strtolower(trim($this->cell($cells, 'AC')));
        if ($ac === 'lose' && !$this->eventsHaveOutcome($events, 'lose')) {
            $events[] = ['stage' => 'Close', 'outcome' => 'lose', 'event_date' => $contactDate];
        }

        $e = strtolower(trim($this->cell($cells, 'E')));
        if ($e === 'reject' && !$this->eventsHaveOutcome($events, 'reject')) {
            $events[] = ['stage' => 'Call', 'outcome' => 'reject', 'event_date' => $contactDate];
        }
        if (str_contains($e, 'weekly') && !$this->eventsHaveStage($events, 'Follow')) {
            $events[] = ['stage' => 'Call', 'outcome' => 'yes', 'event_date' => $contactDate];
            $events[] = ['stage' => 'Follow', 'outcome' => 'yes', 'event_date' => $contactDate];
        }
        if ($e === 'found' && !$this->eventsHaveStage($events, 'Call')) {
            $events[] = ['stage' => 'Call', 'outcome' => 'yes', 'event_date' => $contactDate];
        }

        if (empty($events)) {
            $events = lead_sheet_synthesize_stage_events($sheetStatus, $contactDate);
        }

        if (empty($events) && strtolower(trim($this->cell($cells, 'G'))) === 'yes') {
            $events[] = ['stage' => 'Call', 'outcome' => 'yes', 'event_date' => $contactDate];
        }

        return $events;
    }

    private function eventsHaveStage(array $events, string $stage): bool
    {
        foreach ($events as $e) {
            if (($e['stage'] ?? '') === $stage) {
                return true;
            }
        }
        return false;
    }

    private function eventsHaveOutcome(array $events, string $outcome): bool
    {
        foreach ($events as $e) {
            if (($e['outcome'] ?? '') === $outcome) {
                return true;
            }
        }
        return false;
    }

    private function looksLikeGarbageName(string $s): bool
    {
        if ($s === '') {
            return false;
        }
        if (preg_match('/^\d+(\.\d+)?([eE][+]?\d+)?$/', $s)) {
            return true;
        }
        if (preg_match('/^(sign|call-in|common list|exclusive|a list|buy|rent|line oa|living insider|ddproperty|referral|อาชีพ|stan tan|chaai|kakiaz|wellnote)$/iu', $s)) {
            return true;
        }
        if (TanWorkbookImport::normalizeListingCode($s) !== '') {
            return true;
        }
        return false;
    }

    public static function load(string $path): array
    {
        require_once __DIR__ . '/xlsx_reader.php';
        $reader = new XlsxReader($path);
        foreach ($reader->sheetNames() as $sn) {
            $rows = $reader->readSheet($sn);
            if (!self::isLeadSheetFormat($rows)) {
                continue;
            }
            $parser = new self($rows);
            return [
                'leads' => $parser->parseRows($rows),
                'sheet' => $sn,
                'format' => 'lead_sheet_v2',
            ];
        }
        return ['leads' => [], 'sheet' => null, 'format' => 'unknown'];
    }
}
