<?php
declare(strict_types=1);

/* ==============================================================
 *  DatabaseInstaller — the one way to install EcoWealth (plan IR-3)
 *
 *      $installer = new DatabaseInstaller();                 // config + APP_ENV
 *      $preflight = $installer->preflight('ecowealth_sneat'); // read-only report
 *      $report    = $installer->install([…]);                 // the install
 *      $verify    = $installer->verify($pdo, […]);            // re-check later
 *
 *  What it owns
 *  ------------
 *  • the preflight table the CLI prints and the web page renders
 *    (PHP version, extensions, config file, server, schema, bundle,
 *    storage, lock, production INSTALL_KEY);
 *  • the install itself: empty-database guard, the bundle import through
 *    SqlScript, the optional demo tree (IR-7), operator-chosen staff
 *    credentials, install metadata and the `storage/install.lock` that
 *    prevents a second run;
 *  • the post-install verification: the §6.5 counts, the staff sign-in
 *    check and the zero-row pack from Appendix E.
 *
 *  Demo fixtures are opt-in (`with_demo`) and refused in production. The
 *  preconditions are checked before the bundle runs and again right
 *  before DemoDataService writes a row, so a refused request never
 *  creates a member with no package (D-IR-13, plan IR-7-T2).
 *
 *  What it must not do (plan §5.1): authenticate a user, emit HTML, read
 *  CLI flags or touch the global db() connection. Every query uses the
 *  PDO built by its DbTarget, which is what lets the tests run the exact
 *  production code against a scratch *\_test schema.
 *
 *  Failure policy
 *  --------------
 *  Every refusal throws DatabaseToolException with a kind the front-end
 *  maps to an exit code (usage 2, locked/refused/not_empty 3, anything
 *  else 1). Messages never contain a password, an install key or a DSN.
 *  The raw MySQL text is written to error_log() by DbTarget/SqlScript.
 *
 *  The install is not transactional — MySQL DDL commits implicitly — so
 *  its safety comes from the empty-database guard, the advisory lock and
 *  the documented "drop the schema and retry" recovery. The lock file is
 *  written last: an install that fails half-way never looks installed.
 * ============================================================== */

final class DatabaseInstaller
{
    /** Report schema version (plan Appendix D). */
    public const REPORT_VERSION = 1;

    /** The two staff rows the bundle must seed, in role order. */
    public const STAFF_ROLES = ['admin', 'superadmin'];

    /** Fallback version when the footer did not write install_version. */
    public const DEFAULT_APP_VERSION = '1.0.0';

    /** Username shape shared with registration (plan §6.8). */
    public const USERNAME_PATTERN = '/^[A-Za-z0-9_.-]{3,50}$/';

    /** Password policy (plan §6.8): 12–72 bytes, no published default. */
    public const PASSWORD_MIN = 12;
    public const PASSWORD_MAX = 72;

    /** Passwords that must never protect a fresh installation. */
    public const REJECTED_PASSWORDS = [
        'Admin@123',
        'Super@123',
        'password',
        'Password123',
        'admin123',
    ];

    /** Install channels a front-end may claim (plan §5.3). */
    public const CHANNELS = ['cli', 'web'];

    /**
     * Tables that must be empty after a production install (Appendix E).
     *
     * `users` is checked through members_rows; the catalogue and settings
     * are counted explicitly. With demo fixtures the three tables the demo
     * seed writes are excluded and checked with their own expected counts.
     *
     * Phase 17 adds the three transactional repeat-purchase tables: a fresh
     * install must fail verification if an award, an activity snapshot or a
     * closed period already exists. `product_unilevel_levels` is catalogue,
     * not money, so it is not in this list.
     */
    public const TRANSACTIONAL_TABLES = [
        'addresses',
        'activations',
        'audit_log',
        'cart_items',
        'carts',
        'commissions',
        'ewallet_admin_topups',
        'ewallet_ledger',
        'ewallet_transfers',
        'impersonation_log',
        'live_revisions',
        'payout_requests',
        'reg_codes',
        'remember_tokens',
        'shop_invoice_items',
        'shop_invoices',
        'shop_order_events',
        'shop_order_items',
        'shop_orders',
        'shop_payment_proofs',
        'shop_shipments',
        'unilevel_activity',
        'unilevel_awards',
        'unilevel_periods',
    ];

    /** Tables the demo seed writes, so the zero pack must not require them (IR-7). */
    public const DEMO_TABLES = ['ewallet_ledger', 'reg_codes'];

    /** The expected production counts — the numbers the footer prints (plan §6.5). */
    public const EXPECTED = [
        'tables_created' => 31,
        'settings_rows'  => 54,
        'staff_rows'     => 2,
        'members_rows'   => 0,
        'products_rows'  => 7,
        'packages_rows'  => 2,
        'levels_rows'    => 18,
        'contents_rows'  => 10,
        'codes_rows'     => 0,
    ];

    /** Demo counts (plan §6.2): five members, three codes, three ledger rows. */
    public const EXPECTED_DEMO = [
        'members_rows'       => 5,
        'codes_rows'         => 3,
        'ledger_rows'        => 3,
    ];

    /** The lock file name inside the storage directory. */
    public const LOCK_FILE = 'install.lock';

    private readonly DbTarget $target;
    private readonly string $appRoot;
    private readonly string $env;

    public function __construct(?DbTarget $target = null, ?string $appRoot = null, string $env = APP_ENV)
    {
        $this->target  = $target ?? DbTarget::fromConfig();
        $this->appRoot = self::resolveAppRoot($appRoot);
        $this->env     = $env;
    }

    /* ==========================================================
     *  Preflight (plan IR-3-T1)
     * ========================================================== */

    /**
     * Every precondition the installer can check without writing anything.
     *
     * The returned array is `['ok' => bool, 'database' => string,
     * 'checks' => list<array{key,label,ok,detail,hint}>]`. `ok` is the
     * conjunction of the checks; a failing check never throws, it is
     * reported, so one command prints the whole picture.
     *
     * The extra parameters keep the plan's documented shape
     * (`preflight($database, $requireEmpty)`) usable while the CLI and the
     * web page pass what only they know:
     *
     *  • `$installKey` — the key the operator supplied (`--key`); compared
     *    with INSTALL_KEY in production, ignored elsewhere;
     *  • `$willCreate` — true when the caller offers to create the schema,
     *    so a missing database is reported as "will be created" instead of
     *    a failure. Preflight itself never creates it.
     *
     * @return array{ok:bool, database:string, checks:list<array{
     *     key:string, label:string, ok:bool, detail:string, hint:string
     * }>}
     */
    public function preflight(
        string $database,
        bool $requireEmpty = true,
        ?string $installKey = null,
        bool $willCreate = false
    ): array {
        $checks = [];

        /* ---- Runtime ------------------------------------------------- */
        $phpOk = PHP_VERSION_ID >= 80100;
        $checks[] = self::check(
            'php',
            'PHP version',
            $phpOk,
            PHP_VERSION,
            $phpOk ? '' : 'EcoWealth needs PHP 8.1 or newer; ask the host to switch the PHP version.'
        );

        foreach (['pdo_mysql', 'fileinfo', 'mbstring', 'json'] as $extension) {
            $loaded   = extension_loaded($extension);
            $checks[] = self::check(
                'ext_' . $extension,
                $extension . ' extension',
                $loaded,
                $loaded ? 'loaded' : 'missing',
                $loaded ? '' : 'Enable the ' . $extension . ' extension in php.ini (or ask the host).'
            );
        }

        $localConfig = $this->appRoot . '/app/config.local.php';
        $localOk     = is_file($localConfig) && is_readable($localConfig);
        $checks[]    = self::check(
            'config_local',
            'config.local.php',
            $localOk,
            $localOk ? 'app/config.local.php is readable' : 'app/config.local.php is missing',
            $localOk
                ? ''
                : 'Create app/config.local.php with the database credentials (docs/deploy.md §7). '
                    . 'The application does not read DB_USER/DB_PASS from the environment.'
        );

        /* ---- MySQL --------------------------------------------------- */
        $server = null;

        try {
            $server = $this->target->connect();
            $version = (string) $server->query('SELECT VERSION()')->fetchColumn();
            $checks[] = self::check('server', 'MySQL server', true, 'connected — MySQL ' . $version);
        } catch (DatabaseToolException $e) {
            $checks[] = self::check('server', 'MySQL server', false, $e->getMessage(), (string) $e->hint);
        }

        /* ---- The target name ---------------------------------------- */
        $safeName = null;

        try {
            $safeName = $this->target->assertSafeDatabaseName($database);
            $checks[] = self::check(
                'database_name',
                'Database name',
                true,
                $safeName,
                ''
            );
        } catch (DatabaseToolException $e) {
            $checks[] = self::check('database_name', 'Database name', false, $e->getMessage(), (string) $e->hint);
        }

        /* ---- Does it exist, and is it empty? ------------------------ */
        $exists = false;

        if ($server === null || $safeName === null) {
            $checks[] = self::check(
                'database_exists',
                'Database exists',
                false,
                'Not checked — the server is unreachable or the name is invalid.',
                'Fix the checks above first.'
            );
            $checks[] = self::check(
                'database_empty',
                'Database empty',
                false,
                'Not checked — the server is unreachable or the name is invalid.',
                ''
            );
        } else {
            $existsKnown = true;

            try {
                $exists = $this->target->exists($safeName);
            } catch (DatabaseToolException $e) {
                $existsKnown = false;
                $checks[]    = self::check('database_exists', 'Database exists', false, $e->getMessage(), (string) $e->hint);
            }

            if (!$existsKnown) {
                $checks[] = self::check(
                    'database_empty',
                    'Database empty',
                    false,
                    'Not checked — the schema could not be looked up.',
                    'Fix the check above first.'
                );
            } elseif ($exists) {
                $checks[] = self::check('database_exists', 'Database exists', true, $safeName . ' exists');
            } elseif ($willCreate) {
                $checks[] = self::check(
                    'database_exists',
                    'Database exists',
                    true,
                    $safeName . ' does not exist yet — it will be created',
                    'Creating a database needs the CREATE privilege; shared hosting usually provides it through the hosting panel instead.'
                );
            } else {
                $checks[] = self::check(
                    'database_exists',
                    'Database exists',
                    false,
                    'The database "' . $safeName . '" does not exist.',
                    'Create it first (hosting panel, or `CREATE DATABASE ' . $safeName
                        . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`), or let the installer create it '
                        . 'with --create-database.'
                );
            }

            if (!$existsKnown) {
                /* The "not checked" row above already explains the gap. */
            } elseif (!$requireEmpty) {
                $checks[] = self::check('database_empty', 'Database empty', true, 'Not required for this run.');
            } elseif (!$exists) {
                /* A schema that does not exist is trivially empty; the
                 * failing "Database exists" row above is the actionable one.
                 * Reported as a pass so the table names one problem, not two. */
                $checks[] = self::check(
                    'database_empty',
                    'Database empty',
                    true,
                    $willCreate ? 'A new schema starts empty.' : 'not checked — the schema does not exist yet'
                );
            } else {
                try {
                    $tables = $this->target->tableCount($safeName);
                    $empty  = $tables === 0;
                    $checks[] = self::check(
                        'database_empty',
                        'Database empty',
                        $empty,
                        $empty
                            ? 'no tables — ready for the bundle'
                            : 'the schema already holds ' . $tables . ' table' . ($tables === 1 ? '' : 's'),
                        $empty
                            ? ''
                            : 'The bundle is fresh-install only. Drop and recreate the schema (or choose another one); '
                                . 'existing databases are upgraded with database/migrations/NNN_*.sql, never with install.sql.'
                    );
                } catch (DatabaseToolException $e) {
                    $checks[] = self::check('database_empty', 'Database empty', false, $e->getMessage(), (string) $e->hint);
                }
            }
        }

        /* ---- The bundle --------------------------------------------- */
        try {
            $bundlePath = $this->installSqlPath([]);
            $script     = SqlScript::fromFile($bundlePath);
            $statements = count($script->statements());
            $bytes      = (int) (@filesize($bundlePath) ?: 0);

            if ($script->containsDatabaseSwitch()) {
                $checks[] = self::check(
                    'install_sql',
                    'install.sql',
                    false,
                    'the bundle contains an executable CREATE DATABASE or USE statement',
                    'The caller selects the schema (D-IR-02). Rebuild the bundle with php bin/build_install_sql.php.'
                );
            } else {
                $checks[] = self::check(
                    'install_sql',
                    'install.sql',
                    $statements > 0,
                    'database/install.sql — ' . $statements . ' statements, ' . number_format($bytes) . ' bytes',
                    $statements > 0 ? '' : 'The bundle is empty; rebuild it with php bin/build_install_sql.php.'
                );
            }
        } catch (DatabaseToolException $e) {
            $checks[] = self::check('install_sql', 'install.sql', false, $e->getMessage(), (string) $e->hint);
        }

        /* ---- Writable storage + the lock ---------------------------- */
        $storage = defined('STORAGE_PATH') ? (string) STORAGE_PATH : $this->appRoot . '/storage';
        $storageOk = is_dir($storage) && is_writable($storage);
        $checks[] = self::check(
            'storage',
            'Storage writable',
            $storageOk,
            $this->relativePath($storage) . ($storageOk ? ' is writable' : ' is missing or read-only'),
            $storageOk
                ? ''
                : 'Create system/storage with mode 0775 and grant the web-server user write access (/storage/.htaccess keeps it private).'
        );

        $lockPath   = self::lockPath($this->appRoot);
        $lockDir    = dirname($lockPath);
        $lockDirOk  = is_dir($lockDir) && is_writable($lockDir);
        $checks[]   = self::check(
            'lock_dir',
            'Lock directory',
            $lockDirOk,
            $this->relativePath($lockDir) . ($lockDirOk ? ' is writable' : ' is missing or read-only'),
            $lockDirOk ? '' : 'The installer writes storage/install.lock after a successful install; make the folder writable.'
        );

        $locked = self::isLocked($this->appRoot);
        $lock   = $locked ? self::readLock($this->appRoot) : null;
        $checks[] = self::check(
            'install_lock',
            'Install lock',
            !$locked,
            $locked
                ? 'installed ' . ($lock['installed_at'] ?? 'at an unknown date')
                    . ' into ' . ($lock['database'] ?? 'an unknown database')
                    . ' (' . ($lock['channel'] ?? 'unknown channel') . ')'
                : 'storage/install.lock is absent',
            $locked
                ? 'This installation is already locked. Delete storage/install.lock only if you really intend to '
                    . 'wipe the database and install from scratch.'
                : ''
        );

        /* ---- The production key ------------------------------------- */
        $checks[] = $this->installKeyCheck((string) ($installKey ?? ''));

        $ok = true;

        foreach ($checks as $check) {
            if (!$check['ok']) {
                $ok = false;
                break;
            }
        }

        return [
            'ok'       => $ok,
            'database' => $safeName ?? $database,
            'checks'   => $checks,
        ];
    }

    /* ==========================================================
     *  Install (plan IR-3-T2 … T5)
     * ========================================================== */

    /**
     * Install the bundle into an empty schema and return the report.
     *
     * Options (plan §5.6):
     *
     *   database        string  target schema; defaults to DB_NAME
     *   create_database bool    create the schema when it does not exist
     *   admin           array   {username, email, password, password_confirm?}
     *   superadmin      array   same shape
     *   site_name       string  settings.site_name (1–80 chars); '' keeps the bundle value
     *   with_demo       bool    install the demo tree after the bundle (never production)
     *   install_sql     string  alternative bundle path (absolute, or relative to the app root)
     *   channel         string  'cli' (default) or 'web' — recorded in settings + the lock
     *   install_key     string  the key the operator supplied; checked only in production
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed> the report (Appendix D shape, see the docblock below)
     * @throws DatabaseToolException on any refusal or failure
     */
    public function install(array $options): array
    {
        $started = microtime(true);

        /** @var list<array<string, mixed>> $steps */
        $steps    = [];
        $warnings = [];

        /** Demo result for the report; stays "not installed" unless with_demo succeeded. */
        $demo = [
            'installed'   => false,
            'members'     => 0,
            'codes'       => 0,
            'ledger_rows' => 0,
            'wallets'     => [],
            'credentials' => [],
            'statements'  => 0,
            'ms'          => 0,
        ];

        $record = static function (
            string $key,
            string $label,
            string $status,
            int $rows = 0,
            int $ms = 0,
            ?string $error = null
        ) use (&$steps): void {
            $step = ['key' => $key, 'label' => $label, 'status' => $status, 'rows' => $rows, 'ms' => $ms];

            if ($error !== null) {
                $step['error'] = $error;
            }

            $steps[] = $step;
        };

        /* ---- Normalise and validate the options --------------------- */
        $database = $this->target->assertSafeDatabaseName((string) ($options['database'] ?? DB_NAME));
        $channel  = strtolower(trim((string) ($options['channel'] ?? 'cli')));

        if (!in_array($channel, self::CHANNELS, true)) {
            throw DatabaseToolException::usage(
                'Unknown install channel "' . $channel . '".',
                ['channel' => $channel],
                'Use one of: ' . implode(', ', self::CHANNELS) . '.'
            );
        }

        $withDemo = !empty($options['with_demo']);

        $siteName = trim((string) ($options['site_name'] ?? ''));

        if ($siteName !== '' && mb_strlen($siteName) > 80) {
            throw DatabaseToolException::usage(
                'The site name must be 1–80 characters.',
                ['site_name_length' => mb_strlen($siteName)],
                'Pass a shorter --site-name.'
            );
        }

        $this->assertInstallKey((string) ($options['install_key'] ?? ''));

        $credentials = [
            'admin'      => (array) ($options['admin'] ?? []),
            'superadmin' => (array) ($options['superadmin'] ?? []),
        ];

        $validation = $this->validateStaffInput($credentials);

        if (!$validation['ok']) {
            throw DatabaseToolException::usage(
                'The staff credentials are not valid: ' . implode(' ', array_values($validation['errors'])),
                ['errors' => $validation['errors']],
                'Usernames are 3–50 letters, digits, dots, underscores or dashes; emails must be valid and differ; '
                    . 'passwords are 12–72 characters and must not be a published default.'
            );
        }

        /* ---- Refuse an already-installed tree ----------------------- */
        if (self::isLocked($this->appRoot)) {
            $lock = self::readLock($this->appRoot) ?? [];

            throw DatabaseToolException::locked(
                'This installation is already locked'
                    . (isset($lock['installed_at']) ? ' (installed ' . (string) $lock['installed_at'] . ')' : '') . '.',
                [
                    'database'  => $lock['database'] ?? null,
                    'lock'      => $this->relativePath(self::lockPath($this->appRoot)),
                    'installed' => isset($lock['installed_at']),
                ],
                'Delete ' . $this->relativePath(self::lockPath($this->appRoot))
                    . ' only if you intend to wipe the database and install from scratch.'
            );
        }

        /* ---- Advisory lock: two installers must not interleave ------ */
        $lockName = 'ecowealth:install:' . substr(sha1($database), 0, 16);
        $guard    = $this->target->connect();

        $t = microtime(true);

        if (!$this->advisoryLock($guard, $lockName, 10)) {
            throw DatabaseToolException::locked(
                'Another installer is already running for "' . $database . '".',
                ['database' => $database, 'lock' => $lockName],
                'Wait for that run to finish and try again.'
            );
        }

        $record('lock', 'Advisory lock acquired', 'ok', 0, self::ms($t));

        try {
            /* ---- Schema: create when asked, else require it --------- */
            $t = microtime(true);

            if (!$this->target->exists($database)) {
                if (empty($options['create_database'])) {
                    throw DatabaseToolException::preflight(
                        'The database "' . $database . '" does not exist.',
                        ['database' => $database],
                        'Create it first (hosting panel or CREATE DATABASE), or pass create_database / '
                            . '--create-database to let the installer do it.'
                    );
                }

                $this->target->create($database);
                $record('create_database', 'Create the empty database', 'ok', 1, self::ms($t));
            } else {
                $record('create_database', 'Database already exists', 'skip', 0, self::ms($t));
            }

            /* ---- The empty-database guard ---------------------------- */
            $t      = microtime(true);
            $tables = $this->target->tableCount($database);

            if ($tables > 0) {
                throw DatabaseToolException::notEmpty(
                    'The database "' . $database . '" is not empty (it already holds ' . $tables
                        . ' table' . ($tables === 1 ? '' : 's') . ').',
                    ['database' => $database, 'tables' => $tables],
                    'The install bundle is fresh-install only. Drop and recreate the schema, then run the '
                        . 'installer again; existing databases are upgraded with database/migrations/NNN_*.sql.'
                );
            }

            $record('guard', 'Target schema is empty', 'ok', 0, self::ms($t));

            /* ---- Demo preconditions, before the bundle runs ----------- */
            if ($withDemo) {
                $this->assertDemoRequestAllowed($database);
            }

            /* ---- Import the bundle ----------------------------------- */
            $t          = microtime(true);
            $bundlePath = $this->installSqlPath($options);
            $script     = SqlScript::fromFile($bundlePath);

            if ($script->containsDatabaseSwitch()) {
                throw DatabaseToolException::refused(
                    'The install bundle contains an executable CREATE DATABASE or USE statement.',
                    ['source' => $this->relativePath($bundlePath)],
                    'Rebuild it with php bin/build_install_sql.php (D-IR-02: the caller selects the schema).'
                );
            }

            $pdo = $this->target->connect($database);

            try {
                $run = $script->run($pdo);
            } catch (DatabaseToolException $e) {
                if ($this->isGuardFailure($e, $database)) {
                    throw DatabaseToolException::notEmpty(
                        'The database "' . $database . '" stopped being empty while the bundle ran; '
                            . 'the install bundle refused to continue.',
                        ['database' => $database, 'driver_code' => (int) ($e->context['driver_code'] ?? 0)],
                        'Drop and recreate the schema, then run the installer again. Never re-run the bundle on '
                            . 'top of a partial import.'
                    );
                }

                throw $e;
            }

            $record(
                'bundle',
                'Import ' . $this->relativePath($bundlePath),
                'ok',
                $run['statements'],
                self::ms($t)
            );

            /* ---- Optional demo tree (plan IR-7-T2) ------------------- */
            if ($withDemo) {
                $t           = microtime(true);
                $demoService = new DemoDataService($this->appRoot, $this->env);

                /* Re-check on the imported schema: the packages exist now,
                 * and a customized bundle without the two default packages
                 * must refuse before a single demo row is written. */
                $availability = $demoService->available($pdo, true, false);

                if (!$availability['ok']) {
                    throw DatabaseToolException::refused(
                        $availability['reason'],
                        ['database' => $database, 'with_demo' => true],
                        'The bundle was imported, so the schema exists, but no demo row was written. Drop and '
                            . 'recreate the schema and install again without --with-demo (or with a catalogue that '
                            . 'contains the default packages).'
                    );
                }

                $demo = $demoService->install($pdo);
                $demo['installed'] = true;

                $record(
                    'demo',
                    'Install the demo tree (members, wallets and codes)',
                    'ok',
                    $demo['members'] + $demo['codes'] + $demo['ledger_rows'],
                    self::ms($t)
                );
            }

            /* ---- Staff credentials ----------------------------------- */
            $t        = microtime(true);
            $accounts = [];

            foreach (self::STAFF_ROLES as $role) {
                $count = (int) self::scalar($pdo, 'SELECT COUNT(*) FROM users WHERE role = ?', [$role]);

                if ($count !== 1) {
                    throw DatabaseToolException::verify(
                        'The bundle must seed exactly one ' . $role . ' account; it created ' . $count . '.',
                        ['role' => $role, 'rows' => $count],
                        'The install is not trustworthy. Drop the schema, rebuild the bundle with '
                            . 'php bin/build_install_sql.php and try again.'
                    );
                }

                $account = $validation['staff'][$role];

                $update = $pdo->prepare(
                    "UPDATE users
                        SET username = ?, email = ?, password_hash = ?,
                            member_status = 'active', is_active = 1
                      WHERE role = ?"
                );
                $update->execute([
                    $account['username'],
                    $account['email'],
                    password_hash((string) $credentials[$role]['password'], PASSWORD_DEFAULT),
                    $role,
                ]);

                $accounts[] = [
                    'username' => $account['username'],
                    'email'    => $account['email'],
                    'role'     => $role,
                ];
            }

            $record('staff', 'Set both staff accounts', 'ok', count($accounts), self::ms($t));

            /* ---- Settings + install metadata ------------------------- */
            $t       = microtime(true);
            $version = (string) self::scalar($pdo, "SELECT value FROM settings WHERE key_name = 'install_version'");

            if ($version === '') {
                $version = self::DEFAULT_APP_VERSION;
            }

            $completedAt = date('Y-m-d H:i:s');
            $settings    = [
                'install_version'      => $version,
                'install_channel'      => $channel,
                'install_completed_at' => $completedAt,
            ];

            if ($siteName !== '') {
                $settings = ['site_name' => $siteName] + $settings;
            }

            $upsert = $pdo->prepare(
                'INSERT INTO settings (key_name, value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE value = ?'
            );

            foreach ($settings as $key => $value) {
                $upsert->execute([$key, $value, $value]);
            }

            if ($siteName === '') {
                $warnings[] = 'The site name was left at the value the bundle seeds ("'
                    . (string) self::scalar($pdo, "SELECT value FROM settings WHERE key_name = 'site_name'") . '").';
            }

            $record('settings', 'Write the install metadata', 'ok', count($settings), self::ms($t));

            /* ---- Verification ---------------------------------------- */
            $t      = microtime(true);
            $verify = $this->verify($pdo, [
                'database'   => $database,
                'admin'      => $credentials['admin'],
                'superadmin' => $credentials['superadmin'],
                'channel'    => $channel,
                'with_demo'  => $withDemo,
            ]);

            $record(
                'verify',
                'Verify the installation',
                $verify['ok'] ? 'ok' : 'fail',
                count($verify['checks']),
                self::ms($t),
                $verify['ok'] ? null : 'See the verification checks below.'
            );

            /* ---- Lock (last: a failed install never looks installed) -- */
            $t    = microtime(true);
            $lock = [
                'installed_at'       => date('c'),
                'database'           => $database,
                'app_version'        => $version,
                'install_sql_sha256' => $script->checksum(),
                'staff'              => array_column($accounts, 'username'),
                'channel'            => $channel,
            ];

            $written = $this->writeLock($lock);

            if ($written['written']) {
                $record('lock_file', 'Write the install lock', 'ok', 1, self::ms($t));
            } else {
                $record('lock_file', 'Write the install lock', 'fail', 0, self::ms($t), $written['error']);
                $warnings[] = 'The database is installed, but ' . $this->relativePath($written['path'])
                    . ' could not be written (' . $written['error'] . '). Create it by hand with the JSON in '
                    . '"lock.contents" so no previous run can install over this one.';
            }

            /* ---- Assemble the report --------------------------------- */
            $checks   = $verify['checks'];
            $checks[] = $this->lockCheck(true, $lock);

            $ok = true;

            foreach ($checks as $check) {
                if (!$check['ok']) {
                    $ok = false;
                    break;
                }
            }

            $report = $this->report(
                true,
                $database,
                $channel,
                $started,
                $steps,
                $warnings,
                $verify['counts'],
                $checks,
                [
                    'accounts'   => $accounts,
                    'usernames'  => array_column($accounts, 'username'),
                ],
                [
                    'path'     => $this->relativePath($written['path']),
                    'written'  => (bool) $written['written'],
                    'contents' => $lock,
                    'error'    => $written['written'] ? null : $written['error'],
                ],
                [
                    'path'    => $this->relativePath($bundlePath),
                    'sha256'  => $script->checksum(),
                    'bytes'   => (int) (@filesize($bundlePath) ?: 0),
                ],
                $demo,
                [
                    'database'        => $database,
                    'create_database' => !empty($options['create_database']),
                    'site_name'       => $siteName,
                    'with_demo'       => $withDemo,
                    'channel'         => $channel,
                    'install_sql'     => $this->relativePath($bundlePath),
                    'install_key_set' => trim((string) ($options['install_key'] ?? '')) !== '',
                    'admin'           => [
                        'username' => $validation['staff']['admin']['username'],
                        'email'    => $validation['staff']['admin']['email'],
                    ],
                    'superadmin'      => [
                        'username' => $validation['staff']['superadmin']['username'],
                        'email'    => $validation['staff']['superadmin']['email'],
                    ],
                ]
            );

            return $report;
        } finally {
            $this->releaseLock($guard, $lockName);
        }
    }

    /* ==========================================================
     *  Verification (plan IR-3-T5, §6.5, Appendix E)
     * ========================================================== */

    /**
     * Check that a schema is a complete production install.
     *
     * @param array{
     *   database?:string, channel?:string, with_demo?:bool, require_lock?:bool,
     *   admin?:array<string,mixed>, superadmin?:array<string,mixed>
     * } $options
     * @return array{ok:bool, database:string, counts:array<string,int>, checks:list<array{
     *     key:string, label:string, ok:bool, detail:string, hint:string
     * }>}
     */
    public function verify(PDO $pdo, array $options = []): array
    {
        $database = trim((string) ($options['database'] ?? ''));

        if ($database === '') {
            $selected = $pdo->query('SELECT DATABASE()')->fetchColumn();
            $database = is_string($selected) ? $selected : '';
        }

        $withDemo    = !empty($options['with_demo']);
        $channel     = isset($options['channel']) ? (string) $options['channel'] : null;
        $requireLock = !empty($options['require_lock']);

        $counts = array_fill_keys(array_keys(self::EXPECTED), 0) + ['ledger_rows' => 0];

        $counts['tables_created'] = (int) self::scalar(
            $pdo,
            "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = ? AND table_type = 'BASE TABLE'",
            [$database]
        );

        /* A schema that is not a complete install (a missing table, a
         * half-applied import) must be reported, not fatal. Every row
         * count below assumes the table exists. */
        try {
            $counts['settings_rows'] = (int) self::scalar($pdo, 'SELECT COUNT(*) FROM settings');
            $counts['staff_rows']    = (int) self::scalar($pdo, "SELECT COUNT(*) FROM users WHERE role IN ('admin','superadmin')");
            $counts['members_rows']  = (int) self::scalar($pdo, "SELECT COUNT(*) FROM users WHERE role = 'member'");
            $counts['products_rows'] = (int) self::scalar($pdo, 'SELECT COUNT(*) FROM products');
            $counts['packages_rows'] = (int) self::scalar($pdo, 'SELECT COUNT(*) FROM packages');
            $counts['levels_rows']   = (int) self::scalar($pdo, 'SELECT COUNT(*) FROM package_levels');
            $counts['contents_rows'] = (int) self::scalar($pdo, 'SELECT COUNT(*) FROM package_products');
            $counts['codes_rows']    = (int) self::scalar($pdo, 'SELECT COUNT(*) FROM reg_codes');
            $counts['ledger_rows']   = (int) self::scalar($pdo, 'SELECT COUNT(*) FROM ewallet_ledger');
        } catch (PDOException $e) {
            error_log('[ecowealth] installer verify: ' . $e->getMessage());

            return [
                'ok'       => false,
                'database' => $database,
                'counts'   => $counts,
                'checks'   => [self::check(
                    'tables',
                    'Tables',
                    false,
                    $counts['tables_created'] . ' of ' . self::EXPECTED['tables_created']
                        . ' tables — a required table is missing',
                    'The schema is incomplete or damaged. Drop it and run the install bundle again; '
                        . 'never patch a half-applied install by hand.'
                )],
            ];
        }

        $expected = self::EXPECTED;

        if ($withDemo) {
            $expected['members_rows'] = self::EXPECTED_DEMO['members_rows'];
            $expected['codes_rows']   = self::EXPECTED_DEMO['codes_rows'];
            $expected['ledger_rows']  = self::EXPECTED_DEMO['ledger_rows'];
        }

        $checks = [];

        $checks[] = self::check(
            'tables',
            'Tables',
            $counts['tables_created'] === $expected['tables_created'],
            $counts['tables_created'] . ' of ' . $expected['tables_created'] . ' tables',
            'The schema is incomplete: drop it and re-run the install bundle.'
        );

        $checks[] = self::check(
            'settings',
            'Settings',
            $counts['settings_rows'] === $expected['settings_rows'],
            $counts['settings_rows'] . ' of ' . $expected['settings_rows'] . ' settings',
            'The bundle seeds 51 keys plus the three install_* metadata rows; rebuild it with php bin/build_install_sql.php.'
        );

        /* ---- Staff rows: exactly one per role, active ---------------- */
        $staffRows = self::rows(
            $pdo,
            "SELECT username, role, member_status, is_active
               FROM users WHERE role IN ('admin','superadmin') ORDER BY id"
        );

        $roles  = array_map(static fn (array $row): string => (string) $row['role'], $staffRows);
        $staffOk = $counts['staff_rows'] === $expected['staff_rows']
            && count($staffRows) === 2
            && in_array('admin', $roles, true)
            && in_array('superadmin', $roles, true);

        foreach ($staffRows as $row) {
            if ((string) $row['member_status'] !== 'active' || (int) $row['is_active'] !== 1) {
                $staffOk = false;
            }
        }

        $checks[] = self::check(
            'staff',
            'Staff accounts',
            $staffOk,
            implode(', ', array_map(
                static fn (array $row): string => (string) $row['username'] . ' (' . (string) $row['role'] . ')',
                $staffRows
            )),
            'Both staff rows must exist and be able to sign in (member_status = active, is_active = 1).'
        );

        /* ---- Both operator passwords really work -------------------- */
        $signinChecks = [];

        foreach (self::STAFF_ROLES as $role) {
            $block = (array) ($options[$role] ?? []);
            $password = (string) ($block['password'] ?? '');

            if ($password === '') {
                continue;
            }

            $row = self::row($pdo, 'SELECT username, password_hash FROM users WHERE role = ? LIMIT 1', [$role]);

            $signinChecks[] = $row !== false
                && hash_equals((string) $row['username'], (string) ($block['username'] ?? $row['username']))
                && password_verify($password, (string) $row['password_hash']);
        }

        $checks[] = self::check(
            'signin',
            'Staff sign-in',
            $signinChecks === [] || !in_array(false, $signinChecks, true),
            $signinChecks === []
                ? 'not checked (no passwords supplied)'
                : count($signinChecks) . ' password' . (count($signinChecks) === 1 ? '' : 's') . ' verified',
            'The stored password_hash must verify the password the operator chose; re-run the install with the '
                . 'same credentials or reset the password from the application.'
        );

        /* ---- Members, catalogue, codes ------------------------------- */
        $checks[] = self::check(
            'members',
            'Members',
            $counts['members_rows'] === $expected['members_rows'],
            $counts['members_rows'] . ' of ' . $expected['members_rows'] . ' members',
            $withDemo
                ? 'The demo tree should hold five members (juan, maria, peter, sarah, david).'
                : 'A production install seeds no members; demo data is an explicit import (seeds/005_demo_tree.sql).'
        );

        $catalogueOk = $counts['products_rows'] === $expected['products_rows']
            && $counts['packages_rows'] === $expected['packages_rows']
            && $counts['levels_rows'] === $expected['levels_rows']
            && $counts['contents_rows'] === $expected['contents_rows'];

        $checks[] = self::check(
            'catalogue',
            'Catalogue',
            $catalogueOk,
            $counts['products_rows'] . ' products / ' . $counts['packages_rows'] . ' packages / '
                . $counts['levels_rows'] . ' levels / ' . $counts['contents_rows'] . ' contents',
            'Expected ' . $expected['products_rows'] . ' / ' . $expected['packages_rows'] . ' / '
                . $expected['levels_rows'] . ' / ' . $expected['contents_rows'] . '; rebuild the bundle from seeds 003–004.'
        );

        $codesOk = $counts['codes_rows'] === $expected['codes_rows'];
        $checks[] = self::check(
            'codes',
            'Registration codes',
            $codesOk,
            $counts['codes_rows'] . ' of ' . $expected['codes_rows'] . ' codes',
            $withDemo ? 'The demo seed writes three codes.' : 'A production install seeds no registration codes.'
        );

        /* ---- Every transactional table is empty --------------------- */
        $nonEmpty = [];
        $skip     = $withDemo ? self::DEMO_TABLES : [];

        foreach (self::TRANSACTIONAL_TABLES as $table) {
            if (in_array($table, $skip, true)) {
                continue;
            }

            $rows = (int) self::scalar($pdo, 'SELECT COUNT(*) FROM `' . $table . '`');

            if ($rows > 0) {
                $nonEmpty[] = $table . '=' . $rows;
            }
        }

        if ($withDemo) {
            $checks[] = self::check(
                'demo_rows',
                'Demo fixtures',
                $counts['ledger_rows'] === $expected['ledger_rows'],
                $counts['ledger_rows'] . ' of ' . $expected['ledger_rows'] . ' demo ledger rows',
                'The demo seed writes one ledger row per funded demo wallet (juan, maria, peter).'
            );

            $checks[] = $this->demoWalletCheck($pdo);
        }

        $checks[] = self::check(
            'transactional',
            'No transactional rows',
            $nonEmpty === [],
            $nonEmpty === [] ? 'every shop, wallet, membership and session table is empty' : implode(', ', $nonEmpty),
            'A fresh install contains no orders, ledger rows, codes, carts, proofs or sessions. '
                . 'Drop the schema and re-import the bundle; do not delete rows by hand.'
        );

        /* ---- Install metadata --------------------------------------- */
        $metadata = [];

        foreach (self::rows(
            $pdo,
            "SELECT key_name, value FROM settings
              WHERE key_name IN ('install_version','install_channel','install_completed_at')"
        ) as $row) {
            $metadata[(string) $row['key_name']] = (string) $row['value'];
        }

        $metadataOk = ($metadata['install_version'] ?? '') !== ''
            && ($metadata['install_channel'] ?? '') !== ''
            && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) ($metadata['install_completed_at'] ?? '')) === 1;

        if ($channel !== null && ($metadata['install_channel'] ?? '') !== $channel) {
            $metadataOk = false;
        }

        $checks[] = self::check(
            'metadata',
            'Install metadata',
            $metadataOk,
            'version ' . ($metadata['install_version'] ?? '—')
                . ' · channel ' . ($metadata['install_channel'] ?? '—')
                . ' · ' . ($metadata['install_completed_at'] ?? '—'),
            'Re-run the install (the footer writes install_version/install_channel/install_completed_at).'
        );

        /* ---- The lock, when the caller asks for it ------------------- */
        if ($requireLock) {
            $checks[] = $this->lockCheck(true);
        }

        $ok = true;

        foreach ($checks as $check) {
            if (!$check['ok']) {
                $ok = false;
                break;
            }
        }

        return [
            'ok'       => $ok,
            'database' => $database,
            'counts'   => $counts,
            'checks'   => $checks,
        ];
    }

    /* ==========================================================
     *  Staff credential validation (plan §6.8)
     * ========================================================== */

    /**
     * Validate the two staff credential blocks before anything is written.
     *
     * The target database is empty by contract, so the only uniqueness
     * that can collide is between the two new accounts: the same username
     * or email twice would make the second UPDATE fail (or worse, silently
     * point one login at the other).
     *
     * @param array{admin?:array<string,mixed>, superadmin?:array<string,mixed>} $input
     *        each block: username, email, password and optionally password_confirm
     * @return array{ok:bool, errors:array<string,string>, staff:array<string,array{username:string,email:string,role:string}>}
     */
    public function validateStaffInput(array $input): array
    {
        $errors = [];
        $staff  = [];
        $seen   = [];

        foreach (self::STAFF_ROLES as $role) {
            $block = (array) ($input[$role] ?? []);

            $username = trim((string) ($block['username'] ?? ''));
            $email    = strtolower(trim((string) ($block['email'] ?? '')));
            $password = (string) ($block['password'] ?? '');

            if ($username === '') {
                $errors[$role . '.username'] = ucfirst($role) . ' username is required.';
            } elseif (preg_match(self::USERNAME_PATTERN, $username) !== 1) {
                $errors[$role . '.username'] = ucfirst($role)
                    . ' username must be 3–50 characters: letters, numbers, dot, underscore or dash.';
            }

            if ($email === '') {
                $errors[$role . '.email'] = ucfirst($role) . ' email is required.';
            } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $errors[$role . '.email'] = 'Enter a valid email address for the ' . $role . ' account.';
            }

            if ($password === '') {
                $errors[$role . '.password'] = ucfirst($role) . ' password is required.';
            } else {
                $length = strlen($password);

                if ($length < self::PASSWORD_MIN || $length > self::PASSWORD_MAX) {
                    $errors[$role . '.password'] = ucfirst($role) . ' password must be '
                        . self::PASSWORD_MIN . '–' . self::PASSWORD_MAX . ' characters long.';
                } elseif (in_array($password, self::REJECTED_PASSWORDS, true)) {
                    $errors[$role . '.password'] = 'That password is a published default; choose a different one.';
                }
            }

            if (array_key_exists('password_confirm', $block)
                && (string) $block['password_confirm'] !== $password) {
                $errors[$role . '.password_confirm'] = 'The two passwords do not match.';
            }

            $staff[$role] = ['username' => $username, 'email' => $email, 'role' => $role];

            /* Case-insensitive because uq_users_username/uq_users_email are
             * utf8mb4_unicode_ci on the server too. */
            foreach (['username' => 'username', 'email' => 'email'] as $field) {
                $value = $staff[$role][$field];

                if ($value === '') {
                    continue;
                }

                if (isset($seen[$field]) && strcasecmp($seen[$field], $value) === 0) {
                    $errors[$role . '.' . $field] = 'The two staff accounts need different '
                        . ($field === 'email' ? 'email addresses' : 'usernames') . '.';
                }

                $seen[$field] = $value;
            }
        }

        return [
            'ok'     => $errors === [],
            'errors' => $errors,
            'staff'  => $staff,
        ];
    }

    /* ==========================================================
     *  Install lock (plan IR-3-T4)
     * ========================================================== */

    /**
     * The lock file path: `<STORAGE_PATH>/install.lock` when the constant
     * is defined (the test harness points it at scratch space), otherwise
     * `<app>/storage/install.lock`.
     */
    public static function lockPath(?string $appRoot = null): string
    {
        if (defined('STORAGE_PATH') && (string) STORAGE_PATH !== '') {
            return rtrim(str_replace('\\', '/', (string) STORAGE_PATH), '/') . '/' . self::LOCK_FILE;
        }

        return self::resolveAppRoot($appRoot) . '/storage/' . self::LOCK_FILE;
    }

    /** Is there a lock file? (A corrupt file is still a lock.) */
    public static function isLocked(?string $appRoot = null): bool
    {
        return is_file(self::lockPath($appRoot));
    }

    /**
     * The decoded lock, or null when the file is missing or not valid JSON.
     *
     * @return array<string, mixed>|null
     */
    public static function readLock(?string $appRoot = null): ?array
    {
        $path = self::lockPath($appRoot);

        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $json = file_get_contents($path);

        if ($json === false || trim($json) === '') {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * The key configured for this installation (INSTALL_KEY), or ''.
     *
     * Read at call time so a test or a bootstrap that defines the constant
     * before the class is used is honoured.
     */
    public static function configuredInstallKey(): string
    {
        return defined('INSTALL_KEY') ? (string) INSTALL_KEY : '';
    }

    /**
     * Does the submitted key match the configured one?
     *
     * A configured-but-empty key never matches, even against an empty
     * submission: an empty INSTALL_KEY must not turn the production gate
     * into a no-op (plan IR-4-T5). `$expected` exists so the tests can
     * exercise the comparison without redefining the constant; the
     * installer itself always passes the configured key. The actual
     * comparison lives in DbToolAccess so the CLI and the web page share
     * one implementation.
     */
    public static function installKeyMatches(string $submitted, ?string $expected = null): bool
    {
        $expected ??= self::configuredInstallKey();

        /* One implementation, in DbToolAccess (IR-4). The argument order
         * stays as documented for the CLI: (submitted, expected). */
        return DbToolAccess::installKeyMatches($expected, $submitted);
    }

    /* ==========================================================
     *  Internals
     * ========================================================== */

    /**
     * The install report (plan Appendix D + IR-3-T5).
     *
     * Top-level keys: tool, version, ok, mode, database, started_at,
     * duration_ms, options (redacted — never a password or the key),
     * steps, counts, staff, lock, install_sql, verification, checks
     * (alias of verification.checks) and warnings.
     *
     * @param list<array<string,mixed>> $steps
     * @param list<string> $warnings
     * @param array<string,int> $counts
     * @param list<array<string,mixed>> $checks
     * @param array<string,mixed> $staff
     * @param array<string,mixed> $lock
     * @param array<string,mixed> $bundle
     * @param array<string,mixed> $demo
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function report(
        bool $ok,
        string $database,
        string $channel,
        float $started,
        array $steps,
        array $warnings,
        array $counts,
        array $checks,
        array $staff,
        array $lock,
        array $bundle,
        array $demo,
        array $options
    ): array {
        $verificationOk = true;

        foreach ($checks as $check) {
            if (!$check['ok']) {
                $verificationOk = false;
                break;
            }
        }

        return [
            'tool'         => 'db_install',
            'version'      => self::REPORT_VERSION,
            'ok'           => $ok && $verificationOk,
            'mode'         => 'install',
            'database'     => $database,
            'channel'      => $channel,
            'started_at'   => date('c', (int) $started),
            'duration_ms'  => self::ms($started),
            'options'      => $options,
            'steps'        => $steps,
            'counts'       => $counts,
            'staff'        => [
                'reset'     => false,
                'usernames' => $staff['usernames'],
                'accounts'  => $staff['accounts'],
            ],
            'lock'         => $lock,
            'install_sql'  => $bundle,
            'demo'         => $demo,
            'verification' => ['ok' => $verificationOk, 'checks' => $checks],
            'checks'       => $checks,
            'warnings'     => $warnings,
        ];
    }

    /** The production gate: INSTALL_KEY must be set and matched (plan IR-3-T3). */
    private function assertInstallKey(string $submitted): void
    {
        if ($this->env !== 'production') {
            return;
        }

        $configured = self::configuredInstallKey();

        if ($configured === '') {
            throw DatabaseToolException::refused(
                'INSTALL_KEY is empty, so the installer refuses to run in production.',
                ['env' => $this->env, 'key_configured' => false],
                'Set a long random INSTALL_KEY in app/config.local.php (or the ECO_INSTALL_KEY environment '
                    . 'variable) and pass the same value with --key.'
            );
        }

        if (!self::installKeyMatches($submitted, $configured)) {
            throw DatabaseToolException::refused(
                'The install key does not match INSTALL_KEY.',
                ['env' => $this->env, 'key_configured' => true, 'key_supplied' => $submitted !== ''],
                'Pass --key=<the INSTALL_KEY value from app/config.local.php>.'
            );
        }
    }

    /** The preflight/verify entry for the production key. */
    private function installKeyCheck(string $submitted): array
    {
        if ($this->env !== 'production') {
            return self::check('install_key', 'INSTALL_KEY', true, 'not required outside production');
        }

        $configured = self::configuredInstallKey();

        if ($configured === '') {
            return self::check(
                'install_key',
                'INSTALL_KEY',
                false,
                'INSTALL_KEY is empty',
                'Set a long random INSTALL_KEY in app/config.local.php (or ECO_INSTALL_KEY) before installing in production.'
            );
        }

        if (!self::installKeyMatches($submitted, $configured)) {
            return self::check(
                'install_key',
                'INSTALL_KEY',
                false,
                $submitted === '' ? 'no --key was supplied' : 'the supplied key does not match',
                'Pass --key=<INSTALL_KEY>. The key is never printed or stored in the report.'
            );
        }

        return self::check('install_key', 'INSTALL_KEY', true, 'present and matched');
    }

    /**
     * Refuse an impossible demo request before a single row is written
     * (plan IR-7-T2, D-IR-13).
     *
     * The target is empty by contract, so this checks the environment,
     * the seed file and — when the schema already exists — that it really
     * holds no business rows. The catalogue check cannot run here (the
     * bundle seeds the packages); it re-runs after the import.
     */
    private function assertDemoRequestAllowed(string $database): void
    {
        $service = new DemoDataService($this->appRoot, $this->env);

        if ($this->env === 'production') {
            throw DatabaseToolException::refused(
                'Demo fixtures cannot be installed in production (APP_ENV=production).',
                ['env' => $this->env, 'with_demo' => true],
                'Install without demo data; database/seeds/005_demo_tree.sql is a QA fixture and must never be '
                    . 'imported into production.'
            );
        }

        if (!is_file($service->seedPath())) {
            throw DatabaseToolException::refused(
                'The demo seed ' . DemoDataService::SEED_FILE . ' is missing.',
                ['path' => $service->seedPath()],
                'Restore the file from the repository; the production bundle never contains demo data.'
            );
        }

        if (!$this->target->exists($database)) {
            /* The bundle creates an empty schema next; there is nothing to
             * inspect and nothing to merge. */
            return;
        }

        $pdo          = $this->target->connect($database);
        $availability = $service->available($pdo, true, false);

        if (!$availability['ok']) {
            throw DatabaseToolException::refused(
                $availability['reason'],
                ['database' => $database, 'with_demo' => true],
                'No table or row was written: the demo preconditions failed before the bundle ran. Drop and '
                    . 'recreate the schema, or install again without --with-demo.'
            );
        }
    }

    /**
     * The demo wallet/tree check used by verify() when with_demo was set.
     *
     * Delegates to DemoDataService so the installer and the reset engine
     * accept exactly the same tree (plan IR-3-T5, IR-7-T1).
     */
    private function demoWalletCheck(PDO $pdo): array
    {
        try {
            $verified = (new DemoDataService($this->appRoot, $this->env))->verify($pdo);
        } catch (Throwable $e) {
            error_log('[ecowealth] installer demo verification failed: ' . $e->getMessage());

            return self::check(
                'demo_wallets',
                'Demo wallets and tree',
                false,
                'the demo verification could not run; see the PHP error log',
                'Re-run the install with --with-demo after dropping the schema.'
            );
        }

        $byKey = [];

        foreach ($verified['checks'] as $check) {
            $byKey[(string) $check['key']] = $check;
        }

        $wallets = $byKey['wallets'] ?? null;
        $pending = $byKey['pending_member'] ?? null;

        $ok = is_array($wallets) && $wallets['ok'] === true
            && is_array($pending) && $pending['ok'] === true;

        return self::check(
            'demo_wallets',
            'Demo wallets and tree',
            $ok,
            (is_array($wallets) ? (string) $wallets['detail'] : 'no wallet data')
                . ' · david ' . (is_array($pending) && $pending['ok'] ? 'pending' : 'not in the expected state'),
            'The demo tree funds juan 2000/1500, maria 1000/800 and peter 500/400, and leaves david pending.'
        );
    }

    /**
     * Read the lock back and, when expected values are given, compare them.
     *
     * @param array<string,mixed>|null $expected
     */
    private function lockCheck(bool $required, ?array $expected = null): array
    {
        $path    = self::lockPath($this->appRoot);
        $display = $this->relativePath($path);

        if (!is_file($path)) {
            return self::check(
                'lock',
                'Install lock',
                !$required,
                $required ? $display . ' is missing' : 'not written yet',
                $required
                    ? 'The install did not leave a lock. Create ' . $display . ' by hand with the JSON from the report.'
                    : ''
            );
        }

        $lock = self::readLock($this->appRoot);

        if ($lock === null) {
            return self::check(
                'lock',
                'Install lock',
                false,
                $display . ' is not valid JSON',
                'Delete the corrupt file and install again, or recreate it with the JSON from the report.'
            );
        }

        $missing = array_values(array_diff(
            ['installed_at', 'database', 'app_version', 'install_sql_sha256', 'staff', 'channel'],
            array_keys($lock)
        ));

        if ($missing !== []) {
            return self::check(
                'lock',
                'Install lock',
                false,
                $display . ' is missing ' . implode(', ', $missing),
                'The lock must record installed_at, database, app_version, install_sql_sha256, staff and channel.'
            );
        }

        $problems = [];

        if ($expected !== null) {
            foreach (['database', 'app_version', 'install_sql_sha256', 'channel'] as $field) {
                if ((string) ($lock[$field] ?? '') !== (string) ($expected[$field] ?? '')) {
                    $problems[] = $field;
                }
            }
        }

        return self::check(
            'lock',
            'Install lock',
            $problems === [],
            $problems === []
                ? $display . ' records the install into ' . (string) $lock['database']
                : $display . ' does not match this install (' . implode(', ', $problems) . ')',
            'Delete the lock and re-run the installer only if you intend to wipe the database.'
        );
    }

    /**
     * Write the lock atomically (temp file + rename) and return what happened.
     *
     * @param array<string,mixed> $lock
     * @return array{written:bool, path:string, json:string, error:?string}
     */
    private function writeLock(array $lock): array
    {
        $path = self::lockPath($this->appRoot);
        $json = (string) json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . "\n";

        $result = ['written' => false, 'path' => $path, 'json' => $json, 'error' => null];

        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            $result['error'] = 'the folder ' . $this->relativePath($directory) . ' could not be created';

            return $result;
        }

        $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (@file_put_contents($temporary, $json) === false) {
            $result['error'] = 'the file could not be written (permissions?)';

            return $result;
        }

        @chmod($temporary, 0600);

        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            $result['error'] = 'the temporary file could not be moved into place (permissions?)';

            return $result;
        }

        $result['written'] = true;

        return $result;
    }

    /** GET_LOCK: 1 acquired, 0 timeout, null error. */
    private function advisoryLock(PDO $pdo, string $name, int $timeout): bool
    {
        try {
            $statement = $pdo->prepare('SELECT GET_LOCK(?, ?)');
            $statement->execute([$name, $timeout]);

            return (int) $statement->fetchColumn() === 1;
        } catch (PDOException $e) {
            error_log('[ecowealth] installer GET_LOCK failed: ' . $e->getMessage());

            return false;
        }
    }

    /** Best-effort release: the connection closing would also release it. */
    private function releaseLock(PDO $pdo, string $name): void
    {
        try {
            $statement = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $statement->execute([$name]);
            $statement->closeCursor();
        } catch (PDOException $e) {
            error_log('[ecowealth] installer RELEASE_LOCK failed: ' . $e->getMessage());
        }
    }

    /**
     * Was this SQL failure the bundle's empty-database guard?
     *
     * The guard is the only block in the bundle that names the prepared
     * statement `ew_guard`. It fails with 1146 because the statement it
     * builds selects a deliberately missing table — MySQL 8.4 rejects the
     * `PREPARE` itself (older versions reject the `EXECUTE`), so both are
     * accepted. Anything else keeps its original error (and its statement
     * and line context): a real failure is never swallowed.
     */
    private function isGuardFailure(DatabaseToolException $e, string $database): bool
    {
        if ($e->kind !== DatabaseToolException::KIND_SQL) {
            return false;
        }

        if ((int) ($e->context['driver_code'] ?? 0) !== 1146) {
            return false;
        }

        if (preg_match('/^\s*(?:PREPARE|EXECUTE)\s+ew_guard\b/i', (string) ($e->context['sql'] ?? '')) !== 1) {
            return false;
        }

        try {
            return $this->target->tableCount($database) > 0;
        } catch (DatabaseToolException) {
            return true;
        }
    }

    /**
     * Absolute path of the bundle: the option (absolute, or relative to the
     * app root) or database/install.sql.
     *
     * @param array<string,mixed> $options
     */
    private function installSqlPath(array $options): string
    {
        $custom = trim((string) ($options['install_sql'] ?? ''));

        if ($custom === '') {
            return $this->appRoot . '/database/install.sql';
        }

        if (preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $custom) === 1 || is_file($custom)) {
            return $custom;
        }

        return $this->appRoot . '/' . ltrim(str_replace('\\', '/', $custom), '/');
    }

    /** A path as the operator sees it: relative to the app root when it is inside it. */
    private function relativePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $root = $this->appRoot . '/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    private static function resolveAppRoot(?string $appRoot): string
    {
        $root     = $appRoot ?? dirname(__DIR__, 2);
        $resolved = realpath($root);
        $root     = $resolved === false ? $root : $resolved;

        return rtrim(str_replace('\\', '/', $root), '/');
    }

    /** One preflight/verification row. */
    private static function check(string $key, string $label, bool $ok, string $detail, string $hint = ''): array
    {
        return ['key' => $key, 'label' => $label, 'ok' => $ok, 'detail' => $detail, 'hint' => $hint];
    }

    /** @param list<mixed> $params */
    private static function scalar(PDO $pdo, string $sql, array $params = []): mixed
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $value = $statement->fetchColumn();
        $statement->closeCursor();

        return $value;
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string,mixed>>
     */
    private static function rows(PDO $pdo, string $sql, array $params = []): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll();
        $statement->closeCursor();

        return is_array($rows) ? array_values($rows) : [];
    }

    /** @param list<mixed> $params */
    private static function row(PDO $pdo, string $sql, array $params = []): array|false
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();
        $statement->closeCursor();

        return is_array($row) ? $row : false;
    }

    /** Whole milliseconds elapsed since $started (float seconds). */
    private static function ms(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
