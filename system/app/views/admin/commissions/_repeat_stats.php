<?php
declare(strict_types=1);

/**
 * Admin repeat-purchase ledger — headline counters (Phase 17D, plan §8).
 *
 * Only `credited` rows count as money. `skipped` is an audit note, and
 * `unrecovered` is the part of a voided award the wallet could not
 * return — never hidden (plan §8 Rules 5–6).
 *
 * @var array{
 *   credited_total:float, this_month:float, credited:int, skipped:int,
 *   recipients:int, average:float, top_level:int, voided:int, unrecovered:float
 * } $stats
 */

$stats = array_merge([
    'credited_total' => 0.0, 'this_month' => 0.0, 'credited' => 0, 'skipped' => 0,
    'recipients' => 0, 'average' => 0.0, 'top_level' => 0, 'voided' => 0, 'unrecovered' => 0.0,
], $stats ?? []);

$cards = [
    [
        'label' => 'Total credited',
        'value' => money($stats['credited_total']),
        'hint'  => (int) $stats['recipients'] . ' member' . ($stats['recipients'] === 1 ? '' : 's') . ' paid',
        'icon'  => 'bx-gift',
        'tone'  => 'success',
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
        'hint'  => $stats['credited'] === 0 ? 'Nothing paid yet' : 'Average ' . money($stats['average']),
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
        'label' => 'Unrecovered',
        'value' => money($stats['unrecovered']),
        'hint'  => $stats['voided'] . ' voided award' . ($stats['voided'] === 1 ? '' : 's') . ' — shortfall after reversal',
        'icon'  => 'bx-error-circle',
        'tone'  => 'danger',
    ],
];
?>
              <div class="row g-4 mb-4" data-live-region="repeat-stats">
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
