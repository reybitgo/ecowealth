<?php
declare(strict_types=1);

/**
 * Member Royalty statement — the rank card (Phase 19E, plan §9 Rules
 * 2–5, Appendix C.3).
 *
 * The card answers four questions in the member's own words:
 *
 *   · what rank am I, and what does the next one need?
 *   · how many people are in my team at each ranked level?
 *   · how much have my team and I sold this month, against the quota?
 *   · if the month closed now, what would I be paid?
 *
 * Everything here is `RoyaltyService::statusFor()` — the engine's own
 * read for the **open** month — so the projection can never disagree
 * with what the close would pay (plan §14.11). The projection is
 * labelled "if this month closed now" and is never presented as money
 * in the wallet (Rule 3).
 *
 * Copy rules (Rule 7): the card says **team**, never "downline"; it
 * says **Royalty**, never "royalty commission"; and it states the month
 * close, not the purchase event. A Leader is explained, not hidden: the
 * first rank that pays is Supervisor (Rule 2). When the stream is off
 * the promise surfaces are replaced by an explicit "Royalty is currently
 * off" note while the rank and the month's figures stay visible (Rule 5).
 *
 * @var array<string, mixed> $royalty RoyaltyService::statusFor()
 */

$royalty = $royalty ?? [];

$rank        = (int) ($royalty['rank'] ?? RoyaltyRank::MEMBER);
$rankName    = (string) ($royalty['rank_name'] ?? RoyaltyRank::NAMES[$rank] ?? 'Member');
$enabled     = (bool) ($royalty['enabled'] ?? false);
$payments    = (bool) ($royalty['payments_enabled'] ?? true);
$directs     = (int) ($royalty['directs'] ?? 0);
$downline    = is_array($royalty['downline'] ?? null) ? $royalty['downline'] : [];
$leaders     = (int) ($downline['leaders'] ?? 0);
$supervisors = (int) ($downline['supervisors'] ?? 0);
$managers    = (int) ($downline['managers'] ?? 0);
$personal    = (float) ($royalty['personal'] ?? 0);
$group       = (float) ($royalty['group'] ?? 0);
$quota       = (float) ($royalty['quota'] ?? 0);
$groupPct    = (float) ($royalty['group_pct'] ?? 0);
$personalPct = (float) ($royalty['personal_pct'] ?? 0);
$progress    = max(0, min(100, (int) ($royalty['progress'] ?? 0)));
$eligible    = (bool) ($royalty['eligible'] ?? false);
$projected   = (float) ($royalty['projected'] ?? 0);
$next        = is_array($royalty['next'] ?? null) ? $royalty['next'] : null;

$tone   = RoyaltyRank::LABELS[$rank]['tone'] ?? 'secondary';
$icon   = RoyaltyRank::LABELS[$rank]['icon'] ?? 'bx-user';
$paying = in_array($rank, RoyaltyRank::PAYING, true);

$period = (string) ($royalty['period'] ?? '');
$ts     = $period === '' ? false : strtotime($period . '-01 12:00:00');
$month  = $ts === false ? 'this month' : date('F Y', $ts);

/* The one requirement that unlocks the next rank. Every rank is
 * self-prerequisite, so a single item is the whole step (plan §12.2).
 * The labels are the member-facing ones — never "Downline Leaders". */
$nextRank = $next === null ? 0 : (int) ($next['rank'] ?? 0);
$need     = $next === null ? 0 : max(0, (int) ($next['need'] ?? 0));
$have     = $next === null ? 0 : max(0, (int) ($next['have'] ?? 0));

$requirement = match ($nextRank) {
    RoyaltyRank::LEADER     => ['label' => 'Direct members',          'cta' => 'Reach ' . $need . ' direct member' . ($need === 1 ? '' : 's') . ' to become a Leader.'],
    RoyaltyRank::SUPERVISOR => ['label' => 'Leaders in your team',     'cta' => 'Reach ' . $need . ' Leader' . ($need === 1 ? '' : 's') . ' in your team to become a Supervisor.'],
    RoyaltyRank::MANAGER    => ['label' => 'Supervisors in your team', 'cta' => 'Reach ' . $need . ' Supervisor' . ($need === 1 ? '' : 's') . ' in your team to become a Manager.'],
    RoyaltyRank::DIRECTOR   => ['label' => 'Managers in your team',    'cta' => 'Reach ' . $need . ' Manager' . ($need === 1 ? '' : 's') . ' in your team to become a Director.'],
    default                 => null,
};

$nextPercent = $need > 0 ? (int) max(0, min(100, intdiv($have * 100, $need))) : 100;
$quotaMet    = $group >= $quota;   // the engine's rule: quota 0 is met (₱0 quota is legal)
$gap         = round(max(0.0, $quota - $group), 2);
?>
              <div class="card mb-4" data-live-region="earnings-royalty-rank">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <div>
                    <h5 class="mb-1">Your rank</h5>
                    <p class="text-body-secondary small mb-0">
                      Ranks are derived from your team every time they are shown. Nothing here is stored or
                      promised until the month closes.
                    </p>
                  </div>
                  <span class="badge bg-label-<?= e($tone) ?> text-nowrap">
                    <i class="icon-base <?= e(icon_class($icon)) ?> me-1"></i>
                    <?= e($rankName) ?>
                  </span>
                </div>
                <div class="card-body">
                  <div class="row g-4">
                    <div class="col-lg-6">
                      <div class="d-flex align-items-center gap-3 mb-3">
                        <span class="avatar">
                          <span class="avatar-initial rounded bg-label-<?= e($tone) ?>">
                            <i class="icon-base <?= e(icon_class($icon)) ?>"></i>
                          </span>
                        </span>
                        <div>
                          <div class="h5 mb-0"><?= e($rankName) ?></div>
                          <small class="text-body-secondary">
<?php if ($next === null): ?>
                            Highest rank reached
<?php else: ?>
                            Next rank: <?= e((string) ($next['name'] ?? RoyaltyRank::NAMES[$nextRank] ?? '')) ?>
<?php endif; ?>
                          </small>
                        </div>
                      </div>

<?php if ($next !== null && $requirement !== null): ?>
                      <div class="d-flex flex-wrap align-items-baseline justify-content-between gap-2 mb-1">
                        <span class="small text-body-secondary"><?= e($requirement['label']) ?></span>
                        <strong class="small"><?= $have ?> / <?= $need ?></strong>
                      </div>
                      <div
                        class="progress commission-progress"
                        role="progressbar"
                        aria-valuenow="<?= $nextPercent ?>"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        aria-label="<?= e($requirement['label']) ?> toward the next rank"
                      >
                        <div class="progress-bar bg-<?= e($tone) ?>" style="width: <?= $nextPercent ?>%"></div>
                      </div>
                      <p class="small text-body-secondary mt-2 mb-0">
                        <?= e($requirement['cta']) ?>
                        <span class="d-block">
                          <?= $have ?> of <?= $need ?> so far<?= $have >= $need ? ' — met.' : '.' ?>
                        </span>
                      </p>
<?php else: ?>
                      <p class="small text-body-secondary mb-0">
                        You have reached the highest rank. Your team keeps counting toward the next month's
                        group sales.
                      </p>
<?php endif; ?>

<?php if ($rank === RoyaltyRank::LEADER): ?>
                      <div class="alert alert-info py-2 px-3 small mb-0 mt-3" role="status">
                        <strong>You are a Leader.</strong>
                        Leader is a rank, not a royalty rank — the first rank that pays is Supervisor.
                      </div>
<?php endif; ?>

                      <ul class="list-unstyled small mb-0 mt-3">
                        <li class="d-flex align-items-center justify-content-between py-1 border-bottom">
                          <span class="text-body-secondary">Direct members</span>
                          <strong><?= $directs ?></strong>
                        </li>
                        <li class="d-flex align-items-center justify-content-between py-1 border-bottom">
                          <span class="text-body-secondary">Leaders in your team</span>
                          <strong><?= $leaders ?></strong>
                        </li>
                        <li class="d-flex align-items-center justify-content-between py-1 border-bottom">
                          <span class="text-body-secondary">Supervisors in your team</span>
                          <strong><?= $supervisors ?></strong>
                        </li>
                        <li class="d-flex align-items-center justify-content-between py-1">
                          <span class="text-body-secondary">Managers in your team</span>
                          <strong><?= $managers ?></strong>
                        </li>
                      </ul>
                    </div>

                    <div class="col-lg-6">
                      <h6 class="mb-2"><?= e($month) ?></h6>

<?php if (!$enabled): ?>
                      <div class="alert alert-warning py-2 px-3 small mb-3" role="status">
                        <strong>Royalty is currently off.</strong>
                        Past months stay in your history below, and nothing is paid for this month while
                        Royalty is off.
                      </div>
<?php endif; ?>

                      <ul class="list-unstyled small mb-0">
                        <li class="d-flex align-items-center justify-content-between py-1 border-bottom">
                          <span class="text-body-secondary">Personal sales</span>
                          <strong class="commission-num"><?= e(money($personal)) ?></strong>
                        </li>
                        <li class="d-flex align-items-center justify-content-between py-1 border-bottom">
                          <span class="text-body-secondary">Group sales</span>
                          <strong class="commission-num"><?= e(money($group)) ?></strong>
                        </li>
<?php if ($paying): ?>
                        <li class="d-flex align-items-center justify-content-between py-1 border-bottom">
                          <span class="text-body-secondary"><?= e($rankName) ?> quota</span>
                          <strong class="commission-num"><?= e(money($quota)) ?></strong>
                        </li>
                        <li class="d-flex align-items-center justify-content-between py-1">
                          <span class="text-body-secondary">Your rates</span>
                          <strong class="commission-num">
                            <?= e(number_format($groupPct, 3, '.', '')) ?>% group /
                            <?= e(number_format($personalPct, 3, '.', '')) ?>% personal
                          </strong>
                        </li>
<?php endif; ?>
                      </ul>

<?php if ($enabled && $paying): ?>
                      <div class="d-flex flex-wrap align-items-baseline justify-content-between gap-2 mt-3 mb-1">
                        <span class="small text-body-secondary">Quota progress</span>
                        <strong class="small"><?= $quotaMet ? 'Quota met' : $progress . '%' ?></strong>
                      </div>
                      <div
                        class="progress commission-progress"
                        role="progressbar"
                        aria-valuenow="<?= $progress ?>"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        aria-label="Group sales against the monthly quota"
                      >
                        <div class="progress-bar bg-<?= $quotaMet ? 'success' : 'primary' ?>" style="width: <?= $progress ?>%"></div>
                      </div>
<?php endif; ?>

<?php if ($enabled): ?>
<?php if (!$paying): ?>
                      <p class="small text-body-secondary mt-3 mb-0">
                        Royalty starts at Supervisor. Reach Supervisor first; the group quota applies from
                        there.
                      </p>
<?php elseif (!$eligible): ?>
                      <div class="alert alert-secondary py-2 px-3 small mt-3 mb-0" role="status">
                        If this month closed now: <strong><?= e(money(0)) ?></strong>.
                        Your account must be active for the month close to pay Royalty.
                      </div>
<?php elseif (!$quotaMet): ?>
                      <div class="alert alert-secondary py-2 px-3 small mt-3 mb-0" role="status">
                        If this month closed now: <strong><?= e(money(0)) ?></strong>.
                        Group sales are <?= e(money($gap)) ?> below the <?= e(money($quota)) ?> quota.
                        Missing the quota by one centavo pays nothing — including the personal rate.
                      </div>
<?php else: ?>
                      <div class="alert alert-success py-2 px-3 mt-3 mb-0" role="status">
                        <span class="small">
                          <strong>If this month closed now: <?= e(money($projected)) ?></strong>
                        </span>
                        <span class="d-block small text-body-secondary">
                          Nothing is paid until the month closes and the monthly Royalty run evaluates it.
                        </span>
                      </div>
<?php endif; ?>
<?php endif; ?>
                    </div>
                  </div>
                </div>
                <div class="card-footer small text-body-secondary">
                  Royalty is paid once a month, after the month closes. Group sales include your own
                  purchases and every member below you, at any depth. Your own purchases are also paid the
                  personal rate, so they count twice.
<?php if ($payments === false): ?>
                  <span class="d-block mt-1">
                    <i class="icon-base bx bx-pause-circle me-1"></i>
                    Payment is paused right now: an evaluated award stays <strong>Awaiting payment</strong>
                    until payments resume.
                  </span>
<?php endif; ?>
                </div>
              </div>
