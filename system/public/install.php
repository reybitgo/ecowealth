<?php
declare(strict_types=1);

/* ==============================================================
 *  EcoWealth — web installer (plan IR-4)
 *
 *      https://example.com/ecowealth/system/install.php
 *
 *  The shared-hosting path for an owner with no SSH: a preflight page,
 *  a small form for the site name and both staff credentials, one
 *  button, and a verified result. It is a STANDALONE file on purpose:
 *
 *    • it must work when `users` does not exist yet, so it cannot use
 *      app/bootstrap.php, the application session, the application
 *      layout, require_admin() or any helper that reads `settings`;
 *    • it loads only config + helpers + autoload + the install service;
 *    • it starts its own session (`ecowealth_install`) for CSRF and the
 *      install-key throttle;
 *    • it sends its own hardening headers (no-store, no framing);
 *    • it never writes app/config.local.php and never prints a
 *      password, a DSN or the install key.
 *
 *  What it does NOT do
 *  -------------------
 *  It does not create a database: shared hosts rarely grant CREATE
 *  DATABASE, so the schema is created in the hosting panel first and
 *  this page refuses when it is missing. It does not run on a non-empty
 *  schema and it refuses once storage/install.lock exists.
 *
 *  All work goes through DatabaseInstaller (IR-3); all gates come from
 *  DbToolAccess. This file owns markup, the session and the POST, and
 *  nothing else. Deleting it is the supported way to retire the tool
 *  after go-live (plan §11.4).
 * ============================================================== */

/* --------------------------------------------------------------
 *  Boot without the application
 * -------------------------------------------------------------- */

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/autoload.php';

if (!defined('APP_BASE_URL')) {
    define('APP_BASE_URL', install_base_url());
}

/* The installer does not load app/bootstrap.php, so it owns the two
 * process defaults that file normally sets: the business time zone (the
 * lock's `installed_at` and `install_completed_at` must be Manila time,
 * not the host's default) and production error display. */
@ini_set('log_errors', '1');

if (!APP_DEBUG) {
    @ini_set('display_errors', '0');
    @ini_set('display_startup_errors', '0');
}

date_default_timezone_set(APP_TIMEZONE);

/* A shared host may default to 30 s; the bundle is ~64 KB and the
 * verification queries follow it, so ask for two minutes. The host is
 * allowed to ignore this; phpMyAdmin remains the fallback (plan §6.7). */
@set_time_limit(120);

install_start_session();
install_send_headers();

/* --------------------------------------------------------------
 *  State
 * -------------------------------------------------------------- */

$appRoot      = dirname(__DIR__);
$env          = (string) APP_ENV;
$database     = (string) DB_NAME;
$service      = new DatabaseInstaller(null, $appRoot, $env);

$keyRequired  = DbToolAccess::installKeyRequired($env);
$configuredKey = DatabaseInstaller::configuredInstallKey();
$submittedKey = trim((string) ($_POST['install_key'] ?? ''));

$locked   = DatabaseInstaller::isLocked($appRoot);
$lock     = $locked ? DatabaseInstaller::readLock($appRoot) : null;
$lockPath = DatabaseInstaller::lockPath($appRoot);

$state        = 'form';       // form | locked | installed | success | partial | error
$preflight    = null;
$checks       = [];
$byKey        = [];
$formReady    = false;
$formError    = '';
$fieldErrors  = [];
$report       = null;
$toolError    = null;         // DatabaseToolException
$fatal        = null;         // any other Throwable
$dbDetail     = '';

$old = [
    'site_name'           => (string) ($_POST['site_name'] ?? APP_NAME),
    'admin_username'      => (string) ($_POST['admin_username'] ?? 'admin'),
    'admin_email'         => (string) ($_POST['admin_email'] ?? ''),
    'superadmin_username' => (string) ($_POST['superadmin_username'] ?? 'superadmin'),
    'superadmin_email'    => (string) ($_POST['superadmin_email'] ?? ''),
    'with_demo'           => !empty($_POST['with_demo']),
];

try {
    $preflight = $service->preflight($database, true, $submittedKey, false);
    $checks    = $preflight['checks'];

    foreach ($checks as $check) {
        $byKey[(string) $check['key']] = $check;
    }

    /* The service's key row is written for both front-ends; the CLI's
     * "--key" wording would be noise in a browser. Keep the detail (it
     * says whether the key is empty or wrong) and give the page its own
     * next step. */
    if ($keyRequired) {
        foreach ($checks as $index => $check) {
            if ((string) $check['key'] === 'install_key') {
                $checks[$index]['hint'] = $configuredKey === ''
                    ? 'Set a long random INSTALL_KEY in app/config.local.php (or ECO_INSTALL_KEY) before installing in production.'
                    : 'Type the INSTALL_KEY from app/config.local.php. It is never stored and never shown again.';
            }
        }
    }

    $dbExists = (bool) ($byKey['database_exists']['ok'] ?? false);
    $dbEmpty  = (bool) ($byKey['database_empty']['ok'] ?? false);

    if ($locked) {
        $state = 'locked';
    } elseif ($dbExists && !$dbEmpty) {
        $state    = 'installed';
        $dbDetail = (string) ($byKey['database_empty']['detail'] ?? '');
    } else {
        /* The key row is deliberately not a reason to disable the form:
         * it is a field the operator has not typed yet. Every other
         * failing check means the form cannot succeed. */
        $blocking = [];

        foreach ($checks as $check) {
            if (!$check['ok'] && (string) $check['key'] !== 'install_key') {
                $blocking[] = $check;
            }
        }

        $keyConfiguredMissing = $keyRequired && $configuredKey === '';
        $formReady            = $blocking === [] && !$keyConfiguredMissing;

        if ($keyRequired && $configuredKey !== '' && $submittedKey === '') {
            /* Presentation only: show "pending" instead of the CLI's
             * "no --key was supplied" red row before the first submit. */
            foreach ($checks as $index => $check) {
                if ((string) $check['key'] === 'install_key') {
                    $checks[$index]['ok']      = true;
                    $checks[$index]['detail']  = 'will be checked when you submit';
                    $checks[$index]['hint']    = '';
                    $checks[$index]['pending'] = true;
                }
            }
        }

        if (is_post()) {
            $admin      = [
                'username'         => trim((string) ($_POST['admin_username'] ?? '')),
                'email'            => trim((string) ($_POST['admin_email'] ?? '')),
                'password'         => (string) ($_POST['admin_password'] ?? ''),
                'password_confirm' => (string) ($_POST['admin_password_confirm'] ?? ''),
            ];
            $superadmin = [
                'username'         => trim((string) ($_POST['superadmin_username'] ?? '')),
                'email'            => trim((string) ($_POST['superadmin_email'] ?? '')),
                'password'         => (string) ($_POST['superadmin_password'] ?? ''),
                'password_confirm' => (string) ($_POST['superadmin_password_confirm'] ?? ''),
            ];

            $old['admin_username']      = $admin['username'];
            $old['admin_email']         = $admin['email'];
            $old['superadmin_username'] = $superadmin['username'];
            $old['superadmin_email']    = $superadmin['email'];
            $old['site_name']           = trim((string) ($_POST['site_name'] ?? ''));

            $siteName = $old['site_name'];
            $withDemo = !empty($_POST['with_demo']);

            if (!$formReady) {
                $formError = 'The preflight checks must pass before the installer can run. '
                    . 'Resolve the failing checks above and reload this page.';
            } elseif (!csrf_verify($_POST['_csrf'] ?? null)) {
                $formError = 'Your session expired before the form was submitted. Nothing was written; '
                    . 'reload the page and try again.';
            } else {
                /* ---- Install key (production) -------------------- */
                if ($keyRequired) {
                    if ($configuredKey === '') {
                        $formError = 'INSTALL_KEY is not configured. The web installer refuses to run in '
                            . 'production without it. Set a long random INSTALL_KEY in app/config.local.php '
                            . 'and reload this page.';
                    } elseif (DbToolAccess::installKeyAttemptsLeft() <= 0) {
                        $formError = 'Too many install-key attempts. Wait 15 minutes before trying again.';
                    } elseif (!DbToolAccess::installKeyMatches($configuredKey, $submittedKey)) {
                        DbToolAccess::recordInstallKeyFailure();

                        $left = DbToolAccess::installKeyAttemptsLeft();
                        $formError = $left <= 0
                            ? 'Too many install-key attempts. Wait 15 minutes before trying again.'
                            : 'The install key does not match INSTALL_KEY. '
                                . $left . ' attempt' . ($left === 1 ? '' : 's') . ' left.';
                        $fieldErrors['install_key'] = 'The key does not match the value in app/config.local.php.';
                    } else {
                        DbToolAccess::clearInstallKeyFailures();
                    }
                }

                /* ---- Typed confirmation -------------------------- */
                if ($formError === ''
                    && !DbToolAccess::installPhraseMatches((string) ($_POST['confirm'] ?? ''))) {
                    $formError = 'Type ' . DbToolAccess::installPhrase() . ' in the confirmation box. '
                        . 'Nothing was written.';
                    $fieldErrors['confirm'] = 'The confirmation phrase is missing or wrong.';
                }

                /* ---- Field validation ---------------------------- */
                if ($formError === '') {
                    $validation  = $service->validateStaffInput([
                        'admin'      => $admin,
                        'superadmin' => $superadmin,
                    ]);
                    $fieldErrors = $validation['errors'];

                    if ($siteName !== '' && mb_strlen($siteName) > 80) {
                        $fieldErrors['site_name'] = 'The site name must be 1–80 characters.';
                    }

                    /* IR-7 owns the demo fixtures. The checkbox is not
                     * rendered in production, so this only guards a
                     * crafted POST; the service refuses as well. */
                    if ($withDemo && $env === 'production') {
                        $fieldErrors['with_demo'] = 'Demo fixtures cannot be installed in production. '
                            . 'They are a development/QA fixture; leave the option off on a live site.';
                    }

                    if ($fieldErrors !== []) {
                        $formError = 'Please correct the highlighted fields. Nothing was written.';
                    }
                }

                /* ---- Run ----------------------------------------- */
                if ($formError === '' && $fieldErrors === []) {
                    try {
                        $report = $service->install([
                            'database'   => $database,
                            'channel'    => 'web',
                            'admin'      => [
                                'username' => $admin['username'],
                                'email'    => $admin['email'],
                                'password' => $admin['password'],
                            ],
                            'superadmin' => [
                                'username' => $superadmin['username'],
                                'email'    => $superadmin['email'],
                                'password' => $superadmin['password'],
                            ],
                            'site_name'   => $siteName,
                            'with_demo'   => $withDemo,
                            'install_key' => $submittedKey,
                        ]);

                        $state = $report['ok'] ? 'success' : 'partial';
                    } catch (DatabaseToolException $e) {
                        if ($e->kind === DatabaseToolException::KIND_LOCKED) {
                            $locked = DatabaseInstaller::isLocked($appRoot);
                            $lock   = $locked ? DatabaseInstaller::readLock($appRoot) : null;
                            $state  = 'locked';
                        } elseif ($e->kind === DatabaseToolException::KIND_NOT_EMPTY) {
                            $state    = 'installed';
                            $dbDetail = $e->getMessage();
                        } else {
                            $state     = 'error';
                            $toolError = $e;
                            error_log('[ecowealth] web installer failed (' . $e->kind . '): ' . $e->getMessage());
                        }
                    }
                }
            }
        }
    }
} catch (Throwable $e) {
    $state = 'error';
    $fatal = $e;
    error_log(
        '[ecowealth] web installer crashed: ' . $e::class . ': ' . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine()
    );
}

/* --------------------------------------------------------------
 *  Render
 * -------------------------------------------------------------- */

$title = match ($state) {
    'locked'    => 'Already installed',
    'installed' => 'Database not empty',
    'success'   => 'Install complete',
    'partial'   => 'Install completed with warnings',
    'error'     => 'Install failed',
    default     => 'Install EcoWealth',
};

install_page_open($title, $state, $database, $env);

if ($state === 'locked') {
    install_render_locked([
        'database'  => $database,
        'lock'      => $lock,
        'lock_path' => $lockPath,
    ]);
} elseif ($state === 'installed') {
    install_render_nonempty([
        'database' => $database,
        'detail'   => $dbDetail,
    ]);
} elseif ($state === 'success' || $state === 'partial') {
    install_render_report([
        'report'   => (array) $report,
        'database' => $database,
        'app_root' => $appRoot,
    ]);
} elseif ($state === 'error') {
    install_render_error([
        'database'  => $database,
        'error'     => $toolError,
        'fatal'     => $fatal,
    ]);
} else {
    install_render_preflight($checks, $formReady, $keyRequired, $configuredKey, $env);
    install_render_form([
        'form_ready'   => $formReady,
        'form_error'   => $formError,
        'field_errors' => $fieldErrors,
        'old'          => $old,
        'env'          => $env,
        'key_required' => $keyRequired,
        'key_missing'  => $keyRequired && $configuredKey === '',
        'database'     => $database,
        'attempts'     => DbToolAccess::installKeyAttemptsLeft(),
    ]);
}

install_page_close();

/* ==============================================================
 *  Page helpers
 * ============================================================== */

/**
 * The app URL prefix this request is served under.
 *
 * The installer is a flat file at the same level as the app's clean
 * URLs, so the two-level detection used by public/index.php does not
 * apply when the web server reports the rewritten path. Prefer the
 * /public/ script path (it gives the real prefix), fall back to the
 * requested file's own directory.
 */
function install_base_url(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));

    if (preg_match('#/public/[^/]+\.php$#', $script) === 1) {
        return detect_app_base_url($script);
    }

    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/install.php'), PHP_URL_PATH);
    $path = is_string($path) && $path !== '' ? $path : '/install.php';
    $dir  = str_replace('\\', '/', dirname($path));

    return ($dir === '/' || $dir === '.' || $dir === '\\') ? '/' : rtrim($dir, '/') . '/';
}

/** Start the installer's own session (never the application session). */
function install_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0])) === 'https';

    session_name(DbToolAccess::INSTALL_SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

/** The response hardening every tool response carries (plan §5.10). */
function install_send_headers(): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }

    header('Content-Type: text/html; charset=UTF-8');
    SecurityHeaders::send();
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

/** HTML-escape, accepting anything scalar-ish. */
function install_e(mixed $value): string
{
    return e(is_scalar($value) || $value === null ? (string) $value : '');
}

/** A PASS / FAIL / PENDING pill. */
function install_pill(array $check): string
{
    if (($check['pending'] ?? false) === true) {
        return '<span class="dbt-pill dbt-pill--warn">pending</span>';
    }

    return $check['ok'] === true
        ? '<span class="dbt-pill dbt-pill--ok">pass</span>'
        : '<span class="dbt-pill dbt-pill--fail">fail</span>';
}

/** Open the document, header and progress strip. */
function install_page_open(string $title, string $state, string $database, string $env): void
{
    $finished = in_array($state, ['success', 'partial', 'locked', 'installed'], true);

    $steps = [
        ['Preflight', $finished ? 'done' : ($state === 'form' ? 'current' : 'done')],
        ['Install', $finished ? 'done' : ($state === 'error' ? 'current' : '')],
        ['Done', $finished ? 'current' : ''],
    ];

    ?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="dark">
    <title><?= install_e($title) ?> · <?= install_e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= install_e(asset_url('assets/css/db-tools.css')) ?>">
</head>
<body class="dbt-body">
<main class="dbt-shell">
    <header class="dbt-header">
        <div>
            <span class="dbt-badge dbt-badge--tool">&#9888; Database tool</span>
            <h1 class="dbt-title"><?= install_e($title) ?></h1>
            <p class="dbt-subtitle">
                Fresh-install only. The installer imports the production bundle into an
                <strong>empty</strong> database, sets both staff credentials and verifies the result.
            </p>
            <p class="dbt-meta">
                database <strong><?= install_e($database) ?></strong>
                &middot; environment <strong><?= install_e(strtoupper($env)) ?></strong>
                &middot; <?= install_e(APP_NAME) ?>
            </p>
        </div>
        <div class="dbt-nowrap">
            <?php if ($env === 'production'): ?>
                <span class="dbt-badge dbt-badge--danger">Production</span>
            <?php else: ?>
                <span class="dbt-badge dbt-badge--info"><?= install_e(strtoupper($env)) ?></span>
            <?php endif; ?>
        </div>
    </header>

    <ol class="dbt-steps" aria-label="Installer progress">
        <?php foreach ($steps as $index => [$label, $class]): ?>
            <li class="dbt-step<?= $class !== '' ? ' dbt-step--' . $class : '' ?>">
                <span class="dbt-step__num"><?= $index + 1 ?></span><?= install_e($label) ?>
            </li>
        <?php endforeach; ?>
    </ol>
<?php
}

/** Close the document. */
function install_page_close(): void
{
    ?>
    <footer class="dbt-footer">
        EcoWealth web installer &middot; delete <code>public/install.php</code> after a successful install.
        <a href="<?= install_e(url('login', false)) ?>">Sign-in page</a>
    </footer>
</main>
</body>
</html>
<?php
}

/** Alert box. */
function install_alert(string $type, string $title, string $text, string $extra = ''): void
{
    $icons = [
        'danger'  => '&#9940;',
        'success' => '&#10003;',
        'warning' => '&#9888;',
        'info'    => '&#8505;',
    ];

    ?>
    <div class="dbt-alert dbt-alert--<?= install_e($type) ?>" role="alert">
        <div class="dbt-alert__icon" aria-hidden="true"><?= $icons[$type] ?? $icons['info'] ?></div>
        <div class="dbt-alert__body">
            <p class="dbt-alert__title"><?= install_e($title) ?></p>
            <p class="dbt-alert__text"><?= $text ?></p>
            <?php if ($extra !== ''): ?>
                <p class="dbt-alert__text"><?= $extra ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/** The preflight table + blocking guidance. */
function install_render_preflight(
    array $checks,
    bool $formReady,
    bool $keyRequired,
    string $configuredKey,
    string $env
): void {
    ?>
    <section class="dbt-card" aria-labelledby="preflight-title">
        <div class="dbt-card__head">
            <h2 class="dbt-card__title" id="preflight-title">Preflight checks</h2>
            <span class="dbt-card__note">Read-only — this page view writes nothing.</span>
        </div>
        <div class="dbt-card__body">
            <table class="dbt-table">
                <thead>
                    <tr>
                        <th class="dbt-table__status" scope="col">Status</th>
                        <th class="dbt-table__label" scope="col">Check</th>
                        <th scope="col">Detail</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($checks as $check): ?>
                    <tr class="<?= $check['ok'] ? '' : 'dbt-row--fail' ?>">
                        <td class="dbt-table__status"><?= install_pill($check) ?></td>
                        <td class="dbt-table__label"><?= install_e($check['label']) ?></td>
                        <td class="dbt-table__detail">
                            <?= install_e($check['detail']) ?>
                            <?php if ($check['ok'] !== true && (string) ($check['hint'] ?? '') !== ''): ?>
                                <span class="dbt-table__hint"><?= install_e($check['hint']) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($keyRequired && $configuredKey === ''): ?>
                <?php install_alert(
                    'danger',
                    'INSTALL_KEY is not configured',
                    'This is a production environment, so the web installer refuses to run without an install key. '
                        . 'Add a long random <code>INSTALL_KEY</code> to <code>app/config.local.php</code> and reload this page. '
                        . 'The key is never displayed, stored or written to the database.'
                ); ?>
            <?php elseif (!$formReady): ?>
                <?php install_alert(
                    'warning',
                    'The install form is disabled',
                    'Resolve the failing checks above first. The form becomes available again as soon as every check passes. '
                        . 'Nothing has been written by this page view.'
                ); ?>
            <?php endif; ?>
        </div>
    </section>
    <?php
}

/** One form field with its label, hint and error. */
function install_field(string $name, string $label, string $value, array $options = []): void
{
    $type        = (string) ($options['type'] ?? 'text');
    $hint        = (string) ($options['hint'] ?? '');
    $error       = (string) ($options['error'] ?? '');
    $wide        = (bool) ($options['wide'] ?? false);
    $disabled    = (bool) ($options['disabled'] ?? false);
    $autocomplete = (string) ($options['autocomplete'] ?? 'off');
    $optional    = (bool) ($options['optional'] ?? false);
    $placeholder = (string) ($options['placeholder'] ?? '');
    $class       = 'dbt-field' . ($wide ? ' dbt-field--wide' : '');
    ?>
    <div class="<?= $class ?>">
        <label class="dbt-label" for="f-<?= install_e($name) ?>">
            <?= install_e($label) ?>
            <?php if ($optional): ?><span class="dbt-optional">(optional)</span><?php endif; ?>
        </label>
        <input
            class="dbt-input<?= $error !== '' ? ' dbt-input--invalid' : '' ?>"
            type="<?= install_e($type) ?>"
            id="f-<?= install_e($name) ?>"
            name="<?= install_e($name) ?>"
            value="<?= install_e($value) ?>"
            autocomplete="<?= install_e($autocomplete) ?>"
            spellcheck="false"
            <?= $placeholder !== '' ? 'placeholder="' . install_e($placeholder) . '"' : '' ?>
            <?= $disabled ? 'disabled' : '' ?>
            <?= $error !== '' ? 'aria-invalid="true"' : '' ?>
            <?= $error !== '' ? 'aria-describedby="e-' . install_e($name) . '"' : '' ?>
        >
        <?php if ($hint !== ''): ?>
            <p class="dbt-hint"><?= $hint ?></p>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <p class="dbt-error" id="e-<?= install_e($name) ?>"><?= install_e($error) ?></p>
        <?php endif; ?>
    </div>
    <?php
}

/** The install form (enabled only when the preflight passed). */
function install_render_form(array $context): void
{
    $formReady   = (bool) $context['form_ready'];
    $formError   = (string) $context['form_error'];
    $fieldErrors = (array) $context['field_errors'];
    $old         = (array) $context['old'];
    $env         = (string) $context['env'];
    $keyRequired = (bool) $context['key_required'];
    $keyMissing  = (bool) $context['key_missing'];
    $attempts    = (int) $context['attempts'];
    $disabled    = !$formReady;

    ?>
    <section class="dbt-card" aria-labelledby="form-title">
        <div class="dbt-card__head">
            <h2 class="dbt-card__title" id="form-title">Install details</h2>
            <span class="dbt-card__note">Both accounts are required; passwords are not displayed afterwards.</span>
        </div>
        <div class="dbt-card__body">
            <?php if ($formError !== ''): ?>
                <?php install_alert('danger', 'Nothing was written', install_e($formError)); ?>
            <?php endif; ?>

            <?php if (!$formReady): ?>
                <?php install_alert(
                    'warning',
                    'Form disabled',
                    'One or more preflight checks are failing. The fields below are read-only until the checks pass.'
                ); ?>
            <?php endif; ?>

            <form class="dbt-form" method="POST" action="<?= install_e(url('install.php', false)) ?>" novalidate>
                <?= csrf_field() ?>

                <fieldset class="dbt-fieldset" <?= $disabled ? 'disabled' : '' ?>>
                    <legend class="dbt-legend">Site</legend>
                    <div class="dbt-grid-2">
                        <?php install_field('site_name', 'Site name', (string) $old['site_name'], [
                            'wide'     => true,
                            'optional' => true,
                            'hint'     => 'Shown in the back office and on the shop. Leave blank to keep the bundle default.',
                            'error'    => (string) ($fieldErrors['site_name'] ?? ''),
                            'disabled' => $disabled,
                        ]); ?>
                    </div>
                </fieldset>

                <fieldset class="dbt-fieldset" <?= $disabled ? 'disabled' : '' ?>>
                    <legend class="dbt-legend">Admin account</legend>
                    <div class="dbt-grid-2">
                        <?php install_field('admin_username', 'Username', (string) $old['admin_username'], [
                            'hint'     => '3–50 letters, numbers, dots, underscores or dashes.',
                            'error'    => (string) ($fieldErrors['admin.username'] ?? ''),
                            'disabled' => $disabled,
                        ]); ?>
                        <?php install_field('admin_email', 'Email', (string) $old['admin_email'], [
                            'type'     => 'email',
                            'hint'     => 'Used to sign in and for notifications.',
                            'error'    => (string) ($fieldErrors['admin.email'] ?? ''),
                            'disabled' => $disabled,
                        ]); ?>
                        <?php install_field('admin_password', 'Password', '', [
                            'type'         => 'password',
                            'autocomplete' => 'new-password',
                            'hint'         => '12–72 characters, not a published default.',
                            'error'        => (string) ($fieldErrors['admin.password'] ?? ''),
                            'disabled'     => $disabled,
                        ]); ?>
                        <?php install_field('admin_password_confirm', 'Password (again)', '', [
                            'type'         => 'password',
                            'autocomplete' => 'new-password',
                            'error'        => (string) ($fieldErrors['admin.password_confirm'] ?? ''),
                            'disabled'     => $disabled,
                        ]); ?>
                    </div>
                </fieldset>

                <fieldset class="dbt-fieldset" <?= $disabled ? 'disabled' : '' ?>>
                    <legend class="dbt-legend">Superadmin account</legend>
                    <div class="dbt-grid-2">
                        <?php install_field('superadmin_username', 'Username', (string) $old['superadmin_username'], [
                            'hint'     => 'Used for the back office and the S-Login console.',
                            'error'    => (string) ($fieldErrors['superadmin.username'] ?? ''),
                            'disabled' => $disabled,
                        ]); ?>
                        <?php install_field('superadmin_email', 'Email', (string) $old['superadmin_email'], [
                            'type'     => 'email',
                            'hint'     => 'Must differ from the admin email.',
                            'error'    => (string) ($fieldErrors['superadmin.email'] ?? ''),
                            'disabled' => $disabled,
                        ]); ?>
                        <?php install_field('superadmin_password', 'Password', '', [
                            'type'         => 'password',
                            'autocomplete' => 'new-password',
                            'hint'         => '12–72 characters, not a published default.',
                            'error'        => (string) ($fieldErrors['superadmin.password'] ?? ''),
                            'disabled'     => $disabled,
                        ]); ?>
                        <?php install_field('superadmin_password_confirm', 'Password (again)', '', [
                            'type'         => 'password',
                            'autocomplete' => 'new-password',
                            'error'        => (string) ($fieldErrors['superadmin.password_confirm'] ?? ''),
                            'disabled'     => $disabled,
                        ]); ?>
                    </div>
                </fieldset>

                <fieldset class="dbt-fieldset" <?= $disabled ? 'disabled' : '' ?>>
                    <legend class="dbt-legend">Confirmation</legend>

                    <?php if ($keyRequired): ?>
                        <div class="dbt-grid-2">
                            <?php install_field('install_key', 'INSTALL_KEY', '', [
                                'type'         => 'password',
                                'wide'         => true,
                                'autocomplete' => 'off',
                                'hint'         => $keyMissing
                                    ? 'INSTALL_KEY is not set in app/config.local.php; the installer cannot run until it is.'
                                    : 'The value of INSTALL_KEY from app/config.local.php. It is never stored and never shown again.'
                                        . ($attempts < DbToolAccess::KEY_MAX_ATTEMPTS
                                            ? ' Attempts left: ' . $attempts . '.'
                                            : ''),
                                'error'        => (string) ($fieldErrors['install_key'] ?? ''),
                                'disabled'     => $disabled || $keyMissing,
                            ]); ?>
                        </div>
                    <?php endif; ?>

                    <div class="dbt-grid-2">
                        <?php install_field('confirm', 'Type ' . DbToolAccess::installPhrase() . ' to confirm', '', [
                            'wide'         => true,
                            'autocomplete' => 'off',
                            'hint'         => 'This is the last step before the bundle is imported. Nothing is written until this matches.',
                            'error'        => (string) ($fieldErrors['confirm'] ?? ''),
                            'disabled'     => $disabled,
                        ]); ?>
                    </div>

                    <?php if ($env !== 'production'): ?>
                        <div class="dbt-check dbt-mt-3">
                            <input type="checkbox" id="f-with_demo" name="with_demo" value="1"
                                <?= !empty($old['with_demo']) ? 'checked' : '' ?>
                                <?= $disabled ? 'disabled' : '' ?>>
                            <div class="dbt-check__text">
                                <label for="f-with_demo">Seed the demo tree</label>
                                <small>
                                    Development/QA only: creates the demo sponsor tree
                                    (<code>juan</code>, <code>maria</code>, <code>peter</code>,
                                    <code>sarah</code>, <code>david</code>), their wallets and three sample codes.
                                    Every demo member signs in with <code>Demo@1234</code>. Never use it on a live site.
                                </small>
                            </div>
                        </div>
                        <?php if (isset($fieldErrors['with_demo'])): ?>
                            <p class="dbt-error"><?= install_e($fieldErrors['with_demo']) ?></p>
                        <?php endif; ?>
                    <?php endif; ?>
                </fieldset>

                <div class="dbt-actions">
                    <button class="dbt-button dbt-button--primary" type="submit" <?= $disabled ? 'disabled' : '' ?>>
                        Install <?= install_e(APP_NAME) ?>
                    </button>
                    <span class="dbt-actions__note">
                        The database must already exist and be empty. This page never creates a database and never writes app/config.local.php.
                    </span>
                </div>
            </form>
        </div>
    </section>
    <?php
}

/** The "already installed" state: the lock exists. */
function install_render_locked(array $context): void
{
    $database = (string) $context['database'];
    $lock     = is_array($context['lock'] ?? null) ? $context['lock'] : null;
    $lockPath = (string) ($context['lock_path'] ?? '');

    ?>
    <section class="dbt-card dbt-card--danger" aria-labelledby="locked-title">
        <div class="dbt-card__head">
            <h2 class="dbt-card__title" id="locked-title">This installation is already locked</h2>
            <span class="dbt-badge dbt-badge--danger">Refused</span>
        </div>
        <div class="dbt-card__body">
            <?php install_alert(
                'danger',
                'No second install was run',
                'A finished installation recorded <code>storage/install.lock</code>. The web installer refuses to run again, '
                    . 'so an attacker who reaches this URL cannot re-install over your database.',
                'Delete <code>storage/install.lock</code> only when you really intend to wipe the database and install from scratch.'
            ); ?>

            <?php if ($lock !== null): ?>
                <dl class="dbt-facts dbt-mt-3">
                    <dt>Database</dt>
                    <dd><?= install_e((string) ($lock['database'] ?? $database)) ?></dd>
                    <dt>Installed at</dt>
                    <dd><?= install_e((string) ($lock['installed_at'] ?? 'unknown')) ?></dd>
                    <dt>Channel</dt>
                    <dd><?= install_e((string) ($lock['channel'] ?? 'unknown')) ?></dd>
                    <dt>App version</dt>
                    <dd><?= install_e((string) ($lock['app_version'] ?? 'unknown')) ?></dd>
                    <dt>Staff accounts</dt>
                    <dd><?= install_e(implode(', ', array_map('strval', (array) ($lock['staff'] ?? [])))) ?></dd>
                    <dt>Bundle sha256</dt>
                    <dd><?= install_e((string) ($lock['install_sql_sha256'] ?? 'unknown')) ?></dd>
                </dl>
            <?php elseif ($lockPath !== '' && is_file($lockPath)): ?>
                <?php install_alert(
                    'warning',
                    'The lock file is not valid JSON',
                    'The file exists, so the installer still refuses to run. Recreate it from the CLI report '
                        . 'or delete it only if you intend to re-install.'
                ); ?>
            <?php endif; ?>
        </div>
    </section>

    <section class="dbt-card">
        <div class="dbt-card__head">
            <h2 class="dbt-card__title">What now</h2>
        </div>
        <div class="dbt-card__body">
            <ul class="dbt-list">
                <li><strong>Sign in</strong> with the staff account chosen during the install.</li>
                <li><strong>Delete <code>public/install.php</code></strong> from the server — the tool is not needed after a successful install.</li>
                <li><strong>Remove <code>INSTALL_KEY</code></strong> from <code>app/config.local.php</code> if it was only used for this install.</li>
                <li>Change both staff passwords if they were ever exposed.</li>
                <li>Keep <code>storage/install.lock</code>; deleting it is the only way to make this page install again.</li>
            </ul>
            <div class="dbt-actions">
                <a class="dbt-button dbt-button--primary" href="<?= install_e(url('login', false)) ?>">Go to the sign-in page</a>
                <a class="dbt-button dbt-button--ghost" href="<?= install_e(url('/', false)) ?>">Open the application</a>
            </div>
        </div>
    </section>
    <?php
}

/** The "database is not empty" state: no lock, but the schema has tables. */
function install_render_nonempty(array $context): void
{
    $database = (string) $context['database'];
    $detail   = (string) $context['detail'];

    ?>
    <section class="dbt-card dbt-card--danger" aria-labelledby="nonempty-title">
        <div class="dbt-card__head">
            <h2 class="dbt-card__title" id="nonempty-title">This database is already installed</h2>
            <span class="dbt-badge dbt-badge--danger">Refused</span>
        </div>
        <div class="dbt-card__body">
            <?php install_alert(
                'danger',
                'The fresh-install bundle was not run',
                'The database <code>' . install_e($database) . '</code> already contains tables'
                    . ($detail !== '' ? ' (' . install_e($detail) . ')' : '') . '.',
                'The production bundle is fresh-install only. A populated schema is upgraded with '
                    . '<code>database/migrations/NNN_*.sql</code>, never by re-importing install.sql.'
            ); ?>

            <p class="dbt-muted dbt-mt-3">
                If this is a brand-new deployment where the schema should be empty, drop and recreate it in
                the hosting panel (<strong>Databases &rarr; MySQL Databases</strong>), then reload this page.
                Do not try to repair a partial import by running the bundle again.
            </p>

            <div class="dbt-actions">
                <a class="dbt-button dbt-button--primary" href="<?= install_e(url('login', false)) ?>">Go to the sign-in page</a>
                <a class="dbt-button dbt-button--ghost" href="<?= install_e(url('install.php', false)) ?>">Reload preflight</a>
            </div>
        </div>
    </section>
    <?php
}

/** The success / partial report. */
function install_render_report(array $context): void
{
    $report   = (array) ($context['report'] ?? []);
    $database = (string) ($context['database'] ?? '');
    $counts   = (array) ($report['counts'] ?? []);
    $checks   = (array) ($report['checks'] ?? []);
    $warnings = (array) ($report['warnings'] ?? []);
    $accounts = (array) ($report['staff']['accounts'] ?? []);
    $lock     = (array) ($report['lock'] ?? []);
    $bundle   = (array) ($report['install_sql'] ?? []);
    $demo     = (array) ($report['demo'] ?? []);
    $ok       = (bool) ($report['ok'] ?? false);

    $countLabels = [
        'tables_created' => 'Tables',
        'settings_rows'  => 'Settings',
        'staff_rows'     => 'Staff',
        'members_rows'   => 'Members',
        'products_rows'  => 'Products',
        'packages_rows'  => 'Packages',
        'levels_rows'    => 'Levels',
        'contents_rows'  => 'Contents',
        'codes_rows'     => 'Codes',
    ];

    ?>
    <section class="dbt-card dbt-card--success" aria-labelledby="success-title">
        <div class="dbt-card__head">
            <h2 class="dbt-card__title" id="success-title"><?= $ok ? 'Installation complete' : 'Installation completed with warnings' ?></h2>
            <span class="dbt-badge <?= $ok ? 'dbt-badge--ok' : 'dbt-badge--danger' ?>"><?= $ok ? 'Verified' : 'Attention' ?></span>
        </div>
        <div class="dbt-card__body">
            <?php if ($ok): ?>
                <?php install_alert(
                    'success',
                    'The database is installed and verified',
                    'The bundle was imported, both staff credentials were set and the verification pack passed. '
                        . 'Delete <code>public/install.php</code> now — the tool is finished.',
                    'Sign-in is available at <code>' . install_e(url('login', false)) . '</code>.'
                ); ?>
            <?php else: ?>
                <?php install_alert(
                    'warning',
                    'The data was written, but a verification check needs attention',
                    'Read the failing check below before signing in. The install has already committed; '
                        . 'do not re-run the bundle on top of it.',
                    'If the database itself is wrong, drop and recreate it, then install again from an empty schema.'
                ); ?>
            <?php endif; ?>

            <div class="dbt-stats dbt-mt-3">
                <?php foreach ($countLabels as $key => $label): ?>
                    <div class="dbt-stat">
                        <span class="dbt-stat__value"><?= (int) ($counts[$key] ?? 0) ?></span>
                        <span class="dbt-stat__label"><?= install_e($label) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <dl class="dbt-facts dbt-mt-4">
                <dt>Database</dt>
                <dd><?= install_e($database) ?></dd>
                <dt>Channel</dt>
                <dd><?= install_e((string) ($report['channel'] ?? 'web')) ?></dd>
                <dt>Duration</dt>
                <dd><?= (int) ($report['duration_ms'] ?? 0) ?> ms</dd>
                <dt>Bundle</dt>
                <dd><?= install_e((string) ($bundle['path'] ?? 'database/install.sql')) ?>
                    (<?= number_format((int) ($bundle['bytes'] ?? 0)) ?> bytes,
                    sha256 <?= install_e(substr((string) ($bundle['sha256'] ?? ''), 0, 16)) ?>&hellip;)</dd>
                <dt>Install lock</dt>
                <dd><?= install_e((string) ($lock['path'] ?? 'storage/install.lock')) ?>
                    (<?= !empty($lock['written']) ? 'written' : 'not written' ?>)</dd>
            </dl>
        </div>
    </section>

    <section class="dbt-card" aria-labelledby="accounts-title">
        <div class="dbt-card__head">
            <h2 class="dbt-card__title" id="accounts-title">Staff accounts</h2>
            <span class="dbt-card__note">Passwords are never displayed or stored in this report.</span>
        </div>
        <div class="dbt-card__body">
            <table class="dbt-table">
                <thead>
                    <tr>
                        <th class="dbt-table__label" scope="col">Role</th>
                        <th scope="col">Username</th>
                        <th scope="col">Email</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($accounts as $account): ?>
                    <tr>
                        <td class="dbt-table__label"><?= install_e((string) ($account['role'] ?? '')) ?></td>
                        <td class="dbt-table__detail"><?= install_e((string) ($account['username'] ?? '')) ?></td>
                        <td class="dbt-table__detail"><?= install_e((string) ($account['email'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="dbt-hint dbt-mt-2">
                Sign in with the admin account first. Change both passwords from <strong>Profile &rarr; Change password</strong>
                if there is any doubt about who saw them.
            </p>
        </div>
    </section>

    <?php if (!empty($demo['installed'])): ?>
        <section class="dbt-card" aria-labelledby="demo-title">
            <div class="dbt-card__head">
                <h2 class="dbt-card__title" id="demo-title">Demo fixtures</h2>
                <span class="dbt-badge dbt-badge--info">Development only</span>
            </div>
            <div class="dbt-card__body">
                <?php install_alert(
                    'info',
                    'The demo tree was installed',
                    (int) ($demo['members'] ?? 0) . ' members, ' . (int) ($demo['codes'] ?? 0)
                        . ' registration codes and ' . (int) ($demo['ledger_rows'] ?? 0) . ' ledger rows were created. '
                        . 'Every demo member signs in with <code>' . install_e((string) ($demo['credentials'] ?? ''))
                        . '</code>; the sponsor chain is admin &rarr; juan &rarr; maria &rarr; peter/sarah/david.',
                    'This is QA data. Reset the database without demo data (or re-install without the option) '
                        . 'before showing the site to real users.'
                ); ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="dbt-card" aria-labelledby="verify-title">
        <div class="dbt-card__head">
            <h2 class="dbt-card__title" id="verify-title">Verification</h2>
            <span class="dbt-card__note"><?= count($checks) ?> checks</span>
        </div>
        <div class="dbt-card__body">
            <table class="dbt-table">
                <thead>
                    <tr>
                        <th class="dbt-table__status" scope="col">Status</th>
                        <th class="dbt-table__label" scope="col">Check</th>
                        <th scope="col">Detail</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($checks as $check): ?>
                    <tr class="<?= $check['ok'] ? '' : 'dbt-row--fail' ?>">
                        <td class="dbt-table__status"><?= install_pill($check) ?></td>
                        <td class="dbt-table__label"><?= install_e((string) ($check['label'] ?? '')) ?></td>
                        <td class="dbt-table__detail"><?= install_e((string) ($check['detail'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($warnings !== []): ?>
                <hr class="dbt-divider">
                <h3 class="dbt-card__title">Warnings</h3>
                <ul class="dbt-list dbt-mt-2">
                    <?php foreach ($warnings as $warning): ?>
                        <li><?= install_e((string) $warning) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </section>

    <section class="dbt-card" aria-labelledby="next-title">
        <div class="dbt-card__head">
            <h2 class="dbt-card__title" id="next-title">Next steps</h2>
        </div>
        <div class="dbt-card__body">
            <ol class="dbt-list">
                <li><strong>Delete <code>public/install.php</code></strong> from the server now. This page has done its job.</li>
                <li><strong>Remove <code>INSTALL_KEY</code></strong> from <code>app/config.local.php</code> (or rotate it).</li>
                <li>Sign in and change both staff passwords if they were typed on a shared machine.</li>
                <li>Configure the site settings and replace the starter catalogue before launch.</li>
                <li>Keep <code>storage/install.lock</code> as proof of the guided install; do not expose it over the web.</li>
                <li>If the app is live, delete <code>public/reset.php</code> too and confirm both tool URLs return 404.</li>
            </ol>
            <div class="dbt-actions">
                <a class="dbt-button dbt-button--primary" href="<?= install_e(url('login', false)) ?>">Open the sign-in page</a>
                <a class="dbt-button dbt-button--ghost" href="<?= install_e(url('/', false)) ?>">Open the application</a>
            </div>
        </div>
    </section>
    <?php
}

/** The failure page: message + hint, never a DSN, SQL snippet or stack trace. */
function install_render_error(array $context): void
{
    $database = (string) ($context['database'] ?? '');
    $error    = $context['error'] ?? null;
    $fatal    = $context['fatal'] ?? null;

    $message = 'The installer stopped before it could finish.';
    $hint    = 'Read the message above, correct the cause and try again. If the schema was partially created, '
        . 'drop and recreate it before retrying; never re-run the bundle on top of a partial import.';
    $kind    = '';

    if ($error instanceof DatabaseToolException) {
        $message = $error->getMessage();
        $hint    = (string) ($error->hint ?? $hint);
        $kind    = $error->kind;

        if ($kind === DatabaseToolException::KIND_SQL) {
            $statement = (int) ($error->context['statement'] ?? 0);
            $line      = (int) ($error->context['line'] ?? 0);

            if ($statement > 0 || $line > 0) {
                $message .= ' It stopped at statement #' . $statement . ' (line ' . $line . ').';
            }
        }
    } elseif ($fatal instanceof Throwable) {
        /* Deliberately generic: the class and message go to error_log(),
         * never into HTML. A raw driver error can embed a DSN. */
        $message = 'The installer hit an unexpected error and stopped.';
        $hint    = 'Check the PHP error log, then reload this page. If the error persists, delete install.php '
            . 'and install through phpMyAdmin with database/install.sql.';
    }

    ?>
    <section class="dbt-card dbt-card--danger" aria-labelledby="error-title">
        <div class="dbt-card__head">
            <h2 class="dbt-card__title" id="error-title">Install failed</h2>
            <span class="dbt-badge dbt-badge--danger"><?= install_e($kind !== '' ? $kind : 'error') ?></span>
        </div>
        <div class="dbt-card__body">
            <?php install_alert('danger', 'Nothing was reported as a successful install', install_e($message), install_e($hint)); ?>

            <p class="dbt-muted dbt-mt-3">
                The target was <code><?= install_e($database) ?></code>. A failed import may have created some
                tables: the supported recovery is to drop and recreate the database, then run the installer again
                from an empty schema. A partially imported database must not be patched by hand.
            </p>

            <div class="dbt-actions">
                <a class="dbt-button dbt-button--primary" href="<?= install_e(url('install.php', false)) ?>">Back to the installer</a>
                <a class="dbt-button dbt-button--ghost" href="<?= install_e(url('login', false)) ?>">Sign-in page</a>
            </div>
        </div>
    </section>
    <?php
}
