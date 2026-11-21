<?php
declare(strict_types=1);

/* ==============================================================
 *  AdminPayoutController — the withdrawal queue (plan §5.9,
 *  §7 Phase 13; reference AdminController::payouts / payoutAction and
 *  views/admin/payouts.php)
 *
 *  Routes (Appendix A):
 *      GET  /admin/payouts                  status tabs, totals, queue
 *      POST /admin/payouts/{id}/approve     pending → approved
 *      POST /admin/payouts/{id}/reject      pending/approved → rejected (refunds)
 *      POST /admin/payouts/{id}/complete    approved → completed
 *
 *  The router owns the admin guard and the central CSRF check. The
 *  money and the status machine are PayoutService's; this controller
 *  validates the shape of the action, maps a refusal onto the page and
 *  picks the response.
 *
 *  The queue is one live surface (`admin.payouts`): the stat cards, the
 *  tab counts, the rows and the pagination are regions, so an approval
 *  in one tab refreshes the counters in another. The filter form stays
 *  outside every region.
 * ============================================================== */

final class AdminPayoutController
{
    /** Tabs of the queue, in display order ('' = every request). */
    private const TABS = ['pending', 'approved', 'completed', 'rejected', ''];

    private PayoutService $payouts;

    public function __construct()
    {
        $this->payouts = new PayoutService();
    }

    /** @param array<string, string> $params */
    public function index(array $params = []): void
    {
        $tab = $this->currentTab();

        if (Live::isFeedRequest()) {
            $this->feed($tab);
            return;
        }

        View::page('admin', 'admin/payouts/index', $this->queueContext($tab) + [
            'page_title'  => 'Payouts',
            'current_nav' => 'admin.payouts',
            'page_css'    => [
                'assets/css/wallet.css',
                'assets/vendor/libs/sweetalert2/sweetalert2.css',
            ],
            'page_js'     => [
                'assets/vendor/libs/sweetalert2/sweetalert2.js',
                'assets/js/app-confirm.js',
            ],
            'live'        => $this->liveMeta(),
        ]);
    }

    private function currentTab(): string
    {
        /* No `status` at all opens the work queue (**Pending**); an explicit
         * empty `?status=` asks for **All requests**; anything unrecognised
         * falls back to the queue. */
        $raw = Request::query('status');

        if ($raw === null) {
            return 'pending';
        }

        $tab = strtolower(trim((string) $raw));

        return in_array($tab, self::TABS, true) ? $tab : 'pending';
    }

    /**
     * Everything the queue renders, built once for the page and its feed.
     * Public so the view tests can assert the real screen data.
     *
     * @return array<string, mixed>
     */
    public function queueContext(string $tab): array
    {
        $filters = Payout::normaliseFilters([
            'status' => $tab,
            'q'      => Request::string('q'),
            'from'   => Request::string('from'),
            'to'     => Request::string('to'),
        ]);

        $pager = Paginator::fromTableRequest(Payout::queueCount($filters));

        return [
            'tab'          => $tab,
            'filters'      => $filters,
            'queue'        => Payout::queue($filters, $pager),
            'pager'        => $pager,
            'queue_totals' => Payout::queueTotals($filters),
            'tab_counts'   => Payout::statusCounts(),
            'totals'       => $this->payouts->totals(),
            /* The settings the queue is working with, printed beside the
             * cards so staff can see a closed USDT channel at a glance. */
            'facts'        => [
                'min_payout'  => PayoutService::minPayout(),
                'usdt_rate'   => PayoutService::usdtRate(),
                'trc20_fee'   => PayoutService::serviceFeePct('usdt_trc20'),
                'trc20_gas'   => PayoutService::gasFee('usdt_trc20'),
                'bep20_fee'   => PayoutService::serviceFeePct('usdt_bep20'),
                'bep20_gas'   => PayoutService::gasFee('usdt_bep20'),
            ],
            'queue_url'    => url('admin/payouts'),
        ];
    }

    /**
     * ETag scope for the queue.
     *
     * The stat cards include “today” figures only indirectly, but the
     * rows say “x minutes ago”, so the hour is part of the scope: a tab
     * left open revalidates without a write. The facts line under the
     * cards prints the minimum, the ₱/USDT rate and the fees, so the
     * payout-settings fingerprint is mixed in as well — a settings save,
     * or a CLI/SQL edit of the same keys, moves it.
     *
     * Public so the live tests can prove the mixing without a request.
     */
    public function queueScope(int $userId): string
    {
        return Live::scope($_GET, $userId)
            . '|hour:' . date('Y-m-d H')
            . '|payouts:' . PayoutService::settingsFingerprint();
    }

    /** @return array{surface:string, enabled:bool, revision:int, etag:string, interval:int} */
    private function liveMeta(): array
    {
        return Live::meta(Live::ADMIN_PAYOUTS, $this->queueScope((int) (auth_user()['id'] ?? 0)));
    }

    /**
     * JSON feed for the open queue tab.
     *
     * The 304 check runs before queueContext(), so an unchanged surface
     * costs one revision lookup and no list/aggregate queries.
     */
    private function feed(string $tab): void
    {
        $userId = (int) (auth_user()['id'] ?? 0);

        /* Every queue row carries its own action forms and CSRF field, so
         * the token must be primed before the session lock is released —
         * otherwise the replacement rows would carry a token the next
         * request does not recognise. */
        csrf_token();
        session_write_close();

        $surface = Live::ADMIN_PAYOUTS;

        try {
            $revision = Live::revision($surface);
            $etag     = Live::etag($surface, $revision, $this->queueScope($userId));

            if (Live::matchesIfNoneMatch(Request::header('If-None-Match'), $etag)) {
                Live::notModified($etag);
            }

            Live::json($etag, $this->feedPayload($revision, $etag, $this->queueContext($tab)));
        } catch (Throwable $e) {
            error_log('[ecowealth] payout queue feed failed: ' . $e->getMessage());
            Live::jsonError('Could not refresh the payout queue. Please try again.', 500);
        }
    }

    /**
     * Render the queue payload. Public so the view tests can assert the
     * fragments without going through Live::json()'s exit.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function feedPayload(int $revision, string $etag, array $data): array
    {
        $pager = $data['pager'];

        return [
            'ok'        => true,
            'surface'   => Live::ADMIN_PAYOUTS,
            'revision'  => $revision,
            'etag'      => $etag,
            'fragments' => [
                'payout-stats' => View::capture('admin/payouts/_stats', [
                    'totals'  => $data['totals'],
                    'facts'   => $data['facts'],
                ]),
                'payout-tabs' => View::capture('admin/payouts/_tabs', [
                    'tab'        => $data['tab'],
                    'tab_counts' => $data['tab_counts'],
                ]),
                'payout-rows' => View::capture('admin/payouts/_rows', [
                    'queue'  => $data['queue'],
                    'tab'    => $data['tab'],
                    'totals' => $data['queue_totals'],
                ]),
                'payout-pagination' => View::capture('admin/payouts/_pagination', [
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

    /** The queue URL with an out-of-range page clamped to a real page. */
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

        return url('admin/payouts' . ($qs !== '' ? '?' . $qs : ''));
    }

    /* ----------------------------------------------------------
     *  Decisions
     * ---------------------------------------------------------- */

    /** @param array<string, string> $params */
    public function approve(array $params = []): void
    {
        $this->process('approve', (int) ($params['id'] ?? 0));
    }

    /** @param array<string, string> $params */
    public function reject(array $params = []): void
    {
        $this->process('reject', (int) ($params['id'] ?? 0));
    }

    /** @param array<string, string> $params */
    public function complete(array $params = []): void
    {
        $this->process('complete', (int) ($params['id'] ?? 0));
    }

    /**
     * POST /admin/payouts/{id}/{action} — one decision, one flash.
     */
    private function process(string $action, int $payoutId): void
    {
        $admin = auth_user();

        if ($admin === null) {
            redirect('login');
        }

        $request = Payout::find($payoutId);

        if ($request === null) {
            View::error(404);
        }

        $back = $this->backUrl($request);

        /* The rejection reason and the completion note share one field;
         * only a rejection requires it. */
        $note = Request::string('note');

        $input = ['note' => $note];

        $errors = Validator::check($input, [
            'note' => $action === 'reject' ? 'required|string|max:500' : 'nullable|string|max:500',
        ], [
            'note' => 'Note',
        ]);

        if ($errors !== []) {
            errors_set($errors);
            old_set($input);
            flash_set('danger', 'Enter the reason for rejecting this request.');
            redirect($back);
        }

        try {
            $result = match ($action) {
                'approve'  => $this->payouts->approve($payoutId, (int) $admin['id'], $note),
                'reject'   => $this->payouts->reject($payoutId, (int) $admin['id'], $note),
                default    => $this->payouts->complete($payoutId, (int) $admin['id'], $note),
            };
        } catch (PayoutException $e) {
            errors_set(['note' => $e->getMessage()]);
            old_set($input);
            flash_set('danger', $e->getMessage());
            redirect($back);
        } catch (WalletException $e) {
            errors_set(['note' => $e->getMessage()]);
            old_set($input);
            flash_set('danger', $e->getMessage());
            redirect($back);
        } catch (Throwable $e) {
            error_log('[ecowealth] payout ' . $action . ' failed: ' . $e->getMessage());
            flash_set('danger', 'The payout could not be updated. Please try again.');
            redirect($back);
        }

        old_clear();

        /* The POST redirects to a normal page load; queue every surface
         * the decision touched so other open tabs refresh on landing. */
        $memberId = (int) $result['user_id'];

        Live::publish([
            Live::ADMIN_PAYOUTS,
            Live::payoutSurface($memberId),
            Live::memberWalletSurface($memberId),
            Live::memberDashboardSurface($memberId),
            Live::membershipSurface($memberId),
            'admin.wallets',
            Live::walletSurface($memberId),
            Live::memberSurface($memberId),
        ]);

        flash_set('success', $this->decisionMessage($action, $result));

        redirect($back);
    }

    /**
     * The flash line after a decision.
     *
     * @param array<string, mixed> $result
     */
    public function decisionMessage(string $action, array $result): string
    {
        $who = '@' . (string) $result['username'];
        $amount = money((float) $result['amount']);

        return match ($action) {
            'approve' => sprintf('%s for %s approved. Send the funds, then mark it complete.', $amount, $who),
            'reject'  => sprintf('%s for %s rejected — the held amount was returned to their wallet.', $amount, $who),
            default   => sprintf('%s for %s marked completed.', $amount, $who),
        };
    }

    /**
     * Where a decision returns to: the row's own tab, so the queue does
     * not jump to a different status after the request leaves it.
     *
     * @param array<string, mixed> $request
     */
    private function backUrl(array $request): string
    {
        $status = (string) $request['status'];

        /* The row's own form carries the page number, so an approval on
         * page 3 returns to page 3 rather than jumping to the top. */
        $page = Request::input('page');

        $query = ['status' => $status];

        if (is_scalar($page) && (int) $page > 1) {
            $query['page'] = (string) (int) $page;
        }

        return 'admin/payouts?' . http_build_query($query);
    }
}
