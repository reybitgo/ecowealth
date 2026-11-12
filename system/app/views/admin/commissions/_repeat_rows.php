<?php
declare(strict_types=1);

/**
 * Admin repeat-purchase ledger — rows (Phase 17D, plan §8).
 *
 * One ladder, levels 1–10: the `Lvl` column is the whole story and
 * there is no direct/indirect column (D-17). Every row links to the
 * order that produced it and to the recipient's wallet statement
 * filtered to that order, so a number is one click from both its
 * sources (plan §8 Rule 8).
 *
 * A `skipped` row shows the amount that was due and why it was not
 * paid; it never enters a credited total. A `voided` row shows how much
 * of the award the wallet could return (plan §8 Rules 5–6).
 *
 * @var list<array<string, mixed>> $awards
 * @var array{level:string, status:string, product_id:string, from:string, to:string, q:string} $filters
 */

$filters = $filters ?? UnilevelAward::filterDefaults();

$hasFilters = (string) $filters['level'] !== ''
    || (string) $filters['status'] !== ''
    || (string) $filters['product_id'] !== ''
    || (string) $filters['from'] !== ''
    || (string) $filters['to'] !== ''
    || (string) $filters['q'] !== '';
?>
                    <tbody data-live-region="repeat-rows">
<?php if ($awards === []): ?>
                      <tr>
                        <td colspan="11" class="py-0">
<?php
    echo View::partial('empty_state', [
        'icon'         => 'bx bx-gift',
        'title'        => $hasFilters ? 'No repeat-purchase awards match these filters' : 'No repeat-purchase bonus has been paid',
        'text'         => $hasFilters
            ? 'Try a different level, status, product or date range.'
            : 'Turn the repeat-purchase stream on in Settings and give a product a ladder. '
                . 'Only a paid order pays the ladder, and only when both switches are on.',
        'action_href'  => $hasFilters ? 'admin/commissions?ledger=repeat' : 'admin/settings',
        'action_label' => $hasFilters ? 'Clear filters' : 'Open settings',
    ]);
?>
                        </td>
                      </tr>
<?php endif; ?>
<?php foreach ($awards as $award): ?>
<?php
    $id       = (int) $award['id'];
    $status   = (string) $award['status'];
    $level    = (int) $award['level'];
    $amount   = (float) $award['amount'];
    $rate     = (float) $award['rate_pct'];
    $reason   = trim((string) ($award['reason'] ?? ''));
    $reversed = (float) ($award['reversed_amount'] ?? 0);
    $orderNo  = (string) ($award['order_no'] ?? '');
    $credited = $status === 'credited';
    $voided   = $status === 'voided';
    $shortfall = $voided ? round(max(0.0, $amount - $reversed), 2) : 0.0;
    $walletUrl = url('admin/wallets/' . (int) $award['user_id'] . '?q=' . rawurlencode($orderNo));
?>
                      <tr data-live-key="<?= $id ?>">
                        <td class="text-nowrap">
                          <span class="badge bg-label-secondary" title="Level <?= $level ?>">L<?= $level ?></span>
                        </td>
                        <td>
                          <a href="<?= e(url('admin/members/' . (int) $award['user_id'])) ?>" class="text-body fw-medium">
                            @<?= e((string) $award['recipient_username']) ?>
                          </a>
<?php if (trim((string) ($award['recipient_name'] ?? '')) !== ''): ?>
                          <div class="small text-body-secondary"><?= e((string) $award['recipient_name']) ?></div>
<?php endif; ?>
                          <div class="small text-body-secondary commission-meta-mobile">
                            <?= e($orderNo) ?>
                          </div>
                        </td>
                        <td>
                          <span class="fw-medium">@<?= e((string) $award['buyer_username']) ?></span>
                          <div class="small text-body-secondary"><?= e(date('M j, Y', (int) strtotime((string) $award['paid_at']))) ?></div>
                        </td>
                        <td>
                          <?= e((string) $award['product_name']) ?>
<?php if (trim((string) ($award['product_sku'] ?? '')) !== ''): ?>
                          <div class="small text-body-secondary"><?= e((string) $award['product_sku']) ?></div>
<?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap"><?= e(money((float) $award['base_amount'])) ?></td>
                        <td class="text-end text-nowrap"><?= e(number_format($rate, 3, '.', '')) ?>%</td>
                        <td class="text-end text-nowrap fw-semibold <?= $credited ? 'commission-amount-credit' : 'text-body-secondary' ?>">
                          <?= e($credited ? money($amount, true) : money($amount)) ?>
<?php if (!$credited): ?>
                          <div class="small text-body-secondary fw-normal">not paid</div>
<?php endif; ?>
                        </td>
                        <td>
                          <?= status_badge($status) ?>
<?php if ($status === 'skipped' && $reason !== ''): ?>
                          <div class="small text-body-secondary commission-reason"><?= e($reason) ?></div>
<?php endif; ?>
<?php if ($voided): ?>
                          <div class="small text-body-secondary commission-reason">
                            <?= e(money($reversed)) ?> recovered of <?= e(money($amount)) ?>
                          </div>
<?php if ($shortfall > 0): ?>
                          <div class="small text-danger"><?= e(money($shortfall)) ?> shortfall</div>
<?php endif; ?>
<?php endif; ?>
                        </td>
                        <td class="text-nowrap">
                          <a href="<?= e(url('admin/orders/' . (int) $award['order_id'])) ?>" class="text-body">
                            <?= e($orderNo) ?>
                          </a>
                          <div class="small"><?= status_badge((string) ($award['order_status'] ?? '')) ?></div>
                        </td>
                        <td class="text-nowrap">
                          <div><?= e(date('M j, Y', (int) strtotime((string) $award['created_at']))) ?></div>
                          <div class="small text-body-secondary"><?= e(date('g:i A', (int) strtotime((string) $award['created_at']))) ?></div>
                        </td>
                        <td class="text-end">
<?php if ($award['ledger_id'] !== null): ?>
                          <a
                            href="<?= e($walletUrl) ?>"
                            class="btn btn-sm btn-icon btn-label-secondary"
                            title="Open the wallet movement for this order"
                            aria-label="Open the wallet movement"
                          >
                            <i class="icon-base bx bx-wallet"></i>
                          </a>
<?php else: ?>
                          <span class="text-body-secondary small" title="No wallet movement">—</span>
<?php endif; ?>
                        </td>
                      </tr>
<?php endforeach; ?>
                    </tbody>
