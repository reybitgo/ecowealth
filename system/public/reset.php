<?php
declare(strict_types=1);

/* ==============================================================
 *  EcoWealth — database reset console (plan IR-6)
 *
 *      http://localhost/ecowealth/system/reset.php
 *
 *  A development/QA tool: it returns the configured database to the
 *  state a fresh install produces, while keeping the operator's
 *  settings and both staff identities. Every destructive rule lives in
 *  app/Services/DatabaseResetService.php (IR-5); this file owns the
 *  authentication gates, the form, the preview, the typed confirmation,
 *  the POST/redirect/get report and the HTML — nothing else.
 *
 *  Gates (plan 5.8, 7.15):
 *    • production: hidden entirely unless DB_RESET_ENABLED is true;
 *    • signed-in admin (development) or superadmin (production);
 *    • never a member, never an S-Login (impersonated) tab;
 *    • CSRF token, typed phrase ("RESET" / the database name), backup ack;
 *    • the engine re-checks the target, the preflight, the phrase and
 *      the advisory lock before it deletes anything.
 *
 *  The page is deliberately standalone: it boots the application only
 *  for the session and the guards, then renders its own dark console
 *  with public/assets/css/db-tools.css. It is not a route and is not in
 *  any navigation (D-IR-17): deleting the file retires the tool.
 *
 *  It does not duplicate SQL: every count, staff row, package and step
 *  comes from DatabaseResetService::preview() / preflight() / run().
 * ============================================================== */

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/autoload.php';

@ini_set('log_errors', '1');
@set_time_limit(120);

/* --------------------------------------------------------------
 *  Pre-check the database before the application boot
 *
 *  app/database.php's db() exits with a bare 500 page when MySQL is
 *  unreachable, and that exit cannot be caught. DbTarget answers with a
 *  typed, operator-facing failure first, so this page can render its own
 *  "database unavailable" card instead (plan 5.10, R-13).
 * -------------------------------------------------------------- */

$database = defined('DB_NAME') ? (string) DB_NAME : '';

try {
    if ($database === '') {
        throw DatabaseToolException::preflight(
            'The application has no database name configured.',
            [],
            'Set DB_NAME (or the ECO_DB_NAME environment variable) in app/config.local.php, then reload this page.'
        );
    }

    DbTarget::fromConfig()->connect($database);
} catch (Throwable $e) {
    reset_render_unavailable($e);
    exit;
}

/* --------------------------------------------------------------
 *  Boot the application (session, auth, security headers)
 * -------------------------------------------------------------- */

require_once __DIR__ . '/../app/bootstrap.php';

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

date_default_timezone_set(APP_TIMEZONE);

$env         = (string) APP_ENV;
$expected    = DbToolAccess::confirmationPhrase($env, $database);
$phraseExact = strtolower(trim($env)) === 'production';

/* --------------------------------------------------------------
 *  Visibility and actor gates (plan IR-6-T3)
 * -------------------------------------------------------------- */

if (!DbToolAccess::resetVisible($env, (bool) DB_RESET_ENABLED)) {
    /* Indistinguishable from any unknown URL: in production the tool
     * does not exist unless the operator turned it on. */
    View::error(404);
}

if (DbToolAccess::isImpersonating()) {
    /* An S-Login tab is a member's session run by a superadmin; it must
     * never be able to wipe the database (plan 5.10). */
    View::error(403);
}

if (auth_check() && !DbToolAccess::resetActorAllowed(auth_user(), $env)) {
    View::error(403);
}

require_admin();

if ($phraseExact) {
    require_superadmin();
}

$actor = auth_user();
$actorName = $actor === null ? 'system' : (string) $actor['username'];

/* --------------------------------------------------------------
 *  Page state
 * -------------------------------------------------------------- */

$state       = 'form';     // form | report
$preview     = null;
$preflight   = null;
$formError   = '';
$fieldErrors = [];
$report      = null;
$submitted   = [
    'confirm'        => '',
    'keep_catalogue' => true,
    'code_qty'       => '0',
    'code_package'   => '',
    'keep_audit'     => true,
    'keep_uploads'   => false,
    'backup_ack'     => false,
];

/* "Done" clears the stored report and returns to a clean console. */
if (isset($_GET['clear'])) {
    unset($_SESSION['db_reset_report']);
    redirect('reset.php');
}

/* PRG: a stored report is rendered on ?done=1; a refresh re-renders it
 * and can never re-run the reset. */
if (isset($_GET['done']) && !is_post()) {
    $stored = $_SESSION['db_reset_report'] ?? null;

    if (is_array($stored)) {
        $report = $stored;
        $state  = 'report';
    } else {
        redirect('reset.php');
    }
}

try {
    if ($state === 'form') {
        $service   = reset_service($database, $env, $actor, $expected);
        $preview   = $service->preview();
        $preflight = $service->preflight();

        $submitted = reset_submitted_values($preview);

        if (is_post()) {
            [$formError, $fieldErrors, $submitted] = reset_validate($env, $database, $expected, $preview['packages'] ?? []);

            if ($formError === '' && $fieldErrors === []) {
                /* The engine is constructed with the validated values; the
                 * preview above never needed the confirmation. */
                $service = reset_service($database, $env, $actor, $expected, [
                    'code_qty'        => (int) $submitted['code_qty'],
                    'code_package'    => $submitted['code_package'] === '' ? null : (int) $submitted['code_package'],
                    'reset_catalogue' => !$submitted['keep_catalogue'],
                    'clear_audit'     => !$submitted['keep_audit'],
                    'keep_uploads'    => (bool) $submitted['keep_uploads'],
                ]);

                unset($_SESSION['db_reset_report']);

                try {
                    $result = $service->run();

                    setting_cache_clear();

                    /* Other open tabs revalidate on their next app page
                     * load; the queue lives in this session (plan 5.12). */
                    if (class_exists('Live')) {
                        Live::publish(DatabaseResetService::STANDARD_SURFACES);
                    }

                    $result['rendered_at'] = date('c');
                    $_SESSION['db_reset_report'] = $result;

                    redirect('reset.php?done=1');
                } catch (DatabaseToolException $e) {
                    error_log('[ecowealth] web reset failed (' . $e->kind . '): ' . $e->getMessage());

                    $_SESSION['db_reset_report'] = reset_failure_report($database, $e);
                    redirect('reset.php?done=1');
                } catch (Throwable $e) {
                    error_log(
                        '[ecowealth] web reset crashed: ' . $e::class . ': ' . $e->getMessage()
                        . ' @ ' . $e->getFile() . ':' . $e->getLine()
                    );

                    $_SESSION['db_reset_report'] = reset_failure_report(
                        $database,
                        DatabaseToolException::sql(
                            'The reset stopped unexpectedly. Read the PHP error log for the detail.',
                            [],
                            'Nothing may have been changed. Open the console and check the preview and the last '
                                . 'reset time before trying again.'
                        )
                    );
                    redirect('reset.php?done=1');
                }
            }
        }
    }
} catch (Throwable $e) {
    error_log('[ecowealth] reset console crashed: ' . $e::class . ': ' . $e->getMessage());

    reset_render_unavailable($e);
    exit;
}

/* --------------------------------------------------------------
 *  Render
 * -------------------------------------------------------------- */

$title = $state === 'report'
    ? (($report['ok'] ?? false) ? 'Reset complete' : 'Reset needs attention')
    : 'Database reset';

reset_page_open($title, $state, $database, $env, $actorName);

if ($state === 'report') {
    reset_render_report((array) $report, $database, $env, $expected);
} else {
    $packages = is_array($preview['packages'] ?? null) ? $preview['packages'] : [];
    $ready    = (bool) ($preflight['ok'] ?? false);

    reset_render_console([
        'preview'       => (array) $preview,
        'preflight'     => (array) $preflight,
        'packages'      => $packages,
        'ready'         => $ready,
        'expected'      => $expected,
        'phrase_exact'  => $phraseExact,
        'form_error'    => $formError,
        'field_errors'  => $fieldErrors,
        'submitted'     => $submitted,
        'env'           => $env,
        'database'      => $database,
        'last_report'   => is_array($_SESSION['db_reset_report'] ?? null),
    ]);
}

reset_page_close($state);

/* ==============================================================
 *  Service factory
 * ============================================================== */

/**
 * The reset engine, configured for this request.
 *
 * The page never runs SQL itself: every count, delete, audit row and
 * verification check belongs to DatabaseResetService. The confirmation
 * phrase is passed as the canonical value only after this page has
 * validated the typed one; the engine still re-checks it.
 *
 * @param array<string, mixed> $extra
 */
function reset_service(string $database, string $env, ?array $actor, string $phrase, array $extra = []): DatabaseResetService
{
    $options = array_merge([
        'env'                 => $env,
        'tool'                => 'web',
        'confirm'             => $phrase,
        'confirmation_phrase' => $phrase,
        'code_qty'            => 0,
        'code_package'        => null,
        'reset_catalogue'     => false,
        'clear_audit'         => false,
        'keep_uploads'        => false,
        'demo'                => false,
        'allow_production'    => strtolower(trim($env)) === 'production',
        'actor_id'            => $actor === null ? null : (int) $actor['id'],
        'actor_role'          => $actor === null ? 'system' : (string) $actor['role'],
        'actor_username'      => $actor === null ? null : (string) $actor['username'],
        'ip'                  => Request::ip(),
    ], $extra);

    return new DatabaseResetService(db(), $database, $options);
}

/* ==============================================================
 *  Validation (plan IR-6-T5, 7.15)
 * ============================================================== */

/**
 * Validate the POST without touching the database.
 *
 * A validation failure re-renders the form with inline errors and no
 * execution. Only a fully valid form reaches DatabaseResetService::run().
 *
 * @param list<array<string, mixed>> $packages
 * @return array{0:string,1:array<string,string>,2:array<string,mixed>}
 */
function reset_validate(string $env, string $database, string $expected, array $packages): array
{
    $errors = [];
    $values = [
        'confirm'        => trim((string) ($_POST['confirm'] ?? '')),
        'keep_catalogue' => !empty($_POST['keep_catalogue']),
        'code_qty'       => trim((string) ($_POST['code_qty'] ?? '0')),
        'code_package'   => trim((string) ($_POST['code_package'] ?? '')),
        'keep_audit'     => !empty($_POST['keep_audit']),
        'keep_uploads'   => !empty($_POST['keep_uploads']),
        'backup_ack'     => !empty($_POST['backup_ack']),
    ];

    if (!csrf_verify(isset($_POST['_csrf']) && is_string($_POST['_csrf']) ? $_POST['_csrf'] : null)) {
        $errors['_form'] = 'Your session expired before the form was submitted. Nothing was reset; reload the page and try again.';
    }

    if (!DbToolAccess::resetPhraseMatches($env, $database, $values['confirm'])) {
        $errors['confirm'] = 'Type ' . $expected . ' exactly to confirm. Nothing was reset.';
    }

    if (!$values['backup_ack']) {
        $errors['backup_ack'] = 'Tick the backup acknowledgement. A reset has no undo.';
    }

    if (preg_match('/^\d{1,2}$/', $values['code_qty']) !== 1 || (int) $values['code_qty'] > DatabaseResetService::MAX_CODES) {
        $errors['code_qty'] = 'Enter a whole number from 0 to ' . DatabaseResetService::MAX_CODES . '.';
    }

    if ($values['code_package'] !== '' && preg_match('/^[1-9]\d*$/', $values['code_package']) !== 1) {
        $errors['code_package'] = 'Choose a package from the list.';
    } elseif ($values['code_package'] !== '') {
        $known = array_map(static fn (array $package): string => (string) $package['id'], $packages);

        if (!in_array($values['code_package'], $known, true)) {
            $errors['code_package'] = 'That package does not exist any more; reload the page and choose one.';
        }
    }

    if (!empty($_POST['demo'])) {
        $errors['demo'] = 'Demo fixtures are not available on the reset path yet (plan phase IR-7). '
            . 'Run the reset without demo data.';
    }

    if ($errors !== [] && !isset($errors['_form'])) {
        $errors['_form'] = 'Please correct the highlighted fields. Nothing was reset.';
    }

    return [$errors['_form'] ?? '', $errors, $values];
}

/**
 * The values the form shows after a validation failure or an ordinary GET.
 *
 * @param array<string, mixed> $preview
 * @return array<string, mixed>
 */
function reset_submitted_values(array $preview): array
{
    $packages = is_array($preview['packages'] ?? null) ? $preview['packages'] : [];
    $default  = reset_default_package($packages);

    $values = [
        'confirm'        => '',
        'keep_catalogue' => true,
        'code_qty'       => '0',
        'code_package'   => $default === null ? '' : (string) $default['id'],
        'keep_audit'     => true,
        'keep_uploads'   => false,
        'backup_ack'     => false,
    ];

    if (!is_post()) {
        return $values;
    }

    return [
        'confirm'        => trim((string) ($_POST['confirm'] ?? '')),
        'keep_catalogue' => !empty($_POST['keep_catalogue']),
        'code_qty'       => trim((string) ($_POST['code_qty'] ?? '0')),
        'code_package'   => trim((string) ($_POST['code_package'] ?? ($default === null ? '' : (string) $default['id']))),
        'keep_audit'     => !empty($_POST['keep_audit']),
        'keep_uploads'   => !empty($_POST['keep_uploads']),
        'backup_ack'     => !empty($_POST['backup_ack']),
    ];
}

/**
 * The package the console preselects: the first active one, else the
 * first package at all (the engine warns when it is not active).
 *
 * @param list<array<string, mixed>> $packages
 * @return array<string, mixed>|null
 */
function reset_default_package(array $packages): ?array
{
    foreach ($packages as $package) {
        if ((string) ($package['status'] ?? '') === 'active') {
            return $package;
        }
    }

    return $packages[0] ?? null;
}

/* ==============================================================
 *  Report construction (failure path)
 * ============================================================== */

/**
 * A failure report with the same top-level keys as a real one
 * (plan Appendix D), so the report view has one shape.
 *
 * @return array<string, mixed>
 */
function reset_failure_report(string $database, DatabaseToolException $e): array
{
    $rolledBack = (bool) ($e->context['rolled_back'] ?? false)
        || in_array($e->kind, [
            DatabaseToolException::KIND_USAGE,
            DatabaseToolException::KIND_PREFLIGHT,
            DatabaseToolException::KIND_REFUSED,
            DatabaseToolException::KIND_LOCKED,
            DatabaseToolException::KIND_NOT_EMPTY,
            DatabaseToolException::KIND_INVALID_NAME,
        ], true);

    return [
        'tool'           => 'db_reset',
        'version'        => DatabaseResetService::REPORT_VERSION,
        'ok'             => false,
        'mode'           => 'reset',
        'database'       => $database,
        'server_version' => null,
        'started_at'     => date('c'),
        'duration_ms'    => 0,
        'options'        => [],
        'steps'          => [],
        'counts_before'  => [],
        'counts_after'   => [],
        'staff'          => ['reset' => 0, 'usernames' => []],
        'audit'          => ['kept' => true, 'rows_before' => 0, 'cleared' => false, 'rows_after' => 0],
        'auto_increment' => ['reset' => 0, 'failed' => 0, 'tables' => []],
        'uploads'        => ['deleted' => 0, 'failed' => 0, 'directories' => []],
        'codes'          => ['generated' => 0, 'expected' => 0, 'package' => null, 'package_id' => null, 'list' => []],
        'demo'           => ['installed' => false, 'members' => 0, 'codes' => 0, 'credentials' => []],
        'verification'   => ['ok' => false, 'checks' => []],
        'warnings'       => [],
        'error'          => [
            'kind'        => $e->kind,
            'message'     => $e->getMessage(),
            'hint'        => $e->hint,
            'rolled_back' => $rolledBack,
        ],
        'rendered_at'    => date('c'),
    ];
}

/* ==============================================================
 *  Page helpers
 * ============================================================== */

function reset_e(mixed $value): string
{
    return e(is_scalar($value) || $value === null ? (string) $value : '');
}

/** A PASS / FAIL / SKIP / WARN pill. */
function reset_pill(string $status): string
{
    $class = match ($status) {
        'ok'   => 'dbt-pill--ok',
        'skip' => 'dbt-pill--skip',
        'warn' => 'dbt-pill--warn',
        default => 'dbt-pill--fail',
    };

    return '<span class="dbt-pill ' . $class . '">' . reset_e($status) . '</span>';
}

/** The alert box shared by every state. */
function reset_alert(string $type, string $title, string $text, string $extra = ''): void
{
    $icons = [
        'danger'  => '&#9940;',
        'success' => '&#10003;',
        'warning' => '&#9888;',
        'info'    => '&#8505;',
    ];

    ?>
    <div class="dbt-alert dbt-alert--<?= reset_e($type) ?>" role="alert">
        <div class="dbt-alert__icon" aria-hidden="true"><?= $icons[$type] ?? $icons['info'] ?></div>
        <div class="dbt-alert__body">
            <p class="dbt-alert__title"><?= reset_e($title) ?></p>
            <p class="dbt-alert__text"><?= $text ?></p>
            <?php if ($extra !== ''): ?>
                <p class="dbt-alert__text"><?= $extra ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/** Open the document and the header. */
function reset_page_open(string $title, string $state, string $database, string $env, string $actor): void
{
    $production = strtolower(trim($env)) === 'production';

    $steps = [
        ['Preview', $state === 'form' ? 'current' : 'done'],
        ['Confirm', $state === 'form' ? '' : 'done'],
        ['Result', $state === 'report' ? 'current' : ''],
    ];

    ?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="dark">
    <title><?= reset_e($title) ?> · <?= reset_e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= reset_e(asset_url('assets/css/db-tools.css')) ?>">
</head>
<body class="dbt-body dbt-body--console">
<main class="dbt-shell">
    <header class="dbt-header">
        <div>
            <span class="dbt-badge dbt-badge--tool">&#9888; Database tool</span>
            <h1 class="dbt-title">Database Reset</h1>
            <p class="dbt-subtitle">
                Returns this database to a clean install state while keeping the settings and both staff
                identities. A development/QA tool: every member and every business row is deleted.
            </p>
            <p class="dbt-meta">
                <?= reset_e(APP_NAME) ?>
                &middot; database <strong><?= reset_e($database) ?></strong>
                &middot; environment <strong><?= reset_e(strtoupper($env)) ?></strong>
                &middot; signed in as <strong><?= reset_e($actor) ?></strong>
            </p>
        </div>
        <div class="dbt-nowrap">
            <?php if ($production): ?>
                <span class="dbt-badge dbt-badge--danger">Production</span>
            <?php else: ?>
                <span class="dbt-badge dbt-badge--info"><?= reset_e(strtoupper($env)) ?></span>
            <?php endif; ?>
            <span class="dbt-badge dbt-badge--danger">Destructive</span>
        </div>
    </header>

    <?php if ($production): ?>
        <div class="dbt-band dbt-band--danger" role="note">
            <strong>PRODUCTION</strong> — DB_RESET_ENABLED is on. This page permanently deletes member and
            transaction data. Delete <code>public/reset.php</code> and turn the constant off after the change window.
        </div>
    <?php endif; ?>

    <ol class="dbt-steps" aria-label="Reset progress">
        <?php foreach ($steps as $index => [$label, $class]): ?>
            <li class="dbt-step<?= $class !== '' ? ' dbt-step--' . $class : '' ?>">
                <span class="dbt-step__num"><?= $index + 1 ?></span><?= reset_e($label) ?>
            </li>
        <?php endforeach; ?>
    </ol>
<?php
}

/** Close the document. */
function reset_page_close(string $state): void
{
    ?>
    <footer class="dbt-footer">
        EcoWealth database reset console &middot; development/QA only &middot;
        <a href="<?= reset_e(url('admin', false)) ?>">Back to the back office</a>
    </footer>
    <div class="dbt-toast" data-toast hidden role="status" aria-live="polite"></div>
</main>

<?php if ($state === 'report'): ?>
<script>
(function () {
    var toast = document.querySelector('[data-toast]');
    var timer = null;

    function showToast(message) {
        if (!toast) { return; }
        toast.textContent = message;
        toast.hidden = false;
        window.clearTimeout(timer);
        timer = window.setTimeout(function () { toast.hidden = true; }, 1800);
    }

    document.querySelectorAll('[data-copy-code]').forEach(function (button) {
        button.addEventListener('click', function () {
            var code = button.getAttribute('data-copy-code') || button.textContent.trim();

            function done() {
                button.classList.add('dbt-chip--copied');
                showToast('Copied ' + code);
                window.setTimeout(function () { button.classList.remove('dbt-chip--copied'); }, 1200);
            }

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(code).then(done, fallback);
            } else {
                fallback();
            }

            function fallback() {
                var area = document.createElement('textarea');
                area.value = code;
                area.setAttribute('readonly', 'readonly');
                area.style.position = 'fixed';
                area.style.opacity = '0';
                document.body.appendChild(area);
                area.select();

                try { document.execCommand('copy'); } catch (error) { /* selection stays for a manual copy */ }

                document.body.removeChild(area);
                done();
            }
        });
    });
})();
</script>
<?php endif; ?>
</body>
</html>
<?php
}

/* ==============================================================
 *  Console (form state)
 * ============================================================== */

/**
 * The ready state: stats, what will be cleared, options and the form.
 *
 * @param array<string, mixed> $context
 */
function reset_render_console(array $context): void
{
    $preview      = (array) $context['preview'];
    $preflight    = (array) $context['preflight'];
    $packages     = (array) $context['packages'];
    $ready        = (bool) $context['ready'];
    $expected     = (string) $context['expected'];
    $phraseExact  = (bool) $context['phrase_exact'];
    $formError    = (string) $context['form_error'];
    $fieldErrors  = (array) $context['field_errors'];
    $submitted    = (array) $context['submitted'];
    $env          = (string) $context['env'];
    $database     = (string) $context['database'];
    $lastReport   = (bool) $context['last_report'];
    $counts       = (array) ($preview['counts'] ?? []);
    $catalogue    = (array) ($preview['catalogue'] ?? []);
    $staff        = (array) ($preview['staff'] ?? []);
    $warnings     = (array) ($preview['warnings'] ?? []);
    $production   = strtolower(trim($env)) === 'production';
    $resetCatalogue = empty($submitted['keep_catalogue']);
    $clearAudit     = empty($submitted['keep_audit']);
    $codeQty        = (string) ($submitted['code_qty'] ?? '0');
    $selectedPackage = (string) ($submitted['code_package'] ?? '');
    ?>
    <section class="dbt-card" aria-labelledby="stats-title">
        <div class="dbt-card__head">
            <h2 class="dbt-card__title" id="stats-title">&#128202; Current state</h2>
            <span class="dbt-card__note">Read-only preview — this page view writes nothing.</span>
        </div>
        <div class="dbt-card__body">
            <div class="dbt-stats">
                <div class="dbt-stat">
                    <span class="dbt-stat__value"><?= (int) ($counts['members'] ?? 0) ?></span>
                    <span class="dbt-stat__label">Members</span>
                </div>
                <div class="dbt-stat">
                    <span class="dbt-stat__value"><?= count($staff) ?></span>
                    <span class="dbt-stat__label">Staff kept</span>
                </div>
                <div class="dbt-stat">
                    <span class="dbt-stat__value"><?= (int) ($counts['shop_orders'] ?? 0) ?></span>
                    <span class="dbt-stat__label">Orders</span>
                </div>
                <div class="dbt-stat">
                    <span class="dbt-stat__value"><?= (int) ($counts['ewallet_ledger'] ?? 0) ?></span>
                    <span class="dbt-stat__label">Ledger rows</span>
                </div>
                <div class="dbt-stat">
                    <span class="dbt-stat__value"><?= (int) ($counts['reg_codes'] ?? 0) ?></span>
                    <span class="dbt-stat__label">Codes</span>
                </div>
                <div class="dbt-stat">
                    <span class="dbt-stat__value"><?= (int) ($catalogue['products'] ?? 0) ?></span>
                    <span class="dbt-stat__label">Products</span>
                </div>
            </div>

            <?php if ($warnings !== []): ?>
                <hr class="dbt-divider">
                <ul class="dbt-list">
                    <?php foreach ($warnings as $warning): ?>
                        <li><?= reset_e((string) $warning) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </section>

    <div class="dbt-console-grid">
        <section class="dbt-card" aria-labelledby="cleared-title">
            <div class="dbt-card__head">
                <h2 class="dbt-card__title" id="cleared-title">&#128465; What will be cleared</h2>
                <span class="dbt-badge dbt-badge--danger">Permanent</span>
            </div>
            <div class="dbt-card__body">
                <ul class="dbt-dot-list">
                    <li class="dbt-dot dbt-dot--red">all member accounts</li>
                    <li class="dbt-dot dbt-dot--red">all orders, payments, proofs, invoices and shipments</li>
                    <li class="dbt-dot dbt-dot--red">all commissions and activations</li>
                    <li class="dbt-dot dbt-dot--red">all wallet ledger rows, top-ups and transfers</li>
                    <li class="dbt-dot dbt-dot--red">all payout requests and holds</li>
                    <li class="dbt-dot dbt-dot--red">all registration codes</li>
                    <li class="dbt-dot dbt-dot--red">all payment and activation proof images<?= !empty($submitted['keep_uploads']) ? ' (kept this run)' : '' ?></li>
                    <li class="dbt-dot dbt-dot--red">all S-Login sessions</li>
                    <li class="dbt-dot dbt-dot--amber">staff balances, package, sponsor, registration and transfer counters</li>
                    <li class="dbt-dot dbt-dot--amber">every remember-me session, staff included</li>
                </ul>

                <hr class="dbt-divider">

                <ul class="dbt-dot-list">
                    <li class="dbt-dot dbt-dot--green">settings are kept (plus <code>last_reset_at</code> / <code>last_reset_by</code>)</li>
                    <li class="dbt-dot dbt-dot--green">
                        <?= $resetCatalogue ? 'the catalogue is deleted and re-seeded from 003/004' : 'the catalogue is kept as it is' ?>
                    </li>
                    <li class="dbt-dot dbt-dot--green">
                        <?= $clearAudit ? 'the audit log is cleared, then the reset row is written' : 'the audit log is kept, plus one <code>system.db_reset</code> row' ?>
                    </li>
                </ul>
            </div>
        </section>

        <section class="dbt-card dbt-card--danger" aria-labelledby="form-title">
            <div class="dbt-card__head">
                <h2 class="dbt-card__title" id="form-title">&#9881; Options</h2>
                <span class="dbt-card__note">All checks run on the server; the browser only helps.</span>
            </div>
            <div class="dbt-card__body">
                <?php if (!$ready): ?>
                    <?php reset_alert(
                        'danger',
                        'The reset form is disabled',
                        'The preflight refused this target: ' . reset_e(reset_preflight_reason($preflight))
                            . '. Fix the schema or restore a staff account first; nothing will be deleted until every '
                            . 'check passes.'
                    ); ?>
                <?php endif; ?>

                <?php if ($formError !== ''): ?>
                    <?php reset_alert('danger', 'Nothing was reset', reset_e($formError)); ?>
                <?php endif; ?>

                <?php if ($lastReport): ?>
                    <p class="dbt-hint dbt-mt-2">
                        A previous reset report is stored in this session.
                        <a href="<?= reset_e(url('reset.php?done=1', false)) ?>">View the last report</a>.
                    </p>
                <?php endif; ?>

                <form
                    class="dbt-form dbt-mt-3"
                    method="POST"
                    action="<?= reset_e(url('reset.php', false)) ?>"
                    data-reset-form
                    data-phrase="<?= reset_e($expected) ?>"
                    data-phrase-exact="<?= $phraseExact ? '1' : '0' ?>"
                    novalidate
                >
                    <?= csrf_field() ?>

                    <div class="dbt-field">
                        <label class="dbt-label" for="f-confirm">
                            Type <code><?= reset_e($expected) ?></code> to confirm
                        </label>
                        <input
                            class="dbt-input<?= isset($fieldErrors['confirm']) ? ' dbt-input--invalid' : '' ?>"
                            type="text"
                            id="f-confirm"
                            name="confirm"
                            value="<?= reset_e($submitted['confirm']) ?>"
                            autocomplete="off"
                            autocapitalize="characters"
                            spellcheck="false"
                            placeholder="<?= reset_e($expected) ?>"
                            <?= !$ready ? 'disabled' : '' ?>
                            <?= isset($fieldErrors['confirm']) ? 'aria-invalid="true" aria-describedby="e-confirm"' : '' ?>
                        >
                        <?php if (isset($fieldErrors['confirm'])): ?>
                            <p class="dbt-error" id="e-confirm"><?= reset_e($fieldErrors['confirm']) ?></p>
                        <?php else: ?>
                            <p class="dbt-hint">
                                <?= $production
                                    ? 'The database name is the confirmation in production; it must match exactly.'
                                    : 'This is the last step before the transaction starts.' ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="dbt-grid-2 dbt-mt-3">
                        <div class="dbt-field">
                            <label class="dbt-label" for="f-code_qty">Generate registration codes</label>
                            <input
                                class="dbt-input<?= isset($fieldErrors['code_qty']) ? ' dbt-input--invalid' : '' ?>"
                                type="number"
                                id="f-code_qty"
                                name="code_qty"
                                value="<?= reset_e($codeQty) ?>"
                                min="0"
                                max="<?= DatabaseResetService::MAX_CODES ?>"
                                step="1"
                                inputmode="numeric"
                                <?= !$ready ? 'disabled' : '' ?>
                            >
                            <?php if (isset($fieldErrors['code_qty'])): ?>
                                <p class="dbt-error"><?= reset_e($fieldErrors['code_qty']) ?></p>
                            <?php else: ?>
                                <p class="dbt-hint">0–<?= DatabaseResetService::MAX_CODES ?>, default 0. Codes are inventory; a reset creates none unless asked.</p>
                            <?php endif; ?>
                        </div>

                        <div class="dbt-field">
                            <label class="dbt-label" for="f-code_package">Code package</label>
                            <select
                                class="dbt-input<?= isset($fieldErrors['code_package']) ? ' dbt-input--invalid' : '' ?>"
                                id="f-code_package"
                                name="code_package"
                                <?= !$ready ? 'disabled' : '' ?>
                            >
                                <?php if ($packages === []): ?>
                                    <option value="">No package exists</option>
                                <?php else: ?>
                                    <?php foreach ($packages as $package): ?>
                                        <option
                                            value="<?= (int) $package['id'] ?>"
                                            <?= (string) $package['id'] === $selectedPackage ? 'selected' : '' ?>
                                        >
                                            <?= reset_e((string) $package['name']) ?>
                                            — <?= reset_e(CURRENCY_SYMBOL . number_format((float) $package['price'], 2)) ?>
                                            <?= (string) $package['status'] === 'active' ? '' : ' (inactive)' ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <?php if (isset($fieldErrors['code_package'])): ?>
                                <p class="dbt-error"><?= reset_e($fieldErrors['code_package']) ?></p>
                            <?php else: ?>
                                <p class="dbt-hint">Bound to the generated codes; the price is copied from the package.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <fieldset class="dbt-fieldset dbt-mt-4">
                        <legend class="dbt-legend">What to keep</legend>

                        <div class="dbt-check">
                            <input type="checkbox" id="f-keep_catalogue" name="keep_catalogue" value="1"
                                <?= !empty($submitted['keep_catalogue']) ? 'checked' : '' ?>
                                <?= !$ready ? 'disabled' : '' ?>>
                            <div class="dbt-check__text">
                                <label for="f-keep_catalogue">Keep the existing catalogue</label>
                                <small>
                                    Unchecking deletes products, packages, levels and contents, then re-seeds the
                                    starter catalogue from <code>003_products.sql</code> + <code>004_packages.sql</code>.
                                </small>
                            </div>
                        </div>

                        <div class="dbt-check">
                            <input type="checkbox" id="f-keep_audit" name="keep_audit" value="1"
                                <?= !empty($submitted['keep_audit']) ? 'checked' : '' ?>
                                <?= !$ready ? 'disabled' : '' ?>>
                            <div class="dbt-check__text">
                                <label for="f-keep_audit">Keep the audit log</label>
                                <small>One <code>system.db_reset</code> row is appended. Uncheck to clear the log first.</small>
                            </div>
                        </div>

                        <div class="dbt-check">
                            <input type="checkbox" id="f-keep_uploads" name="keep_uploads" value="1"
                                <?= !empty($submitted['keep_uploads']) ? 'checked' : '' ?>
                                <?= !$ready ? 'disabled' : '' ?>>
                            <div class="dbt-check__text">
                                <label for="f-keep_uploads">Keep uploaded files</label>
                                <small>Leaves proof images on disk (their rows are still deleted). Off by default.</small>
                            </div>
                        </div>

                        <?php if (!$production): ?>
                            <div class="dbt-check dbt-check--disabled">
                                <input type="checkbox" id="f-demo" name="demo" value="1" disabled>
                                <div class="dbt-check__text">
                                    <label for="f-demo">Seed the demo tree</label>
                                    <small>
                                        Demo members, wallets and codes arrive in plan phase IR-7. This build refuses
                                        the option; import <code>database/seeds/005_demo_tree.sql</code> on a test site
                                        instead.
                                    </small>
                                </div>
                            </div>
                            <?php if (isset($fieldErrors['demo'])): ?>
                                <p class="dbt-error"><?= reset_e($fieldErrors['demo']) ?></p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </fieldset>

                    <div class="dbt-check dbt-check--ack dbt-mt-3">
                        <input type="checkbox" id="f-backup_ack" name="backup_ack" value="1"
                            <?= !empty($submitted['backup_ack']) ? 'checked' : '' ?>
                            <?= !$ready ? 'disabled' : '' ?>>
                        <div class="dbt-check__text">
                            <label for="f-backup_ack">I have a backup, or this database is disposable</label>
                            <small>
                                A reset has no undo. Take a dump first; the runbook has the command
                                (<code>mysqldump --single-transaction</code>).
                            </small>
                        </div>
                    </div>
                    <?php if (isset($fieldErrors['backup_ack'])): ?>
                        <p class="dbt-error"><?= reset_e($fieldErrors['backup_ack']) ?></p>
                    <?php endif; ?>

                    <div class="dbt-actions">
                        <button
                            class="dbt-button dbt-button--danger"
                            type="submit"
                            data-reset-submit
                            <?= !$ready ? 'disabled' : '' ?>
                        >
                            &#128465; Reset database
                        </button>
                        <a class="dbt-button dbt-button--ghost" href="<?= reset_e(url('admin', false)) ?>">Cancel</a>
                        <span class="dbt-actions__note">
                            The button stays disabled until the typed phrase and the backup acknowledgement are
                            present. The server re-checks both before it deletes anything.
                        </span>
                    </div>
                </form>
            </div>
        </section>
    </div>

    <script>
    (function () {
        var form = document.querySelector('[data-reset-form]');
        if (!form) { return; }

        var phrase  = form.querySelector('[name="confirm"]');
        var ack     = form.querySelector('[name="backup_ack"]');
        var submit  = form.querySelector('[data-reset-submit]');
        var expected = form.getAttribute('data-phrase') || '';
        var exact    = form.getAttribute('data-phrase-exact') === '1';

        if (!phrase || !ack || !submit) { return; }

        function matches(value) {
            value = (value || '').trim();
            if (value === '') { return false; }
            return exact ? value === expected : value.toUpperCase() === expected.toUpperCase();
        }

        function sync() {
            submit.disabled = !(matches(phrase.value) && ack.checked);
        }

        phrase.addEventListener('input', sync);
        ack.addEventListener('change', sync);
        sync();
    })();
    </script>
    <?php
}

/**
 * A one-line reason the preflight is red, without printing the whole
 * check list twice.
 *
 * @param array<string, mixed> $preflight
 */
function reset_preflight_reason(array $preflight): string
{
    $reasons = [];

    foreach ((array) ($preflight['checks'] ?? []) as $check) {
        if (($check['ok'] ?? false) !== true) {
            $reasons[] = (string) ($check['detail'] ?? $check['label'] ?? 'a required check failed');
        }
    }

    return $reasons === [] ? 'a required check failed' : implode('; ', $reasons);
}

/* ==============================================================
 *  Report (done state)
 * ============================================================== */

/**
 * The result view: banner, step log, verification checklist, codes.
 *
 * @param array<string, mixed> $report
 */
function reset_render_report(array $report, string $database, string $env, string $expected): void
{
    $ok            = (bool) ($report['ok'] ?? false);
    $error         = is_array($report['error'] ?? null) ? $report['error'] : null;
    $steps         = is_array($report['steps'] ?? null) ? $report['steps'] : [];
    $checks        = (array) ($report['verification']['checks'] ?? []);
    $warnings      = (array) ($report['warnings'] ?? []);
    $codes         = (array) ($report['codes'] ?? []);
    $codeList      = (array) ($codes['list'] ?? []);
    $countsBefore  = (array) ($report['counts_before'] ?? []);
    $countsAfter   = (array) ($report['counts_after'] ?? []);
    $staffNames    = array_values(array_map('strval', (array) ($report['staff']['usernames'] ?? [])));
    $duration      = (int) ($report['duration_ms'] ?? 0);
    $reportDb      = (string) ($report['database'] ?? $database);
    $renderedAt    = (string) ($report['rendered_at'] ?? '');
    $codePackage   = $codes['package'] === null ? '' : (string) $codes['package'];

    $deleted = 0;

    foreach ($steps as $step) {
        $deleted += isset($step['deleted']) ? (int) $step['deleted'] : 0;
    }

    $banner = 'success';
    $bannerTitle = 'Reset complete and verified';
    $bannerText = 'The database is in the clean install state and every verification check passed.';

    if ($error !== null) {
        $banner = 'danger';
        $bannerTitle = 'Reset failed';
        $bannerText = 'The reset did not complete. Read the message below before trying again.';
    } elseif (!$ok) {
        $banner = 'warning';
        $bannerTitle = 'Reset committed, but verification failed';
        $bannerText = 'The data was reset and committed. Do not let anyone sign in until the failing checks below are resolved.';
    }
    ?>
    <section class="dbt-card dbt-card--<?= $banner === 'success' ? 'success' : 'danger' ?>" aria-labelledby="result-title">
        <div class="dbt-card__head">
            <h2 class="dbt-card__title" id="result-title"><?= reset_e($bannerTitle) ?></h2>
            <span class="dbt-badge <?= $ok ? 'dbt-badge--ok' : 'dbt-badge--danger' ?>"><?= $ok ? 'Verified' : 'Attention' ?></span>
        </div>
        <div class="dbt-card__body">
            <?php reset_alert($banner, $bannerTitle, reset_e($bannerText)); ?>

            <?php if ($error !== null): ?>
                <div class="dbt-error-panel dbt-mt-3">
                    <dl class="dbt-facts">
                        <dt>Reason</dt>
                        <dd><?= reset_e((string) ($error['message'] ?? 'The reset stopped.')) ?></dd>
                        <dt>Kind</dt>
                        <dd><?= reset_e((string) ($error['kind'] ?? 'error')) ?></dd>
                        <?php if ((string) ($error['hint'] ?? '') !== ''): ?>
                            <dt>Next step</dt>
                            <dd><?= reset_e((string) $error['hint']) ?></dd>
                        <?php endif; ?>
                    </dl>
                    <?php if (!empty($error['rolled_back'])): ?>
                        <p class="dbt-hint dbt-mt-2">
                            <strong>Nothing was deleted.</strong> Every statement of a reset runs in one
                            transaction; a failure rolls the whole block back.
                        </p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <dl class="dbt-facts dbt-mt-3">
                <dt>Database</dt>
                <dd><?= reset_e($reportDb) ?> · <?= reset_e(strtoupper($env)) ?></dd>
                <dt>Duration</dt>
                <dd><?= $duration ?> ms</dd>
                <dt>Rows deleted</dt>
                <dd><?= $deleted ?> across <?= count($steps) ?> step(s)</dd>
                <dt>Reset at</dt>
                <dd><?= reset_e($renderedAt) ?></dd>
                <?php if ($staffNames !== []): ?>
                    <dt>Staff kept</dt>
                    <dd><?= reset_e(implode(', ', $staffNames)) ?></dd>
                <?php endif; ?>
            </dl>
        </div>
    </section>

    <?php if ($steps !== []): ?>
        <section class="dbt-card" aria-labelledby="steps-title">
            <div class="dbt-card__head">
                <h2 class="dbt-card__title" id="steps-title">&#128203; Step log</h2>
                <span class="dbt-card__note"><?= count($steps) ?> step(s), one transaction</span>
            </div>
            <div class="dbt-card__body dbt-card__body--flush">
                <table class="dbt-table dbt-log">
                    <thead>
                        <tr>
                            <th class="dbt-table__status" scope="col">Status</th>
                            <th class="dbt-log__key" scope="col">Step</th>
                            <th class="dbt-log__label" scope="col">Action</th>
                            <th class="dbt-log__num" scope="col">Rows</th>
                            <th class="dbt-log__num" scope="col">ms</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($steps as $step): ?>
                        <?php
                        $rows = $step['deleted'] ?? $step['rows'] ?? 0;
                        $isDeleted = isset($step['deleted']);
                        ?>
                        <tr class="<?= ($step['status'] ?? '') === 'fail' ? 'dbt-row--fail' : '' ?>">
                            <td class="dbt-table__status"><?= reset_pill((string) ($step['status'] ?? 'ok')) ?></td>
                            <td class="dbt-log__key"><?= reset_e((string) ($step['key'] ?? '')) ?></td>
                            <td class="dbt-log__label">
                                <?= reset_e((string) ($step['label'] ?? '')) ?>
                                <?php if (isset($step['error'])): ?>
                                    <span class="dbt-table__hint"><?= reset_e((string) $step['error']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="dbt-log__num"><?= (int) $rows ?><?= $isDeleted ? ' deleted' : '' ?></td>
                            <td class="dbt-log__num"><?= (int) ($step['ms'] ?? 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($checks !== []): ?>
        <section class="dbt-card" aria-labelledby="verify-title">
            <div class="dbt-card__head">
                <h2 class="dbt-card__title" id="verify-title">&#10003; Verification</h2>
                <span class="dbt-card__note"><?= count($checks) ?> check(s) — mandatory</span>
            </div>
            <div class="dbt-card__body dbt-card__body--flush">
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
                        <tr class="<?= ($check['ok'] ?? false) ? '' : 'dbt-row--fail' ?>">
                            <td class="dbt-table__status"><?= reset_pill(($check['ok'] ?? false) ? 'ok' : 'fail') ?></td>
                            <td class="dbt-table__label"><?= reset_e((string) ($check['label'] ?? '')) ?></td>
                            <td class="dbt-table__detail">
                                <?= reset_e((string) ($check['detail'] ?? '')) ?>
                                <?php if (($check['ok'] ?? false) !== true && (string) ($check['hint'] ?? '') !== ''): ?>
                                    <span class="dbt-table__hint"><?= reset_e((string) $check['hint']) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($codeList !== []): ?>
        <section class="dbt-card" aria-labelledby="codes-title">
            <div class="dbt-card__head">
                <h2 class="dbt-card__title" id="codes-title">&#127991; Fresh registration codes</h2>
                <span class="dbt-card__note">
                    <?= count($codeList) ?> code(s) for <?= reset_e($codePackage) ?> — click to copy
                </span>
            </div>
            <div class="dbt-card__body">
                <div class="dbt-chips">
                    <?php foreach ($codeList as $code): ?>
                        <button class="dbt-chip" type="button" data-copy-code="<?= reset_e((string) $code) ?>">
                            <?= reset_e((string) $code) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
                <p class="dbt-hint dbt-mt-2">
                    These codes are stored only in this session's report. Copy the ones you need now; they are
                    sellable inventory and are also listed under <a href="<?= reset_e(url('admin/codes', false)) ?>">Codes</a>.
                </p>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($warnings !== []): ?>
        <section class="dbt-card" aria-labelledby="warnings-title">
            <div class="dbt-card__head">
                <h2 class="dbt-card__title" id="warnings-title">&#9888; Warnings</h2>
                <span class="dbt-card__note">The reset can succeed with these; read them.</span>
            </div>
            <div class="dbt-card__body">
                <ul class="dbt-list">
                    <?php foreach ($warnings as $warning): ?>
                        <li><?= reset_e((string) $warning) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>
    <?php endif; ?>

    <section class="dbt-card" aria-labelledby="counts-title">
        <div class="dbt-card__head">
            <h2 class="dbt-card__title" id="counts-title">&#128200; Before and after</h2>
            <span class="dbt-card__note">Snapshot taken by the reset engine</span>
        </div>
        <div class="dbt-card__body">
            <div class="dbt-stats">
                <?php
                $countCards = [
                    'members'        => 'Members',
                    'shop_orders'    => 'Orders',
                    'ewallet_ledger' => 'Ledger rows',
                    'reg_codes'      => 'Codes',
                    'audit_log'      => 'Audit rows',
                    'live_revisions' => 'Live surfaces',
                ];
                ?>
                <?php foreach ($countCards as $key => $label): ?>
                    <div class="dbt-stat">
                        <span class="dbt-stat__value">
                            <?= (int) ($countsBefore[$key] ?? 0) ?> &rarr; <?= (int) ($countsAfter[$key] ?? 0) ?>
                        </span>
                        <span class="dbt-stat__label"><?= reset_e($label) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="dbt-card" aria-labelledby="next-title">
        <div class="dbt-card__head">
            <h2 class="dbt-card__title" id="next-title">What now</h2>
            <span class="dbt-card__note">
                This report lives in the session; refreshing re-renders it and never runs the reset again.
            </span>
        </div>
        <div class="dbt-card__body">
            <?php if ($ok): ?>
                <ul class="dbt-list">
                    <li><strong>Sign in</strong> with the staff account shown above. Staff passwords are unchanged.</li>
                    <li><strong>Confirm</strong> that <a href="<?= reset_e(url('admin/members', false)) ?>">Members</a> and
                        <a href="<?= reset_e(url('admin/orders', false)) ?>">Orders</a> are empty and the catalogue is intact.</li>
                    <li><strong>Generate extra codes</strong> from <a href="<?= reset_e(url('admin/codes', false)) ?>">Codes</a>
                        if this run created none.</li>
                    <li><strong>Never</strong> run this on production unless it is an approved change window; delete
                        <code>public/reset.php</code> when the QA round ends.</li>
                </ul>
            <?php elseif ($error !== null): ?>
                <ul class="dbt-list">
                    <li><strong>Nothing was changed</strong> when the transaction rolled back. Fix the cause and run
                        the console again.</li>
                    <li><strong>Read the PHP error log</strong> for the raw driver detail; the page never prints a DSN,
                        SQL snippet or stack trace.</li>
                    <li><strong>Do not hand-edit</strong> any table to "repair" a failed reset.</li>
                </ul>
            <?php else: ?>
                <ul class="dbt-list">
                    <li><strong>Do not let anyone sign in</strong> until the failing checks are resolved.</li>
                    <li><strong>Run</strong> <code>php bin/wallet_reconcile.php</code> for the money detail.</li>
                    <li><strong>Restore the pre-reset backup</strong> if real data is wrong; never patch wallet
                        balances by hand.</li>
                </ul>
            <?php endif; ?>

            <div class="dbt-actions">
                <a class="dbt-button dbt-button--primary" href="<?= reset_e(url('admin', false)) ?>">Back to the dashboard</a>
                <a class="dbt-button dbt-button--ghost" href="<?= reset_e(url('reset.php', false)) ?>">Reset again</a>
                <a class="dbt-button dbt-button--ghost" href="<?= reset_e(url('reset.php?clear=1', false)) ?>">Done</a>
                <span class="dbt-actions__note">
                    Done clears the report from this session. The audit trail keeps the <code>system.db_reset</code> row.
                </span>
            </div>
        </div>
    </section>
    <?php
}

/* ==============================================================
 *  Standalone failure page
 * ============================================================== */

/**
 * "Database unavailable" without the application boot.
 *
 * The page is intentionally standalone (like install.php): db() exits on
 * a failed connection, so this path must render before bootstrap. It shows
 * the typed hint, never a DSN, SQLSTATE or stack trace.
 */
function reset_render_unavailable(Throwable $e): void
{
    $hint = 'Check app/config.local.php (DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS) and that MySQL is running, '
        . 'then reload this page.';

    if ($e instanceof DatabaseToolException && (string) $e->hint !== '') {
        $hint = (string) $e->hint;
    }

    $message = $e instanceof DatabaseToolException
        ? $e->getMessage()
        : 'The application could not reach its database.';

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
        SecurityHeaders::send();
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    $appName = defined('APP_NAME') ? (string) APP_NAME : 'EcoWealth';
    $asset   = function_exists('asset_url') ? asset_url('assets/css/db-tools.css') : 'assets/css/db-tools.css';
    $dbName  = defined('DB_NAME') ? (string) DB_NAME : '(not configured)';
    ?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="dark">
    <title>Database unavailable · <?= reset_e($appName) ?></title>
    <link rel="stylesheet" href="<?= reset_e($asset) ?>">
</head>
<body class="dbt-body dbt-body--console">
<main class="dbt-shell dbt-shell--narrow">
    <header class="dbt-header">
        <div>
            <span class="dbt-badge dbt-badge--tool">&#9888; Database tool</span>
            <h1 class="dbt-title">Database unavailable</h1>
            <p class="dbt-subtitle">
                The reset console stopped before it could read the database. No reset was attempted.
            </p>
            <p class="dbt-meta">database <strong><?= reset_e($dbName) ?></strong></p>
        </div>
        <div class="dbt-nowrap"><span class="dbt-badge dbt-badge--danger">Stopped</span></div>
    </header>

    <section class="dbt-card dbt-card--danger" aria-labelledby="db-error-title">
        <div class="dbt-card__head">
            <h2 class="dbt-card__title" id="db-error-title">Nothing was changed</h2>
        </div>
        <div class="dbt-card__body">
            <dl class="dbt-facts">
                <dt>Problem</dt>
                <dd><?= reset_e($message) ?></dd>
                <dt>Next step</dt>
                <dd><?= reset_e($hint) ?></dd>
            </dl>
            <p class="dbt-hint dbt-mt-3">
                The detail is in the PHP error log. This page never prints a DSN, a password or a stack trace.
            </p>
            <div class="dbt-actions">
                <a class="dbt-button dbt-button--primary" href="<?= reset_e(url('admin', false)) ?>">Reload the back office</a>
            </div>
        </div>
    </section>
</main>
</body>
</html>
<?php
}
