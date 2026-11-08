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

    /**
     * Live metadata for a page render.
     *
     * Never throws: when the revision table is unavailable (a deploy that
     * lands before migration 013) the page still renders, with updates
     * switched off, instead of failing the screen.
     *
     * @return array{surface:string, enabled:bool, revision:int, etag:string, interval:int}
     */
    public static function meta(string $surface, string $scope = ''): array
    {
        $meta = [
            'surface'  => $surface,
            'enabled'  => true,
            'revision' => 0,
            'etag'     => '',
            'interval' => 30000,
        ];

        try {
            $revision         = self::revision($surface);
            $meta['revision'] = $revision;
            $meta['etag']     = self::etag($surface, $revision, $scope);
        } catch (Throwable $e) {
            error_log('[ecowealth] live revision unavailable for ' . $surface . ': ' . $e->getMessage());
            $meta['enabled'] = false;
        }

        return $meta;
    }

    /** The detail surface key for one order (`admin.orders:42`). */
    public static function orderSurface(int $orderId): string
    {
        return 'admin.orders:' . max(0, $orderId);
    }

    /**
     * The detail surface key for one member wallet (`admin.wallets:42`).
     */
    public static function walletSurface(int $userId): string
    {
        return 'admin.wallets:' . max(0, $userId);
    }

    /**
     * The detail surface key for one member page (`admin.members:42`).
     */
    public static function memberSurface(int $userId): string
    {
        return 'admin.members:' . max(0, $userId);
    }

    /**
     * The member's own dashboard surface (`member.dashboard:42`).
     *
     * The member dashboard only exposes the E-wallet KPI and card as live
     * regions; the surface is per user so one member's balance change never
     * refreshes another member's open dashboard.
     */
    public static function memberDashboardSurface(int $userId): string
    {
        return 'member.dashboard:' . max(0, $userId);
    }

    /**
     * The member's own membership page (`member.membership:42`).
     *
     * The surface is per user: one member's activation/history change never
     * refreshes another member's open membership page.
     */
    public static function membershipSurface(int $userId): string
    {
        return 'member.membership:' . max(0, $userId);
    }

    /**
     * The storefront product detail surface (`shop.product:42`).
     */
    public static function shopProductSurface(int $productId): string
    {
        return 'shop.product:' . max(0, $productId);
    }

    /**
     * Bump the order list and one order's detail surface.
     *
     * Every write that changes an order row — placement, a proof, a review
     * decision, a fulfilment step, expiry or a member confirmation — calls
     * this after the change so both open pages revalidate.
     */
    public static function touchOrder(int $orderId): void
    {
        self::touch(['admin.orders', self::orderSurface($orderId)]);
    }

    /**
     * Bump the wallet monitor and one member's wallet page.
     *
     * Every balance movement (top-up, commission, purchase, refund, payout)
     * and every change to a member's directory fields (name, status) calls
     * this after the change.
     */
    public static function touchWallet(int $userId): void
    {
        self::touch(['admin.wallets', self::walletSurface($userId)]);
    }

    /**
     * Bump the member directory and one member's detail page.
     *
     * Called by every write that changes a directory row — registration,
     * activation (status/package), a status change, a profile name, a
     * wallet balance — and by any write that changes a member page's
     * wallet, order, commission, activation or audit figures.
     */
    public static function touchMember(int $userId): void
    {
        self::touch(['admin.members', self::memberSurface($userId)]);
    }

    /**
     * Bump one member's dashboard so its E-wallet KPI and card refresh.
     *
     * Called after a wallet movement, not after directory/profile changes:
     * the member dashboard's live regions only read the two buckets.
     */
    public static function touchMemberDashboard(int $userId): void
    {
        self::touch(self::memberDashboardSurface($userId));
    }

    /**
     * Bump one member's `/membership` page.
     *
     * Called after an activation request, an approval or rejection, a member
     * status change, a profile-name change and every wallet movement: the
     * page shows the status, package, activation history and the wallet
     * balance used by the e-wallet activation path.
     */
    public static function touchMembership(int $userId): void
    {
        self::touch(self::membershipSurface($userId));
    }

    /**
     * Bump the catalogue and one or more product detail pages.
     *
     * Every write that can change what the storefront shows — a product
     * edit, a status change, a delete, or a reservation release/creation
     * that moves `available` — calls this after the change.
     *
     * @param int|list<int> $productIds
     */
    public static function touchShop(int|array $productIds = []): void
    {
        $ids      = is_array($productIds) ? $productIds : [$productIds];
        $surfaces = ['shop.catalog'];

        foreach ($ids as $productId) {
            $productId = (int) $productId;

            if ($productId > 0) {
                $surfaces[] = self::shopProductSurface($productId);
            }
        }

        self::touch($surfaces);
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
