<?php
declare(strict_types=1);

/* ==============================================================
 *  TeamHarvestException — a Team Harvest money rule was broken (20D)
 *
 *  Same shape as RoyaltyException and UnilevelException: a
 *  machine-readable `reason` plus a message the caller may render as-is.
 *  The engine throws only for structural failures — an evaluation
 *  against an unseeded queue, a recipient who is not a member, a state
 *  the ledger cannot represent. A member simply below a threshold is
 *  **not** an exception: it is a normal `paid = 0` evaluation
 *  (plan §14.5, §14.7).
 *
 *      unseeded · not_member · state
 *
 *  `disabled()` exists for callers that must refuse rather than return
 *  `off`; the engine itself returns `off` (D-09), because the stream
 *  being off is a configuration, not a failure.
 *
 *  There is deliberately no repair path: an award is a financial record
 *  and a wrong one is a forward-only business decision (§18 Q3, D-12).
 * ============================================================== */

final class TeamHarvestException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason = 'team_harvest'
    ) {
        parent::__construct($message);
    }

    /**
     * `team_harvest_ledger_since` is empty: the queue was never seeded,
     * so nobody can tell a pre-go-live level from a payable one (D-04).
     */
    public static function unseeded(): self
    {
        return new self(
            'The Team Harvest queue is not seeded (team_harvest_ledger_since is empty);'
                . ' run bin/harvest_queue_seed.php before anything can be evaluated (plan D-04).',
            'unseeded'
        );
    }

    /**
     * The stream is switched off. The engine returns `off` rather than
     * throwing; this factory is for a caller that must refuse explicitly.
     */
    public static function disabled(): self
    {
        return new self(
            'The Team Harvest bonus is switched off (team_harvest_enabled = 0); nothing is evaluated (plan D-09).',
            'disabled'
        );
    }

    /**
     * The engine was asked to evaluate an account that is not a member.
     * The queue makes it impossible (20C Rule 4); the engine asserts it
     * anyway rather than pay a `role <> 'member'` row (§14.5 Rule 12).
     */
    public static function notMember(int $userId): self
    {
        return new self(
            'Team Harvest pays members only; account #' . $userId
                . ' is not a member account (or no longer exists), so nothing was evaluated.',
            'not_member'
        );
    }

    /** Any other state violation (a row the ledger cannot represent). */
    public static function state(string $message): self
    {
        return new self($message, 'state');
    }
}
