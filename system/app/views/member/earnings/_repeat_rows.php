<?php
declare(strict_types=1);

/**
 * Member repeat-purchase statement — award rows (Phase 17E, plan §9;
 * Phase 18 escrow rows, plan §10.3).
 *
 * Each row is one level of one paid order line from the member's Team
 * chain. The buyer is named because the statement shows which Team
 * chain member's purchase paid it; the order number is plain text, not
 * a link — the order belongs to the buyer and is not the recipient's
 * to open (Rule 8, admin-only detail).
 *
 * Phase 18 adds the escrow states and dates, so the member can tell
 * **when** an award becomes money (plan §10.3, Appendix C.7):
 *
 *   pending    "Pending release" — available after the delivery date
 *              plus the award's snapshotted hold, or after delivery +
 *              N days when the parcel is still in transit
 *   credited   "Credited" — the date the wallet was credited
 *   cancelled  "Cancelled before release" — no money was ever paid
 *
 * A skipped row shows the amount that was due and why it was not paid;
 * a voided row shows how much of it was reversed. Neither is ever
 * counted as money. The pill overrides are the model's
 * `STATUS_BADGES` map, never a change to the global `status_badge()`
 * map — order statuses use the same `pending`/`cancelled` keys.
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

$releaseEnabled = UnilevelService::releaseEnabled();

/**
 * The row's explanatory line under the status pill (Appendix C.7).
 *
 * @param array<string, mixed> $award
 */
$rowNote = static function (array $award) use ($releaseEnabled): string {
    $status = (string) ($award['status'] ?? '');

    if ($status === 'credited') {
        $creditedAt = trim((string) ($award['credited_at'] ?? ''));

        return $creditedAt === ''
            ? 'Credited to your e-wallet.'
            : 'Credited to your e-wallet on ' . format_date($creditedAt) . '.';
    }

    if ($status === 'cancelled') {
        return 'Order cancelled before release — no money was paid.';
    }

    if ($status !== 'pending') {
        return '';
    }

    if (!$releaseEnabled) {
        return 'Release is currently paused by the administrator.';
    }

    /* The snapshotted hold starts at delivery, not at payment (ESC-03),
     * so the promised date only exists once the order was delivered or
     * completed. */
    $holdDays = max(0, (int) ($award['hold_days'] ?? 0));
    $base     = trim((string) ($award['order_delivered_at'] ?? ''));

    if ($base === '') {
        $base = trim((string) ($award['order_completed_at'] ?? ''));
    }

    if ($base === '') {
        return 'Available after delivery + ' . $holdDays . ' days.';
    }

    $timestamp = strtotime($base . ' +' . $holdDays . ' day');

    if ($timestamp === false) {
        return 'Available after delivery + ' . $holdDays . ' days.';
    }

    if ($timestamp <= time()) {
        return 'Being finalized — it is added on the next release run.';
    }

    return 'Available after ' . format_date(date('Y-m-d H:i:s', $timestamp)) . '.';
};
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
                . 'order is paid, your level\'s share is earned here. It is released to your e-wallet after '
                . 'the return window. Your own purchase counts toward your monthly minimum, not your income.',
        'action_href'  => $hasFilters ? 'earnings?ledger=repeat' : 'shop',
        'action_label' => $hasFilters ? 'Clear filters' : 'Open the shop',
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
    $rate      = (float) $award['rate_pct'];
    $reason    = trim((string) ($award['reason'] ?? ''));
    $reversed  = (float) ($award['reversed_amount'] ?? 0);
    $isCredited = $status === 'credited';
    $isPending  = $status === 'pending';
    $orderNo   = (string) ($award['order_no'] ?? '');
    $note      = $rowNote($award);
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
                        <td class="text-end text-nowrap fw-semibold <?= $isCredited ? 'commission-amount-credit' : ($isPending ? 'text-info' : 'text-body-secondary') ?>">
                          <?= e($isCredited ? money($amount, true) : money($amount)) ?>
<?php if (!$isCredited): ?>
                          <div class="small text-body-secondary fw-normal"><?= $isPending ? 'not yet in your wallet' : 'not paid' ?></div>
<?php endif; ?>
                        </td>
                        <td>
                          <?= status_badge($status, UnilevelAward::STATUS_BADGES) ?>
<?php if ($status === 'skipped' && $reason !== ''): ?>
                          <div class="small text-body-secondary commission-reason"><?= e($reason) ?></div>
<?php elseif ($status === 'voided'): ?>
                          <div class="small text-body-secondary commission-reason">
                            <?= e(money($reversed)) ?> reversed after the order was cancelled
                          </div>
<?php elseif ($note !== ''): ?>
                          <div class="small text-body-secondary commission-reason"><?= e($note) ?></div>
<?php endif; ?>
                        </td>
                        <td class="text-nowrap commission-col-order">
                          <span class="text-body-secondary"><?= e($orderNo) ?></span>
                        </td>
                      </tr>
<?php endforeach; ?>
                    </tbody>
