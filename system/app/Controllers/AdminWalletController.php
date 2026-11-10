<?php
declare(strict_types=1);

/* ==============================================================
 *  AdminWalletController — monitor and top-ups (plan §7 Phase 5 and
 *  Phase 12, §5.8; reference: AdminController::ewalletMonitor /
 *  ewalletTopUp and views/admin/ewallet_*.php)
 *
 *  Routes (Appendix A):
 *      GET  /admin/wallets              float, both buckets, directory,
 *                                       transfers, top-ups, ledger
 *      GET  /admin/wallets/{id}         one member's wallet + top-up form
 *      POST /admin/wallets/{id}/credit  audited admin credit
 *
 *  The router owns the admin guard and the central CSRF check. The
 *  money movement itself is WalletService::adminTopUp() — this
 *  controller only validates input and picks the response.
 *
 *  Both pages are live surfaces: `admin.wallets` for the monitor and
 *  `admin.wallets:{id}` for one wallet. The monitor's active tab decides
 *  which extra fragments its feed returns; the metric badges, stats and
 *  tab counts are shared by every tab.
 * ============================================================== */

final class AdminWalletController
{
    /** Tabs of the monitor page, in display order. */
    private const TABS = ['members', 'transfers', 'topups', 'ledger'];

    private WalletService $wallet;

    public function __construct()
    {
        $this->wallet = new WalletService();
    }

    /** @param array<string, string> $params */
    public function index(array $params = []): void
    {
        $tab = $this->currentTab();

        if (Live::isFeedRequest()) {
            $this->monitorFeed($tab);
            return;
        }

        View::page('admin', 'admin/wallets/index', $this->monitorContext($tab) + [
            'page_title'  => 'E-wallet monitor',
            'current_nav' => 'admin.wallets',
            'page_css'    => ['assets/css/wallet.css'],
            'page_js'     => ['assets/js/admin-live.js'],
            'live'        => $this->monitorLiveMeta(),
        ]);
    }

    private function currentTab(): string
    {
        $tab = strtolower(Request::string('tab', 'members'));

        return in_array($tab, self::TABS, true) ? $tab : 'members';
    }

    /**
     * Everything the monitor renders, built once for the page and its feed.
     *
     * Only the active tab's list is queried; the other tabs keep their
     * empty defaults, exactly like the pre-live controller.
     *
     * @return array<string, mixed>
     */
    public function monitorContext(string $tab): array
    {
        $query  = Request::string('q');
        $funded = Request::string('funded');
        $status = strtolower(Request::string('status'));

        if (!in_array($status, ['active', 'pending', 'inactive', 'suspended'], true)) {
            $status = '';
        }

        $totals = Wallet::monitorTotals() + Transfer::monitorTotals();

        /* Tab badges always show the total for that list, not the active
         * tab's paginated/filtered subset. */
        $tabCounts = [
            'members'   => Wallet::memberCount(['q' => '', 'funded' => '']),
            'transfers' => Transfer::allCount(Transfer::filterDefaults()),
            'topups'    => Wallet::allTopupCount(['q' => '']),
            'ledger'    => Wallet::ledgerCount(null, Wallet::filterDefaults()),
        ];

        $members      = [];
        $membersPager = new Paginator(0);
        $transfers    = [];
        $transfersPager = new Paginator(0);
        $transferFilters = Transfer::filterDefaults();
        $topups       = [];
        $topupsPager  = new Paginator(0);
        $ledger       = [];
        $ledgerPager  = new Paginator(0);
        $ledgerTotals = ['rows' => 0, 'credits' => 0.0, 'debits' => 0.0, 'net' => 0.0];
        $ledgerFilters = Wallet::filterDefaults();

        if ($tab === 'members') {
            $filters      = ['q' => $query, 'funded' => $funded, 'status' => $status];
            $membersPager = Paginator::fromTableRequest(Wallet::memberCount($filters));
            $members      = Wallet::members($filters, $membersPager);
        } elseif ($tab === 'transfers') {
            $transferFilters = Transfer::normaliseFilters([
                'q'    => $query,
                'from' => Request::string('from'),
                'to'   => Request::string('to'),
            ]);
            $transfersPager = Paginator::fromTableRequest(Transfer::allCount($transferFilters));
            $transfers      = Transfer::all($transferFilters, $transfersPager);
        } elseif ($tab === 'topups') {
            $filters     = ['q' => $query];
            $topupsPager = Paginator::fromTableRequest(Wallet::allTopupCount($filters));
            $topups      = Wallet::allTopups($filters, $topupsPager);
        } else {
            $ledgerFilters = Wallet::normaliseFilters([
                'type'     => Request::string('type'),
                'ref_type' => Request::string('ref_type'),
                'from'     => Request::string('from'),
                'to'       => Request::string('to'),
                'q'        => $query,
            ]);
            $ledgerPager  = Paginator::fromTableRequest(Wallet::ledgerCount(null, $ledgerFilters));
            $ledger       = Wallet::ledger(null, $ledgerFilters, $ledgerPager);
            $ledgerTotals = Wallet::ledgerTotals(null, $ledgerFilters);
        }

        return [
            'totals'         => $totals,
            'tab'            => $tab,
            'tab_counts'     => $tabCounts,
            'query'          => $query,
            'funded'         => $funded,
            'status'         => $status,
            'members'        => $members,
            'members_pager'  => $membersPager,
            'transfers'      => $transfers,
            'transfers_pager' => $transfersPager,
            'transfer_filters' => $transferFilters,
            'topups'         => $topups,
            'topups_pager'   => $topupsPager,
            'ledger'         => $ledger,
            'ledger_pager'   => $ledgerPager,
            'ledger_totals'  => $ledgerTotals,
            'ledger_filters' => $ledgerFilters,
        ];
    }

    /**
     * ETag scope for the monitor.
     *
     * The “today” counters use CURDATE() and the directory shows a
     * “time ago” column, so both the day and the hour are part of the
     * scope: a tab left open revalidates without a write.
     */
    private function monitorScope(int $userId): string
    {
        return Live::scope($_GET, $userId) . '|hour:' . date('Y-m-d H');
    }

    /** @return array{surface:string, enabled:bool, revision:int, etag:string, interval:int} */
    private function monitorLiveMeta(): array
    {
        return Live::meta('admin.wallets', $this->monitorScope((int) (auth_user()['id'] ?? 0)));
    }

    /**
     * JSON feed for the open monitor tab.
     *
     * The 304 check runs before monitorContext(), so an unchanged surface
     * costs one revision lookup and no list/aggregate queries.
     */
    private function monitorFeed(string $tab): void
    {
        $userId = (int) (auth_user()['id'] ?? 0);

        /* No monitor fragment renders a CSRF field, but release the lock
         * before any query work: two tabs must not serialize on it. */
        session_write_close();

        $surface = 'admin.wallets';

        try {
            $revision = Live::revision($surface);
            $etag     = Live::etag($surface, $revision, $this->monitorScope($userId));

            if (Live::matchesIfNoneMatch(Request::header('If-None-Match'), $etag)) {
                Live::notModified($etag);
            }

            Live::json($etag, $this->monitorFeedPayload($revision, $etag, $this->monitorContext($tab)));
        } catch (Throwable $e) {
            error_log('[ecowealth] wallet monitor feed failed: ' . $e->getMessage());
            Live::jsonError('Could not refresh the wallet monitor. Please try again.', 500);
        }
    }

    /**
     * Render the monitor payload. Public so the view tests can assert the
     * fragments without going through Live::json()'s exit.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function monitorFeedPayload(int $revision, string $etag, array $data): array
    {
        $tab = (string) $data['tab'];

        $fragments = [
            'wallet-metrics' => View::capture('admin/wallets/_metrics', [
                'totals' => $data['totals'],
            ]),
            'wallet-stats' => View::capture('admin/wallets/_stats', [
                'totals' => $data['totals'],
            ]),
            'wallet-tabs' => View::capture('admin/wallets/_tabs', [
                'tab'        => $tab,
                'tab_counts' => $data['tab_counts'],
            ]),
        ];

        if ($tab === 'members') {
            $fragments['wallet-members'] = View::capture('admin/wallets/_members', [
                'members' => $data['members'],
            ]);
            $fragments['wallet-members-pagination'] = View::capture('admin/wallets/_members_pagination', [
                'members_pager' => $data['members_pager'],
            ]);
            $pager = $data['members_pager'];
        } elseif ($tab === 'transfers') {
            $fragments['wallet-transfers'] = View::capture('admin/wallets/_transfers', [
                'transfers' => $data['transfers'],
            ]);
            $fragments['wallet-transfers-pagination'] = View::capture('admin/wallets/_transfers_pagination', [
                'transfers_pager' => $data['transfers_pager'],
            ]);
            $pager = $data['transfers_pager'];
        } elseif ($tab === 'topups') {
            $fragments['wallet-topups'] = View::capture('admin/wallets/_topups', [
                'topups' => $data['topups'],
            ]);
            $fragments['wallet-topups-pagination'] = View::capture('admin/wallets/_topups_pagination', [
                'topups_pager' => $data['topups_pager'],
            ]);
            $pager = $data['topups_pager'];
        } else {
            $fragments['wallet-ledger'] = View::capture('admin/wallets/_ledger', [
                'ledger_filters' => $data['ledger_filters'],
                'ledger'         => $data['ledger'],
                'ledger_pager'   => $data['ledger_pager'],
                'ledger_totals'  => $data['ledger_totals'],
            ]);
            $pager = $data['ledger_pager'];
        }

        return [
            'ok'        => true,
            'surface'   => 'admin.wallets',
            'revision'  => $revision,
            'etag'      => $etag,
            'fragments' => $fragments,
            'meta'      => [
                'page'      => $pager->page,
                'pages'     => $pager->pages,
                'total'     => $pager->total,
                'per_page'  => $pager->perPage,
                'canonical' => $this->canonicalMonitorUrl($pager),
            ],
        ];
    }

    /** The monitor URL with an out-of-range page clamped to a real page. */
    private function canonicalMonitorUrl(Paginator $pager): string
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

        return url('admin/wallets' . ($qs !== '' ? '?' . $qs : ''));
    }

    /** @param array<string, string> $params */
    public function show(array $params = []): void
    {
        $userId = (int) ($params['id'] ?? 0);

        if (Live::isFeedRequest()) {
            $this->detailFeed($userId);
            return;
        }

        $member = Wallet::account($userId);

        if ($member === null) {
            View::error(404);
        }

        View::page('admin', 'admin/wallets/show', $this->walletContext($member) + [
            'page_title'  => 'Wallet — ' . (string) $member['username'],
            'current_nav' => 'admin.wallets',
            'page_css'    => [
                'assets/css/wallet.css',
                'assets/vendor/libs/sweetalert2/sweetalert2.css',
            ],
            'page_js'     => [
                'assets/vendor/libs/sweetalert2/sweetalert2.js',
                'assets/js/app-confirm.js',
                'assets/js/admin-live.js',
            ],
            'live'        => Live::meta(
                Live::walletSurface($userId),
                Live::scope($_GET, (int) (auth_user()['id'] ?? 0))
            ),
        ]);
    }

    /**
     * Everything one wallet page renders, built once for the page and its
     * feed. Public so the view tests can assert the real screen data.
     *
     * @return array<string, mixed>
     */
    public function walletContext(array $member): array
    {
        $userId  = (int) $member['id'];
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

        /* Two independent lists share this page, so the top-up history
         * uses its own page key; changing one never moves the other. */
        $topupsPager = Paginator::fromTableRequest(Wallet::topupCountFor($userId), 'topup_page');
        $topups      = Wallet::topupsFor($userId, $topupsPager);

        return [
            'member'          => $member,
            'ledger'          => $statement['rows'],
            'pager'           => $statement['pager'],
            'totals'          => $statement['totals'],
            'filters'         => $filters,
            'statement_url'   => url('admin/wallets/' . $userId),
            'statement_title' => 'Wallet statement',
            'show_member'     => false,
            'topups'          => $topups,
            'topups_pager'    => $topupsPager,
        ];
    }

    /**
     * JSON feed for one open wallet page.
     *
     * The 304 check runs before the member read, so an unchanged wallet
     * costs one revision lookup. The top-up form carries a CSRF field, so
     * the token is primed before the session lock is released.
     */
    private function detailFeed(int $userId): void
    {
        $adminId = (int) (auth_user()['id'] ?? 0);

        csrf_token();
        session_write_close();

        $surface = Live::walletSurface($userId);

        try {
            $revision = Live::revision($surface);
            $etag     = Live::etag($surface, $revision, Live::scope($_GET, $adminId));

            if (Live::matchesIfNoneMatch(Request::header('If-None-Match'), $etag)) {
                Live::notModified($etag);
            }

            $member = Wallet::account($userId);

            if ($member === null) {
                Live::jsonError('Wallet not found.', 404);
            }

            Live::json($etag, $this->detailFeedPayload($revision, $etag, $this->walletContext($member)));
        } catch (Throwable $e) {
            error_log('[ecowealth] wallet detail live feed failed: ' . $e->getMessage());
            Live::jsonError('Could not refresh this wallet. Please try again.', 500);
        }
    }

    /**
     * Render the wallet detail payload. Public so tests can assert the
     * fragments without going through Live::json()'s exit.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function detailFeedPayload(int $revision, string $etag, array $data): array
    {
        $member = $data['member'];

        return [
            'ok'        => true,
            'surface'   => Live::walletSurface((int) $member['id']),
            'revision'  => $revision,
            'etag'      => $etag,
            'fragments' => [
                'wallet-heading' => View::capture('admin/wallets/_heading', [
                    'member' => $member,
                ]),
                'wallet-badges' => View::capture('admin/wallets/_badges', [
                    'member' => $member,
                ]),
                'wallet-buckets' => View::capture('admin/wallets/_buckets', [
                    'member' => $member,
                ]),
                'wallet-statement' => View::capture('admin/wallets/_statement', [
                    'filters'         => $data['filters'],
                    'ledger'          => $data['ledger'],
                    'pager'           => $data['pager'],
                    'totals'          => $data['totals'],
                    'statement_url'   => $data['statement_url'],
                    'statement_title' => $data['statement_title'],
                ]),
                'wallet-topup-form' => View::capture('admin/wallets/_topup_form', [
                    'member' => $member,
                ]),
                'wallet-topup-history' => View::capture('admin/wallets/_topup_history', [
                    'topups'       => $data['topups'],
                    'topups_pager' => $data['topups_pager'],
                ]),
            ],
            'meta'      => [
                'canonical' => $this->canonicalDetailUrl(
                    (int) $member['id'],
                    $data['pager'],
                    $data['topups_pager']
                ),
            ],
        ];
    }

    /**
     * The wallet URL with both page numbers clamped.
     *
     * The statement and the top-up history paginate independently, so the
     * canonical keeps every filter and per-page value but writes back the
     * real page each list landed on.
     */
    private function canonicalDetailUrl(int $userId, Paginator $pager, Paginator $topupsPager): string
    {
        $query = [];

        foreach ($_GET as $key => $value) {
            if (is_scalar($value) && $key !== 'XTransformPort') {
                $query[(string) $key] = (string) $value;
            }
        }

        unset($query['page'], $query['topup_page']);

        if ($pager->page > 1) {
            $query['page'] = (string) $pager->page;
        }

        if ($topupsPager->page > 1) {
            $query['topup_page'] = (string) $topupsPager->page;
        }

        $qs = http_build_query($query);

        return url('admin/wallets/' . $userId . ($qs !== '' ? '?' . $qs : ''));
    }

    /** POST /admin/wallets/{id}/credit — audited top-up. */
    public function credit(array $params = []): void
    {
        $userId = (int) ($params['id'] ?? 0);
        $member = Wallet::account($userId);

        if ($member === null) {
            View::error(404);
        }

        $backUrl = 'admin/wallets/' . $userId;

        $input = [
            'amount' => Request::string('amount'),
            'note'   => Request::string('note'),
        ];

        $errors = Validator::check($input, [
            'amount' => 'required|numeric|gt:0|decimal:2|lte:9999999999.99',
            'note'   => 'nullable|string|max:255',
        ], [
            'amount' => 'Top-up amount',
            'note'   => 'Note',
        ]);

        if ($errors !== []) {
            errors_set($errors);
            old_set($input);
            flash_set('danger', 'Please fix the highlighted top-up fields.');
            redirect($backUrl);
        }

        $admin = auth_user();

        if ($admin === null) {
            redirect('login');
        }

        try {
            $result = $this->wallet->adminTopUp((int) $admin['id'], $userId, $input['amount'], $input['note']);
        } catch (WalletException $e) {
            errors_set(['amount' => $e->getMessage()]);
            old_set($input);
            flash_set('danger', $e->getMessage());
            redirect($backUrl);
        } catch (Throwable $e) {
            error_log('[ecowealth] wallet top-up failed: ' . $e->getMessage());
            flash_set('danger', 'The top-up could not be completed. Please try again.');
            redirect($backUrl);
        }

        old_clear();

        /* The POST redirects to a normal page load; queue the surfaces so
         * that landing page broadcasts them to every other open tab. */
        Live::publish([
            'admin.wallets',
            Live::walletSurface($userId),
            Live::memberWalletSurface($userId),
            Live::checkoutSurface($userId),
            Live::memberDashboardSurface($userId),
            Live::membershipSurface($userId),
        ]);

        flash_set('success', sprintf(
            '%s credited to @%s — the amount is spendable in the shop and non-withdrawable.',
            money($result['amount']),
            (string) $member['username']
        ));

        redirect($backUrl);
    }
}
