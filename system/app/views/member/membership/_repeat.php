<?php
declare(strict_types=1);

/**
 * Membership page — the repeat-purchase paragraph (Phase 17E, plan §9).
 *
 * Informational, never an action: the member cannot "become Active"
 * from a button, and there is no repeat-purchase form on this page
 * (QA17E-08). The paragraph states the two-clock rule in one sentence,
 * names the member's status for the current month, and repeats the
 * copy rule that matters most: the buyer is not a recipient.
 *
 * The region element is always rendered, even when the paragraph is
 * empty, so a live refresh can add it once an operator switches the
 * stream on without the client missing its region.
 *
 * @var array{totals:array<string, mixed>, status:array<string, mixed>, enabled:bool}|null $unilevel
 */

$unilevel = $unilevel ?? null;

$totals = is_array($unilevel) ? ($unilevel['totals'] ?? []) : [];
$status = is_array($unilevel) ? ($unilevel['status'] ?? []) : [];
$count  = (int) ($totals['count'] ?? 0);
$show   = is_array($unilevel) && ((bool) ($unilevel['enabled'] ?? false) || $count > 0);

$period      = (string) ($status['period'] ?? UnilevelCycle::periodOf());
$periodLabel = strtotime($period . '-01') === false ? $period : date('F Y', (int) strtotime($period . '-01'));
$active      = (bool) ($status['active'] ?? true);
$requires    = (bool) ($status['requires_active'] ?? true);
$credited    = (float) ($totals['credited'] ?? 0);
?>
              <div data-live-region="membership-repeat">
<?php if ($show): ?>
                <div class="card mb-4">
                  <div class="card-body">
                    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
                      <div>
                        <h6 class="mb-1">
                          <i class="icon-base bx bx-gift me-1"></i> Repeat-purchase bonus
                        </h6>
                        <p class="text-body-secondary small mb-0">
<?php if (!$requires): ?>
                          The monthly Active rule is currently turned off, so every eligible upline is paid
                          regardless of their purchases.
<?php elseif ($active): ?>
                          You are <strong>Active for <?= e($periodLabel) ?></strong>: a repeat-purchase level
                          that falls on you this month is paid into your e-wallet.
<?php else: ?>
                          You are <strong>not Active for <?= e($periodLabel) ?></strong>: a repeat-purchase
                          level that falls on you this month is skipped and never moved to another upline.
<?php endif; ?>
                          Your own purchases decide this — not your rank — and you do not earn on your own purchase.
                        </p>
                      </div>
                      <div class="text-end">
                        <span class="badge <?= $active ? 'bg-label-success' : 'bg-label-secondary' ?>">
                          <?= $active ? 'Active' : 'Not Active' ?> for <?= e($periodLabel) ?>
                        </span>
                        <div class="small text-body-secondary mt-2">
                          <?= e(money($credited)) ?> credited as an upline
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
<?php endif; ?>
              </div>
