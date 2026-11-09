<?php
declare(strict_types=1);

/* ==============================================================
 *  WalletController — the member's own e-wallet statement
 *  (plan §7 Phase 5, §5.8; reference: MemberController::ewallet +
 *  views/member/ewallet.php)
 *
 *  Route (Appendix A): GET /wallet
 *
 *  The page shows both buckets and the immutable ledger with filters,
 *  a running balance (`balance_after`) and page totals. Since Phase 12
 *  it also carries the "send funds" card, which shows how much of the
 *  member's daily and weekly transfer allowance is left. Admins may
 *  open it too — the `member` guard admits them (plan §3.4) — and get
 *  their own statement inside the back-office layout.
 *
 *  The page is one per-member live surface (`member.wallet:{id}`).
 *  The same URL doubles as the JSON feed; the buckets, the send-funds
 *  card and the statement card are regions. The statement owns the
 *  filter form, so it is held while the member has unsaved input and
 *  the page shows the stale banner.
 * ============================================================== */

final class WalletController
{
    private WalletService $wallet;
    private TransferService $transfers;

    public function __construct()
    {
        $this->wallet    = new WalletService();
        $this->transfers = new TransferService();
    }

    /** @param array<string, string> $params */
    public function index(array $params = []): void
    {
        $user = auth_user();

        if ($user === null) {
            redirect('login');
        }

        $userId  = (int) $user['id'];
        $isAdmin = is_admin($user);

        /* The same URL doubles as the JSON feed. The surface is per member,
         * so one member's movement never refreshes another member's page. */
        if (Live::isFeedRequest()) {
            $this->feed($userId);
            return;
        }

        $account = Wallet::account($userId);

        if ($account === null) {
            View::error(404);
        }

        View::page($isAdmin ? 'admin' : 'member', 'member/wallet', $this->walletContext($account) + [
            'page_title'  => 'E-wallet',
            'current_nav' => $isAdmin ? 'admin.wallet' : 'wallet',
            'page_css'    => ['assets/css/wallet.css'],
            'page_js'     => ['assets/js/admin-live.js'],
            'live'        => Live::meta(
                Live::memberWalletSurface($userId),
                Live::scope($_GET, $userId)
            ),
        ]);
    }

    /**
     * Everything the statement renders, built once for the page and its
     * feed. Public so the view tests can assert the real screen data.
     *
     * @param array<string, mixed> $account
     * @return array<string, mixed>
     */
    public function walletContext(array $account): array
    {
        $userId  = (int) $account['id'];
        $filters = Wallet::normaliseFilters([
            'type'     => Request::string('type'),
            'ref_type' => Request::string('ref_type'),
            'from'     => Request::string('from'),
            'to'       => Request::string('to'),
            'q'        => Request::string('q'),
        ]);

        $page = Request::query('page', 1);

        $statement = $this->wallet->statement(
            $userId,
            $filters,
            is_scalar($page) ? (int) $page : 1,
            Paginator::perPageFromRequest()
        );

        return [
            'account'         => $account,
            'limits'          => $this->transfers->usageFor($account),
            'ledger'          => $statement['rows'],
            'pager'           => $statement['pager'],
            'totals'          => $statement['totals'],
            'filters'         => $filters,
            'statement_url'   => url('wallet'),
            'statement_title' => 'Statement',
            'show_member'     => false,
        ];
    }

    /**
     * JSON feed for the open statement.
     *
     * The 304 check runs before the account read, so an unchanged wallet
     * costs one revision lookup. No fragment renders a POST form, so the
     * session lock is released immediately.
     */
    private function feed(int $userId): void
    {
        session_write_close();

        $surface = Live::memberWalletSurface($userId);

        try {
            $revision = Live::revision($surface);
            $etag     = Live::etag($surface, $revision, Live::scope($_GET, $userId));

            if (Live::matchesIfNoneMatch(Request::header('If-None-Match'), $etag)) {
                Live::notModified($etag);
            }

            $account = Wallet::account($userId);

            if ($account === null) {
                Live::jsonError('Account not found.', 404);
            }

            Live::json($etag, $this->feedPayload($revision, $etag, $this->walletContext($account)));
        } catch (Throwable $e) {
            error_log('[ecowealth] member wallet live feed failed: ' . $e->getMessage());
            Live::jsonError('Could not refresh your wallet. Please try again.', 500);
        }
    }

    /**
     * Render the statement payload. Public so tests can assert the
     * fragments without going through Live::json()'s exit.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function feedPayload(int $revision, string $etag, array $data): array
    {
        $account = $data['account'];
        $pager   = $data['pager'];

        return [
            'ok'        => true,
            'surface'   => Live::memberWalletSurface((int) $account['id']),
            'revision'  => $revision,
            'etag'      => $etag,
            'fragments' => [
                'member-wallet-buckets' => View::capture('member/wallet/_buckets', [
                    'account' => $account,
                ]),
                'member-wallet-transfer' => View::capture('member/wallet/_transfer_card', [
                    'limits' => $data['limits'],
                ]),
                'member-wallet-statement' => View::capture('member/wallet/_statement', [
                    'filters'         => $data['filters'],
                    'ledger'          => $data['ledger'],
                    'pager'           => $pager,
                    'totals'          => $data['totals'],
                    'statement_url'   => $data['statement_url'],
                    'statement_title' => $data['statement_title'],
                    'show_member'     => $data['show_member'],
                ]),
            ],
            'meta'      => [
                'page'      => $pager->page,
                'pages'     => $pager->pages,
                'total'     => $pager->total,
                'per_page'  => $pager->perPage,
                'canonical' => $this->canonicalWalletUrl($pager),
            ],
        ];
    }

    /** The statement URL with an out-of-range page clamped to a real page. */
    private function canonicalWalletUrl(Paginator $pager): string
    {
        $query = [];

        foreach ($_GET as $key => $value) {
            if (is_scalar($value) && $key !== 'XTransformPort') {
                $query[(string) $key] = (string) $value;
            }
        }

        unset($query['page']);

        if ($pager->page > 1) {
            $query['page'] = (string) $pager->page;
        }

        $qs = http_build_query($query);

        return url('wallet' . ($qs !== '' ? '?' . $qs : ''));
    }
}
