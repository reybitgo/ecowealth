<?php
declare(strict_types=1);

/**
 * Admin repeat-purchase ledger — card heading (Phase 17D, plan §8).
 *
 * @var Paginator $pager
 */
?>
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3" data-live-region="repeat-header">
                  <div>
                    <h5 class="mb-1">Repeat-purchase ledger</h5>
                    <p class="text-body-secondary mb-0 small">
                      Every award a paid shop order produced, credited, skipped or voided.
                      One row per order line, recipient and level.
                    </p>
                  </div>
                  <span class="badge bg-label-primary"><?= (int) $pager->total ?> row<?= $pager->total === 1 ? '' : 's' ?></span>
                </div>
