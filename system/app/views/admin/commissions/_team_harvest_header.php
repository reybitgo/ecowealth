<?php
declare(strict_types=1);

/**
 * Admin Team Harvest ledger — card heading (Phase 20E, plan §9, Appendix C.2).
 *
 * The copy is plan Appendix C.2: one row per level a member completed, and a
 * row marked **Before go-live** is recorded, not paid. The badges carry the
 * two facts a screenshot must keep: whether the queue has been seeded (no
 * position can exist before it has, D-04) and whether the stream is allowed
 * to write awards (D-09).
 *
 * Read-only: nothing on this card, and nothing behind it, writes an award, a
 * wallet row or a queue row (Rule 1).
 *
 * @var array<string, mixed> $stats
 * @var Paginator $pager
 * @var bool   $seeded
 * @var ?string $baseline
 * @var bool   $enabled
 */

$stats    = $stats ?? [];
$pager    = $pager ?? new Paginator(0);
$seeded   = (bool) ($seeded ?? false);
$baseline = $baseline ?? null;
$enabled  = (bool) ($enabled ?? false);
?>
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3" data-live-region="team-harvest-header">
                  <div>
                    <h5 class="mb-1">Team Harvest ledger</h5>
                    <p class="text-body-secondary mb-0 small">
                      One row per level a member completed. Rows marked
                      <span class="badge bg-label-secondary">Before go-live</span>
                      were already complete when the plan started: they are recorded, not paid.
                    </p>
                  </div>
                  <div class="d-flex flex-wrap align-items-center gap-2">
<?php if ($seeded): ?>
                    <span class="badge bg-label-success text-nowrap" title="Every position exists from the go-live seed">
                      Seeded<?= $baseline === null || $baseline === '' ? '' : ' · ' . e(format_datetime($baseline)) ?>
                    </span>
<?php else: ?>
                    <span class="badge bg-label-warning text-nowrap" title="Run bin/harvest_queue_seed.php">
                      Not seeded
                    </span>
<?php endif; ?>
                    <span class="badge bg-label-<?= $enabled ? 'primary' : 'secondary' ?> text-nowrap">
                      <?= $enabled ? 'Paying' : 'Switched off' ?>
                    </span>
                    <span class="badge bg-label-primary text-nowrap">
                      <?= (int) $pager->total ?> row<?= $pager->total === 1 ? '' : 's' ?>
                    </span>
                  </div>
                </div>
<?php if (!$seeded): ?>
                <div class="card-body border-bottom py-3">
                  <div class="alert alert-warning mb-0 py-2 small" role="status">
                    <strong>The queue is not seeded.</strong>
                    No position has been assigned, so no level can complete. Run
                    <code>bin/harvest_queue_seed.php</code> before enabling the bonus — nothing can pay until the queue exists.
                  </div>
                </div>
<?php endif; ?>
