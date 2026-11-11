<?php
declare(strict_types=1);

/**
 * Reports — reconciliation / health widget (plan §6.8).
 *
 * One row per invariant: wallets, commissions, awards, transfers,
 * withdrawals, order totals and stock. A failing row lists the first
 * five examples and links to the screen that can act on it; nothing
 * here repairs anything.
 *
 * @var array{ok:bool, checked_at:string, totals:array{checks:int,failing:int}, sections:list<array<string,mixed>>} $health
 */

$health = array_merge(
    [
        'ok'         => true,
        'checked_at' => '',
        'totals'     => ['checks' => 0, 'failing' => 0],
        'sections'   => [],
    ],
    $health ?? []
);
?>
              <div class="card h-100 report-health-card" data-live-region="report-health">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <div>
                    <h5 class="mb-1">Finance health</h5>
                    <p class="text-body-secondary small mb-0">
                      Every wallet, award, transfer, withdrawal, order and stock position reconciles against its source
                      (<code>bin/wallet_reconcile.php</code> covers the four money services).
                    </p>
                  </div>
                  <span class="badge rounded-pill bg-label-<?= $health['ok'] ? 'success' : 'danger' ?>">
                    <i class="icon-base <?= $health['ok'] ? 'bx-check-circle' : 'bx-error-circle' ?> me-1"></i>
                    <?= $health['ok'] ? 'All clear' : $health['totals']['failing'] . ' failing' ?>
                  </span>
                </div>
                <div class="card-body">
                  <ul class="list-unstyled report-health-list mb-0">
<?php foreach ($health['sections'] as $section): ?>
                    <li class="report-health-row<?= $section['ok'] ? '' : ' is-bad' ?>">
                      <div class="d-flex align-items-start gap-2">
                        <i class="icon-base bx <?= $section['ok'] ? 'bx-check-circle text-success' : 'bx-error text-danger' ?> mt-1"></i>
                        <div class="flex-grow-1">
                          <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                            <span class="fw-medium"><?= e((string) $section['label']) ?></span>
<?php if ((string) ($section['href'] ?? '') !== ''): ?>
                            <a href="<?= e(url((string) $section['href'])) ?>" class="small">Open</a>
<?php endif; ?>
                          </div>
                          <p class="text-body-secondary small mb-0"><?= e((string) $section['summary']) ?></p>
<?php if (!$section['ok'] && ($section['details'] ?? []) !== []): ?>
                          <ul class="report-health-details mb-0">
<?php foreach ($section['details'] as $detail): ?>
                            <li><?= e((string) $detail) ?></li>
<?php endforeach; ?>
                          </ul>
<?php endif; ?>
                        </div>
                      </div>
                    </li>
<?php endforeach; ?>
<?php if ($health['sections'] === []): ?>
                    <li class="text-body-secondary small">The checks have not run yet.</li>
<?php endif; ?>
                  </ul>
                </div>
                <div class="card-footer small text-body-secondary">
                  <?= (int) $health['totals']['checks'] ?> checks ·
                  last run <?= e((string) $health['checked_at']) ?> ·
                  <a href="<?= e(url('admin/audit')) ?>">Open the audit log</a>
                </div>
              </div>
