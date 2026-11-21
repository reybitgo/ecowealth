<?php
declare(strict_types=1);

/**
 * Admin member directory — headline counters and the Royalty rank
 * distribution (Phase 19B, plan §6).
 *
 * The initial page and the live feed render this same partial, so the
 * five cards and the distribution can never drift apart. The rank
 * badges come from one `RoyaltyRank::map()` pass; the distribution is
 * exclusive per rank, with `ranked` counting Supervisor and above.
 *
 * @var array{total:int, active:int, pending:int, suspended:int, with_package:int, new_week:int} $stats
 * @var array{member:int, leader:int, supervisor:int, manager:int, director:int, ranked:int, total:int} $rank_counts
 */

$rankCounts = $rank_counts ?? [];

$cards = [
    ['label' => 'Members', 'value' => (string) $stats['total'], 'icon' => 'bx-group', 'tone' => 'primary'],
    ['label' => 'Active', 'value' => (string) $stats['active'], 'icon' => 'bx-check-circle', 'tone' => 'success'],
    ['label' => 'Pending', 'value' => (string) $stats['pending'], 'icon' => 'bx-time-five', 'tone' => 'warning'],
    ['label' => 'Suspended', 'value' => (string) $stats['suspended'], 'icon' => 'bx-block', 'tone' => 'danger'],
    ['label' => 'With a package', 'value' => (string) $stats['with_package'], 'icon' => 'bx-medal', 'tone' => 'info'],
];

$rankBadges = [
    ['key' => 'member', 'label' => 'Member', 'tone' => 'secondary'],
    ['key' => 'leader', 'label' => 'Leader', 'tone' => 'info'],
    ['key' => 'supervisor', 'label' => 'Supervisor', 'tone' => 'primary'],
    ['key' => 'manager', 'label' => 'Manager', 'tone' => 'warning'],
    ['key' => 'director', 'label' => 'Director', 'tone' => 'success'],
];
?>
              <div class="row g-4 mb-4" data-live-region="member-stats">
<?php foreach ($cards as $card): ?>
                <div class="col-6 col-xl">
                  <div class="card h-100">
                    <div class="card-body">
                      <div class="d-flex align-items-start justify-content-between mb-3">
                        <span class="avatar avatar-sm">
                          <span class="avatar-initial rounded bg-label-<?= e($card['tone']) ?>">
                            <i class="icon-base <?= e(icon_class($card['icon'])) ?>"></i>
                          </span>
                        </span>
                      </div>
                      <p class="text-body-secondary mb-1 small"><?= e($card['label']) ?></p>
                      <h5 class="mb-0"><?= e($card['value']) ?></h5>
                    </div>
                  </div>
                </div>
<?php endforeach; ?>
<?php if ($rankCounts !== []): ?>
                <div class="col-12">
                  <div class="card">
                    <div class="card-body d-flex flex-wrap align-items-center gap-3" data-rank-distribution>
                      <span class="fw-medium">Rank distribution</span>
<?php foreach ($rankBadges as $badge): ?>
                      <span class="badge bg-label-<?= e($badge['tone']) ?>" data-rank-count="<?= e($badge['key']) ?>">
                        <?= e($badge['label']) ?> <?= (int) ($rankCounts[$badge['key']] ?? 0) ?>
                      </span>
<?php endforeach; ?>
                      <span class="small text-body-secondary">
                        <?= (int) ($rankCounts['ranked'] ?? 0) ?> ranked (Supervisor+)
                        · ranks derive from the sponsor tree
                      </span>
                    </div>
                  </div>
                </div>
<?php endif; ?>
              </div>
