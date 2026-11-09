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
 * @var string                     $referral_code
 * @var string                     $referral_link
 * @var array{surface:string, enabled:bool, revision:int, etag:string, interval:int} $live
 */

$display_name = $display_name ?? (
    trim((string) ($member['full_name'] ?? '')) !== ''
        ? (string) $member['full_name']
        : (string) $member['username']
);
$team_levels    = $team_levels ?? [];
$direct         = $direct ?? [];
$deeper         = $deeper ?? [];
$level_pagers   = $level_pagers ?? [];
$team_count     = $team_count ?? 0;
$levels_used    = $levels_used ?? 0;
$referral_code  = $referral_code ?? '';
$referral_link  = $referral_link ?? '';
$recruits_pager = $recruits_pager ?? Paginator::make(count($direct), 1, Paginator::DEFAULT_PER_PAGE);

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
    'team_count'  => $team_count,
    'levels_used' => $levels_used,
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
                              <th>Joined</th>
                            </tr>
                          </thead>
<?= View::capture('member/genealogy/_recruits_rows', ['direct' => $direct]) ?>
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
    'deeper'       => $deeper,
    'level_pagers' => $level_pagers,
]) ?>
                </div>
              </div>
            </div>
