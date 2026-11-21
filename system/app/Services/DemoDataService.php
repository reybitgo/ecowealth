<?php
declare(strict_types=1);

/* ==============================================================
 *  DemoDataService — the one way demo fixtures enter a database
 *  (plan IR-7, §IR-7-T1/T2)
 *
 *      $demo = new DemoDataService();                  // config + APP_ENV
 *      $ok   = $demo->available($pdo, true, false);     // preconditions
 *      $rows = $demo->install($pdo);                   // seed + verify
 *      $check = $demo->verify($pdo);                   // re-check later
 *
 *  Demo data is a QA act, never a default (D-IR-03, D-IR-13). This
 *  service owns the three rules that make it safe:
 *
 *    1. it never runs in production (or any environment a caller pins
 *       as production through $env — the tests use that instead of
 *       redefining a constant);
 *    2. it only runs against the two default packages, because the
 *       005 seed binds demo members and codes to them; a missing
 *       package would create active members with a NULL package;
 *    3. when the caller requires it, it only runs on an empty business
 *       database: no demo username exists and every transactional table
 *       is empty. `audit_log` and `live_revisions` are deliberately
 *       excluded — a reset appends its own audit row and bumps the live
 *       surfaces before the demo step runs.
 *
 *  `install()` re-checks the catalogue, then runs the real
 *  database/seeds/005_demo_tree.sql through SqlScript (no second copy
 *  of its values) inside its own transaction, verifies the tree,
 *  wallets and codes, and rolls the whole demo back if anything is
 *  wrong. It is the T2 post-step of plan §5.11: a failure here never
 *  un-resets the database and never writes a half tree.
 *
 *  What it must not do: authenticate, emit HTML, read CLI flags, call
 *  the global db(), or write outside the injected PDO.  The front-ends
 *  report what it returns; the reset engine and the installer decide
 *  when it runs.
 * ============================================================== */

final class DemoDataService
{
    /** The seed this service imports, relative to the application root. */
    public const SEED_FILE = 'database/seeds/005_demo_tree.sql';

    /** The documented demo password (plan IR-7-T3). */
    public const PASSWORD = 'Demo@1234';

    /** The five members the seed creates, in sponsor-chain order. */
    public const USERNAMES = ['juan', 'maria', 'peter', 'sarah', 'david'];

    /** Packages the seed binds members and codes to (plan 7.5). */
    public const REQUIRED_PACKAGE_SLUGS = ['package-a', 'package-b'];

    /** The three sample codes the seed writes. */
    public const CODES = ['DEMO-PKGA-2A7K', 'DEMO-PKGB-3B8L', 'DEMO-EXPD-4C9M'];

    /** The one code that is deliberately expired. */
    public const EXPIRED_CODE = 'DEMO-EXPD-4C9M';

    /** Expected totals (plan IR-7-T1). */
    public const EXPECTED_MEMBERS = 5;
    public const EXPECTED_CODES = 3;
    public const EXPECTED_LEDGER_ROWS = 3;

    /**
     * Funded demo wallets: username => [balance, withdrawable] (plan §4.5).
     *
     * The three funded members carry a ledger row each, so the wallet
     * reconciliation stays clean after the seed.
     */
    public const WALLETS = [
        'juan'  => ['2000.00', '1500.00'],
        'maria' => ['1000.00', '800.00'],
        'peter' => ['500.00', '400.00'],
    ];

    /**
     * The transactional tables that must be empty before demo data is
     * merged into a database (`audit_log` / `live_revisions` excepted).
     *
     * Deliberately an explicit list, not a query wildcard: a demo import
     * must never decide on its own that an unknown table is disposable.
     * The names match DatabaseResetService::ZERO_TABLES plus `users`'
     * demo-username check, which is handled separately.
     */
    public const BUSINESS_TABLES = [
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
        'reg_codes',
        'remember_tokens',
        'shop_invoice_items',
        'shop_invoices',
        'shop_order_events',
        'shop_order_items',
        'shop_orders',
        'shop_payment_proofs',
        'shop_shipments',
    ];

    private readonly string $appRoot;
    private readonly ?string $env;

    /**
     * @param string|null $appRoot application root (defaults to system/)
     * @param string|null $env     environment override (defaults to APP_ENV)
     */
    public function __construct(?string $appRoot = null, ?string $env = null)
    {
        $root           = $appRoot ?? dirname(__DIR__, 2);
        $resolved       = realpath($root);
        $this->appRoot  = rtrim(str_replace('\\', '/', $resolved === false ? $root : $resolved), '/');
        $this->env      = $env;
    }

    /** Absolute path of database/seeds/005_demo_tree.sql. */
    public function seedPath(): string
    {
        return $this->appRoot . '/' . self::SEED_FILE;
    }

    /**
     * May demo data be written to this target?
     *
     * Every refusal is a reason string the caller can show verbatim; no
     * check throws, so the installer/reset preflight can report the
     * situation before anything is written.
     *
     * @param bool        $requireEmpty        refuse a database that already holds business rows
     * @param bool        $catalogueWillReset  the caller will re-seed packages before the demo runs
     * @param string|null $env                 override the constructor/APP_ENV value
     * @return array{ok:bool, reason:string}
     */
    public function available(
        PDO $pdo,
        bool $requireEmpty = true,
        bool $catalogueWillReset = false,
        ?string $env = null
    ): array {
        $env = strtolower(trim((string) ($env ?? $this->env ?? (defined('APP_ENV') ? APP_ENV : 'production'))));

        if ($env === 'production') {
            return [
                'ok'     => false,
                'reason' => 'Demo fixtures cannot be installed in production (APP_ENV=production).',
            ];
        }

        if (!is_file($this->seedPath())) {
            return [
                'ok'     => false,
                'reason' => 'The demo seed ' . self::SEED_FILE . ' is missing.',
            ];
        }

        try {
            $tables = $this->tables($pdo);
        } catch (DatabaseToolException $e) {
            return ['ok' => false, 'reason' => $e->getMessage()];
        }

        /* ---- Catalogue ------------------------------------------------
         * A fresh install has no packages yet: the bundle that runs after
         * this check seeds them (the same reason a catalogue reset skips
         * the check — the re-seed provides them). An installed database
         * that lost them is refused instead of writing a broken tree. */
        if (!$catalogueWillReset && in_array('packages', $tables, true)) {
            $missing = array_values(array_diff(self::REQUIRED_PACKAGE_SLUGS, $this->packageSlugs($pdo)));

            if ($missing !== []) {
                return [
                    'ok'     => false,
                    'reason' => 'The demo fixtures need the default package'
                        . (count($missing) === 1 ? '' : 's') . ' "' . implode('", "', $missing)
                        . '" in the catalogue. Restore or re-seed the starter catalogue '
                        . '(database/seeds/004_packages.sql) and try again.',
                ];
            }
        }

        if (!$requireEmpty) {
            return ['ok' => true, 'reason' => ''];
        }

        /* ---- The five demo usernames --------------------------------- */
        if (in_array('users', $tables, true)) {
            $existing = $this->rows(
                $pdo,
                'SELECT username FROM users WHERE username IN ('
                    . implode(', ', array_fill(0, count(self::USERNAMES), '?')) . ')',
                self::USERNAMES
            );

            if ($existing !== []) {
                return [
                    'ok'     => false,
                    'reason' => 'The demo members already exist in this database ('
                        . implode(', ', array_column($existing, 'username'))
                        . '). Demo data is never merged into an existing tree; reset the database without --demo '
                        . 'and run the demo step again.',
                ];
            }

            $members = (int) $this->scalar($pdo, "SELECT COUNT(*) FROM users WHERE role = 'member'");

            if ($members > 0) {
                return [
                    'ok'     => false,
                    'reason' => 'The database already holds ' . $members . ' member account'
                        . ($members === 1 ? '' : 's') . '. Demo fixtures only go into an empty business database; '
                        . 'reset it without --demo first if you want the demo tree instead.',
                ];
            }
        }

        /* ---- Every transactional table -------------------------------- */
        $occupied = [];

        foreach (self::BUSINESS_TABLES as $table) {
            if (!in_array($table, $tables, true)) {
                continue;
            }

            $count = (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM `' . $table . '`');

            if ($count > 0) {
                $occupied[$table] = $count;
            }
        }

        if ($occupied !== []) {
            return [
                'ok'     => false,
                'reason' => 'The database already holds business rows ('
                    . implode(', ', array_map(
                        static fn (string $table, int $count): string => $table . '=' . $count,
                        array_keys($occupied),
                        array_values($occupied)
                    ))
                    . '). Demo fixtures are installed into an empty database only, never merged into live data. '
                    . 'A reset clears these tables before the demo step runs.',
            ];
        }

        return ['ok' => true, 'reason' => ''];
    }

    /**
     * Import the demo tree and prove it landed.
     *
     * The catalogue is re-checked here (not only in available()) because
     * the reset engine may run this after a catalogue re-seed, and a
     * customized seed set is allowed to be missing the default packages:
     * that case must refuse before writing a single demo row (plan 7.5).
     *
     * @return array{
     *   installed:bool, members:int, codes:int, ledger_rows:int,
     *   wallets:array<string,array{balance:string,withdrawable:string}>,
     *   credentials:string, statements:int, ms:int
     * }
     * @throws DatabaseToolException when the catalogue is inconsistent, the
     *         seed fails, or the imported tree does not verify
     */
    public function install(PDO $pdo): array
    {
        $started = microtime(true);

        $tables = $this->tables($pdo);

        if (!in_array('packages', $tables, true)) {
            throw DatabaseToolException::refused(
                'The demo fixtures need the packages table, which does not exist in this database.',
                ['database' => $this->database($pdo)],
                'Install the schema (database/install.sql) or reset the catalogue before requesting demo data.'
            );
        }

        $missing = array_values(array_diff(self::REQUIRED_PACKAGE_SLUGS, $this->packageSlugs($pdo)));

        if ($missing !== []) {
            throw DatabaseToolException::refused(
                'The demo fixtures need the default package'
                    . (count($missing) === 1 ? '' : 's') . ' "' . implode('", "', $missing)
                    . '", which the catalogue does not contain.',
                ['missing_packages' => $missing, 'database' => $this->database($pdo)],
                'Restore the starter catalogue (database/seeds/004_packages.sql) or run the reset with '
                    . '--reset-catalogue, then request demo data again.'
            );
        }

        if ($pdo->inTransaction()) {
            throw DatabaseToolException::refused(
                'The demo import needs a connection with no open transaction.',
                ['database' => $this->database($pdo)],
                'Commit or roll back the caller\'s transaction first; the demo batch owns its own transaction.'
            );
        }

        $script = SqlScript::fromFile($this->seedPath());

        $pdo->beginTransaction();

        try {
            $run      = $script->run($pdo);
            $verified = $this->verify($pdo);

            if (!$verified['ok']) {
                $failed = array_values(array_filter(
                    $verified['checks'],
                    static fn (array $check): bool => !$check['ok']
                ));

                throw DatabaseToolException::verify(
                    'The demo import did not produce the expected tree: '
                        . implode(' ', array_map(
                            static fn (array $check): string => $check['detail'],
                            $failed
                        )),
                    ['checks' => $failed],
                    'The demo batch was rolled back. Run the reset or install again; if it repeats, inspect '
                        . 'database/seeds/005_demo_tree.sql and the packages it binds to.'
                );
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } catch (Throwable $rollback) {
                    error_log('[ecowealth] demo rollback failed: ' . $rollback->getMessage());
                }
            }

            if ($e instanceof DatabaseToolException) {
                throw $e;
            }

            error_log('[ecowealth] demo import failed: ' . $e->getMessage());

            throw DatabaseToolException::sql(
                'The demo fixtures could not be installed and were rolled back.',
                ['exception' => $e::class],
                'Nothing demo-related was written. Read the PHP error log, fix the cause, and run the step again.'
            );
        }

        return [
            'installed'   => true,
            'members'     => self::EXPECTED_MEMBERS,
            'codes'       => self::EXPECTED_CODES,
            'ledger_rows' => self::EXPECTED_LEDGER_ROWS,
            'wallets'     => self::walletReport(),
            'credentials' => self::PASSWORD,
            'statements'  => (int) $run['statements'],
            'ms'          => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    /**
     * Prove the demo tree, wallets and codes are present and correct.
     *
     * Read-only, so an operator (or a later verification pass) can call
     * it without re-importing. Every check compares natural keys
     * (`username`, `code`, `ref_id`) and money strings, never ids.
     *
     * @return array{ok:bool, checks:list<array{key:string,label:string,ok:bool,detail:string}>}
     */
    public function verify(PDO $pdo): array
    {
        $checks = [];
        $tables = $this->tables($pdo);

        /* ---- The five members ---------------------------------------- */
        $members = in_array('users', $tables, true)
            ? $this->rows(
                $pdo,
                'SELECT username, role, member_status, is_active, package_id, sponsor_id,
                        ewallet_balance, withdrawable_balance
                   FROM users
                  WHERE username IN ('
                    . implode(', ', array_fill(0, count(self::USERNAMES), '?')) . ')
                  ORDER BY id',
                self::USERNAMES
            )
            : [];

        $usernames = array_map(static fn (array $row): string => (string) $row['username'], $members);
        $missing   = array_values(array_diff(self::USERNAMES, $usernames));

        $membersOk = $missing === [] && count($members) === self::EXPECTED_MEMBERS;

        foreach ($members as $member) {
            if ((string) $member['role'] !== 'member') {
                $membersOk = false;
            }
        }

        $checks[] = self::check(
            'members',
            'Demo members',
            $membersOk,
            $missing === []
                ? count($members) . ' demo members: ' . implode(', ', $usernames)
                : count($members) . ' member rows; missing: ' . implode(', ', $missing),
            'The seed creates juan, maria, peter, sarah and david; every one is a member row.'
        );

        /* ---- david waits for activation ------------------------------- */
        $david = in_array('users', $tables, true)
            ? $this->row($pdo, "SELECT member_status, package_id FROM users WHERE username = 'david' LIMIT 1")
            : false;

        $davidOk = $david !== false
            && (string) $david['member_status'] === 'pending'
            && $david['package_id'] === null;

        $checks[] = self::check(
            'pending_member',
            'Pending demo member',
            $davidOk,
            $david === false
                ? 'david does not exist'
                : 'david is ' . (string) $david['member_status']
                    . ($david['package_id'] === null ? ' with no package' : ' with a package'),
            'david is the pending member the activation tests use; the seed leaves him unactivated.'
        );

        /* ---- Wallet balances ------------------------------------------ */
        $wallets  = [];
        $walletOk = true;

        if (in_array('users', $tables, true)) {
            foreach (self::WALLETS as $username => [$balance, $withdrawable]) {
                $row = $this->row(
                    $pdo,
                    'SELECT ewallet_balance, withdrawable_balance FROM users WHERE username = ? LIMIT 1',
                    [$username]
                );

                if ($row === false) {
                    $walletOk = false;
                    continue;
                }

                $actual = [
                    'balance'      => self::decimal($row['ewallet_balance']),
                    'withdrawable' => self::decimal($row['withdrawable_balance']),
                ];

                $wallets[$username] = $actual;

                if ($actual['balance'] !== $balance || $actual['withdrawable'] !== $withdrawable) {
                    $walletOk = false;
                }
            }
        } else {
            $walletOk = false;
        }

        $checks[] = self::check(
            'wallets',
            'Demo wallets',
            $walletOk,
            $wallets === []
                ? 'no demo wallet row'
                : implode(', ', array_map(
                    static fn (string $username, array $wallet): string => $username . ' '
                        . $wallet['balance'] . '/' . $wallet['withdrawable'],
                    array_keys($wallets),
                    array_values($wallets)
                )),
            'The seed funds juan 2000/1500, maria 1000/800 and peter 500/400 so the wallet reconciliation is clean.'
        );

        /* ---- The three ledger rows ----------------------------------- */
        $ledgerRows = in_array('ewallet_ledger', $tables, true)
            ? (int) $this->scalar(
                $pdo,
                "SELECT COUNT(*) FROM ewallet_ledger
                  WHERE ref_type = 'adjustment' AND ref_id IN (9001, 9002, 9003)"
            )
            : 0;

        $checks[] = self::check(
            'ledger',
            'Demo ledger rows',
            $ledgerRows === self::EXPECTED_LEDGER_ROWS,
            $ledgerRows . ' of ' . self::EXPECTED_LEDGER_ROWS . ' adjustment rows (ref_ids 9001–9003)',
            'Each funded demo wallet carries the immutable ledger row that justifies its balance.'
        );

        /* ---- The three codes ----------------------------------------- */
        $codesOk = false;
        $codesDetail = 'the reg_codes table is missing';

        if (in_array('reg_codes', $tables, true)) {
            $placeholders = implode(', ', array_fill(0, count(self::CODES), '?'));
            $codes = $this->rows(
                $pdo,
                'SELECT code, status, package_id, expires_at FROM reg_codes
                  WHERE code IN (' . $placeholders . ') ORDER BY code',
                self::CODES
            );

            $found = array_map(static fn (array $row): string => (string) $row['code'], $codes);
            $codesOk = count($codes) === self::EXPECTED_CODES
                && array_values(array_diff(self::CODES, $found)) === [];

            foreach ($codes as $code) {
                if ((int) $code['package_id'] <= 0) {
                    $codesOk = false;
                }

                $expectedStatus = (string) $code['code'] === self::EXPIRED_CODE ? 'expired' : 'unused';

                if ((string) $code['status'] !== $expectedStatus) {
                    $codesOk = false;
                }
            }

            $codesDetail = count($codes) . ' of ' . self::EXPECTED_CODES . ' demo codes'
                . ($codes === [] ? '' : ': ' . implode(', ', $found));
        }

        $checks[] = self::check(
            'codes',
            'Demo registration codes',
            $codesOk,
            $codesDetail,
            'Two unused codes and one deliberately expired code, each bound to its package.'
        );

        $ok = true;

        foreach ($checks as $check) {
            if (!$check['ok']) {
                $ok = false;
                break;
            }
        }

        return ['ok' => $ok, 'checks' => $checks];
    }

    /* ==========================================================
     *  Internals
     * ========================================================== */

    /** Base tables in the connection's current schema, sorted. */
    private function tables(PDO $pdo): array
    {
        $database = $this->database($pdo);

        $statement = $pdo->prepare(
            "SELECT table_name FROM information_schema.tables
              WHERE table_schema = ? AND table_type = 'BASE TABLE'"
        );
        $statement->execute([$database]);
        $tables = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
        $statement->closeCursor();
        sort($tables, SORT_STRING);

        return $tables;
    }

    /** The schema the connection is using; a connection without one cannot be seeded. */
    private function database(PDO $pdo): string
    {
        $database = $pdo->query('SELECT DATABASE()')->fetchColumn();

        if (!is_string($database) || $database === '') {
            throw DatabaseToolException::refused(
                'The demo fixtures need a connection that has selected a database.',
                [],
                'Connect through DbTarget::connect($database) before requesting demo data.'
            );
        }

        return $database;
    }

    /** @return list<string> */
    private function packageSlugs(PDO $pdo): array
    {
        $rows = $this->rows($pdo, 'SELECT slug FROM packages ORDER BY slug');

        return array_map(static fn (array $row): string => (string) $row['slug'], $rows);
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string,mixed>>
     */
    private function rows(PDO $pdo, string $sql, array $params = []): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll();
        $statement->closeCursor();

        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * @param list<mixed> $params
     * @return array<string,mixed>|false
     */
    private function row(PDO $pdo, string $sql, array $params = []): array|false
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();
        $statement->closeCursor();

        return is_array($row) ? $row : false;
    }

    /** @param list<mixed> $params */
    private function scalar(PDO $pdo, string $sql, array $params = []): mixed
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $value = $statement->fetchColumn();
        $statement->closeCursor();

        return $value;
    }

    /** Two-decimal comparison form (no float equality). */
    private static function decimal(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /**
     * The funded wallets as balance/withdrawable pairs for a report.
     *
     * @return array<string,array{balance:string,withdrawable:string}>
     */
    private static function walletReport(): array
    {
        $wallets = [];

        foreach (self::WALLETS as $username => [$balance, $withdrawable]) {
            $wallets[$username] = ['balance' => $balance, 'withdrawable' => $withdrawable];
        }

        return $wallets;
    }

    /** One verification row. */
    private static function check(string $key, string $label, bool $ok, string $detail, string $hint = ''): array
    {
        return ['key' => $key, 'label' => $label, 'ok' => $ok, 'detail' => $detail, 'hint' => $hint];
    }
}
