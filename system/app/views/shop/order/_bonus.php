<?php
declare(strict_types=1);

/**
 * Member order — the repeat-purchase outcome card (Phase 17E, plan §9).
 *
 * The buyer sees the order's total only (Rule 8): which upline
 * received which amount is admin-only, the same rule as package
 * commissions. The figure comes from the order's stamp
 * (`unilevel_total`, recomputed after a reversal), so it always equals
 * what the ledger says is still credited.
 *
 * The card renders only for a paid, processed order whose total is
 * above zero. An order with no awards shows nothing — "no bonus"
 * needs no explanation on the buyer's page, and a pending order must
 * never imply money is already on its way.
 *
 * @var array<string, mixed> $order
 */

$processed = ($order['unilevel_processed_at'] ?? null) !== null;
$total     = round((float) ($order['unilevel_total'] ?? 0), 2);
$paid      = Order::isPaid($order);
$show      = $processed && $paid && $total > 0;
?>
                <div data-live-region="member-order-bonus">
<?php if ($show): ?>
                  <div class="card mb-6">
                    <div class="card-body">
                      <div class="d-flex align-items-start gap-3">
                        <span class="avatar avatar-sm flex-shrink-0">
                          <span class="avatar-initial rounded-circle bg-label-success">
                            <i class="icon-base bx bx-gift"></i>
                          </span>
                        </span>
                        <div>
                          <h6 class="mb-1">Repeat-purchase bonus</h6>
                          <p class="mb-2">
                            <strong><?= e(money($total)) ?></strong> went to your sponsor chain when this order
                            was paid.
                          </p>
                          <p class="text-body-secondary small mb-0">
                            The bonus is credited level by level to the uplines above you, when the order is
                            paid. Only the order's total is shown here; this money is not paid to you.
                          </p>
                        </div>
                      </div>
                    </div>
                  </div>
<?php endif; ?>
                </div>
