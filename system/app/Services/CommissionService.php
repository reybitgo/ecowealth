<?php
declare(strict_types=1);

/* ==============================================================
 *  CommissionService — the compensation plan's money path
 *  (plan §6.2–6.5, §7 Phase 10; reference Commission::processDirectReferral)
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
 *  Phase 11 adds the indirect walk (levels 2–10) to the same call.
 *
 *  Rules this service owns (plan §6.3, §6.5):
 *
 *    • a zero direct bonus awards nothing — no row, no money
 *    • a recipient without an active membership + package is SKIPPED:
 *      a `status='skipped'` row records why, and no money moves
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
     * @return array{awards: list<array<string, mixed>>, credited: float, skipped: int}
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
        $empty = ['awards' => [], 'credited' => 0.0, 'skipped' => 0];

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

        $sourceId  = (int) $activation['user_id'];
        $sponsorId = $this->sponsorOf($sourceId, $pdo);

        if ($sponsorId === null) {
            /* No upline: there is no recipient, so there is no row to
             * write. The audit trail still records the activation. */
            error_log(sprintf(
                '[ecowealth] activation #%d: @%s has no sponsor — no direct referral due',
                $activationId,
                (string) ($activation['username'] ?? ('#' . $sourceId))
            ));

            return $empty;
        }

        $amount = round((float) $package['direct_referral_bonus'], 2);

        if ($amount <= 0) {
            /* The package pays no direct bonus: nothing due, no row (P10-T3). */
            return $empty;
        }

        /* Idempotency anchor: a replayed approval never pays twice. */
        $existing = Commission::forEvent('direct_referral', $sourceId, $sponsorId, Commission::LEVEL_DIRECT, $pdo);

        if ($existing !== null) {
            /* Already awarded: report the row, but claim no new money. */
            return [
                'awards'   => [$existing],
                'credited' => 0.0,
                'skipped'  => 0,
            ];
        }

        $sponsor = Member::find($sponsorId, $pdo);
        $reason  = self::ineligibility($sponsor);

        if ($reason !== '') {
            $row = $this->skip($activation, $package, $sponsorId, Commission::LEVEL_DIRECT, $amount, $reason, 'direct_referral');

            return ['awards' => [$row], 'credited' => 0.0, 'skipped' => 1];
        }

        $row = $this->award($activation, $package, $sponsorId, Commission::LEVEL_DIRECT, $amount, 'direct_referral');

        return ['awards' => [$row], 'credited' => $amount, 'skipped' => 0];
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

        $since = trim((string) setting('commission_ledger_since', ''));
        $args  = $since === '' ? [] : [$since];

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
