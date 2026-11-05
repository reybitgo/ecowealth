<?php
declare(strict_types=1);

/* ==============================================================
 *  AuditLog — admin / money actions (plan §4.2, §3.7)
 *
 *  One append-only row per audited action. Phase 5 writes its first
 *  entries (admin wallet top-ups); Phase 15 renders the full log.
 *
 *  There is deliberately no update/delete method: an audit trail that
 *  can be edited is not an audit trail.
 * ============================================================== */

final class AuditLog
{
    /**
     * Record one audited action.
     *
     * @param array{
     *   action: string,
     *   actor_id?: int|null,
     *   actor_role?: string|null,
     *   subject_type?: string|null,
     *   subject_id?: int|null,
     *   meta?: array<string, mixed>|null,
     *   ip?: string|null
     * } $entry
     */
    public static function record(array $entry, ?PDO $pdo = null): int
    {
        $pdo ??= db();

        $action = trim((string) ($entry['action'] ?? ''));

        if ($action === '') {
            throw new InvalidArgumentException('An audit entry needs an action.');
        }

        $meta = $entry['meta'] ?? null;

        $stmt = $pdo->prepare(
            'INSERT INTO audit_log (actor_id, actor_role, action, subject_type, subject_id, meta_json, ip)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            isset($entry['actor_id']) && (int) $entry['actor_id'] > 0 ? (int) $entry['actor_id'] : null,
            isset($entry['actor_role']) && (string) $entry['actor_role'] !== '' ? (string) $entry['actor_role'] : null,
            mb_substr($action, 0, 80),
            isset($entry['subject_type']) && (string) $entry['subject_type'] !== '' ? mb_substr((string) $entry['subject_type'], 0, 40) : null,
            isset($entry['subject_id']) && (int) $entry['subject_id'] > 0 ? (int) $entry['subject_id'] : null,
            $meta === null ? null : json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            isset($entry['ip']) && (string) $entry['ip'] !== '' ? (string) $entry['ip'] : Request::ip(),
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Recent entries for one subject (the member's own money trail).
     *
     * @return list<array<string, mixed>>
     */
    public static function forSubject(string $type, int $id, int $limit = 10, ?PDO $pdo = null): array
    {
        $pdo   ??= db();
        $limit   = max(1, min(100, $limit));

        $stmt = $pdo->prepare(
            'SELECT id, actor_id, actor_role, action, subject_type, subject_id, meta_json, ip, created_at
               FROM audit_log
              WHERE subject_type = ? AND subject_id = ?
              ORDER BY id DESC
              LIMIT ' . $limit
        );
        $stmt->execute([$type, $id]);

        return $stmt->fetchAll();
    }
}
