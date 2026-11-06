<?php
declare(strict_types=1);

/* ==============================================================
 *  CommissionService — the compensation hook (plan §6.2)
 *
 *  Phase 9 wires the seam and proves it runs EXACTLY ONCE per
 *  approved activation. Phases 10–11 fill in the awards:
 *
 *      Phase 10  direct referral — paid to users.sponsor_id
 *      Phase 11  indirect (unilevel) — levels 2–10 up the chain
 *
 *  The only trigger in this build is a completed package activation.
 *  ActivationService::complete() calls onActivation() inside the same
 *  transaction that sets the member active, then stamps
 *  `activations.commission_processed_at` — so a replayed approval can
 *  never pay twice (plan §6.2, §6.5).
 *
 *  No money moves yet: onActivation() records the call (for the Phase 9
 *  test) and returns an empty result. Phase 10 replaces the body.
 * ============================================================== */

final class CommissionService
{
    /**
     * Activation ids the hook has been called with, in call order.
     *
     * Phase 9 asserts "invoked once per approved activation"; Phases
     * 10–11 replace this with the real award log. Deliberately static
     * and in-memory: it is a test seam, not stored data.
     *
     * @var list<int>
     */
    private static array $invocations = [];

    /**
     * Run the compensation plan for one approved activation.
     *
     * @return array{awards: list<array<string, mixed>>, credited: float, skipped: int}
     */
    public function onActivation(int $activationId, ?PDO $pdo = null): array
    {
        self::$invocations[] = $activationId;

        /* Phases 10–11 award here, using:
         *   $activation = Activation::find($activationId, $pdo);
         *   Recipient   = sponsor chain from $activation['user_id']
         *   Amounts     = packages.direct_referral_bonus / package_levels
         * and credit each award through WalletService::credit() with
         * ref_type 'commission' inside this same transaction. */

        return ['awards' => [], 'credited' => 0.0, 'skipped' => 0];
    }

    /* ----------------------------------------------------------
     *  Test seam + Phase 10 placeholders
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

    /**
     * Commission totals for a member.
     *
     * The `commissions` table arrives with Phase 10; until then this
     * returns zeros, so the Phase 9 dashboard can show the card without
     * depending on a table that does not exist yet.
     *
     * @return array{count:int, credited:float, this_month:float, last_at:?string}
     */
    public function totalsFor(int $userId, ?PDO $pdo = null): array
    {
        $empty = ['count' => 0, 'credited' => 0.0, 'this_month' => 0.0, 'last_at' => null];

        if (!self::tableExists($pdo)) {
            return $empty;
        }

        $pdo ??= db();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS total_count,
                    COALESCE(SUM(amount), 0) AS credited,
                    COALESCE(SUM(CASE WHEN created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN amount ELSE 0 END), 0) AS this_month,
                    MAX(created_at) AS last_at
               FROM commissions
              WHERE user_id = ? AND status = 'credited'"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return $empty;
        }

        return [
            'count'      => (int) $row['total_count'],
            'credited'   => round((float) $row['credited'], 2),
            'this_month' => round((float) $row['this_month'], 2),
            'last_at'    => $row['last_at'] === null ? null : (string) $row['last_at'],
        ];
    }

    /** Does the Phase 10 `commissions` table exist yet? Cached per process. */
    private static ?bool $commissionsTable = null;

    /** Does the Phase 10 `commissions` table exist yet? */
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

    /** Clear the table-exists cache (the test harness may switch schemas). */
    public static function resetSchemaCache(): void
    {
        self::$commissionsTable = null;
    }
}
