<?php
declare(strict_types=1);

/**
 * Admin Royalty ledger — rows (Phase 19D, plan §8 Rules 5–6).
 *
 * One row per member per closed month: the rank they were judged at,
 * the GS/PS the award was calculated from, the quota and the two rates,
 * the amount, the status and — for a skipped row — the stored reason
 * verbatim, plus the amount that would have been paid. A skipped row
 * stores 0.00 and never enters a paid total (plan §13.3); its
 * "would have been" is derived from the stored GS/PS/rates with the
 * same single rounding the engine uses.
 *
 * GS and PS can be large, so the money columns use tabular figures
 * (plan §8 Rule 6). Every amount is a peso amount with two decimals.
 *
 * @var list<array<string, mixed>> $awards
 * @var array{period:string, status:string, rank:string, q:string} $filters
 * @var bool $preview_mode
 * @var string $open_period
 */

$filters      = $filters ?? RoyaltyAward::filterDefaults();
$awards       = $awards ?? [];
$preview_mode = (bool) ($preview_mode ?? false);
$open_period  = (string) ($open_period ?? '');

$hasFilters = (string) $filters['status'] !== ''
    || (string) $filters['rank'] !== ''
    || (string) $filters['q'] !== '';

$rankTones = [
    RoyaltyRank::DIRECTOR   => 'success',
    RoyaltyRank::MANAGER    => 'warning',
    RoyaltyRank::SUPERVISOR => 'primary',
];

$statusBadges = [
    'pending' => ['Awaiting payment', 'warning'],
    'paid'    => ['Paid', 'success'],
    'skipped' => ['Skipped', 'secondary'],
];

$monthLabel = static function (string $period): string {
    $ts = strtotime($period . '-01 12:00:00');

    return $ts === false ? $period : date('M Y', $ts);
};
?>
                    <tbody data-live-region="commission-royalty-rows">
<?php if ($awards === []): ?>
                      <tr>
                        <td colspan="10" class="py-0">
<?php if ($preview_mode): ?>
<?php
    echo View::partial('empty_state', [
        'icon'  => 'bx bx-crown',
        'title' => 'This month is not closed yet',
        'text'  => 'The preview above shows what the close would pay. Nothing is stored until the monthly '
            . 'close job evaluates the month; a ledger row appears only after that.',
    ]);
?>
<?php else: ?>
<?php
    echo View::partial('empty_state', [
        'icon'         => 'bx bx-crown',
        'title'        => $hasFilters ? 'No Royalty awards match these filters' : 'No Royalty awards for this period',
        'text'         => $hasFilters
            ? 'Try a different rank, status or member.'
            : 'No Supervisor, Manager or Director was due in this month, or every one of them was '
                . 'below the group quota. A skipped member still gets a row — this period has none.',
        'action_href'  => $hasFilters ? 'admin/commissions?ledger=royalty' : 'admin/commissions?ledger=royalty&period=' . $filters['period'],
        'action_label' => $hasFilters ? 'Clear filters' : 'Open the Royalty tab',
    ]);
?>
<?php endif; ?>
                        </td>
                      </tr>
<?php endif; ?>
<?php foreach ($awards as $award): ?>
<?php
    $id       = (int) $award['id'];
    $status   = (string) $award['status'];
    $rank     = (int) $award['rank_level'];
    $amount   = (float) $award['amount'];
    $group    = (float) $award['group_volume'];
    $personal = (float) $award['personal_volume'];
    $groupPct = (float) $award['group_pct'];
    $personalPct = (float) $award['personal_pct'];
    $reason   = trim((string) ($award['reason'] ?? ''));

    /* The single rounding the engine uses: one combined amount. */
    $wouldBe = round($group * $groupPct / 100 + $personal * $personalPct / 100, 2);

    $walletUrl = url('admin/wallets/' . (int) $award['user_id']);
?>
                      <tr data-live-key="<?= $id ?>">
                        <td>
                          <a href="<?= e(url('admin/members/' . (int) $award['user_id'])) ?>" class="text-body fw-medium">
                            @<?= e((string) $award['username']) ?>
                          </a>
<?php if (trim((string) ($award['full_name'] ?? '')) !== ''): ?>
                          <div class="small text-body-secondary"><?= e((string) $award['full_name']) ?></div>
<?php endif; ?>
                          <div class="small text-body-secondary commission-meta-mobile">
                            <?= e($monthLabel((string) $award['period'])) ?>
                          </div>
                        </td>
                        <td class="text-nowrap">
                          <span class="badge bg-label-<?= e($rankTones[$rank] ?? 'secondary') ?>">
                            <?= e(RoyaltyRank::NAMES[$rank] ?? 'Rank ' . $rank) ?>
                          </span>
                        </td>
                        <td class="text-end commission-num"><?= e(money($group)) ?></td>
                        <td class="text-end commission-num"><?= e(money($personal)) ?></td>
                        <td class="text-end commission-num"><?= e(money((float) $award['quota'])) ?></td>
                        <td class="text-end commission-num small text-body-secondary">
                          <?= e(number_format($groupPct, 3, '.', '')) ?>% / <?= e(number_format($personalPct, 3, '.', '')) ?>%
                        </td>
                        <td class="text-end commission-num fw-semibold <?= $status === 'paid' ? 'commission-amount-credit' : '' ?>">
                          <?= e(money($amount, $status === 'paid')) ?>
<?php if ($status === 'skipped'): ?>
                          <div class="small text-body-secondary fw-normal">would have been <?= e(money($wouldBe)) ?></div>
<?php elseif ($status === 'pending'): ?>
                          <div class="small text-body-secondary fw-normal">not in a wallet yet</div>
<?php endif; ?>
                        </td>
                        <td>
                          <?= status_badge($status, $statusBadges) ?>
<?php if ($status === 'skipped' && $reason !== ''): ?>
                          <div class="small text-body-secondary commission-reason"><?= e($reason) ?></div>
<?php endif; ?>
                        </td>
                        <td class="text-end">
<?php if (($award['ledger_id'] ?? null) !== null): ?>
                          <a
                            href="<?= e($walletUrl) ?>"
                            class="btn btn-sm btn-icon btn-label-secondary"
                            title="Open the wallet movement (ledger #<?= (int) $award['ledger_id'] ?>)"
                            aria-label="Open the wallet movement"
                          >
                            <i class="icon-base bx bx-wallet"></i>
                          </a>
                          <div class="small text-body-secondary">#<?= (int) $award['ledger_id'] ?></div>
<?php else: ?>
                          <span class="text-body-secondary small" title="No wallet movement">—</span>
<?php endif; ?>
                        </td>
                        <td class="text-nowrap">
<?php if (($award['credited_at'] ?? null) !== null && (string) $award['credited_at'] !== ''): ?>
                          <div><?= e(format_date((string) $award['credited_at'])) ?></div>
                          <div class="small text-body-secondary">
                            <?= e(date('g:i A', (int) strtotime((string) $award['credited_at']))) ?>
                          </div>
<?php else: ?>
                          <span class="text-body-secondary small">—</span>
<?php endif; ?>
                        </td>
                      </tr>
<?php endforeach; ?>
                    </tbody>
