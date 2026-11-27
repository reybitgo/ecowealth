<?php
declare(strict_types=1);

/**
 * Member dashboard — the Royalty tile (Phase 19E, plan §9, Appendix C.4).
 *
 * A live region: the monthly close and every payment bump the member's
 * dashboard surface, so the rank badge, this month's group sales and the
 * all-time paid figure refresh in place. The tile is a read-out — it has
 * no form.
 *
 * The copy never promises money the engine will not pay: the projection
 * is the engine's own `statusFor()`, the quota progress is this month's
 * group sales against the rank's quota, and the only peso figure stated
 * as money is what already reached the wallet.
 *
 * Visibility (Rule 5): hidden only when the stream is off **and** the
 * member has no history. Otherwise the tile renders — with an explicit
 * "Royalty is currently off" note when the stream is off — and the
 * statement stays one click away.
 *
 * @var array<string, mixed>|null $royalty
 */

$royalty = $royalty ?? null;

if (!is_array($royalty)) {
    return;
}

$enabled  = (bool) ($royalty['enabled'] ?? false);
$history  = (bool) ($royalty['has_history'] ?? false);

if (!$enabled && !$history) {
    return;
}

$rank        = (int) ($royalty['rank'] ?? RoyaltyRank::MEMBER);
$rankName    = (string) ($royalty['rank_name'] ?? RoyaltyRank::NAMES[$rank] ?? 'Member');
$group       = (float) ($royalty['group'] ?? 0);
$quota       = (float) ($royalty['quota'] ?? 0);
$progress    = max(0, min(100, (int) ($royalty['progress'] ?? 0)));
$projected   = (float) ($royalty['projected'] ?? 0);
$paidTotal   = (float) ($royalty['paid_all_time'] ?? 0);
$lastPaid    = $royalty['last_paid'] ?? null;
$next        = is_array($royalty['next'] ?? null) ? $royalty['next'] : null;
$paying      = in_array($rank, RoyaltyRank::PAYING, true);
$quotaMet    = $quota > 0 && $group >= $quota;

$tone     = RoyaltyRank::LABELS[$rank]['tone'] ?? 'secondary';
$icon     = RoyaltyRank::LABELS[$rank]['icon'] ?? 'bx-user';
$statement = url('earnings?ledger=royalty');

/* The single step to the next rank, in member-facing words. */
$nextRank = $next === null ? 0 : (int) ($next['rank'] ?? 0);
$need     = $next === null ? 0 : max(0, (int) ($next['need'] ?? 0));
$have     = $next === null ? 0 : max(0, (int) ($next['have'] ?? 0));

$step = match ($nextRank) {
    RoyaltyRank::LEADER     => 'Reach ' . $need . ' direct member' . ($need === 1 ? '' : 's') . ' to become a Leader.',
    RoyaltyRank::SUPERVISOR => $have . ' of ' . $need . ' Leader' . ($need === 1 ? '' : 's') . ' in your team.',
    RoyaltyRank::MANAGER    => $have . ' of ' . $need . ' Supervisor' . ($need === 1 ? '' : 's') . ' in your team.',
    RoyaltyRank::DIRECTOR   => $have . ' of ' . $need . ' Manager' . ($need === 1 ? '' : 's') . ' in your team.',
    default                 => 'Highest rank reached.',
};

$lastPaidLabel = '';

if (is_array($lastPaid) && isset($lastPaid['period'])) {
    $lastPaidLabel = format_month((string) $lastPaid['period'], (string) $lastPaid['period']);
}
?>
              <div class="row g-6 mb-6" data-live-region="dashboard-royalty">
                <div class="col-12">
                  <div class="card h-100">
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <div class="d-flex align-items-center gap-2">
                        <h5 class="card-title mb-0">Royalty</h5>
                        <span class="badge bg-label-<?= e($tone) ?>">
                          <i class="icon-base <?= e(icon_class($icon)) ?> me-1"></i><?= e($rankName) ?>
                        </span>
                      </div>
                      <a href="<?= e($statement) ?>" class="btn btn-sm btn-label-primary">
                        <?= $history ? 'Royalty statement' : 'Open Royalty' ?>
                      </a>
                    </div>
                    <div class="card-body">
<?php if (!$enabled): ?>
                      <div class="alert alert-warning py-2 px-3 small mb-3" role="status">
                        <strong>Royalty is currently off.</strong>
                        Past months stay in your statement; nothing new is paid while it is off.
                      </div>
<?php endif; ?>

<?php if (!$enabled): ?>
                      <p class="small text-body-secondary mb-0"><?= e($step) ?></p>
<?php elseif ($paying): ?>
                      <p class="small text-body-secondary mb-3">
                        <?= e($rankName) ?> · <span class="commission-num"><?= e(money($group)) ?></span> of
                        <span class="commission-num"><?= e(money($quota)) ?></span> group this month
                        <?= $quotaMet ? '— quota met.' : '— ' . $progress . '% of the quota.' ?>
                      </p>
                      <div
                        class="progress commission-progress mb-3"
                        role="progressbar"
                        aria-valuenow="<?= $progress ?>"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        aria-label="This month's group sales against the Royalty quota"
                      >
                        <div class="progress-bar bg-<?= $quotaMet ? 'success' : 'primary' ?>" style="width: <?= $progress ?>%"></div>
                      </div>
<?php elseif ($rank === RoyaltyRank::LEADER): ?>
                      <p class="small text-body-secondary mb-0">
                        Leader — Royalty starts at Supervisor. <?= e($step) ?>
                      </p>
<?php else: ?>
                      <p class="small text-body-secondary mb-0"><?= e($step) ?></p>
<?php endif; ?>

                      <div class="row g-3 mt-1">
                        <div class="col-6 col-lg-3">
                          <div class="small text-body-secondary">Group sales this month</div>
                          <div class="h5 mb-0 commission-num"><?= e(money($group)) ?></div>
                        </div>
<?php if ($enabled && $paying): ?>
                        <div class="col-6 col-lg-3">
                          <div class="small text-body-secondary">If this month closed now</div>
                          <div class="h5 mb-0 commission-num"><?= e(money($projected)) ?></div>
                        </div>
<?php endif; ?>
                        <div class="col-6 col-lg-3">
                          <div class="small text-body-secondary">Paid all-time</div>
                          <div class="h5 mb-0 commission-num"><?= e(money($paidTotal)) ?></div>
                        </div>
                        <div class="col-6 col-lg-3">
                          <div class="small text-body-secondary">Last paid</div>
                          <div class="h5 mb-0">
<?php if ($lastPaidLabel === ''): ?>
                            <span class="text-body-secondary">—</span>
<?php else: ?>
                            <?= e($lastPaidLabel) ?>
<?php endif; ?>
                          </div>
                        </div>
                      </div>
                    </div>
                    <div class="card-footer small text-body-secondary">
                      Royalty is paid once a month, after the month closes. Group sales include your own
                      purchases and every member below you, at any depth.
                    </div>
                  </div>
                </div>
              </div>
