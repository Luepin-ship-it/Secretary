<?php
/**
 * CLI: Import Lead Sheet จาก CSV → MySQL (PDO prepared statements)
 *
 * Usage:
 *   php tools/import_leads_csv.php "c:\path\to\lead.csv" --user-id=8 [--dry-run]
 *   php tools/import_leads_csv.php --user-id=8   (ใช้ไฟล์ default ใน Downloads)
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/lead_csv_import.php';

$defaultPath = 'c:\\Users\\ningp\\Downloads\\lead - ชีต1.csv';
$path = $defaultPath;
$dryRun = false;
$userIdArg = 0;

foreach ($argv as $i => $arg) {
    if ($i === 0) {
        continue;
    }
    if ($arg === '--dry-run') {
        $dryRun = true;
        continue;
    }
    if (strpos($arg, '--user-id=') === 0) {
        $userIdArg = (int)substr($arg, 10);
        continue;
    }
    if ($arg !== '' && $arg[0] !== '-') {
        $path = $arg;
    }
}

if ($userIdArg <= 0) {
    $userIdArg = 8;
}

if (!is_readable($path)) {
    fwrite(STDERR, "Cannot read CSV: $path\n");
    fwrite(STDERR, "Usage: php tools/import_leads_csv.php [csv-path] --user-id=N [--dry-run]\n");
    exit(1);
}

echo "Reading: $path\n";
try {
    $data = LeadCsvImport::load($path);
} catch (Throwable $e) {
    fwrite(STDERR, 'Parse error: ' . $e->getMessage() . "\n");
    exit(1);
}

$leads = $data['leads'];
echo 'Headers: ' . count($data['headers']) . " columns\n";
echo 'Data rows scanned: ' . $data['row_count'] . "\n";
echo 'Leads parsed: ' . count($leads) . "\n\n";

if ($leads) {
    echo "Sample leads:\n";
    foreach (array_slice($leads, 0, 3, true) as $l) {
        $ev = count($l['stage_events'] ?? []);
        echo "  {$l['lead_code']} | {$l['lead_name']} | {$l['status']} | {$l['owner_code']} | line={$l['line_id']} | events=$ev\n";
    }
    echo "\n";
}

if ($dryRun) {
    echo "Dry-run only — no database writes.\n";
    exit(0);
}

$stmtUser = $conn->prepare('SELECT id, user_name, encryption_key FROM users WHERE id = ? LIMIT 1');
$stmtUser->bind_param('i', $userIdArg);
$stmtUser->execute();
$user = $stmtUser->get_result()->fetch_assoc();
$stmtUser->close();

if (!$user) {
    fwrite(STDERR, "User not found for user_id: $userIdArg\n");
    exit(1);
}

$userId = (int)$user['id'];
$key = $user['encryption_key'];

lead_stage_events_ensure_schema($conn);
lead_sheet_ensure_schema($conn);
require_once dirname(__DIR__) . '/lib/lead_customer_group.php';
lead_customer_group_ensure_schema($conn);

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

function csv_import_enc(?string $val, string $key): ?string
{
    if ($val === null || $val === '') {
        return null;
    }
    return encrypt_data(LeadCsvImport::stripQuotes($val), $key);
}

$selectLead = $pdo->prepare('SELECT id FROM leads WHERE user_id = :uid AND lead_code = :code LIMIT 1');

$insertLead = $pdo->prepare('INSERT INTO leads (
    user_id, lead_code, lead_name_enc, project_enc, phone_enc, line_id_enc, budget_enc,
    potential, contact_date, status, owner_code, win_date, win_price_enc,
    gender_enc, nationality_enc, sheet_status, pain_point_found,
    revenue_enc, units_sent, source_enc, contact_by_enc,
    intent_buy_rent, unit_type_enc, listing_type_enc, is_agent,
    agent_client_name_enc, agent_client_phone_last4_enc,
    age_enc, occupation_enc, work_area_enc, commute_enc,
    interest_area_enc, purchase_purpose_enc, close_project_enc, close_owner_code
) VALUES (
    :user_id, :lead_code, :lead_name_enc, :project_enc, :phone_enc, :line_id_enc, :budget_enc,
    :potential, :contact_date, :status, :owner_code, :win_date, :win_price_enc,
    :gender_enc, :nationality_enc, :sheet_status, :pain_point_found,
    :revenue_enc, :units_sent, :source_enc, :contact_by_enc,
    :intent_buy_rent, :unit_type_enc, :listing_type_enc, :is_agent,
    :agent_client_name_enc, :agent_client_phone_last4_enc,
    :age_enc, :occupation_enc, :work_area_enc, :commute_enc,
    :interest_area_enc, :purchase_purpose_enc, :close_project_enc, :close_owner_code
)');

$updateLead = $pdo->prepare('UPDATE leads SET
    lead_name_enc = :lead_name_enc, project_enc = :project_enc, phone_enc = :phone_enc,
    line_id_enc = :line_id_enc, budget_enc = :budget_enc, potential = :potential,
    contact_date = :contact_date, status = :status, owner_code = :owner_code,
    win_date = :win_date, win_price_enc = :win_price_enc,
    gender_enc = :gender_enc, nationality_enc = :nationality_enc,
    sheet_status = :sheet_status, pain_point_found = :pain_point_found,
    revenue_enc = :revenue_enc, units_sent = :units_sent, source_enc = :source_enc,
    contact_by_enc = :contact_by_enc, intent_buy_rent = :intent_buy_rent,
    unit_type_enc = :unit_type_enc, listing_type_enc = :listing_type_enc, is_agent = :is_agent,
    agent_client_name_enc = :agent_client_name_enc,
    agent_client_phone_last4_enc = :agent_client_phone_last4_enc,
    age_enc = :age_enc, occupation_enc = :occupation_enc, work_area_enc = :work_area_enc,
    commute_enc = :commute_enc, interest_area_enc = :interest_area_enc,
    purchase_purpose_enc = :purchase_purpose_enc, close_project_enc = :close_project_enc,
    close_owner_code = :close_owner_code
    WHERE id = :id');

$deleteEvents = $pdo->prepare('DELETE FROM lead_stage_events WHERE user_id = :uid AND lead_id = :lid');
$insertEvent = $pdo->prepare('INSERT INTO lead_stage_events (user_id, lead_id, stage, outcome, note_enc, event_date)
    VALUES (:uid, :lid, :stage, :outcome, :note, :edate)');

echo "=== Import leads: {$user['user_name']} (user_id=$userId) via PDO ===\n";

$leadUpsert = 0;
$eventsWritten = 0;
$emptyNote = '';

$pdo->beginTransaction();
try {
    foreach ($leads as $l) {
        $code = $l['lead_code'];

        $params = [
            ':lead_name_enc' => encrypt_data($l['lead_name'], $key),
            ':project_enc' => csv_import_enc($l['project'] ?? '', $key),
            ':phone_enc' => csv_import_enc($l['phone'] ?? '', $key),
            ':line_id_enc' => csv_import_enc($l['line_id'] ?? '', $key),
            ':budget_enc' => csv_import_enc($l['budget'] ?? '', $key),
            ':potential' => in_array($l['potential'] ?? '', ['A', 'B', 'C'], true) ? $l['potential'] : null,
            ':contact_date' => $l['contact_date'] ?: null,
            ':status' => TanWorkbookImport::normalizeLeadStatus($l['status'] ?? 'Call'),
            ':owner_code' => $l['owner_code'] ?? '',
            ':win_date' => $l['win_date'],
            ':win_price_enc' => csv_import_enc($l['win_price'] ?? '', $key),
            ':gender_enc' => csv_import_enc($l['gender'] ?? '', $key),
            ':nationality_enc' => csv_import_enc($l['nationality'] ?? '', $key),
            ':sheet_status' => LeadCsvImport::stripQuotes($l['sheet_status'] ?? ''),
            ':pain_point_found' => LeadCsvImport::stripQuotes($l['pain_point_found'] ?? ''),
            ':revenue_enc' => csv_import_enc($l['revenue'] ?? '', $key),
            ':units_sent' => $l['units_sent'] !== null ? (int)$l['units_sent'] : null,
            ':source_enc' => csv_import_enc($l['source'] ?? '', $key),
            ':contact_by_enc' => csv_import_enc($l['contact_by'] ?? '', $key),
            ':intent_buy_rent' => LeadCsvImport::stripQuotes($l['intent_buy_rent'] ?? ''),
            ':unit_type_enc' => csv_import_enc($l['unit_type'] ?? '', $key),
            ':listing_type_enc' => LeadCsvImport::stripQuotes($l['listing_type'] ?? ''),
            ':is_agent' => (int)($l['is_agent'] ?? 0),
            ':agent_client_name_enc' => csv_import_enc($l['agent_client_name'] ?? '', $key),
            ':agent_client_phone_last4_enc' => csv_import_enc($l['agent_client_phone_last4'] ?? '', $key),
            ':age_enc' => csv_import_enc($l['age'] ?? '', $key),
            ':occupation_enc' => csv_import_enc($l['occupation'] ?? '', $key),
            ':work_area_enc' => csv_import_enc($l['work_area'] ?? '', $key),
            ':commute_enc' => csv_import_enc($l['commute'] ?? '', $key),
            ':interest_area_enc' => csv_import_enc($l['interest_area'] ?? '', $key),
            ':purchase_purpose_enc' => csv_import_enc($l['purchase_purpose'] ?? '', $key),
            ':close_project_enc' => csv_import_enc($l['close_project'] ?? '', $key),
            ':close_owner_code' => LeadCsvImport::stripQuotes($l['close_owner_code'] ?? ''),
        ];

        $selectLead->execute([':uid' => $userId, ':code' => $code]);
        $existing = $selectLead->fetch();

        if ($existing) {
            $leadId = (int)$existing['id'];
            $updateLead->execute($params + [':id' => $leadId]);
        } else {
            $insertLead->execute($params + [
                ':user_id' => $userId,
                ':lead_code' => $code,
            ]);
            $leadId = (int)$pdo->lastInsertId();
        }
        $leadUpsert++;

        $events = $l['stage_events'] ?? [];
        if ($events && $leadId > 0) {
            $deleteEvents->execute([':uid' => $userId, ':lid' => $leadId]);
            foreach ($events as $ev) {
                $insertEvent->execute([
                    ':uid' => $userId,
                    ':lid' => $leadId,
                    ':stage' => $ev['stage'],
                    ':outcome' => $ev['outcome'],
                    ':note' => $emptyNote,
                    ':edate' => $ev['event_date'] ?? $l['contact_date'],
                ]);
                $eventsWritten++;
            }
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Import FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Imported leads: $leadUpsert\n";
echo "Stage events written: $eventsWritten\n";
$synced = lead_customer_group_backfill_user($conn, $userId, $key);
echo "Customer group rows synced: $synced\n";
echo "OK\n";
