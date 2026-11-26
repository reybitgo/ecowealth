<?php
declare(strict_types=1);

/**
 * Admin General Harvest ledger — counters (Phase 21E-S1, plan §9, Appendix
 * C.2).
 *
 * The tiles of plan Appendix C.2. The figures describe the **whole ledger**,
 * the same convention the package and repeat tabs use: filtering the rows
 * below never rewrites the totals above.
 *
 * Two rules are visible here:
 *
 *  - Rule 4: *Credited* counts `status = 'credited'` only. A `baseline` row
 *    (complete before go-live) has its own tile and never enters a paid
 *    total.
 *  - Rule 8: *Open liability* is what `openLiability()` says the engine
 *    would pay if every outstanding level were evaluated now. It is a
 *    number, not a warning, and it is labelled *not yet paid*.
 *
 * @var array{
 *   credited_total:float, credited_rows:int, recipients:int,
 *   baseline_total:float, baseline_rows:int, awards:int,
 *   open_amount:float, open_levels:int, settled_n:int, queue_n:int,
 *   armed:bool, enabled:bool
 * } $stats
 */

$stats = array_merge([
    'credited_total' => 0.0, 'credited_rows' => 0, 'recipients' => 0,
    'baseline_total' => 0.0, 'baseline_rows' => 0, 'awards' => 0,
    'open_amount' => 0.0, 'open_levels' => 0, 'settled_n' => 0,
    'queue_n' => 0, 'armed' => false, 'enabled' => false,
], $stats ?? []);

$paidRows = (int) $stats['credited_rows'];
$baseRows = (int) $stats['baseline_rows'];
$awardRows = (int) $stats['awards'];

$cards = [
    [
        'label' => 'Credited',
        'value' => money($stats['credited_total']),
        'hint'  => $paidRows . ' paid block' . ($paidRows === 1 ? '' : 's') . ' in the wallets',
        'icon'  => 'bx-wallet',
        'tone'  => 'success',
    ],
    [
        'label' => 'Awards',
        'value' => (string) $awardRows,
        'hint'  => 'Blocks decided — one row per node and level',
        'icon'  => 'bx-list-check',
        'tone'  => 'info',
    ],
    [
        'label' => 'Recipients',
        'value' => (string) (int) $stats['recipients'],
        'hint'  => 'Node holders with at least one paid block',
        'icon'  => 'bx-user-check',
        'tone'  => 'secondary',
    ],
    [
        'label' => 'Before go-live',
        'value' => money($stats['baseline_total']),
        'hint'  => $baseRows . ' block' . ($baseRows === 1 ? '' : 's') . ' recorded, never paid',
        'icon'  => 'bx-time-five',
        'tone'  => 'warning',
    ],
    [
        'label' => 'Open liability',
        'value' => money($stats['open_amount']),
        'hint'  => (int) $stats['open_levels'] . ' level' . ((int) $stats['open_levels'] === 1 ? '' : 's')
            . ' not yet settled — not yet paid',
        'icon'  => 'bx-error-circle',
        'tone'  => 'dark',
    ],
];
?>
              <div class="row g-6 mb-6" data-live-region="general-harvest-stats">
<?php foreach ($cards as $card): ?>
                <div class="col-6 col-md-4 col-xl-2">
                  <?= View::partial('stat_card', $card) ?>
                </div>
<?php endforeach; ?>

<?php if ((int) ($stats['queue_n'] ?? 0) > (int) ($stats['settled_n'] ?? 0)): ?>
                <div class="alert alert-warning mb-0 py-3 small" role="status">
                  <strong>Backlog:</strong>
                  the queue is ahead of settled_n. Run
                  <code>bin/general_harvest_catchup.php</code> to settle the outstanding levels.
                </div>
<?php endif; ?>
              </div>
