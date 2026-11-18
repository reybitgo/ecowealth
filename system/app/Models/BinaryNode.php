<?php
declare(strict_types=1);

/* ==============================================================
 *  BinaryNode — the product-binary placement tree (Phase 22B)
 *
 *  Phase 22B ships only the vocabulary: the labels, statuses, sides
 *  and the depth/points ceilings that the settings card, the badge
 *  copy and the later placement and money phases compare against.
 *  No query method lives here yet — 22C adds find(), forUser(),
 *  place(), move() and the rest. Every name and every limit below
 *  is frozen by plan §15.4 and may not change after 22B.
 * ============================================================== */

final class BinaryNode
{
    /** One row per member: the user's own node is the primary key. */
    public const KINDS = ['own', 'team', 'pair', 'pair_return', 'reversal', 'adjustment'];

    /** The two money award kinds (D-10). */
    public const AWARD_KINDS = ['self', 'pair'];

    /** Node life cycle: root (the company), placed, floating (legacy only). */
    public const STATUSES = ['root', 'placed', 'floating'];

    /** A child sits on one side of its upline; (upline_id, side) is unique. */
    public const SIDES = ['left', 'right'];

    /** Depth is bounded so a path never outgrows its column (100 chars). */
    public const MAX_DEPTH = 100;

    /** One product line can place at most one million points (D-05). */
    public const POINTS_MAX = 1_000_000;

    /** Human labels for the node statuses (settings card and badge copy). */
    public const STATUS_LABELS = [
        'root'     => 'Company root',
        'placed'   => 'Placed',
        'floating' => 'Floating',
    ];

    /** Badge tone per node status (settings card and member panel copy). */
    public const STATUS_BADGES = [
        'root'     => 'bg-label-secondary',
        'placed'   => 'bg-label-success',
        'floating' => 'bg-label-warning',
    ];

    /** Side labels (member cards, admin console, settings copy). */
    public const SIDE_LABELS = [
        'left'  => 'Left',
        'right' => 'Right',
    ];
}
