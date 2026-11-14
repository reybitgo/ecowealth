<?php
declare(strict_types=1);

/**
 * Member order — the repeat-purchase outcome card (Phase 17E, plan §9;
 * Phase 18 escrow wording, plan §10.4, Appendix C.8).
 *
 * Member copy is **Team chain directed**: the card states the order's
 * fact once (the total earned by the members above the buyer; which
 * upline got which amount stays admin-only, Rule 8) and then points the
 * buyer at their own earning side — the same ladder pays them when their
 * Team chain buys these products, provided they keep the monthly minimum
 * and stay Active.
 *
 * Phase 18 changes the verb. The award is earned when the order is paid
 * but released to the upline's e-wallet only after the return window, so
 * the card says **reserved** while any award is pending and **was
 * released** once they are all credited (Appendix C.8). The estimated
 * date is printed only when the order was actually delivered — before
 * delivery the copy states the rule ("after the return window") and never
 * promises a date (plan §10.6).
 *
 * The figure comes from the order's stamp (`unilevel_total`, recomputed
 * after a cancellation), so it always equals the awarded total the
 * ledger holds. The card renders only for a paid, processed order whose
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

/* Which awards are still pending decides the wording. The rows carry the
 * snapshotted hold and the order's delivery stamps, so the estimate is
 * computed from the same data the release job uses (plan §7.1). */
$awards = [];

if ($show) {
    try {
        $awards = UnilevelAward::forOrder((int) $order['id']);
    } catch (Throwable) {
        /* An order page must still render if the awards table is
         * missing on an old schema; the copy below then falls back to
         * the released wording the stamp alone implies. */
        $awards = [];
    }
}

$pendingTotal  = 0.0;
$creditedTotal = 0.0;
$pendingRows   = [];

foreach ($awards as $award) {
    $status = (string) ($award['status'] ?? '');
    $amount = round((float) ($award['amount'] ?? 0), 2);

    if ($status === 'pending') {
        $pendingTotal += $amount;
        $pendingRows[] = $award;
    } elseif ($status === 'credited') {
        $creditedTotal += $amount;
    }
}

$pendingTotal  = round($pendingTotal, 2);
$creditedTotal = round($creditedTotal, 2);

/* The earliest promised release among the pending rows: delivery +
 * the row's own hold_days. No delivery yet means no date at all. */
$estimateTs = null;
$holdDays   = 0;

foreach ($pendingRows as $row) {
    $hold = max(0, (int) ($row['hold_days'] ?? 0));
    $holdDays = max($holdDays, $hold);

    $from = trim((string) ($row['order_delivered_at'] ?? ''));

    if ($from === '') {
        $from = trim((string) ($row['order_completed_at'] ?? ''));
    }

    if ($from === '') {
        continue;
    }

    $timestamp = strtotime($from . ' +' . $hold . ' day');

    if ($timestamp === false) {
        continue;
    }

    $estimateTs = $estimateTs === null ? $timestamp : min($estimateTs, $timestamp);
}

$estimate = $estimateTs === null ? null : date('Y-m-d H:i:s', $estimateTs);
$released = $pendingTotal <= 0;

/* The sentence's timing clause, in the order the money actually moves:
 * a known date once delivered, "being finalized" when it is already due,
 * and the snapshotted hold while the parcel is still in transit. */
$timingHtml = '';

if ($estimateTs !== null && $estimateTs > time()) {
    $timingHtml = ' (estimated <strong>' . e(format_date($estimate)) . '</strong>)';
} elseif ($estimateTs !== null) {
    $timingHtml = ' — the release is being finalized';
} elseif ($holdDays > 0) {
    $timingHtml = ' (delivery + <strong>' . $holdDays . ' days</strong>)';
}
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
<?php if ($released): ?>
                          <p class="mb-2">
                            <strong><?= e(money($total)) ?></strong> was released to the members above you when
                            the return window closed.
                          </p>
<?php else: ?>
                          <p class="mb-2">
                            <strong><?= e(money($total)) ?></strong> is reserved for the members above you. It is
                            released to their e-wallets after the return window<?= $timingHtml ?>.
<?php if ($creditedTotal > 0): ?>
                            <span class="d-block small text-body-secondary mt-1">
                              <?= e(money($creditedTotal)) ?> of it has already been released.
                            </span>
<?php endif; ?>
                          </p>
<?php endif; ?>
                          <p class="text-body-secondary small mb-0">
                            Your <strong>Team chain</strong> is your earning side: when a member you
                            sponsored buys these products, the same ladder earns your level's share for
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
