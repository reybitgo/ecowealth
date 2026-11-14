<?php
declare(strict_types=1);

/**
 * Member repeat-purchase statement — the 1–10 team breakdown (Phase 17E;
 * 17E follow-up lists the team).
 *
 * The card answers two questions at once:
 *
 *   • who is at level N? Level 1 is the members the session member
 *     sponsored, level 2 their recruits, and so on — the same
 *     breadth-first sponsor chain the genealogy "Team by level" card
 *     shows.
 *   • what did that level earn? Each member row carries the credited
 *     repeat-purchase money their purchases paid at that level, and the
 *     level header carries the full credited total at that depth.
 *
 * The ladder is still one ladder (D-17): there is no direct/indirect
 * split and the level number is the whole label. A level with no team
 * members stays visible with its ₱0.00 total, so the whole 1–10 ladder
 * remains on one screen. A member with no credited purchase also stays
 * visible at ₱0.00 — the roster is the team, not only the payers.
 *
 * Each level card paginates on its own, exactly like the genealogy
 * "Team by level" card: ten rows by default, a rows-per-page control
 * and a "Showing X–Y of Z" summary whenever the level has any member,
 * and page links once a level needs more than one page. The query keys
 * stay namespaced — ?level3_page=2&level3_per_page=5 — so one crowded
 * level never moves the others or the award table below.
 *
 * @var array<int, array{
 *   amount:float, count:int, team_count:int, pager:Paginator,
 *   members:list<array{
 *     id:int, username:string, full_name:string, package_name:?string,
 *     member_status:string, amount:float, count:int
 *   }>
 * }> $levels
 * @var array{count:int, credited:float} $totals
 */

$levels = $levels ?? [];
$totals = $totals ?? ['count' => 0, 'credited' => 0.0];

$maxAmount = 0.0;

foreach ($levels as $level) {
    $maxAmount = max($maxAmount, (float) $level['amount']);
}

$earned = 0.0;
$awards = 0;

for ($level = UnilevelAward::LEVEL_MIN; $level <= UnilevelAward::LEVEL_MAX; $level++) {
    $earned += (float) ($levels[$level]['amount'] ?? 0);
    $awards += (int) ($levels[$level]['count'] ?? 0);
}
?>
              <div class="card mb-4" data-live-region="earnings-repeat-levels">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3">
                  <div>
                    <h5 class="mb-1">Repeat-purchase by level</h5>
                    <p class="text-body-secondary mb-0 small">
                      Your team by level — level 1 is the members you personally sponsored, level 2 their
                      recruits, and so on. Each row shows what that member's repeat purchases paid you at
                      that depth; the level total is the Unilevel Repeat-purchase Bonus you earned there.
                    </p>
                  </div>
                  <span class="badge bg-label-info">
                    <?= $awards ?> level award<?= $awards === 1 ? '' : 's' ?> · <?= e(money($earned)) ?>
                  </span>
                </div>
                <div class="card-body">
                  <div class="row g-4 align-items-start">
<?php for ($level = UnilevelAward::LEVEL_MIN; $level <= UnilevelAward::LEVEL_MAX; $level++): ?>
<?php
    $levelData  = (array) ($levels[$level] ?? []);
    $amount     = round((float) ($levelData['amount'] ?? 0), 2);
    $count      = (int) ($levelData['count'] ?? 0);
    $members    = (array) ($levelData['members'] ?? []);
    $teamCount  = (int) ($levelData['team_count'] ?? count($members));
    $pager      = $levelData['pager'] ?? null;
    $percent    = $maxAmount > 0 ? (int) round(($amount / $maxAmount) * 100) : 0;
    $isPaid     = $amount > 0;
?>
                    <div class="col-md-6 col-xl-4">
                      <div class="commission-team-card border rounded-3 p-3<?= $isPaid ? ' is-paid' : '' ?>" data-level="<?= $level ?>" data-pagination-anchor>
                        <div class="d-flex align-items-start justify-content-between gap-2">
                          <div>
                            <div class="commission-level-label">Level <?= $level ?></div>
                            <div class="small text-body-secondary">
                              <?= $teamCount ?> member<?= $teamCount === 1 ? '' : 's' ?>
                              · <?= $count ?> award<?= $count === 1 ? '' : 's' ?>
                            </div>
                          </div>
                          <div class="text-end">
                            <div class="small text-body-secondary">Level total</div>
                            <div class="fw-semibold text-nowrap"><?= e(money($amount)) ?></div>
                          </div>
                        </div>
                        <div
                          class="progress commission-level-bar"
                          role="progressbar"
                          aria-label="Level <?= $level ?> share"
                          aria-valuenow="<?= $percent ?>"
                          aria-valuemin="0"
                          aria-valuemax="100"
                        >
                          <div class="progress-bar bg-info" style="width: <?= $percent ?>%"></div>
                        </div>

<?php if ($members === []): ?>
                        <p class="small text-body-secondary mt-3 mb-0">
                          No team members at this level yet.
                        </p>
<?php else: ?>
                        <ul class="list-unstyled commission-team-list mb-0 small">
<?php foreach ($members as $member): ?>
<?php
    $memberAmount = round((float) ($member['amount'] ?? 0), 2);
    $memberCount  = (int) ($member['count'] ?? 0);
    $packageName  = trim((string) ($member['package_name'] ?? ''));
?>
                          <li class="commission-team-member d-flex align-items-start justify-content-between gap-2">
                            <div class="commission-team-member-info">
                              <div class="d-flex align-items-center gap-2">
                                <span class="fw-medium text-truncate">@<?= e((string) $member['username']) ?></span>
                                <?= status_badge((string) $member['member_status']) ?>
                              </div>
<?php if ($packageName !== ''): ?>
                              <div class="text-body-secondary text-truncate"><?= e($packageName) ?></div>
<?php endif; ?>
                            </div>
                            <div class="commission-team-amount text-end">
                              <div class="fw-semibold<?= $memberAmount > 0 ? '' : ' text-body-secondary' ?>">
                                <span class="visually-hidden">Earned </span><?= e(money($memberAmount)) ?>
                              </div>
                              <div class="text-body-secondary">
                                <?= $memberCount === 0 ? 'No credited award' : $memberCount . ' award' . ($memberCount === 1 ? '' : 's') ?>
                              </div>
                            </div>
                          </li>
<?php endforeach; ?>
                        </ul>
<?php endif; ?>

<?php if ($pager instanceof Paginator && $pager->total > 0): ?>
                        <div class="commission-team-pagination">
<?= View::partial('pagination', [
    'pager'            => $pager,
    'page_param'       => 'level' . $level . '_page',
    'per_page_param'   => 'level' . $level . '_per_page',
    'pagination_label' => 'Level ' . $level . ' pagination',
]) ?>
                        </div>
<?php endif; ?>
                      </div>
                    </div>
<?php endfor; ?>
                  </div>
                  <p class="small text-body-secondary mt-3 mb-0">
                    The level total counts only credited awards. A member with no credited amount has not
                    had a paid repeat purchase credited to you; skipped and voided awards stay in your
                    statement below. A level with no members simply means your team is not that deep yet.
                  </p>
                </div>
              </div>
