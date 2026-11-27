<?php
declare(strict_types=1);

/**
 * Admin Royalty ledger — card heading (Phase 19D, plan §8, Appendix C.2).
 *
 * The copy is Plan Appendix C.2 verbatim: one award per member per
 * closed month, calculated at the month close — never per order. The
 * period badge keeps the selected month and its state in view, so a
 * screenshot of the ledger carries the context.
 *
 * @var Paginator $pager
 * @var string $period
 * @var ?string $period_status
 * @var bool $preview_mode
 */

$pager         = $pager ?? new Paginator(0);
$period        = (string) ($period ?? '');
$period_status = $period_status ?? null;
$preview_mode  = (bool) ($preview_mode ?? false);

$monthLabel = static fn (string $period): string => format_month($period, $period);

$stateLabel = $preview_mode
    ? 'Not closed yet — preview'
    : RoyaltyPeriod::label($period_status === null ? null : (string) $period_status);

$tone = match ($preview_mode ? 'open' : (string) $period_status) {
    'paid'       => 'success',
    'evaluated'  => 'info',
    'skipped'    => 'secondary',
    default      => 'warning',
};
?>
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3" data-live-region="commission-royalty-header">
                  <div>
                    <h5 class="mb-1">Royalty ledger</h5>
                    <p class="text-body-secondary mb-0 small">
                      One award per member per closed month. Royalty is calculated from paid shop
                      orders at the month close — never per order.
                    </p>
                  </div>
                  <div class="d-flex flex-wrap align-items-center gap-2">
<?php if ($period !== ''): ?>
                    <span class="badge bg-label-<?= e($tone) ?> text-nowrap">
                      <?= e($monthLabel($period)) ?> · <?= e($stateLabel) ?>
                    </span>
<?php endif; ?>
                    <span class="badge bg-label-primary"><?= (int) $pager->total ?> row<?= $pager->total === 1 ? '' : 's' ?></span>
                  </div>
                </div>
