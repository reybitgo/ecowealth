<?php
declare(strict_types=1);

/**
 * Admin Product Binary ledger — rows (Phase 22E, plan §9).
 *
 * One row per (order, recipient, kind) decision: a `self` award paid to
 * the buyer on their own order, or a `pair` award paid at a node when
 * left and right volume match. Every row links to the order that
 * produced it and to the recipient's wallet movement (when released),
 * so a number is one click from both its sources.
 *
 * Phase 18's escrow states apply: `pending` is earned but not in the
 * wallet yet; `cancelled` was never paid and is not a shortfall;
 * `voided` was released then reversed, with the recovered amount shown
 * and any shortfall flagged.
 *
 * @var list<array<string, mixed>> $awards
 * @var array{kind:string, status:string, side:string, product_id:string, from:string, to:string, q:string} $filters
 */

$filters = $filters ?? BinaryAward::filterDefaults();

$hasFilters = (string) $filters['kind'] !== ''
    || (string) $filters['status'] !== ''
    || (string) $filters['side'] !== ''
    || (string) $filters['product_id'] !== ''
    || (string) $filters['from'] !== ''
    || (string) $filters['to'] !== ''
    || (string) $filters['q'] !== '';

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
                    <tbody data-live-region="binary-rows">
<?php if ($awards === []): ?>
                      <tr>
                        <td colspan="12" class="py-0">
<?php
    echo View::partial('empty_state', [
        'icon'         => 'bx bx-link-external',
        'title'        => $hasFilters ? 'No Product Binary awards match these filters' : 'No Product Binary has been paid',
        'text'         => $hasFilters
            ? 'Try a different kind, status, side, product or date range.'
            : 'Turn the stream on in Settings, flag a product with a P.V., and place it on the binary tree. '
                . 'Only a paid order pays the binary bonus, and only when both switches are on.',
        'action_href'  => $hasFilters ? 'admin/commissions?ledger=binary' : 'admin/settings',
        'action_label' => $hasFilters ? 'Clear filters' : 'Open settings',
    ]);
?>
                        </td>
                      </tr>
<?php endif; ?>
<?php foreach ($awards as $award): ?>
<?php
    $id        = (int) $award['id'];
    $status    = (string) $award['status'];
    $kind      = (string) $award['kind'];
    $amount    = (float) $award['amount'];
    $rate      = (float) $award['rate_pct'];
    $reason    = trim((string) ($award['void_reason'] ?? ''));
    $reversed  = (float) ($award['reversed_amount'] ?? 0);
    $orderNo   = (string) ($award['order_no'] ?? '');
    $credited  = $status === 'credited';
    $pending   = $status === 'pending';
    $cancelled = $status === 'cancelled';
    $voided    = $status === 'voided';
    $shortfall = $voided ? round(max(0.0, $amount - $reversed), 2) : 0.0;
    $holdDays  = max(0, (int) ($award['hold_days'] ?? 0));
    $rowDue    = $pending ? $dueAt($award) : null;
    $walletUrl = url('admin/wallets/' . (int) $award['user_id'] . '?q=' . rawurlencode($orderNo));
    $pairCount = (int) ($award['pair_count'] ?? 0);
    $pairLabel = $pairCount > 1 ? $pairCount . ' pairs' : ($pairCount === 1 ? '1 pair' : '');
?>
                      <tr data-live-key="<?= $id ?>">
                        <td class="text-nowrap">
                          <div><?= e(date('M j, Y', (int) strtotime((string) $award['created_at']))) ?></div>
                          <div class="small text-body-secondary"><?= e(date('g:i A', (int) strtotime((string) $award['created_at']))) ?></div>
                        </td>
                        <td>
                          <span class="badge bg-label-secondary"><?= e(ucfirst($kind)) ?></span>
<?php if ($pairLabel !== ''): ?>
                          <div class="small text-body-secondary fw-normal"><?= e($pairLabel) ?></div>
<?php endif; ?>
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
                          <?= e((string) ($award['product_name'] ?? '—')) ?>
                        </td>
                        <td class="text-end text-nowrap">
                          <?= e(ucfirst((string) ($award['side'] ?? '—'))) ?>
                        </td>
                        <td class="text-end text-nowrap"><?= e((string) (int) $award['points']) ?></td>
                        <td class="text-end text-nowrap"><?= e(number_format($rate, 3, '.', '')) ?>%</td>
                        <td class="text-end text-nowrap fw-semibold <?= $credited ? 'commission-amount-credit' : 'text-body-secondary' ?>">
                          <?= e($credited ? money($amount, true) : money($amount)) ?>
<?php if ($status === 'skipped'): ?>
                          <div class="small text-body-secondary fw-normal">not paid</div>
<?php endif; ?>
                        </td>
                        <td>
                          <?= status_badge($status, BinaryAward::STATUS_BADGES) ?>
<?php if ($pending): ?>
                          <div class="small text-body-secondary commission-reason">
<?php if ($rowDue !== null): ?>
                            due <?= e(format_date($rowDue)) ?>
<?php else: ?>
                            after delivery + <?= $holdDays ?> day<?= $holdDays === 1 ? '' : 's' ?>
<?php endif; ?>
                          </div>
<?php elseif ($cancelled): ?>
                          <div class="small text-body-secondary commission-reason">no money moved</div>
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
                        <td class="text-end">
<?php if (($award['ledger_id'] ?? null) !== null): ?>
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
