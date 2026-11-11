<?php
declare(strict_types=1);

/* ==============================================================
 *  TransferController — the member's send-money page (plan §5.8,
 *  §7 Phase 12; reference MemberController::ewalletTransfer /
 *  doEwalletTransfer and views/member/ewallet_transfer.php)
 *
 *  Routes (Appendix A):
 *      GET  /wallet/transfer    the form, fee preview, limit usage, history
 *      POST /wallet/transfer    password-confirmed transfer
 *
 *  The router owns the `member` guard and the central CSRF check
 *  (the reference left both pages unguarded — plan §3.4). The money
 *  movement is TransferService::send(); this controller only validates
 *  the field shape, maps a refusal onto the right field and picks the
 *  response.
 *
 *  The page is one live surface per member (`member.transfer:{id}`).
 *  The buckets, the limit meters and the recent list are regions; the
 *  form itself is never replaced, so a background refresh cannot wipe
 *  what the member is typing. Admins get the same page inside the
 *  back-office layout (the `member` guard admits them, plan §3.4) and
 *  send without a fee or a limit.
 * ============================================================== */

final class TransferController
{
    private TransferService $transfers;

    public function __construct()
    {
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

        if (Live::isFeedRequest()) {
            $this->feed($userId);
            return;
        }

        $account = Wallet::account($userId);

        if ($account === null) {
            View::error(404);
        }

        View::page($isAdmin ? 'admin' : 'member', 'member/transfer', $this->transferContext($account) + [
            'page_title'  => 'Send funds',
            'current_nav' => $isAdmin ? 'admin.transfer' : 'transfer',
            'page_css'    => ['assets/css/wallet.css'],
            'page_js'     => ['assets/js/app-transfer.js', 'assets/js/admin-live.js'],
            'live'        => Live::meta(Live::transferSurface($userId), $this->pageScope($userId)),
        ]);
    }

    /**
     * Everything the page renders, built once for the page and its feed.
     * Public so the view tests can assert the real screen data.
     *
     * @param array<string, mixed> $account
     * @return array<string, mixed>
     */
    public function transferContext(array $account): array
    {
        $userId = (int) $account['id'];
        $pager  = Paginator::fromTableRequest(Transfer::countFor($userId));

        return [
            'account'      => $account,
            'limits'       => $this->transfers->usageFor($account),
            'recent'       => Transfer::forMember($userId, $pager),
            'pager'        => $pager,
            'totals'       => Transfer::memberTotals($userId),
            'transfer_url' => url('wallet/transfer'),
            'statement_url' => url('wallet'),
            'prefill_to'   => Request::string('to'),
        ];
    }

    /**
     * POST /wallet/transfer — password-confirmed transfer.
     *
     * @param array<string, string> $params
     */
    public function send(array $params = []): void
    {
        $user = auth_user();

        if ($user === null) {
            redirect('login');
        }

        $userId = (int) $user['id'];
        $back   = 'wallet/transfer';

        /* Abuse guard (plan §3.7): ten transfer attempts per quarter hour.
         * A refusal moves no money and writes no row. */
        if (!Throttle::hit('transfer:' . $userId, 10, 900)) {
            flash_set('danger', 'Too many transfer attempts. Please wait a few minutes and try again.');
            redirect($back);
        }

        $input = [
            'recipient' => Request::string('recipient'),
            'amount'    => Request::string('amount'),
            'note'      => Request::string('note'),
            'password'  => (string) Request::post('password', ''),
        ];

        $errors = Validator::check($input, [
            'recipient' => 'required|string|max:50',
            'amount'    => 'required|numeric|gt:0|decimal:2|lte:9999999999.99',
            'note'      => 'nullable|string|max:255',
            'password'  => 'required|string|max:200',
        ], [
            'recipient' => 'Recipient username',
            'amount'    => 'Amount',
            'note'      => 'Note',
            'password'  => 'Password',
        ]);

        if ($errors !== []) {
            errors_set($errors);
            old_set($input);

            /* Only non-negotiable fields come back: a wrong password or a
             * refused transfer must be re-entered from scratch. */
            flash_set('danger', 'Please fix the highlighted transfer fields.');
            redirect($back . ($input['recipient'] !== '' ? '?to=' . rawurlencode($input['recipient']) : ''));
        }

        try {
            $result = $this->transfers->send(
                $userId,
                $input['recipient'],
                $input['amount'],
                $input['note'],
                $input['password']
            );
        } catch (TransferException $e) {
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
            error_log('[ecowealth] wallet transfer failed: ' . $e->getMessage());
            flash_set('danger', 'The transfer could not be completed. Please try again.');
            redirect($back);
        }

        old_clear();

        /* The POST redirects to a normal page load; queue every surface
         * the movement touched so other open tabs refresh on landing. */
        $senderId    = (int) $result['sender_id'];
        $recipientId = (int) $result['recipient_id'];

        Live::publish([
            'admin.wallets',
            Live::walletSurface($senderId),
            Live::walletSurface($recipientId),
            Live::memberWalletSurface($senderId),
            Live::memberWalletSurface($recipientId),
            Live::memberDashboardSurface($senderId),
            Live::memberDashboardSurface($recipientId),
            Live::membershipSurface($senderId),
            Live::membershipSurface($recipientId),
            Live::checkoutSurface($senderId),
            Live::transferSurface($senderId),
            Live::transferSurface($recipientId),
        ]);

        flash_set('success', $this->successMessage($result));

        redirect($back);
    }

    /**
     * The flash line after a completed transfer.
     *
     * @param array<string, mixed> $result
     */
    public function successMessage(array $result): string
    {
        $fee = (float) $result['fee'];

        if ($fee > 0) {
            return sprintf(
                'Sent %s to @%s. The fee of %s brought the total debit to %s.',
                money((float) $result['amount']),
                (string) $result['recipient_username'],
                money($fee),
                money((float) $result['total_debit'])
            );
        }

        return sprintf(
            'Sent %s to @%s — no fee was charged.',
            money((float) $result['amount']),
            (string) $result['recipient_username']
        );
    }

    /* ----------------------------------------------------------
     *  Live feed
     * ---------------------------------------------------------- */

    /**
     * ETag scope for the transfer page.
     *
     * The buckets move on every wallet movement, not only on a transfer,
     * so the member's wallet revision is mixed into the scope: a
     * commission or a purchase refreshes the balance card even though it
     * does not bump the transfer surface itself.
     *
     * Public so the live tests can prove the mixing without a request.
     */
    public function pageScope(int $userId): string
    {
        return Live::scope($_GET, $userId)
            . '|wallet:' . Live::revisionOrZero(Live::memberWalletSurface($userId));
    }

    /**
     * JSON feed for the open transfer page.
     *
     * The 304 check runs before the account read, so an unchanged page
     * costs one revision lookup. No fragment renders a form, so the
     * session lock is released immediately.
     */
    private function feed(int $userId): void
    {
        session_write_close();

        $surface = Live::transferSurface($userId);

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

            Live::json($etag, $this->feedPayload($revision, $etag, $this->transferContext($account)));
        } catch (Throwable $e) {
            error_log('[ecowealth] transfer live feed failed: ' . $e->getMessage());
            Live::jsonError('Could not refresh the transfer page. Please try again.', 500);
        }
    }

    /**
     * Render the transfer payload. Public so tests can assert the
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
            'surface'   => Live::transferSurface((int) $account['id']),
            'revision'  => $revision,
            'etag'      => $etag,
            'fragments' => [
                'member-transfer-balance' => View::capture('member/transfer/_balance', [
                    'limits' => $data['limits'],
                ]),
                'member-transfer-limits' => View::capture('member/transfer/_limits', [
                    'limits' => $data['limits'],
                ]),
                'member-transfer-recent' => View::capture('member/transfer/_recent', [
                    'recent'     => $data['recent'],
                    'account'    => $account,
                    'totals'     => $data['totals'],
                    'statement_url' => $data['statement_url'],
                ]),
                'member-transfer-pagination' => View::capture('member/transfer/_recent_pagination', [
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

    /** The transfer URL with an out-of-range page clamped to a real page. */
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

        return url('wallet/transfer' . ($qs !== '' ? '?' . $qs : ''));
    }

    /* ----------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------- */

    /**
     * Put a refusal on the field the member should fix.
     *
     * @return array<string, string>
     */
    private function fieldErrorsFor(TransferException $e): array
    {
        return match ($e->reason) {
            'password'                        => ['password' => $e->getMessage()],
            'amount', 'minimum', 'balance',
            'daily_limit', 'weekly_limit'     => ['amount' => $e->getMessage()],
            'recipient', 'inactive', 'self'   => ['recipient' => $e->getMessage()],
            default                           => [],
        };
    }
}
