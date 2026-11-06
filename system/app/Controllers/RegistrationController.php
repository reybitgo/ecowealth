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
 *  The binary-upline checker (auto_select_upline / check_upline) is
 *  NOT ported: V3.0 has no binary tree, so the endpoint answers a
 *  sponsor question instead.
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

    /** POST /register/check-sponsor — resolve a username OR referral code. */
    public function checkSponsor(array $params = []): void
    {
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

        json_response([
            'ok'            => true,
            'valid'         => true,
            'message'       => 'Sponsor found.',
            'username'      => (string) $sponsor['username'],
            'referral_code' => (string) ($sponsor['referral_code'] ?? ''),
            'package_name'  => $sponsor['package_name'] === null ? null : (string) $sponsor['package_name'],
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
