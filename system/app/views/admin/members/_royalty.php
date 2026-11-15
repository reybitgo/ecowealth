<?php
declare(strict_types=1);

/**
 * Admin member page — Royalty panel (Phase 19D, plan §8, §14.11).
 *
 * Read-only. Shows the rank the engine derives right now, the quota
 * progress, this month's personal and group volume against the quota,
 * the member's last six stored awards and the all-time paid figure.
 * The numbers come from `RoyaltyService::statusFor()`, the same read the
 * member's own card will use (19E), so the two surfaces cannot promise
 * different amounts.
 *
 * A stored award is the truth for a closed month; the panel never
 * recalculates it (plan D-13).
 *
 * @var array<string, mixed>       $member
 * @var array<string, mixed>|null  $royalty
 */

$member  = $member ?? [];
$royalty = $royalty ?? null;

$username = (string) ($member['username'] ?? '');

$rankTones = [
    RoyaltyRank::DIRECTOR   => 'success',
    RoyaltyRank::MANAGER    => 'warning',
    RoyaltyRank::SUPERVISOR => 'primary',
    RoyaltyRank::LEADER     => 'info',
    RoyaltyRank::MEMBER     => 'secondary',
];

$statusBadges = [
    'pending' => ['Awaiting payment', 'warning'],
    'paid'    => ['Paid', 'success'],
    'skipped' => ['Not paid', 'secondary'],
];

$monthLabel = static function (string $period): string {
    $ts = strtotime($period . '-01 12:00:00');

    return $ts === false ? $period : date('M Y', $ts);
};

$ledgerUrl = url('admin/commissions?ledger=royalty&q=' . rawurlencode($username));
?>
                  <div class="card" data-live-region="member-royalty">
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <div>
                        <h5 class="card-title mb-1">Royalty</h5>
                        <p class="text-body-secondary small mb-0">
                          One award per month, after the close. Derived from the rank ladder and the
                          member's paid orders.
                        </p>
                      </div>
                      <a href="<?= e($ledgerUrl) ?>" class="btn btn-sm btn-label-primary">Open the ledger</a>
                    </div>
<?php if ($royalty === null): ?>
                    <div class="card-body">
                      <p class="text-body-secondary mb-0">
                        The Royalty panel is unavailable right now. The rest of this page is unaffected.
                      </p>
                    </div>
<?php else: ?>
<?php
    $rank       = (int) ($royalty['rank'] ?? 0);
    $rankName   = (string) ($royalty['rank_name'] ?? RoyaltyRank::NAMES[$rank] ?? 'Member');
    $period     = (string) ($royalty['period'] ?? '');
    $personal   = (float) ($royalty['personal'] ?? 0);
    $group      = (float) ($royalty['group'] ?? 0);
    $quota      = (float) ($royalty['quota'] ?? 0);
    $progress   = max(0, min(100, (int) ($royalty['progress'] ?? 0)));
    $met        = $quota > 0 && $group >= $quota;
    $enabled    = (bool) ($royalty['enabled'] ?? false);
    $paidAll    = (float) ($royalty['paid_all_time'] ?? 0);
    $lastPaid   = $royalty['last_paid'] ?? null;
    $next       = $royalty['next'] ?? null;
    $history    = is_array($royalty['history'] ?? null) ? array_slice($royalty['history'], 0, 6) : [];
    $downline   = is_array($royalty['downline'] ?? null) ? $royalty['downline'] : [];
    $directs    = (int) ($royalty['directs'] ?? 0);
?>
                    <div class="card-body">
<?php if (!$enabled): ?>
                      <div class="alert alert-warning py-2 small" role="status">
                        Royalty is currently off. The panel still shows the rank and this month's volume;
                        no new award will be evaluated until the stream is turned on.
                      </div>
<?php endif; ?>
                      <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                        <div class="d-flex align-items-center gap-2">
                          <span class="badge bg-label-<?= e($rankTones[$rank] ?? 'secondary') ?>"><?= e($rankName) ?></span>
                          <span class="small text-body-secondary"><?= e($monthLabel($period)) ?> · month to date</span>
                        </div>
                        <div class="small text-body-secondary">
                          <?= $directs ?> direct<?= $directs === 1 ? '' : 's' ?> ·
                          <?= (int) ($downline['leaders'] ?? 0) ?> Leaders ·
                          <?= (int) ($downline['supervisors'] ?? 0) ?> Supervisors ·
                          <?= (int) ($downline['managers'] ?? 0) ?> Managers below
                        </div>
                      </div>

                      <div class="mb-3">
                        <div class="d-flex align-items-center justify-content-between small mb-1">
                          <span class="text-body-secondary">Quota progress</span>
                          <span class="fw-semibold"><?= $progress ?>%</span>
                        </div>
                        <div class="progress" role="progressbar" aria-valuenow="<?= $progress ?>" aria-valuemin="0" aria-valuemax="100" style="height: 8px">
                          <div class="progress-bar<?= $met ? ' bg-success' : '' ?>" style="width: <?= $progress ?>%"></div>
                        </div>
                        <div class="small text-body-secondary mt-1">
                          Group sales <?= e(money($group)) ?> of the <?= e(money($quota)) ?> quota —
                          <?= $met ? 'met' : 'not met yet' ?>.
                        </div>
                      </div>

                      <div class="row g-3 mb-3">
                        <div class="col-6 col-xl-4">
                          <div class="status-tile">
                            <div class="status-tile-label">Personal this month</div>
                            <div class="status-tile-value"><?= e(money($personal)) ?></div>
                          </div>
                        </div>
                        <div class="col-6 col-xl-4">
                          <div class="status-tile">
                            <div class="status-tile-label">Group this month</div>
                            <div class="status-tile-value"><?= e(money($group)) ?></div>
                          </div>
                        </div>
                        <div class="col-12 col-xl-4">
                          <div class="status-tile">
                            <div class="status-tile-label">Monthly quota</div>
                            <div class="status-tile-value"><?= e(money($quota)) ?></div>
                          </div>
                        </div>
                      </div>

                      <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 small py-1">
                        <span class="text-body-secondary">Next rank</span>
                        <span class="fw-semibold">
<?php if ($next === null): ?>
                          Top rank reached
<?php else: ?>
                          <?= e((string) ($next['name'] ?? '')) ?> —
                          <?= (int) ($next['have'] ?? 0) ?> of <?= (int) ($next['need'] ?? 0) ?>
<?php endif; ?>
                        </span>
                      </div>
                      <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 small py-1">
                        <span class="text-body-secondary">Paid all time</span>
                        <span class="fw-semibold"><?= e(money($paidAll)) ?></span>
                      </div>
<?php if (is_array($lastPaid)): ?>
                      <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 small py-1">
                        <span class="text-body-secondary">Last paid</span>
                        <span class="fw-semibold">
                          <?= e(money((float) ($lastPaid['amount'] ?? 0))) ?>
                          <span class="text-body-secondary fw-normal">· <?= e($monthLabel((string) ($lastPaid['period'] ?? ''))) ?></span>
                        </span>
                      </div>
<?php endif; ?>
                    </div>

                    <div class="table-responsive border-top">
                      <table class="table table-sm align-middle mb-0">
                        <thead>
                          <tr>
                            <th scope="col">Last six awards</th>
                            <th scope="col">Rank</th>
                            <th scope="col" class="text-end">Amount</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="text-nowrap">Paid</th>
                          </tr>
                        </thead>
                        <tbody>
<?php foreach ($history as $award): ?>
<?php
    $awardStatus = (string) ($award['status'] ?? '');
    $awardRank   = (int) ($award['rank_level'] ?? 0);
    $reason      = trim((string) ($award['reason'] ?? ''));
?>
                          <tr>
                            <td class="text-nowrap fw-medium"><?= e($monthLabel((string) ($award['period'] ?? ''))) ?></td>
                            <td class="text-nowrap"><?= e(RoyaltyRank::NAMES[$awardRank] ?? '—') ?></td>
                            <td class="text-end text-nowrap"><?= e(money((float) ($award['amount'] ?? 0))) ?></td>
                            <td>
                              <?= status_badge($awardStatus, $statusBadges) ?>
<?php if ($awardStatus === 'skipped' && $reason !== ''): ?>
                              <div class="small text-body-secondary commission-reason"><?= e($reason) ?></div>
<?php endif; ?>
                            </td>
                            <td class="text-nowrap small text-body-secondary">
                              <?= ($award['credited_at'] ?? null) === null || (string) $award['credited_at'] === ''
                                  ? '—'
                                  : e(format_date((string) $award['credited_at'])) ?>
                            </td>
                          </tr>
<?php endforeach; ?>
<?php if ($history === []): ?>
                          <tr>
                            <td colspan="5" class="text-body-secondary">
                              No Royalty awards yet. Royalty is paid after the month closes.
                            </td>
                          </tr>
<?php endif; ?>
                        </tbody>
                      </table>
                    </div>
<?php endif; ?>
                    <div class="card-footer small text-body-secondary">
                      Read-only. A closed month is never recalculated; a cancellation after the close does not
                      claw Royalty back.
                    </div>
                  </div>
