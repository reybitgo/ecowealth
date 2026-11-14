<?php
declare(strict_types=1);

/**
 * Admin order page — the repeat-purchase bonus this order paid (17D, 18).
 *
 * Read-only, and deliberately the only place an administrator sees
 * which upline received which amount: the member page shows the order
 * total only (plan §9 Rule 8).
 *
 * Phase 18 adds the escrow states. An award is `pending` at payment (no
 * wallet movement yet), `credited` once the release job has paid it, and
 * `cancelled` when the order was cancelled before release. The rows stay
 * visible even though the order is no longer paid — the audit trail is
 * the point (plan §19.2).
 *
 * Three order states, and the order row tells them apart:
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
    'awards'          => UnilevelAward::forOrder((int) ($order['id'] ?? 0)),
    'processed_at'    => $order['unilevel_processed_at'] ?? null,
    'total'           => (float) ($order['unilevel_total'] ?? 0),
    'is_paid'         => Order::isPaid($order),
    'is_cancelled'    => (string) ($order['status'] ?? '') === 'cancelled'
        || ($order['cancelled_at'] ?? null) !== null,
    'status'          => (string) ($order['status'] ?? ''),
    'enabled'         => UnilevelService::enabled(),
    'release_enabled' => UnilevelService::releaseEnabled(),
];

$awards         = $unilevel['awards'];
$processedAt    = $unilevel['processed_at'];
$total          = (float) $unilevel['total'];
$isPaid         = (bool) $unilevel['is_paid'];
$isCancelled    = (bool) ($unilevel['is_cancelled'] ?? false);
$status         = (string) $unilevel['status'];
$enabled        = (bool) $unilevel['enabled'];
$releaseEnabled = (bool) ($unilevel['release_enabled'] ?? UnilevelService::releaseEnabled());
$orderNo        = (string) $order['order_no'];

$releasedTotal  = (float) ($unilevel['released_total'] ?? 0.0);
$pendingTotal   = (float) ($unilevel['pending_total'] ?? 0.0);
$cancelledTotal = (float) ($unilevel['cancelled_total'] ?? 0.0);
$nextReleaseAt  = $unilevel['next_release_at'] ?? null;

/* A standalone render derives the same totals from the rows it already
 * has, so the card never needs a second query (plan §19.1). */
if (!array_key_exists('released_total', $unilevel)) {
    foreach ($awards as $award) {
        $amount = round((float) $award['amount'], 2);

        if ((string) $award['status'] === 'credited') {
            $releasedTotal = round($releasedTotal + $amount, 2);
        } elseif ((string) $award['status'] === 'pending') {
            $pendingTotal = round($pendingTotal + $amount, 2);
        } elseif ((string) $award['status'] === 'cancelled') {
            $cancelledTotal = round($cancelledTotal + $amount, 2);
        }
    }
}

$awardedTotal = round($releasedTotal + $pendingTotal, 2);
if ($awardedTotal <= 0.0) {
    $awardedTotal = $total;
}

$hasMoney  = $releasedTotal > 0.0 || $pendingTotal > 0.0;
$hasVoided = false;

foreach ($awards as $award) {
    if ((string) $award['status'] === 'voided') {
        $hasVoided = true;
        break;
    }
}

/** When a pending award is due: delivery (or completion) + its hold. */
$dueAt = static function (array $award): ?string {
    $delivered = $award['order_delivered_at'] ?? null;
    $completed = $award['order_completed_at'] ?? null;
    $base      = $delivered !== null && $delivered !== ''
        ? (string) $delivered
        : ($completed !== null && $completed !== '' ? (string) $completed : '');

    if ($base === '') {
        return null;
    }

    $timestamp = strtotime($base);

    return $timestamp === false
        ? null
        : date('Y-m-d H:i:s', $timestamp + max(0, (int) ($award['hold_days'] ?? 0)) * 86400);
};
?>
                  <div class="card mb-6 order-unilevel-card" data-live-region="order-unilevel">
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <h5 class="mb-0">Repeat-purchase bonus</h5>
<?php if ($isCancelled && $awards !== []): ?>
<?php if ($releasedTotal > 0.0): ?>
                      <span class="badge bg-label-success"><?= e(money($releasedTotal)) ?> released</span>
<?php else: ?>
                      <span class="badge bg-label-secondary">No bonus released</span>
<?php endif; ?>
<?php elseif ($hasMoney): ?>
<?php if ($releasedTotal > 0.0): ?>
                      <span class="badge bg-label-success"><?= e(money($releasedTotal)) ?> released</span>
<?php endif; ?>
<?php if ($pendingTotal > 0.0): ?>
                      <span class="badge bg-label-warning"><?= e(money($pendingTotal)) ?> pending release</span>
<?php endif; ?>
<?php else: ?>
                      <span class="badge bg-label-secondary">No bonus</span>
<?php endif; ?>
                    </div>
                    <div class="card-body">
<?php if ($awards === []): ?>
<?php if (!$isPaid): ?>
                      <p class="text-body-secondary small mb-0">
                        Paid orders earn the repeat-purchase bonus. This order is <code><?= e($status) ?></code>.
                      </p>
<?php elseif ($processedAt === null): ?>
                      <p class="text-body-secondary small mb-0">
                        This order was paid before the repeat-purchase stream was switched on, so it
                        earned no bonus. Orders paid after the switch are awarded once, when they are paid.
                      </p>
<?php else: ?>
                      <p class="text-body-secondary small mb-0">
<?php if (!$enabled): ?>
                        The repeat-purchase stream is switched off. This order is stamped as processed,
                        so turning the stream on later will not pay it retroactively.
<?php else: ?>
                        No bonus was due: no product on this order had an active ladder, or the buyer
                        has no sponsor chain to pay.
<?php endif; ?>
                      </p>
<?php endif; ?>
<?php else: ?>
<?php if ($isCancelled && $cancelledTotal > 0.0): ?>
                      <div class="alert alert-secondary small mb-3" role="status">
                        <strong>No bonus released.</strong>
                        This order was cancelled before any repeat-purchase bonus was released.
                      </div>
<?php elseif ($isCancelled && $hasVoided): ?>
                      <div class="alert alert-secondary small mb-3" role="status">
                        This order was cancelled after its bonus was released. The reversal is shown below.
                      </div>
<?php elseif (!$isPaid): ?>
                      <div class="alert alert-secondary small mb-3" role="status">
                        This order is <code><?= e($status) ?></code>. The rows below are the decisions
                        that were recorded when it was paid.
                      </div>
<?php endif; ?>
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
    $isCredited  = $awardStatus === 'credited';
    $isPending   = $awardStatus === 'pending';
    $isCancelledRow = $awardStatus === 'cancelled';
    $isVoided    = $awardStatus === 'voided';
    $hasWallet   = ($award['ledger_id'] ?? null) !== null;
    $walletUrl   = url('admin/wallets/' . (int) $award['user_id']);
    $rowDue      = $isPending ? $dueAt($award) : null;
    $holdDays    = max(0, (int) ($award['hold_days'] ?? 0));
?>
                            <tr>
                              <td class="text-nowrap">L<?= (int) $award['level'] ?></td>
                              <td class="text-nowrap">
<?php if ($hasWallet): ?>
                                <a href="<?= e($walletUrl) ?>" class="text-body">
                                  @<?= e((string) $award['recipient_username']) ?>
                                </a>
<?php else: ?>
                                <span class="text-body">@<?= e((string) $award['recipient_username']) ?></span>
<?php endif; ?>
                              </td>
                              <td><?= e((string) $award['product_name']) ?></td>
                              <td class="text-end text-nowrap"><?= e(money((float) $award['base_amount'])) ?></td>
                              <td class="text-end text-nowrap"><?= e(number_format((float) $award['rate_pct'], 3, '.', '')) ?>%</td>
                              <td class="text-end text-nowrap fw-semibold <?= $isCredited ? 'commission-amount-credit' : 'text-body-secondary' ?>">
                                <?= e($isCredited ? money($amount, true) : money($amount)) ?>
                              </td>
                              <td>
                                <?= status_badge($awardStatus, UnilevelAward::STATUS_BADGES) ?>
<?php if ($isPending): ?>
                                <div class="small text-body-secondary">
<?php if ($rowDue !== null): ?>
                                  Pending release — due <?= e(format_date($rowDue)) ?>
<?php else: ?>
                                  Pending release — after delivery + <?= $holdDays ?> day<?= $holdDays === 1 ? '' : 's' ?>
<?php endif; ?>
                                </div>
<?php elseif ($isCancelledRow): ?>
                                <div class="small text-body-secondary">no money moved</div>
<?php elseif ($isCredited && trim((string) ($award['credited_at'] ?? '')) !== ''): ?>
                                <div class="small text-body-secondary">
                                  Credited — <?= e(format_date((string) $award['credited_at'])) ?>
                                </div>
<?php elseif ($isVoided): ?>
                                <div class="small text-body-secondary">
                                  <?= e(money($reversed)) ?> recovered of <?= e(money($amount)) ?>
                                </div>
<?php if ($shortfall > 0): ?>
                                <div class="small text-danger"><?= e(money($shortfall)) ?> shortfall</div>
<?php endif; ?>
<?php elseif ($awardStatus === 'skipped' && $reason !== ''): ?>
                                <div class="small text-body-secondary"><?= e($reason) ?></div>
<?php endif; ?>
                              </td>
                            </tr>
<?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
<?php if ($isPaid && $pendingTotal > 0.0): ?>
                      <p class="small text-body-secondary mt-3 mb-0">
<?php if ($nextReleaseAt !== null): ?>
                        Next release <strong><?= e(format_date((string) $nextReleaseAt)) ?></strong>.
<?php else: ?>
                        Pending awards release after delivery plus the hold window.
<?php endif; ?>
<?php if (!$releaseEnabled): ?>
                        Release is currently paused by the administrator.
<?php endif; ?>
                      </p>
<?php else: ?>
                      <p class="small text-body-secondary mt-3 mb-0">
<?php if ($releasedTotal > 0.0): ?>
                        Credited to the sponsor chain. The rows above are what the release job paid.
<?php endif; ?>
                        One row per order line, recipient and level. A skipped level keeps its number —
                        no money is redirected. The buyer never receives a rebate.
                      </p>
<?php endif; ?>
<?php endif; ?>
                    </div>
                    <div class="card-footer small text-body-secondary d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <span>Awarded <?= e(money($awardedTotal)) ?> · Released <?= e(money($releasedTotal)) ?> · Pending <?= e(money($pendingTotal)) ?></span>
                      <span>Order <?= e($orderNo) ?> · processed <?= $processedAt === null ? '—' : e(format_datetime($processedAt)) ?></span>
                    </div>
                  </div>
