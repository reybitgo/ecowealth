<?php
declare(strict_types=1);

/* ==============================================================
 *  DatabaseResetService — the one way to return EcoWealth to a
 *  clean install state (plan IR-5, §7)
 *
 *      $service = new DatabaseResetService($pdo, $database, [
 *          'confirm'      => 'ecowealth_sneat',   // typed database name
 *          'code_qty'     => 0,                   // 0–50
 *          'tools'        => 'cli',
 *      ]);
 *      $preview = $service->preview();   // counts, staff, packages, warnings
 *      $plan    = $service->dryRun();    // preview + statement plan, no writes
 *      $report  = $service->run();       // the reset (7.11 report)
 *      $verify  = $service->verify();    // the same checks, callable later
 *
 *  A reset keeps the operator's configuration (settings) and both staff
 *  identities/passwords, and deletes every member and every business
 *  row. It is the exact operation the QA box needs between test runs:
 *
 *    • one DML transaction (D-IR-14) — a failure rolls everything back;
 *    • explicit child-first delete order with foreign-key checks ON
 *      (D-IR-08) — there is no SET FOREIGN_KEY_CHECKS anywhere;
 *    • staff reset to the install state (package/sponsor/registration
 *      null, wallet and transfer counters 0.00, last login null);
 *    • the catalogue is kept unless reset_catalogue is set, in which
 *      case the real 003/004 seeds are re-run *inside* the transaction;
 *    • audit is kept and one `system.db_reset` row is appended;
 *    • live revisions are bumped inside the transaction so every open
 *      page sees a change after a committed reset;
 *    • AUTO_INCREMENT, upload cleanup, the optional demo tree (IR-7) and
 *      registration codes run after the commit and are reported
 *      separately, never mixed into it;
 *    • verification (four reconciliations + invariants) is mandatory:
 *      a reset that cannot prove its result reports ok=false.
 *
 *  What it must not do (plan §5.1): authenticate a user, emit HTML,
 *  read CLI flags, or call the global db(). Every query uses the PDO
 *  injected by the caller, which is what lets the tests run the exact
 *  production code against ecowealth_sneat_tool_b_test.
 *
 *  Failure policy: a refusal (production guard, wrong confirmation, no
 *  staff, missing tables, invalid quantity) throws
 *  DatabaseToolException before anything is deleted; a SQL failure
 *  inside the transaction rolls it back and throws with the step name
 *  and a "nothing was deleted" hint. Messages never include a DSN or a
 *  password; the raw driver text goes to error_log().
 * ============================================================== */

final class DatabaseResetService
{
    /** Report schema version (plan Appendix D). */
    public const REPORT_VERSION = 1;

    /** Registration-code batch limit (plan 7.6). */
    public const MAX_CODES = 50;

    /** Attempts for one unique code, the same bound CodeService uses. */
    private const CODE_ATTEMPTS = 40;

    /** GET_LOCK wait for a concurrent reset (plan 5.9). */
    private const LOCK_TIMEOUT = 5;

    /** The two staff roles a reset keeps (D-IR-05). */
    public const STAFF_ROLES = ['admin', 'superadmin'];

    /**
     * Every table the reset engine reasons about (plan 2.4).
     *
     * This is the only allow-list a table name may come from: identifiers
     * in the SQL below are hard-coded constants, never input.
     */
    public const ALL_TABLES = [
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
        'package_levels',
        'package_products',
        'packages',
        'payout_requests',
        'products',
        'reg_codes',
        'remember_tokens',
        'settings',
        'shop_invoice_items',
        'shop_invoices',
        'shop_order_events',
        'shop_order_items',
        'shop_orders',
        'shop_payment_proofs',
        'shop_shipments',
        'users',
    ];

    /**
     * Tables that must hold zero rows after a reset (plan 7.11).
     *
     * `users` is covered by the members check, `audit_log` grows by the
     * reset row, `live_revisions` is bumped and `reg_codes` may carry the
     * generated batch — those four are verified separately.
     */
    public const ZERO_TABLES = [
        'addresses',
        'activations',
        'cart_items',
        'carts',
        'commissions',
        'ewallet_admin_topups',
        'ewallet_ledger',
        'ewallet_transfers',
        'impersonation_log',
        'payout_requests',
        'remember_tokens',
        'shop_invoice_items',
        'shop_invoices',
        'shop_order_events',
        'shop_order_items',
        'shop_orders',
        'shop_payment_proofs',
        'shop_shipments',
    ];

    /**
     * Tables whose counter is reset after commit, when empty (plan 7.10).
     *
     * `users` is never in this list: staff rows survive and keep their
     * ids (D-IR-15). Catalogue tables are excluded because they may have
     * just been re-seeded. `audit_log` is excluded because the reset row
     * always keeps one row in it.
     */
    public const AUTO_INCREMENT_TABLES = [
        'shop_orders',
        'shop_order_items',
        'shop_order_events',
        'shop_payment_proofs',
        'shop_shipments',
        'shop_invoices',
        'shop_invoice_items',
        'carts',
        'cart_items',
        'addresses',
        'ewallet_ledger',
        'ewallet_admin_topups',
        'ewallet_transfers',
        'payout_requests',
        'commissions',
        'activations',
        'reg_codes',
        'impersonation_log',
        'remember_tokens',
    ];

    /**
     * Every live surface a fresh install/back office can render (plan 5.12).
     *
     * A reset increments every existing row and inserts a missing surface
     * at revision 1, so an open page can never keep a pre-reset ETag.
     */
    public const STANDARD_SURFACES = [
        'admin.dashboard',
        'admin.products',
        'admin.packages',
        'admin.orders',
        'admin.members',
        'admin.activations',
        'admin.codes',
        'admin.commissions',
        'admin.wallets',
        'admin.payouts',
        'admin.reports',
        'admin.settings',
        'admin.audit',
        'admin.slogin',
        'shop.catalog',
        'shop.packages',
        'member.genealogy',
        'config',
    ];

    /** The upload files a reset never deletes (plan 7.7). */
    private const UPLOAD_KEEP = ['.gitkeep', '.htaccess'];

    /** The staff reset (plan 7.4 / Appendix A step 10). */
    private const STAFF_RESET_SQL = "UPDATE users SET
          package_id             = NULL,
          sponsor_id             = NULL,
          reg_code_id            = NULL,
          reg_paid_by            = NULL,
          reg_payment_method     = 'standard',
          activated_at           = NULL,
          referral_code          = NULL,
          member_status          = 'active',
          is_active              = 1,
          ewallet_balance        = 0.00,
          withdrawable_balance   = 0.00,
          ewallet_sent_today     = 0.00,
          ewallet_sent_this_week = 0.00,
          last_login_at          = NULL
        WHERE role IN ('admin', 'superadmin')";

    /**
     * Child-first delete steps (plan Appendix A).
     *
     * Every table here is a constant, so no input can become an
     * identifier. Foreign-key checks stay enabled: the order is the
     * safety mechanism, not a disabled check.
     */
    private const DELETE_GROUPS = [
        [
            'key'   => 'impersonation',
            'label' => 'S-Login sessions',
            'sql'   => [
                'DELETE FROM impersonation_log',
            ],
        ],
        [
            'key'   => 'orders',
            'label' => 'Shop orders, payments, invoices and fulfilment',
            'sql'   => [
                'DELETE FROM shop_payment_proofs',
                'DELETE FROM shop_invoice_items',
                'DELETE FROM shop_invoices',
                'DELETE FROM shop_shipments',
                'DELETE FROM shop_order_events',
                'DELETE FROM shop_order_items',
                'DELETE FROM shop_orders',
            ],
        ],
        [
            'key'   => 'carts',
            'label' => 'Carts and cart lines',
            'sql'   => [
                'DELETE FROM cart_items',
                'DELETE FROM carts',
            ],
        ],
        [
            'key'   => 'addresses',
            'label' => 'Address book',
            'sql'   => [
                'DELETE FROM addresses',
            ],
        ],
        [
            'key'   => 'wallet',
            'label' => 'Wallet ledger, transfers and admin top-ups',
            'sql'   => [
                'DELETE FROM ewallet_ledger',
                'DELETE FROM ewallet_transfers',
                'DELETE FROM ewallet_admin_topups',
            ],
        ],
        [
            'key'   => 'payouts',
            'label' => 'Payout requests',
            'sql'   => [
                'DELETE FROM payout_requests',
            ],
        ],
        [
            'key'   => 'commissions',
            'label' => 'Commissions and activations',
            'sql'   => [
                'DELETE FROM commissions',
                'DELETE FROM activations',
            ],
        ],
        [
            'key'   => 'codes',
            'label' => 'Registration codes',
            'sql'   => [
                'DELETE FROM reg_codes',
            ],
        ],
        [
            'key'   => 'sessions',
            'label' => 'Remember-me sessions (all users)',
            'sql'   => [
                'DELETE FROM remember_tokens',
            ],
        ],
    ];

    private readonly PDO $pdo;
    private readonly string $database;
    private readonly string $appRoot;

    /** @var array<string, mixed> */
    private array $options;

    /** @var Closure(int,array):void|null */
    private ?Closure $onStep;

    private ?string $targetDatabase = null;

    /** @var list<string>|null */
    private ?array $existingTablesCache = null;

    /** @var list<array<string, mixed>> */
    private array $steps = [];

    private int $stepIndex = 0;

    /** The step being executed, for error context. */
    private string $currentStep = '';

    /** @var list<string> */
    private array $warnings = [];

    /** @var array<string, mixed> */
    private array $state = [];

    /** Expectations captured by run(), read back by verify(). */
    private ?array $lastRun = null;

    /**
     * @param array<string, mixed> $options  plan 7.2 (see resolvedOptions())
     * @param callable(int,array):void|null $onStep progress hook / test seam
     */
    public function __construct(PDO $pdo, string $database, array $options = [], ?callable $onStep = null)
    {
        $this->pdo     = $pdo;
        $this->database = $database;
        $this->options = $options;
        $this->onStep  = $onStep === null ? null : Closure::fromCallable($onStep);

        $root          = dirname(__DIR__, 2);
        $resolved      = realpath($root);
        $this->appRoot = rtrim(str_replace('\\', '/', $resolved === false ? $root : $resolved), '/');
    }

    /* ==========================================================
     *  Preview and dry run (plan 7.13, IR-5-T1)
     * ========================================================== */

    /**
     * Everything a reset would act on, read-only.
     *
     * @return array{
     *   database: string, server_version: string,
     *   counts: array<string,int>, members: int,
     *   staff: list<array<string,mixed>>,
     *   packages: list<array<string,mixed>>,
     *   catalogue: array{products:int,packages:int,levels:int,contents:int},
     *   last_reset_at: ?string, last_reset_by: ?string,
     *   missing_tables: list<string>, warnings: list<string>,
     *   options: array<string,mixed>
     * }
     */
    public function preview(): array
    {
        $options = $this->resolvedOptions();
        $database = $this->targetDatabase();

        $tables  = $this->existingTables(true);
        $missing = array_values(array_diff(self::ALL_TABLES, $tables));
        $counts  = $this->snapshotCounts(true);

        $staff    = in_array('users', $tables, true) ? $this->staffRows() : [];
        $packages = $this->packageRows();
        $active   = array_values(array_filter(
            $packages,
            static fn (array $package): bool => (string) $package['status'] === 'active'
        ));

        [$lastResetAt, $lastResetBy] = $this->lastResetSettings();
        $catalogue = $this->catalogueCounts();

        $warnings = [];

        if ($missing !== []) {
            $warnings[] = count($missing) . ' required table(s) are missing: '
                . implode(', ', array_slice($missing, 0, 8))
                . (count($missing) > 8 ? '…' : '');
        }

        if ($staff === []) {
            $warnings[] = 'No admin or superadmin account exists; a reset would be refused.';
        }

        if ($active === [] && $options['code_qty'] > 0) {
            $warnings[] = 'No active package exists; no registration codes would be generated.';
        }

        if ($options['env'] === 'production') {
            $warnings[] = 'APP_ENV is production: the CLI needs --allow-production and the web console needs '
                . 'DB_RESET_ENABLED=true.';
        }

        if ($options['demo']) {
            try {
                $availability = (new DemoDataService($this->appRoot))
                    ->available($this->pdo, false, $options['reset_catalogue'], $options['env']);

                if (!$availability['ok']) {
                    $warnings[] = 'The demo fixtures cannot be installed: ' . $availability['reason'];
                }
            } catch (Throwable $e) {
                error_log('[ecowealth] reset preview demo check failed: ' . $e->getMessage());
                $warnings[] = 'The demo fixtures could not be checked; see the PHP error log.';
            }
        }

        if (!$options['keep_uploads']) {
            $proofs = $this->uploadsRoot() . '/proofs';

            if (is_dir($proofs) && !is_writable($proofs)) {
                $warnings[] = 'The proof upload folder is not writable; proof files may stay on disk.';
            }
        }

        return [
            'database'       => $database,
            'server_version' => $this->serverVersion(),
            'counts'         => $counts,
            'members'        => (int) $counts['members'],
            'staff'          => $staff,
            'packages'       => $packages,
            'catalogue'      => [
                'products' => $catalogue['products'],
                'packages' => $catalogue['packages'],
                'levels'   => $catalogue['levels'],
                'contents' => $catalogue['contents'],
            ],
            'last_reset_at'  => $lastResetAt,
            'last_reset_by'  => $lastResetBy,
            'missing_tables' => $missing,
            'warnings'       => $warnings,
            'options'        => $this->publicOptions($options),
        ];
    }

    /**
     * Preview plus the statement plan; never starts a transaction.
     *
     * @return array<string, mixed>
     */
    public function dryRun(): array
    {
        $started = microtime(true);
        $options = $this->resolvedOptions();
        $preview = $this->preview();
        $plan    = $this->plan($options);

        $statements = 0;

        foreach ($plan as $step) {
            $statements += (int) $step['statements'];
        }

        return [
            'tool'           => 'db_reset',
            'version'        => self::REPORT_VERSION,
            'ok'             => $preview['missing_tables'] === [] && $preview['staff'] !== [],
            'mode'           => 'dry_run',
            'database'       => $preview['database'],
            'server_version' => $preview['server_version'],
            'started_at'     => date('c', (int) $started),
            'duration_ms'    => self::ms($started),
            'options'        => $this->publicOptions($options),
            'writes'         => 0,
            'plan'           => $plan,
            'statements'     => $statements,
            'counts'         => $preview['counts'],
            'members'        => $preview['members'],
            'staff'          => $preview['staff'],
            'staff_count'    => count($preview['staff']),
            'packages'       => $preview['packages'],
            'catalogue'      => $preview['catalogue'],
            'last_reset_at'  => $preview['last_reset_at'],
            'last_reset_by'  => $preview['last_reset_by'],
            'missing_tables' => $preview['missing_tables'],
            'warnings'       => $preview['warnings'],
        ];
    }

    /* ==========================================================
     *  Preflight
     * ========================================================== */

    /**
     * The two preconditions that must hold before anything is deleted.
     *
     * @return array{ok:bool, database:string, checks:list<array{
     *     key:string, label:string, ok:bool, detail:string, hint:string
     * }>, missing_tables:list<string>, staff_count:int}
     */
    public function preflight(): array
    {
        $database = $this->targetDatabase();
        $tables   = $this->existingTables(true);
        $missing  = array_values(array_diff(self::ALL_TABLES, $tables));
        $staff    = in_array('users', $tables, true) ? $this->staffRows() : [];

        $tableCheck = self::check(
            'tables',
            'Required tables',
            $missing === [],
            $missing === []
                ? count(self::ALL_TABLES) . ' of ' . count(self::ALL_TABLES) . ' tables present'
                : count($missing) . ' table(s) missing: ' . implode(', ', array_slice($missing, 0, 8)),
            $missing === []
                ? ''
                : 'The schema is incomplete. Install it from database/install.sql or run the missing migrations; '
                    . 'a reset never repairs the schema.'
        );

        $staffCheck = self::check(
            'staff',
            'Staff accounts',
            $staff !== [],
            $staff === []
                ? 'no admin or superadmin account'
                : count($staff) . ' staff row(s): ' . implode(', ', array_column($staff, 'username')),
            $staff === []
                ? 'A reset with no staff row would leave no way to sign in. Restore an admin account first '
                    . '(never hand-edit a password hash into production).'
                : ''
        );

        return [
            'ok'             => $tableCheck['ok'] && $staffCheck['ok'],
            'database'       => $database,
            'checks'         => [$tableCheck, $staffCheck],
            'missing_tables' => $missing,
            'staff_count'    => count($staff),
        ];
    }

    /* ==========================================================
     *  The reset (plan 5.4, 7.3, Appendix A)
     * ========================================================== */

    /**
     * Execute the reset and return the §7.11 report.
     *
     * @return array<string, mixed>
     * @throws DatabaseToolException on a refusal, a rolled-back failure
     */
    public function run(): array
    {
        $started = microtime(true);

        $this->steps      = [];
        $this->stepIndex  = 0;
        $this->warnings   = [];
        $this->state      = [];
        $this->currentStep = '';

        $options  = $this->resolvedOptions();
        $database = $this->targetDatabase();

        $this->assertRunnable($options, $database);

        if ($this->pdo->inTransaction()) {
            throw DatabaseToolException::refused(
                'The reset needs a connection with no open transaction.',
                ['database' => $database],
                'Commit or roll back the caller\'s transaction first; a reset owns its whole transaction.'
            );
        }

        $lockName = self::lockName($database);
        $lockTime = microtime(true);

        if (!$this->advisoryLock($lockName, self::LOCK_TIMEOUT)) {
            throw DatabaseToolException::locked(
                'Another reset is already running for "' . $database . '".',
                ['database' => $database, 'lock' => $lockName],
                'Wait for that run to finish and try again. Nothing was changed.'
            );
        }

        $this->beginStep('lock', 'Advisory lock acquired');
        $this->recordStep('lock', 'Advisory lock acquired', 'ok', 0, self::ms($lockTime));

        try {
            /* ---- 0/1: preflight ---------------------------------- */
            $t = microtime(true);
            $this->beginStep('preflight', 'Preflight check');
            $preflight = $this->preflight();

            $this->recordStep(
                'preflight',
                'Preflight check',
                $preflight['ok'] ? 'ok' : 'fail',
                count($preflight['checks']),
                self::ms($t),
                $preflight['ok'] ? null : 'The schema is incomplete or has no staff account.'
            );

            if (!$preflight['ok']) {
                throw DatabaseToolException::refused(
                    'The reset was refused before anything was deleted: '
                        . ($preflight['missing_tables'] === []
                            ? 'no staff account exists.'
                            : 'required tables are missing.'),
                    [
                        'database'       => $database,
                        'missing_tables' => $preflight['missing_tables'],
                        'staff_count'    => $preflight['staff_count'],
                    ],
                    'Fix the schema with database/install.sql or the migrations, or restore a staff row. '
                        . 'Nothing was changed.'
                );
            }

            /* ---- Demo preconditions, before any delete ------------ */
            if ($options['demo']) {
                $availability = (new DemoDataService($this->appRoot))
                    ->available($this->pdo, false, $options['reset_catalogue'], $options['env']);

                if (!$availability['ok']) {
                    throw DatabaseToolException::refused(
                        'The demo fixtures cannot be installed: ' . $availability['reason'] . ' Nothing was deleted.',
                        ['database' => $database, 'demo' => true],
                        'Fix the environment or the catalogue, or run the reset without --demo. A demo request '
                            . 'never merges into an existing tree.'
                    );
                }
            }

            /* ---- Resolve the code package before any delete ------ */
            [$codePackage, $codeWarnings] = $this->resolveCodePackage($options);
            $this->warnings = array_merge($this->warnings, $codeWarnings);

            $staffRows    = $this->staffRows();
            $primaryStaff = self::primaryStaff($staffRows);

            /* ---- 2: snapshot the counts -------------------------- */
            $t = microtime(true);
            $this->beginStep('counts', 'Snapshot the row counts');

            $countsBefore     = $this->snapshotCounts();
            $auditRowsBefore  = (int) ($countsBefore['audit_log'] ?? 0);
            $resetAuditBefore = $this->countResetAuditRows();

            $this->state['reset_audit_before'] = $resetAuditBefore;
            $this->state['catalogue_before']   = [
                'products' => (int) ($countsBefore['products'] ?? 0),
                'packages' => (int) ($countsBefore['packages'] ?? 0),
                'levels'   => (int) ($countsBefore['package_levels'] ?? 0),
                'contents' => (int) ($countsBefore['package_products'] ?? 0),
            ];

            $this->recordStep('counts', 'Snapshot the row counts', 'ok', 0, self::ms($t));

            /* ---- 3..20: the transactional block ------------------ */
            $this->pdo->beginTransaction();
            try {
                $this->runTransactional($options, $database, $codePackage, $primaryStaff, $staffRows, $countsBefore, $started);
                $this->pdo->commit();
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    try {
                        $this->pdo->rollBack();
                    } catch (Throwable $rollback) {
                        error_log('[ecowealth] reset rollback failed: ' . $rollback->getMessage());
                    }
                }

                if ($e instanceof PDOException) {
                    throw $this->sqlFailure($e, $this->currentStep, '');
                }

                if ($e instanceof DatabaseToolException) {
                    throw $e;
                }

                error_log('[ecowealth] reset failed at step "' . $this->currentStep . '": ' . $e->getMessage());

                throw DatabaseToolException::sql(
                    'The reset failed at step "' . $this->currentStep . '" and was rolled back.',
                    ['step' => $this->currentStep, 'rolled_back' => true, 'exception' => $e::class],
                    'Nothing was deleted: every statement of the reset runs in one transaction. '
                        . 'Read the PHP error log, fix the cause and run it again.'
                );
            }
        } catch (Throwable $e) {
            $this->releaseLock($lockName);

            throw $e;
        }

        /*
         * The advisory lock is held until after the post-commit steps and
         * the verification: a second reset must not start while this one
         * is still writing its code batch or reading its result.
         */
        try {
            /* ---- 21: AUTO_INCREMENT (DDL, after the commit) ---------- */
            $t = microtime(true);
            $this->beginStep('auto_increment', 'Reset AUTO_INCREMENT counters');

            $counters = ['reset' => 0, 'failed' => 0, 'tables' => []];

            try {
                $counters = $this->resetAutoIncrement();

                if ($counters['failed'] > 0) {
                    $this->warnings[] = $counters['failed'] . ' AUTO_INCREMENT counter(s) could not be reset: '
                        . implode(', ', $counters['tables']) . '. The data is reset; the counters are cosmetic.';
                }

                $this->recordStep(
                    'auto_increment',
                    'Reset AUTO_INCREMENT counters',
                    'ok',
                    $counters['reset'],
                    self::ms($t)
                );
            } catch (Throwable $e) {
                error_log('[ecowealth] reset AUTO_INCREMENT step failed: ' . $e->getMessage());
                $this->warnings[] = 'The AUTO_INCREMENT step could not run; the data is reset and only the '
                    . 'counters may be high.';
                $this->recordStep('auto_increment', 'Reset AUTO_INCREMENT counters', 'fail', 0, self::ms($t), 'See warnings.');
            }

            /* ---- uploads (after the rows are gone) -------------------- */
            $t = microtime(true);
            $this->beginStep('uploads', 'Delete orphaned upload files');

            $uploads = ['deleted' => 0, 'failed' => 0, 'directories' => []];

            if ($options['keep_uploads']) {
                $this->recordStep('uploads', 'Delete orphaned upload files', 'skip', 0, self::ms($t));
            } else {
                try {
                    $uploads = $this->cleanUploads($options);

                    if ($uploads['failed'] > 0) {
                        $this->warnings[] = $uploads['failed'] . ' upload file(s) could not be removed (permissions?); '
                            . 'the database rows are already gone.';
                    }

                    $this->recordStep('uploads', 'Delete orphaned upload files', 'ok', $uploads['deleted'], self::ms($t));
                } catch (Throwable $e) {
                    error_log('[ecowealth] reset upload cleanup failed: ' . $e->getMessage());
                    $this->warnings[] = 'The upload cleanup could not run; proof files may stay on disk.';
                    $this->recordStep('uploads', 'Delete orphaned upload files', 'fail', 0, self::ms($t), 'See warnings.');
                }
            }

            /* ---- 22: demo tree (after the commit, before the codes) --- */
            $t    = microtime(true);
            $demo = [
                'installed'   => false,
                'members'     => 0,
                'codes'       => 0,
                'ledger_rows' => 0,
                'wallets'     => [],
                'credentials' => [],
            ];

            if (!$options['demo']) {
                $this->beginStep('demo', 'Install the demo tree');
                $this->recordStep('demo', 'Install the demo tree', 'skip', 0, self::ms($t));
            } else {
                $this->beginStep('demo', 'Install the demo tree');

                try {
                    $demoService  = new DemoDataService($this->appRoot);
                    $availability = $demoService->available(
                        $this->pdo,
                        false,
                        $options['reset_catalogue'],
                        $options['env']
                    );

                    /* The preflight above trusted the catalogue reset to
                     * provide the packages. If a customized seed set did
                     * not, this re-check refuses before a single demo row
                     * is written: the reset stays committed and the demo
                     * step reports a warning (plan 7.5). */
                    if (!$availability['ok']) {
                        throw DatabaseToolException::refused(
                            $availability['reason'],
                            ['database' => $database, 'demo' => true],
                            'The database is reset; no demo row was written. Import the standard catalogue '
                                . '(003/004) or run the reset again with --reset-catalogue.'
                        );
                    }

                    $demo              = $demoService->install($this->pdo);
                    $demo['installed'] = true;

                    $this->recordStep(
                        'demo',
                        'Install the demo tree',
                        'ok',
                        $demo['members'] + $demo['codes'] + $demo['ledger_rows'],
                        self::ms($t)
                    );
                } catch (Throwable $e) {
                    error_log('[ecowealth] reset demo step failed: ' . $e->getMessage());

                    $this->warnings[] = 'The reset succeeded, but the demo fixtures could not be installed: '
                        . $e->getMessage() . ' No demo row was left behind.';

                    $this->recordStep('demo', 'Install the demo tree', 'fail', 0, self::ms($t), 'See warnings.');

                    $demo = [
                        'installed'   => false,
                        'members'     => 0,
                        'codes'       => 0,
                        'ledger_rows' => 0,
                        'wallets'     => [],
                        'credentials' => [],
                    ];
                }
            }

            /* ---- 23: registration codes (own transaction) ------------ */
            $t = microtime(true);

            /*
             * Resolve the package again after the commit: a catalogue reset
             * deletes and re-seeds the packages, so the row validated before
             * the delete may no longer exist. The natural key (slug) is what
             * survives the re-seed (D-IR-15: ids continue, references follow
             * the new rows).
             */
            [$codeTarget, $targetWarnings] = $this->resolveCodePackageAfterReset($options, $codePackage);
            $this->warnings = array_merge($this->warnings, $targetWarnings);

            $codes = [
                'generated'  => 0,
                'expected'   => $options['code_qty'],
                'package'    => $codeTarget === null ? null : (string) $codeTarget['name'],
                'package_id' => $codeTarget === null ? null : (int) $codeTarget['id'],
                'list'       => [],
            ];

            $this->beginStep('codes_generate', 'Generate registration codes');

            if ($options['code_qty'] === 0 || $codeTarget === null) {
                $this->recordStep('codes_generate', 'Generate registration codes', 'skip', 0, self::ms($t));
            } else {
                try {
                    $batch = $this->generateCodes($options['code_qty'], $codeTarget, $primaryStaff, $options);
                    $codes['generated'] = count($batch);
                    $codes['list']      = $batch;
                    $this->recordStep('codes_generate', 'Generate registration codes', 'ok', count($batch), self::ms($t));
                } catch (Throwable $e) {
                    error_log('[ecowealth] reset code generation failed: ' . $e->getMessage());
                    $this->warnings[] = 'The reset succeeded, but the registration codes could not be generated. '
                        . 'Generate them from /admin/codes, or run the reset again.';
                    $this->recordStep('codes_generate', 'Generate registration codes', 'fail', 0, self::ms($t), 'See warnings.');
                }
            }

            /* ---- 24: verification ------------------------------------ */
            $demoInstalled = (bool) $demo['installed'];

            $this->lastRun = [
                'members_expected'   => $demoInstalled ? DemoDataService::EXPECTED_MEMBERS : 0,
                'codes_expected'     => ($codeTarget === null ? 0 : $options['code_qty'])
                    + ($demoInstalled ? DemoDataService::EXPECTED_CODES : 0),
                'catalogue_reset'    => $options['reset_catalogue'],
                'catalogue_before'   => $this->state['catalogue_before'] ?? null,
                'reset_audit_before' => $this->state['reset_audit_before'] ?? null,
                'audit_cleared'      => $options['clear_audit'],
                'demo'               => $demoInstalled,
            ];

            $t = microtime(true);
            $this->beginStep('verify', 'Verify the reset');
            $verification = $this->verify();
            $this->recordStep(
                'verify',
                'Verify the reset',
                $verification['ok'] ? 'ok' : 'fail',
                count($verification['checks']),
                self::ms($t),
                $verification['ok'] ? null : 'See the failing checks below.'
            );

            $countsAfter = $this->snapshotCounts(true);

            /* ---- The report (plan 7.11 / Appendix D) ----------------- */
            return [
                'tool'           => 'db_reset',
                'version'        => self::REPORT_VERSION,
                'ok'             => $verification['ok'],
                'mode'           => 'reset',
                'database'       => $database,
                'server_version' => $this->serverVersion(),
                'started_at'     => date('c', (int) $started),
                'duration_ms'    => self::ms($started),
                'options'        => $this->publicOptions($options),
                'steps'          => $this->steps,
                'counts_before'  => $countsBefore,
                'counts_after'   => $countsAfter,
                'staff'          => [
                    'reset'     => (int) ($this->state['staff_reset'] ?? 0),
                    'usernames' => array_values(array_map(
                        static fn (array $row): string => (string) $row['username'],
                        $staffRows
                    )),
                ],
                'audit'          => [
                    'kept'        => !$options['clear_audit'],
                    'rows_before' => $auditRowsBefore,
                    'cleared'     => (bool) ($this->state['audit_cleared'] ?? false),
                    'rows_after'  => (int) ($countsAfter['audit_log'] ?? 0),
                ],
                'auto_increment' => [
                    'reset'  => (int) $counters['reset'],
                    'failed' => (int) $counters['failed'],
                    'tables' => $counters['tables'],
                ],
                'uploads'        => $uploads,
                'codes'          => $codes,
                'demo'           => $demo,
                'verification'   => ['ok' => $verification['ok'], 'checks' => $verification['checks']],
                'warnings'       => array_values(array_unique($this->warnings)),
            ];
        } finally {
            $this->releaseLock($lockName);
        }
    }

    /* ==========================================================
     *  The transaction body (Appendix A)
     * ========================================================== */

    /**
     * Every DELETE/UPDATE/INSERT between BEGIN and COMMIT.
     *
     * A throw here is caught by run(), which rolls the whole block back:
     * no partial reset is ever committed.
     *
     * @param array<string,mixed> $options
     * @param array<string,mixed>|null $codePackage
     * @param array<string,mixed>|null $primaryStaff
     * @param list<array<string,mixed>> $staffRows
     * @param array<string,int> $countsBefore
     */
    private function runTransactional(
        array $options,
        string $database,
        ?array $codePackage,
        ?array $primaryStaff,
        array $staffRows,
        array $countsBefore,
        float $started
    ): void {
        /* 4–12: every child table before its parent. */
        foreach (self::DELETE_GROUPS as $group) {
            $this->beginStep($group['key'], $group['label']);
            $t = microtime(true);

            $total = 0;

            foreach ($group['sql'] as $sql) {
                $total += $this->exec($sql);
            }

            $this->recordStep($group['key'], $group['label'], 'ok', $total, self::ms($t), null, true);
        }

        /* 13: members only — staff rows survive. */
        $this->beginStep('members', 'Member accounts');
        $t = microtime(true);

        $members = $this->exec("DELETE FROM users WHERE role = 'member'");
        $this->recordStep('members', 'Member accounts', 'ok', $members, self::ms($t), null, true);

        /* 14: staff back to the install state. */
        $this->beginStep('staff', 'Reset the staff accounts');
        $t = microtime(true);

        $this->exec(self::STAFF_RESET_SQL);
        $staffAfter = $this->staffStateRows();

        foreach ($staffAfter as $staff) {
            if ($staff['package_id'] !== null
                || $staff['sponsor_id'] !== null
                || self::decimal($staff['ewallet_balance']) !== '0.00'
                || self::decimal($staff['withdrawable_balance']) !== '0.00'
                || (string) $staff['member_status'] !== 'active'
                || (int) $staff['is_active'] !== 1) {
                throw DatabaseToolException::verify(
                    'The staff reset did not reach the install state for @' . (string) $staff['username'] . '.',
                    ['username' => (string) $staff['username']],
                    'The reset was rolled back. Re-run it; if this repeats, inspect the users table definition.'
                );
            }
        }

        $this->state['staff_reset'] = count($staffAfter);
        $this->recordStep('staff', 'Reset the staff accounts', 'ok', count($staffAfter), self::ms($t));

        /* 15: optional catalogue reset. */
        if ($options['reset_catalogue']) {
            $this->resetCatalogue();
        } else {
            $this->beginStep('catalogue', 'Keep the existing catalogue');
            $this->recordStep('catalogue', 'Keep the existing catalogue', 'skip', 0, 0);
        }

        /* 16: optional audit clear. */
        if ($options['clear_audit']) {
            $this->beginStep('audit_clear', 'Clear the audit log');
            $t = microtime(true);

            $cleared = $this->exec('DELETE FROM audit_log');
            $this->state['audit_cleared'] = true;
            $this->recordStep('audit_clear', 'Clear the audit log', 'ok', $cleared, self::ms($t), null, true);
        } else {
            $this->beginStep('audit_clear', 'Keep the audit log');
            $this->recordStep('audit_clear', 'Keep the audit log', 'skip', 0, 0);
        }

        /* 17: live surfaces move forward, inside the transaction. */
        $this->beginStep('live', 'Bump live revisions');
        $t = microtime(true);

        $bumped = $this->exec('UPDATE live_revisions SET revision = revision + 1');

        $placeholders = [];
        $surfaceArgs  = [];

        foreach (self::STANDARD_SURFACES as $index => $surface) {
            $placeholders[] = '(?, 1)';
            $surfaceArgs[]  = $surface;
        }

        $inserted = $this->execPrepared(
            'INSERT IGNORE INTO live_revisions (surface, revision) VALUES ' . implode(', ', $placeholders),
            $surfaceArgs,
            'live'
        );

        $this->recordStep('live', 'Bump live revisions', 'ok', $bumped + $inserted, self::ms($t));

        /* 18: reset metadata (two placeholders per key — no VALUES()). */
        $this->beginStep('settings', 'Write the reset metadata');
        $t = microtime(true);

        $this->upsertSetting('last_reset_at', date('Y-m-d H:i:s'));
        $this->upsertSetting('last_reset_by', (string) $options['last_reset_by']);
        $this->recordStep('settings', 'Write the reset metadata', 'ok', 2, self::ms($t));

        /* 19: the audit row, last, so its meta can carry near-final counts. */
        $this->beginStep('audit', 'Append the reset audit row');
        $t = microtime(true);

        $countsForAudit = $this->snapshotCounts(true);
        $countsForAudit['audit_log'] = (int) ($countsForAudit['audit_log'] ?? 0) + 1;

        $meta = [
            'tool'           => $options['tool'],
            'database'       => $database,
            'options'        => $this->publicOptions($options),
            'counts_before'  => $countsBefore,
            'counts_after'   => $countsForAudit,
            'catalogue_reset' => (bool) $options['reset_catalogue'],
            'codes'          => (int) $options['code_qty'],
            'demo'           => (bool) $options['demo'],
            'audit_cleared'  => (bool) $options['clear_audit'],
            'duration_ms'    => self::ms($started),
        ];

        $this->insertAudit('system.db_reset', 'database', null, $meta, $options);
        $this->recordStep('audit', 'Append the reset audit row', 'ok', 1, self::ms($t));
    }

    /**
     * Delete and re-seed the starter catalogue inside the caller's transaction.
     *
     * The seeds are the real `003_products.sql` / `004_packages.sql`
     * files, not a second hard-coded copy of their values (D-IR-09).
     */
    private function resetCatalogue(): void
    {
        $this->beginStep('catalogue', 'Reset the starter catalogue');
        $t = microtime(true);

        $deleted = 0;

        foreach ([
            'DELETE FROM package_products',
            'DELETE FROM package_levels',
            'DELETE FROM packages',
            'DELETE FROM products',
        ] as $sql) {
            $deleted += $this->exec($sql);
        }

        $statements = 0;

        foreach ([
            $this->appRoot . '/database/seeds/003_products.sql',
            $this->appRoot . '/database/seeds/004_packages.sql',
        ] as $path) {
            $script = SqlScript::fromFile($path);

            try {
                $run = $script->run($this->pdo);
            } catch (DatabaseToolException $e) {
                throw DatabaseToolException::sql(
                    'The catalogue re-seed failed: ' . $e->getMessage(),
                    [
                        'step'        => 'catalogue',
                        'source'      => $e->context['source'] ?? $path,
                        'statement'   => $e->context['statement'] ?? null,
                        'line'        => $e->context['line'] ?? null,
                        'driver_code' => $e->context['driver_code'] ?? 0,
                    ],
                    'The catalogue delete and the re-seed share the reset transaction, so the whole reset was '
                        . 'rolled back. Fix the seed file and run it again.'
                );
            }

            $statements += $run['statements'];
        }

        $catalogue = $this->catalogueCounts();

        foreach (['package-a', 'package-b'] as $slug) {
            if (!in_array($slug, $catalogue['package_slugs'], true)) {
                $this->warnings[] = 'The catalogue was re-seeded, but the default package "' . $slug
                    . '" is missing (the seed files were customized).';
            }
        }

        $this->recordStep(
            'catalogue',
            'Reset the starter catalogue',
            'ok',
            $statements,
            self::ms($t)
        );
    }

    /* ==========================================================
     *  Catalogue read helper
     * ========================================================== */

    /**
     * @return array{products:int,packages:int,levels:int,contents:int,package_slugs:list<string>}
     */
    private function catalogueCounts(): array
    {
        $tables = $this->existingTables(true);
        $counts = ['products' => 0, 'packages' => 0, 'levels' => 0, 'contents' => 0, 'package_slugs' => []];

        $map = [
            'products'         => 'products',
            'packages'         => 'packages',
            'package_levels'   => 'levels',
            'package_products' => 'contents',
        ];

        foreach ($map as $table => $key) {
            if (in_array($table, $tables, true)) {
                $counts[$key] = $this->countRows($table);
            }
        }

        if (in_array('packages', $tables, true)) {
            $counts['package_slugs'] = array_map(
                static fn (array $row): string => (string) $row['slug'],
                $this->rows('SELECT slug FROM packages ORDER BY slug')
            );
        }

        return $counts;
    }

    /* ==========================================================
     *  Post-commit steps
     * ========================================================== */

    /**
     * Reset the counter of every listed table that is empty (plan 7.10).
     *
     * DDL implicitly commits in MySQL, which is why this runs after the
     * transaction (D-IR-14) and why each table is wrapped individually.
     *
     * @return array{reset:int, failed:int, tables:list<string>}
     */
    private function resetAutoIncrement(): array
    {
        $result = ['reset' => 0, 'failed' => 0, 'tables' => []];
        $tables = $this->existingTables(true);

        foreach (self::AUTO_INCREMENT_TABLES as $table) {
            if (!in_array($table, $tables, true)) {
                continue;
            }

            if ($this->countRows($table) > 0) {
                continue;
            }

            try {
                $this->pdo->exec('ALTER TABLE `' . $table . '` AUTO_INCREMENT = 1');
                $result['reset']++;
            } catch (PDOException $e) {
                error_log('[ecowealth] reset AUTO_INCREMENT ' . $table . ' failed: ' . $e->getMessage());
                $result['failed']++;
                $result['tables'][] = $table;
            }
        }

        return $result;
    }

    /**
     * Delete orphaned upload files (plan 7.7).
     *
     * Only files directly inside the folder are touched: no directory, no
     * symlink, and never `.gitkeep` / `.htaccess`.
     *
     * @param array<string,mixed> $options
     * @return array{deleted:int, failed:int, directories:list<string>}
     */
    private function cleanUploads(array $options): array
    {
        $root        = $this->uploadsRoot();
        $directories = ['proofs'];

        if ($options['reset_catalogue']) {
            $directories[] = 'products';
            $directories[] = 'packages';
        }

        $result = ['deleted' => 0, 'failed' => 0, 'directories' => []];

        foreach ($directories as $directory) {
            $path = $root . '/' . $directory;

            if (!is_dir($path)) {
                continue;
            }

            $result['directories'][] = $directory;

            try {
                $iterator = new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS);
            } catch (Throwable $e) {
                error_log('[ecowealth] reset upload folder unreadable: ' . $path . ': ' . $e->getMessage());
                $result['failed']++;

                continue;
            }

            foreach ($iterator as $file) {
                $name = $file->getFilename();

                if (in_array($name, self::UPLOAD_KEEP, true)) {
                    continue;
                }

                /* is_file() follows a symlink; deleting through one could
                 * remove a file outside the upload folder. */
                if (is_link($file->getPathname()) || !$file->isFile()) {
                    continue;
                }

                if (@unlink($file->getPathname())) {
                    $result['deleted']++;
                } else {
                    error_log('[ecowealth] reset could not delete upload file: ' . $file->getPathname());
                    $result['failed']++;
                }
            }
        }

        return $result;
    }

    /**
     * Insert the requested batch of unused registration codes (plan 7.6).
     *
     * Its own transaction: a failed batch does not un-reset the database,
     * and it cannot split the main reset's boundaries. Only the pure
     * `CodeService::randomCode()` helper is reused — `generate()` writes
     * through the global db() and calls Live, which would escape the
     * injected target.
     *
     * @param array<string,mixed> $package
     * @param array<string,mixed>|null $actor
     * @param array<string,mixed> $options
     * @return list<string>
     */
    private function generateCodes(int $quantity, array $package, ?array $actor, array $options): array
    {
        $createdBy = $actor === null ? 0 : (int) $actor['id'];

        if ($createdBy <= 0) {
            throw DatabaseToolException::refused(
                'No staff account can own the generated registration codes.',
                ['database' => $this->database],
                'A registration code must name its creator; restore an admin row first.'
            );
        }

        $price = number_format((float) $package['price'], 2, '.', '');
        $pdo   = $this->pdo;

        $pdo->beginTransaction();
        $codes = [];

        try {
            $check = $pdo->prepare('SELECT COUNT(*) FROM reg_codes WHERE code = ?');
            $insert = $pdo->prepare(
                "INSERT INTO reg_codes (code, package_id, price, code_type, status, created_by, expires_at)
                 VALUES (?, ?, ?, 'registration', 'unused', ?, NULL)"
            );

            for ($i = 0; $i < $quantity; $i++) {
                $code = null;

                for ($attempt = 0; $attempt < self::CODE_ATTEMPTS; $attempt++) {
                    $candidate = CodeService::randomCode();
                    $check->execute([$candidate]);

                    if ((int) $check->fetchColumn() === 0) {
                        $code = $candidate;
                        break;
                    }
                }

                if ($code === null) {
                    throw DatabaseToolException::verify(
                        'Could not generate a unique registration code after ' . self::CODE_ATTEMPTS . ' attempts.',
                        ['quantity' => $quantity, 'generated' => count($codes)],
                        'The reg_codes table may be full for this format; generate the codes from /admin/codes instead.'
                    );
                }

                $insert->execute([$code, (int) $package['id'], $price, $createdBy]);
                $codes[] = $code;
            }

            $this->insertAudit('code.generate', 'database', null, [
                'tool'        => $options['tool'],
                'database'    => $this->targetDatabase(),
                'package_id'  => (int) $package['id'],
                'package'     => (string) $package['name'],
                'price'       => $price,
                'count'       => count($codes),
                'first_code'  => $codes[0] ?? null,
                'last_code'   => $codes[count($codes) - 1] ?? null,
                'source'      => 'db_reset',
            ], $options);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } catch (Throwable $rollback) {
                    error_log('[ecowealth] code batch rollback failed: ' . $rollback->getMessage());
                }
            }

            throw $e;
        }

        return $codes;
    }

    /* ==========================================================
     *  Verification (plan 7.11, IR-5-T9)
     * ========================================================== */

    /**
     * Prove the database is in the post-reset state.
     *
     * The four finance services are constructed on the injected PDO, so
     * the exact production reconciliation runs against the target.
     * `run()` captures the expectations below; a caller may override any
     * of them explicitly.
     *
     * @param array<string,mixed> $expectations
     * @return array{ok:bool, database:string, checks:list<array{
     *     key:string, label:string, ok:bool, detail:string
     * }>}
     */
    public function verify(array $expectations = []): array
    {
        $database = $this->targetDatabase();
        $tables   = $this->existingTables(true);

        $expect = array_replace([
            'members_expected'   => 0,
            'codes_expected'     => 0,
            'catalogue_reset'    => false,
            'catalogue_before'   => null,
            'reset_audit_before' => null,
            'audit_cleared'      => false,
            'demo'               => false,
        ], $this->lastRun ?? [], $expectations);

        $demo  = !empty($expect['demo']);
        $checks = [];

        /* ---- Members ---------------------------------------------- */
        $members = in_array('users', $tables, true)
            ? (int) $this->scalar("SELECT COUNT(*) FROM users WHERE role = 'member'")
            : 0;
        $membersExpected = (int) $expect['members_expected'];

        $checks[] = self::check(
            'members',
            'Member accounts',
            $members === $membersExpected,
            $members . ' member row(s) (expected ' . $membersExpected . ')',
            'Members are deleted by a reset; a remaining row means the delete order or a fixture is wrong.'
        );

        /* ---- Every transactional table ----------------------------- */
        $zeroTables = self::ZERO_TABLES;

        if ($demo) {
            $zeroTables = array_values(array_diff($zeroTables, ['ewallet_ledger']));
        }

        $remaining = [];
        $total     = $members;

        foreach ($zeroTables as $table) {
            if (!in_array($table, $tables, true)) {
                continue;
            }

            $count = $this->countRows($table);

            if ($count > 0) {
                $remaining[$table] = $count;
            }

            $total += $count;
        }

        $checks[] = self::check(
            'zero_transactional',
            'Business rows',
            $remaining === [],
            $remaining === []
                ? $total . ' rows across ' . (count($zeroTables) + 1) . ' tables (all zero)'
                : 'rows remain: ' . implode(', ', array_map(
                    static fn (string $table, int $count): string => $table . '=' . $count,
                    array_keys($remaining),
                    array_values($remaining)
                )),
            'Every child table must be deleted before its parent, and a reset must leave no business rows. '
                . 'Foreign-key checks stay on precisely so a bad order fails loudly.'
        );

        /* ---- Staff ------------------------------------------------ */
        $staff = in_array('users', $tables, true) ? $this->staffStateRows() : [];
        $staffOk = $staff !== [];

        foreach ($staff as $row) {
            if ($row['package_id'] !== null
                || $row['sponsor_id'] !== null
                || self::decimal($row['ewallet_balance']) !== '0.00'
                || self::decimal($row['withdrawable_balance']) !== '0.00'
                || (string) $row['member_status'] !== 'active'
                || (int) $row['is_active'] !== 1) {
                $staffOk = false;
                break;
            }
        }

        $checks[] = self::check(
            'staff',
            'Staff accounts',
            $staffOk,
            $staff === []
                ? 'no staff account'
                : count($staff) . ' staff, balances zero, package/sponsor null, active',
            'A reset keeps both staff identities and passwords; it only returns their counters and membership '
                . 'fields to the install defaults.'
        );

        /* ---- Catalogue -------------------------------------------- */
        $catalogue = $this->catalogueCounts();
        $catalogueDetail = $catalogue['products'] . ' products, ' . $catalogue['packages'] . ' packages, '
            . $catalogue['levels'] . ' levels, ' . $catalogue['contents'] . ' contents';

        if (!empty($expect['catalogue_reset'])) {
            $catalogueOk = $catalogue['products'] > 0 && $catalogue['packages'] > 0;
            $catalogueDetail .= ' (re-seeded from 003/004)';
        } elseif (is_array($expect['catalogue_before'])) {
            $before = $expect['catalogue_before'];
            $catalogueOk = $catalogue['products'] === (int) ($before['products'] ?? -1)
                && $catalogue['packages'] === (int) ($before['packages'] ?? -1)
                && $catalogue['levels'] === (int) ($before['levels'] ?? -1)
                && $catalogue['contents'] === (int) ($before['contents'] ?? -1);
            $catalogueDetail .= ' (kept: unchanged from the snapshot)';
        } else {
            $catalogueOk = true;
            $catalogueDetail .= ' (kept)';
        }

        $checks[] = self::check(
            'catalogue',
            'Catalogue',
            $catalogueOk,
            $catalogueDetail,
            'The default reset keeps the catalogue. A catalogue reset re-runs the real seed files, so the result '
                . 'matches a fresh install by natural key (slug/sku), not by id.'
        );

        /* ---- Registration codes ----------------------------------- */
        $codes = in_array('reg_codes', $tables, true) ? $this->countRows('reg_codes') : 0;
        $codesExpected = (int) $expect['codes_expected'];
        $codesOk = $codes === $codesExpected;
        $codesDetail = $codes . ' code row(s) (expected ' . $codesExpected . ')';

        if ($codesOk && $codesExpected > 0 && !$demo) {
            $bad = (int) $this->scalar(
                "SELECT COUNT(*) FROM reg_codes WHERE status <> 'unused' OR package_id IS NULL"
            );

            if ($bad > 0) {
                $codesOk = false;
                $codesDetail .= '; ' . $bad . ' row(s) are not unused/package-bound';
            }
        }

        $checks[] = self::check(
            'codes',
            'Registration codes',
            $codesOk,
            $codesDetail,
            'Codes are sellable inventory: a reset only creates the count the operator asked for, all unused '
                . 'and bound to a package.'
        );

        /* ---- Demo fixtures (plan IR-7-T1) ------------------------- */
        if ($demo) {
            $demoOk     = false;
            $demoDetail = 'the demo verification could not run';

            try {
                $demoVerify = (new DemoDataService($this->appRoot))->verify($this->pdo);
                $demoOk     = $demoVerify['ok'];
                $demoDetail = implode('; ', array_map(
                    static fn (array $check): string => (string) $check['detail'],
                    $demoVerify['checks']
                ));
            } catch (Throwable $e) {
                error_log('[ecowealth] reset demo verification failed: ' . $e->getMessage());
            }

            $checks[] = self::check(
                'demo',
                'Demo fixtures',
                $demoOk,
                $demoDetail,
                'The demo tree must hold five members (juan, maria, peter, sarah, david), three demo codes, '
                    . 'three ledger rows and the funded wallets; david stays pending.'
            );
        }

        /* ---- Audit ------------------------------------------------ */
        $auditRows  = in_array('audit_log', $tables, true) ? $this->countRows('audit_log') : 0;
        $resetRows  = $this->countResetAuditRows();

        if (!empty($expect['audit_cleared'])) {
            /* The reset row must be the only *pre-existing* entry. The
             * post-commit code batch may append its own `code.generate`
             * row when codes were requested, so that one action is
             * allowed and anything else is a failure. */
            $otherActions = (int) $this->scalar(
                "SELECT COUNT(*) FROM audit_log
                  WHERE action <> 'system.db_reset' AND action <> 'code.generate'"
            );
            $codeBatches = (int) $this->scalar(
                "SELECT COUNT(*) FROM audit_log WHERE action = 'code.generate'"
            );

            $auditOk = $resetRows === 1 && $otherActions === 0;
            $auditDetail = $auditRows . ' audit row(s): 1 system.db_reset'
                . ($codeBatches > 0 ? ' + ' . $codeBatches . ' code.generate' : '')
                . ' (the log was cleared first)';
        } elseif ($expect['reset_audit_before'] !== null) {
            $expectedResetRows = (int) $expect['reset_audit_before'] + 1;
            $auditOk = $resetRows === $expectedResetRows && $auditRows >= $expectedResetRows;
            $auditDetail = $resetRows . ' system.db_reset row(s) (expected ' . $expectedResetRows . ')';
        } else {
            $auditOk = $resetRows >= 1;
            $auditDetail = $resetRows . ' system.db_reset row(s)';
        }

        $checks[] = self::check(
            'audit',
            'Audit trail',
            $auditOk,
            $auditDetail,
            'The reset records itself. A kept log grows by exactly one system.db_reset row; a cleared log '
                . 'starts with it.'
        );

        /* ---- The four finance reconciliations --------------------- */
        $services = [
            'wallets'     => ['label' => 'Wallet reconciliation', 'service' => 'WalletService'],
            'commissions' => ['label' => 'Commission reconciliation', 'service' => 'CommissionService'],
            'transfers'   => ['label' => 'Transfer reconciliation', 'service' => 'TransferService'],
            'payouts'     => ['label' => 'Payout reconciliation', 'service' => 'PayoutService'],
        ];

        foreach ($services as $key => $meta) {
            $ok     = false;
            $detail = '';

            try {
                $result = match ($key) {
                    'wallets'     => (new WalletService($this->pdo))->reconcile(),
                    'commissions' => (new CommissionService($this->pdo))->reconcile(),
                    'transfers'   => (new TransferService($this->pdo))->reconcile(),
                    'payouts'     => (new PayoutService($this->pdo))->reconcile(),
                };

                $ok = (bool) ($result['ok'] ?? false);
                $detail = $ok
                    ? $meta['service'] . '::reconcile clean'
                    : $meta['service'] . '::reconcile reports ' . count($result['mismatches'] ?? []) . ' mismatch(es)';
            } catch (Throwable $e) {
                error_log('[ecowealth] reset verify ' . $key . ': ' . $e->getMessage());
                $detail = $meta['service'] . '::reconcile could not run; see the PHP error log';
            }

            $checks[] = self::check(
                $key,
                $meta['label'],
                $ok,
                $detail,
                'A reset that leaves money inconsistent is a failure. Run php bin/wallet_reconcile.php for the '
                    . 'detail and restore the pre-reset backup if real data was lost.'
            );
        }

        /* ---- Live revisions --------------------------------------- */
        $surfaces = $this->standardSurfaceCount();
        $checks[] = self::check(
            'live',
            'Live revisions',
            $surfaces === count(self::STANDARD_SURFACES),
            $surfaces . ' of ' . count(self::STANDARD_SURFACES) . ' standard surfaces (existing incremented, missing at 1)',
            'Every standard surface must exist so an open page sees a changed revision and revalidates.'
        );

        /* ---- AUTO_INCREMENT --------------------------------------- */
        $checked = 0;
        $wrong   = [];
        $withRows = [];

        foreach (self::AUTO_INCREMENT_TABLES as $table) {
            if (!in_array($table, $tables, true)) {
                continue;
            }

            if ($this->countRows($table) > 0) {
                $withRows[] = $table;

                continue;
            }

            $checked++;
            $next = $this->autoIncrementValue($table);

            if ($next !== null && $next !== 1) {
                $wrong[$table] = $next;
            }
        }

        $autoDetail = $checked . ' counter(s) at 1';

        if ($withRows !== []) {
            $autoDetail .= ' (' . count($withRows) . ' table(s) hold rows, so their counter continues)';
        }

        if ($wrong !== []) {
            $autoDetail .= '; wrong: ' . implode(', ', array_map(
                static fn (string $table, int $value): string => $table . '=' . $value,
                array_keys($wrong),
                array_values($wrong)
            ));
        }

        $checks[] = self::check(
            'auto_increment',
            'AUTO_INCREMENT counters',
            $wrong === [],
            $autoDetail,
            'Counters are cosmetic (D-IR-15): a failed ALTER is a warning, not a failed reset, and ids are '
                . 'never compared by tests.'
        );

        $ok = true;

        foreach ($checks as $check) {
            if (!$check['ok']) {
                $ok = false;
                break;
            }
        }

        return ['ok' => $ok, 'database' => $database, 'checks' => $checks];
    }

    /* ==========================================================
     *  Option handling
     * ========================================================== */

    /**
     * Normalise the caller's options (plan 7.2).
     *
     * Unknown keys are ignored so the web console may pass its whole
     * validated form; a value that cannot be normalised is a usage error.
     *
     * @return array<string, mixed>
     */
    private function resolvedOptions(): array
    {
        if (!empty($this->options['keep_catalogue']) && !empty($this->options['reset_catalogue'])) {
            throw DatabaseToolException::usage(
                '--keep-catalogue and --reset-catalogue are mutually exclusive.',
                [],
                'Pick one: the catalogue is kept by default; --reset-catalogue deletes and re-seeds it.'
            );
        }

        if (!empty($this->options['keep_audit']) && !empty($this->options['clear_audit'])) {
            throw DatabaseToolException::usage(
                '--keep-audit and --clear-audit are mutually exclusive.',
                [],
                'Pick one: the audit log is kept by default; --clear-audit deletes it before the reset row.'
            );
        }

        $env = strtolower(trim((string) ($this->options['env'] ?? (defined('APP_ENV') ? APP_ENV : 'production'))));

        if (!in_array($env, ['development', 'production', 'test', 'staging'], true)) {
            $env = 'production';
        }

        $tool = strtolower(trim((string) ($this->options['tool'] ?? 'cli')));

        if (!in_array($tool, ['cli', 'web'], true)) {
            throw DatabaseToolException::usage(
                'Unknown reset tool "' . $tool . '".',
                ['tool' => $tool],
                'Use "cli" or "web"; the value only labels the audit row.'
            );
        }

        return [
            'env'                 => $env,
            'reset_catalogue'     => (bool) ($this->options['reset_catalogue'] ?? false),
            'keep_catalogue'      => empty($this->options['reset_catalogue']),
            'clear_audit'         => (bool) ($this->options['clear_audit'] ?? false),
            'keep_audit'          => empty($this->options['clear_audit']),
            'keep_uploads'        => (bool) ($this->options['keep_uploads'] ?? false),
            'demo'                => (bool) ($this->options['demo'] ?? false),
            'allow_production'    => (bool) ($this->options['allow_production'] ?? false),
            'code_qty'            => self::integerOption($this->options['code_qty'] ?? 0, 'code quantity'),
            'code_package'        => self::nullableIntegerOption($this->options['code_package'] ?? null, 'code package'),
            'confirm'             => array_key_exists('confirm', $this->options) && $this->options['confirm'] !== null
                ? (string) $this->options['confirm']
                : null,
            'confirmation_phrase' => isset($this->options['confirmation_phrase'])
                ? (string) $this->options['confirmation_phrase']
                : null,
            'tool'                => $tool,
            'actor_id'            => isset($this->options['actor_id']) && (int) $this->options['actor_id'] > 0
                ? (int) $this->options['actor_id']
                : null,
            'actor_role'          => isset($this->options['actor_role']) && (string) $this->options['actor_role'] !== ''
                ? (string) $this->options['actor_role']
                : 'system',
            'actor_username'      => isset($this->options['actor_username'])
                ? (string) $this->options['actor_username']
                : null,
            'ip'                  => isset($this->options['ip']) && (string) $this->options['ip'] !== ''
                ? (string) $this->options['ip']
                : '127.0.0.1',
            'last_reset_by'       => isset($this->options['actor_username'])
                && (string) $this->options['actor_username'] !== ''
                ? (string) $this->options['actor_username']
                : $tool,
        ];
    }

    /**
     * Refuse before anything is deleted (plan 7.15).
     *
     * @param array<string,mixed> $options
     */
    private function assertRunnable(array $options, string $database): void
    {
        if ($options['code_qty'] < 0 || $options['code_qty'] > self::MAX_CODES) {
            throw DatabaseToolException::usage(
                'The registration-code quantity must be between 0 and ' . self::MAX_CODES . '.',
                ['code_qty' => $options['code_qty']],
                'Pass --codes=N with 0 <= N <= ' . self::MAX_CODES . '.'
            );
        }

        if ($options['code_package'] !== null && $options['code_package'] <= 0) {
            throw DatabaseToolException::usage(
                'The code package must be a positive package id.',
                ['code_package' => $options['code_package']],
                'Pass --code-package=<id> from /admin/packages, or omit it to use the first active package.'
            );
        }

        if ($options['env'] === 'production' && !$options['allow_production']) {
            throw DatabaseToolException::refused(
                'The target runs with APP_ENV=production and the reset needs --allow-production.',
                ['database' => $database, 'env' => $options['env']],
                'A reset is a destructive maintenance operation. Confirm the change window and pass '
                    . '--allow-production (production also requires the typed database name).'
            );
        }

        $phrase = $options['confirmation_phrase'] ?? $database;

        if ($options['confirm'] === null || $options['confirm'] === '') {
            throw DatabaseToolException::refused(
                'The reset needs a typed confirmation; nothing was deleted.',
                ['database' => $database],
                'Type the database name at the prompt, or pass --confirm=' . $database . '.'
            );
        }

        if (!hash_equals($phrase, $options['confirm'])) {
            throw DatabaseToolException::refused(
                'The typed confirmation does not match "' . $phrase . '"; nothing was deleted.',
                ['database' => $database],
                'Type the phrase exactly (the database name on the CLI, "RESET" in the development web console).'
            );
        }
    }

    /**
     * The package the codes are bound to, resolved before any delete.
     *
     * @param array<string,mixed> $options
     * @return array{0: ?array<string,mixed>, 1: list<string>}
     */
    private function resolveCodePackage(array $options): array
    {
        $warnings = [];
        $tables   = $this->existingTables(true);

        if (!in_array('packages', $tables, true)) {
            return [null, $warnings];
        }

        if ($options['code_package'] !== null) {
            $row = $this->row(
                'SELECT id, name, slug, price, status FROM packages WHERE id = ? LIMIT 1',
                [$options['code_package']]
            );

            if ($row === false) {
                throw DatabaseToolException::usage(
                    'The code package #' . $options['code_package'] . ' does not exist.',
                    ['code_package' => $options['code_package']],
                    'Pick an id from /admin/packages, or omit --code-package to use the first active package.'
                );
            }

            if ((string) $row['status'] !== 'active') {
                $warnings[] = 'The chosen code package "' . (string) $row['name'] . '" is not active; the codes '
                    . 'are still bound to it.';
            }

            return [$row, $warnings];
        }

        $row = $this->row(
            "SELECT id, name, slug, price, status FROM packages
              WHERE status = 'active'
              ORDER BY sort_order, id
              LIMIT 1"
        );

        if ($row === false) {
            if ($options['code_qty'] > 0) {
                $warnings[] = 'No active package exists; no registration codes were generated.';
            }

            return [null, $warnings];
        }

        return [$row, $warnings];
    }

    /**
     * Re-resolve the code package after the reset.
     *
     * A catalogue reset replaces the package rows, so the package
     * validated before the delete is resolved again by its natural key
     * (`slug`): the re-seeded row keeps the same slug and gets a new id.
     * A customized seed that no longer creates it produces a warning and
     * no codes — the reset itself still succeeds (plan 7.6).
     *
     * @param array<string,mixed> $options
     * @param array<string,mixed>|null $before
     * @return array{0: ?array<string,mixed>, 1: list<string>}
     */
    private function resolveCodePackageAfterReset(array $options, ?array $before): array
    {
        $warnings = [];
        $tables   = $this->existingTables(true);

        if (!in_array('packages', $tables, true)) {
            return [null, $warnings];
        }

        $slug = $before === null ? '' : (string) ($before['slug'] ?? '');

        if ($slug !== '') {
            $row = $this->row(
                'SELECT id, name, slug, price, status FROM packages WHERE slug = ? LIMIT 1',
                [$slug]
            );

            if ($row !== false) {
                return [$row, $warnings];
            }

            if ($options['code_qty'] > 0) {
                $warnings[] = 'The chosen code package "' . (string) ($before['name'] ?? $slug)
                    . '" no longer exists after the reset; no registration codes were generated.';
            }

            return [null, $warnings];
        }

        $row = $this->row(
            "SELECT id, name, slug, price, status FROM packages
              WHERE status = 'active'
              ORDER BY sort_order, id
              LIMIT 1"
        );

        if ($row === false) {
            if ($options['code_qty'] > 0) {
                $warnings[] = 'No active package exists after the reset; no registration codes were generated.';
            }

            return [null, $warnings];
        }

        return [$row, $warnings];
    }

    /**
     * The options as they may appear in a report or an audit row.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function publicOptions(array $options): array
    {
        return [
            'env'              => $options['env'],
            'keep_catalogue'   => $options['keep_catalogue'],
            'reset_catalogue'  => $options['reset_catalogue'],
            'code_qty'         => $options['code_qty'],
            'code_package'     => $options['code_package'],
            'demo'             => $options['demo'],
            'keep_audit'       => $options['keep_audit'],
            'clear_audit'      => $options['clear_audit'],
            'keep_uploads'     => $options['keep_uploads'],
            'allow_production' => $options['allow_production'],
            'tool'             => $options['tool'],
            'actor_id'         => $options['actor_id'],
            'actor_role'       => $options['actor_role'],
        ];
    }

    /**
     * The ordered statement plan a dry run prints (no database access
     * beyond the preview's counts).
     *
     * @param array<string,mixed> $options
     * @return list<array{key:string,label:string,statements:int}>
     */
    private function plan(array $options): array
    {
        $plan = [
            ['key' => 'lock', 'label' => 'Acquire the advisory lock', 'statements' => 1],
            ['key' => 'preflight', 'label' => 'Preflight check (read-only)', 'statements' => 0],
            ['key' => 'counts', 'label' => 'Snapshot the row counts (read-only)', 'statements' => 0],
        ];

        foreach (self::DELETE_GROUPS as $group) {
            $plan[] = [
                'key'        => $group['key'],
                'label'      => $group['label'],
                'statements' => count($group['sql']),
            ];
        }

        $plan[] = ['key' => 'members', 'label' => 'Delete the member accounts', 'statements' => 1];
        $plan[] = ['key' => 'staff', 'label' => 'Reset the staff accounts', 'statements' => 1];

        if ($options['reset_catalogue']) {
            $seedStatements = 0;

            foreach ([
                $this->appRoot . '/database/seeds/003_products.sql',
                $this->appRoot . '/database/seeds/004_packages.sql',
            ] as $path) {
                try {
                    $seedStatements += count(SqlScript::fromFile($path)->statements());
                } catch (Throwable $e) {
                    error_log('[ecowealth] reset plan could not read ' . $path . ': ' . $e->getMessage());
                }
            }

            $plan[] = [
                'key'        => 'catalogue',
                'label'      => 'Delete and re-seed the starter catalogue',
                'statements' => 4 + $seedStatements,
            ];
        } else {
            $plan[] = ['key' => 'catalogue', 'label' => 'Keep the existing catalogue', 'statements' => 0];
        }

        $plan[] = [
            'key'        => 'audit_clear',
            'label'      => $options['clear_audit'] ? 'Clear the audit log' : 'Keep the audit log',
            'statements' => $options['clear_audit'] ? 1 : 0,
        ];
        $plan[] = ['key' => 'live', 'label' => 'Bump the live revisions', 'statements' => 2];
        $plan[] = ['key' => 'settings', 'label' => 'Write the reset metadata', 'statements' => 2];
        $plan[] = ['key' => 'audit', 'label' => 'Append the reset audit row', 'statements' => 1];
        $plan[] = [
            'key'        => 'auto_increment',
            'label'      => 'Reset the AUTO_INCREMENT counters (after commit)',
            'statements' => count(self::AUTO_INCREMENT_TABLES),
        ];
        $plan[] = [
            'key'        => 'uploads',
            'label'      => 'Delete the orphaned upload files (after commit)',
            'statements' => 0,
        ];
        $plan[] = [
            'key'        => 'demo',
            'label'      => $options['demo']
                ? 'Install the demo tree (after commit)'
                : 'Do not install the demo tree',
            'statements' => $options['demo'] ? $this->demoStatementCount() : 0,
        ];
        $plan[] = [
            'key'        => 'codes_generate',
            'label'      => 'Generate registration codes (after commit)',
            'statements' => $options['code_qty'] + ($options['code_qty'] > 0 ? 1 : 0),
        ];
        $plan[] = ['key' => 'verify', 'label' => 'Verify the reset (read-only)', 'statements' => 0];

        return $plan;
    }

    /**
     * The statements the demo seed contains, for the dry-run plan.
     *
     * A missing/unreadable seed is not fatal to a dry run: the demo step
     * would refuse at run time, so the plan shows zero and the preview
     * warning explains it.
     */
    private function demoStatementCount(): int
    {
        try {
            return count(SqlScript::fromFile((new DemoDataService($this->appRoot))->seedPath())->statements());
        } catch (Throwable $e) {
            error_log('[ecowealth] reset plan could not read the demo seed: ' . $e->getMessage());

            return 0;
        }
    }

    /* ==========================================================
     *  Reads
     * ========================================================== */

    /**
     * The validated target database, and the proof the connection uses it.
     */
    private function targetDatabase(): string
    {
        if ($this->targetDatabase !== null) {
            return $this->targetDatabase;
        }

        $requested = trim($this->database);
        $selected  = $this->selectedDatabase();

        if ($requested === '') {
            $requested = $selected;
        }

        if ($requested === '' || preg_match(DbTarget::NAME_PATTERN, $requested) !== 1) {
            throw DatabaseToolException::invalidName($requested);
        }

        if ($selected === '' || strcasecmp($selected, $requested) !== 0) {
            throw DatabaseToolException::refused(
                'The connection is not using the database the reset was asked to clear.',
                ['database' => $requested, 'connected_database' => $selected],
                'Open the connection with DbTarget::connect($database) so the target and the typed confirmation '
                    . 'cannot disagree.'
            );
        }

        return $this->targetDatabase = $requested;
    }

    private function selectedDatabase(): string
    {
        try {
            $selected = $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        } catch (PDOException $e) {
            throw $this->sqlFailure($e, 'connect', 'SELECT DATABASE()');
        }

        return is_string($selected) ? $selected : '';
    }

    private function serverVersion(): string
    {
        try {
            $version = $this->pdo->query('SELECT VERSION()')->fetchColumn();
        } catch (PDOException) {
            return 'unknown';
        }

        return is_string($version) ? $version : 'unknown';
    }

    /** @return list<string> */
    private function existingTables(bool $refresh = false): array
    {
        if ($this->existingTablesCache !== null && !$refresh) {
            return $this->existingTablesCache;
        }

        $database = $this->targetDatabase();

        try {
            $statement = $this->pdo->prepare(
                "SELECT table_name FROM information_schema.tables
                  WHERE table_schema = ? AND table_type = 'BASE TABLE'"
            );
            $statement->execute([$database]);
            $tables = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
            $statement->closeCursor();
        } catch (PDOException $e) {
            throw $this->sqlFailure($e, 'preflight', 'information_schema.tables');
        }

        sort($tables, SORT_STRING);

        return $this->existingTablesCache = $tables;
    }

    private function tableExists(string $table): bool
    {
        return in_array($table, $this->existingTables(), true);
    }

    /**
     * Per-table row counts plus a separate `members` figure.
     *
     * Preview tolerates a missing table (it reports the schema as
     * incomplete); the reset itself does not.
     *
     * @return array<string,int>
     */
    private function snapshotCounts(bool $tolerant = false): array
    {
        $tables = $this->existingTables(true);
        $counts = ['members' => 0];

        if (in_array('users', $tables, true)) {
            try {
                $counts['members'] = (int) $this->scalar("SELECT COUNT(*) FROM users WHERE role = 'member'");
            } catch (PDOException $e) {
                if (!$tolerant) {
                    throw $e;
                }
            }
        }

        foreach (self::ALL_TABLES as $table) {
            if (!in_array($table, $tables, true)) {
                $counts[$table] = 0;

                continue;
            }

            try {
                $counts[$table] = $this->countRows($table);
            } catch (PDOException $e) {
                if (!$tolerant) {
                    throw $e;
                }

                $counts[$table] = 0;
            }
        }

        return $counts;
    }

    private function countRows(string $table): int
    {
        if (!in_array($table, self::ALL_TABLES, true)) {
            throw DatabaseToolException::usage(
                'Unknown table "' . $table . '" in the reset allow-list.',
                ['table' => $table]
            );
        }

        return (int) $this->pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    }

    /** @return list<array{id:int,username:string,role:string}> */
    private function staffRows(): array
    {
        $rows = $this->rows(
            "SELECT id, username, role FROM users WHERE role IN ('admin', 'superadmin') ORDER BY id"
        );

        return array_map(static fn (array $row): array => [
            'id'       => (int) $row['id'],
            'username' => (string) $row['username'],
            'role'     => (string) $row['role'],
        ], $rows);
    }

    /**
     * The staff rows with every column the reset/verification asserts.
     *
     * @return list<array<string,mixed>>
     */
    private function staffStateRows(): array
    {
        return $this->rows(
            "SELECT id, username, role, member_status, is_active, package_id, sponsor_id,
                    ewallet_balance, withdrawable_balance, last_login_at
               FROM users
              WHERE role IN ('admin', 'superadmin')
              ORDER BY id"
        );
    }

    /**
     * The package rows the preview and the code batch read.
     *
     * @return list<array<string,mixed>>
     */
    private function packageRows(): array
    {
        if (!$this->tableExists('packages')) {
            return [];
        }

        return $this->rows(
            'SELECT id, name, slug, price, status FROM packages ORDER BY sort_order, id'
        );
    }

    /**
     * The primary admin row (the code batch's created_by).
     *
     * @param list<array<string,mixed>> $staff
     * @return array<string,mixed>|null
     */
    private static function primaryStaff(array $staff): ?array
    {
        foreach ($staff as $row) {
            if ((string) $row['role'] === 'admin') {
                return $row;
            }
        }

        return $staff[0] ?? null;
    }

    /** @return array{0:?string,1:?string} last_reset_at, last_reset_by */
    private function lastResetSettings(): array
    {
        if (!$this->tableExists('settings')) {
            return [null, null];
        }

        $rows = $this->rows(
            "SELECT key_name, value FROM settings WHERE key_name IN ('last_reset_at', 'last_reset_by')"
        );

        $map = [];

        foreach ($rows as $row) {
            $map[(string) $row['key_name']] = (string) $row['value'];
        }

        return [$map['last_reset_at'] ?? null, $map['last_reset_by'] ?? null];
    }

    private function countResetAuditRows(): int
    {
        if (!$this->tableExists('audit_log')) {
            return 0;
        }

        return (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'system.db_reset'");
    }

    private function standardSurfaceCount(): int
    {
        if (!$this->tableExists('live_revisions')) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count(self::STANDARD_SURFACES), '?'));

        return (int) $this->scalar(
            'SELECT COUNT(*) FROM live_revisions WHERE surface IN (' . $placeholders . ')',
            self::STANDARD_SURFACES
        );
    }

    private function autoIncrementValue(string $table): ?int
    {
        if (!in_array($table, self::ALL_TABLES, true)) {
            return null;
        }

        $value = $this->scalar(
            'SELECT auto_increment FROM information_schema.tables
              WHERE table_schema = ? AND table_name = ? LIMIT 1',
            [$this->targetDatabase(), $table]
        );

        return $value === null || $value === false ? null : (int) $value;
    }

    /* ==========================================================
     *  Writes
     * ========================================================== */

    /** One DELETE/UPDATE inside T1; returns the affected row count. */
    private function exec(string $sql): int
    {
        try {
            $count = $this->pdo->exec($sql);
        } catch (PDOException $e) {
            throw $this->sqlFailure($e, $this->currentStep, $sql);
        }

        return max(0, (int) $count);
    }

    /**
     * One prepared INSERT/UPDATE inside T1.
     *
     * @param list<mixed> $args
     */
    private function execPrepared(string $sql, array $args, string $step): int
    {
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($args);
            $count = $statement->rowCount();
            $statement->closeCursor();
        } catch (PDOException $e) {
            throw $this->sqlFailure($e, $step, $sql);
        }

        return max(0, (int) $count);
    }

    /** Upsert one settings row with two positional placeholders (plan 7.8). */
    private function upsertSetting(string $key, string $value): void
    {
        $this->execPrepared(
            'INSERT INTO settings (key_name, value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE value = ?',
            [$key, $value, $value],
            'settings'
        );
    }

    /**
     * Append one audit row.
     *
     * @param array<string,mixed>|null $meta
     * @param array<string,mixed> $options
     */
    private function insertAudit(?string $action, ?string $subjectType, ?int $subjectId, ?array $meta, array $options): void
    {
        $this->execPrepared(
            'INSERT INTO audit_log (actor_id, actor_role, action, subject_type, subject_id, meta_json, ip)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $options['actor_id'],
                $options['actor_role'],
                $action,
                $subjectType,
                $subjectId,
                $meta === null ? null : json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $options['ip'],
            ],
            'audit'
        );
    }

    /* ==========================================================
     *  Steps, locks and internals
     * ========================================================== */

    /**
     * Mark the start of a step: number it and call the progress hook.
     *
     * A hook that throws inside the transaction is the test seam for the
     * rollback case (IR-5-T12 #11); the outer catch rolls everything back.
     */
    private function beginStep(string $key, string $label): void
    {
        $this->stepIndex++;
        $this->currentStep = $key;

        if ($this->onStep !== null) {
            ($this->onStep)($this->stepIndex, ['key' => $key, 'label' => $label, 'status' => 'running']);
        }
    }

    /** @param array<string,mixed> $extra */
    private function recordStep(
        string $key,
        string $label,
        string $status,
        int $rows = 0,
        int $ms = 0,
        ?string $error = null,
        bool $deleted = false
    ): void {
        $step = [
            'key'    => $key,
            'label'  => $label,
            'status' => $status,
            'ms'     => $ms,
        ];

        if ($deleted) {
            $step['deleted'] = $rows;
        } else {
            $step['rows'] = $rows;
        }

        if ($error !== null) {
            $step['error'] = $error;
        }

        $this->steps[] = $step;
    }

    /** GET_LOCK: 1 acquired, 0 timeout, null error. */
    private function advisoryLock(string $name, int $timeout): bool
    {
        try {
            $statement = $this->pdo->prepare('SELECT GET_LOCK(?, ?)');
            $statement->execute([$name, $timeout]);
            $acquired = (int) $statement->fetchColumn() === 1;
            $statement->closeCursor();

            return $acquired;
        } catch (PDOException $e) {
            error_log('[ecowealth] reset GET_LOCK failed: ' . $e->getMessage());

            return false;
        }
    }

    /** Best-effort release: closing the connection would also release it. */
    private function releaseLock(string $name): void
    {
        try {
            $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $statement->execute([$name]);
            $statement->closeCursor();
        } catch (Throwable $e) {
            error_log('[ecowealth] reset RELEASE_LOCK failed: ' . $e->getMessage());
        }
    }

    private static function lockName(string $database): string
    {
        return 'ecowealth:reset:' . substr(sha1($database), 0, 16);
    }

    /**
     * Map a driver failure to an operator message without a DSN.
     */
    private function sqlFailure(PDOException $e, string $step, string $sql): DatabaseToolException
    {
        $sqlstate   = (string) ($e->errorInfo[0] ?? '');
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        error_log(sprintf(
            '[ecowealth] reset step "%s" failed: %s%s',
            $step,
            $e->getMessage(),
            $sql === '' ? '' : ' [' . substr((string) preg_replace('/\s+/', ' ', $sql), 0, 200) . ']'
        ));

        return DatabaseToolException::sql(
            'The reset failed at step "' . $step . '" (MySQL error ' . $driverCode . ') and was rolled back.',
            [
                'step'        => $step,
                'sqlstate'    => $sqlstate,
                'driver_code' => $driverCode,
                'statement'   => substr((string) preg_replace('/\s+/', ' ', $sql), 0, 200),
                'rolled_back' => true,
            ],
            'Nothing was deleted: every update of the reset runs in one transaction. Read the PHP error log, '
                . 'fix the cause and run it again.'
        );
    }

    /** @param list<mixed> $params @return list<array<string,mixed>> */
    private function rows(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll();
        $statement->closeCursor();

        return is_array($rows) ? array_values($rows) : [];
    }

    /** @param list<mixed> $params */
    private function row(string $sql, array $params = []): array|false
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();
        $statement->closeCursor();

        return is_array($row) ? $row : false;
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params = []): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $value = $statement->fetchColumn();
        $statement->closeCursor();

        return $value;
    }

    private function uploadsRoot(): string
    {
        if (defined('UPLOAD_ROOT') && (string) UPLOAD_ROOT !== '') {
            return rtrim(str_replace('\\', '/', (string) UPLOAD_ROOT), '/');
        }

        return $this->appRoot . '/public/uploads';
    }

    /** Two-decimal comparison form (no float equality in verification). */
    private static function decimal(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /** A non-negative-or-zero integer option, or a usage error. */
    private static function integerOption(mixed $value, string $label): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        if (is_string($value) && preg_match('/^\s*[+-]?\d+\s*$/', $value) === 1) {
            return (int) $value;
        }

        throw DatabaseToolException::usage(
            'The ' . $label . ' must be a whole number.',
            ['value' => is_scalar($value) ? (string) $value : gettype($value)],
            'Pass an integer, for example --codes=5.'
        );
    }

    private static function nullableIntegerOption(mixed $value, string $label): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::integerOption($value, $label);
    }

    /** One verification/preflight row. */
    private static function check(string $key, string $label, bool $ok, string $detail, string $hint = ''): array
    {
        return ['key' => $key, 'label' => $label, 'ok' => $ok, 'detail' => $detail, 'hint' => $hint];
    }

    /** Whole milliseconds elapsed since $started (float seconds). */
    private static function ms(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
