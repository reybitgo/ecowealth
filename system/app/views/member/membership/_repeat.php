<?php
declare(strict_types=1);

/**
 * Membership page — the repeat-purchase paragraph (Phase 17E, plan §9;
 * Phase 18 escrow copy, plan §10.5).
 *
 * Informational, never an action: the member cannot "become Active"
 * from a button, and there is no repeat-purchase form on this page
 * (QA17E-08). The paragraph states the two-clock rule in one sentence,
 * names the member's status for the current month, and keeps the copy
 * Team chain directed — the member's own purchases keep them Active;
 * the income comes from their Team chain's paid orders.
 *
 * Phase 18 shows the earned figure as **credited + pending release**
 * (`total_awarded`), because an award is earned at payment and only the
 * cash event waits for the return window. The two-clock Active
 * explanation is unchanged: the escrow changes when money reaches the
 * wallet, never when a month counts.
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

$count        = (int) ($totals['count'] ?? 0);
$credited     = round((float) ($totals['credited'] ?? 0), 2);
$pending      = round((float) ($totals['pending'] ?? 0), 2);
$pendingCount = (int) ($totals['pending_count'] ?? 0);
$earned       = round((float) ($totals['total_awarded'] ?? ($credited + $pending)), 2);
$show         = is_array($unilevel) && ((bool) ($unilevel['enabled'] ?? false) || $count + $pendingCount > 0);

$period      = (string) ($status['period'] ?? UnilevelCycle::periodOf());
$periodLabel = format_month($period, $period);
$active      = (bool) ($status['active'] ?? true);
$requires    = (bool) ($status['requires_active'] ?? true);
?>
              <div data-live-region="membership-repeat">
<?php if ($show): ?>
                <div class="card mb-6">
                  <div class="card-body">
                    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
                      <div>
                        <h6 class="mb-1">
                          <i class="icon-base bx bx-gift me-1"></i> Repeat-purchase bonus
                        </h6>
                        <p class="text-body-secondary small mb-0">
<?php if (!$requires): ?>
                          The monthly Active rule is currently turned off, so every eligible member is paid
                          regardless of their purchases.
<?php elseif ($active): ?>
                          You are <strong>Active for <?= e($periodLabel) ?></strong>: a
                          <strong>Team chain</strong> purchase that reaches your level this month pays your
                          share into your e-wallet.
<?php else: ?>
                          You are <strong>not Active for <?= e($periodLabel) ?></strong>: a
                          <strong>Team chain</strong> purchase that reaches your level this month is skipped
                          and never moved to another member.
<?php endif; ?>
                          Your own purchases decide this — not your rank — and they keep you Active so your
                          Team chain's purchases can pay you.
                        </p>
                      </div>
                      <div class="text-end">
                        <span class="badge <?= $active ? 'bg-label-success' : 'bg-label-secondary' ?>">
                          <?= $active ? 'Active' : 'Not Active' ?> for <?= e($periodLabel) ?>
                        </span>
                        <div class="small text-body-secondary mt-2">
                          <?= e(money($earned)) ?> earned from your Team chain
                        </div>
<?php if ($pending > 0): ?>
                        <div class="small text-body-secondary mt-1">
                          <?= e(money($credited)) ?> credited
                          + <?= e(money($pending)) ?> pending release.
                          Pending amounts become withdrawable after the return window.
                        </div>
<?php endif; ?>
                      </div>
                    </div>
                  </div>
                </div>
<?php endif; ?>
              </div>
