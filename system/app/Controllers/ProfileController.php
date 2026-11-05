<?php
declare(strict_types=1);

/* ==============================================================
 *  ProfileController — the member's own account (plan §5.3, Phase 6)
 *
 *  Phase 1 shipped the password change. Phase 6 adds the profile
 *  page and the address book used by checkout:
 *
 *      GET  /profile                          profile + addresses
 *      POST /profile                          full name
 *      POST /profile/address                  create / update one address
 *      POST /profile/address/{id}/delete      remove one address
 *      POST /profile/address/{id}/default     make one address the default
 *
 *  Every action is scoped to the signed-in user; address writes go
 *  through AddressService (one-default rule, ownership checks).
 * ============================================================== */

final class ProfileController
{
    private AddressService $addresses;
    private PaymentDetailsService $payments;

    public function __construct()
    {
        $this->addresses = new AddressService();
        $this->payments  = new PaymentDetailsService();
    }

    /** @param array<string, string> $params */
    public function index(array $params = []): void
    {
        $user   = $this->member();
        $userId = (int) $user['id'];
        $isAdmin = is_admin($user);

        $editId  = (int) Request::query('edit', 0);
        $editing = $editId > 0 ? $this->addresses->find($editId, $userId) : null;

        View::page($isAdmin ? 'admin' : 'member', 'member/profile', [
            'page_title'  => 'Profile',
            'current_nav' => $isAdmin ? 'admin.profile' : 'profile',
            'page_css'    => [
                'assets/css/orders.css',
                'assets/vendor/libs/sweetalert2/sweetalert2.css',
            ],
            'page_js'     => [
                'assets/vendor/libs/sweetalert2/sweetalert2.js',
                'assets/js/app-confirm.js',
            ],
            'user'        => $user,
            'addresses'   => $this->addresses->forUser($userId),
            'editing'     => $editing,
            'payments'    => $this->payments->forUser($userId),
            'posted'      => old_payload(),
        ]);
    }

    /** POST /profile/payment — the member's payout accounts. */
    public function savePayment(array $params = []): void
    {
        $user   = $this->member();
        $userId = (int) $user['id'];

        $input = [
            'gcash_number'       => Request::string('gcash_number'),
            'maya_number'        => Request::string('maya_number'),
            'usdt_trc20_address' => Request::string('usdt_trc20_address'),
            'usdt_bep20_address' => Request::string('usdt_bep20_address'),
        ];

        $errors = $this->payments->validate($input);

        if ($errors !== []) {
            errors_set($errors);
            old_payload_set($input);
            flash_set('danger', 'Please fix the highlighted payout details.');
            redirect('profile');
        }

        try {
            $this->payments->save($userId, $input);
        } catch (InvalidArgumentException $e) {
            errors_set(['payments' => $e->getMessage()]);
            old_payload_set($input);
            flash_set('danger', $e->getMessage());
            redirect('profile');
        }

        old_payload_clear();
        flash_set('success', 'Payout details saved.');
        redirect('profile');
    }

    /** POST /profile */
    public function save(array $params = []): void
    {
        $user = $this->member();

        $input  = ['full_name' => Request::string('full_name')];
        $errors = Validator::check($input, [
            'full_name' => 'required|string|min:2|max:100',
        ], [
            'full_name' => 'Full name',
        ]);

        if ($errors !== []) {
            errors_set($errors);
            old_set($input);
            flash_set('danger', 'Please fix the highlighted profile fields.');
            redirect('profile');
        }

        db()->prepare('UPDATE users SET full_name = ? WHERE id = ?')
            ->execute([mb_substr($input['full_name'], 0, 100), (int) $user['id']]);

        old_clear();
        flash_set('success', 'Profile updated.');
        redirect('profile');
    }

    /** POST /profile/address */
    public function saveAddress(array $params = []): void
    {
        $user   = $this->member();
        $userId = (int) $user['id'];
        $id     = (int) Request::post('address_id', 0);

        $input = [
            'label'          => Request::string('label'),
            'recipient_name' => Request::string('recipient_name'),
            'phone'          => Request::string('phone'),
            'address_line'   => Request::string('address_line'),
            'barangay'       => Request::string('barangay'),
            'city'           => Request::string('city'),
            'province'       => Request::string('province'),
            'postal'         => Request::string('postal'),
            'billing_name'   => Request::string('billing_name'),
            'billing_phone'  => Request::string('billing_phone'),
            'notes'          => Request::string('notes'),
            'is_default'     => Request::post('is_default') === '1' ? 1 : 0,
        ];

        $errors = $this->addresses->validate($input);

        if ($errors !== []) {
            errors_set($errors);
            old_payload_set($input + ['address_id' => $id]);
            flash_set('danger', 'Please fix the highlighted address fields.');
            redirect($id > 0 ? 'profile?edit=' . $id : 'profile');
        }

        try {
            $this->addresses->save($userId, $input, $id > 0 ? $id : null);
        } catch (InvalidArgumentException $e) {
            errors_set(['address' => $e->getMessage()]);
            old_payload_set($input + ['address_id' => $id]);
            flash_set('danger', $e->getMessage());
            redirect($id > 0 ? 'profile?edit=' . $id : 'profile');
        }

        old_payload_clear();
        flash_set('success', $id > 0 ? 'Address updated.' : 'Address saved.');
        redirect('profile');
    }

    /** POST /profile/address/{id}/delete */
    public function deleteAddress(array $params = []): void
    {
        $user = $this->member();

        $removed = $this->addresses->remove((int) ($params['id'] ?? 0), (int) $user['id']);

        flash_set(
            $removed ? 'success' : 'danger',
            $removed ? 'Address removed.' : 'That address could not be found.'
        );

        redirect('profile');
    }

    /** POST /profile/address/{id}/default */
    public function defaultAddress(array $params = []): void
    {
        $user = $this->member();

        $ok = $this->addresses->setDefault((int) ($params['id'] ?? 0), (int) $user['id']);

        flash_set(
            $ok ? 'success' : 'danger',
            $ok ? 'Default address updated.' : 'That address could not be found.'
        );

        redirect('profile');
    }

    /** @param array<string, string> $params */
    public function password(array $params = []): void
    {
        View::page('member', 'profile/password', [
            'page_title'  => 'Change password',
            'current_nav' => 'password',
        ]);
    }

    /** @param array<string, string> $params */
    public function savePassword(array $params = []): void
    {
        $user = $this->member();

        $current = (string) Request::post('current_password', '');
        $new     = (string) Request::post('new_password', '');
        $confirm = (string) Request::post('confirm_password', '');

        $errors = Validator::check(
            ['current_password' => $current, 'new_password' => $new, 'confirm_password' => $confirm],
            [
                'current_password' => 'required',
                'new_password'     => 'required|string|min:8|max:100',
                'confirm_password' => 'required',
            ],
            [
                'current_password' => 'Current password',
                'new_password'     => 'New password',
                'confirm_password' => 'Confirmation',
            ]
        );

        $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([(int) $user['id']]);
        $hash = (string) $stmt->fetchColumn();

        if ($errors === [] && !password_verify($current, $hash)) {
            $errors['current_password'] = 'Your current password is not correct.';
        }

        if ($errors === [] && $new !== $confirm) {
            $errors['confirm_password'] = 'The new password and confirmation do not match.';
        }

        if ($errors === [] && password_verify($new, $hash)) {
            $errors['new_password'] = 'Choose a password you have not used before.';
        }

        if ($errors !== []) {
            errors_set($errors);
            redirect('profile/password');
        }

        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_BCRYPT), (int) $user['id']]);

        /* A password change ends every remembered session for the account. */
        db()->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute([(int) $user['id']]);

        session_regenerate_id(true);
        flash_set('success', 'Your password has been updated. Use it the next time you sign in.');
        redirect('profile/password');
    }

    /** @return array<string, mixed> */
    private function member(): array
    {
        $user = auth_user();

        if ($user === null) {
            flash_set('warning', 'Please sign in to continue.');
            redirect('login');
        }

        return $user;
    }
}
