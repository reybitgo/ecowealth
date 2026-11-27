<?php
declare(strict_types=1);

/**
 * Admin Royalty ledger — closed months (Phase 19D, plan §8 Rule 3,
 * Appendix C.2).
 *
 * `royalty_periods` is written only by the close job, and a closed
 * month is never rewritten (plan §2.5, D-13). Each row links back into
 * the ledger filtered to its month. The open month is **not** a row:
 * it is one labelled preview link in the footer (Rule 4), because a
 * preview has no header and never enters a total.
 *
 * @var list<array<string, mixed>> $periods
 * @var string $selected
 * @var string $open
 */

$periods  = $periods ?? [];
$selected = (string) ($selected ?? '');
$open     = (string) ($open ?? '');

$monthLabel = static function (string $period): string {
    $ts = strtotime($period . '-01 12:00:00');

    return $ts === false ? $period : date('M Y', $ts);
};

$openLabel = $open === '' ? '' : $monthLabel($open);
$openLink  = $open === '' ? '' : url('admin/commissions?ledger=royalty&period=' . rawurlencode($open));
?>
              <div class="card mb-6" data-live-region="commission-royalty-periods">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <div>
                    <h5 class="mb-1">Closed months</h5>
                    <p class="text-body-secondary small mb-0">
                      One award per member per closed month. A closed period is never recalculated.
                    </p>
                  </div>
                  <span class="badge bg-label-secondary"><?= count($periods) ?> closed</span>
                </div>
                <div class="table-responsive">
                  <table class="table table-sm align-middle mb-0">
                    <thead>
                      <tr>
                        <th scope="col">Month</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end">Awards</th>
                        <th scope="col" class="text-end">Paid</th>
                        <th scope="col" class="text-end">Skipped</th>
                        <th scope="col" class="text-end">Award total</th>
                        <th scope="col" class="text-end">Paid total</th>
                        <th scope="col" class="text-nowrap">Evaluated</th>
                        <th scope="col" class="text-nowrap">Paid at</th>
                      </tr>
                    </thead>
                    <tbody>
<?php foreach ($periods as $period): ?>
<?php
    $key    = (string) $period['period'];
    $status = (string) $period['status'];
    $href   = url('admin/commissions?ledger=royalty&period=' . rawurlencode($key));
?>
                      <tr<?= $key === $selected ? ' class="table-active"' : '' ?>>
                        <td class="text-nowrap fw-medium">
                          <a href="<?= e($href) ?>" class="text-body"><?= e($monthLabel($key)) ?></a>
                        </td>
                        <td><?= status_badge($status, RoyaltyPeriod::STATUS_BADGES) ?></td>
                        <td class="text-end"><?= (int) $period['award_count'] ?></td>
                        <td class="text-end"><?= (int) $period['paid_count'] ?></td>
                        <td class="text-end"><?= (int) $period['skipped_count'] ?></td>
                        <td class="text-end text-nowrap"><?= e(money((float) $period['award_total'])) ?></td>
                        <td class="text-end text-nowrap"><?= e(money((float) $period['paid_total'])) ?></td>
                        <td class="text-end small text-body-secondary text-nowrap">
                          <?= e(format_datetime((string) $period['evaluated_at'])) ?>
                        </td>
                        <td class="text-end small text-body-secondary text-nowrap">
                          <?= $period['paid_at'] === null || $period['paid_at'] === ''
                              ? '—'
                              : e(format_datetime((string) $period['paid_at'])) ?>
                        </td>
                      </tr>
<?php endforeach; ?>
<?php if ($periods === []): ?>
                      <tr>
                        <td colspan="9" class="py-0">
<?php
    echo View::partial('empty_state', [
        'compact'      => true,
        'icon'         => 'bx bx-calendar',
        'title'        => 'No Royalty periods yet',
        'text'         => 'The first period closes after the first full month following installation.'
            . ($open === '' ? '' : ' Until then, ' . $openLabel . ' is only a preview.'),
        'action_href'  => $open === '' ? '' : 'admin/commissions?ledger=royalty&period=' . rawurlencode($open),
        'action_label' => $open === '' ? '' : 'Preview ' . $openLabel,
    ]);
?>
                        </td>
                      </tr>
<?php endif; ?>
                    </tbody>
                  </table>
                </div>
                <div class="card-footer d-flex flex-wrap align-items-center justify-content-between gap-2 small text-body-secondary">
                  <span>
                    Closing a month is the monthly close job; this card reads the snapshots it leaves behind.
                  </span>
<?php if ($open !== ''): ?>
<?php if ($open === $selected): ?>
                  <span class="text-nowrap"><strong>Previewing <?= e($openLabel) ?></strong> — nothing is stored.</span>
<?php else: ?>
                  <a href="<?= e($openLink) ?>" class="text-nowrap">
                    If <?= e($openLabel) ?> closed now — preview
                  </a>
<?php endif; ?>
<?php endif; ?>
                </div>
              </div>
