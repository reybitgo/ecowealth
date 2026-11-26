<?php
declare(strict_types=1);

/**
 * Member dashboard — the KPI row.
 *
 * One live region: membership, the E-wallet KPI, commissions and team are
 * replaced together, so a status change, a wallet movement, a commission
 * award or a new downline line refreshes the whole row from one server
 * render. Computing the cards here (rather than in the page) keeps the
 * page and the JSON feed on exactly the same rules.
 *
 * Phase 18 adds one small line under the row when repeat-purchase money
 * is pending release (plan §10.2): the withdrawable KPI must never look
 * as if the member's whole "extra" money disappeared. The line is a
 * read-out only — the amount is not withdrawable and not spendable.
 *
 * `$pending_repeat` is the controller's summary. `member/dashboard.php`
 * (outside this work package) does not pass it into the capture yet, so
 * the partial falls back to the same per-member read when it is missing
 * — the page and the live region then show the same line instead of the
 * feed adding it on the next poll.
 *
 * @var array<string, mixed> $member
 * @var array{count:int, credited:float, this_month:float, last_at:?string} $commissions
 * @var array{direct:int, team:int, levels:int} $team
 * @var float $balance
 * @var float $withdrawable
 * @var array{total:float, count:int, next_release_at:?string, undelivered:int, overdue:int, release_enabled:bool} $pending_repeat
 */

$status     = (string) ($member['member_status'] ?? '');
$hasPackage = ($member['package_id'] ?? null) !== null;
$count      = (int) ($commissions['count'] ?? 0);
$levels     = (int) ($team['levels'] ?? 0);

$pending       = $pending_repeat ?? UnilevelAward::pendingSummaryForUser((int) ($member['id'] ?? 0));
$pending       = is_array($pending) ? $pending : [];
$pendingTotal  = round((float) ($pending['total'] ?? 0), 2);
$pendingShown  = $pendingTotal > 0;

$statusLabel = match (true) {
    $status === 'active' && $hasPackage => 'Active associate',
    $status === 'active'                => 'Standard member',
    $status === 'pending'               => 'Pending activation',
    $status === 'suspended'             => 'Suspended',
    default                             => ucfirst($status),
};

$kpis = [
    [
        'label' => 'Membership',
        'value' => $hasPackage ? (string) ($member['package_name'] ?? 'Package') : 'No package',
        'sub'   => $statusLabel,
        'icon'  => $hasPackage ? 'bx-medal' : 'bx-user-check',
        'tone'  => $hasPackage ? 'primary' : 'info',
    ],
    [
        'label' => 'Commissions',
        'value' => money((float) ($commissions['credited'] ?? 0)),
        'sub'   => $count === 0
            ? 'No awards yet'
            : money((float) ($commissions['this_month'] ?? 0)) . ' this month',
        'icon'  => 'bx-line-chart',
        'tone'  => 'warning',
    ],
    [
        'label' => 'Team',
        'value' => (string) (int) ($team['team'] ?? 0),
        'sub'   => (int) ($team['direct'] ?? 0) . ' direct · ' . $levels . ' level' . ($levels === 1 ? '' : 's'),
        'icon'  => 'bx-git-branch',
        'tone'  => 'info',
    ],
];
?>
              <div class="row g-6 mb-6" data-live-region="dashboard-kpis">
<?= View::capture('member/dashboard/_kpi', ['kpi' => $kpis[0]]) ?>
<?= View::capture('member/dashboard/_wallet_kpi', ['balance' => $balance, 'withdrawable' => $withdrawable]) ?>
<?= View::capture('member/dashboard/_kpi', ['kpi' => $kpis[1]]) ?>
<?= View::capture('member/dashboard/_kpi', ['kpi' => $kpis[2]]) ?>
<?php if ($pendingShown): ?>
                <div class="col-12">
                  <p class="small text-body-secondary mb-0 mt-2">
                    <i class="icon-base bx bx-time-five me-1"></i>
                    <strong><?= e(money($pendingTotal)) ?> pending release</strong> — repeat-purchase bonus that is
                    not withdrawable yet.
                    <a href="<?= e(url('earnings?ledger=repeat')) ?>">See the statement</a>.
                  </p>
                </div>
<?php endif; ?>
              </div>
