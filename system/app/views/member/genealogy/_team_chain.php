<?php
declare(strict_types=1);

/**
 * Genealogy — the team chain.
 *
 * The logged-in member is always the first row; below it each downline
 * level is summarised from the member's point of view:
 *
 *   L1  direct recruits
 *   L2  the people their direct recruits sponsored
 *   L3  …
 *
 * Level 1 is the direct referral leg; levels 2–10 are indirect. This
 * card is the compact monitor; the Direct recruits table and the Team
 * by level card below it carry the full rows.
 *
 * A live region: a registration at any depth changes the level counts
 * and names, so the whole block is replaced.
 *
 * @var array<string, mixed>                     $member
 * @var array<int, list<array<string, mixed>>>   $team_levels
 * @var string                                   $display_name
 */

$display_name = $display_name ?? (
    trim((string) ($member['full_name'] ?? '')) !== ''
        ? (string) $member['full_name']
        : (string) $member['username']
);

$teamCount = 0;

foreach ($team_levels as $rows) {
    $teamCount += count($rows);
}
?>
              <div data-live-region="genealogy-team-chain">
                <div class="genealogy-upline">
                  <div class="upline-row is-self">
                    <span class="upline-level" aria-label="You"><i class="icon-base bx bx-user"></i></span>
                    <div class="flex-grow-1 min-w-0">
                      <div class="fw-medium text-truncate">
                        <?= e($display_name) ?> <span class="text-body-secondary small">· you</span>
                      </div>
                      <div class="member-meta">
                        <?= $member['package_name'] === null ? 'No package' : e((string) $member['package_name']) ?>
                        · <?= $teamCount ?> team member<?= $teamCount === 1 ? '' : 's' ?>
                      </div>
                    </div>
                    <?= status_badge((string) $member['member_status']) ?>
                  </div>
<?php if ($team_levels === []): ?>
                  <div class="alert alert-secondary py-3 mb-0 small">
                    No team yet — share your referral link to start building it.
                  </div>
<?php else: ?>
<?php foreach ($team_levels as $level => $rows): ?>
<?php
    $level   = (int) $level;
    $count   = count($rows);
    $names   = [];
    $shown   = array_slice($rows, 0, 4);

    foreach ($shown as $row) {
        $names[] = '@' . (string) $row['username'];
    }

    $namesText = implode(', ', $names) . ($count > count($shown) ? ' +' . ($count - count($shown)) . ' more' : '');
?>
                  <div class="upline-row" data-team-level="<?= $level ?>">
                    <span class="upline-level">L<?= $level ?></span>
                    <div class="flex-grow-1 min-w-0">
                      <div class="fw-medium text-truncate">
                        <?= $level === Commission::LEVEL_DIRECT ? 'Direct recruits' : 'Indirect level ' . $level ?>
                        <span class="text-body-secondary small">· <?= $count ?> member<?= $count === 1 ? '' : 's' ?></span>
                      </div>
                      <div class="member-meta text-truncate"><?= e($namesText) ?></div>
                    </div>
                  </div>
<?php endforeach; ?>
<?php endif; ?>
                </div>
              </div>
