<?php
declare(strict_types=1);

/**
 * Member order — the product-binary outcome card (22F-S3, plan §14.8,
 * Appendix C.4).
 *
 * Member copy is downward-only: the buyer's own side, points, self
 * bonus and pairs. Never an upline, never a sponsor, never a team chain.
 *
 * @var array<string, mixed> $order
 */

$processed = ($order['binary_processed_at'] ?? null) !== null;
$paid      = Order::isPaid($order);
$points    = (int) ($order['binary_points'] ?? 0);
$side      = (string) ($order['binary_position'] ?? '');
$selfTotal = round((float) ($order['binary_self_total'] ?? 0), 2);
$pairTotal = round((float) ($order['binary_pair_total'] ?? 0), 2);
$pairCount = 0;

$awards = [];

if ($processed && $paid && $points > 0) {
    try {
        $awards = BinaryAward::forOrder((int) $order['id']);
    } catch (Throwable) {
        $awards = [];
    }

    foreach ($awards as $award) {
        if ((string) ($award['kind'] ?? '') === 'pair') {
            $pairCount += (int) ($award['pair_count'] ?? 0);
        }
    }
}

$showOutcome = $processed && $paid && $points > 0;
$showNotice  = !$processed && $side !== '' && $points > 0;

$statement = url('earnings?ledger=binary');
?>
<?php if ($showNotice): ?>
    <div data-live-region="member-order-binary">
        <div class="card mb-6">
            <div class="card-body">
                <div class="d-flex align-items-start gap-3">
                    <span class="avatar avatar-sm flex-shrink-0">
                        <span class="avatar-initial rounded-circle bg-label-info">
                            <i class="icon-base bx bx-git-branch"></i>
                        </span>
                    </span>
                    <div>
                        <h6 class="mb-1">Product binary</h6>
                        <p class="text-body-secondary mb-0">
                            This order places points when it is paid.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php elseif ($showOutcome): ?>
    <div data-live-region="member-order-binary">
        <div class="card mb-6">
            <div class="card-body">
                <div class="d-flex align-items-start gap-3">
                    <span class="avatar avatar-sm flex-shrink-0">
                        <span class="avatar-initial rounded-circle bg-label-success">
                            <i class="icon-base bx bx-git-branch"></i>
                        </span>
                    </span>
                    <div>
                        <h6 class="mb-1">Product binary</h6>
                        <p class="mb-2">
                            This order was placed on the
                            <strong><?= e(ucfirst($side)) ?></strong>
                            with <strong><?= e((string) $points) ?> pts</strong>.
                        </p>
                        <p class="mb-2">
                            You earn <strong><?= e(money($selfTotal)) ?></strong> on your own purchase.
                            <?= e((string) (int) $pairCount) ?> pair<?= (int) $pairCount === 1 ? '' : 's' ?>
                            <?= (int) $pairCount === 1 ? 'was' : 'were' ?> paid to you.
                        </p>
                        <p class="text-body-secondary small mb-0">
                            <a href="<?= e($statement) ?>">See your Product binary statement</a>.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
