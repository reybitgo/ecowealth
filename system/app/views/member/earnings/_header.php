<?php
declare(strict_types=1);

/**
 * Member earnings statement — the card heading with the live row count.
 *
 * @var Paginator $pager
 */
?>
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3" data-live-region="earnings-header">
                  <div>
                    <h5 class="mb-1">Awards</h5>
                    <p class="text-body-secondary mb-0 small">
                      One row per activation, recipient and level — never paid twice.
                    </p>
                  </div>
                  <span class="badge bg-label-primary"><?= (int) $pager->total ?> award<?= $pager->total === 1 ? '' : 's' ?></span>
                </div>
