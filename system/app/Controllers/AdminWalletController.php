<?php
declare(strict_types=1);

/* ==============================================================
 *  AdminWalletController — monitor and top-ups (plan §7 Phase 5,
 *  §5.8; reference: AdminController::ewalletMonitor / ewalletTopUp
 *  and views/admin/ewallet_*.php)
 *
 *  Routes (Appendix A):
 *      GET  /admin/wallets              float, both buckets, directory
 *      GET  /admin/wallets/{id}         one member's wallet + top-up form
 *      POST /admin/wallets/{id}/credit  audited admin credit
 *
 *  The router owns the admin guard and the central CSRF check. The
 *  money movement itself is WalletService::adminTopUp() — this
 *  controller only validates input and picks the response.
 * ============================================================== */

final class AdminWalletController
{
    /** Tabs of the monitor page. */
    private const TABS = ['members', 'topups', 'ledger'];

    private WalletService $wallet;

    public function __construct()
    {
        $this->wallet = new WalletService();
    }

    /** @param array<string, string> $params */
    public function index(array $params = []): void
    {
        $tab = strtolower(Request::string('tab', 'members'));
        if (!in_array($tab, self::TABS, true)) {
            $tab = 'members';
        }

        $query  = Request::string('q');
        $funded = Request::string('funded');
        $status = strtolower(Request::string('status'));

        if (!in_array($status, ['active', 'pending', 'inactive', 'suspended'], true)) {
            $status = '';
        }

        $totals = Wallet::monitorTotals();

        /* Tab badges always show the total for that list, not the active
         * tab's paginated/filtered subset. */
        $tabCounts = [
            'members' => Wallet::memberCount(['q' => '', 'funded' => '']),
            'topups'  => Wallet::allTopupCount(['q' => '']),
            'ledger'  => Wallet::ledgerCount(null, Wallet::filterDefaults()),
        ];

        $members      = [];
        $membersPager = new Paginator(0);
        $topups       = [];
        $topupsPager  = new Paginator(0);
        $ledger       = [];
        $ledgerPager  = new Paginator(0);
        $ledgerTotals = ['rows' => 0, 'credits' => 0.0, 'debits' => 0.0, 'net' => 0.0];

        if ($tab === 'members') {
            $filters      = ['q' => $query, 'funded' => $funded, 'status' => $status];
            $membersPager = Paginator::fromTableRequest(Wallet::memberCount($filters));
            $members      = Wallet::members($filters, $membersPager);
        } elseif ($tab === 'topups') {
            $filters     = ['q' => $query];
            $topupsPager = Paginator::fromTableRequest(Wallet::allTopupCount($filters));
            $topups      = Wallet::allTopups($filters, $topupsPager);
        } else {
            $filters     = Wallet::normaliseFilters([
                'type'     => Request::string('type'),
                'ref_type' => Request::string('ref_type'),
                'from'     => Request::string('from'),
                'to'       => Request::string('to'),
                'q'        => $query,
            ]);
            $ledgerPager  = Paginator::fromTableRequest(Wallet::ledgerCount(null, $filters));
            $ledger       = Wallet::ledger(null, $filters, $ledgerPager);
            $ledgerTotals = Wallet::ledgerTotals(null, $filters);
        }

        View::page('admin', 'admin/wallets/index', [
            'page_title'      => 'E-wallet monitor',
            'current_nav'     => 'admin.wallets',
            'page_css'        => ['assets/css/wallet.css'],
            'totals'          => $totals,
            'tab'             => $tab,
            'tab_counts'      => $tabCounts,
            'query'           => $query,
            'funded'          => $funded,
            'status'          => $status,
            'members'         => $members,
            'members_pager'   => $membersPager,
            'topups'          => $topups,
            'topups_pager'    => $topupsPager,
            'ledger'          => $ledger,
            'ledger_pager'    => $ledgerPager,
            'ledger_totals'   => $ledgerTotals,
            'ledger_filters'  => $tab === 'ledger' ? $filters : Wallet::filterDefaults(),
        ]);
    }

    /** @param array<string, string> $params */
    public function show(array $params = []): void
    {
        $member = Wallet::account((int) ($params['id'] ?? 0));

        if ($member === null) {
            View::error(404);
        }

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

        View::page('admin', 'admin/wallets/show', [
            'page_title'      => 'Wallet — ' . (string) $member['username'],
            'current_nav'     => 'admin.wallets',
            'page_css'        => ['assets/css/wallet.css'],
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
        ]);
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

        flash_set('success', sprintf(
            '%s credited to @%s — the amount is spendable in the shop and non-withdrawable.',
            money($result['amount']),
            (string) $member['username']
        ));

        redirect($backUrl);
    }
}
