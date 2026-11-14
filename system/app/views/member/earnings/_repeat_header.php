<?php
declare(strict_types=1);

/**
 * Member repeat-purchase statement — the card heading (Phase 17E, plan §9).
 *
 * @var Paginator $pager
 */
?>
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3" data-live-region="earnings-repeat-header">
                  <div>
                    <h5 class="mb-1">Your repeat-purchase awards</h5>
                    <p class="text-body-secondary mb-0 small">
                      One row per paid order line, product and level. A level is credited only when
                      you are eligible and Active that month.
                    </p>
                  </div>
                  <span class="badge bg-label-primary"><?= (int) $pager->total ?> award<?= $pager->total === 1 ? '' : 's' ?></span>
                </div>
