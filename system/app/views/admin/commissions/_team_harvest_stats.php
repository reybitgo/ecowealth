<?php
declare(strict_types=1);

/**
 * Admin Team Harvest ledger — counters (Phase 20E, plan §9, Appendix C.2).
 *
 * The six tiles of plan Appendix C.2. The figures describe the **whole
 * ledger**, the same convention the package and repeat tabs use: filtering
 * the rows below never rewrites the totals above.
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
 *   credited_total:float, this_month:float, credited_rows:int, recipients:int,
 *   baseline_total:float, baseline_rows:int, awards:int,
 *   open_levels:int, open_members:int, open_amount:float
 * } $stats
 */

$stats = array_merge([
    'credited_total' => 0.0, 'this_month' => 0.0, 'credited_rows' => 0,
    'recipients' => 0, 'baseline_total' => 0.0, 'baseline_rows' => 0,
    'awards' => 0, 'open_levels' => 0, 'open_members' => 0, 'open_amount' => 0.0,
], $stats ?? []);

$paidRows = (int) $stats['credited_rows'];
$baseRows = (int) $stats['baseline_rows'];

$cards = [
    [
        'label' => 'Credited',
        'value' => money($stats['credited_total']),
        'hint'  => $paidRows . ' paid level' . ($paidRows === 1 ? '' : 's') . ' in the wallets',
        'icon'  => 'bx-wallet',
        'tone'  => 'success',
    ],
    [
        'label' => 'This month',
        'value' => money($stats['this_month']),
        'hint'  => 'Credited since the first of the month',
        'icon'  => 'bx-calendar',
        'tone'  => 'primary',
    ],
    [
        'label' => 'Awards',
        'value' => (string) $paidRows,
        'hint'  => 'Levels paid — one row per member and level',
        'icon'  => 'bx-list-check',
        'tone'  => 'info',
    ],
    [
        'label' => 'Recipients',
        'value' => (string) (int) $stats['recipients'],
        'hint'  => 'Members with at least one paid level',
        'icon'  => 'bx-user-check',
        'tone'  => 'secondary',
    ],
    [
        'label' => 'Before go-live',
        'value' => money($stats['baseline_total']),
        'hint'  => $baseRows . ' level' . ($baseRows === 1 ? '' : 's') . ' recorded, never paid',
        'icon'  => 'bx-time-five',
        'tone'  => 'warning',
    ],
    [
        'label' => 'Open liability',
        'value' => money($stats['open_amount']),
        'hint'  => (int) $stats['open_levels'] . ' level' . ((int) $stats['open_levels'] === 1 ? '' : 's')
            . ' across ' . (int) $stats['open_members'] . ' member'
            . ((int) $stats['open_members'] === 1 ? '' : 's') . ' — not yet paid',
        'icon'  => 'bx-error-circle',
        'tone'  => 'dark',
    ],
];
?>
              <div class="row g-4 mb-4" data-live-region="team-harvest-stats">
<?php foreach ($cards as $card): ?>
                <div class="col-6 col-md-4 col-xl-2">
                  <div class="card h-100 commission-stat-card">
                    <div class="card-body">
                      <div class="d-flex align-items-center justify-content-between mb-3">
                        <span class="avatar avatar-sm">
                          <span class="avatar-initial rounded bg-label-<?= e($card['tone']) ?>">
                            <i class="icon-base <?= e(icon_class($card['icon'])) ?>"></i>
                          </span>
                        </span>
                        <span class="text-body-secondary small text-end"><?= e($card['label']) ?></span>
                      </div>
                      <h4 class="mb-2 commission-stat-value"><?= e($card['value']) ?></h4>
                      <p class="text-body-secondary small mb-0"><?= e($card['hint']) ?></p>
                    </div>
                  </div>
                </div>
<?php endforeach; ?>
              </div>
