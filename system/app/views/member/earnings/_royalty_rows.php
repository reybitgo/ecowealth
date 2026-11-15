<?php
declare(strict_types=1);

/**
 * Member Royalty statement — the monthly rows (Phase 19E, plan §9
 * Rules 4 and 6, Appendix C.3/C.8).
 *
 * One row per closed month the member was judged in: the rank they were
 * judged at, the group and personal sales the award was calculated from,
 * the quota and the two rates, the amount, the status and — for a month
 * that was not paid — the stored reason verbatim, with the group sales
 * it was judged on still in the row (Rule 4).
 *
 * The statuses are the member-facing ones (Appendix C.8): `pending` is
 * *Awaiting payment*, `paid` is *Paid* and `skipped` is *Not paid*. When
 * payments are paused a pending row says so explicitly and never reads
 * like a payment (Rule 6).
 *
 * Mobile: the money columns move out of the way on a phone and the
 * month cell keeps the rank it was judged at, so the row reads
 * top-to-bottom instead of scrolling sideways (Rule 9).
 *
 * @var list<array<string, mixed>> $awards
 * @var array{period:string, status:string, rank:string, q:string} $filters
 * @var bool $payments_enabled
 */

$awards           = $awards ?? [];
$filters          = $filters ?? RoyaltyAward::filterDefaults();
$paymentsEnabled  = (bool) ($payments_enabled ?? true);

$hasFilters = (string) $filters['period'] !== ''
    || (string) $filters['status'] !== ''
    || (string) $filters['rank'] !== '';

$monthLabel = static function (string $period): string {
    $ts = $period === '' ? false : strtotime($period . '-01 12:00:00');

    return $ts === false ? $period : date('F Y', $ts);
};

$rankTones = [
    RoyaltyRank::DIRECTOR   => 'success',
    RoyaltyRank::MANAGER    => 'warning',
    RoyaltyRank::SUPERVISOR => 'primary',
];
?>
                    <tbody data-live-region="earnings-royalty-rows">
<?php if ($awards === []): ?>
                      <tr>
                        <td colspan="9" class="py-0">
<?php
    echo View::partial('empty_state', [
        'icon'  => 'bx bx-crown',
        'title' => $hasFilters ? 'No Royalty month matches these filters' : 'No Royalty awards yet',
        'text'  => $hasFilters
            ? 'Try a different month, rank or status.'
            : 'Royalty is paid after the month closes. Your first month appears here once the close has '
                . 'evaluated it — the rank card above shows where this month stands.',
        'action_href'  => $hasFilters ? 'earnings?ledger=royalty' : '',
        'action_label' => $hasFilters ? 'Clear filters' : '',
    ]);
?>
                        </td>
                      </tr>
<?php endif; ?>
<?php foreach ($awards as $award): ?>
<?php
    $id          = (int) $award['id'];
    $status      = (string) $award['status'];
    $rank        = (int) $award['rank_level'];
    $amount      = (float) $award['amount'];
    $group       = (float) $award['group_volume'];
    $personal    = (float) $award['personal_volume'];
    $quota       = (float) $award['quota'];
    $groupPct    = (float) $award['group_pct'];
    $personalPct = (float) $award['personal_pct'];
    $reason      = trim((string) ($award['reason'] ?? ''));
    $creditedAt  = $award['credited_at'] ?? null;
    $hasPaidAt   = $creditedAt !== null && (string) $creditedAt !== '';
    $period      = (string) $award['period'];
?>
                      <tr data-live-key="<?= $id ?>">
                        <td class="text-nowrap">
                          <span class="fw-medium"><?= e($monthLabel($period)) ?></span>
                          <span class="small text-body-secondary d-block commission-meta-mobile">
                            <?= e(RoyaltyRank::NAMES[$rank] ?? 'Rank ' . $rank) ?>
                          </span>
                        </td>
                        <td class="text-nowrap">
                          <span class="badge bg-label-<?= e($rankTones[$rank] ?? 'secondary') ?>">
                            <?= e(RoyaltyRank::NAMES[$rank] ?? 'Rank ' . $rank) ?>
                          </span>
                        </td>
                        <td class="text-end commission-num commission-col-gs"><?= e(money($group)) ?></td>
                        <td class="text-end commission-num commission-col-ps"><?= e(money($personal)) ?></td>
                        <td class="text-end commission-num commission-col-quota"><?= e(money($quota)) ?></td>
                        <td class="text-end commission-num small text-body-secondary commission-col-rates">
                          <?= e(number_format($groupPct, 3, '.', '')) ?>% / <?= e(number_format($personalPct, 3, '.', '')) ?>%
                        </td>
                        <td class="text-end commission-num fw-semibold <?= $status === 'paid' ? 'commission-amount-credit' : '' ?>">
                          <?= e(money($amount, $status === 'paid')) ?>
<?php if ($status === 'pending'): ?>
                          <div class="small text-body-secondary fw-normal">
                            <?= $paymentsEnabled ? 'not in your wallet yet' : 'payment is paused' ?>
                          </div>
<?php elseif ($status === 'skipped'): ?>
                          <div class="small text-body-secondary fw-normal">no payment</div>
<?php endif; ?>
                        </td>
                        <td>
                          <?= status_badge($status, RoyaltyAward::STATUS_BADGES) ?>
<?php if ($status === 'skipped' && $reason !== ''): ?>
                          <div class="small text-body-secondary commission-reason">
                            <?= e(RoyaltyAward::reasonLabel($reason, $quota)) ?>
                          </div>
                          <div class="small text-body-secondary commission-reason">
                            Judged on <?= e(money($group)) ?> group / <?= e(money($personal)) ?> personal
                          </div>
<?php elseif ($status === 'pending' && !$paymentsEnabled): ?>
                          <div class="small text-body-secondary commission-reason">
                            Payment is paused; the award stays pending.
                          </div>
<?php elseif ($status === 'pending'): ?>
                          <div class="small text-body-secondary commission-reason">
                            Awaiting the next payment run.
                          </div>
<?php endif; ?>
                        </td>
                        <td class="text-nowrap commission-col-paid">
<?php if ($hasPaidAt): ?>
                          <div><?= e(format_date((string) $creditedAt)) ?></div>
                          <div class="small text-body-secondary">
                            <?= e(date('g:i A', (int) strtotime((string) $creditedAt))) ?>
                          </div>
<?php else: ?>
                          <span class="text-body-secondary small">—</span>
<?php endif; ?>
                        </td>
                      </tr>
<?php endforeach; ?>
                    </tbody>
