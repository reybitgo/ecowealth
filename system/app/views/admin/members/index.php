<?php
declare(strict_types=1);

/**
 * Admin member directory (plan §7 Phase 9; reference views/admin/users.php).
 *
 * One live surface: the heading badge, the five counters, the filter form,
 * the Team Harvest queue card, the rows and the pagination are regions. The
 * form is held back while the administrator has unsaved input, so a
 * background refresh never discards a typed search; the page shows a
 * "changed in another tab" banner instead.
 *
 * Phase 20E adds the queue card and the `Harvest` column: the card explains
 * the queue's state (not seeded / nobody qualified / the positions), and the
 * column reads the stored position — never a derived one (Rule 5).
 *
 * @var array{total:int, active:int, pending:int, suspended:int, with_package:int, new_week:int} $stats
 * @var array{member:int, leader:int, supervisor:int, manager:int, director:int, ranked:int, total:int} $rank_counts
 * @var array{q:string, status:string, package:string, harvest:string, board:string, placement:string} $filters
 * @var Paginator                  $pager
 * @var list<array<string, mixed>> $members
 * @var list<array{id:int, name:string, members:int}> $packages
 * @var array<string, mixed>|null $harvest  the Team Harvest queue card's data (20E)
 * @var array<string, mixed>|null $general_harvest  the General Harvest board card's data (21E)
 * @var array{surface:string, enabled:bool, revision:int, etag:string, interval:int} $live
 */

$live = $live ?? [
    'surface'  => 'admin.members',
    'enabled'  => false,
    'revision' => 0,
    'etag'     => '',
    'interval' => 30000,
];

$rankCounts = $rank_counts ?? [];
$harvest    = $harvest ?? null;
$generalHarvest = $general_harvest ?? null;
?>
            <div
              class="container-xxl flex-grow-1 container-p-y member-directory"
              data-live-surface="<?= e($live['surface']) ?>"
              data-live-enabled="<?= $live['enabled'] ? '1' : '0' ?>"
              data-live-revision="<?= (int) $live['revision'] ?>"
              data-live-etag="<?= e((string) $live['etag']) ?>"
              data-live-interval="<?= (int) $live['interval'] ?>"
            >
              <div
                class="alert alert-warning d-flex align-items-start gap-2 mb-4 d-none"
                data-live-stale
                role="status"
                aria-live="polite"
              >
                <i class="icon-base bx bx-error-circle fs-5"></i>
                <div>
                  <strong>The member data changed in another tab.</strong>
                  Your unsaved filters were kept, so the directory may be out of date.
                  <a href="<?= e(url('admin/members')) ?>" class="alert-link" data-live-reload>
                    Reload the member directory
                  </a>
                  to see the latest.
                </div>
              </div>

<?= View::capture('admin/members/_header', ['stats' => $stats]) ?>

<?= View::capture('admin/members/_stats', ['stats' => $stats, 'rank_counts' => $rankCounts]) ?>

<?= View::capture('admin/members/_harvest', ['harvest' => $harvest]) ?>

<?= View::capture('admin/members/_general_harvest_board_card', ['board' => $generalHarvest]) ?>

              <div class="card">
                <div class="card-header">
                  <h5 class="card-title mb-0">Member directory</h5>
                  <small class="text-body-secondary">
                    Search by member, sponsor or referral code.
                    <span class="small text-body-secondary" data-live-status aria-live="polite"></span>
                  </small>
                </div>
<?= View::capture('admin/members/_filters', ['filters' => $filters, 'packages' => $packages]) ?>

                <div class="table-responsive">
                  <table class="table table-hover align-middle mb-0">
                    <thead>
                      <tr>
<?php foreach (AdminMemberController::DIRECTORY_COLUMNS as $column): ?>
                        <th scope="col"<?= $column['class'] !== '' ? ' class="' . e($column['class']) . '"' : '' ?>><?= e($column['label']) ?></th>
<?php endforeach; ?>
                      </tr>
                    </thead>
<?= View::capture('admin/members/_rows', ['members' => $members, 'filters' => $filters]) ?>
                  </table>
                </div>

<?= View::capture('admin/members/_pagination', ['pager' => $pager]) ?>
              </div>
            </div>
