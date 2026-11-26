<?php
declare(strict_types=1);

/**
 * Admin Product Binary ledger — headline counters (Phase 22E, plan §9;
 * Phase 23D adds the source split).
 *
 * `credited` rows are released money (the wallet was credited at release).
 * `pending` rows are earned but not in a wallet yet; `cancelled` rows were
 * never paid, so they are not a shortfall. `unrecovered` counts only
 * `voided` awards — the part of a released award the wallet could not
 * return after a post-release reversal (plan §15.7, §19.3).
 *
 * Points and money are shown side by side and never added together
 * (plan Rule 4): the header separates *Self* and *Pair* from the points
 * placed on the tree.
 *
 * @var array{
 *   credited_total:float, this_month:float, credited:int, voided:int, unrecovered:float,
 *   recipients:int, average:float, pending_total:float, pending:int, cancelled:int,
 *   cancelled_total:float, total_awarded:float, next_release_at:?string, pending_recipients:int,
 *   self_total:float, pair_total:float,
 *   activation_credited_total:float, activation_pending_total:float, activation_pairs:int, activation_points:int,
 *   order_credited_total:float, order_pending_total:float, order_pairs:int, order_points:int
 * } $stats
 */

$stats = array_merge([
    'credited_total'           => 0.0, 'this_month' => 0.0, 'credited' => 0, 'voided' => 0, 'unrecovered' => 0.0,
    'recipients'               => 0,   'average'    => 0.0,
    'pending_total'            => 0.0, 'pending'    => 0,   'cancelled' => 0, 'cancelled_total' => 0.0,
    'total_awarded'            => 0.0, 'next_release_at' => null, 'pending_recipients' => 0,
    'self_total'               => 0.0, 'pair_total' => 0.0,
    'activation_credited_total'=> 0.0, 'activation_pending_total' => 0.0, 'activation_pairs' => 0, 'activation_points' => 0,
    'order_credited_total'     => 0.0, 'order_pending_total' => 0.0, 'order_pairs' => 0, 'order_points' => 0,
], $stats ?? []);

$pendingCount      = (int) $stats['pending'];
$pendingRecipients = (int) $stats['pending_recipients'];
$cancelledCount    = (int) $stats['cancelled'];
$nextReleaseAt     = $stats['next_release_at'] === null ? null : (string) $stats['next_release_at'];

$pendingHint = 'Earned but not yet in a wallet.';

if ($pendingRecipients > 0) {
    $pendingHint .= ' ' . $pendingRecipients . ' member' . ($pendingRecipients === 1 ? '' : 's') . ' waiting.';
}

if ($nextReleaseAt !== null) {
    $pendingHint .= ' Next release ' . format_date($nextReleaseAt) . '.';
}

$cancelledHint = 'Never paid; no shortfall.';

if ($cancelledCount > 0) {
    $cancelledHint .= ' ' . money($stats['cancelled_total']) . ' cancelled.';
}

$cards = [
    [
        'label' => 'Total credited',
        'value' => money($stats['credited_total']),
        'hint'  => (int) $stats['recipients'] . ' member' . ($stats['recipients'] === 1 ? '' : 's') . ' paid',
        'icon'  => 'bx-gift',
        'tone'  => 'success',
    ],
    [
        'label' => 'Pending release',
        'value' => money($stats['pending_total']),
        'hint'  => $pendingHint,
        'icon'  => 'bx-time-five',
        'tone'  => 'warning',
    ],
    [
        'label' => 'This month',
        'value' => money($stats['this_month']),
        'hint'  => 'Since the 1st',
        'icon'  => 'bx-calendar',
        'tone'  => 'primary',
    ],
    [
        'label' => 'Awards',
        'value' => (string) $stats['credited'],
        'hint'  => $stats['credited'] === 0
            ? 'Nothing paid yet' . ($pendingCount > 0 ? ' · ' . $pendingCount . ' pending' : '')
            : 'Average ' . money($stats['average'])
                . ($pendingCount > 0 ? ' · ' . $pendingCount . ' pending' : ''),
        'icon'  => 'bx-receipt',
        'tone'  => 'info',
    ],
    [
        'label' => 'Recipients',
        'value' => (string) $stats['recipients'],
        'hint'  => 'Members credited at least once',
        'icon'  => 'bx-user-check',
        'tone'  => 'secondary',
    ],
    [
        'label' => 'Self bonus',
        'value' => money($stats['self_total']),
        'hint'  => 'Paid to the buyer on their own order',
        'icon'  => 'bx-user',
        'tone'  => 'info',
    ],
    [
        'label' => 'Pair bonus',
        'value' => money($stats['pair_total']),
        'hint'  => 'Matched left against right at every node',
        'icon'  => 'bx-link-alt',
        'tone'  => 'secondary',
    ],
    [
        'label' => 'Package pairs',
        'value' => (string) $stats['activation_pairs'],
        'hint'  => 'Pairs paid from package activations · ' . money($stats['activation_credited_total']) . ' credited',
        'icon'  => 'bx-package',
        'tone'  => 'info',
    ],
    [
        'label' => 'Package points',
        'value' => (string) $stats['activation_points'] . ' pts',
        'hint'  => 'Points placed by package activations',
        'icon'  => 'bx-box',
        'tone'  => 'secondary',
    ],
    [
        'label' => 'Cancelled before release',
        'value' => (string) $cancelledCount,
        'hint'  => $cancelledHint,
        'icon'  => 'bx-x-circle',
        'tone'  => 'secondary',
    ],
    [
        'label' => 'Unrecovered',
        'value' => money($stats['unrecovered']),
        'hint'  => 'Voided awards only — shortfall after a post-release reversal.',
        'icon'  => 'bx-error-circle',
        'tone'  => 'danger',
    ],
];
?>
              <div class="row g-6 mb-6" data-live-region="binary-stats">
<?php foreach ($cards as $card): ?>
                <div class="col-6 col-md-4 col-xl-3">
                  <?= View::partial('stat_card', $card) ?>
                </div>
<?php endforeach; ?>
              </div>
