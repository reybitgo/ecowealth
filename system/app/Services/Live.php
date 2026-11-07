<?php
declare(strict_types=1);

/* ==============================================================
 *  Live — revision + JSON protocol for no-refresh page updates
 *
 *  A page that wants live updates asks the server for its current
 *  surface revision. The client keeps that revision (inside an ETag)
 *  and sends it back on every poll; an unchanged surface is a 304
 *  with no page queries and no HTML rendering.
 *
 *  The revision row is bumped by the write path (Live::touch) after a
 *  successful change. A page can have one surface or several; a page
 *  with several should combine its revisions into one ETag and return
 *  one JSON payload, not one request per widget.
 *
 *  See docs/live-updates.md for the page-level contract.
 * ============================================================== */

final class Live
{
    /** Is this request the JSON feed for the current page? */
    public static function isFeedRequest(): bool
    {
        return Request::header('X-Live') === '1';
    }

    /** Current revision for a surface; a missing row means revision 0. */
    public static function revision(string $surface): int
    {
        $stmt = db()->prepare('SELECT revision FROM live_revisions WHERE surface = ? LIMIT 1');
        $stmt->execute([$surface]);

        $value = $stmt->fetchColumn();

        return $value === false ? 0 : (int) $value;
    }

    /**
     * Bump one or more surfaces after a successful write.
     *
     * Best-effort: a revision failure must never turn a completed
     * product/order write into a 500. The migration is part of deploy,
     * so a missing table is logged once and the write still succeeds.
     *
     * @param string|list<string> $surfaces
     */
    public static function touch(string|array $surfaces): void
    {
        $surfaces = is_array($surfaces) ? $surfaces : [$surfaces];
        $surfaces = array_values(array_unique(array_filter(
            array_map(static fn (mixed $surface): string => trim((string) $surface), $surfaces),
            static fn (string $surface): bool => $surface !== ''
        )));

        if ($surfaces === []) {
            return;
        }

        try {
            $stmt = db()->prepare(
                'INSERT INTO live_revisions (surface, revision) VALUES (?, 1)
                 ON DUPLICATE KEY UPDATE revision = revision + 1'
            );

            foreach ($surfaces as $surface) {
                $stmt->execute([$surface]);
            }
        } catch (Throwable $e) {
            static $warned = false;

            if (!$warned) {
                $warned = true;
                error_log('[ecowealth] live revision touch failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Stable, request-specific scope inside the ETag.
     *
     * The same page with a different filter, page number or signed-in
     * user must not reuse another response's 304. Query keys are sorted
     * so an equivalent URL always produces the same scope.
     *
     * @param array<string, mixed> $query
     */
    public static function scope(array $query, ?int $userId): string
    {
        $normalized = [];

        foreach ($query as $key => $value) {
            if (is_scalar($value)) {
                $normalized[(string) $key] = (string) $value;
            }
        }

        ksort($normalized);

        $encoded = json_encode(
            ['query' => $normalized, 'user' => $userId],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return $encoded === false ? '' : $encoded;
    }

    /** Build the quoted ETag for a surface, revision and request scope. */
    public static function etag(string $surface, int $revision, string $scope = ''): string
    {
        return '"' . $surface . ':' . $revision . ':' . substr(hash('sha256', $scope), 0, 12) . '"';
    }

    /** Does an If-None-Match header contain our ETag (strong, weak or *)? */
    public static function matchesIfNoneMatch(?string $header, string $etag): bool
    {
        if ($header === null || trim($header) === '') {
            return false;
        }

        $ours = trim($etag, '"');

        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);

            if ($candidate === '*' || $candidate === $etag || $candidate === 'W/' . $etag) {
                return true;
            }

            /* Some command-line clients strip the quotes from a header
             * value. Compare the payload too, so a valid conditional
             * request does not turn into a full 200. */
            $unquoted = trim(preg_replace('/^W\//i', '', $candidate) ?? '', '"');

            if ($unquoted === $ours) {
                return true;
            }
        }

        return false;
    }

    /** Send 304 Not Modified and stop — no body, no page queries. */
    public static function notModified(string $etag): never
    {
        http_response_code(304);
        header('ETag: ' . $etag);
        header('Cache-Control: private, no-store');
        header('Vary: Accept, Cookie');

        exit;
    }

    /** Send a live JSON payload with its ETag and stop. */
    public static function json(string $etag, array $payload): never
    {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        header('ETag: ' . $etag);
        header('Cache-Control: private, no-store');
        header('Vary: Accept, Cookie');
        header('X-Content-Type-Options: nosniff');

        self::emit($payload);
    }

    /** Send a JSON failure from a live request and stop. */
    public static function jsonError(string $message, int $status = 500): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        header('Vary: Accept, Cookie');
        header('X-Content-Type-Options: nosniff');

        self::emit(['ok' => false, 'message' => $message]);
    }

    /**
     * Queue surfaces for the next normal page load to broadcast.
     *
     * This is how a native POST (the product create/edit form) tells
     * other tabs about a change: the redirect lands on a normal page,
     * the footer emits the queued keys, and app-live.js broadcasts
     * them. JSON mutations broadcast directly and never use this.
     *
     * @param string|list<string> $surfaces
     */
    public static function publish(string|array $surfaces): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $surfaces = is_array($surfaces) ? $surfaces : [$surfaces];
        $now      = time();

        foreach ($surfaces as $surface) {
            $surface = trim((string) $surface);

            if ($surface !== '') {
                $_SESSION['_live_publish'][$surface] = $now;
            }
        }
    }

    /**
     * Read and clear the queued surfaces for this page load.
     * Entries older than five minutes are dropped (a close/crash
     * between the write and the redirect must not replay forever).
     *
     * @return list<string>
     */
    public static function takePublished(): array
    {
        $queued = $_SESSION['_live_publish'] ?? [];
        unset($_SESSION['_live_publish']);

        if (!is_array($queued)) {
            return [];
        }

        $cutoff = time() - 300;
        $out    = [];

        foreach ($queued as $surface => $at) {
            if ((int) $at >= $cutoff && (string) $surface !== '') {
                $out[] = (string) $surface;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $payload */
    private static function emit(array $payload): never
    {
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );

        echo $json === false
            ? '{"ok":false,"message":"Could not encode the response."}'
            : $json;

        exit;
    }
}
