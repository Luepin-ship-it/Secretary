<?php
/**
 * CLI: Import owner xlsx → owners table (literal header mapping for clean sheet)
 *
 * Usage:
 *   php tools/import_tan_xlsx.php "path/to/file.xlsx" --line-id=Uxxx [--dry-run]
 *   [--lead-default-status=Win] [--lead-code-prefix=WIN]
 *   [--drive-root=FOLDER_ID] [--skip-cover]
 *
 * Cover (ตอน import เท่านั้น):
 *   - ลิงก์โฟลเดอร์ Drive ใน photos_link → รูปแรกในโฟลเดอร์ (โฟลเดอร์แชร์ Anyone with link ได้เลย)
 *   - รหัสโฟลเดอร์ (Tan036) + --drive-root + GOOGLE_DRIVE_API_KEY → หารูปแรกใน subfolder
 *   ไม่ยิง Google API ตอนแสดง dashboard
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/tan_workbook_import.php';
require_once dirname(__DIR__) . '/lib/gdrive_cover_resolver.php';
require_once dirname(__DIR__) . '/lib/contact_normalize.php';
require_once dirname(__DIR__) . '/lib/map_coords.php';

$path = $argv[1] ?? '';
$dryRun = in_array('--dry-run', $argv, true);
$skipCover = in_array('--skip-cover', $argv, true);
$lineId = '';
$driveRoot = '';
$leadDefaultStatus = '';
$leadCodePrefix = 'LEAD';
foreach ($argv as $arg) {
    if (strpos($arg, '--line-id=') === 0) {
        $lineId = substr($arg, 10);
    }
    if (strpos($arg, '--drive-root=') === 0) {
        $driveRoot = substr($arg, 13);
    }
    if (strpos($arg, '--lead-default-status=') === 0) {
        $leadDefaultStatus = substr($arg, 22);
    }
    if (strpos($arg, '--lead-code-prefix=') === 0) {
        $leadCodePrefix = substr($arg, 19);
    }
}

if ($path === '' || !is_readable($path)) {
    fwrite(STDERR, "Usage: php tools/import_tan_xlsx.php <xlsx-path> --line-id=Uxxxx [--dry-run]\n");
    exit(1);
}

echo "Reading: $path\n";
$loadOpts = [];
if ($leadDefaultStatus !== '') {
    $loadOpts['leads'] = [
        'default_status' => $leadDefaultStatus,
        'lead_code_prefix' => $leadCodePrefix,
    ];
}
$data = TanWorkbookImport::load($path, $loadOpts);

$owners = $data['owners'];
$leads = $data['leads'];

echo "Format: " . ($data['format'] ?? 'unknown');
if (!empty($data['sheet'])) {
    echo " (sheet: {$data['sheet']})";
}
echo "\n";
echo "Owners parsed: " . count($owners) . "\n";
echo "Leads parsed: " . count($leads) . "\n";
if (($data['format'] ?? '') === 'tan_workbook') {
    echo "Owner Focus matched: {$data['focus_matched']} / {$data['focus_total']}\n";
}
echo "\n";

if ($owners) {
    echo "Sample owners:\n";
    foreach (array_slice($owners, 0, 3, true) as $o) {
        $th = $o['project_name_th'] ?? $o['project'] ?? '';
        $en = $o['project_name_en'] ?? '';
        $name = $o['owner_name'] ?? '';
        echo "  {$o['code_list']} | {$name} | {$o['sales_status']} | TH={$th} EN={$en}\n";
    }
    echo "\n";
}

$driveApiKey = defined('GOOGLE_DRIVE_API_KEY') ? (string)GOOGLE_DRIVE_API_KEY : '';
$coverResolved = 0;
$coverCandidates = 0;
foreach ($owners as $o) {
    if (TanWorkbookImport::shouldSkipImportRow($o['code_list'] ?? '')) {
        continue;
    }
    $photos = trim($o['photos_link'] ?? '');
    if ($photos === '' && ($driveRoot === '')) {
        continue;
    }
    $coverCandidates++;
    if ($skipCover) {
        continue;
    }
    $cover = GdriveCoverResolver::resolveAtImport(
        $photos,
        $o['code_list'] ?? '',
        $driveRoot !== '' ? $driveRoot : null,
        $driveApiKey !== '' ? $driveApiKey : null
    );
    if ($cover !== null) {
        $coverResolved++;
    }
}
if (!$skipCover) {
    echo "Cover resolve (preview): $coverResolved / $coverCandidates candidates\n";
    if ($driveRoot === '' && $driveApiKey === '') {
        echo "  (folder URLs in photos_link work if folder is shared Anyone with the link)\n";
    }
    echo "\n";
}

if ($dryRun) {
    echo "Dry-run only — no database writes.\n";
    exit(0);
}

if ($lineId === '') {
    fwrite(STDERR, "Import requires --line-id=Uxxxx (registered user)\n");
    exit(1);
}

$stmt = $conn->prepare("SELECT id, encryption_key, google_drive_id FROM users WHERE line_user_id = ? LIMIT 1");
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
if ($driveRoot === '') {
    $driveRoot = trim($user['google_drive_id'] ?? '');
}
$driveApiKey = defined('GOOGLE_DRIVE_API_KEY') ? trim((string)GOOGLE_DRIVE_API_KEY) : '';
$ownerUpsert = 0;
$leadUpsert = 0;
$skippedDit = 0;
$coversSet = 0;

function enc_or_null($val, $key) {
    return ($val === null || $val === '') ? null : encrypt_data($val, $key);
}

foreach ($owners as $o) {
    $code = $o['code_list'];
    if (TanWorkbookImport::shouldSkipImportRow($code)) {
        $skippedDit++;
        continue;
    }

    $isClean = isset($o['project_name_th']) || isset($o['project_name_en']);

    $ownerName = $o['owner_name'] ?? '';
    $projectTh = $isClean ? ($o['project_name_th'] ?? '') : '';
    $projectEn = $isClean ? ($o['project_name_en'] ?? '') : ($o['project'] ?? '');
    $projectLegacy = $projectEn !== '' ? $projectEn : $projectTh;

    $ownerNameEnc = enc_or_null($ownerName, $key);
    $projectThEnc = enc_or_null($projectTh, $key);
    $projectEnEnc = enc_or_null($projectEn, $key);
    $projectEnc = enc_or_null($projectLegacy, $key);
    $repairedContact = repair_owner_contacts([
        'phone'   => $o['phone'] ?? '',
        'line_id' => $o['line_id'] ?? '',
        'zone'    => $o['zone'] ?? '',
    ]);
    $phoneEnc = enc_or_null($repairedContact['phone'], $key);
    $lineEnc = enc_or_null($repairedContact['line_id'], $key);
    $propEnc = enc_or_null($o['property_type'] ?? '', $key);
    $zoneEnc = enc_or_null($repairedContact['zone'] ?? ($o['zone'] ?? ''), $key);
    $gradeEnc = enc_or_null($o['location_grade'] ?? '', $key);
    $btsEnc = enc_or_null($o['bts_mrt_srt'] ?? '', $key);
    $bedEnc = enc_or_null($o['bed'] ?? '', $key);
    $bathEnc = enc_or_null($o['bath'] ?? '', $key);
    $unitEnc = enc_or_null($o['unit_no'] ?? '', $key);
    $sqwaEnc = enc_or_null($o['area_sqwa'] ?? '', $key);
    $sqmEnc = enc_or_null($o['area_sqm'] ?? '', $key);
    $raiEnc = enc_or_null($o['area_rai'] ?? '', $key);
    $nganEnc = enc_or_null($o['area_ngan'] ?? '', $key);
    $floorEnc = enc_or_null($o['floor'] ?? '', $key);
    $parkEnc = enc_or_null($o['parking'] ?? '', $key);
    $dirEnc = enc_or_null($o['direction'] ?? '', $key);
    $askEnc = enc_or_null($o['asking_price'] ?? '', $key);
    $rentEnc = enc_or_null($o['rental_price'] ?? '', $key);
    $mapEnc = enc_or_null($o['map_url'] ?? '', $key);
    [$ownerLat, $ownerLng] = map_coords_for_import(trim($o['map_url'] ?? ''));
    $photosEnc = enc_or_null($o['photos_link'] ?? '', $key);
    $incEnc = enc_or_null($isClean ? ($o['price_remark'] ?? '') : ($o['incomplete_details'] ?? ''), $key);
    $contactEnc = enc_or_null($o['contact_summary'] ?? '', $key);
    $ownerAskEnc = enc_or_null($o['net_price'] ?? '', $key);
    $timelineEnc = enc_or_null($o['months_on_sale'] ?? ($o['selling_timeline'] ?? ''), $key);
    $consultEnc = enc_or_null($o['months_to_sold'] ?? '', $key);
    $reasonEnc = enc_or_null($o['closing_project'] ?? ($o['selling_reason'] ?? ''), $key);
    $soldPriceEnc = enc_or_null($o['closing_price'] ?? ($o['sold_price'] ?? ''), $key);

    $coverUrl = null;
    if (!$skipCover) {
        $coverUrl = GdriveCoverResolver::resolveAtImport(
            $o['photos_link'] ?? '',
            $code,
            $driveRoot !== '' ? $driveRoot : null,
            $driveApiKey !== '' ? $driveApiKey : null
        );
        if ($coverUrl !== null) {
            $coversSet++;
        }
    }

    $listingDate = $o['listing_date'] ?? date('Y-m-d');
    $marketingStatus = $o['marketing_status'] ?? 'ลงการตลาดแล้ว';
    $marketingDate = $o['marketing_date'] ?? null;
    $lastContact = $o['last_contact_date'] ?? null;
    $salesStatus = $o['sales_status'] ?? 'Sale';
    $avail = $o['availability_status'] ?? 'ยังขายอยู่';
    $urgency = trim($o['owner_urgency'] ?? '');
    if ($urgency === '') {
        $urgency = null;
    }
    $sellCond = $o['selling_condition'] ?? '50/50 Transfer Fee';

    $chk = $conn->prepare('SELECT id FROM owners WHERE user_id = ? AND code_list = ? LIMIT 1');
    $chk->bind_param('is', $userId, $code);
    $chk->execute();
    $exists = $chk->get_result()->fetch_assoc();
    $chk->close();

    if ($exists) {
        $sql = 'UPDATE owners SET
            owner_name_enc=?, project_name_th_enc=?, project_name_en_enc=?, project_enc=?,
            listing_date=?, marketing_status=?, marketing_date=?, property_type_enc=?,
            phone_enc=?, line_id_enc=?, zone_enc=?, location_grade_enc=?, bts_mrt_srt_enc=?,
            bed_enc=?, bath_enc=?, unit_no_enc=?, area_rai_enc=?, area_ngan_enc=?, area_sqwa_enc=?, area_sqm_enc=?,
            floor_enc=?, parking_enc=?, direction_enc=?, asking_price_enc=?, rental_price_enc=?, owner_asking_price_enc=?,
            map_url_enc=?, photos_link_enc=?, incomplete_details_enc=?, contact_summary_enc=?, last_contact_date=?,
            selling_timeline_enc=?, price_consult_enc=?, selling_reason_enc=?, sold_price_enc=?,
            availability_status=?, sales_status=?, owner_urgency=?, selling_condition=?,
            lat=?, lng=?, cover_image_url=COALESCE(?, cover_image_url)
            WHERE id=?';
        $st = $conn->prepare($sql);
        $st->bind_param(
            'sssssssssssssssssssssssssssssssssssssssddsi',
            $ownerNameEnc, $projectThEnc, $projectEnEnc, $projectEnc,
            $listingDate, $marketingStatus, $marketingDate, $propEnc,
            $phoneEnc, $lineEnc, $zoneEnc, $gradeEnc, $btsEnc,
            $bedEnc, $bathEnc, $unitEnc, $raiEnc, $nganEnc, $sqwaEnc, $sqmEnc,
            $floorEnc, $parkEnc, $dirEnc, $askEnc, $rentEnc, $ownerAskEnc,
            $mapEnc, $photosEnc, $incEnc, $contactEnc, $lastContact,
            $timelineEnc, $consultEnc, $reasonEnc, $soldPriceEnc,
            $avail, $salesStatus, $urgency, $sellCond,
            $ownerLat, $ownerLng, $coverUrl,
            $exists['id']
        );
    } else {
        $sql = 'INSERT INTO owners (
            user_id, code_list, owner_name_enc, project_name_th_enc, project_name_en_enc, project_enc,
            listing_date, marketing_status, marketing_date, property_type_enc,
            phone_enc, line_id_enc, zone_enc, location_grade_enc, bts_mrt_srt_enc,
            bed_enc, bath_enc, unit_no_enc, area_rai_enc, area_ngan_enc, area_sqwa_enc, area_sqm_enc,
            floor_enc, parking_enc, direction_enc, asking_price_enc, rental_price_enc, owner_asking_price_enc,
            map_url_enc, photos_link_enc, incomplete_details_enc, contact_summary_enc, last_contact_date,
            selling_timeline_enc, price_consult_enc, selling_reason_enc, sold_price_enc,
            availability_status, sales_status, owner_urgency, selling_condition, lat, lng, cover_image_url
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
        $st = $conn->prepare($sql);
        $st->bind_param(
            'issssssssssssssssssssssssssssssssssssssdds',
            $userId, $code, $ownerNameEnc, $projectThEnc, $projectEnEnc, $projectEnc,
            $listingDate, $marketingStatus, $marketingDate, $propEnc,
            $phoneEnc, $lineEnc, $zoneEnc, $gradeEnc, $btsEnc,
            $bedEnc, $bathEnc, $unitEnc, $raiEnc, $nganEnc, $sqwaEnc, $sqmEnc,
            $floorEnc, $parkEnc, $dirEnc, $askEnc, $rentEnc, $ownerAskEnc,
            $mapEnc, $photosEnc, $incEnc, $contactEnc, $lastContact,
            $timelineEnc, $consultEnc, $reasonEnc, $soldPriceEnc,
            $avail, $salesStatus, $urgency, $sellCond, $ownerLat, $ownerLng, $coverUrl
        );
    }
    if ($st->execute()) {
        $ownerUpsert++;
        if ($ownerLat !== null && $ownerLng !== null) {
            owner_sync_lead_coords($conn, $userId, $code, $ownerLat, $ownerLng);
        }
    }
    $st->close();
}

foreach ($leads as $l) {
    $code = lead_code_for_display($l['lead_code'] ?? '');
    if ($code === '') {
        continue;
    }
    $repairedContact = repair_owner_contacts(['phone' => $l['phone'] ?? '', 'line_id' => $l['line_id'] ?? '']);
    $nameEnc = encrypt_data($l['lead_name'], $key);
    $projEnc = enc_or_null($l['project'] ?? '', $key);
    $phoneEnc = enc_or_null($repairedContact['phone'], $key);
    $lineEnc = enc_or_null($repairedContact['line_id'], $key);
    $budgetEnc = enc_or_null($l['budget'] ?? '', $key);
    $bgEnc = enc_or_null($l['background'] ?? '', $key);
    $painEnc = enc_or_null($l['pain_point'] ?? '', $key);
    $reqEnc = enc_or_null($l['requirement'] ?? '', $key);
    $finEnc = enc_or_null($l['financials'] ?? '', $key);
    $timelineEnc = enc_or_null($l['target_date'] ?? '', $key);
    $updEnc = enc_or_null($l['current_update'] ?? '', $key);
    $nextEnc = enc_or_null($l['next_plan_action'] ?? '', $key);
    $winPriceEnc = enc_or_null($l['win_price'] ?? '', $key);
    $nextPlanDate = $l['next_plan_date'] ?? null;
    $winDate = $l['win_date'] ?? null;
    $status = TanWorkbookImport::normalizeLeadStatus($l['status'] ?? 'Call');

    $chk = $conn->prepare('SELECT id FROM leads WHERE user_id = ? AND lead_code = ? LIMIT 1');
    $chk->bind_param('is', $userId, $code);
    $chk->execute();
    $exists = $chk->get_result()->fetch_assoc();
    $chk->close();

    if ($exists) {
        $sql = 'UPDATE leads SET lead_name_enc=?, project_enc=?, phone_enc=?, line_id_enc=?, budget_enc=?,
            potential=?, contact_date=?, background_enc=?, pain_point_enc=?, requirement_enc=?, financials_enc=?,
            target_date_enc=?, current_update_enc=?, next_plan_action_enc=?, next_plan_date=?, status=?, owner_code=?,
            win_date=?, win_price_enc=? WHERE id=?';
        $st = $conn->prepare($sql);
        $st->bind_param(
            'ssssssssssssssssssi',
            $nameEnc, $projEnc, $phoneEnc, $lineEnc, $budgetEnc,
            $l['potential'], $l['contact_date'], $bgEnc, $painEnc, $reqEnc, $finEnc,
            $timelineEnc, $updEnc, $nextEnc, $nextPlanDate, $status, $l['owner_code'],
            $winDate, $winPriceEnc, $exists['id']
        );
    } else {
        $sql = 'INSERT INTO leads (user_id, lead_code, lead_name_enc, project_enc, phone_enc, line_id_enc, budget_enc,
            potential, contact_date, background_enc, pain_point_enc, requirement_enc, financials_enc,
            target_date_enc, current_update_enc, next_plan_action_enc, next_plan_date, status, owner_code,
            win_date, win_price_enc) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
        $st = $conn->prepare($sql);
        $st->bind_param(
            'issssssssssssssssssss',
            $userId, $code, $nameEnc, $projEnc, $phoneEnc, $lineEnc, $budgetEnc,
            $l['potential'], $l['contact_date'], $bgEnc, $painEnc, $reqEnc, $finEnc,
            $timelineEnc, $updEnc, $nextEnc, $nextPlanDate, $status, $l['owner_code'],
            $winDate, $winPriceEnc
        );
    }
    if ($st->execute()) {
        $leadUpsert++;
    }
    $st->close();
}

echo "Imported owners: $ownerUpsert\n";
echo "Cover images set: $coversSet\n";
echo "Imported leads: $leadUpsert\n";
