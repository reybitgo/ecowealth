<?php
declare(strict_types=1);

/**
 * Send funds — the daily and weekly limit meters (live region).
 *
 * The counters are server-side truth: they only move when a transfer
 * completes, so the meters can never disagree with a refused transfer.
 * A null remaining means "no limit" (a staff account or a limit setting
 * of 0, which this build treats as unlimited).
 *
 * @var array<string, mixed> $limits
 */

$staff    = (bool) ($limits['staff'] ?? false);
$meters   = [
    [
        'label'     => 'Sent today',
        'sent'      => (float) ($limits['sent_today'] ?? 0),
        'limit'     => (float) ($limits['daily_limit'] ?? 0),
        'remaining' => $limits['daily_remaining'] ?? null,
        'percent'   => (float) ($limits['daily_percent'] ?? 0),
    ],
    [
        'label'     => 'Sent this week',
        'sent'      => (float) ($limits['sent_this_week'] ?? 0),
        'limit'     => (float) ($limits['weekly_limit'] ?? 0),
        'remaining' => $limits['weekly_remaining'] ?? null,
        'percent'   => (float) ($limits['weekly_percent'] ?? 0),
    ],
];
?>
              <div class="card mb-6 wallet-limit-card" data-live-region="member-transfer-limits">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <div>
                    <h5 class="mb-1">Transfer limits</h5>
                    <p class="text-body-secondary small mb-0">
                      Limits reset daily at midnight; the weekly counter resets every Monday.
                    </p>
                  </div>
<?php if ($staff): ?>
                  <span class="badge bg-label-warning">Exempt — staff account</span>
<?php endif; ?>
                </div>
                <div class="card-body">
                  <div class="row g-4">
<?php foreach ($meters as $meter): ?>
<?php
    $unlimited = $meter['remaining'] === null;
    $tone      = $meter['percent'] >= 100 ? 'danger' : ($meter['percent'] >= 80 ? 'warning' : 'primary');
?>
                    <div class="col-12 col-md-6">
                      <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="fw-medium"><?= e($meter['label']) ?></span>
                        <span class="small text-body-secondary">
<?php if ($unlimited): ?>
                          <?= e(money($meter['sent'])) ?> sent · no limit
<?php else: ?>
                          <?= e(money($meter['sent'])) ?> of <?= e(money($meter['limit'])) ?>
<?php endif; ?>
                        </span>
                      </div>
                      <div
                        class="progress wallet-limit-meter"
                        role="progressbar"
                        aria-label="<?= e($meter['label']) ?>"
                        aria-valuenow="<?= (int) round($meter['percent']) ?>"
                        aria-valuemin="0"
                        aria-valuemax="100"
                      >
                        <div
                          class="progress-bar bg-<?= e($unlimited ? 'secondary' : $tone) ?>"
                          style="width: <?= $unlimited ? 0 : e((string) max(1, min(100, $meter['percent']))) ?>%"
                        ></div>
                      </div>
                      <div class="small text-body-secondary mt-2">
<?php if ($unlimited): ?>
                        No limit applies to this account.
<?php else: ?>
                        <?= e(money((float) $meter['remaining'])) ?> left <?= $meter['label'] === 'Sent today' ? 'today' : 'this week' ?>
<?php endif; ?>
                      </div>
                    </div>
<?php endforeach; ?>
                  </div>
                </div>
              </div>
