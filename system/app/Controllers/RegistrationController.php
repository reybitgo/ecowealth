<?php
declare(strict_types=1);

/* ==============================================================
 *  RegistrationController — live validation for the register form
 *  (plan §2.5, §7 Phase 9, Appendix A; reference AuthController::ajax*)
 *
 *  Routes (guard `any` — every one is public by design):
 *      POST /register/validate-code    reference validate_code
 *      POST /register/check-username   reference check_username
 *      POST /register/check-sponsor    reference check_upline, re-specified
 *      GET  /register/packages         reference get_packages
 *
 *  The reference kept a separate `check_upline` / `auto_select_upline`
 *  route. V3.0 folds the binary-upline lookup into check-sponsor:
 *  posting `placement=1` asks about the binary upline instead of a
 *  sponsor and answers with the immediate left/right slots. That keeps
 *  the public route surface (and the parity inventory) unchanged.
 *
 *  The router already verified the CSRF token on each POST. These
 *  endpoints never write anything except flipping an expired code to
 *  `expired` (CodeService::validate does that on purpose).
 * ============================================================== */

final class RegistrationController
{
    /** POST /register/validate-code — is this code usable? */
    public function validateCode(array $params = []): void
    {
        $typed   = Request::string('code');
        $service = new CodeService();
        $result  = $service->validate($typed);

        if (!$result['valid']) {
            json_response([
                'ok'      => true,
                'valid'   => false,
                'message' => (string) $result['message'],
            ]);
        }

        /** @var array<string, mixed> $row */
        $row = $result['code'];

        json_response([
            'ok'           => true,
            'valid'        => true,
            'message'      => (string) $result['message'],
            'code'         => (string) $row['code'],
            'package_id'   => (int) $row['package_id'],
            'package_name' => (string) $row['package_name'],
            'price'        => (float) $row['package_price'],
            'price_label'  => money((float) $row['package_price']),
            'expires_at'   => $row['expires_at'] === null ? null : (string) $row['expires_at'],
        ]);
    }

    /** POST /register/check-username — format + availability. */
    public function checkUsername(array $params = []): void
    {
        $username = trim(Request::string('username'));

        if ($username === '') {
            json_response(['ok' => true, 'available' => false, 'message' => 'Enter a username.']);
        }

        if (preg_match(RegistrationService::USERNAME_PATTERN, $username) !== 1) {
            json_response([
                'ok'        => true,
                'available' => false,
                'message'   => 'Use 3–50 letters, numbers, dot, underscore or dash.',
            ]);
        }

        if (Member::usernameExists($username)) {
            json_response(['ok' => true, 'available' => false, 'message' => 'This username is already taken.']);
        }

        json_response(['ok' => true, 'available' => true, 'message' => 'Username is available.']);
    }

    /**
     * POST /register/check-sponsor — resolve a username OR referral code.
     *
     * With `placement=1` the same request checks the binary upline
     * instead: the `sponsor` field carries the upline identifier (or is
     * blank to ask for the sponsor/company default), and the response
     * carries the immediate left/right slots with their occupant names
     * and legality.
     */
    public function checkSponsor(array $params = []): void
    {
        if (Request::string('placement') === '1') {
            $this->checkPlacement();

            return;
        }

        $typed    = Request::string('sponsor');
        $sponsors = new SponsorService();
        $sponsor  = $sponsors->resolve($typed);

        if ($sponsor === null) {
            json_response([
                'ok'      => true,
                'valid'   => false,
                'message' => 'No account or referral code matches that.',
            ]);
        }

        if (!$sponsors->isEligible($sponsor)) {
            json_response([
                'ok'      => true,
                'valid'   => false,
                'message' => 'That sponsor account is not active.',
            ]);
        }

        $placement = new BinaryPlacementService();
        $slots     = $placement->isEnabled()
            ? $placement->freeSlotsInSubtree((int) $sponsor['id'], 20)
            : [];

        json_response([
            'ok'            => true,
            'valid'         => true,
            'message'       => 'Sponsor found.',
            'username'      => (string) $sponsor['username'],
            'referral_code' => (string) ($sponsor['referral_code'] ?? ''),
            'package_name'  => $sponsor['package_name'] === null ? null : (string) $sponsor['package_name'],
            'slots'         => $slots,
            'suggested'     => $slots !== []
                ? [
                    'upline_id' => (int) $slots[0]['upline_id'],
                    'side'      => (string) $slots[0]['side'],
                ]
                : null,
        ]);
    }

    /**
     * The placement branch of check-sponsor: resolve the binary upline
     * (a blank field means the sponsor's node, or the company root when
     * the sponsor is staff / not placed) and report both immediate
     * slots. A side is `legal` only when it is free **and** the current
     * registrar may place there, so the picker never promises a slot
     * the final validation would refuse.
     */
    private function checkPlacement(): void
    {
        $typed          = Request::string('sponsor');
        $currentSponsor = Request::string('current_sponsor');
        $sponsors       = new SponsorService();
        $placement      = new BinaryPlacementService();

        if (!$placement->isEnabled()) {
            json_response([
                'ok'      => true,
                'valid'   => false,
                'message' => 'Binary placement is not available right now.',
            ]);
        }

        $contextSponsor = $currentSponsor === ''
            ? $sponsors->defaultSponsor()
            : $sponsors->resolve($currentSponsor);

        if ($typed !== '') {
            $upline = $placement->resolveUpline($typed);

            if ($upline === null) {
                json_response([
                    'ok'      => true,
                    'valid'   => false,
                    'message' => 'No member matches that username or referral code.',
                ]);
            }
        } else {
            /* Mirror RegistrationService::placeBinaryNode(): the sponsor's
             * node is the default upline; staff accounts and sponsors
             * without a node fall back to the company root. */
            $base = $contextSponsor;

            if ($base === null) {
                json_response([
                    'ok'      => true,
                    'valid'   => false,
                    'message' => 'Enter a sponsor first, or choose an upline.',
                ]);
            }

            if ((string) ($base['role'] ?? 'member') !== 'member') {
                $root   = $placement->ensureRoot();
                $upline = Member::find((int) $root['user_id']);
            } else {
                $upline = BinaryNode::forUser((int) $base['id']) === null
                    ? Member::find((int) $placement->ensureRoot()['user_id'])
                    : $base;
            }
        }

        if ($upline === null) {
            json_response([
                'ok'      => true,
                'valid'   => false,
                'message' => 'That account could not be used as an upline.',
            ]);
        }

        $uplineId = (int) $upline['id'];
        $node     = BinaryNode::forUser($uplineId);

        if ($node === null) {
            json_response([
                'ok'      => true,
                'valid'   => false,
                'message' => '@' . (string) $upline['username'] . ' is not placed in the binary tree yet.',
            ]);
        }

        if (!$sponsors->isEligible($upline)) {
            json_response([
                'ok'      => true,
                'valid'   => false,
                'message' => '@' . (string) $upline['username'] . ' is not an active account.',
            ]);
        }

        $root     = BinaryNode::root();
        $rootId   = (int) ($root['user_id'] ?? 0);

        if ((string) ($upline['role'] ?? 'member') !== 'member' && $uplineId !== $rootId) {
            json_response([
                'ok'      => true,
                'valid'   => false,
                'message' => 'Staff accounts cannot be a binary upline.',
            ]);
        }

        if ((int) $node['depth'] + 1 > BinaryNode::MAX_DEPTH) {
            json_response([
                'ok'      => true,
                'valid'   => false,
                'message' => 'That placement would exceed the maximum tree depth.',
            ]);
        }

        $registrar = auth_user();
        $slots     = $placement->slotOccupants($uplineId);
        $suggested = null;

        foreach ($slots as $side => $slot) {
            $legal = $slot['free'] && $placement->legalUpline($uplineId, 0, $side, [
                'registrar' => $registrar,
                'sponsor'   => $contextSponsor,
            ]);

            $slots[$side]['legal'] = $legal;

            if ($legal && $suggested === null) {
                $suggested = $side;
            }
        }

        json_response([
            'ok'        => true,
            'valid'     => true,
            'message'   => 'Upline found.',
            'username'  => (string) $upline['username'],
            'defaulted' => $typed === '',
            'slots'     => $slots,
            'suggested' => $suggested,
        ]);
    }

    /** GET /register/packages — the packages the e-wallet mode can buy. */
    public function packages(array $params = []): void
    {
        $out = [];

        foreach (Package::all(true) as $package) {
            $out[] = [
                'id'                    => (int) $package['id'],
                'name'                  => (string) $package['name'],
                'slug'                  => (string) $package['slug'],
                'price'                 => (float) $package['price'],
                'price_label'           => money((float) $package['price']),
                'product_value'         => (float) $package['product_value'],
                'product_value_label'   => money((float) $package['product_value']),
                'direct_referral_bonus' => (float) $package['direct_referral_bonus'],
            ];
        }

        json_response([
            'ok'       => true,
            'packages' => $out,
            'free_registration_enabled' => setting_bool('free_registration_enabled', true),
        ]);
    }
}
