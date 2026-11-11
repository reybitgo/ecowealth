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

    /* ----------------------------------------------------------
     *  Admin audit desk (Phase 15)
     * ---------------------------------------------------------- */

    /**
     * Empty filter set, in the order the audit form shows it.
     *
     * @return array{q:string, action:string, subject_type:string, from:string, to:string}
     */
    public static function filterDefaults(): array
    {
        return [
            'q'            => '',
            'action'       => '',
            'subject_type' => '',
            'from'         => '',
            'to'           => '',
        ];
    }

    /**
     * Validate and normalise the audit filters.
     *
     * @param array<string, mixed> $raw
     * @return array{q:string, action:string, subject_type:string, from:string, to:string}
     */
    public static function normaliseFilters(array $raw): array
    {
        $filters = self::filterDefaults();

        $filters['q']            = mb_substr(trim((string) ($raw['q'] ?? '')), 0, 80);
        $filters['action']       = mb_substr(trim((string) ($raw['action'] ?? '')), 0, 80);
        $filters['subject_type'] = mb_substr(trim((string) ($raw['subject_type'] ?? '')), 0, 40);
        $filters['from']         = self::date($raw['from'] ?? '');
        $filters['to']           = self::date($raw['to'] ?? '');

        if ($filters['from'] !== '' && $filters['to'] !== '' && $filters['from'] > $filters['to']) {
            [$filters['from'], $filters['to']] = [$filters['to'], $filters['from']];
        }

        return $filters;
    }

    /**
     * Build the shared WHERE clause for the list and its count.
     *
     * @param array{q:string, action:string, subject_type:string, from:string, to:string} $filters
     * @return array{0:string, 1:list<string>}
     */
    private static function where(array $filters): array
    {
        $where  = ['1 = 1'];
        $params = [];

        if ($filters['action'] !== '') {
            $where[]  = 'a.action = ?';
            $params[] = $filters['action'];
        }

        if ($filters['subject_type'] !== '') {
            $where[]  = 'a.subject_type = ?';
            $params[] = $filters['subject_type'];
        }

        if ($filters['q'] !== '') {
            $like     = '%' . like_escape($filters['q']) . '%';
            $where[]  = '(a.action LIKE ? OR u.username LIKE ? OR u.full_name LIKE ? OR a.subject_type LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if ($filters['from'] !== '') {
            $where[]  = 'a.created_at >= ?';
            $params[] = $filters['from'] . ' 00:00:00';
        }

        if ($filters['to'] !== '') {
            $where[]  = 'a.created_at < ?';
            $params[] = date('Y-m-d', strtotime($filters['to'] . ' +1 day')) . ' 00:00:00';
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * How many entries match the filters.
     *
     * @param array{q:string, action:string, subject_type:string, from:string, to:string} $filters
     */
    public static function countAdmin(array $filters, ?PDO $pdo = null): int
    {
        $pdo ??= db();

        [$where, $params] = self::where($filters);

        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
               FROM audit_log a
               LEFT JOIN users u ON u.id = a.actor_id
              WHERE ' . $where
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * One page of the audit list, newest first.
     *
     * @param array{q:string, action:string, subject_type:string, from:string, to:string} $filters
     * @return list<array<string, mixed>>
     */
    public static function adminList(array $filters, Paginator $pager, ?PDO $pdo = null): array
    {
        $pdo ??= db();

        [$where, $params] = self::where($filters);

        $stmt = $pdo->prepare(
            'SELECT a.id, a.actor_id, a.actor_role, a.action, a.subject_type, a.subject_id,
                    a.meta_json, a.ip, a.created_at,
                    u.username AS actor_username, u.full_name AS actor_name
               FROM audit_log a
               LEFT JOIN users u ON u.id = a.actor_id
              WHERE ' . $where . '
              ORDER BY a.id DESC
              LIMIT ' . (int) $pager->perPage . ' OFFSET ' . (int) $pager->offset
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Distinct actions and subject types, for the filter dropdowns.
     *
     * @return array{actions:list<string>, subjects:list<string>}
     */
    public static function vocabulary(?PDO $pdo = null): array
    {
        $pdo ??= db();

        $actions = array_column(
            $pdo->query('SELECT DISTINCT action FROM audit_log ORDER BY action')->fetchAll(),
            'action'
        );

        $subjects = array_column(
            $pdo->query(
                "SELECT DISTINCT subject_type FROM audit_log
                  WHERE subject_type IS NOT NULL AND subject_type <> ''
                  ORDER BY subject_type"
            )->fetchAll(),
            'subject_type'
        );

        return [
            'actions'  => array_values(array_map('strval', $actions)),
            'subjects' => array_values(array_map('strval', $subjects)),
        ];
    }

    /**
     * Header counters for the audit desk.
     *
     * @return array{total:int, today:int, actors:int, settings:int}
     */
    public static function stats(?PDO $pdo = null): array
    {
        $pdo ??= db();

        $row = $pdo->query(
            "SELECT COUNT(*) AS total,
                    COUNT(CASE WHEN created_at >= CURDATE() THEN 1 END) AS today,
                    COUNT(DISTINCT actor_id) AS actors,
                    COUNT(CASE WHEN action LIKE 'settings.%' THEN 1 END) AS settings
               FROM audit_log"
        )->fetch() ?: [];

        return [
            'total'    => (int) ($row['total'] ?? 0),
            'today'    => (int) ($row['today'] ?? 0),
            'actors'   => (int) ($row['actors'] ?? 0),
            'settings' => (int) ($row['settings'] ?? 0),
        ];
    }

    /**
     * Decode an entry's meta payload for display (never throws).
     *
     * @return array<string, mixed>
     */
    public static function meta(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** Validate one Y-m-d date ('' when invalid). */
    private static function date(mixed $value): string
    {
        $value = trim((string) $value);

        if ($value === '' || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
            return '';
        }

        $ts = strtotime($value . ' 12:00:00');

        return $ts !== false && date('Y-m-d', $ts) === $value ? $value : '';
    }
}
