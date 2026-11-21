<?php
declare(strict_types=1);

/**
 * Member wallet — the "send funds" card (live region).
 *
 * The statement answers "what happened"; this card answers "what can I
 * do next" and how much of today's/week's transfer allowance is left.
 * The counters come from the same locked row a transfer decrements, so
 * the card can never advertise more headroom than the service allows.
 *
 * @var array<string, mixed> $limits
 */

$staff     = (bool) ($limits['staff'] ?? false);
$sentToday = (float) ($limits['sent_today'] ?? 0);
$sentWeek  = (float) ($limits['sent_this_week'] ?? 0);
$fee       = (float) ($limits['fee'] ?? 0);
$min       = (float) ($limits['min'] ?? 0);
$dailyLeft = $limits['daily_remaining'] ?? null;
$weekLeft  = $limits['weekly_remaining'] ?? null;
?>
              <div class="card mb-6 wallet-send-card" data-live-region="member-wallet-transfer">
                <div class="card-body">
                  <div class="d-flex flex-wrap align-items-center justify-content-between gap-4">
                    <div class="d-flex align-items-start gap-3">
                      <span class="avatar avatar-md flex-shrink-0">
                        <span class="avatar-initial rounded-circle bg-label-primary">
                          <i class="icon-base bx bx-paper-plane icon-md"></i>
                        </span>
                      </span>
                      <div>
                        <h5 class="mb-1">Send money to another member</h5>
                        <p class="text-body-secondary small mb-0">
<?php if ($staff): ?>
                          Staff transfers are free and exempt from the daily and weekly limits.
<?php else: ?>
                          Instant, password-confirmed and irreversible. Fee <?= e($fee > 0 ? money($fee) : 'none') ?>,
                          minimum <?= e(money($min)) ?>.
<?php endif; ?>
                        </p>
                      </div>
                    </div>
                    <div class="d-flex flex-wrap align-items-center gap-3">
<?php if (!$staff): ?>
                      <div class="small text-body-secondary">
                        <div>
                          <span class="fw-medium text-body"><?= e(money($sentToday)) ?></span> sent today
<?php if ($dailyLeft !== null): ?>
                          · <?= e(money((float) $dailyLeft)) ?> left
<?php endif; ?>
                        </div>
                        <div>
                          <span class="fw-medium text-body"><?= e(money($sentWeek)) ?></span> sent this week
<?php if ($weekLeft !== null): ?>
                          · <?= e(money((float) $weekLeft)) ?> left
<?php endif; ?>
                        </div>
                      </div>
<?php endif; ?>
                      <a href="<?= e(url('wallet/transfer')) ?>" class="btn btn-primary">
                        <i class="icon-base bx bx-paper-plane me-1"></i> Send funds
                      </a>
                    </div>
                  </div>
                </div>
              </div>
