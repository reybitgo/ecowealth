<?php
declare(strict_types=1);

/**
 * Admin order page — the Product Binary bonus this order paid (22D, 22E).
 *
 * Read-only. Points and money are shown side by side and never added
 * together (plan §9 Rule 4). One row per award: `self` (one per order,
 * paid to the buyer) and `pair` (one per recipient node, `pair_count`
 * pairs at once).
 *
 * Four order states, told apart by the order row:
 *
 *   not paid            no money has been received, so nothing is due
 *   paid, no stamp      paid before 22D switched the stream on; it will
 *                       never pay retroactively (D-16)
 *   stamped, zero total processed but no eligible line / no position / the
 *                       stream was off; decided, nothing due
 *   stamped, > 0 total  processed; the awards (if any) are the ledger
 *
 * @var array<string, mixed> $ctx
 */

$order = $ctx['order'];

/* The controller builds this once for the page and its feed; a caller
 * that renders the card standalone (tests, a future print view) gets
 * the same shape derived here. */
$binary = $ctx['binary'] ?? [
    'awards'          => BinaryAward::forOrder((int) ($order['id'] ?? 0)),
    'processed_at'    => $order['binary_processed_at'] ?? null,
    'points'          => (int) ($order['binary_points'] ?? 0),
    'self_total'      => (float) ($order['binary_self_total'] ?? 0),
    'pair_total'      => (float) ($order['binary_pair_total'] ?? 0),
    'total'           => (float) ($order['binary_total'] ?? 0),
    'position'        => (string) ($order['binary_position'] ?? ''),
    'is_paid'         => Order::isPaid($order),
    'is_cancelled'    => (string) ($order['status'] ?? '') === 'cancelled'
        || ($order['cancelled_at'] ?? null) !== null,
    'status'          => (string) ($order['status'] ?? ''),
    'enabled'         => BinaryService::enabled(),
    'release_enabled' => setting_bool('binary_release_enabled', true),
];

$awards         = $binary['awards'];
$processedAt    = $binary['processed_at'];
$points         = (int) $binary['points'];
$selfTotal      = (float) $binary['self_total'];
$pairTotal      = (float) $binary['pair_total'];
$total          = (float) $binary['total'];
$position       = (string) $binary['position'];
$isPaid         = (bool) $binary['is_paid'];
$isCancelled    = (bool) ($binary['is_cancelled'] ?? false);
$status         = (string) $binary['status'];
$enabled        = (bool) $binary['enabled'];
$releaseEnabled = (bool) ($binary['release_enabled'] ?? true);
$orderNo        = (string) $order['order_no'];

$releasedTotal  = (float) ($binary['released_total'] ?? 0.0);
$pendingTotal   = (float) ($binary['pending_total'] ?? 0.0);
$cancelledTotal = (float) ($binary['cancelled_total'] ?? 0.0);
$nextReleaseAt  = $binary['next_release_at'] ?? null;

/* A standalone render derives the same totals from the rows it already
 * has, so the card never needs a second query (plan §19.1). */
if (!array_key_exists('released_total', $binary)) {
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
$dueAt = static function (array $award): ?int {
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
        : $timestamp + max(0, (int) ($award['hold_days'] ?? 0)) * 86400;
};
?>
                  <div class="card mb-6 order-binary-card" data-live-region="order-binary">
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <h5 class="mb-0">Product binary</h5>
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
                        Paid orders place product-binary points. This order is <code><?= e($status) ?></code>.
                      </p>
<?php elseif ($processedAt === null): ?>
                      <p class="text-body-secondary small mb-0">
                        This order was paid before the Product binary stream was switched on, so it
                        earned no bonus. Orders paid after the switch are awarded once, when they are paid.
                      </p>
<?php else: ?>
                      <p class="text-body-secondary small mb-0">
<?php if (!$enabled): ?>
                        No points were placed: the stream was off when this order was paid.
<?php else: ?>
                        No points were placed: no product on this order had binary on, the buyer has no
                        binary node, or no side was chosen.
<?php endif; ?>
                      </p>
<?php endif; ?>
<?php else: ?>
<?php if ($isCancelled && $cancelledTotal > 0.0): ?>
                      <div class="alert alert-secondary small mb-3" role="status">
                        <strong>No bonus released.</strong>
                        This order was cancelled before any product-binary bonus was released.
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
                        <table class="table table-sm align-middle mb-0 order-binary-table">
                          <thead>
                            <tr>
                              <th scope="col">Side</th>
                              <th scope="col">Recipient</th>
                              <th scope="col">Node</th>
                              <th scope="col" class="text-end">Points</th>
                              <th scope="col" class="text-end">Pairs</th>
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
    $awardKind   = (string) $award['kind'];
    $awardSide   = (string) ($award['side'] ?? '');
    $pairCount   = (int) ($award['pair_count'] ?? 0);
    $awardPoints = (int) ($award['points'] ?? 0);
    $ratePct     = (float) ($award['rate_pct'] ?? 0);
    $recipient   = (string) ($award['recipient_username'] ?? '');
?>
                            <tr>
                              <td class="text-nowrap"><?= $awardSide !== '' ? e(BinaryNode::SIDE_LABELS[$awardSide] ?? $awardSide) : '—' ?></td>
                              <td class="text-nowrap">
<?php if ($hasWallet): ?>
                                <a href="<?= e($walletUrl) ?>" class="text-body">
                                  @<?= e($recipient) ?>
                                </a>
<?php else: ?>
                                <span class="text-body">@<?= e($recipient) ?></span>
<?php endif; ?>
                              </td>
                              <td class="text-nowrap">
                                @<?= e($recipient) ?>
                                <span class="small text-body-secondary d-block">
                                  #<?= (int) ($award['user_id'] ?? 0) ?> &middot; side <?= e(BinaryNode::SIDE_LABELS[$awardSide] ?? $awardSide) ?>
                                </span>
                              </td>
                              <td class="text-end text-nowrap"><?= e(number_format($awardPoints, 0, '.', '')) ?> pts</td>
                              <td class="text-end text-nowrap">
                                <?php if ($awardKind === 'pair' && $pairCount > 0): ?>
                                  <?= $pairCount ?> pair<?= $pairCount !== 1 ? 's' : '' ?>
                                <?php else: ?>
                                  —
                                <?php endif; ?>
                              </td>
                              <td class="text-end text-nowrap"><?= e(number_format($ratePct, 3, '.', '')) ?>%</td>
                              <td class="text-end text-nowrap fw-semibold <?= $isCredited ? 'commission-amount-credit' : 'text-body-secondary' ?>">
                                <?= e($isCredited ? money($amount, true) : money($amount)) ?>
                              </td>
                              <td>
                                <?= status_badge($awardStatus, BinaryAward::STATUS_BADGES) ?>
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
                        Credited to the wallet. The rows above are what the release job paid.
<?php endif; ?>
                        One row per award: a self bonus on the buyer's own purchase and pair awards at
                        each node whose left and right points matched. Matched points are consumed and
                        carry forward forever.
                      </p>
<?php endif; ?>
<?php endif; ?>
                    </div>
                    <div class="card-footer small text-body-secondary d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <span>
                        Position <?= $position !== '' ? e(ucfirst($position)) : '—' ?> ·
                        Points <?= e(number_format($points, 0, '.', '')) ?> pts ·
                        Self <?= e(money($selfTotal)) ?> ·
                        Pairs <?= e(money($pairTotal)) ?>
                      </span>
                      <span>
                        Order <?= e($orderNo) ?> ·
                        processed <?= $processedAt === null ? '—' : e(format_datetime($processedAt)) ?>
                      </span>
                    </div>
                  </div>

<?php unset($order, $binary, $awards, $processedAt, $points, $selfTotal, $pairTotal, $total, $position, $isPaid, $isCancelled, $status, $enabled, $releaseEnabled, $orderNo, $releasedTotal, $pendingTotal, $cancelledTotal, $nextReleaseAt, $hasMoney, $hasVoided, $dueAt, $awardedTotal); ?>
