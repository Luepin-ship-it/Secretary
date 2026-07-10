<?php
/**
 * ดึง Owner/Lead ในขอบเขตแผนที่ (bounding box) สำหรับหน้า Map
 */

function map_bbox_normalize(array $bbox): ?array
{
    $north = (float)($bbox['north'] ?? 0);
    $south = (float)($bbox['south'] ?? 0);
    $east = (float)($bbox['east'] ?? 0);
    $west = (float)($bbox['west'] ?? 0);
    if ($north <= $south || $east <= $west) {
        return null;
    }
    if ($north > 90 || $south < -90 || $east > 180 || $west < -180) {
        return null;
    }
    return compact('north', 'south', 'east', 'west');
}

function map_bbox_fetch_owners(mysqli $conn, int $userId, array $bbox, int $limit = 400): array
{
    $bbox = map_bbox_normalize($bbox);
    if (!$bbox) {
        return [];
    }
    $limit = max(1, min(800, $limit));
    $sql = "SELECT * FROM owners
            WHERE user_id = ?
              AND (availability_status IS NULL OR availability_status != 'ยกเลิกการขาย')
              AND lat IS NOT NULL AND lng IS NOT NULL
              AND lat != 0 AND lng != 0
              AND lat BETWEEN ? AND ?
              AND lng BETWEEN ? AND ?
            ORDER BY updated_at DESC
            LIMIT {$limit}";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        'idddd',
        $userId,
        $bbox['south'],
        $bbox['north'],
        $bbox['west'],
        $bbox['east']
    );
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function map_bbox_lead_status_sql(): string
{
    return "status NOT IN ('Rejected','Hold_Reject','Lose')";
}

/** @return array{from:?string,to:?string}|null */
function map_bbox_lead_window_from_request(array $post): ?array
{
    $mode = trim((string)($post['lead_mode'] ?? 'preset'));
    if ($mode === 'all') {
        return null;
    }
    if ($mode === 'range') {
        $from = trim((string)($post['lead_from'] ?? ''));
        $to = trim((string)($post['lead_to'] ?? ''));
        if ($from === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            return null;
        }
        if ($to === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $to = date('Y-m-d');
        }
        return ['from' => $from, 'to' => $to];
    }
    $days = max(1, min(365, (int)($post['lead_days'] ?? 30)));
    $to = date('Y-m-d');
    $from = date('Y-m-d', strtotime($to . ' -' . ($days - 1) . ' days'));
    return ['from' => $from, 'to' => $to];
}

function map_bbox_fetch_leads(mysqli $conn, int $userId, array $bbox, ?array $dateWindow, int $limit = 600): array
{
    $bbox = map_bbox_normalize($bbox);
    if (!$bbox) {
        return [];
    }
    $limit = max(1, min(1000, $limit));
    $statusSql = map_bbox_lead_status_sql();
    $sql = "SELECT * FROM leads
            WHERE user_id = ?
              AND {$statusSql}
              AND lat IS NOT NULL AND lng IS NOT NULL
              AND lat != 0 AND lng != 0
              AND lat BETWEEN ? AND ?
              AND lng BETWEEN ? AND ?";
    $types = 'idddd';
    $params = [$userId, $bbox['south'], $bbox['north'], $bbox['west'], $bbox['east']];
    if ($dateWindow) {
        $sql .= " AND COALESCE(NULLIF(contact_date,'0000-00-00'), DATE(created_at)) BETWEEN ? AND ?";
        $types .= 'ss';
        $params[] = $dateWindow['from'];
        $params[] = $dateWindow['to'];
    }
    $sql .= " ORDER BY contact_date DESC, updated_at DESC LIMIT {$limit}";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function map_bbox_fetch_owners_by_codes(mysqli $conn, int $userId, array $codes): array
{
    $codes = array_values(array_unique(array_filter(array_map('strval', $codes))));
    if (!$codes) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    $sql = "SELECT * FROM owners WHERE user_id = ? AND code_list IN ({$placeholders})";
    $stmt = $conn->prepare($sql);
    $types = 'i' . str_repeat('s', count($codes));
    $params = array_merge([$userId], $codes);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function map_bbox_filter_projects(array $projects, array $bbox): array
{
    $bbox = map_bbox_normalize($bbox);
    if (!$bbox) {
        return [];
    }
    return array_values(array_filter($projects, static function ($p) use ($bbox) {
        $lat = (float)($p['lat'] ?? 0);
        $lng = (float)($p['lng'] ?? 0);
        if ($lat == 0.0 || $lng == 0.0) {
            return false;
        }
        return $lat >= $bbox['south'] && $lat <= $bbox['north']
            && $lng >= $bbox['west'] && $lng <= $bbox['east'];
    }));
}

function map_meta_counts(mysqli $conn, int $userId): array
{
    $ownerTotal = 0;
    $leadTotal = 0;
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM owners WHERE user_id = ? AND (availability_status IS NULL OR availability_status != 'ยกเลิกการขาย')");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $ownerTotal = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    $statusSql = map_bbox_lead_status_sql();
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM leads WHERE user_id = ? AND {$statusSql}");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $leadTotal = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    return ['owner_total' => $ownerTotal, 'lead_total' => $leadTotal];
}

function map_meta_center(mysqli $conn, int $userId): array
{
    $stmt = $conn->prepare(
        "SELECT AVG(lat) lat, AVG(lng) lng FROM owners
         WHERE user_id = ? AND lat IS NOT NULL AND lng IS NOT NULL AND lat != 0 AND lng != 0"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row && $row['lat'] !== null && $row['lng'] !== null) {
        return ['lat' => (float)$row['lat'], 'lng' => (float)$row['lng']];
    }
    return ['lat' => 13.7563, 'lng' => 100.5018];
}
