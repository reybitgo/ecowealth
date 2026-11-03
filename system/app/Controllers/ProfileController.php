<?php
declare(strict_types=1);

/* ==============================================================
 *  ProfileController — the member's own account
 *
 *  Phase 1 ships the password change only (reference:
 *  admin_change_password); profile editing and the address book
 *  arrive with the member profile in Phase 6. Every action is
 *  scoped to the signed-in user.
 * ============================================================== */

final class ProfileController
{
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
        $user = auth_user();

        if ($user === null) {
            redirect('login');
        }

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
}
