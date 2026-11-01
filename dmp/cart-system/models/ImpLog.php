<?php

/**
 * @file   models/ImpLog.php
 * @brief  Audit log for Super-Login (impersonation) sessions
 */
class ImpLog
{
    /**
     * Record a new impersonation session. Only the SHA-256 hash of the
     * session nonce is persisted — never the raw URL token.
     *
     * @return int  impersonation_log.id
     */
    public static function record(
        int $superadminId,
        string $superadminName,
        int $targetUserId,
        string $targetUsername,
        string $nonce,
        string $ip,
        string $ua,
        int $expiresAt
    ): int {
        $st = db()->prepare('
            INSERT INTO impersonation_log
              (superadmin_id, superadmin_name, target_user_id, target_username,
               nonce_hash, ip, user_agent, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $st->execute([
            $superadminId,
            $superadminName,
            $targetUserId,
            $targetUsername,
            hash('sha256', $nonce),
            $ip,
            substr($ua, 0, 255),
            date('Y-m-d H:i:s', $expiresAt),
        ]);
        return (int) db()->lastInsertId();
    }

    public static function mark(int $id, string $status): void
    {
        if ($id <= 0) return;
        $st = db()->prepare('UPDATE impersonation_log SET status = ? WHERE id = ?');
        $st->execute([$status, $id]);
    }
}