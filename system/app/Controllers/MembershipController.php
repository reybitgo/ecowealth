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

        $userId = (int) $user['id'];
        $member = Member::find($userId);

        if ($member === null) {
            View::error(404);
        }

        $status     = (string) $member['member_status'];
        $isAdmin    = is_admin($user);
        $hasPackage = $member['package_id'] !== null;

        $packages = PackageService::publicCards();
        $minimum  = null;

        foreach ($packages as $package) {
            $price   = (float) $package['price'];
            $minimum = $minimum === null ? $price : min($minimum, $price);
        }

        $balance = $this->wallet->balance($userId);

        View::page($isAdmin ? 'admin' : 'member', 'member/membership', [
            'page_title'        => 'Membership',
            'current_nav'       => $isAdmin ? 'admin.membership' : 'membership',
            'page_css'          => ['assets/css/membership.css'],
            'member'            => $member,
            'status'            => $status,
            'has_package'       => $hasPackage,
            'can_activate'      => in_array($status, ['pending', 'inactive'], true),
            'is_standard'       => $status === 'active' && !$hasPackage,
            'packages'          => $packages,
            'balance'           => $balance,
            'withdrawable'      => $this->wallet->withdrawable($userId),
            'minimum_price'     => $minimum,
            'open_activation'   => Activation::latestOpen($userId),
            'history'           => Activation::forUser($userId),
            'referral_link'     => url('register?ref=' . rawurlencode((string) ($member['referral_code'] ?? ''))),
            'channels'          => self::receivingAccounts(),
            'free_registration' => setting_bool('free_registration_enabled', true),
        ]);
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
