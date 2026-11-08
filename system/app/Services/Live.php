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
     * The member's own wallet statement surface (`member.wallet:42`).
     *
     * The surface is per user: one member's movement never refreshes
     * another member's open statement.
     */
    public static function memberWalletSurface(int $userId): string
    {
        return 'member.wallet:' . max(0, $userId);
    }

    /**
     * The member's own earnings statement surface (`member.earnings:42`).
     *
     * The surface is per user: one member's award never refreshes another
     * member's open earnings page.
     */
    public static function earningsSurface(int $userId): string
    {
        return 'member.earnings:' . max(0, $userId);
    }

    /**
     * The member's own genealogy surface (`member.genealogy:42`).
     *
     * The page key is per user, but every genealogy page also includes
     * the shared `GENEALOGY_TEAM` revision in its ETag: a team change
     * moves the ancestors' counters and lists *and* the descendants'
     * upline chain, so one shared bump is more accurate than walking the
     * tree in only one direction.
     */
    public static function genealogySurface(int $userId): string
    {
        return 'member.genealogy:' . max(0, $userId);
    }

    /**
     * The shared revision every genealogy page includes in its ETag.
     *
     * Bumped by a registration, an activation, a member status change and
     * a profile-name change.
     */
    public const GENEALOGY_TEAM = 'member.genealogy';

    /**
     * Shared revision for configuration read by other live pages.
     *
     * Settings and packages change what the membership activation paths,
     * the order payment instructions and the member package page render;
     * those pages mix this revision into their ETags instead of walking
     * every member or every order on a save.
     */
    public const CONFIG = 'config';

    /**
     * Bump the admin package list, the member package page and the shared
     * configuration revision.
     *
     * Called by Package::save() and Package::setStatus() after a
     * successful change.
     */
    public static function touchPackages(): void
    {
        self::touch(['admin.packages', 'shop.packages', self::CONFIG]);
    }

    /**
     * Bump the admin settings page and the shared configuration revision.
     *
     * Called after a settings card is saved; open membership and order
     * pages revalidate from the shared revision.
     */
    public static function touchSettings(): void
    {
        self::touch(['admin.settings', self::CONFIG]);
    }

    /**
     * A revision read that never fails the page.
     *
     * Used by scopes that mix a shared revision into the ETag: a deploy
     * that lands before migration 013 must render with updates off, not
     * throw while the scope is being built.
     */
    public static function revisionOrZero(string $surface): int
    {
        try {
            return self::revision($surface);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * The member's own profile page (`member.profile:42`).
     *
     * The page key is per user; the full-name, payout-account and address
     * forms are held regions, while the address book list is replaced
     * freely.
     */
    public static function profileSurface(int $userId): string
    {
        return 'member.profile:' . max(0, $userId);
    }

    /**
     * The member's own checkout page (`member.checkout:42`).
     *
     * Only the saved-address picker is live; the delivery/payment form is
     * the member's input and stays outside every region.
     */
    public static function checkoutSurface(int $userId): string
    {
        return 'member.checkout:' . max(0, $userId);
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
     * The member's order-history surface (`member.orders:42`).
     */
    public static function memberOrdersSurface(int $userId): string
    {
        return 'member.orders:' . max(0, $userId);
    }

    /**
     * The detail surface for one member order (`member.order:42`).
     */
    public static function memberOrderSurface(int $orderId): string
    {
        return 'member.order:' . max(0, $orderId);
    }

    /**
     * Bump the order list and one order's detail surface.
     *
     * Every write that changes an order row — placement, a proof, a review
     * decision, a fulfilment step, expiry or a member confirmation — calls
     * this after the change so both open pages revalidate. Passing the
     * buyer's id also bumps their `/orders` history; the member order detail
     * surface is always bumped, so an order page never goes stale even when
     * the caller only knows the order id.
     */
    public static function touchOrder(int $orderId, ?int $userId = null): void
    {
        $surfaces = [
            'admin.orders',
            self::orderSurface($orderId),
            self::memberOrderSurface($orderId),
        ];

        if ($userId !== null && $userId > 0) {
            $surfaces[] = self::memberOrdersSurface($userId);
        }

        self::touch($surfaces);
    }

    /**
     * Bump the wallet monitor and one admin wallet detail page.
     *
     * Every balance movement (top-up, commission, purchase, refund, payout)
     * and every change to a member's directory fields (name, status) calls
     * this after the change. The member's own surfaces are bumped by their
     * own helpers: touchMemberWallet(), touchMemberDashboard() and
     * touchMembership().
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
     * Bump one member's own `/wallet` statement.
     *
     * Called by WalletService::apply() after every successful balance
     * movement, alongside touchWallet(); a replayed movement moves no
     * money and never bumps it. Directory/profile changes do not call it:
     * the statement renders only the two buckets and the ledger rows.
     */
    public static function touchMemberWallet(int $userId): void
    {
        self::touch(self::memberWalletSurface($userId));
    }

    /**
     * Bump one member's `/earnings` statement.
     *
     * Called by CommissionService after every credited or skipped award;
     * a replayed award writes no row and never bumps it. The award also
     * credits the wallet, so WalletService separately refreshes the
     * dashboard, membership and wallet surfaces of the recipient.
     */
    public static function touchEarnings(int $userId): void
    {
        self::touch(self::earningsSurface($userId));
    }

    /**
     * Bump one member's profile page.
     *
     * Called after a full-name save, a payout-account save and every
     * address-book write (through touchAddressBook()).
     */
    public static function touchProfile(int $userId): void
    {
        self::touch(self::profileSurface($userId));
    }

    /**
     * Bump one member's checkout address picker.
     *
     * The delivery/payment form is never replaced; only the saved-address
     * card revalidates when the address book changes.
     */
    public static function touchCheckout(int $userId): void
    {
        self::touch(self::checkoutSurface($userId));
    }

    /**
     * Bump the profile page and the checkout address picker together.
     *
     * Every address-book write lands in both places: the profile list and
     * the checkout picker.
     */
    public static function touchAddressBook(int $userId): void
    {
        self::touch([self::profileSurface($userId), self::checkoutSurface($userId)]);
    }

    /**
     * Bump the shared genealogy revision.
     *
     * A team change moves every affected page: a new registration grows
     * every ancestor's team counters and lists, while an activation, a
     * status change or a profile-name change also rewrites the upline row
     * on every descendant's page. Bumping one shared revision keeps both
     * directions correct instead of walking the whole tree per write.
     */
    public static function touchGenealogy(): void
    {
        self::touch(self::GENEALOGY_TEAM);
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
