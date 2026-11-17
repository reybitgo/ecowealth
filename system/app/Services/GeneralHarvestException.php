<?php
declare(strict_types=1);

/* ==============================================================
 *  GeneralHarvestException — a General Harvest rule was broken
 *  (plan §6 Phase 21C, §12.6, §14.3, §14.12)
 *
 *  Same shape as TeamHarvestException and RoyaltyException: a
 *  machine-readable `reason` plus a message the caller may render as-is.
 *  The arm job throws only for a state it must refuse — the stream on,
 *  the board already armed, the queue unseeded or not gapless, a ladder
 *  that was never priced. A member simply below a threshold is **not**
 *  an exception: that is a normal empty board read (21D).
 *
 *      enabled · armed · unseeded · not_gapless · invalid_ladder · state
 *
 *  `state()` is the catch-all the later phases use for the anomaly codes
 *  of §14.12 (`not gapless`, `queue shrank`, `ledger ahead of the queue`,
 *  …); the dedicated factories exist so the arm job can name the exact
 *  refusal without parsing a message.
 *
 *  There is deliberately no repair path: the baseline is irreversible,
 *  so a refusal is always "nothing was written, fix the cause and run it
 *  again" (plan §12.6 Rule 7).
 * ============================================================== */

final class GeneralHarvestException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason = 'general_harvest'
    ) {
        parent::__construct($message);
    }

    /**
     * The stream is switched on. Arming while the money could pay would
     * make the baseline depend on the order of two operator actions, so
     * the only working order is configure → arm → enable (§14.3 Rule 3).
     */
    public static function enabled(): self
    {
        return new self(
            'The General Harvest stream is switched on (general_harvest_enabled = 1);'
                . ' switch the stream off before arming (plan §14.3).',
            'enabled'
        );
    }

    /** The board already holds a baseline: arm is a one-time act (§12.6 Rule 1). */
    public static function armed(?string $armedAt = null): self
    {
        $instant = $armedAt === null || $armedAt === ''
            ? 'The General Harvest board is already armed'
            : 'The General Harvest board was already armed on ' . $armedAt;

        return new self(
            $instant . '; there is no --force. The baseline is irreversible —'
                . ' a business reset is the only way to start a new network\'s history (plan §12.6).',
            'armed'
        );
    }

    /**
     * `team_harvest_ledger_since` is empty or not a datetime: the queue
     * has no history, so `N₀` would be meaningless (§14.3 Rule 4).
     */
    public static function unseeded(?string $value = null): self
    {
        $detail = $value === null || trim($value) === ''
            ? 'team_harvest_ledger_since is empty'
            : 'team_harvest_ledger_since is not a valid datetime (' . $value . ')';

        return new self(
            'The Harvest queue is not live (' . $detail . ');'
                . ' run bin/harvest_queue_seed.php before arming (plan §14.3 Rule 4).',
            'unseeded'
        );
    }

    /**
     * `COUNT(*) <> MAX(id)`: a hole would shift every later node, so the
     * arm fails closed and names both numbers (§12.3 Rule 1, D-03).
     */
    public static function notGapless(int $n, int $maxId): self
    {
        return new self(
            sprintf(
                'The Harvest queue is not gapless: COUNT(*) = %d but MAX(id) = %d, so N and the node index disagree.'
                    . ' A hole is out-of-band corruption; restore the queue from a backup before arming (plan D-03).',
                $n,
                $maxId
            ),
            'not_gapless'
        );
    }

    /**
     * A level is priced at zero (or the whole ladder is). All ten levels
     * must be positive because the amounts freeze at the arm (D-23); the
     * message names the level so the operator can fix it on the card.
     */
    public static function invalidLadder(int $level, float $total = 0.0): self
    {
        if ($level < 1) {
            return new self(
                sprintf(
                    'The General Harvest ladder is invalid: the ten amounts total %s, and the arm freezes them for ever.'
                        . ' Price all ten levels on /admin/settings → Commissions before arming (plan §14.3 Rule 5, D-23).',
                    money($total)
                ),
                'invalid_ladder'
            );
        }

        return new self(
            sprintf(
                'Level %d has no amount, and the arm freezes all ten amounts for ever (D-23).'
                    . ' Price every level 1–10 on /admin/settings → Commissions before arming (plan §14.3 Rule 5).',
                $level
            ),
            'invalid_ladder'
        );
    }

    /** Any other state violation (a row or a lock the arm cannot represent). */
    public static function state(string $message): self
    {
        return new self($message, 'state');
    }

    /** `N < settled_n`: a position vanished since the last settlement (§14.12). */
    public static function queueShrank(int $n, int $settled): self
    {
        return new self(
            sprintf(
                'The Harvest queue shrank: N = %d but settled_n = %d. Positions were deleted under the board.'
                    . ' Restore from a backup before settling again (plan §14.12).',
                $n,
                $settled
            ),
            'queue shrank'
        );
    }

    /** A level's rows exceed `qFor(L, target) + 1` (§14.12). */
    public static function ledgerAheadOfQueue(int $level, int $rows, int $expected): self
    {
        return new self(
            sprintf(
                'The General Harvest ledger is ahead of the queue at level %d: %d rows but the queue fills %d blocks.'
                    . ' A row was written for a block that does not exist yet (plan §14.12).',
                $level,
                $rows,
                $expected
            ),
            'ledger ahead of the queue'
        );
    }

    /** A priced level's rows are fewer than `qFor(L, settled_n) + 1` (§14.12). */
    public static function ledgerBehindSettled(int $level, int $rows, int $expected): self
    {
        return new self(
            sprintf(
                'The General Harvest ledger is behind settled_n at level %d: %d rows but %d blocks are complete.'
                    . ' An interrupted settlement left the prefix incomplete (plan §14.12).',
                $level,
                $rows,
                $expected
            ),
            'ledger behind settled_n'
        );
    }

    /** A settlement was asked to pay a node whose account is not a member (§14.12). */
    public static function notAMember(int $userId): self
    {
        return new self(
            sprintf(
                'General Harvest would pay node holder user %d, but that account is not a member.'
                    . ' The engine pays only role = member rows (plan §14.12).',
                $userId
            ),
            'not a member'
        );
    }
}
