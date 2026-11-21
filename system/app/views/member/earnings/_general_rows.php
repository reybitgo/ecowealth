<?php
declare(strict_types=1);

/**
 * Member General Harvest statement — the award rows (Phase 21F, plan §10,
 * Appendix C.3/C.7).
 *
 * One row per decided level the member has been paid, or had recorded
 * before go-live. A `credited` row links to its wallet movement
 * (`ref_type = general_harvest`, `ref_id` = the award id); a `baseline`
 * row has no movement, because nothing was credited (D-06).
 *
 * The statuses are the member-facing ones (Appendix C.7): a stored
 * `baseline` reads *Before go-live*, never the admin's vocabulary.
 *
 * @var list<array<string, mixed>> $awards
 * @var array{user_id:int, level:string, status:string, from:string, to:string, q:string} $filters
 * @var string $wallet_url
 */

$awards     = $awards ?? [];
$filters    = $filters ?? GeneralHarvestAward::filterDefaults();
$wallet_url = $wallet_url ?? url('wallet');

$hasFilters = (string) $filters['level'] !== ''
    || (string) $filters['status'] !== ''
    || (string) $filters['from'] !== ''
    || (string) $filters['to'] !== '';
?>
                    <tbody data-live-region="earnings-general-rows">
<?php if ($awards === []): ?>
                      <tr>
                        <td colspan="7" class="py-0">
<?php
    echo View::partial('empty_state', [
        'icon'  => 'bx bx-grid-alt',
        'title' => $hasFilters ? 'No level matches these filters' : 'No level has completed yet',
        'text'  => $hasFilters
            ? 'Try a different level, status or date range.'
            : 'Your first level needs the queue to reach 4 qualified members. Your blocks fill with '
              . 'whoever qualifies after you.',
        'action_href'  => $hasFilters ? 'earnings?ledger=general_harvest' : '',
        'action_label' => $hasFilters ? 'Clear filters' : '',
    ]);
?>
                        </td>
                      </tr>
<?php endif; ?>
<?php foreach ($awards as $award): ?>
<?php
    $id        = (int) $award['id'];
    $level     = (int) $award['level'];
    $status    = (string) $award['status'];
    $amount    = (float) $award['amount'];
    $paid      = $status === GeneralHarvestAward::STATUS_CREDITED;
    $ledgerId  = $award['ledger_id'] === null ? null : (int) $award['ledger_id'];
    $decidedAt = (string) ($award['credited_at'] ?? '');
    $walletHref = $wallet_url . '?ref_type=' . GeneralHarvestAward::REF_TYPE . '&ref_id=' . $id;
?>
                      <tr data-live-key="<?= $id ?>">
                        <td class="text-nowrap commission-col-date">
                          <div><?= e(format_datetime($decidedAt)) ?></div>
                        </td>
                        <td class="text-nowrap fw-medium">Level <?= $level ?></td>
                        <td class="text-end text-nowrap"><?= (int) $award['n_at_award'] ?></td>
                        <td class="text-end text-nowrap text-body-secondary"><?= (int) $award['threshold_n'] ?></td>
                        <td class="text-end text-nowrap fw-semibold <?= $paid ? 'commission-amount-credit' : '' ?>">
                          <?= e($paid ? money($amount, true) : money($amount)) ?>
<?php if (!$paid): ?>
                          <div class="small text-body-secondary fw-normal">not paid</div>
<?php endif; ?>
                        </td>
                        <td class="text-nowrap">
                          <?= status_badge($status, GeneralHarvestAward::STATUS_BADGES) ?>
                        </td>
                        <td class="text-end text-nowrap">
<?php if ($ledgerId !== null): ?>
                          <a
                            href="<?= e($walletHref) ?>"
                            class="btn btn-sm btn-icon btn-label-secondary"
                            title="Open the wallet movement (ledger #<?= $ledgerId ?>)"
                            aria-label="Open the wallet movement"
                          ><i class="icon-base bx bx-wallet"></i></a>
<?php else: ?>
                          <span class="text-body-secondary small" title="Recorded before go-live: no wallet movement">—</span>
<?php endif; ?>
                        </td>
                      </tr>
<?php endforeach; ?>
                    </tbody>
