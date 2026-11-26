<?php
declare(strict_types=1);

/**
 * Member dashboard — the Product binary tile (22F-S3, plan §14.3,
 * Appendix C.5).
 *
 * A live region: every payment bumps the member's dashboard surface, so
 * the left/right points and the earned total refresh in place. The tile
 * is a read-out — it has no form — and never names an upline or sponsor.
 *
 * @var array<string, mixed>|null $binary  BinaryService::totalsFor(), or null
 */

$binary = $binary ?? null;

if (!is_array($binary)) {
    return;
}

$streamOn = BinaryService::enabled();

if (!$streamOn) {
    return;
}

$userId = (int) (auth_user()['id'] ?? 0);
if ($userId <= 0) {
    return;
}

$node = BinaryNode::forUser($userId);

if ($node === null) {
    return;
}

$leftPoints  = (int) ($node['left_points']  ?? 0);
$rightPoints = (int) ($node['right_points'] ?? 0);
$pairPoints  = (int) setting_int('binary_pair_points', 1000);
$selfPct     = round((float) setting_float('binary_self_pct', 5.0), 3);

$matching = min($leftPoints, $rightPoints);
$nextPair = max(0, $pairPoints - $matching);

$selfTotal  = round((float) ($binary['self_total']  ?? 0), 2);
$pairTotal  = round((float) ($binary['pair_total']  ?? 0), 2);
$earned     = round($selfTotal + $pairTotal, 2);

$packagePairs   = (int) ($binary['package_pairs']   ?? 0);
$packagePoints  = (int) ($binary['package_points']  ?? 0);
$packageCredited= round((float) ($binary['package_credited'] ?? 0), 2);
$hasPackage     = $packagePairs > 0 || $packagePoints > 0 || $packageCredited > 0;

$canPair = $matching >= $pairPoints;
$pairValue = money(round($pairPoints * (float) setting_float('binary_pair_pct', 10.0) / 100, 2));
?>
<div class="row g-6 mb-6" data-live-region="dashboard-binary">
    <div class="col-12">
        <div class="card h-100">
            <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div class="d-flex align-items-center gap-2">
                    <h5 class="card-title mb-0">Product binary</h5>
                </div>
                <a href="<?= e(url('earnings?ledger=binary')) ?>" class="btn btn-sm btn-label-primary">
                    View statement
                </a>
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between text-body-secondary small mb-3">
                    <span><?= e((string) $leftPoints) ?> pts left</span>
                    <span><?= e((string) $rightPoints) ?> pts right</span>
                </div>
                <div class="progress mb-3" style="height:8px">
                    <div class="progress-bar <?= $canPair ? 'bg-success' : 'bg-info' ?>"
                         role="progressbar"
                         style="width: <?= e((string) min(100, (int) round(($matching / max(1, $pairPoints)) * 100))) ?>%"
                         aria-valuenow="<?= e((string) min(100, (int) round(($matching / max(1, $pairPoints)) * 100))) ?>"
                         aria-valuemin="0"
                         aria-valuemax="100"></div>
                </div>
                <div class="d-flex justify-content-between text-body-secondary small mb-1">
                    <span><?= e((string) $nextPair) ?> pts more for your next pair</span>
                    <span><?= e($pairValue) ?> per pair</span>
                </div>
                <div class="row g-3 mt-3">
                    <div class="col-6 col-lg-4">
                        <div class="small text-body-secondary">Your self bonus</div>
                        <div class="h5 mb-0 commission-num"><?= e(money($selfTotal)) ?></div>
                        <div class="small text-body-secondary"><?= e((string) $selfPct) ?>% of your purchase points</div>
                    </div>
                    <div class="col-6 col-lg-4">
                        <div class="small text-body-secondary">Your pairs</div>
                        <div class="h5 mb-0 commission-num"><?= e(money($pairTotal)) ?></div>
                        <div class="small text-body-secondary"><?= e((string) $pairPoints) ?> pts per side, per pair</div>
                    </div>
                    <div class="col-6 col-lg-4">
                        <div class="small text-body-secondary">Earned</div>
                        <div class="h5 mb-0 commission-num"><?= e(money($earned)) ?></div>
                        <div class="small text-body-secondary">Pending until released</div>
                    </div>
                </div>
<?php if ($hasPackage): ?>
                <hr class="my-3" />
                <div class="row g-3">
                    <div class="col-6 col-lg-4">
                        <div class="small text-body-secondary">Package pairs earned</div>
                        <div class="h5 mb-0 commission-num"><?= e((string) $packagePairs) ?></div>
                        <div class="small text-body-secondary"><?= e(money($packageCredited)) ?> credited</div>
                    </div>
                    <div class="col-6 col-lg-4">
                        <div class="small text-body-secondary">Package points placed</div>
                        <div class="h5 mb-0 commission-num"><?= e((string) $packagePoints) ?> pts</div>
                        <div class="small text-body-secondary">Team volume from your activations</div>
                    </div>
                </div>
<?php endif; ?>
            </div>
            <div class="card-footer small text-body-secondary">
                Points on both sides of your tree make a pair. You earn <?= e((string) $selfPct) ?>% of the
                points on every purchase you place.
            </div>
        </div>
    </div>
</div>
