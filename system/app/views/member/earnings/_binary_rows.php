<?php
declare(strict_types=1);

/**
 * Member Product binary statement — award rows (22F, plan §10,
 * Appendix C.8).
 *
 * Each row is one award the member earned: their Self Bonus on their
 * own purchase, or a pair matched at their Left or Right node. The
 * buyer named is the member whose purchase produced it — downstream,
 * never an upline (plan §14.12, D-22). No recipient column: the viewer
 * is always the recipient.
 *
 * @var list<array<string, mixed>> $awards
 * @var array{kind:string, status:string, side:string, product_id:string, from:string, to:string, q:string, source:string} $filters
 * @var string $wallet_url
 * @var array{0:array{value:string,label:string}} $source_options
 */

$filters = $filters ?? BinaryAward::filterDefaults();
$sourceOptions = $source_options ?? [];

$hasFilters = (string) $filters['kind'] !== ''
    || (string) $filters['status'] !== ''
    || (string) $filters['side'] !== ''
    || (string) $filters['product_id'] !== ''
    || (string) $filters['from'] !== ''
    || (string) $filters['to'] !== ''
    || (string) $filters['q'] !== ''
    || (string) $filters['source'] !== '';

$releaseEnabled = setting_bool('binary_release_enabled', true);

/**
 * The row's explanatory line under the status pill (Appendix C.8).
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

    if ($status === 'voided') {
        $reversed = (float) ($award['reversed_amount'] ?? 0);

        return $reversed > 0
            ? 'Order cancelled after release — ' . money($reversed) . ' clawed back.'
            : 'Order cancelled after release — nothing recovered.';
    }

    if ($status !== 'pending') {
        return '';
    }

    if (!$releaseEnabled) {
        return 'Release is currently paused by the administrator.';
    }

    $holdDays = max(0, (int) ($award['hold_days'] ?? 0));
    $base     = trim((string) ($award['order_delivered_at'] ?? ''))
        ?: trim((string) ($award['order_completed_at'] ?? ''));

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

    return 'Available after ' . format_date($timestamp) . '';
};
?>
                    <tbody data-live-region="earnings-binary-rows">
<?php if ($awards === []): ?>
                      <tr>
                        <td colspan="11" class="py-0">
<?php
    echo View::partial('empty_state', [
        'icon'         => 'bx bx-git-branch',
        'title'        => $hasFilters ? 'No awards match these filters' : 'No Binary bonus awards yet',
        'text'         => $hasFilters
            ? 'Try a different kind, status, side, product, source or date range.'
            : 'Your Self Bonus and your team’s pairs appear here once an order is paid. The awards '
                . 'are released to your e-wallet after delivery and the hold window.',
        'action_href'  => $hasFilters ? 'earnings?ledger=binary' : 'shop',
        'action_label' => $hasFilters ? 'Clear filters' : 'Open the shop',
    ]);
?>
                        </td>
                      </tr>
<?php endif; ?>
<?php foreach ($awards as $award): ?>
<?php
    $id        = (int) $award['id'];
    $kind      = (string) $award['kind'];
    $status    = (string) $award['status'];
    $amount    = (float) $award['amount'];
    $isCredited = $status === 'credited';
    $isPending  = $status === 'pending';
    $orderNo   = (string) ($award['order_no'] ?? '');
    $buyer     = (string) ($award['buyer_username'] ?? '');
    $product   = (string) ($award['product_name'] ?? '');
    $side      = (string) ($award['side'] ?? '');
    $points    = (int) ($award['points'] ?? 0);
    $pairCount = (int) ($award['pair_count'] ?? 0);
    $rate      = (float) ($award['rate_pct'] ?? 0);
    $source    = (string) ($award['source'] ?? '');
    $note      = $rowNote($award);
?>
                      <tr data-live-key="<?= $id ?>">
                        <td class="text-nowrap commission-col-date">
                          <div><?= e(format_date((string) $award['created_at'])) ?></div>
                          <div class="small text-body-secondary"><?= e(format_time((string) $award['created_at'])) ?></div>
                        </td>
                        <td class="commission-col-kind">
                          <span class="badge bg-label-secondary text-capitalize"><?= e($kind) ?></span>
                        </td>
                        <td class="commission-col-buyer">
                          <span class="fw-medium">@<?= e($buyer) ?></span>
<?php if (trim((string) ($award['buyer_name'] ?? '')) !== ''): ?>
                          <div class="small text-body-secondary"><?= e((string) $award['buyer_name']) ?></div>
<?php endif; ?>
                        </td>
                        <td class="commission-col-product"><?= e($product) ?></td>
                        <td class="text-nowrap commission-col-side">
                          <span class="badge bg-label-secondary"><?= e(ucfirst($side)) ?></span>
                        </td>
                        <td class="text-end text-nowrap commission-col-points">
                          <span class="fw-semibold"><?= (string) $points ?></span> pts
                        </td>
<?php if ($kind === 'pair' && $pairCount > 0): ?>
                        <td class="text-end text-nowrap"><?= $pairCount ?> pair<?= $pairCount === 1 ? '' : 's' ?></td>
<?php else: ?>
                        <td class="text-end text-nowrap text-body-secondary">—</td>
<?php endif; ?>
                        <td class="text-end text-nowrap commission-col-rate"><?= e(number_format($rate, 3, '.', '')) ?>%</td>
                        <td class="text-end text-nowrap fw-semibold <?= $isCredited ? 'commission-amount-credit' : ($isPending ? 'text-info' : 'text-body-secondary') ?>">
                          <?= e($isCredited ? money($amount, true) : money($amount)) ?>
<?php if (!$isCredited): ?>
                          <div class="small text-body-secondary fw-normal"><?= $isPending ? 'not yet in your wallet' : 'not paid' ?></div>
<?php endif; ?>
                        </td>
                        <td>
                          <?= status_badge($status, BinaryAward::STATUS_BADGES) ?>
<?php if ($note !== ''): ?>
                          <div class="small text-body-secondary commission-reason"><?= e($note) ?></div>
<?php endif; ?>
                        </td>
                        <td class="text-nowrap commission-col-source">
                          <span class="badge bg-label-secondary text-capitalize">
                            <?= e($source === 'activation' ? 'Package activation' : ($source === 'order' ? 'Repeat purchase' : '—')) ?>
                          </span>
                        </td>
                        <td class="text-nowrap commission-col-order">
                          <span class="text-body-secondary"><?= e($orderNo) ?></span>
                        </td>
                      </tr>
<?php endforeach; ?>
                    </tbody>
