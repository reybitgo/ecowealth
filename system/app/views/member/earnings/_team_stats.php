<?php
declare(strict_types=1);

/**
 * Member Team Harvest statement — the five counters (Phase 20F, plan §10,
 * Appendix C.3).
 *
 * Reads `TeamHarvestService::statusFor()` and nothing else, so the numbers
 * here, the matrix below and the admin panel can never disagree (Rule 9).
 *
 * `Earned` is the **credited** money only: a `baseline` level is recorded
 * and never paid, so it has its own counter and never inflates Earned
 * (Rule 8, D-07).
 *
 * @var array<string, mixed> $team_harvest TeamHarvestService::statusFor()
 */

$team_harvest = $team_harvest ?? [];

$queued     = (bool) ($team_harvest['queued'] ?? false);
$position   = $team_harvest['position'] ?? null;
$qualified  = $team_harvest['qualified_at'] ?? null;
$teammates  = (int) ($team_harvest['teammates'] ?? 0);
$paidAmount = (float) ($team_harvest['paid_amount'] ?? 0);
$paidLevels = (int) ($team_harvest['credited_levels'] ?? 0);
$baseAmount = (float) ($team_harvest['baseline_amount'] ?? 0);
$baseLevels = (int) ($team_harvest['baseline_levels'] ?? 0);
$next       = is_array($team_harvest['next'] ?? null) ? $team_harvest['next'] : null;

$cards = [
    [
        'label' => 'Earned',
        'value' => money($paidAmount),
        'hint'  => $paidLevels === 0
            ? 'No level paid yet'
            : $paidLevels . ' level' . ($paidLevels === 1 ? '' : 's') . ' paid into your e-wallet',
        'icon'  => 'bx-trophy',
        'tone'  => 'success',
    ],
    [
        'label' => 'Harvest position',
        'value' => $position === null ? '—' : '#' . (int) $position,
        'hint'  => $position === null
            ? 'Not qualified yet'
            : 'Qualified ' . format_date((string) $qualified),
        'icon'  => 'bx-hash',
        'tone'  => 'primary',
    ],
    [
        'label' => 'Teammates',
        'value' => (string) $teammates,
        'hint'  => $teammates === 0
            ? 'Qualified members below you start counting when they qualify after you'
            : 'Qualified members below you count once they qualified after you',
        'icon'  => 'bx-group',
        'tone'  => 'info',
    ],
    [
        'label' => 'Next level',
        'value' => $position === null || $next === null ? '—' : 'Level ' . (int) $next['level'],
        'hint'  => $position === null
            ? 'The matrix starts when you qualify'
            : ($next === null
                ? 'Every payable level is complete'
                : number_format((int) $next['filled']) . ' of ' . number_format((int) $next['slots']) . ' slots · '
                    . money((float) $next['amount']) . ' when the block is full'),
        'icon'  => 'bx-grid-alt',
        'tone'  => 'warning',
    ],
    [
        'label' => 'Before go-live',
        'value' => money($baseAmount),
        'hint'  => $baseLevels === 0
            ? 'Nothing was recorded before go-live'
            : $baseLevels . ' level' . ($baseLevels === 1 ? '' : 's') . ' recorded, never paid',
        'icon'  => 'bx-time-five',
        'tone'  => 'secondary',
    ],
];
?>
              <div class="row g-4 mb-4" data-live-region="earnings-team-stats">
<?php foreach ($cards as $card): ?>
                <div class="col-sm-6 col-xl">
                  <div class="card h-100 commission-stat-card">
                    <div class="card-body">
                      <div class="d-flex align-items-center justify-content-between mb-3">
                        <span class="avatar avatar-sm">
                          <span class="avatar-initial rounded bg-label-<?= e($card['tone']) ?>">
                            <i class="icon-base <?= e(icon_class($card['icon'])) ?>"></i>
                          </span>
                        </span>
                        <span class="text-body-secondary small text-end"><?= e($card['label']) ?></span>
                      </div>
                      <h4 class="mb-2 commission-stat-value"><?= e($card['value']) ?></h4>
                      <p class="text-body-secondary small mb-0"><?= e($card['hint']) ?></p>
                    </div>
                  </div>
                </div>
<?php endforeach; ?>
              </div>
