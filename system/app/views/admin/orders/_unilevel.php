<?php
declare(strict_types=1);

/**
 * Admin order page — the repeat-purchase bonus this order paid (17D).
 *
 * Read-only, and deliberately the only place an administrator sees
 * which upline received which amount: the member page shows the order
 * total only (plan §9 Rule 8).
 *
 * Three states, and the order row tells them apart:
 *
 *   not paid            no money has been received, so nothing is due
 *   paid, no stamp      paid before 17C switched the stream on; it will
 *                       never pay retroactively (D-16)
 *   stamped             processed; the awards (if any) are the ledger
 *
 * @var array<string, mixed> $ctx
 */

$order = $ctx['order'];

/* The controller builds this once for the page and its feed; a caller
 * that renders the card standalone (tests, a future print view) gets
 * the same shape derived here. */
$unilevel = $ctx['unilevel'] ?? [
    'awards'       => UnilevelAward::forOrder((int) ($order['id'] ?? 0)),
    'processed_at' => $order['unilevel_processed_at'] ?? null,
    'total'        => (float) ($order['unilevel_total'] ?? 0),
    'is_paid'      => Order::isPaid($order),
    'status'       => (string) ($order['status'] ?? ''),
    'enabled'      => UnilevelService::enabled(),
];

$awards      = $unilevel['awards'];
$processedAt = $unilevel['processed_at'];
$total       = (float) $unilevel['total'];
$isPaid      = (bool) $unilevel['is_paid'];
$status      = (string) $unilevel['status'];
$enabled     = (bool) $unilevel['enabled'];
$orderNo     = (string) $order['order_no'];
?>
                  <div class="card mb-6 order-unilevel-card" data-live-region="order-unilevel">
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <h5 class="mb-0">Repeat-purchase bonus</h5>
<?php if ($isPaid && $processedAt !== null): ?>
                      <span class="badge bg-label-<?= $total > 0 ? 'success' : 'secondary' ?>">
                        <?= e(money($total)) ?> to the sponsor chain
                      </span>
<?php else: ?>
                      <span class="badge bg-label-secondary">No bonus</span>
<?php endif; ?>
                    </div>
                    <div class="card-body">
<?php if (!$isPaid): ?>
                      <p class="text-body-secondary small mb-0">
                        Paid orders earn the repeat-purchase bonus. This order is <code><?= e($status) ?></code>.
                      </p>
<?php elseif ($processedAt === null): ?>
                      <p class="text-body-secondary small mb-0">
                        This order was paid before the repeat-purchase stream was switched on, so it
                        earned no bonus. Orders paid after the switch are awarded once, when they are paid.
                      </p>
<?php elseif ($awards === []): ?>
                      <p class="text-body-secondary small mb-0">
<?php if (!$enabled): ?>
                        The repeat-purchase stream is switched off. This order is stamped as processed,
                        so turning the stream on later will not pay it retroactively.
<?php else: ?>
                        No bonus was due: no product on this order had an active ladder, or the buyer
                        has no sponsor chain to pay.
<?php endif; ?>
                      </p>
<?php else: ?>
                      <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0 order-unilevel-table">
                          <thead>
                            <tr>
                              <th scope="col">Lvl</th>
                              <th scope="col">Recipient</th>
                              <th scope="col">Product</th>
                              <th scope="col" class="text-end">Base</th>
                              <th scope="col" class="text-end">Rate</th>
                              <th scope="col" class="text-end">Amount</th>
                              <th scope="col">Status</th>
                            </tr>
                          </thead>
                          <tbody>
<?php foreach ($awards as $award): ?>
<?php
    $awardStatus = (string) $award['status'];
    $amount      = (float) $award['amount'];
    $reversed    = (float) ($award['reversed_amount'] ?? 0);
    $reason      = trim((string) ($award['reason'] ?? ''));
    $shortfall   = $awardStatus === 'voided' ? round(max(0.0, $amount - $reversed), 2) : 0.0;
?>
                            <tr>
                              <td class="text-nowrap">L<?= (int) $award['level'] ?></td>
                              <td class="text-nowrap">
                                <a href="<?= e(url('admin/wallets/' . (int) $award['user_id'])) ?>" class="text-body">
                                  @<?= e((string) $award['recipient_username']) ?>
                                </a>
                              </td>
                              <td><?= e((string) $award['product_name']) ?></td>
                              <td class="text-end text-nowrap"><?= e(money((float) $award['base_amount'])) ?></td>
                              <td class="text-end text-nowrap"><?= e(number_format((float) $award['rate_pct'], 3, '.', '')) ?>%</td>
                              <td class="text-end text-nowrap fw-semibold <?= $awardStatus === 'credited' ? 'commission-amount-credit' : 'text-body-secondary' ?>">
                                <?= e($awardStatus === 'credited' ? money($amount, true) : money($amount)) ?>
                              </td>
                              <td>
                                <?= status_badge($awardStatus) ?>
<?php if ($awardStatus === 'skipped' && $reason !== ''): ?>
                                <div class="small text-body-secondary"><?= e($reason) ?></div>
<?php endif; ?>
<?php if ($awardStatus === 'voided'): ?>
                                <div class="small text-body-secondary">
                                  <?= e(money($reversed)) ?> recovered of <?= e(money($amount)) ?>
                                </div>
<?php if ($shortfall > 0): ?>
                                <div class="small text-danger"><?= e(money($shortfall)) ?> shortfall</div>
<?php endif; ?>
<?php endif; ?>
                              </td>
                            </tr>
<?php endforeach; ?>
                          </tbody>
                          <tfoot>
                            <tr class="fw-semibold">
                              <td colspan="5">Credited to the sponsor chain</td>
                              <td class="text-end text-nowrap"><?= e(money($total)) ?></td>
                              <td></td>
                            </tr>
                          </tfoot>
                        </table>
                      </div>
                      <p class="small text-body-secondary mt-3 mb-0">
                        One row per order line, recipient and level. A skipped level keeps its number —
                        no money is redirected. The buyer never receives a rebate.
                      </p>
<?php endif; ?>
                    </div>
                    <div class="card-footer small text-body-secondary">
                      Order <?= e($orderNo) ?> · processed
                      <?= $processedAt === null ? '—' : e(format_datetime($processedAt)) ?>
                    </div>
                  </div>
