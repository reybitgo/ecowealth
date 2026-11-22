<?php
declare(strict_types=1);

/**
 * Member Product binary statement — the card heading with the live row
 * count (22F, plan §10, Appendix C.8).
 *
 * The heading names only the member's own awards: no upline, no sponsor,
 * no ancestor and no "team chain" (plan §14.12, D-22).
 *
 * @var Paginator $pager
 */
?>
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3" data-live-region="earnings-binary-header">
                  <div>
                    <h5 class="mb-1">Binary bonus</h5>
                    <p class="text-body-secondary mb-0 small">
                      Your own purchases place points on your left or your right team. A pair matches
                      1,000 points on each side and pays 10% of the matched value. You earn on your own
                      purchase too: 5% of its points.
                    </p>
                  </div>
                  <span class="badge bg-label-primary"><?= (int) $pager->total ?> award<?= $pager->total === 1 ? '' : 's' ?></span>
                </div>
