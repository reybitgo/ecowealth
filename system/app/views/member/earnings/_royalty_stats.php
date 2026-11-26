<?php
declare(strict_types=1);

/**
 * Member Royalty statement — the five counters (Phase 19E, plan §9,
 * Appendix C.3/C.8).
 *
 * `this_period` is the newest closed month the member has a row for (a
 * member has at most one row per month, D-08). It is the only figure
 * here that describes a single month; the rest are the whole history, so
 * a member can tell "what did I earn last month" from "what have I ever
 * earned" without opening the table.
 *
 * `skipped` months read **Not paid** and never look like a bug: the row
 * below carries the stored reason and the group sales it was judged on
 * (Rule 4).
 *
 * @var array{
 *   rows:int, paid_all_time:float, paid_rows:int, pending_total:float,
 *   pending_rows:int, skipped_rows:int,
 *   last_paid:?array{period:string, amount:float},
 *   this_period:?array{period:string, rank:int, amount:float, status:string},
 *   enabled:bool, payments_enabled:bool
 * } $stats
 */

$stats = array_merge([
    'rows'             => 0,
    'paid_all_time'    => 0.0,
    'paid_rows'        => 0,
    'pending_total'    => 0.0,
    'pending_rows'     => 0,
    'skipped_rows'     => 0,
    'last_paid'        => null,
    'this_period'      => null,
    'enabled'          => false,
    'payments_enabled' => true,
], $stats ?? []);

$monthLabel = static function (string $period): string {
    $ts = $period === '' ? false : strtotime($period . '-01 12:00:00');

    return $ts === false ? $period : date('M Y', $ts);
};

$thisPeriod = $stats['this_period'];
$lastPaid   = $stats['last_paid'];

$statusLabel = $thisPeriod === null
    ? ''
    : (RoyaltyAward::MEMBER_STATUS_LABELS[(string) $thisPeriod['status']] ?? ucfirst((string) $thisPeriod['status']));

$cards = [
    [
        'label' => 'Paid all-time',
        'value' => money((float) $stats['paid_all_time']),
        'hint'  => (int) $stats['paid_rows'] === 0
            ? 'No Royalty paid yet'
            : (int) $stats['paid_rows'] . ' closed month' . ((int) $stats['paid_rows'] === 1 ? '' : 's') . ' paid into your e-wallet',
        'icon'  => 'bx-crown',
        'tone'  => 'success',
    ],
    [
        'label' => 'This period',
        'value' => $thisPeriod === null ? '—' : money((float) $thisPeriod['amount']),
        'hint'  => $thisPeriod === null
            ? 'No closed month yet'
            : $monthLabel((string) $thisPeriod['period']) . ' · ' . $statusLabel,
        'icon'  => 'bx-calendar-check',
        'tone'  => 'primary',
    ],
    [
        'label' => 'Awaiting payment',
        'value' => money((float) $stats['pending_total']),
        'hint'  => (int) $stats['pending_rows'] === 0
            ? 'Nothing evaluated and unpaid'
            : (int) $stats['pending_rows'] . ' month' . ((int) $stats['pending_rows'] === 1 ? '' : 's') . ' evaluated, not in your wallet yet',
        'icon'  => 'bx-time-five',
        'tone'  => 'warning',
    ],
    [
        'label' => 'Not paid',
        'value' => (string) (int) $stats['skipped_rows'],
        'hint'  => (int) $stats['skipped_rows'] === 0
            ? 'Every judged month paid'
            : 'Month' . ((int) $stats['skipped_rows'] === 1 ? '' : 's') . ' below the quota or with an inactive account',
        'icon'  => 'bx-block',
        'tone'  => 'secondary',
    ],
    [
        'label' => 'Last paid',
        'value' => $lastPaid === null ? '—' : money((float) $lastPaid['amount']),
        'hint'  => $lastPaid === null
            ? 'No payment yet'
            : 'For ' . $monthLabel((string) $lastPaid['period']),
        'icon'  => 'bx-wallet',
        'tone'  => 'info',
    ],
];
?>
              <div class="row g-6 mb-6" data-live-region="earnings-royalty-stats">
<?php foreach ($cards as $card): ?>
                <div class="col-sm-6 col-xl">
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
