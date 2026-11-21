<?php
declare(strict_types=1);

/**
 * Admin wallet monitor — today's movement badges.
 *
 * @var array{credits_today:float, debits_today:float} $totals
 */
?>
                <div class="text-md-end" data-live-region="wallet-metrics">
                  <span class="badge bg-label-secondary me-1">Credits today <?= e(money((float) $totals['credits_today'])) ?></span>
                  <span class="badge bg-label-secondary">Debits today <?= e(money((float) $totals['debits_today'])) ?></span>
                </div>
