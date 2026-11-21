<?php
declare(strict_types=1);

/**
 * Admin General Harvest ledger — card heading (Phase 21E-S1, plan §9,
 * Appendix C.2).
 *
 * One row per block a node completed. Rows marked **Before go-live** were
 * already full when the board was armed: they are recorded, not paid.
 * The badges show whether the board is armed and whether the stream is
 * allowed to write awards (D-09).
 *
 * Read-only: nothing on this card, and nothing behind it, writes an award,
 * a wallet row or a queue row (Rule 1).
 *
 * @var array<string, mixed> $stats
 * @var Paginator $pager
 * @var bool   $armed
 * @var bool   $enabled
 */

$stats = $stats ?? [];
$pager = $pager ?? new Paginator(0);
$armed = (bool) ($armed ?? false);
$enabled = (bool) ($enabled ?? false);
?>
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3" data-live-region="general-harvest-header">
                  <div>
                    <h5 class="mb-1">General Harvest bonus</h5>
                    <p class="text-body-secondary mb-0 small">
                      One row per block a node completed — the shared board pays across team lines,
                      never just within a member's own team. Rows marked
                      <span class="badge bg-label-secondary">Before go-live</span>
                      were already full when the board was armed: they are recorded, not paid.
                    </p>
                  </div>
                  <div class="d-flex flex-wrap align-items-center gap-2">
                    <span class="badge bg-label-<?= $armed ? 'success' : 'secondary' ?> text-nowrap" title="Whether the board has a go-live baseline">
                      <?= $armed ? 'Armed' : 'Not armed' ?>
                    </span>
                    <span class="badge bg-label-<?= $enabled ? 'primary' : 'secondary' ?> text-nowrap">
                      <?= $enabled ? 'Paying' : 'Switched off' ?>
                    </span>
                    <span class="badge bg-label-primary text-nowrap">
                      <?= (int) $pager->total ?> row<?= $pager->total === 1 ? '' : 's' ?>
                    </span>
                  </div>
                </div>
<?php if (!$armed): ?>
                <div class="card-body border-bottom py-3">
                  <div class="alert alert-warning mb-0 py-2 small" role="status">
                    <strong>The board is not armed.</strong>
                    No block can pay yet. Run
                    <code>bin/general_harvest_arm.php</code> first — nothing can pay until the board
                    has a baseline.
                  </div>
                </div>
<?php endif; ?>
