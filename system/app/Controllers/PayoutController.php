<?php
declare(strict_types=1);

/* ==============================================================
 *  PayoutController — the member's withdraw-earnings page (plan §5.9,
 *  §7 Phase 13; reference MemberController::payout / requestPayout and
 *  views/member/payout.php)
 *
 *  Routes (Appendix A):
 *      GET  /payouts          withdrawable balance, request form, history
 *      POST /payouts/request  create the request (the funds are held)
 *
 *  The router owns the `member` guard and the central CSRF check. The
 *  money movement is PayoutService::request(); this controller only
 *  validates the field shape, maps a refusal onto the right field and
 *  picks the response.
 *
 *  The page is one live surface per member (`member.payouts:{id}`). The
 *  balance card, the request card (the form, or the open request), the
 *  history table and its pagination are regions. The request card carries
 *  `data-live-hold="dirty"`, so a background refresh never wipes a
 *  half-typed amount; `app-payout.js` re-binds the fee/rate preview after
 *  the card was replaced. Admins get the same page inside the back-office
 *  layout (the `member` guard admits them, plan §3.4).
 * ============================================================== */

final class PayoutController
{
    /** The account input name for a channel (`account_gcash`, …). */
    public const ACCOUNT_INPUT = 'account_';

    private PayoutService $payouts;

    public function __construct()
    {
        $this->payouts = new PayoutService();
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

        if (Live::isFeedRequest()) {
            $this->feed($userId);
            return;
        }

        $account = Wallet::account($userId);

        if ($account === null) {
            View::error(404);
        }

        View::page($isAdmin ? 'admin' : 'member', 'member/payouts', $this->payoutContext($account) + [
            'page_title'  => 'Payouts',
            'current_nav' => $isAdmin ? 'admin.my_payouts' : 'payouts',
            'page_css'    => ['assets/css/wallet.css'],
            'page_js'     => ['assets/js/app-payout.js', 'assets/js/admin-live.js'],
            'live'        => Live::meta(Live::payoutSurface($userId), $this->pageScope($userId)),
        ]);
    }

    /**
     * Everything the page renders, built once for the page and its feed.
     * Public so the view tests can assert the real screen data.
     *
     * @param array<string, mixed> $account
     * @return array<string, mixed>
     */
    public function payoutContext(array $account): array
    {
        $userId = (int) $account['id'];
        $pager  = Paginator::fromTableRequest(Payout::countFor($userId));

        return [
            'account'       => $account,
            'options'       => $this->payouts->optionsFor($account),
            'requests'      => Payout::forUser($userId, $pager),
            'pager'         => $pager,
            'totals'        => Payout::memberTotals($userId),
            'payout_url'    => url('payouts'),
            'statement_url' => url('wallet'),
            'profile_url'   => url('profile'),
        ];
    }

    /**
     * POST /payouts/request — create the request and hold the funds.
     *
     * @param array<string, string> $params
     */
    public function request(array $params = []): void
    {
        $user = auth_user();

        if ($user === null) {
            redirect('login');
        }

        $userId = (int) $user['id'];
        $back   = 'payouts';

        /* Abuse guard (plan §3.7): ten withdrawal requests per hour. A
         * member may legitimately retry a below-minimum or USDT-closed
         * refusal a few times, so the cap is loose; the service still
         * enforces "one open request" and the withdrawable balance. */
        if (!Throttle::hit('payout:' . $userId, 10, 3600)) {
            flash_set('danger', 'Too many withdrawal requests. Please try again later.');
            redirect($back);
        }

        $method = strtolower(Request::string('payout_method'));
        $amount = Request::string('amount');

        /* The form carries one account box per channel; the selected
         * channel's box is the one that counts (so switching method in a
         * browser without JavaScript still submits the right number). */
        $account = $method === ''
            ? ''
            : Request::string(self::ACCOUNT_INPUT . $method);

        $input = [
            'payout_method'  => $method,
            'amount'         => $amount,
            self::ACCOUNT_INPUT . 'gcash'       => Request::string(self::ACCOUNT_INPUT . 'gcash'),
            self::ACCOUNT_INPUT . 'maya'        => Request::string(self::ACCOUNT_INPUT . 'maya'),
            self::ACCOUNT_INPUT . 'usdt_trc20'  => Request::string(self::ACCOUNT_INPUT . 'usdt_trc20'),
            self::ACCOUNT_INPUT . 'usdt_bep20'  => Request::string(self::ACCOUNT_INPUT . 'usdt_bep20'),
        ];

        $errors = Validator::check([
            'payout_method' => $method,
            'amount'        => $amount,
        ], [
            'payout_method' => 'required|in:' . implode(',', PayoutService::METHODS),
            'amount'        => 'required|numeric|gt:0|decimal:2|lte:9999999999.99',
        ], [
            'payout_method' => 'Payout method',
            'amount'        => 'Amount',
        ]);

        if ($errors !== []) {
            errors_set($errors);
            old_set($input);
            flash_set('danger', 'Please fix the highlighted payout fields.');
            redirect($back);
        }

        try {
            $result = $this->payouts->request($userId, $method, $account, $amount);
        } catch (PayoutException $e) {
            errors_set($this->fieldErrorsFor($e));
            old_set($input);
            flash_set('danger', $e->getMessage());
            redirect($back);
        } catch (WalletException $e) {
            errors_set(['amount' => $e->getMessage()]);
            old_set($input);
            flash_set('danger', $e->getMessage());
            redirect($back);
        } catch (Throwable $e) {
            error_log('[ecowealth] payout request failed: ' . $e->getMessage());
            flash_set('danger', 'The payout request could not be completed. Please try again.');
            redirect($back);
        }

        old_clear();

        /* The POST redirects to a normal page load; queue every surface
         * the hold touched so other open tabs refresh on landing. */
        Live::publish([
            Live::ADMIN_PAYOUTS,
            Live::payoutSurface($userId),
            Live::memberWalletSurface($userId),
            Live::memberDashboardSurface($userId),
            Live::membershipSurface($userId),
            'admin.wallets',
            Live::walletSurface($userId),
            Live::memberSurface($userId),
        ]);

        flash_set('success', $this->successMessage($result));

        redirect($back);
    }

    /**
     * The flash line after a request is queued.
     *
     * @param array<string, mixed> $result
     */
    public function successMessage(array $result): string
    {
        $message = sprintf(
            '%s is held for payout by %s to %s.',
            money((float) $result['amount']),
            (string) $result['method_label'],
            (string) $result['account']
        );

        $fee = (float) $result['fee_amount'];

        if ($fee > 0) {
            $message .= sprintf(' The fee is %s, so %s will be sent.', money($fee), money((float) $result['net_amount']));
        }

        if ((bool) $result['usdt']) {
            $message .= sprintf(' That is %s USDT.', $this->usdt((float) $result['usdt_amount']));
        }

        return $message . ' An administrator will process it shortly.';
    }

    /* ----------------------------------------------------------
     *  Live feed
     * ---------------------------------------------------------- */

    /**
     * ETag scope for the payout page.
     *
     * Three things move this page besides a payout action:
     *
     *   · the buckets — every wallet movement (a commission, a purchase)
     *     refreshes the withdrawable card without bumping the payout
     *     surface, so the member's wallet revision is mixed in;
     *   · the saved payout accounts the form prefills — the profile page
     *     writes them in another tab, so its revision is mixed in too;
     *   · the withdrawal rules — the minimum, each channel's switch and
     *     fee, and the ₱/USDT rate with its network fees. Those come from
     *     the admin's **Payout methods** card (or from SQL), and the
     *     fingerprint catches every writer, not only one that touches a
     *     revision.
     *
     * Public so the live tests can prove the mixing without a request.
     */
    public function pageScope(int $userId): string
    {
        return Live::scope($_GET, $userId)
            . '|wallet:' . Live::revisionOrZero(Live::memberWalletSurface($userId))
            . '|profile:' . Live::revisionOrZero(Live::profileSurface($userId))
            . '|payouts:' . PayoutService::settingsFingerprint();
    }

    /**
     * JSON feed for the open payout page.
     *
     * The 304 check runs before the account read, so an unchanged page
     * costs one revision lookup. No fragment renders a form, so the
     * session lock is released immediately.
     */
    private function feed(int $userId): void
    {
        /* The request region can carry the request form (and its CSRF
         * field), so prime the token before the session lock is released —
         * a token generated after the close could never be persisted. */
        csrf_token();
        session_write_close();

        $surface = Live::payoutSurface($userId);

        try {
            $revision = Live::revision($surface);
            $etag     = Live::etag($surface, $revision, $this->pageScope($userId));

            if (Live::matchesIfNoneMatch(Request::header('If-None-Match'), $etag)) {
                Live::notModified($etag);
            }

            $account = Wallet::account($userId);

            if ($account === null) {
                Live::jsonError('Account not found.', 404);
            }

            Live::json($etag, $this->feedPayload($revision, $etag, $this->payoutContext($account)));
        } catch (Throwable $e) {
            error_log('[ecowealth] payout live feed failed: ' . $e->getMessage());
            Live::jsonError('Could not refresh the payout page. Please try again.', 500);
        }
    }

    /**
     * Render the payout payload. Public so tests can assert the
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
            'surface'   => Live::payoutSurface((int) $account['id']),
            'revision'  => $revision,
            'etag'      => $etag,
            'fragments' => [
                'payout-balance' => View::capture('member/payouts/_balance', [
                    'options' => $data['options'],
                ]),
                'payout-request' => View::capture('member/payouts/_request', [
                    'options'     => $data['options'],
                    'payout_url'  => $data['payout_url'],
                    'profile_url' => $data['profile_url'],
                ]),
                'payout-history' => View::capture('member/payouts/_history', [
                    'requests'      => $data['requests'],
                    'totals'        => $data['totals'],
                    'statement_url' => $data['statement_url'],
                ]),
                'payout-pagination' => View::capture('member/payouts/_pagination', [
                    'pager' => $pager,
                ]),
            ],
            'meta'      => [
                'page'      => $pager->page,
                'pages'     => $pager->pages,
                'total'     => $pager->total,
                'per_page'  => $pager->perPage,
                'canonical' => $this->canonicalUrl($pager),
            ],
        ];
    }

    /** The payout URL with an out-of-range page clamped to a real page. */
    private function canonicalUrl(Paginator $pager): string
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

        return url('payouts' . ($qs !== '' ? '?' . $qs : ''));
    }

    /* ----------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------- */

    /**
     * Put a refusal on the field the member should fix.
     *
     * @return array<string, string>
     */
    private function fieldErrorsFor(PayoutException $e): array
    {
        return match ($e->reason) {
            'amount', 'minimum', 'withdrawable' => ['amount' => $e->getMessage()],
            'open_request'                      => ['amount' => $e->getMessage()],
            'method', 'method_disabled', 'rate' => ['payout_method' => $e->getMessage()],
            'account'                           => ['account' => $e->getMessage()],
            default                             => [],
        };
    }

    /** Format a USDT amount the way the rest of the app shows them. */
    private function usdt(float $amount): string
    {
        return number_format($amount, 4, '.', ',');
    }
}
