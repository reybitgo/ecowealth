<?php
declare(strict_types=1);

/* ==============================================================
 *  MembershipController — the member's own membership page
 *  (plan §5.10, §7 Phase 9; reference MemberController::activate /
 *  do_activate and views/member/activate.php)
 *
 *  Routes (Appendix A):
 *      GET  /membership            status, package, sponsor, history
 *      POST /membership/activate   code | ewallet | manual proof
 *
 *  Only a `pending` / `inactive` member can activate (plan §7):
 *  a standard (free, shopping-only) member has an active membership
 *  without a package and reads a clear note instead of a form.
 *
 *  The router owns the guard and CSRF; ActivationService owns every
 *  money and status rule.
 * ============================================================== */

final class MembershipController
{
    private ActivationService $activations;
    private WalletService $wallet;

    public function __construct()
    {
        $this->activations = new ActivationService();
        $this->wallet      = new WalletService();
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

        /* The same URL doubles as the JSON feed: X-Live: 1 returns the
         * regions instead of the layout. The surface is per member, so one
         * member's activation never refreshes another member's page. */
        if (Live::isFeedRequest()) {
            $this->feed($userId);
            return;
        }

        $member = Member::find($userId);

        if ($member === null) {
            View::error(404);
        }

        View::page($isAdmin ? 'admin' : 'member', 'member/membership', $this->membershipContext($member) + [
            'page_title'  => 'Membership',
            'current_nav' => $isAdmin ? 'admin.membership' : 'membership',
            'page_css'    => ['assets/css/membership.css'],
            'page_js'     => ['assets/js/admin-live.js'],
            'live'        => Live::meta(
                Live::membershipSurface($userId),
                Live::scope($_GET, $userId)
            ),
        ]);
    }

    /**
     * Everything the membership page renders, built once for the page and
     * its feed. Public so the view tests can assert the real screen data.
     *
     * @param array<string, mixed> $member
     * @return array<string, mixed>
     */
    public function membershipContext(array $member): array
    {
        $userId     = (int) $member['id'];
        $status     = (string) $member['member_status'];
        $hasPackage = $member['package_id'] !== null;

        $packages = PackageService::publicCards();
        $minimum  = null;

        foreach ($packages as $package) {
            $price   = (float) $package['price'];
            $minimum = $minimum === null ? $price : min($minimum, $price);
        }

        /* The history is a growing table, so it paginates like every
         * other list (10 by default, 5–100 per page). */
        $historyPager = Paginator::fromTableRequest(Activation::countForUser($userId));
        $history      = Activation::pageForUser($userId, $historyPager);

        $displayName = trim((string) ($member['full_name'] ?? '')) !== ''
            ? (string) $member['full_name']
            : (string) $member['username'];

        $statusLabel = match (true) {
            $status === 'active' && $hasPackage => 'Active associate',
            $status === 'active'                => 'Standard member',
            $status === 'pending'               => 'Pending activation',
            $status === 'suspended'             => 'Suspended',
            default                             => ucfirst($status),
        };

        $statusTone = match ($status) {
            'active'    => 'success',
            'pending'   => 'warning',
            'suspended' => 'danger',
            default     => 'secondary',
        };

        return [
            'member'            => $member,
            'status'            => $status,
            'has_package'       => $hasPackage,
            'can_activate'      => in_array($status, ['pending', 'inactive'], true),
            'is_standard'       => $status === 'active' && !$hasPackage,
            'packages'          => $packages,
            'balance'           => $this->wallet->balance($userId),
            'withdrawable'      => $this->wallet->withdrawable($userId),
            'minimum_price'     => $minimum,
            'open_activation'   => Activation::latestOpen($userId),
            'history'           => $history,
            'history_pager'     => $historyPager,
            'referral_link'     => absolute_url('register?ref=' . rawurlencode((string) ($member['referral_code'] ?? ''))),
            'channels'          => self::receivingAccounts(),
            'free_registration' => setting_bool('free_registration_enabled', true),
            'display_name'      => $displayName,
            'status_label'      => $statusLabel,
            'status_tone'       => $statusTone,
        ];
    }

    /**
     * JSON feed for the open membership page.
     *
     * The 304 check runs before membershipContext(), so an unchanged page
     * costs one revision lookup and no queries. The activation-path region
     * carries POST forms, so the CSRF token is primed before the session
     * lock is released.
     */
    private function feed(int $userId): void
    {
        csrf_token();
        session_write_close();

        $surface = Live::membershipSurface($userId);

        try {
            $revision = Live::revision($surface);
            $etag     = Live::etag($surface, $revision, Live::scope($_GET, $userId));

            if (Live::matchesIfNoneMatch(Request::header('If-None-Match'), $etag)) {
                Live::notModified($etag);
            }

            $member = Member::find($userId);

            if ($member === null) {
                Live::jsonError('Member not found.', 404);
            }

            Live::json($etag, $this->feedPayload($revision, $etag, $this->membershipContext($member)));
        } catch (Throwable $e) {
            error_log('[ecowealth] membership live feed failed: ' . $e->getMessage());
            Live::jsonError('Could not refresh your membership page. Please try again.', 500);
        }
    }

    /**
     * Render the membership payload. Public so tests can assert the
     * fragments without going through Live::json()'s exit.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function feedPayload(int $revision, string $etag, array $data): array
    {
        $member = $data['member'];
        $pager  = $data['history_pager'];

        return [
            'ok'        => true,
            'surface'   => Live::membershipSurface((int) $member['id']),
            'revision'  => $revision,
            'etag'      => $etag,
            'fragments' => [
                'membership-header' => View::capture('member/membership/_header', [
                    'status'       => $data['status'],
                    'status_label' => $data['status_label'],
                    'status_tone'  => $data['status_tone'],
                ]),
                'membership-current' => View::capture('member/membership/_current', [
                    'member'       => $member,
                    'display_name' => $data['display_name'],
                    'has_package'  => $data['has_package'],
                ]),
                'membership-paths' => View::capture('member/membership/_paths', [
                    'status'            => $data['status'],
                    'has_package'       => $data['has_package'],
                    'can_activate'      => $data['can_activate'],
                    'is_standard'       => $data['is_standard'],
                    'packages'          => $data['packages'],
                    'balance'           => $data['balance'],
                    'withdrawable'      => $data['withdrawable'],
                    'minimum_price'     => $data['minimum_price'],
                    'open_activation'   => $data['open_activation'],
                    'channels'          => $data['channels'],
                    'free_registration' => $data['free_registration'],
                ]),
                'membership-history' => View::capture('member/membership/_history', [
                    'history'       => $data['history'],
                    'history_pager' => $pager,
                ]),
            ],
            'meta'      => [
                'page'      => $pager->page,
                'pages'     => $pager->pages,
                'total'     => $pager->total,
                'per_page'  => $pager->perPage,
                'canonical' => $this->canonicalMembershipUrl($pager),
            ],
        ];
    }

    /** The membership URL with an out-of-range history page clamped. */
    private function canonicalMembershipUrl(Paginator $pager): string
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

        return url('membership' . ($qs !== '' ? '?' . $qs : ''));
    }

    /** POST /membership/activate — code / wallet / manual proof. */
    public function activate(array $params = []): void
    {
        $user = auth_user();

        if ($user === null) {
            redirect('login');
        }

        $userId = (int) $user['id'];

        $input = [
            'method'       => strtolower(Request::string('method')),
            'reg_code'     => Request::string('reg_code'),
            'package_id'   => Request::string('package_id'),
            'channel'      => strtolower(Request::string('channel')),
            'reference_no' => Request::string('reference_no'),
        ];

        $file = $_FILES['proof'] ?? null;

        try {
            $result = $this->activations->request($userId, $input, is_array($file) ? $file : null);
        } catch (ActivationException $e) {
            errors_set($e->fieldErrors === [] ? ['method' => $e->getMessage()] : $e->fieldErrors);
            old_set($input);
            flash_set('danger', $e->getMessage());
            redirect('membership');
        } catch (CodeException $e) {
            errors_set($e->fieldErrors === [] ? ['reg_code' => $e->getMessage()] : $e->fieldErrors);
            old_set($input);
            flash_set('danger', $e->getMessage());
            redirect('membership');
        } catch (Throwable $e) {
            error_log('[ecowealth] membership activation failed: ' . $e->getMessage());
            flash_set('danger', 'The activation could not be completed. Please try again.');
            redirect('membership');
        }

        old_clear();

        $memberId = (int) ($result['user_id'] ?? $userId);

        if ($memberId <= 0) {
            $memberId = $userId;
        }

        /* The admin queue gained a row (manual proof) or the member went
         * active; the member page's activation history shows both. The
         * directory and the wallet monitor only move on an approval. */
        $publish = ['admin.activations', Live::memberSurface($memberId), Live::membershipSurface($memberId)];

        if ((string) $result['status'] !== 'pending_review') {
            $publish[] = 'admin.dashboard';
            $publish[] = 'admin.wallets';
            $publish[] = 'admin.members';
            $publish[] = 'admin.commissions';
            $publish[] = Live::walletSurface($memberId);
            $publish[] = Live::memberWalletSurface($memberId);
            $publish[] = Live::GENEALOGY_TEAM;
        }

        Live::publish($publish);

        if ((string) $result['status'] === 'pending_review') {
            flash_set(
                'success',
                'Your payment proof is with the review team. You will become active as soon as it is approved.'
            );
        } else {
            flash_set('success', sprintf(
                'Welcome aboard! Your membership is active with %s.',
                (string) ($result['package_name'] ?? 'your package')
            ));
        }

        redirect('membership');
    }

    /**
     * Company receiving accounts shown beside the manual-payment option
     * (same settings the checkout uses; plan §5.4, D9).
     *
     * @return array{gcash: array{number:string, name:string}, maya: array{number:string, name:string}, bank: array{bank:string, account_name:string, account_number:string}}
     */
    public static function receivingAccounts(): array
    {
        return [
            'gcash' => [
                'number' => (string) setting('payment_gcash_number', ''),
                'name'   => (string) setting('payment_gcash_name', ''),
            ],
            'maya' => [
                'number' => (string) setting('payment_maya_number', ''),
                'name'   => (string) setting('payment_maya_name', ''),
            ],
            'bank' => [
                'bank'           => (string) setting('payment_bank_name', ''),
                'account_name'   => (string) setting('payment_bank_account_name', ''),
                'account_number' => (string) setting('payment_bank_account_number', ''),
            ],
        ];
    }
}
