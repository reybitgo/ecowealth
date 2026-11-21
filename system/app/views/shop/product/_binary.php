<?php
declare(strict_types=1);

/**
 * Product detail — the product-binary points panel (22F-S3, plan §14.3,
 * Appendix C.1).
 *
 * Absent when the stream is off, the product's switch is off, or the
 * product's P.V. is 0. Member copy is downward-only: the buyer's own
 * points and self percentage, never an upline or sponsor.
 *
 * @var array<string, mixed> $product
 */

$streamOn = BinaryService::enabled();
$enabled  = (int) ($product['binary_enabled'] ?? 0) === 1;
$pv       = (float) ($product['pv'] ?? 0);

if (!$streamOn || !$enabled || $pv <= 0) {
    return;
}

$unitPoints = (int) round($pv);
$selfPct    = round((float) setting_float('binary_self_pct', 5.0), 3);
?>
<div class="card mb-4 product-binary-panel" data-live-region="shop-product-binary">
    <div class="card-body">
        <div class="d-flex align-items-start gap-3">
            <span class="avatar avatar-sm flex-shrink-0">
                <span class="avatar-initial rounded-circle bg-label-primary">
                    <i class="icon-base bx bx-git-branch"></i>
                </span>
            </span>
            <div>
                <h6 class="mb-1">Product binary points</h6>
                <p class="text-body-secondary mb-2">
                    This product places <strong><?= e((string) $unitPoints) ?> pts</strong> per unit in the
                    binary tree.
                </p>
                <p class="mb-0 small text-body-secondary">
                    You earn <strong><?= e((string) $selfPct) ?>%</strong> of the points on your own
                    purchase when you place it left or right.
                </p>
            </div>
        </div>
    </div>
</div>
