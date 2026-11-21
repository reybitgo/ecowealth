<?php
declare(strict_types=1);

/**
 * Admin commission ledger — card heading with the live row count.
 *
 * @var Paginator $pager
 */
?>
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3" data-live-region="commission-header">
                  <div>
                    <h5 class="mb-1">Commission ledger</h5>
                    <p class="text-body-secondary mb-0 small">
                      Awards are immutable; a correction is a new adjustment, never an edit.
                    </p>
                  </div>
                  <span class="badge bg-label-primary"><?= (int) $pager->total ?> row<?= $pager->total === 1 ? '' : 's' ?></span>
                </div>
