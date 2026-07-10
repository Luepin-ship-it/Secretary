<?php
/**
 * อัปโหลดรูปทรัพย์ (staging) — เรียงลำดับ → ตั้งชื่อ 1, 2, 3…
 */

function owner_photos_ensure_schema(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS owner_photo_files (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        owner_code VARCHAR(32) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        file_name VARCHAR(64) NOT NULL,
        storage_path VARCHAR(512) NOT NULL,
        mime VARCHAR(64) DEFAULT NULL,
        finalized TINYINT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_user_code (user_id, owner_code),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function owner_photos_root_dir(): string
{
    return dirname(__DIR__) . '/uploads/owner_photos';
}

function owner_photos_sanitize_code(string $code): string
{
    $code = strtoupper(trim($code));
    if ($code === '' || !preg_match('/^[A-Z0-9_-]{2,24}$/', $code)) {
        return '';
    }
    return $code;
}

function owner_photos_dir(int $user_id, string $code): string
{
    $code = owner_photos_sanitize_code($code);
    if ($code === '') {
        return '';
    }
    return owner_photos_root_dir() . '/' . $user_id . '/' . $code;
}

function owner_photos_ext_from_mime(string $mime): string
{
    return match ($mime) {
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        default => 'jpg',
    };
}

/** @return list<array{id:int,sort_order:int,file_name:string,url:string,mime:string}> */
function owner_photos_list(mysqli $conn, int $user_id, string $code): array
{
    owner_photos_ensure_schema($conn);
    $code = owner_photos_sanitize_code($code);
    if ($code === '') {
        return [];
    }
    $stmt = $conn->prepare(
        'SELECT id, sort_order, file_name, mime, finalized FROM owner_photo_files
         WHERE user_id = ? AND owner_code = ? ORDER BY sort_order ASC, id ASC'
    );
    $stmt->bind_param('is', $user_id, $code);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = [
            'id' => (int)$row['id'],
            'sort_order' => (int)$row['sort_order'],
            'file_name' => $row['file_name'],
            'mime' => $row['mime'] ?? 'image/jpeg',
            'finalized' => (int)$row['finalized'],
            'url' => owner_photos_public_url($user_id, $code, $row['file_name']),
        ];
    }
    $stmt->close();
    return $rows;
}

function owner_photos_public_url(int $user_id, string $code, string $file_name): string
{
    $base = function_exists('build_public_base_url') ? build_public_base_url() : '';
    if ($base === '' && function_exists('auth_base_url')) {
        $base = auth_base_url();
    }
    $sig = owner_photos_sign($user_id, $code, $file_name);
    $q = http_build_query([
        'u' => $user_id,
        'c' => $code,
        'f' => $file_name,
        'sig' => $sig,
    ], '', '&', PHP_QUERY_RFC3986);
    return rtrim($base, '/') . '/owner_photo.php?' . $q;
}

function owner_photos_sign(int $user_id, string $code, string $file_name): string
{
    $secret = defined('DB_PASS') ? DB_PASS : (defined('LINE_ACCESS_TOKEN') ? LINE_ACCESS_TOKEN : 'owner-photos');
    return substr(hash_hmac('sha256', "{$user_id}:{$code}:{$file_name}", $secret), 0, 16);
}

function owner_photos_verify_sign(int $user_id, string $code, string $file_name, string $sig): bool
{
    return hash_equals(owner_photos_sign($user_id, $code, $file_name), $sig);
}

/** @return array{ok:bool,message?:string,id?:int,url?:string} */
function owner_photos_upload(mysqli $conn, int $user_id, string $code, array $file): array
{
    owner_photos_ensure_schema($conn);
    $code = owner_photos_sanitize_code($code);
    if ($code === '') {
        return ['ok' => false, 'message' => 'invalid_code'];
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'upload_error'];
    }
    $tmp = $file['tmp_name'] ?? '';
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'message' => 'invalid_file'];
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, $tmp) : '';
    if ($finfo) {
        finfo_close($finfo);
    }
    if (!str_starts_with((string)$mime, 'image/')) {
        return ['ok' => false, 'message' => 'not_image'];
    }
    if (($file['size'] ?? 0) > 12 * 1024 * 1024) {
        return ['ok' => false, 'message' => 'too_large'];
    }

    $dir = owner_photos_dir($user_id, $code);
    if ($dir === '') {
        return ['ok' => false, 'message' => 'invalid_code'];
    }
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        return ['ok' => false, 'message' => 'mkdir_failed'];
    }

    $ext = owner_photos_ext_from_mime((string)$mime);
    $tmpName = 'tmp_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = $dir . '/' . $tmpName;
    if (!move_uploaded_file($tmp, $dest)) {
        return ['ok' => false, 'message' => 'save_failed'];
    }

    $stmt = $conn->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 AS nxt FROM owner_photo_files WHERE user_id = ? AND owner_code = ?');
    $stmt->bind_param('is', $user_id, $code);
    $stmt->execute();
    $nxt = (int)($stmt->get_result()->fetch_assoc()['nxt'] ?? 1);
    $stmt->close();

    $rel = $user_id . '/' . $code . '/' . $tmpName;
    $stmt = $conn->prepare(
        'INSERT INTO owner_photo_files (user_id, owner_code, sort_order, file_name, storage_path, mime, finalized)
         VALUES (?, ?, ?, ?, ?, ?, 0)'
    );
    $stmt->bind_param('isisss', $user_id, $code, $nxt, $tmpName, $rel, $mime);
    $stmt->execute();
    $id = (int)$conn->insert_id;
    $stmt->close();

    return [
        'ok' => true,
        'id' => $id,
        'url' => owner_photos_public_url($user_id, $code, $tmpName),
        'file_name' => $tmpName,
    ];
}

/** @param list<int> $ordered_ids */
function owner_photos_reorder(mysqli $conn, int $user_id, string $code, array $ordered_ids): bool
{
    owner_photos_ensure_schema($conn);
    $code = owner_photos_sanitize_code($code);
    if ($code === '' || $ordered_ids === []) {
        return false;
    }
    $order = 1;
    foreach ($ordered_ids as $id) {
        $id = (int)$id;
        $stmt = $conn->prepare(
            'UPDATE owner_photo_files SET sort_order = ? WHERE id = ? AND user_id = ? AND owner_code = ?'
        );
        $stmt->bind_param('iiis', $order, $id, $user_id, $code);
        $stmt->execute();
        $stmt->close();
        $order++;
    }
    return true;
}

/** ตั้งชื่อไฟล์ 1.jpg, 2.jpg … ตามลำดับ */
function owner_photos_finalize(mysqli $conn, int $user_id, string $code): array
{
    owner_photos_ensure_schema($conn);
    $code = owner_photos_sanitize_code($code);
    if ($code === '') {
        return ['ok' => false, 'message' => 'invalid_code'];
    }
    $rows = owner_photos_list($conn, $user_id, $code);
    if ($rows === []) {
        return ['ok' => false, 'message' => 'no_photos'];
    }

    $dir = owner_photos_dir($user_id, $code);
    $n = 1;
    $renamed = [];
    foreach ($rows as $row) {
        $ext = owner_photos_ext_from_mime($row['mime']);
        $newName = $n . '.' . $ext;
        $oldPath = $dir . '/' . $row['file_name'];
        $newPath = $dir . '/' . $newName;
        if (is_file($oldPath)) {
            if ($oldPath !== $newPath) {
                if (is_file($newPath)) {
                    @unlink($newPath);
                }
                rename($oldPath, $newPath);
            }
        }
        $rel = $user_id . '/' . $code . '/' . $newName;
        $stmt = $conn->prepare(
            'UPDATE owner_photo_files SET file_name = ?, storage_path = ?, sort_order = ?, finalized = 1 WHERE id = ? AND user_id = ?'
        );
        $stmt->bind_param('ssiii', $newName, $rel, $n, $row['id'], $user_id);
        $stmt->execute();
        $stmt->close();
        $renamed[] = [
            'n' => $n,
            'file_name' => $newName,
            'url' => owner_photos_public_url($user_id, $code, $newName),
        ];
        $n++;
    }

    return ['ok' => true, 'photos' => $renamed, 'count' => count($renamed)];
}

function owner_photos_cover_url(mysqli $conn, int $user_id, string $code): string
{
    $rows = owner_photos_list($conn, $user_id, $code);
    if ($rows === []) {
        return '';
    }
    foreach ($rows as $row) {
        if (preg_match('/^1\./', $row['file_name'])) {
            return $row['url'];
        }
    }
    return $rows[0]['url'];
}

function owner_photos_apply_to_owner(mysqli $conn, int $user_id, string $code, int $owner_id): void
{
    $cover = owner_photos_cover_url($conn, $user_id, $code);
    if ($cover === '' || $owner_id <= 0) {
        return;
    }
    $stmt = $conn->prepare('UPDATE owners SET cover_image_url = ? WHERE id = ? AND user_id = ?');
    $stmt->bind_param('sii', $cover, $owner_id, $user_id);
    $stmt->execute();
    $stmt->close();
}

function owner_photos_delete_row(mysqli $conn, int $user_id, string $code, int $photo_id): bool
{
    $code = owner_photos_sanitize_code($code);
    $stmt = $conn->prepare('SELECT storage_path, file_name FROM owner_photo_files WHERE id = ? AND user_id = ? AND owner_code = ?');
    $stmt->bind_param('iis', $photo_id, $user_id, $code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return false;
    }
    $path = owner_photos_root_dir() . '/' . $row['storage_path'];
    if (is_file($path)) {
        @unlink($path);
    }
    $stmt = $conn->prepare('DELETE FROM owner_photo_files WHERE id = ? AND user_id = ?');
    $stmt->bind_param('ii', $photo_id, $user_id);
    $stmt->execute();
    $stmt->close();
    return true;
}
