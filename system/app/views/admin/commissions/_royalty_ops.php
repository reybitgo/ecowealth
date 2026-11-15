<?php
declare(strict_types=1);

/**
 * Admin Royalty ledger — operations panel (Phase 19F, plan §10).
 *
 * The one screen that says where the monthly stream stands: the open
 * month (and what it would pay if it closed now), the last final month,
 * the months still waiting to be closed, the months evaluated but not
 * paid, the two switches, the last close time and the command the next
 * run will use.
 *
 * **Read-only by design.** The close is the scheduled job (cron, the
 * CLI or the Windows launcher). This card never renders a form and
 * never triggers a run: an HTTP button would need CSRF, a long-running
 * request and a retry story, and a retry story for money is a bug.
 *
 * @var array<string, mixed>|null $ops     RoyaltyOps::snapshot(), or null
 * @var array<string, mixed>|null $preview the open month's projection
 * @var string $open_period
 */

$ops         = $ops ?? null;
$preview     = $preview ?? null;
$open_period = (string) ($open_period ?? '');

$monthLabel = static function (string $period): string {
    $ts = strtotime($period . '-01 12:00:00');

    return $ts === false ? $period : date('F Y', $ts);
};

$shortMonth = static function (string $period): string {
    $ts = strtotime($period . '-01 12:00:00');

    return $ts === false ? $period : date('M Y', $ts);
};

$openLabel = $open_period === '' ? '' : $monthLabel($open_period);

/* --- the state badge: one word for the whole stream --------------- */
$state = ['label' => 'Unavailable', 'tone' => 'secondary'];

if ($ops !== null) {
    if (($ops['evaluated'] ?? []) !== []) {
        $state = ['label' => 'Payment pending', 'tone' => 'warning'];
    } elseif (($ops['pending'] ?? []) !== []) {
        $state = ['label' => 'Close due', 'tone' => 'info'];
    } elseif (!(bool) ($ops['switches']['pay'] ?? true)) {
        $state = ['label' => 'Payments paused', 'tone' => 'warning'];
    } else {
        $state = ['label' => 'Up to date', 'tone' => 'success'];
    }
}

/* The open month's projection, stated as a projection. */
$previewEnabled = $preview !== null && (bool) ($preview['enabled'] ?? false);
$previewRows    = $preview === null ? 0 : (int) ($preview['paid_members'] ?? 0);
$previewTotal   = $preview === null ? 0.0 : (float) ($preview['award_total'] ?? 0);
$previewSkipped = $preview === null ? 0 : (int) ($preview['skipped'] ?? 0);

$next = $ops['next'] ?? null;
?>
              <div class="card mb-4" data-live-region="commission-royalty-ops">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <div>
                    <h5 class="mb-1">Operations</h5>
                    <p class="text-body-secondary small mb-0">
                      Where the monthly stream stands. Read-only — the close is the scheduled job, never this page.
                    </p>
                  </div>
                  <span class="badge bg-label-<?= e($state['tone']) ?>"><?= e($state['label']) ?></span>
                </div>
                <div class="card-body">
<?php if ($ops === null): ?>
                  <p class="text-body-secondary mb-0">
                    The operations snapshot is unavailable right now. The ledger below still shows every closed month.
                  </p>
<?php else: ?>
                  <div class="row g-3">
                    <div class="col-12 col-md-6 col-xl-3">
                      <div class="text-body-secondary small">Open month — not closable yet</div>
                      <div class="fw-semibold mb-1"><?= e($openLabel) ?></div>
<?php if ($preview === null): ?>
                      <div class="small text-body-secondary">The projection is unavailable right now.</div>
<?php elseif (!$previewEnabled): ?>
                      <div class="small text-body-secondary">
                        Royalty is off — it would close as skipped and pay nothing.
                      </div>
<?php else: ?>
                      <div class="small text-body-secondary">
                        If it closed now: <span class="text-body"><?= e(money($previewTotal)) ?></span>
                        across <?= $previewRows ?> award<?= $previewRows === 1 ? '' : 's' ?><?php if ($previewSkipped > 0): ?>,
                        <?= $previewSkipped ?> skipped<?php endif; ?>. Nothing is stored.
                      </div>
<?php endif; ?>
                    </div>

                    <div class="col-12 col-md-6 col-xl-3">
                      <div class="text-body-secondary small">Last final month</div>
<?php if (($ops['last_final'] ?? null) === null): ?>
                      <div class="fw-semibold mb-1">None yet</div>
                      <div class="small text-body-secondary">The first month closes after the first full month.</div>
<?php else: ?>
                      <div class="fw-semibold mb-1">
                        <?= e($shortMonth((string) $ops['last_final']['period'])) ?>
                        <?= status_badge((string) $ops['last_final']['status'], RoyaltyPeriod::STATUS_BADGES) ?>
                      </div>
                      <div class="small text-body-secondary">
                        <?= e(money((float) $ops['last_final']['paid_total'])) ?> paid ·
                        <?= (int) $ops['last_final']['award_count'] ?> award<?= (int) $ops['last_final']['award_count'] === 1 ? '' : 's' ?>
                      </div>
<?php endif; ?>
                    </div>

                    <div class="col-12 col-md-6 col-xl-3">
                      <div class="text-body-secondary small">Pending periods</div>
<?php if (($ops['pending'] ?? []) === []): ?>
                      <div class="fw-semibold mb-1">None</div>
                      <div class="small text-body-secondary">Every completed month has a period.</div>
<?php else: ?>
                      <div class="fw-semibold mb-1"><?= count($ops['pending']) ?> to close</div>
                      <div class="small text-body-secondary">
                        <?= e(implode(' · ', array_map($shortMonth, array_slice($ops['pending'], 0, 3)))) ?>
<?php if (count($ops['pending']) > 3): ?>
                        · +<?= count($ops['pending']) - 3 ?> more
<?php endif; ?>
                      </div>
<?php endif; ?>
                    </div>

                    <div class="col-12 col-md-6 col-xl-3">
                      <div class="text-body-secondary small">Awaiting payment</div>
<?php if (($ops['evaluated'] ?? []) === []): ?>
                      <div class="fw-semibold mb-1">None</div>
                      <div class="small text-body-secondary">No evaluated month is waiting for its payment.</div>
<?php else: ?>
                      <div class="fw-semibold mb-1">
                        <?= e(money((float) ($ops['counts']['pending_total'] ?? 0))) ?>
                        · <?= (int) ($ops['counts']['pending_rows'] ?? 0) ?> award<?= (int) ($ops['counts']['pending_rows'] ?? 0) === 1 ? '' : 's' ?>
                      </div>
                      <div class="small text-body-secondary">
<?php foreach ($ops['evaluated'] as $row): ?>
                        <div>
                          <?= e($shortMonth((string) $row['period'])) ?>:
                          <?= (int) $row['pending'] ?> pending<?php if ($row['age_hours'] !== null): ?>,
                          <?= (int) $row['age_hours'] ?>h old<?php endif; ?>
                        </div>
<?php endforeach; ?>
                        <code class="d-block mt-1"><?= e(RoyaltyOps::payCommand((string) $ops['evaluated'][0]['period'])) ?></code>
                      </div>
<?php endif; ?>
                    </div>
                  </div>

                  <hr class="my-3" />

                  <div class="row g-3 small">
                    <div class="col-12 col-md-4">
                      <span class="text-body-secondary">Royalty earning:</span>
                      <?php if ((bool) ($ops['switches']['earn'] ?? false)): ?>
                      <span class="badge bg-label-success">On</span>
                      <?php else: ?>
                      <span class="badge bg-label-secondary">Off</span>
                      <span class="text-body-secondary">— a close now records a skipped month.</span>
                      <?php endif; ?>
                    </div>
                    <div class="col-12 col-md-4">
                      <span class="text-body-secondary">Payments:</span>
                      <?php if ((bool) ($ops['switches']['pay'] ?? true)): ?>
                      <span class="badge bg-label-success">On</span>
                      <?php else: ?>
                      <span class="badge bg-label-warning">Paused</span>
                      <span class="text-body-secondary">— awards stay pending.</span>
                      <?php endif; ?>
                    </div>
                    <div class="col-12 col-md-4">
                      <span class="text-body-secondary">Last close:</span>
                      <?php if (($ops['last_close_at'] ?? null) === null): ?>
                      <span>never</span>
                      <?php else: ?>
                      <span><?= e(format_datetime((string) $ops['last_close_at'])) ?></span>
                      <?php endif; ?>
                    </div>
                  </div>

                  <p class="small text-body-secondary mb-0 mt-3">
<?php if ($next === null): ?>
                    <strong>Next run:</strong> nothing to close — the next scheduled run exits without writing.
<?php elseif ($next['action'] === 'pay'): ?>
                    <strong>Next run:</strong> pays
                    <span class="text-body"><?= e($shortMonth((string) $next['period'])) ?></span>, then closes any pending month.
<?php else: ?>
                    <strong>Next run:</strong> closes
                    <span class="text-body"><?= e($shortMonth((string) $next['period'])) ?></span>
                    (evaluates and pays it).
<?php endif; ?>
<?php if ($next !== null): ?>
                    Run one month by hand with
                    <code><?= e($next['action'] === 'pay'
                        ? RoyaltyOps::payCommand((string) $next['period'])
                        : RoyaltyOps::closeCommand((string) $next['period'])) ?></code>.
<?php endif; ?>
                  </p>
<?php endif; ?>
                </div>
                <div class="card-footer d-flex flex-wrap align-items-center justify-content-between gap-2 small text-body-secondary">
                  <span>
                    Schedule: <code>30 0 1 * * php bin/royalty_month_close.php --max-batches=20</code>
                    (after the unilevel close at 00:15).
                  </span>
                  <span class="text-nowrap">
                    <i class="icon-base bx bx-time-five me-1"></i>a closed month is never rewritten
                  </span>
                </div>
              </div>
