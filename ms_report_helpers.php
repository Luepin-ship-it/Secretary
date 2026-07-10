<?php
// ms_report_helpers.php — รายงานการทำงานสำหรับสาขาเมทัลชีท

function ms_ensure_schema($conn) {
    $chk = $conn->query("SHOW COLUMNS FROM users LIKE 'sales_branch'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN sales_branch VARCHAR(30) DEFAULT 'real_estate' AFTER job_title");
    }
    $chk2 = $conn->query("SHOW COLUMNS FROM users LIKE 'sales_display_name'");
    if ($chk2 && $chk2->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN sales_display_name VARCHAR(100) DEFAULT NULL AFTER sales_branch");
    }

    $conn->query("CREATE TABLE IF NOT EXISTS ms_daily_stats (
        user_id INT NOT NULL,
        stat_date DATE NOT NULL,
        deposit_count INT NOT NULL DEFAULT 0,
        survey_count INT NOT NULL DEFAULT 0,
        collection_count INT NOT NULL DEFAULT 0,
        chat_line_count INT NOT NULL DEFAULT 0,
        chat_facebook_count INT NOT NULL DEFAULT 0,
        chat_tel_count INT NOT NULL DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, stat_date),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS ms_work_entries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        entry_type ENUM('deposit','delivery') NOT NULL DEFAULT 'deposit',
        entry_date DATE NOT NULL,
        customer_name_enc TEXT DEFAULT NULL,
        location_enc TEXT DEFAULT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        work_date DATE DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_ms_entry_user_date (user_id, entry_date),
        INDEX idx_ms_entry_type (user_id, entry_type, entry_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function ms_get_daily_stats($conn, $user_id, $date) {
    $stmt = $conn->prepare("SELECT * FROM ms_daily_stats WHERE user_id = ? AND stat_date = ? LIMIT 1");
    $stmt->bind_param("is", $user_id, $date);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return [
            'deposit_count' => 0,
            'survey_count' => 0,
            'collection_count' => 0,
            'chat_line_count' => 0,
            'chat_facebook_count' => 0,
            'chat_tel_count' => 0,
        ];
    }
    return $row;
}

function ms_save_daily_stats($conn, $user_id, $date, $data) {
    $deposit    = max(0, (int)($data['deposit_count'] ?? 0));
    $survey     = max(0, (int)($data['survey_count'] ?? 0));
    $collection = max(0, (int)($data['collection_count'] ?? 0));
    $line       = max(0, (int)($data['chat_line_count'] ?? 0));
    $facebook   = max(0, (int)($data['chat_facebook_count'] ?? 0));
    $tel        = max(0, (int)($data['chat_tel_count'] ?? 0));

    $stmt = $conn->prepare("INSERT INTO ms_daily_stats
        (user_id, stat_date, deposit_count, survey_count, collection_count, chat_line_count, chat_facebook_count, chat_tel_count)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
        deposit_count = VALUES(deposit_count),
        survey_count = VALUES(survey_count),
        collection_count = VALUES(collection_count),
        chat_line_count = VALUES(chat_line_count),
        chat_facebook_count = VALUES(chat_facebook_count),
        chat_tel_count = VALUES(chat_tel_count)");
    $stmt->bind_param("isiiiiii", $user_id, $date, $deposit, $survey, $collection, $line, $facebook, $tel);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function ms_add_entry($conn, $user_id, $key, $data) {
    $type = ($data['entry_type'] ?? 'deposit') === 'delivery' ? 'delivery' : 'deposit';
    $entry_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['entry_date'] ?? '') ? $data['entry_date'] : date('Y-m-d');
    $name = trim($data['customer_name'] ?? '');
    $location = trim($data['location'] ?? '');
    $amount = max(0, (float)str_replace(',', '', (string)($data['amount'] ?? 0)));
    $work_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['work_date'] ?? '') ? $data['work_date'] : null;

    $name_enc = $name !== '' ? encrypt_data($name, $key) : null;
    $loc_enc  = $location !== '' ? encrypt_data($location, $key) : null;

    $stmt = $conn->prepare("INSERT INTO ms_work_entries
        (user_id, entry_type, entry_date, customer_name_enc, location_enc, amount, work_date)
        VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("issssds", $user_id, $type, $entry_date, $name_enc, $loc_enc, $amount, $work_date);
    $ok = $stmt->execute();
    $id = $ok ? (int)$conn->insert_id : 0;
    $stmt->close();
    return $ok ? $id : 0;
}

function ms_entries_for_date($conn, $user_id, $key, $date) {
    $stmt = $conn->prepare("SELECT * FROM ms_work_entries WHERE user_id = ? AND entry_date = ? ORDER BY id DESC");
    $stmt->bind_param("is", $user_id, $date);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = [
            'id' => (int)$row['id'],
            'entry_type' => $row['entry_type'],
            'entry_date' => $row['entry_date'],
            'customer_name' => $row['customer_name_enc'] ? (string)decrypt_data($row['customer_name_enc'], $key) : '',
            'location' => $row['location_enc'] ? (string)decrypt_data($row['location_enc'], $key) : '',
            'amount' => (float)$row['amount'],
            'work_date' => $row['work_date'],
        ];
    }
    $stmt->close();
    return $rows;
}

function ms_month_summary($conn, $user_id, $year, $month) {
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end = date('Y-m-t', strtotime($start));

    $dep_stmt = $conn->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(amount),0) AS total
        FROM ms_work_entries WHERE user_id = ? AND entry_type = 'deposit' AND entry_date BETWEEN ? AND ?");
    $dep_stmt->bind_param("iss", $user_id, $start, $end);
    $dep_stmt->execute();
    $dep = $dep_stmt->get_result()->fetch_assoc();
    $dep_stmt->close();

    $del_stmt = $conn->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(amount),0) AS total
        FROM ms_work_entries WHERE user_id = ? AND entry_type = 'delivery' AND entry_date BETWEEN ? AND ?");
    $del_stmt->bind_param("iss", $user_id, $start, $end);
    $del_stmt->execute();
    $del = $del_stmt->get_result()->fetch_assoc();
    $del_stmt->close();

    return [
        'deposit_count' => (int)($dep['cnt'] ?? 0),
        'deposit_total' => (float)($dep['total'] ?? 0),
        'delivery_count' => (int)($del['cnt'] ?? 0),
        'delivery_total' => (float)($del['total'] ?? 0),
    ];
}

function ms_today_deposit_total($conn, $user_id, $date) {
    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS total FROM ms_work_entries
        WHERE user_id = ? AND entry_type = 'deposit' AND entry_date = ?");
    $stmt->bind_param("is", $user_id, $date);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (float)($row['total'] ?? 0);
}

function ms_build_report_text($sales_name, $date_str, $stats, $entries, $month_summary) {
    $d = branch_thai_short_date($date_str);
    $lines = [];
    $lines[] = "สรุปรายงานการทำงาน {$d} 🔹{$sales_name}🔹";
    $lines[] = '';
    $lines[] = 'มัดจำ (เคาท์ดาวน์) ' . (int)($stats['deposit_count'] ?? 0) . ' คน';
    $lines[] = 'นัดคิวสำรวจหน้างาน (' . (int)($stats['survey_count'] ?? 0) . ') คน';
    $lines[] = 'ส่งงานลูกค้าเก็บเงิน (' . (int)($stats['collection_count'] ?? 0) . ') คน';
    $lines[] = '';
    $lines[] = 'ตอบแชทลูกค้า';
    $lines[] = 'Line : (' . (int)($stats['chat_line_count'] ?? 0) . ') คน';
    $lines[] = 'Facebook : (' . (int)($stats['chat_facebook_count'] ?? 0) . ') คน';
    $lines[] = 'Tel : (' . (int)($stats['chat_tel_count'] ?? 0) . ') คน';

    $today_total = 0;
    foreach ($entries as $e) {
        if ($e['entry_type'] === 'deposit') {
            $today_total += (float)$e['amount'];
        }
    }
    if ($today_total > 0 || count($entries) > 0) {
        $lines[] = '';
        $lines[] = 'วันนี้รับมัดจำรวม ฿' . number_format($today_total, 0);
        foreach ($entries as $e) {
            if ($e['entry_type'] !== 'deposit') continue;
            $wn = $e['work_date'] ? branch_thai_short_date($e['work_date']) : '-';
            $lines[] = '· ' . ($e['customer_name'] ?: '-') . ' | ' . ($e['location'] ?: '-') . ' | ฿' . number_format($e['amount'], 0) . ' | ลงงาน ' . $wn;
        }
    }

    $lines[] = '';
    $lines[] = 'สรุปเดือนนี้';
    $lines[] = 'มัดจำ ' . (int)$month_summary['deposit_count'] . ' คน · ฿' . number_format($month_summary['deposit_total'], 0);
    $lines[] = 'ส่งมอบงาน ' . (int)$month_summary['delivery_count'] . ' คน · ฿' . number_format($month_summary['delivery_total'], 0);

    return implode("\n", $lines);
}
