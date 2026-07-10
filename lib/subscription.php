<?php
/**
 * แพ็กเกจ / trial / สิทธิ์ใช้งาน
 */

function subscription_ensure_schema($conn): void
{
    $chk = $conn->query("SHOW COLUMNS FROM users LIKE 'is_lifetime_free'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query(
            "ALTER TABLE users ADD COLUMN is_lifetime_free TINYINT UNSIGNED NOT NULL DEFAULT 0
             COMMENT 'ใช้ฟรีตลอดชีพ (ไม่หมด trial)' AFTER is_subscribed"
        );
    }
}

function user_has_full_access(array $user): bool
{
    if (!empty($user['is_subscribed'])) {
        return true;
    }
    if (!empty($user['is_lifetime_free'])) {
        return true;
    }
    if (empty($user['trial_ends_at'])) {
        return false;
    }
    return strtotime($user['trial_ends_at']) >= time();
}

function user_plan_badge(array $user): string
{
    return !empty($user['is_subscribed']) ? 'Pro' : 'Free';
}

function user_plan_subtext(array $user): string
{
    if (!empty($user['is_subscribed'])) {
        return '';
    }
    if (!empty($user['is_lifetime_free'])) {
        return 'ตลอดชีพ';
    }
    if (empty($user['trial_ends_at'])) {
        return '';
    }
    $days_left = max(0, (int)ceil((strtotime($user['trial_ends_at']) - time()) / 86400));
    return $days_left > 0 ? "เหลือ {$days_left} วัน" : 'หมดเวลาทดลอง';
}

/** @return array<string,mixed>|null  payload สำหรับ 402 ถ้าใช้งานไม่ได้ */
function subscription_deny_payload(array $user): ?array
{
    if (user_has_full_access($user)) {
        return null;
    }
    return [
        'success'       => false,
        'message'       => 'Trial period expired',
        'trial_ends_at' => $user['trial_ends_at'] ?? null,
    ];
}

function grant_lifetime_free($conn, int $userId): bool
{
    subscription_ensure_schema($conn);
    $stmt = $conn->prepare('UPDATE users SET is_lifetime_free = 1 WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $ok = $stmt->execute() && $stmt->affected_rows >= 0;
    $stmt->close();
    return $ok;
}
