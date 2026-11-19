<?php
declare(strict_types=1);

/**
 * Member Product binary statement — next-pair progress (22F, plan §10,
 * Appendix C.3).
 *
 * Shows how close the member's own node is to the next pair: points on
 * both sides are matched, the smaller side is the limiter. Every point
 * number carries pts; the pair value carries currency.
 *
 * @var int  $left_points
 * @var int  $right_points
 * @var int  $pair_points
 */

$pairPoints = max(1, (int) $pair_points);
$matching   = min((int) $left_points, (int) $right_points);
$percent    = $pairPoints > 0 ? min(100, (int) round(($matching / $pairPoints) * 100)) : 0;
$needLeft   = max(0, $pairPoints - (int) $left_points);
$needRight  = max(0, $pairPoints - (int) $right_points);
$pairRate   = (float) setting_float('binary_pair_pct', 10);
$pairValue  = money(round($pairPoints * $pairRate / 100, 2));
$canPair    = $matching >= $pairPoints;
?>
              <div class="card mb-4" data-live-region="earnings-binary-progress">
                <div class="card-body">
                  <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-body-secondary small">Your next pair</span>
                    <span class="fw-semibold"><?= e((string) $percent) ?>%</span>
                  </div>
                  <div class="progress mb-2" style="height:8px">
                    <div class="progress-bar <?= $canPair ? 'bg-success' : 'bg-info' ?>"
                         role="progressbar"
                         style="width: <?= e((string) $percent) ?>%"
                         aria-valuenow="<?= e((string) $percent) ?>"
                         aria-valuemin="0"
                         aria-valuemax="100"></div>
                  </div>
                  <div class="d-flex justify-content-between text-body-secondary small mb-1">
                    <span><?= e((string) $needLeft) ?> pts more on the left</span>
                    <span><?= e((string) $needRight) ?> pts more on the right</span>
                  </div>
                  <p class="text-body-secondary small mb-0">
                    <strong><?= e((string) $pairPoints) ?> pts on each side</strong> make one pair, paying
                    <strong><?= e($pairValue) ?></strong>.
                  </p>
                </div>
              </div>
