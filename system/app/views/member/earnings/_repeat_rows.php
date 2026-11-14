<?php
declare(strict_types=1);

/**
 * Member repeat-purchase statement — award rows (Phase 17E, plan §9).
 *
 * Each row is one level of one paid order line from the member's Team
 * chain. The buyer is named because the statement shows which Team
 * chain member's purchase paid it; the order number is plain text, not
 * a link — the order belongs to the buyer and is not the recipient's
 * to open (Rule 8, admin-only detail).
 *
 * A skipped row shows the amount that was due and why it was not
 * paid; a voided row shows how much of it was reversed. Neither is
 * ever counted as money.
 *
 * @var list<array<string, mixed>> $awards
 * @var array{level:string, status:string, product_id:string, from:string, to:string, q:string} $filters
 * @var string $statement_url
 * @var string $wallet_url
 * @var string $shop_url
 */

$filters = $filters ?? UnilevelAward::filterDefaults();

$hasFilters = (string) $filters['level'] !== ''
    || (string) $filters['status'] !== ''
    || (string) $filters['product_id'] !== ''
    || (string) $filters['from'] !== ''
    || (string) $filters['to'] !== ''
    || (string) $filters['q'] !== '';
?>
                    <tbody data-live-region="earnings-repeat-rows">
<?php if ($awards === []): ?>
                      <tr>
                        <td colspan="9" class="py-0">
<?php
    echo View::partial('empty_state', [
        'icon'         => 'bx bx-gift',
        'title'        => $hasFilters ? 'No awards match these filters' : 'No repeat-purchase bonus yet',
        'text'         => $hasFilters
            ? 'Try a different level, status, product or date range.'
            : 'When someone in your Team chain buys a product with a repeat-purchase ladder and the '
                . 'order is paid, your level\'s share is credited here. Your own purchase counts toward '
                . 'your monthly minimum, not your income.',
        'action_href'  => $hasFilters ? 'earnings?ledger=repeat' : 'shop',
        'action_label' => $hasFilters ? 'Clear filters' : 'Open the shop',
    ]);
?>
                        </td>
                      </tr>
<?php endif; ?>
<?php foreach ($awards as $award): ?>
<?php
    $id       = (int) $award['id'];
    $level    = (int) $award['level'];
    $status   = (string) $award['status'];
    $amount   = (float) $award['amount'];
    $rate     = (float) $award['rate_pct'];
    $reason   = trim((string) ($award['reason'] ?? ''));
    $reversed = (float) ($award['reversed_amount'] ?? 0);
    $credited = $status === 'credited';
    $orderNo  = (string) ($award['order_no'] ?? '');
?>
                      <tr data-live-key="<?= $id ?>">
                        <td class="text-nowrap commission-col-date">
                          <div><?= e(date('M j, Y', (int) strtotime((string) $award['created_at']))) ?></div>
                          <div class="small text-body-secondary"><?= e(date('g:i A', (int) strtotime((string) $award['created_at']))) ?></div>
                        </td>
                        <td class="commission-col-source">
                          <span class="fw-medium">@<?= e((string) $award['buyer_username']) ?></span>
<?php if (trim((string) ($award['buyer_name'] ?? '')) !== ''): ?>
                          <div class="small text-body-secondary"><?= e((string) $award['buyer_name']) ?></div>
<?php endif; ?>
                          <div class="small text-body-secondary commission-meta-mobile">
                            <?= e((string) $award['product_name']) ?>
                            · Level <?= $level ?>
                            · order <?= e($orderNo) ?>
                            · <?= e(date('M j, Y', (int) strtotime((string) $award['created_at']))) ?>
                          </div>
                        </td>
                        <td class="commission-col-package">
                          <?= e((string) $award['product_name']) ?>
<?php if (trim((string) ($award['product_sku'] ?? '')) !== ''): ?>
                          <div class="small text-body-secondary"><?= e((string) $award['product_sku']) ?></div>
<?php endif; ?>
                        </td>
                        <td class="text-nowrap">
                          <span class="badge bg-label-secondary">L<?= $level ?></span>
                        </td>
                        <td class="text-end text-nowrap commission-col-base"><?= e(money((float) $award['base_amount'])) ?></td>
                        <td class="text-end text-nowrap commission-col-rate"><?= e(number_format($rate, 3, '.', '')) ?>%</td>
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
<?php if ($status === 'voided'): ?>
                          <div class="small text-body-secondary commission-reason">
                            <?= e(money($reversed)) ?> reversed after the order was cancelled
                          </div>
<?php endif; ?>
                        </td>
                        <td class="text-nowrap commission-col-order">
                          <span class="text-body-secondary"><?= e($orderNo) ?></span>
                        </td>
                      </tr>
<?php endforeach; ?>
                    </tbody>
