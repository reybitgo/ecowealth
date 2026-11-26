<?php
declare(strict_types=1);

/**
 * Admin repeat-purchase ledger — headline counters (Phase 17D/18, plan §8, §19.3).
 *
 * `credited` rows are released money (the wallet was credited at
 * release). `pending` rows are earned but not in a wallet yet;
 * `cancelled` rows were never paid, so they are not a shortfall.
 * `unrecovered` counts ONLY `voided` awards — the part of a released
 * award the wallet could not return after a post-release reversal
 * (plan §15.7, §19.3).
 *
 * @var array{
 *   credited_total:float, this_month:float, credited:int, skipped:int,
 *   recipients:int, average:float, top_level:int, voided:int, unrecovered:float,
 *   pending_total:float, pending:int, cancelled:int, cancelled_total:float,
 *   total_awarded:float, next_release_at:?string, pending_recipients:int
 * } $stats
 */

$stats = array_merge([
    'credited_total' => 0.0, 'this_month' => 0.0, 'credited' => 0, 'skipped' => 0,
    'recipients' => 0, 'average' => 0.0, 'top_level' => 0, 'voided' => 0, 'unrecovered' => 0.0,
    'pending_total' => 0.0, 'pending' => 0, 'cancelled' => 0, 'cancelled_total' => 0.0,
    'total_awarded' => 0.0, 'next_release_at' => null, 'pending_recipients' => 0,
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
        'hint'  => 'Uplines credited at least once',
        'icon'  => 'bx-user-check',
        'tone'  => 'secondary',
    ],
    [
        'label' => 'Skipped',
        'value' => (string) $stats['skipped'],
        'hint'  => 'Ineligible upline — no money moved',
        'icon'  => 'bx-block',
        'tone'  => 'warning',
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
              <div class="row g-6 mb-6" data-live-region="repeat-stats">
<?php foreach ($cards as $card): ?>
                <div class="col-6 col-md-4 col-xl-3">
                  <?= View::partial('stat_card', $card) ?>
                </div>
<?php endforeach; ?>
              </div>
