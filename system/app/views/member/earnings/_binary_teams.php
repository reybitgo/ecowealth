<?php
declare(strict_types=1);

/**
 * Member Product binary statement — the Left and Right team cards (22F,
 * plan §10, Appendix C.3).
 *
 * Two cards sit side by side on desktop and stack on mobile. Each card
 * names only the viewer's own team: no upline, no sponsor, no ancestor
 * (plan §14.12, D-22). Each card carries its own pagination
 * (`left_page` / `right_page`) so the two sides move independently.
 *
 * @var array{count:int, available:int, lifetime:int, pair_points:int, pager:array, rows:list} $leftTeam
 * @var array{count:int, available:int, lifetime:int, pair_points:int, pager:array, rows:list} $rightTeam
 * @var int $left_points
 * @var int $right_points
 */

$leftCount  = (int) ($leftTeam['count']  ?? 0);
$rightCount = (int) ($rightTeam['count'] ?? 0);
$leftPts    = (int) ($leftTeam['available']  ?? 0);
$rightPts   = (int) ($rightTeam['available'] ?? 0);
$leftLife   = (int) ($leftTeam['lifetime']  ?? 0);
$rightLife  = (int) ($rightTeam['lifetime'] ?? 0);
$pairPts    = (int) ($leftTeam['pair_points']  ?? 1000);

$leftPager  = Paginator::make((int) ($leftTeam['pager']['total']  ?? 0), (int) ($leftTeam['pager']['page']  ?? 1), (int) ($leftTeam['pager']['per_page']  ?? 10));
$rightPager = Paginator::make((int) ($rightTeam['pager']['total'] ?? 0), (int) ($rightTeam['pager']['page'] ?? 1), (int) ($rightTeam['pager']['per_page'] ?? 10));

$leftInvite  = '<a href="' . e(url('shop')) . '" class="text-primary text-decoration-none">Start by placing a purchase on the left</a>.';
$rightInvite = '<a href="' . e(url('shop')) . '" class="text-primary text-decoration-none">Start by placing a purchase on the right</a>.';
?>
              <div class="row g-4 mb-4" data-live-region="earnings-binary-teams">
                <div class="col-xl-6">
                  <div class="card h-100">
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <h5 class="mb-0">Left team</h5>
                      <span class="badge bg-label-primary text-capitalize">Left</span>
                    </div>
                    <div class="card-body">
                      <div class="d-flex justify-between mb-2">
                        <span class="text-body-secondary small">Members</span>
                        <span class="fw-semibold"><?= e((string) $leftCount) ?></span>
                      </div>
                      <div class="d-flex justify-between mb-2">
                        <span class="text-body-secondary small">Available</span>
                        <span class="fw-semibold"><?= e((string) $leftPts) ?> pts</span>
                      </div>
                      <div class="d-flex justify-between mb-2">
                        <span class="text-body-secondary small">Lifetime earned</span>
                        <span class="fw-semibold"><?= e((string) $leftLife) ?> pts</span>
                      </div>
                      <div class="mt-3">
<?= View::capture('member/earnings/_binary_team_rows', [
    'rows'          => $leftTeam['rows'] ?? [],
    'side'          => 'left',
    'pageParam'     => 'left_page',
    'perPageParam'  => 'left_per_page',
    'pager'         => $leftPager,
    'emptyLabel'    => 'Left',
    'inviteHint'    => $leftInvite,
]) ?>
                      </div>
                    </div>
                    <div class="card-body pt-2">
<?= View::partial('pagination', [
    'pager'           => $leftPager,
    'pager_label'     => 'Left team',
    'page_param'      => 'left_page',
    'per_page_param'  => 'left_per_page',
    'form_action'     => url(ltrim(Request::path(), '/')),
    'pagination_label'=> 'Left team pagination',
]) ?>
                    </div>
                  </div>
                </div>

                <div class="col-xl-6">
                  <div class="card h-100">
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <h5 class="mb-0">Right team</h5>
                      <span class="badge bg-label-info text-capitalize">Right</span>
                    </div>
                    <div class="card-body">
                      <div class="d-flex justify-between mb-2">
                        <span class="text-body-secondary small">Members</span>
                        <span class="fw-semibold"><?= e((string) $rightCount) ?></span>
                      </div>
                      <div class="d-flex justify-between mb-2">
                        <span class="text-body-secondary small">Available</span>
                        <span class="fw-semibold"><?= e((string) $rightPts) ?> pts</span>
                      </div>
                      <div class="d-flex justify-between mb-2">
                        <span class="text-body-secondary small">Lifetime earned</span>
                        <span class="fw-semibold"><?= e((string) $rightLife) ?> pts</span>
                      </div>
                      <div class="mt-3">
<?= View::capture('member/earnings/_binary_team_rows', [
    'rows'          => $rightTeam['rows'] ?? [],
    'side'          => 'right',
    'pageParam'     => 'right_page',
    'perPageParam'  => 'right_per_page',
    'pager'         => $rightPager,
    'emptyLabel'    => 'Right',
    'inviteHint'    => $rightInvite,
]) ?>
                      </div>
                    </div>
                    <div class="card-body pt-2">
<?= View::partial('pagination', [
    'pager'           => $rightPager,
    'pager_label'     => 'Right team',
    'page_param'      => 'right_page',
    'per_page_param'  => 'right_per_page',
    'form_action'     => url(ltrim(Request::path(), '/')),
    'pagination_label'=> 'Right team pagination',
]) ?>
                    </div>
                  </div>
                </div>
              </div>
