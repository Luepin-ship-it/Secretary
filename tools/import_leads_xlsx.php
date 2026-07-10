<?php
/**
 * CLI: Import Lead Sheet v2 (คอลัมน์ A–AJ) → leads + lead_stage_events
 *
 * Usage:
 *   php tools/import_leads_xlsx.php "path/to/leads.xlsx" --line-id=Uxxx [--dry-run]
 *   php tools/import_leads_xlsx.php "path/to/leads.xlsx" --user-id=8 [--dry-run]
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/lead_sheet_import.php';
require_once dirname(__DIR__) . '/lib/contact_normalize.php';
require_once dirname(__DIR__) . '/task_helpers.php';

$path = $argv[1] ?? '';
$dryRun = in_array('--dry-run', $argv, true);
$lineId = '';
$userIdArg = 0;
foreach ($argv as $arg) {
    if (strpos($arg, '--line-id=') === 0) {
        $lineId = substr($arg, 10);
    }
    if (strpos($arg, '--user-id=') === 0) {
        $userIdArg = (int)substr($arg, 10);
    }
}

if ($path === '' || !is_readable($path)) {
    fwrite(STDERR, "Usage: php tools/import_leads_xlsx.php <xlsx-path> --line-id=Uxxxx | --user-id=N [--dry-run]\n");
    exit(1);
}

echo "Reading: $path\n";
$data = LeadSheetImport::load($path);
$leads = $data['leads'];

echo 'Format: ' . ($data['format'] ?? 'unknown');
if (!empty($data['sheet'])) {
    echo " (sheet: {$data['sheet']})";
}
echo "\n";
echo 'Leads parsed: ' . count($leads) . "\n\n";

if ($leads) {
    echo "Sample leads:\n";
    foreach (array_slice($leads, 0, 3, true) as $l) {
        $ev = count($l['stage_events'] ?? []);
        echo "  {$l['lead_code']} | {$l['lead_name']} | {$l['status']} | {$l['owner_code']} | events=$ev\n";
    }
    echo "\n";
}

if ($dryRun) {
    echo "Dry-run only — no database writes.\n";
    exit(0);
}

$userId = $userIdArg;
$key = '';
if ($userId <= 0) {
    if ($lineId === '') {
        fwrite(STDERR, "Import requires --line-id=Uxxxx or --user-id=N\n");
        exit(1);
    }
    $stmt = $conn->prepare('SELECT id, encryption_key FROM users WHERE line_user_id = ? LIMIT 1');
    $stmt->bind_param('s', $lineId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user) {
        fwrite(STDERR, "User not found for line_id: $lineId\n");
        exit(1);
    }
    $userId = (int)$user['id'];
    $key = $user['encryption_key'];
} else {
    $stmt = $conn->prepare('SELECT id, encryption_key FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user) {
        fwrite(STDERR, "User not found for user_id: $userId\n");
        exit(1);
    }
    $key = $user['encryption_key'];
}

lead_stage_events_ensure_schema($conn);
lead_sheet_ensure_schema($conn);

function lead_import_enc($val, $key) {
    return ($val === null || $val === '') ? null : encrypt_data($val, $key);
}

$leadUpsert = 0;
$eventsWritten = 0;

foreach ($leads as $l) {
    $code = $l['lead_code'];
    $contacts = repair_owner_contacts(['phone' => $l['phone'] ?? '', 'line_id' => $l['line_id'] ?? '']);
    $status = TanWorkbookImport::normalizeLeadStatus($l['status'] ?? 'Call');

    $nameEnc = encrypt_data($l['lead_name'], $key);
    $projEnc = lead_import_enc($l['project'] ?? '', $key);
    $phoneEnc = lead_import_enc($contacts['phone'], $key);
    $lineEnc = lead_import_enc($contacts['line_id'], $key);
    $budgetEnc = lead_import_enc($l['budget'] ?? '', $key);
    $winPriceEnc = lead_import_enc($l['win_price'] ?? '', $key);
    $genderEnc = lead_import_enc($l['gender'] ?? '', $key);
    $nationalityEnc = lead_import_enc($l['nationality'] ?? '', $key);
    $revenueEnc = lead_import_enc($l['revenue'] ?? '', $key);
    $sourceEnc = lead_import_enc($l['source'] ?? '', $key);
    $contactByEnc = lead_import_enc($l['contact_by'] ?? '', $key);
    $unitTypeEnc = lead_import_enc($l['unit_type'] ?? '', $key);
    $listingType = $l['listing_type'] ?? '';
    $agentNameEnc = lead_import_enc($l['agent_client_name'] ?? '', $key);
    $agentPhone4Enc = lead_import_enc($l['agent_client_phone_last4'] ?? '', $key);
    $ageEnc = lead_import_enc($l['age'] ?? '', $key);
    $occupationEnc = lead_import_enc($l['occupation'] ?? '', $key);
    $workAreaEnc = lead_import_enc($l['work_area'] ?? '', $key);
    $commuteEnc = lead_import_enc($l['commute'] ?? '', $key);
    $interestAreaEnc = lead_import_enc($l['interest_area'] ?? '', $key);
    $purchasePurposeEnc = lead_import_enc($l['purchase_purpose'] ?? '', $key);
    $closeProjectEnc = lead_import_enc($l['close_project'] ?? '', $key);

    $sheetStatus = $l['sheet_status'] ?? '';
    $painFound = $l['pain_point_found'] ?? '';
    $intentBuyRent = $l['intent_buy_rent'] ?? '';
    $isAgent = (int)($l['is_agent'] ?? 0);
    $unitsSent = $l['units_sent'] !== null ? (int)$l['units_sent'] : 0;
    $closeOwnerCode = $l['close_owner_code'] ?? '';
    $winDate = $l['win_date'] ?? null;
    $contactDate = $l['contact_date'] ?? date('Y-m-d');
    $ownerCode = $l['owner_code'] ?? '';

    $chk = $conn->prepare('SELECT id FROM leads WHERE user_id = ? AND lead_code = ? LIMIT 1');
    $chk->bind_param('is', $userId, $code);
    $chk->execute();
    $exists = $chk->get_result()->fetch_assoc();
    $chk->close();

    if ($exists) {
        $sql = 'UPDATE leads SET
            lead_name_enc=?, project_enc=?, phone_enc=?, line_id_enc=?, budget_enc=?,
            potential=?, contact_date=?, status=?, owner_code=?,
            win_date=?, win_price_enc=?,
            gender_enc=?, nationality_enc=?, sheet_status=?, pain_point_found=?,
            revenue_enc=?, units_sent=?, source_enc=?, contact_by_enc=?,
            intent_buy_rent=?, unit_type_enc=?, listing_type_enc=?, is_agent=?,
            agent_client_name_enc=?, agent_client_phone_last4_enc=?,
            age_enc=?, occupation_enc=?, work_area_enc=?, commute_enc=?,
            interest_area_enc=?, purchase_purpose_enc=?, close_project_enc=?, close_owner_code=?
            WHERE id=?';
        $st = $conn->prepare($sql);
        $st->bind_param(
            'sssssssssssssssssssssssssssssssiii',
            $nameEnc, $projEnc, $phoneEnc, $lineEnc, $budgetEnc,
            $l['potential'], $contactDate, $status, $ownerCode,
            $winDate, $winPriceEnc,
            $genderEnc, $nationalityEnc, $sheetStatus, $painFound,
            $revenueEnc, $unitsSent, $sourceEnc, $contactByEnc,
            $intentBuyRent, $unitTypeEnc, $listingType, $isAgent,
            $agentNameEnc, $agentPhone4Enc,
            $ageEnc, $occupationEnc, $workAreaEnc, $commuteEnc,
            $interestAreaEnc, $purchasePurposeEnc, $closeProjectEnc, $closeOwnerCode,
            $exists['id']
        );
        $leadId = (int)$exists['id'];
    } else {
        $sql = 'INSERT INTO leads (
            user_id, lead_code, lead_name_enc, project_enc, phone_enc, line_id_enc, budget_enc,
            potential, contact_date, status, owner_code, win_date, win_price_enc,
            gender_enc, nationality_enc, sheet_status, pain_point_found,
            revenue_enc, units_sent, source_enc, contact_by_enc,
            intent_buy_rent, unit_type_enc, listing_type_enc, is_agent,
            agent_client_name_enc, agent_client_phone_last4_enc,
            age_enc, occupation_enc, work_area_enc, commute_enc,
            interest_area_enc, purchase_purpose_enc, close_project_enc, close_owner_code
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
        $st = $conn->prepare($sql);
        $st->bind_param(
            'isssssssssssssssssisssssissssssssss',
            $userId, $code, $nameEnc, $projEnc, $phoneEnc, $lineEnc, $budgetEnc,
            $l['potential'], $contactDate, $status, $ownerCode, $winDate, $winPriceEnc,
            $genderEnc, $nationalityEnc, $sheetStatus, $painFound,
            $revenueEnc, $unitsSent, $sourceEnc, $contactByEnc,
            $intentBuyRent, $unitTypeEnc, $listingType, $isAgent,
            $agentNameEnc, $agentPhone4Enc,
            $ageEnc, $occupationEnc, $workAreaEnc, $commuteEnc,
            $interestAreaEnc, $purchasePurposeEnc, $closeProjectEnc, $closeOwnerCode
        );
        $leadId = 0;
    }

    if (!$st->execute()) {
        fwrite(STDERR, "Failed {$code}: " . $st->error . "\n");
        $st->close();
        continue;
    }
    if ($leadId <= 0) {
        $leadId = (int)$conn->insert_id;
    }
    $st->close();
    $leadUpsert++;

    $events = $l['stage_events'] ?? [];
    if ($events && $leadId > 0) {
        $del = $conn->prepare('DELETE FROM lead_stage_events WHERE user_id = ? AND lead_id = ?');
        $del->bind_param('ii', $userId, $leadId);
        $del->execute();
        $del->close();

        $ins = $conn->prepare('INSERT INTO lead_stage_events (user_id, lead_id, stage, outcome, note_enc, event_date)
            VALUES (?, ?, ?, ?, ?, ?)');
        $emptyNote = '';
        foreach ($events as $ev) {
            $stage = $ev['stage'];
            $outcome = $ev['outcome'];
            $eventDate = $ev['event_date'] ?? $contactDate;
            $ins->bind_param('iissss', $userId, $leadId, $stage, $outcome, $emptyNote, $eventDate);
            if ($ins->execute()) {
                $eventsWritten++;
            }
        }
        $ins->close();
    }
}

echo "Imported leads: $leadUpsert\n";
echo "Stage events written: $eventsWritten\n";
