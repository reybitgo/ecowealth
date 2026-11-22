<?php
declare(strict_types=1);

/**
 * Admin Product Binary ledger — card heading (Phase 22E, plan §9; Phase 23D
 * adds the Source column and source-aware stats).
 *
 * @var Paginator $pager
 */
?>
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3" data-live-region="binary-header">
                  <div>
                    <h5 class="mb-1">Product Binary ledger</h5>
                    <p class="text-body-secondary mb-0 small">
                      Every award a paid order or an approved package activation earned in the Binary
                      stream — a self award paid to the buyer or a pair award paid at a node. Credited,
                      pending, cancelled or voided. The Source column tells which stream paid each row.
                    </p>
                  </div>
                  <span class="badge bg-label-primary"><?= (int) $pager->total ?> row<?= $pager->total === 1 ? '' : 's' ?></span>
                </div>
