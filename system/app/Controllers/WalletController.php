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
 *  a running balance (`balance_after`) and page totals. Admins may
 *  open it too — the `member` guard admits them (plan §3.4) — and get
 *  their own statement inside the back-office layout.
 * ============================================================== */

final class WalletController
{
    private WalletService $wallet;

    public function __construct()
    {
        $this->wallet = new WalletService();
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

        $filters = Wallet::normaliseFilters([
            'type'     => Request::string('type'),
            'ref_type' => Request::string('ref_type'),
            'from'     => Request::string('from'),
            'to'       => Request::string('to'),
            'q'        => Request::string('q'),
        ]);

        $statement = $this->wallet->statement($userId, $filters, (int) Request::query('page', 1));
        $account   = Wallet::account($userId);

        if ($account === null) {
            View::error(404);
        }

        View::page($isAdmin ? 'admin' : 'member', 'member/wallet', [
            'page_title'      => 'E-wallet',
            'current_nav'     => $isAdmin ? 'admin.wallet' : 'wallet',
            'page_css'        => ['assets/css/wallet.css'],
            'account'         => $account,
            'ledger'          => $statement['rows'],
            'pager'           => $statement['pager'],
            'totals'          => $statement['totals'],
            'filters'         => $filters,
            'statement_url'   => url('wallet'),
            'statement_title' => 'Statement',
            'show_member'     => false,
        ]);
    }
}
