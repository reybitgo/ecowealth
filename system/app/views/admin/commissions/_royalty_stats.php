<?php
declare(strict_types=1);

/**
 * Admin Royalty ledger — headline counters (Phase 19D, plan §8, Appendix C.2).
 *
 * The seven cards describe the selected period (one award per member
 * per closed month); `lifetime_paid` is every closed month. The
 * current-month preview is a separate, clearly labelled panel: it is
 * not a period row and never enters a ledger total (plan §8 Rule 4).
 *
 * When the selected period **is** the open month, the strip is replaced
 * by the preview panel itself — an open month has no ledger rows and no
 * totals to count.
 *
 * @var array{
 *   period:string, period_status:?string, rows:int, paid_rows:int,
 *   pending_rows:int, skipped_rows:int, recipients:int, paid_total:float,
 *   pending_total:float, award_total:float, average:float, top_rank:?string,
 *   lifetime_paid:float
 * } $stats
 * @var array<string, mixed>|null $preview
 * @var bool $preview_mode
 * @var string $open_period
 */

$stats = array_merge([
    'period' => '', 'period_status' => null, 'rows' => 0, 'paid_rows' => 0,
    'pending_rows' => 0, 'skipped_rows' => 0, 'recipients' => 0,
    'paid_total' => 0.0, 'pending_total' => 0.0, 'award_total' => 0.0,
    'average' => 0.0, 'top_rank' => null, 'lifetime_paid' => 0.0,
], $stats ?? []);

$preview      = $preview ?? null;
$preview_mode = (bool) ($preview_mode ?? false);
$open_period  = (string) ($open_period ?? ($stats['period'] ?? ''));
$preview_url  = url('admin/commissions?ledger=royalty&period=' . rawurlencode($open_period));

$monthLabel = static fn (string $period): string => format_month($period, $period);

$previewEnabled = $preview !== null && (bool) ($preview['enabled'] ?? false);
$previewEligible = $preview === null ? 0 : (int) ($preview['eligible'] ?? 0);
$previewSkipped  = $preview === null ? 0 : (int) ($preview['skipped'] ?? 0);
$previewTotal    = $preview === null ? 0.0 : (float) ($preview['award_total'] ?? 0);
$previewMembers  = $preview === null ? 0 : (int) ($preview['paid_members'] ?? 0);

if ($preview_mode) {
    ?>
              <div class="card mb-6" data-live-region="commission-royalty-stats">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <div>
                    <h5 class="mb-1">If this month closed now</h5>
                    <p class="text-body-secondary small mb-0">
                      <?= e($monthLabel((string) $stats['period'])) ?> is still open. This is a read-only preview:
                      nothing here is stored and none of it enters a period total.
                    </p>
                  </div>
                  <span class="badge bg-label-info">Preview</span>
                </div>
                <div class="card-body">
<?php if ($preview === null): ?>
                  <p class="text-body-secondary mb-0">
                    The preview is unavailable right now. The ledger below still shows every closed month.
                  </p>
<?php else: ?>
<?php if (!$previewEnabled): ?>
                  <div class="alert alert-warning mb-0" role="status">
                    <strong>Royalty is currently off.</strong>
                    A close this month would record a skipped period and pay nothing.
                    Turn the stream on in Settings before the month ends if it should pay.
                  </div>
<?php else: ?>
                  <div class="d-flex flex-wrap align-items-baseline gap-2 mb-3">
                    <h3 class="mb-0"><?= e(money($previewTotal)) ?></h3>
                    <span class="text-body-secondary small">
                      across <?= $previewMembers ?> award<?= $previewMembers === 1 ? '' : 's' ?>
                      if the month closed now
                    </span>
                  </div>
                  <div class="row g-3">
                    <div class="col-6 col-md-3">
                      <div class="text-body-secondary small">Eligible members</div>
                      <div class="fw-semibold"><?= $previewEligible ?></div>
                    </div>
                    <div class="col-6 col-md-3">
                      <div class="text-body-secondary small">Would be paid</div>
                      <div class="fw-semibold"><?= $previewMembers ?></div>
                    </div>
                    <div class="col-6 col-md-3">
                      <div class="text-body-secondary small">Would be skipped</div>
                      <div class="fw-semibold"><?= $previewSkipped ?></div>
                    </div>
                    <div class="col-6 col-md-3">
                      <div class="text-body-secondary small">Month status</div>
                      <div class="fw-semibold">Open</div>
                    </div>
                  </div>
<?php if (!empty($preview['rank_counts']) && is_array($preview['rank_counts'])): ?>
                  <p class="small text-body-secondary mb-0 mt-3">
                    Rank counts at this instant:
<?php foreach (['member' => 'Members', 'leader' => 'Leaders', 'supervisor' => 'Supervisors', 'manager' => 'Managers', 'director' => 'Directors'] as $key => $label): ?>
                    <?= e($label) ?> <?= (int) ($preview['rank_counts'][$key] ?? 0) ?> ·
<?php endforeach; ?>
                    <span class="text-body-secondary">the same derivation the close would use.</span>
                  </p>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>
                </div>
                <div class="card-footer small text-body-secondary">
                  The month is evaluated and paid by the monthly close job, never by this page.
                  A closed month is never recalculated.
                </div>
              </div>
<?php
    return;
}
?>
              <div class="row g-6 mb-6" data-live-region="commission-royalty-stats">
<?php
$cards = [
    [
        'label' => 'Paid in this period',
        'value' => money($stats['paid_total']),
        'hint'  => (int) $stats['paid_rows'] . ' paid award' . ((int) $stats['paid_rows'] === 1 ? '' : 's') . ' in the wallets',
        'icon'  => 'bx-crown',
        'tone'  => 'success',
    ],
    [
        'label' => 'Awaiting payment',
        'value' => money($stats['pending_total']),
        'hint'  => (int) $stats['pending_rows'] . ' award' . ((int) $stats['pending_rows'] === 1 ? '' : 's') . ' evaluated, not paid yet',
        'icon'  => 'bx-time-five',
        'tone'  => 'warning',
    ],
    [
        'label' => 'This period',
        'value' => money($stats['award_total']),
        'hint'  => 'Paid plus awaiting payment',
        'icon'  => 'bx-calendar',
        'tone'  => 'primary',
    ],
    [
        'label' => 'Recipients',
        'value' => (string) $stats['recipients'],
        'hint'  => 'Members with an award row this period',
        'icon'  => 'bx-user-check',
        'tone'  => 'secondary',
    ],
    [
        'label' => 'Skipped',
        'value' => (string) $stats['skipped_rows'],
        'hint'  => 'Due but not paid — each row carries its reason',
        'icon'  => 'bx-block',
        'tone'  => 'warning',
    ],
    [
        'label' => 'Average award',
        'value' => money($stats['average']),
        'hint'  => 'Across this period\'s awards',
        'icon'  => 'bx-receipt',
        'tone'  => 'info',
    ],
    [
        'label' => 'Top rank',
        'value' => $stats['top_rank'] === null ? '—' : (string) $stats['top_rank'],
        'hint'  => 'Highest rank with a row this period',
        'icon'  => 'bx-medal',
        'tone'  => 'dark',
    ],
    [
        'label' => 'Paid all time',
        'value' => money($stats['lifetime_paid']),
        'hint'  => 'Every closed month',
        'icon'  => 'bx-wallet',
        'tone'  => 'success',
    ],
];
?>
<?php foreach ($cards as $card): ?>
                <div class="col-6 col-md-4 col-xl-3">
                  <?= View::partial('stat_card', $card) ?>
                </div>
<?php endforeach; ?>
              </div>

              <div class="alert alert-info d-flex flex-wrap align-items-center justify-content-between gap-2" role="status">
                <div class="small">
                  <strong>If this month closed now:</strong>
<?php if ($preview === null): ?>
                  the preview is unavailable right now.
<?php elseif (!$previewEnabled): ?>
                  Royalty is currently off — the month would close as skipped and pay nothing.
<?php else: ?>
                  <?= e(money($previewTotal)) ?> across <?= $previewMembers ?> award<?= $previewMembers === 1 ? '' : 's' ?>
                  · <?= $previewSkipped ?> skipped. Not part of this period's totals.
<?php endif; ?>
                </div>
                <a href="<?= e($preview_url) ?>" class="btn btn-sm btn-label-primary">Preview the open month</a>
              </div>
