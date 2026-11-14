<?php
declare(strict_types=1);

/**
 * Member order — the repeat-purchase outcome card (Phase 17E, plan §9).
 *
 * Member copy is **Team chain directed**: the card states the order's
 * fact once (the total paid to the members above the buyer; which upline
 * got which amount stays admin-only, Rule 8) and then points the buyer
 * at their own earning side — the same ladder pays them when their Team
 * chain buys these products, provided they keep the monthly minimum and
 * stay Active.
 *
 * The figure comes from the order's stamp (`unilevel_total`, recomputed
 * after a reversal), so it always equals what the ledger says is still
 * credited. The card renders only for a paid, processed order whose
 * total is above zero: "no bonus" needs no explanation on the buyer's
 * page, and a pending order must never imply money is already on its
 * way.
 *
 * @var array<string, mixed> $order
 */

$processed = ($order['unilevel_processed_at'] ?? null) !== null;
$total     = round((float) ($order['unilevel_total'] ?? 0), 2);
$paid      = Order::isPaid($order);
$show      = $processed && $paid && $total > 0;
$statement = url('earnings?ledger=repeat');
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
                            <strong><?= e(money($total)) ?></strong> was paid to the members above you
                            when this order was paid.
                          </p>
                          <p class="text-body-secondary small mb-0">
                            Your <strong>Team chain</strong> is your earning side: when a member you
                            sponsored buys these products, the same ladder credits your level's share to
                            your e-wallet — Level 1 for your direct recruits, up to Level 10. Keep your
                            monthly minimum to stay Active.
                            <a href="<?= e($statement) ?>">See your Team chain statement</a>.
                          </p>
                        </div>
                      </div>
                    </div>
                  </div>
<?php endif; ?>
                </div>
