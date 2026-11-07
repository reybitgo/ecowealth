<?php
declare(strict_types=1);

/* ==============================================================
 *  Registration page (plan §5.10, §7 Phase 9)
 *
 *  Kept a standalone file outside the router (Appendix A), exactly
 *  like login/logout. The live-validation endpoints it calls are
 *  routed (`/register/validate-code`, …) because they answer JSON.
 *
 *  A guest creates their own account and is signed in on success. A
 *  signed-in member may register someone else; their own session is
 *  left untouched (the reference restored it — nothing here changes
 *  it, which is the same guarantee, tested by P9-T4).
 *
 *  No binary placement: the form collects a sponsor only.
 * ============================================================== */
require __DIR__ . '/../app/bootstrap.php';

/* Registration is part of the public site: it follows maintenance mode. */
MaintenanceGate::handle();

$registrar = auth_user();
$errors    = [];

if (is_post()) {
    if (!csrf_verify($_POST['_csrf'] ?? null)) {
        flash_set('danger', 'Your session expired. Please try again.');
        redirect('register');
    }

    $input = [
        'mode'             => strtolower(trim((string) ($_POST['mode'] ?? 'standard'))),
        'username'         => (string) ($_POST['username'] ?? ''),
        'email'            => (string) ($_POST['email'] ?? ''),
        'full_name'        => (string) ($_POST['full_name'] ?? ''),
        'phone'            => (string) ($_POST['phone'] ?? ''),
        'password'         => (string) ($_POST['password'] ?? ''),
        'password_confirm' => (string) ($_POST['password_confirm'] ?? ''),
        'sponsor'          => (string) ($_POST['sponsor'] ?? ''),
        'package_id'       => (string) ($_POST['package_id'] ?? ''),
        'reg_code'         => (string) ($_POST['reg_code'] ?? ''),
        'terms'            => !empty($_POST['terms']),
    ];

    try {
        $result = (new RegistrationService())->register($input, $registrar);
    } catch (RegistrationException $e) {
        /* A guest trying the e-wallet mode is sent to sign in (P9-T5)
         * instead of being shown a field error on a form they cannot use. */
        if ($e->reason === 'login_required') {
            flash_set('warning', $e->getMessage());
            redirect('login');
        }

        $errors = $e->fieldErrors === [] ? ['_form' => $e->getMessage()] : $e->fieldErrors;
    } catch (CodeException $e) {
        $errors = $e->fieldErrors === [] ? ['reg_code' => $e->getMessage()] : $e->fieldErrors;
    } catch (Throwable $e) {
        error_log('[ecowealth] registration failed: ' . $e->getMessage());
        $errors = ['_form' => 'The account could not be created. Please try again.'];
    }

    if (isset($result)) {
        old_clear();
        errors_set([]);

        $package = (string) ($result['package_name'] ?? '');

        if ($registrar !== null) {
            flash_set('success', sprintf(
                'Account @%s created%s. You are still signed in as @%s.',
                (string) $result['username'],
                $package === '' ? '' : ' and activated with ' . $package,
                (string) $registrar['username']
            ));

            redirect(is_admin($registrar) ? 'admin/members' : 'dashboard');
        }

        /* Guest: sign the new member in (reference behaviour). */
        session_regenerate_id(true);
        $_SESSION['user_id']       = (int) $result['user_id'];
        $_SESSION['logged_in_at']  = time();
        $_SESSION['last_activity'] = time();
        $_SESSION['auth_via']      = 'registration';
        auth_cache_reset();

        db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')
            ->execute([(int) $result['user_id']]);

        flash_set('success', match ((string) $result['mode']) {
            'standard' => 'Welcome! Your standard account is ready — you can start shopping.',
            'pending'  => 'Welcome! Your account is pending. Activate it from Membership when you are ready.',
            default    => sprintf(
                'Welcome aboard! Your membership is active%s.',
                $package === '' ? '' : ' with ' . $package
            ),
        });

        redirect('dashboard');
    }

    /* Failed: keep the typed values and render the form again (PRG).
     * The form itself renders the errors — a global message above the
     * form, and each field's reason under its input — so no danger
     * flash is queued here. Flashing the same message as well showed
     * two identical banners (fixed after the Phase 9 QA pass). */
    errors_set($errors);
    old_set([
        'mode'       => $input['mode'],
        'username'   => $input['username'],
        'email'      => $input['email'],
        'full_name'  => $input['full_name'],
        'phone'      => $input['phone'],
        'sponsor'    => $input['sponsor'],
        'package_id' => $input['package_id'],
        'reg_code'   => $input['reg_code'],
    ]);

    redirect('register');
}

/* --------------------------------------------------------------
 *  Render data — names mirror the view's documented variables.
 * -------------------------------------------------------------- */
$free_enabled   = setting_bool('free_registration_enabled', true);
$packages       = PackageService::publicCards();
$prefill_sponsor = trim((string) ($_GET['sponsor'] ?? ''));
$referral        = trim((string) ($_GET['ref'] ?? ''));

if ($referral !== '') {
    /* A referral link carries the sponsor's public code. */
    $prefill_sponsor = $referral;

    $sponsor = (new SponsorService())->resolve($referral);

    if ($sponsor !== null) {
        $prefill_sponsor = (string) $sponsor['username'];
    }
}

$defaultMode = $free_enabled ? 'standard' : 'code';

if ($referral !== '') {
    $defaultMode = $free_enabled ? 'pending' : 'code';
}

if ($registrar !== null) {
    $defaultMode = $free_enabled ? 'standard' : 'code';
}

$mode = old('mode', $defaultMode);

$registrar_balance = $registrar === null
    ? 0.0
    : (new WalletService())->balance((int) $registrar['id']);

$page_title       = 'Register';
$auth_inner_class = 'auth-wide';
$auth_page_css    = ['assets/css/membership.css'];
$auth_page_js     = ['assets/js/app-register.js'];

require __DIR__ . '/../app/layout/auth_header.php';
require __DIR__ . '/../app/views/auth/register_form.php';
require __DIR__ . '/../app/layout/auth_footer.php';
