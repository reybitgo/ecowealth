<?php
declare(strict_types=1);

/* ==============================================================
 *  CommissionService — the compensation plan's money path
 *  (plan §6.2–6.6, §7 Phases 10–11; reference
 *   Commission::processDirectReferral / processIndirectReferral)
 *
 *  The ONLY trigger in this build is a completed package activation.
 *  ActivationService::complete() calls onActivation() inside the same
 *  transaction that sets the member active, then stamps
 *  `activations.commission_processed_at` (plan §6.2).
 *
 *  Phase 10 pays the DIRECT leg:
 *
 *      recipient  users.sponsor_id of the activated member
 *      amount     packages.direct_referral_bonus (level 1)
 *      credit     WalletService::credit(ref_type = 'commission',
 *                                      ref_id    = commissions.id)
 *
 *  Phase 11 adds the INDIRECT leg in the same call (plan §6.4):
 *
 *      walk       users.sponsor_id upward, levels 2 … 10
 *      amount     the SOURCE package's package_levels row for that level
 *      credit     one withdrawable commission credit per awarded level
 *
 *  Rules this service owns (plan §6.3–6.5):
 *
 *    • a zero amount awards nothing — no row, no money
 *    • a recipient without an active membership + package is SKIPPED:
 *      a `status='skipped'` row records why, and no money moves
 *    • no compression: an ineligible upline consumes its level and the
 *      walk continues to the next one (settings.commission_compression
 *      is reserved and currently always 0)
 *    • `packages.indirect_enabled = 0` or
 *      `settings.commission_indirect_enabled = 0` pays the direct leg
 *      only; `commission_indirect_max_levels` caps the depth
 *    • `settings.commission_direct_enabled = 0` pays no direct leg at
 *      all (Phase 15 exposes the switch on /admin/settings)
 *    • `settings.commission_requires_active_sponsor = 0` relaxes the
 *      active-membership eligibility check for every recipient
 *    • a credited row exists only together with its wallet movement:
 *      both are written in the caller's transaction, so a failed
 *      credit rolls the row back (there is no one-sided state)
 *    • the same (type, source, recipient, level) can never be paid
 *      twice: checked in the service AND enforced by the unique key
 *    • the recipient's wallet credit is withdrawable (earnings)
 *
 *  Finance invariants (plan §6.8), exposed through reconcile():
 *    SUM(commissions.amount WHERE status='credited')
 *      = SUM(ewallet_ledger.amount WHERE ref_type='commission')
 *    every credited row has its ledger row (and vice versa)
 * ============================================================== */

final class CommissionService
{
    private PDO $pdo;
    private WalletService $wallet;

    /**
     * Activation ids the hook has been called with, in call order.
     *
     * Phase 9 asserts "invoked once per approved activation"; the real
     * award log is the `commissions` table. This stays as a test seam.
     *
     * @var list<int>
     */
    private static array $invocations = [];

    /** Does the Phase 10 `commissions` table exist? Cached per process. */
    private static ?bool $commissionsTable = null;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo    = $pdo ?? db();
        $this->wallet = new WalletService($this->pdo);
    }

    /* ----------------------------------------------------------
     *  The activation hook
     * ---------------------------------------------------------- */

    /**
     * Run the compensation plan for one approved activation.
     *
     * Always called inside ActivationService's transaction; the wallet
     * credit takes a savepoint inside it, so a refused debit leaves the
     * award row unwritten too.
     *
     * Two legs, one call (plan §6.2–6.5, Phase 11):
     *
     *   1. direct   the activated member's sponsor (level 1), priced by
     *               the source package's `direct_referral_bonus`
     *   2. indirect levels 2–10 up the same sponsor chain, each priced
     *               by the source package's `package_levels` row
     *
     * An ineligible recipient consumes the level (no compression) and
     * writes an audited `skipped` row; deeper levels continue. A zero
     * amount writes no row at all.
     *
     * @return array{
     *   awards: list<array<string, mixed>>, credited: float, skipped: int,
     *   direct_credited: float, indirect_credited: float,
     *   direct_skipped: int, indirect_skipped: int
     * }
     */
    public function onActivation(int $activationId, ?PDO $pdo = null): array
    {
        self::$invocations[] = $activationId;

        /* ActivationService hands in the connection it owns; bind the
         * wallet to that same connection so the award and the credit
         * share one transaction. */
        if ($pdo !== null && $pdo !== $this->pdo) {
            $this->pdo    = $pdo;
            $this->wallet = new WalletService($pdo);
        }

        $pdo ??= $this->pdo;
        $empty = self::emptySummary();

        if (!self::tableExists($pdo)) {
            /* A database mid-migration must not block an activation. */
            error_log(sprintf(
                '[ecowealth] activation #%d: commissions table missing — no award recorded',
                $activationId
            ));

            return $empty;
        }

        $activation = Activation::find($activationId, $pdo);

        if ($activation === null || (string) $activation['status'] !== 'approved') {
            error_log(sprintf(
                '[ecowealth] commission hook ran for activation #%d, which is not approved',
                $activationId
            ));

            return $empty;
        }

        $package = Package::find((int) $activation['package_id']);

        if ($package === null) {
            error_log(sprintf('[ecowealth] activation #%d has no package row', $activationId));

            return $empty;
        }

        $sourceId = (int) $activation['user_id'];

        /* One walk serves both legs. When indirect awards are switched
         * off (globally, by the package, or by a max-level setting of 1)
         * only the direct sponsor is read. */
        $indirectPermitted = setting_bool('commission_indirect_enabled', true)
            && (int) ($package['indirect_enabled'] ?? 1) === 1;

        $maxLevels = $indirectPermitted
            ? $this->indirectMaxLevels()
            : Commission::LEVEL_DIRECT;

        $chain = $this->sponsorChain($sourceId, $maxLevels, $pdo);

        if ($chain === []) {
            /* No upline: there is no recipient, so there is no row to
             * write. The audit trail still records the activation. */
            error_log(sprintf(
                '[ecowealth] activation #%d: @%s has no sponsor — no referral due',
                $activationId,
                (string) ($activation['username'] ?? ('#' . $sourceId))
            ));

            return $empty;
        }

        $summary = self::emptySummary();

        /* ---------- Level 1: direct referral ------------------------ */

        $direct   = $chain[0]['member'];
        $directId = (int) $direct['id'];
        $amount   = round((float) $package['direct_referral_bonus'], 2);

        /* Phase 15: the direct leg has its own switch. Turning it off
         * pays nothing and writes no row — the indirect ladder (when
         * enabled) still runs. */
        if (setting_bool('commission_direct_enabled', true) && $amount > 0) {
            $existing = Commission::forEvent('direct_referral', $sourceId, $directId, Commission::LEVEL_DIRECT, $pdo);

            if ($existing !== null) {
                /* Already awarded: report the row, but claim no new money. */
                $summary['awards'][] = $existing;
            } else {
                $reason = self::ineligibility($direct);

                if ($reason !== '') {
                    $summary['awards'][] = $this->skip(
                        $activation,
                        $package,
                        $directId,
                        Commission::LEVEL_DIRECT,
                        $amount,
                        $reason,
                        'direct_referral'
                    );
                    $summary['skipped']++;
                    $summary['direct_skipped']++;
                } else {
                    $summary['awards'][] = $this->award(
                        $activation,
                        $package,
                        $directId,
                        Commission::LEVEL_DIRECT,
                        $amount,
                        'direct_referral'
                    );
                    $summary['credited'] += $amount;
                    $summary['direct_credited'] += $amount;
                }
            }
        }

        /* ---------- Levels 2–10: indirect referral ------------------ */

        if ($indirectPermitted && count($chain) > 1) {
            $levels = Package::levels((int) $package['id'], $pdo);

            foreach (array_slice($chain, 1) as $hop) {
                $level  = (int) $hop['level'];
                $member = $hop['member'];
                $amount = round((float) ($levels[$level] ?? 0), 2);

                /* A zero level pays nothing and writes no row; it still
                 * consumes the level so deeper amounts keep their place. */
                if ($amount <= 0) {
                    continue;
                }

                $recipientId = (int) $member['id'];
                $existing    = Commission::forEvent('indirect_referral', $sourceId, $recipientId, $level, $pdo);

                if ($existing !== null) {
                    $summary['awards'][] = $existing;
                    continue;
                }

                $reason = self::ineligibility($member);

                if ($reason !== '') {
                    $summary['awards'][] = $this->skip(
                        $activation,
                        $package,
                        $recipientId,
                        $level,
                        $amount,
                        $reason,
                        'indirect_referral'
                    );
                    $summary['skipped']++;
                    $summary['indirect_skipped']++;
                } else {
                    $summary['awards'][] = $this->award(
                        $activation,
                        $package,
                        $recipientId,
                        $level,
                        $amount,
                        'indirect_referral'
                    );
                    $summary['credited'] += $amount;
                    $summary['indirect_credited'] += $amount;
                }
            }
        }

        $summary['credited'] = round($summary['credited'], 2);
        $summary['direct_credited'] = round($summary['direct_credited'], 2);
        $summary['indirect_credited'] = round($summary['indirect_credited'], 2);

        return $summary;
    }

    /** @return array{awards: list<array<string, mixed>>, credited: float, skipped: int, direct_credited: float, indirect_credited: float, direct_skipped: int, indirect_skipped: int} */
    private static function emptySummary(): array
    {
        return [
            'awards'            => [],
            'credited'          => 0.0,
            'skipped'           => 0,
            'direct_credited'   => 0.0,
            'indirect_credited' => 0.0,
            'direct_skipped'    => 0,
            'indirect_skipped'  => 0,
        ];
    }

    /**
     * Walk `users.sponsor_id` from an activated member's direct sponsor
     * upward (plan §6.4).
     *
     * Level 1 is the direct sponsor, level 2 their sponsor, and so on.
     * The walk stops at the requested depth, the end of the chain, or a
     * cycle (visited set). It is the only chain query path; one member
     * read per hop keeps the 10-level walk bounded (P11-T11).
     *
     * @return list<array{level:int, member:array<string, mixed>}>
     */
    public function sponsorChain(int $sourceUserId, int $maxLevels = Commission::LEVEL_MAX, ?PDO $pdo = null): array
    {
        $sourceUserId = max(0, $sourceUserId);
        $maxLevels    = max(1, min(Commission::LEVEL_MAX, $maxLevels));

        if ($sourceUserId <= 0) {
            return [];
        }

        $pdo     = $pdo ?? $this->pdo;
        $current = $this->sponsorOf($sourceUserId, $pdo);
        $visited = [$sourceUserId => true];
        $chain   = [];

        for ($level = 1; $level <= $maxLevels && $current !== null; $level++) {
            $currentId = (int) $current;

            if ($currentId <= 0 || isset($visited[$currentId])) {
                /* A corrupted chain (or a self-sponsor) pays nobody else. */
                error_log(sprintf(
                    '[ecowealth] sponsor chain for user #%d loops at #%d — walk stopped',
                    $sourceUserId,
                    $currentId
                ));
                break;
            }

            $member = Member::find($currentId, $pdo);

            if ($member === null) {
                break;
            }

            $visited[$currentId] = true;
            $chain[] = ['level' => $level, 'member' => $member];

            $next    = (int) ($member['sponsor_id'] ?? 0);
            $current = $next > 0 ? $next : null;
        }

        return $chain;
    }

    /**
     * How many indirect levels the configuration allows.
     *
     * `settings.commission_indirect_max_levels` is clamped to 2–10; a
     * value below 2 (including a non-numeric one read as 0) leaves the
     * direct leg only, which the caller represents as max level 1.
     */
    private function indirectMaxLevels(): int
    {
        $configured = setting_int('commission_indirect_max_levels', Commission::LEVEL_MAX);

        if ($configured < Commission::LEVEL_MIN) {
            return Commission::LEVEL_DIRECT;
        }

        return min(Commission::LEVEL_MAX, $configured);
    }

    /* ----------------------------------------------------------
     *  Award / skip
     * ---------------------------------------------------------- */

    /**
     * Insert the award and credit the recipient's wallet — one
     * transaction, one `ewallet_ledger` row (`ref_type='commission'`).
     *
     * @param array<string, mixed> $activation the approved activation row
     * @param array<string, mixed> $package    the source package (prices the award)
     * @return array<string, mixed> the written row + its wallet movement
     */
    public function award(
        array $activation,
        array $package,
        int $recipientId,
        int $level,
        float $amount,
        string $type = 'direct_referral'
    ): array {
        $sourceId     = (int) $activation['user_id'];
        $activationId = (int) $activation['id'];
        $packageId    = (int) $package['id'];
        $amount       = round($amount, 2);

        if ($amount <= 0) {
            throw new InvalidArgumentException('A commission award must be greater than zero.');
        }

        /* The award row and its wallet movement must commit or roll back
         * together (plan §6.5). ActivationService already owns a
         * transaction; a direct caller gets one here so a failed credit
         * can never leave a credited row behind. */
        $owns = !$this->pdo->inTransaction();

        if ($owns) {
            $this->pdo->beginTransaction();
        }

        try {
            $commissionId = Commission::create([
                'user_id'        => $recipientId,
                'type'           => $type,
                'amount'         => $amount,
                'level'          => $level,
                'source_user_id' => $sourceId,
                'package_id'     => $packageId,
                'activation_id'  => $activationId,
                'status'         => 'credited',
            ], $this->pdo);

            /* The credit is withdrawable (earnings) and idempotent on
             * (user, 'commission', commission id) — plan §6.5. */
            $movement = $this->wallet->credit(
                $recipientId,
                $amount,
                'commission',
                $commissionId,
                $this->note($type, $level, (string) ($package['name'] ?? 'package'), (string) ($activation['username'] ?? '')),
                true
            );

            Commission::linkLedger($commissionId, (int) $movement['ledger_id'], $this->pdo);

            if ($owns) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($owns && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        /* One new award row on the admin ledger and on the recipient's
         * own earnings page (its wallet credit also bumps the recipient's
         * dashboard, wallet and membership pages through WalletService). */
        Live::touch('admin.commissions');
        Live::touchEarnings($recipientId);

        $row = Commission::find($commissionId, $this->pdo) ?? [];

        return $row + [
            'commission_id' => $commissionId,
            'ledger_id'     => (int) $movement['ledger_id'],
            'balance_after' => (float) $movement['balance_after'],
            'replayed'      => (bool) ($movement['replayed'] ?? false),
        ];
    }

    /**
     * Record a due-but-unpaid award (ineligible recipient).
     *
     * No wallet movement happens here — the row is the audit trail that
     * explains why the money was not paid (plan §6.3).
     *
     * @param array<string, mixed> $activation
     * @param array<string, mixed> $package
     * @return array<string, mixed>
     */
    public function skip(
        array $activation,
        array $package,
        int $recipientId,
        int $level,
        float $amount,
        string $reason,
        string $type = 'direct_referral'
    ): array {
        $reason  = trim($reason);
        $reason  = $reason === '' ? 'not eligible' : $reason;

        $existing = Commission::forEvent($type, (int) $activation['user_id'], $recipientId, $level, $this->pdo);

        if ($existing !== null) {
            return $existing;
        }

        $commissionId = Commission::create([
            'user_id'        => $recipientId,
            'type'           => $type,
            'amount'         => round($amount, 2),
            'level'          => $level,
            'source_user_id' => (int) $activation['user_id'],
            'package_id'     => (int) $package['id'],
            'activation_id'  => (int) $activation['id'],
            'status'         => 'skipped',
            'reason'         => $reason,
        ], $this->pdo);

        /* The ledger's "skipped" counter moved and the recipient's own
         * earnings page gained an audit row; no wallet or dashboard page
         * shows a skipped award. */
        Live::touch('admin.commissions');
        Live::touchEarnings($recipientId);

        return Commission::find($commissionId, $this->pdo) ?? ['id' => $commissionId, 'status' => 'skipped'];
    }

    /* ----------------------------------------------------------
     *  Reporting
     * ---------------------------------------------------------- */

    /**
     * Commission totals for a member (dashboard + member page).
     *
     * Returns zeros gracefully when the table has not been migrated
     * yet, so an older database never breaks a page.
     *
     * @return array{
     *   count:int, credited:float, this_month:float, direct:float,
     *   indirect:float, skipped:int, last_at:?string
     * }
     */
    public function totalsFor(int $userId, ?PDO $pdo = null): array
    {
        $empty = [
            'count' => 0, 'credited' => 0.0, 'this_month' => 0.0,
            'direct' => 0.0, 'indirect' => 0.0, 'skipped' => 0, 'last_at' => null,
        ];

        $pdo ??= $this->pdo;

        if (!self::tableExists($pdo)) {
            return $empty;
        }

        return Commission::totalsForUser($userId, $pdo);
    }

    /**
     * Finance reconciliation (plan §6.8, §7 Phase 10 gate).
     *
     * Read-only. Reports:
     *   1. credited commission total vs the commission ledger total
     *   2. credited rows with no (or a missing) ledger row
     *   3. ledger commission rows that belong to no award
     *   4. a ledger amount that differs from its award
     *
     * `settings.commission_ledger_since` (written by migration 012)
     * marks when this database's award history begins. Commission
     * credits that predate it — QA fixtures, an earlier build — have no
     * award row and never will, so they are reported as `legacy` and do
     * not fail the check. From that instant on the rule is strict.
     *
     * @return array{
     *   ok: bool,
     *   credited_rows: int,
     *   skipped_rows: int,
     *   credited_total: float,
     *   ledger_total: float,
     *   legacy_total: float,
     *   legacy_rows: int,
     *   mismatches: list<array<string, mixed>>
     * }
     */
    public function reconcile(): array
    {
        $pdo = $this->pdo;

        if (!self::tableExists($pdo)) {
            return [
                'ok' => true, 'credited_rows' => 0, 'skipped_rows' => 0,
                'credited_total' => 0.0, 'ledger_total' => 0.0, 'legacy_total' => 0.0, 'mismatches' => [],
            ];
        }

        /* Read the baseline through this service's own connection (plan
         * IR-5-T10): the reset engine constructs the service on the
         * injected target, and the global setting() would read the
         * application database instead — a mismatch no test could see.
         * Behaviour on the app connection is unchanged. */
        $since = '';

        try {
            $sinceStmt = $pdo->prepare('SELECT value FROM settings WHERE key_name = ? LIMIT 1');
            $sinceStmt->execute(['commission_ledger_since']);
            $since = trim((string) ($sinceStmt->fetchColumn() ?: ''));
            $sinceStmt->closeCursor();
        } catch (Throwable $e) {
            /* A pre-migration database has no settings table; keep the old default. */
            error_log('[ecowealth] commission ledger baseline unavailable: ' . $e->getMessage());
        }

        $args = $since === '' ? [] : [$since];

        $awards = $pdo->query(
            "SELECT COUNT(CASE WHEN status = 'credited' THEN 1 END) AS credited_rows,
                    COUNT(CASE WHEN status = 'skipped'  THEN 1 END) AS skipped_rows,
                    COALESCE(SUM(CASE WHEN status = 'credited' THEN amount ELSE 0 END), 0) AS credited_total
               FROM commissions"
        )->fetch() ?: [];

        $ledgerStmt = $pdo->prepare(
            "SELECT COUNT(*) AS ledger_rows, COALESCE(SUM(amount), 0) AS ledger_total
               FROM ewallet_ledger l
              WHERE ref_type = 'commission'" . ($since === '' ? '' : ' AND l.created_at >= ?')
        );
        $ledgerStmt->execute($args);
        $ledger = $ledgerStmt->fetch() ?: [];

        $legacy = ['legacy_rows' => 0, 'legacy_total' => 0.0];

        if ($since !== '') {
            $legacyStmt = $pdo->prepare(
                "SELECT COUNT(*) AS legacy_rows, COALESCE(SUM(amount), 0) AS legacy_total
                   FROM ewallet_ledger
                  WHERE ref_type = 'commission' AND created_at < ?"
            );
            $legacyStmt->execute([$since]);
            $legacy = $legacyStmt->fetch() ?: $legacy;
        }

        $creditedTotal = round((float) ($awards['credited_total'] ?? 0), 2);
        $ledgerTotal   = round((float) ($ledger['ledger_total'] ?? 0), 2);
        $mismatches    = [];

        if (number_format($creditedTotal, 2, '.', '') !== number_format($ledgerTotal, 2, '.', '')) {
            $mismatches[] = [
                'kind'     => 'total',
                'expected' => $creditedTotal,
                'actual'   => $ledgerTotal,
                'message'  => 'credited commission total differs from the commission ledger total',
            ];
        }

        $missingStmt = $pdo->prepare(
            "SELECT c.id, c.amount, c.ledger_id, u.username
               FROM commissions c
               JOIN users u ON u.id = c.user_id
               LEFT JOIN ewallet_ledger l ON l.id = c.ledger_id
              WHERE c.status = 'credited'
                AND (c.ledger_id IS NULL OR l.id IS NULL)
              ORDER BY c.id"
        );
        $missingStmt->execute();

        foreach ($missingStmt->fetchAll() as $row) {
            $mismatches[] = [
                'kind'        => 'missing_ledger',
                'commission'  => (int) $row['id'],
                'username'    => (string) $row['username'],
                'expected'    => (float) $row['amount'],
                'actual'      => null,
                'message'     => 'a credited award has no wallet movement',
            ];
        }

        $orphanStmt = $pdo->prepare(
            "SELECT l.id, l.user_id, l.ref_id, l.amount, u.username
               FROM ewallet_ledger l
               JOIN users u ON u.id = l.user_id
               LEFT JOIN commissions c ON c.id = l.ref_id
              WHERE l.ref_type = 'commission'
                AND (l.ref_id IS NULL OR c.id IS NULL)" . ($since === '' ? '' : ' AND l.created_at >= ?') . '
              ORDER BY l.id'
        );
        $orphanStmt->execute($args);

        foreach ($orphanStmt->fetchAll() as $row) {
            $mismatches[] = [
                'kind'       => 'orphan_ledger',
                'ledger'     => (int) $row['id'],
                'username'   => (string) $row['username'],
                'expected'   => null,
                'actual'     => (float) $row['amount'],
                'message'    => 'a commission ledger row belongs to no award',
            ];
        }

        $drift = $pdo->query(
            "SELECT c.id, c.amount AS commission_amount, l.amount AS ledger_amount, u.username
               FROM commissions c
               JOIN ewallet_ledger l ON l.id = c.ledger_id
               JOIN users u ON u.id = c.user_id
              WHERE c.status = 'credited'
                AND l.amount <> c.amount
              ORDER BY c.id"
        )->fetchAll();

        foreach ($drift as $row) {
            $mismatches[] = [
                'kind'       => 'amount',
                'commission' => (int) $row['id'],
                'username'   => (string) $row['username'],
                'expected'   => (float) $row['commission_amount'],
                'actual'     => (float) $row['ledger_amount'],
                'message'    => 'the award and its ledger row disagree',
            ];
        }

        return [
            'ok'             => $mismatches === [],
            'credited_rows'  => (int) ($awards['credited_rows'] ?? 0),
            'skipped_rows'   => (int) ($awards['skipped_rows'] ?? 0),
            'credited_total' => $creditedTotal,
            'ledger_total'   => $ledgerTotal,
            'legacy_total'   => round((float) ($legacy['legacy_total'] ?? 0), 2),
            'legacy_rows'    => (int) ($legacy['legacy_rows'] ?? 0),
            'mismatches'     => $mismatches,
        ];
    }

    /* ----------------------------------------------------------
     *  Test seam (Phase 9)
     * ---------------------------------------------------------- */

    /** @return list<int> */
    public static function invocations(): array
    {
        return self::$invocations;
    }

    public static function resetInvocations(): void
    {
        self::$invocations = [];
    }

    public static function wasInvokedFor(int $activationId): bool
    {
        return in_array($activationId, self::$invocations, true);
    }

    /** Clear the table-exists cache (the test harness may switch schemas). */
    public static function resetSchemaCache(): void
    {
        self::$commissionsTable = null;
    }

    /* ----------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------- */

    /**
     * Why a recipient cannot be paid, or '' when they can.
     *
     * Plan §6.3: a member, active, `member_status = 'active'`, with a
     * package. Anything else is skipped (level consumed, no money).
     *
     * @param array<string, mixed>|null $member
     */
    public static function ineligibility(?array $member): string
    {
        if ($member === null) {
            return 'recipient account not found';
        }

        /* The operator can relax the active-membership rule (seeded 1,
         * exposed on /admin/settings in Phase 15). The default keeps the
         * plan §6.3 rule: an inactive upline consumes its level. */
        if (!setting_bool('commission_requires_active_sponsor', true)) {
            return '';
        }

        if ((int) ($member['is_active'] ?? 0) !== 1) {
            return 'recipient is not active';
        }

        $status = (string) ($member['member_status'] ?? '');

        if ($status !== 'active') {
            return 'recipient membership is ' . ($status === '' ? 'unknown' : $status);
        }

        if (($member['package_id'] ?? null) === null) {
            return 'recipient has no package';
        }

        return '';
    }

    private function sponsorOf(int $userId, PDO $pdo): ?int
    {
        $stmt = $pdo->prepare('SELECT sponsor_id FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $value = $stmt->fetchColumn();

        if ($value === false || $value === null || (int) $value <= 0) {
            return null;
        }

        return (int) $value;
    }

    /** Ledger note, e.g. "Direct referral — Package A (level 1) — from @maria". */
    private function note(string $type, int $level, string $packageName, string $source): string
    {
        $label = Commission::TYPE_LABELS[$type] ?? ucfirst(str_replace('_', ' ', $type));
        $note  = $label . ' — ' . $packageName . ' (level ' . $level . ')';

        if ($source !== '') {
            $note .= ' — from @' . $source;
        }

        return mb_substr($note, 0, 255);
    }

    private static function tableExists(?PDO $pdo = null): bool
    {
        if (self::$commissionsTable !== null) {
            return self::$commissionsTable;
        }

        $pdo ??= db();

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute(['commissions']);

        return self::$commissionsTable = ((int) $stmt->fetchColumn() > 0);
    }
}
