<?php
declare(strict_types=1);

/* ==============================================================
 *  RoyaltyOps — the operations picture of the Royalty stream
 *  (plan Phase 19F §10, §14.10)
 *
 *  One read-only snapshot that answers the operator's one-screen
 *  question: is this month open, evaluated, paid or stuck?
 *
 *      open            the current (always open) month
 *      last_final      the newest period with a final status
 *      pending         completed months with no header yet
 *      evaluated       months written but not paid, with their age
 *      next            the period the next scheduled run touches
 *      switches        royalty_enabled / royalty_pay_enabled
 *      last_close_at   the newest evaluation timestamp
 *      counts          periods, award rows, pending liability, paid
 *
 *  It feeds three readers with one implementation: the operations
 *  panel on the Royalty tab, `--doctor`'s pipeline section and the
 *  `--json` payload of the close job.
 *
 *  This model writes nothing and names no money table directly: every
 *  read goes through `RoyaltyPeriod` and `RoyaltyAward`, so the
 *  "engine is the only producer" invariant (P19A-T13) still holds.
 * ============================================================== */

final class RoyaltyOps
{
    /**
     * Every setting a close reads (plan §13.4, Appendix A): the two
     * switches, the four thresholds, the three quotas, the six rates and
     * the ledger baseline. One list, read by the doctor and the health
     * check, so "16/16 present" cannot drift between them.
     */
    public const SETTING_KEYS = [
        'royalty_enabled',
        'royalty_pay_enabled',
        'royalty_leader_directs',
        'royalty_supervisor_leaders',
        'royalty_manager_supervisors',
        'royalty_director_managers',
        'royalty_supervisor_quota',
        'royalty_manager_quota',
        'royalty_director_quota',
        'royalty_supervisor_group_pct',
        'royalty_supervisor_personal_pct',
        'royalty_manager_group_pct',
        'royalty_manager_personal_pct',
        'royalty_director_group_pct',
        'royalty_director_personal_pct',
        'royalty_ledger_since',
    ];

    /** The two money tables the stream owns (plan §13.2). */
    public const TABLES = ['royalty_periods', 'royalty_awards'];

    /** An open month: how long "now" is, for a testable age. */
    private const MAX_PERIODS = 120;

    /**
     * The snapshot, in one read of the period table.
     *
     * @param int|null $now Epoch used for the "evaluated N h ago" ages;
     *                      injectable so a test never waits 48 hours.
     * @return array{
     *   open:string, baseline:string, last_final:?array<string, mixed>,
     *   last_paid:?string, pending:list<string>,
     *   evaluated:list<array{period:string, pending:int, amount:float,
     *     paid_total:float, evaluated_at:?string, age_hours:?int}>,
     *   next:?array{period:string, action:string},
     *   switches:array{earn:bool, pay:bool},
     *   last_close_at:?string,
     *   counts:array{periods:int, awards:int, pending_rows:int,
     *     pending_total:float, paid_all_time:float}
     * }
     */
    public static function snapshot(?PDO $pdo = null, ?int $now = null): array
    {
        $pdo ??= db();
        $now ??= time();

        $open     = RoyaltyPeriod::periodOf();
        $baseline = RoyaltyPeriod::baseline($pdo);
        $periods  = RoyaltyPeriod::periods(self::MAX_PERIODS, $pdo);

        $lastFinal    = null;
        $lastCloseAt  = null;
        $awardRows    = 0;
        $paidAllTime  = 0.0;
        $pendingRows  = 0;
        $pendingTotal = 0.0;
        $evaluated    = [];

        foreach ($periods as $row) {
            $period = (string) $row['period'];
            $status = (string) $row['status'];

            if ($lastFinal === null && in_array($status, RoyaltyPeriod::FINAL, true)) {
                $lastFinal = $row;
            }

            $evaluatedAt = (string) ($row['evaluated_at'] ?? '');

            if ($evaluatedAt !== '' && ($lastCloseAt === null || $evaluatedAt > $lastCloseAt)) {
                $lastCloseAt = $evaluatedAt;
            }

            $awardRows   += (int) $row['award_count'];
            $paidAllTime += (float) $row['paid_total'];

            if ($status !== 'evaluated') {
                continue;
            }

            $pending      = RoyaltyAward::pendingCount($period, $pdo);
            $pendingRows += $pending;
            $pendingTotal += (float) $row['award_total'] - (float) $row['paid_total'];

            $at = strtotime($evaluatedAt);

            $evaluated[] = [
                'period'       => $period,
                'pending'      => $pending,
                'amount'       => round((float) $row['award_total'] - (float) $row['paid_total'], 2),
                'paid_total'   => (float) $row['paid_total'],
                'evaluated_at' => $evaluatedAt === '' ? null : $evaluatedAt,
                'age_hours'    => $at === false ? null : max(0, (int) floor(($now - $at) / 3600)),
            ];
        }

        $pending = RoyaltyPeriod::pendingPeriods($pdo);

        return [
            'open'          => $open,
            'baseline'      => $baseline,
            'last_final'    => $lastFinal,
            'last_paid'     => RoyaltyPeriod::lastPaid($pdo),
            'pending'       => $pending,
            'evaluated'     => $evaluated,
            'next'          => self::nextAction($evaluated, $pending),
            'switches'      => [
                'earn' => RoyaltyService::enabled(),
                'pay'  => RoyaltyService::paymentsEnabled(),
            ],
            'last_close_at' => $lastCloseAt,
            'counts'        => [
                'periods'      => count($periods),
                'awards'       => $awardRows,
                'pending_rows' => $pendingRows,
                'pending_total' => round(max(0.0, $pendingTotal), 2),
                'paid_all_time' => round($paidAllTime, 2),
            ],
        ];
    }

    /**
     * The period the next scheduled run touches, and what it does with
     * it: `pay` resumes an evaluated month, `close` evaluates a pending
     * one. Evaluated months run first (plan §10 Rule 3).
     *
     * @param list<array{period:string}> $evaluated
     * @param list<string> $pending
     * @return array{period:string, action:string}|null
     */
    private static function nextAction(array $evaluated, array $pending): ?array
    {
        $resume = array_map(
            static fn (array $row): string => (string) $row['period'],
            $evaluated
        );

        sort($resume, SORT_STRING);

        if ($resume !== []) {
            return ['period' => $resume[0], 'action' => 'pay'];
        }

        if ($pending !== []) {
            return ['period' => $pending[0], 'action' => 'close'];
        }

        return null;
    }

    /** The runbook command that finishes an evaluated month. */
    public static function payCommand(string $period): string
    {
        return 'php bin/royalty_month_close.php --pay-only --period=' . $period;
    }

    /** The runbook command that closes one month end to end. */
    public static function closeCommand(string $period): string
    {
        return 'php bin/royalty_month_close.php --period=' . $period;
    }
}
