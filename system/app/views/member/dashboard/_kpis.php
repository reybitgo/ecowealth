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
 * @var array<string, mixed> $member
 * @var array{count:int, credited:float, this_month:float, last_at:?string} $commissions
 * @var array{direct:int, team:int, levels:int} $team
 * @var float $balance
 * @var float $withdrawable
 */

$status     = (string) ($member['member_status'] ?? '');
$hasPackage = ($member['package_id'] ?? null) !== null;
$count      = (int) ($commissions['count'] ?? 0);
$levels     = (int) ($team['levels'] ?? 0);

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
              <div class="row g-4 mb-4" data-live-region="dashboard-kpis">
<?= View::capture('member/dashboard/_kpi', ['kpi' => $kpis[0]]) ?>
<?= View::capture('member/dashboard/_wallet_kpi', ['balance' => $balance, 'withdrawable' => $withdrawable]) ?>
<?= View::capture('member/dashboard/_kpi', ['kpi' => $kpis[1]]) ?>
<?= View::capture('member/dashboard/_kpi', ['kpi' => $kpis[2]]) ?>
              </div>
