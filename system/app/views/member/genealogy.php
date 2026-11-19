<?php
declare(strict_types=1);

/**
 * Genealogy page (plan §7 Phase 9; reference views/member/genealogy.php).
 *
 * The sponsor chain only — no binary legs, no placement mode. The team
 * chain is shown from the member's point of view: level 1 = direct
 * recruits, level 2 = their recruits, and so on, breadth-first.
 *
 * One live surface per member (`member.genealogy:{id}`). The heading
 * badges, team chain, direct-recruit count/rows/pagination and the
 * team-by-level card are regions; the referral block stays static, and
 * the shared `member.genealogy` revision in the ETag keeps every
 * ancestor and descendant page correct after a team change.
 *
 * @var array<string, mixed>       $member
 * @var string                     $display_name
 * @var array<int, list<array<string, mixed>>> $team_levels
 * @var list<array<string, mixed>> $direct       level-1 downline
 * @var Paginator                  $recruits_pager
 * @var array<int, list<array<string, mixed>>> $deeper
 * @var array<int, Paginator>      $level_pagers one pager per team-level card
 * @var int                        $team_count
 * @var int                        $levels_used
 * @var int|null                   $harvest_position  the reader's own Harvest position (Phase 20F)
 * @var array<int, int>            $harvest_positions user_id => position, for the rows' badges
 * @var string                     $referral_code
 * @var string                     $referral_link
 * @var array{surface:string, enabled:bool, revision:int, etag:string, interval:int} $live
 */

$display_name = $display_name ?? (
    trim((string) ($member['full_name'] ?? '')) !== ''
        ? (string) $member['full_name']
        : (string) $member['username']
);
$team_levels       = $team_levels ?? [];
$direct            = $direct ?? [];
$deeper            = $deeper ?? [];
$level_pagers      = $level_pagers ?? [];
$team_count        = $team_count ?? 0;
$levels_used       = $levels_used ?? 0;
$harvest_position  = $harvest_position ?? null;
$harvest_positions = $harvest_positions ?? [];
$referral_code     = $referral_code ?? '';
$referral_link     = $referral_link ?? '';
$recruits_pager    = $recruits_pager ?? Paginator::make(count($direct), 1, Paginator::DEFAULT_PER_PAGE);

$view           = $view ?? null;
$binary_root    = $binary_root ?? null;
$binary_list    = $binary_list ?? [];
$binary_depth   = $binary_depth ?? BinaryTreeService::DEFAULT_DEPTH;
$binary_root_id = $binary_root_id ?? (int) ($member['id'] ?? 0);

$live = $live ?? [
    'surface'  => Live::genealogySurface((int) ($member['id'] ?? 0)),
    'enabled'  => false,
    'revision' => 0,
    'etag'     => '',
    'interval' => 30000,
];
?>
            <div
              class="container-xxl flex-grow-1 container-p-y"
              data-live-surface="<?= e($live['surface']) ?>"
              data-live-enabled="<?= $live['enabled'] ? '1' : '0' ?>"
              data-live-revision="<?= (int) $live['revision'] ?>"
              data-live-etag="<?= e((string) $live['etag']) ?>"
              data-live-interval="<?= (int) $live['interval'] ?>"
            >
              <?= flash_render() ?>

<?php if ($view === 'binary'): ?>
                <!-- ============================ Binary tree (Phase 22G-S2) ============================ -->
                <?php
                $tree       = (array) (($binary_root['tree'] ?? null));
                $rootId     = (int) ($binary_root['root'] ?? $binary_root_id);
                $viewerId   = (int) ($binary_root['viewer'] ?? $rootId);
                $isAdmin    = (bool) ($binary_root['is_admin'] ?? false);
                $canReg     = (bool) ($binary_root['can_register'] ?? false);
                $truncated  = (bool) ($binary_root['truncated'] ?? false);
                $nodes      = (int) ($binary_root['nodes'] ?? 0);
                $etag       = (string) ($binary_root['etag'] ?? '');
                $rootUser   = null;
                foreach ($binary_list as $row) {
                    if ((int) ($row['id'] ?? 0) === $rootId) {
                        $rootUser = $row;
                        break;
                    }
                }
                ?>
                <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
                  <div>
                    <h4 class="mb-1">Binary Tree</h4>
                    <p class="text-body-secondary mb-0">
                      Your placement tree — left and right legs, points and pairs.
                      Server-rendered on first paint; works without JavaScript.
                    </p>
                    <div class="small text-body-secondary mt-2">
                      <strong><?= $nodes ?> node<?= $nodes === 1 ? '' : 's' ?></strong>
                      in tree · depth <?= (int) $binary_depth ?>
                      <?= $truncated ? '· some branches hidden (expand to load more)' : '' ?>
                    </div>
                  </div>
                  <div class="d-flex gap-2">
                    <a
                      href="<?= url('genealogy?view=binary&depth=' . max(1, (int) $binary_depth - 1)) ?>"
                      class="btn btn-sm btn-outline-secondary"
                      title="Shallower view"
                      aria-label="Shallower view"
                    ><i class="icon-base bx bx-chevron-up"></i></a>
                    <a
                      href="<?= url('genealogy?view=binary&depth=' . min(BinaryTreeService::MAX_DEPTH, (int) $binary_depth + 1)) ?>"
                      class="btn btn-sm btn-outline-secondary"
                      title="Deeper view"
                      aria-label="Deeper view"
                    ><i class="icon-base bx bx-chevron-down"></i></a>
                    <a
                      href="<?= url('genealogy?view=binary&root=' . $rootId) ?>"
                      class="btn btn-sm btn-outline-secondary"
                      title="Reset view"
                      aria-label="Reset view"
                    ><i class="icon-base bx bx-reset"></i></a>
                  </div>
                </div>

                <div class="card">
                  <div class="card-header d-flex align-items-center justify-content-between gap-2">
                    <h5 class="card-title mb-0">Binary placement tree</h5>
                    <span class="badge rounded-pill bg-label-<?= $isAdmin ? 'info' : 'primary' ?>">
                      <?= $isAdmin ? 'Admin view' : 'Your tree' ?>
                    </span>
                  </div>
                  <div class="card-body p-0 position-relative">
                    <!-- Server-rendered seed: d3 hydrates this when it loads. -->
                    <script type="application/json" id="binaryTreeSeed"><?= e(json_encode($binary_root)) ?></script>

                    <!-- No-JS semantic list: usable without d3 or JavaScript. -->
                    <div id="binaryTreeListWrap" class="pb-3">
<?= View::capture('member/genealogy/_tree', [
    'tree'          => $tree,
    'list'          => $binary_list,
    'depth'         => (int) $binary_depth,
    'root_id'       => $rootId,
    'viewer_id'     => $viewerId,
    'is_admin'      => $isAdmin,
    'can_register'  => $canReg,
    'truncated'     => $truncated,
]) ?>
                    </div>

                    <?php if ($truncated): ?>
                    <div class="alert alert-info py-3 mb-0 small mx-3">
                      The tree is large — only the first <strong>500 nodes</strong> are in this paint.
                      Expand a branch to load its children.
                    </div>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="card mt-3">
                  <div class="card-body">
                    <h6 class="mb-3">Legend</h6>
                    <div class="d-flex flex-wrap gap-4 small">
                      <span><span class="badge bg-label-success rounded-pill">●</span> Active</span>
                      <span><span class="badge bg-label-secondary rounded-pill">●</span> Inactive</span>
                      <span><span class="text-primary fw-bold">L</span> · Left slot &nbsp; <span class="text-danger fw-bold">R</span> · Right slot</span>
                      <span><span class="text-body-secondary fw-bold">You</span> · Your node</span>
                      <span><span class="badge bg-label-info rounded-pill">+</span> Free slot — register here</span>
                    </div>
                    <p class="text-body-secondary small mt-3 mb-0">
                      An empty side carries a <strong>+</strong>. Click it (or open the link without
                      JavaScript) to register a member into that slot through the normal registration form.
                    </p>
                  </div>
                </div>
<?php else: ?>
                <!-- ============================ Sponsor genealogy (unchanged) ============================ -->
                <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
                  <div>
                    <h4 class="mb-1">Genealogy</h4>
                    <p class="text-body-secondary mb-0">
                      Your team by level — direct recruits at level 1, their recruits at level 2, and so on.
                      Commissions follow this chain — one sponsor per member.
                      <span class="small text-body-secondary" data-live-status aria-live="polite"></span>
                    </p>
                  </div>
<?= View::capture('member/genealogy/_header', [
    'team_count'       => $team_count,
    'levels_used'      => $levels_used,
    'harvest_position' => $harvest_position ?? null,
]) ?>
                </div>

                <div class="row g-4">
                  <!-- Team chain -->
                  <div class="col-xl-6">
                    <div class="card h-100">
                      <div class="card-header">
                        <h5 class="card-title mb-0">Team chain</h5>
                        <small class="text-body-secondary">Your direct recruits at level 1, their recruits at level 2, and so on — up to ten levels.</small>
                      </div>
                      <div class="card-body">
<?= View::capture('member/genealogy/_team_chain', [
    'member'       => $member,
    'team_levels'  => $team_levels,
    'display_name' => $display_name,
]) ?>

                      <hr class="my-4" />

                      <label class="form-label small text-body-secondary" for="genealogyReferral">Your referral link</label>
                      <div class="referral-box">
                        <input
                          type="text"
                          class="form-control referral-link-input"
                          id="genealogyReferral"
                          value="<?= e($referral_link) ?>"
                          readonly
                          onfocus="this.select()"
                        />
                        <button
                          type="button"
                          class="btn btn-icon btn-text-secondary"
                          data-copy="<?= e($referral_link) ?>"
                          data-copy-tooltip="Copy link"
                          data-bs-toggle="tooltip"
                          title="Copy link"
                          aria-label="Copy referral link"
                        ><i class="icon-base bx bx-copy"></i></button>
                      </div>
                      <div class="small text-body-secondary mt-2">Code <strong><?= e($referral_code) ?></strong></div>
                    </div>
                  </div>
                </div>

                <!-- Direct recruits -->
                <div class="col-xl-6">
                  <div class="card h-100 genealogy-direct-card">
                    <div class="card-header d-flex align-items-center justify-content-between">
                      <div>
                        <h5 class="card-title mb-0">Direct recruits</h5>
                        <small class="text-body-secondary">Level 1 — members you sponsored.</small>
                      </div>
<?= View::capture('member/genealogy/_recruits_count', ['recruits_pager' => $recruits_pager]) ?>
                    </div>
                    <div class="genealogy-table-scroll" data-table-scroll>
                      <div class="genealogy-table-scroll-top" data-table-scroll-top aria-hidden="true" hidden>
                        <div class="genealogy-table-scroll-thumb" data-table-scroll-thumb></div>
                      </div>
                      <div class="table-responsive genealogy-table-scroll-body" data-table-scroll-body>
                        <table class="table table-hover align-middle mb-0">
                          <thead>
                            <tr>
                              <th>Member</th>
                              <th>Package</th>
                              <th>Status</th>
                              <th>Harvest</th>
                              <th>Joined</th>
                            </tr>
                          </thead>
<?= View::capture('member/genealogy/_recruits_rows', [
    'direct'            => $direct,
    'harvest_positions' => $harvest_positions ?? [],
]) ?>
                        </table>
                      </div>
                    </div>
<?= View::capture('member/genealogy/_recruits_pagination', ['recruits_pager' => $recruits_pager]) ?>
                  </div>
                </div>
              </div>

              <!-- Team by level -->
              <div class="card mt-4">
                <div class="card-header">
                  <h5 class="card-title mb-0">Team by level</h5>
                  <small class="text-body-secondary">Breadth-first through sponsor links, up to ten levels.</small>
                </div>
                <div class="card-body">
<?= View::capture('member/genealogy/_team', [
    'deeper'            => $deeper,
    'level_pagers'      => $level_pagers,
    'harvest_positions' => $harvest_positions ?? [],
]) ?>
                </div>
              </div>
<?php endif; ?>
            </div>
