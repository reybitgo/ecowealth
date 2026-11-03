<?php
declare(strict_types=1);

/* ==============================================================
 *  Dashboard (protected page)
 *  "Growth overview" — the Eco-wealth concept from index.html,
 *  expressed with the app's UI components and live community data.
 * ============================================================== */
require __DIR__ . '/../app/bootstrap.php';

require_login();

/* Standalone pages honour maintenance mode too (plan §3.3); this page is
 * re-routed in Phase 9, at which point the front controller gates it. */
MaintenanceGate::handle();

$user = auth_user();

/* Admins land on the back-office dashboard: /dashboard and /admin then
 * render the same operations screen with the admin sidebar, so signing
 * in as an admin never drops you into the member "Growth overview". */
if (is_admin($user)) {
    (new AdminDashboardController())->index();
    exit;
}

/* --------------------------------------------------------------
 *  Community data (the only figures this app really tracks)
 * -------------------------------------------------------------- */
$days        = 30;
$chartDates  = [];
$signupByDay = [];

$today = new DateTimeImmutable('today');
for ($i = $days - 1; $i >= 0; $i--) {
    $day             = $today->modify('-' . $i . ' day');
    $chartDates[]    = $day->format('M j');
    $signupByDay[$day->format('Y-m-d')] = 0;
}

$stats = [
    'total_users'       => 0,
    'signups_today'     => 0,
    'signups_week'      => 0,
    'signups_prev_week' => 0,
    'active_week'       => 0,
];

$members = [];

try {
    $db = db();

    $stats['total_users']   = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $stats['signups_today'] = (int) $db->query('SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()')->fetchColumn();
    $stats['active_week']   = (int) $db->query('SELECT COUNT(*) FROM users WHERE last_login_at >= CURDATE() - INTERVAL 6 DAY')->fetchColumn();
    $stats['signups_week']  = (int) $db->query('SELECT COUNT(*) FROM users WHERE created_at >= CURDATE() - INTERVAL 6 DAY')->fetchColumn();
    $stats['signups_prev_week'] = (int) $db->query(
        'SELECT COUNT(*) FROM users
         WHERE created_at >= CURDATE() - INTERVAL 13 DAY
           AND created_at <  CURDATE() - INTERVAL 6 DAY'
    )->fetchColumn();

    $stmt = $db->query(
        'SELECT DATE(created_at) AS d, COUNT(*) AS c
         FROM users
         WHERE created_at >= CURDATE() - INTERVAL 29 DAY
         GROUP BY DATE(created_at)'
    );
    foreach ($stmt as $row) {
        $key = (string) $row['d'];
        if (array_key_exists($key, $signupByDay)) {
            $signupByDay[$key] = (int) $row['c'];
        }
    }

    $members = $db->query(
        'SELECT id, username, email, full_name, created_at, last_login_at
         FROM users
         ORDER BY created_at DESC, id DESC
         LIMIT 25'
    )->fetchAll();
} catch (Throwable $e) {
    error_log('[eco-wealth] dashboard data: ' . $e->getMessage());
}

/* --------------------------------------------------------------
 *  Derived values
 * -------------------------------------------------------------- */
$trendPct = null;
if ($stats['signups_prev_week'] > 0) {
    $trendPct = (($stats['signups_week'] - $stats['signups_prev_week']) / $stats['signups_prev_week']) * 100;
} elseif ($stats['signups_week'] > 0) {
    $trendPct = 100.0;
}
$trendDir = $trendPct === null ? 'flat' : ($trendPct > 0 ? 'up' : ($trendPct < 0 ? 'down' : 'flat'));

$displayName = trim((string) ($user['full_name'] ?? '')) ?: (string) $user['username'];

/* Recent participation events (real sign-up / sign-in activity) */
$events = [];
foreach ($members as $member) {
    $createdTs = strtotime((string) ($member['created_at'] ?? ''));
    if ($createdTs !== false) {
        $events[] = [
            'ts'    => $createdTs,
            'tone'  => 'primary',
            'title' => $member['username'] . ' joined the community',
            'text'  => 'A new member started the Eco-wealth journey.',
            'when'  => time_ago((string) $member['created_at']),
        ];
    }

    $loginTs = strtotime((string) ($member['last_login_at'] ?? ''));
    if ($loginTs !== false) {
        $events[] = [
            'ts'    => $loginTs,
            'tone'  => 'success',
            'title' => $member['username'] . ' checked in',
            'text'  => 'Participation step revisited.',
            'when'  => time_ago((string) $member['last_login_at']),
        ];
    }
}
usort($events, static fn (array $a, array $b): int => $b['ts'] <=> $a['ts']);
$events = array_slice($events, 0, 5);

/* Navbar notifications */
$nav_notes = [];
foreach (array_slice($members, 0, 4) as $member) {
    $nav_notes[] = [
        'icon'  => 'bx bx-user-plus',
        'tone'  => 'primary',
        'title' => '@' . $member['username'],
        'text'  => 'Joined ' . time_ago((string) $member['created_at']),
        'time'  => '',
    ];
}

$nav_badges = ['members' => $stats['total_users']];

/* --------------------------------------------------------------
 *  The vocabulary of index.html
 * -------------------------------------------------------------- */
$pillars = [
    [
        'no'    => '01 / WELL-BEING',
        'title' => 'Health',
        'text'  => 'Wellness begins with everyday choices. We aim to cultivate a community that values personal well-being, informed decisions and products that support a wellness-oriented lifestyle.',
        'tag'   => 'Care for everyday life',
        'icon'  => 'bx-heart',
        'tone'  => 'success',
    ],
    [
        'no'    => '02 / PARTICIPATION',
        'title' => 'Wealth',
        'text'  => 'Economic progress grows from initiative, learning and productive activity. We encourage entrepreneurship, customer relationships and responsible business development.',
        'tag'   => 'Build through participation',
        'icon'  => 'bx-line-chart',
        'tone'  => 'warning',
    ],
    [
        'no'    => '03 / OPPORTUNITY',
        'title' => 'Livelihood',
        'text'  => 'We want people to have opportunities to develop business skills, participate in product distribution, build relationships and pursue meaningful livelihood activities.',
        'tag'   => 'Create paths forward',
        'icon'  => 'bx-home-smile',
        'tone'  => 'info',
    ],
];

$dimensions = [
    ['index' => '01 / MATRIX',           'title' => 'General Harvest',  'text' => 'A shared matrix where qualifying members contribute to the progression of a larger community structure.'],
    ['index' => '02 / TEAM',             'title' => 'Team Harvest',     'text' => 'An individual team matrix that develops as qualifying members join your organization over time.'],
    ['index' => '03 / LEADERSHIP',       'title' => 'Royalty',          'text' => 'A five-stage leadership ladder from Member to Director, based on defined team-development milestones.'],
    ['index' => '04 / PRODUCT ACTIVITY', 'title' => 'Unilevel',         'text' => 'A repeat-purchase mechanism connecting product activity with a multi-level reward structure and monthly activity criteria.'],
    ['index' => '05 / REFERRAL',         'title' => 'Indirect Referral', 'text' => 'A structured mechanism that recognizes the wider sponsor network through multiple upline levels.'],
];

$leadership = ['Member', 'Leader', 'Supervisor', 'Manager', 'Director'];

$participation = [
    ['title' => 'Understand the products',       'text' => 'Start with clear product information, intended use and informed choices. Product details should be grounded in official descriptions and documentation.'],
    ['title' => 'Build genuine relationships',   'text' => 'Listen to customer needs and share accurate information. Trust is built through honest conversations, not pressure or exaggerated promises.'],
    ['title' => 'Support ongoing product activity', 'text' => 'Repeat purchases are a distinct part of the model, connecting continued product activity with the Unilevel mechanism.'],
    ['title' => 'Develop people and teams',      'text' => 'Community and leadership development add another dimension, with separate matrix, referral and Royalty progression mechanics.'],
];

$journey = [
    ['no' => 'STEP 01', 'title' => 'Discover',    'text' => 'Learn about the wellness products and the wider Eco-wealth vision.'],
    ['no' => 'STEP 02', 'title' => 'Participate', 'text' => 'Build product familiarity and meaningful customer and community relationships.'],
    ['no' => 'STEP 03', 'title' => 'Develop',     'text' => 'Connect with others, support team development and follow shared milestones.'],
    ['no' => 'STEP 04', 'title' => 'Progress',    'text' => 'Explore qualification and leadership progression as the community grows.'],
];

$faq = [
    ['q' => 'Is this the finished Eco-wealth platform?', 'a' => 'No. This website introduces the company vision and gives a preliminary look at the growth concept. The production platform is still being developed.'],
    ['q' => 'Are the simulator figures actual payouts?', 'a' => 'No. The simulator is an experimental, browser-based sandbox. Its figures and calculations are illustrative only; it does not process real purchases, financial transactions or payouts.'],
    ['q' => 'What products does Eco-wealth offer?', 'a' => 'This preview does not publish a product catalogue. Specific product names, descriptions, ingredients, prices and availability should be checked against the official product information when it is released.'],
    ['q' => 'How does the growth model work?', 'a' => 'The current simulator demonstrates five separate mechanisms: General Harvest, Team Harvest, Royalty, Unilevel and Indirect Referral. They share a sponsor network but have different qualification and activity rules.'],
    ['q' => 'Does registration or inviting someone guarantee compensation?', 'a' => 'No compensation or income is guaranteed. Any real-world eligibility, amounts and payment conditions must be stated in the finalized official program rules.'],
    ['q' => 'Is this an investment offer?', 'a' => 'This page presents a wellness, product-participation and livelihood concept. It does not offer an investment, promise returns or guarantee earnings.'],
];

/* --------------------------------------------------------------
 *  Page assets
 * -------------------------------------------------------------- */
$page_css = [
    'assets/vendor/libs/apex-charts/apex-charts.css',
    'assets/vendor/libs/datatables-bs5/datatables.bootstrap5.css',
    'assets/vendor/libs/datatables-responsive-bs5/responsive.bootstrap5.css',
];

$page_js = [
    'assets/vendor/libs/apex-charts/apexcharts.js',
    'assets/vendor/libs/datatables-bs5/datatables-bootstrap5.js',
    'assets/js/dashboards-ecowealth.js',
];

$page_styles = <<<CSS
html { scroll-padding-top: 6rem; }
.dash-hero {
  position: relative;
  background:
    radial-gradient(120% 140% at 100% 0%, rgba(var(--bs-primary-rgb), 0.16) 0%, rgba(var(--bs-primary-rgb), 0) 58%),
    linear-gradient(125deg, rgba(var(--bs-primary-rgb), 0.10) 0%, rgba(var(--bs-info-rgb), 0.06) 52%, rgba(var(--bs-primary-rgb), 0) 100%);
}
.dash-hero-inner { position: relative; z-index: 2; }
@media (min-width: 768px) {
  .dash-hero-inner { padding-inline-end: 190px; }
}
.dash-hero-art {
  position: absolute;
  inset-inline-end: 1.5rem;
  bottom: 0;
  width: 176px;
  max-width: 34%;
  z-index: 1;
  pointer-events: none;
}
@media (max-width: 767.98px) {
  .dash-hero-art { display: none; }
}
.dash-hero .avatar-lg { width: 3.25rem; height: 3.25rem; }
.kpi-card .avatar-initial { width: 2.5rem; height: 2.5rem; }
.matrix-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.5rem; max-width: 210px; margin-inline: auto; }
.matrix-cell {
  aspect-ratio: 1;
  border-radius: 0.6rem;
  background: rgba(var(--bs-primary-rgb), 0.10);
  border: 1px solid rgba(var(--bs-primary-rgb), 0.16);
}
.matrix-cell.is-center {
  background: rgba(var(--bs-primary-rgb), 0.75);
  border-color: transparent;
}
.rank-row-active { background: rgba(var(--bs-primary-rgb), 0.06); border-radius: 0.5rem; }
CSS;

$page_title  = 'Growth overview';
$current_nav = 'dashboard';

require __DIR__ . '/../app/layout/app_header.php';
?>
            <!-- Content -->
            <div class="container-xxl flex-grow-1 container-p-y">
              <?= flash_render() ?>

              <!-- Page heading -->
              <div class="d-flex align-items-start align-items-sm-center justify-content-between flex-column flex-sm-row gap-3 mb-4">
                <div>
                  <h4 class="mb-1">Growth overview</h4>
                  <p class="text-body-secondary mb-0">
                    A wellness and livelihood ecosystem &mdash; community view for
                    <strong><?= e($user['username']) ?></strong>.
                  </p>
                </div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                  <span class="badge rounded-pill bg-label-warning">Concept preview</span>
                  <span class="badge rounded-pill bg-label-secondary">In development</span>
                  <a href="<?= e(url('register')) ?>" class="btn btn-sm btn-primary">
                    <i class="icon-base bx bx-user-plus me-1"></i> Add member
                  </a>
                </div>
              </div>

              <!-- Section pills -->
              <ul class="nav nav-pills flex-nowrap overflow-auto mb-4 gap-1 pb-1">
                <li class="nav-item"><a class="nav-link active text-nowrap" href="#overview"><i class="icon-base bx bx-home-smile me-1"></i>Overview</a></li>
                <li class="nav-item"><a class="nav-link text-nowrap" href="#community"><i class="icon-base bx bx-group me-1"></i>Community</a></li>
                <li class="nav-item"><a class="nav-link text-nowrap" href="#dimensions"><i class="icon-base bx bx-layer me-1"></i>Ecosystem</a></li>
                <li class="nav-item"><a class="nav-link text-nowrap" href="#participation"><i class="icon-base bx bx-check-shield me-1"></i>Participation</a></li>
                <li class="nav-item"><a class="nav-link text-nowrap" href="#questions"><i class="icon-base bx bx-help-circle me-1"></i>Questions</a></li>
              </ul>

              <div class="row gy-4" id="overview">
                <!-- Welcome -->
                <div class="col-lg-7">
                  <div class="card h-100 dash-hero overflow-hidden">
                    <div class="card-body dash-hero-inner">
                      <div class="d-flex align-items-center gap-3 mb-4">
                        <div class="avatar avatar-lg">
                          <span class="avatar-initial rounded-circle bg-label-primary fw-semibold fs-4">
                            <?= e(initials((string) $user['username'])) ?>
                          </span>
                        </div>
                        <div>
                          <h4 class="mb-1">Welcome back, <?= e($displayName) ?> &#x1f44b;</h4>
                          <p class="mb-0 text-body-secondary">
                            Wellness for life. Opportunity for everyone &mdash;
                            <strong><?= $stats['total_users'] ?></strong> member<?= $stats['total_users'] === 1 ? '' : 's' ?>
                            in this community view.
                          </p>
                        </div>
                      </div>

                      <div class="d-flex flex-wrap gap-2 mb-0">
                        <a href="#community" class="btn btn-primary btn-sm">
                          <i class="icon-base bx bx-group me-1"></i> Community
                        </a>
                        <a href="#dimensions" class="btn btn-outline-primary btn-sm">
                          <i class="icon-base bx bx-layer me-1"></i> Growth ecosystem
                        </a>
                        <form method="POST" action="<?= e(url('logout')) ?>" class="d-inline">
                          <?= csrf_field() ?>
                          <button type="submit" class="btn btn-outline-secondary btn-sm">
                            <i class="icon-base bx bx-power-off me-1"></i> Sign out
                          </button>
                        </form>
                      </div>
                    </div>
                    <img
                      class="dash-hero-art"
                      src="<?= e(url('assets/img/illustrations/man-with-laptop.png')) ?>"
                      alt=""
                      aria-hidden="true"
                    />
                  </div>
                </div>

                <!-- Your membership -->
                <div class="col-lg-5" id="membership">
                  <div class="card h-100">
                    <div class="card-header d-flex align-items-center justify-content-between">
                      <h5 class="card-title mb-0">Your membership</h5>
                      <span class="badge rounded-pill bg-label-primary">Community</span>
                    </div>
                    <div class="card-body">
                      <div class="d-flex align-items-center gap-3 pb-3 mb-3 border-bottom">
                        <div class="avatar">
                          <span class="avatar-initial rounded-circle bg-label-primary fw-semibold">
                            <?= e(initials((string) $user['username'])) ?>
                          </span>
                        </div>
                        <div class="flex-grow-1">
                          <h6 class="mb-0"><?= e($user['full_name'] ?: $user['username']) ?></h6>
                          <small class="text-body-secondary">@<?= e($user['username']) ?></small>
                        </div>
                        <span class="badge rounded-pill bg-label-success">Active</span>
                      </div>
                      <table class="table table-borderless table-sm mb-3">
                        <tbody>
                          <tr>
                            <td class="text-body-secondary fw-medium ps-0">Email</td>
                            <td class="text-end text-truncate"><?= e($user['email']) ?></td>
                          </tr>
                          <tr>
                            <td class="text-body-secondary fw-medium ps-0">Member since</td>
                            <td class="text-end text-nowrap"><?= e(format_datetime($user['created_at'])) ?></td>
                          </tr>
                          <tr>
                            <td class="text-body-secondary fw-medium ps-0">Last sign-in</td>
                            <td class="text-end text-nowrap"><?= e(format_datetime($user['last_login_at'])) ?></td>
                          </tr>
                          <tr>
                            <td class="text-body-secondary fw-medium ps-0">Member ID</td>
                            <td class="text-end">#<?= (int) $user['id'] ?></td>
                          </tr>
                        </tbody>
                      </table>
                      <div class="d-grid gap-2">
                        <a href="#participation" class="btn btn-outline-primary btn-sm">Where participation begins</a>
                        <a href="#questions" class="btn btn-outline-secondary btn-sm">A few things to know</a>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Concept stats: the three numbers index.html previews -->
              <div class="row gy-4 mt-1">
                <div class="col-md-4">
                  <div class="card h-100 kpi-card">
                    <div class="card-body">
                      <div class="d-flex align-items-start justify-content-between mb-3">
                        <div class="avatar">
                          <span class="avatar-initial rounded bg-label-primary">
                            <i class="icon-base bx bx-network-chart"></i>
                          </span>
                        </div>
                        <span class="badge rounded-pill bg-label-success">Live</span>
                      </div>
                      <h3 class="mb-0"><?= $stats['total_users'] ?></h3>
                      <small class="text-body-secondary d-block">Network &mdash; community view</small>
                      <small class="text-body-secondary d-block mt-1">
                        <?= $stats['signups_today'] ?> joined today
                        <?php if ($trendPct !== null): ?>
                          · <span class="text-<?= $trendDir === 'down' ? 'danger' : 'success' ?>">
                            <?= $trendDir === 'down' ? '&darr;' : '&uarr;' ?> <?= number_format(abs($trendPct), 0) ?>%
                          </span> vs last week
                        <?php endif; ?>
                      </small>
                    </div>
                  </div>
                </div>

                <div class="col-md-4">
                  <div class="card h-100 kpi-card">
                    <div class="card-body">
                      <div class="d-flex align-items-start justify-content-between mb-3">
                        <div class="avatar">
                          <span class="avatar-initial rounded bg-label-info">
                            <i class="icon-base bx bx-grid-alt"></i>
                          </span>
                        </div>
                        <span class="badge rounded-pill bg-label-secondary">Concept</span>
                      </div>
                      <h3 class="mb-0">&mdash;</h3>
                      <small class="text-body-secondary d-block">Team progress &mdash; matrix activity</small>
                      <small class="text-body-secondary d-block mt-1">Not tracked in this preview.</small>
                    </div>
                  </div>
                </div>

                <div class="col-md-4">
                  <div class="card h-100 kpi-card">
                    <div class="card-body">
                      <div class="d-flex align-items-start justify-content-between mb-3">
                        <div class="avatar">
                          <span class="avatar-initial rounded bg-label-warning">
                            <i class="icon-base bx bx-medal"></i>
                          </span>
                        </div>
                        <span class="badge rounded-pill bg-label-secondary">Concept</span>
                      </div>
                      <h3 class="mb-0">&mdash;</h3>
                      <small class="text-body-secondary d-block">Leadership &mdash; rank pathway</small>
                      <small class="text-body-secondary d-block mt-1">Member to Director, five stages.</small>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Three dimensions -->
              <div class="row gy-4 mt-1">
<?php foreach ($pillars as $pillar): ?>
                <div class="col-md-4">
                  <div class="card h-100">
                    <div class="card-body">
                      <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="avatar">
                          <span class="avatar-initial rounded bg-label-<?= e($pillar['tone']) ?>">
                            <i class="icon-base bx <?= e($pillar['icon']) ?>"></i>
                          </span>
                        </div>
                        <small class="text-uppercase text-body-secondary fw-semibold"><?= e($pillar['no']) ?></small>
                      </div>
                      <h5 class="mb-2"><?= e($pillar['title']) ?></h5>
                      <p class="text-body-secondary small mb-3"><?= e($pillar['text']) ?></p>
                      <span class="badge rounded-pill bg-label-<?= e($pillar['tone']) ?>"><?= e($pillar['tag']) ?></span>
                    </div>
                  </div>
                </div>
<?php endforeach; ?>
              </div>

              <!-- Community growth + leadership path -->
              <div class="row gy-4 mt-1">
                <div class="col-xl-8" id="growth">
                  <div class="card h-100">
                    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                      <div>
                        <h5 class="card-title mb-0">Community growth</h5>
                        <small class="text-body-secondary" data-chart-range-label>Last 30 days</small>
                      </div>
                      <div class="d-flex align-items-center gap-2">
                        <span class="badge rounded-pill bg-label-primary">Live data</span>
                        <div class="dropdown">
                          <button
                            class="btn btn-sm btn-icon btn-text-secondary rounded-pill dropdown-toggle hide-arrow"
                            type="button"
                            data-bs-toggle="dropdown"
                            aria-expanded="false"
                          >
                            <i class="icon-base bx bx-dots-vertical-rounded icon-md"></i>
                          </button>
                          <ul class="dropdown-menu dropdown-menu-end">
                            <li><h6 class="dropdown-header">Range</h6></li>
                            <li><a class="dropdown-item" href="#" data-chart-range="7">Last 7 days</a></li>
                            <li><a class="dropdown-item" href="#" data-chart-range="14">Last 14 days</a></li>
                            <li><a class="dropdown-item active" href="#" data-chart-range="30">Last 30 days</a></li>
                          </ul>
                        </div>
                      </div>
                    </div>
                    <div class="card-body pt-2">
                      <div id="registrationsChart"></div>
                    </div>
                    <div class="card-footer d-flex justify-content-between flex-wrap gap-2">
                      <small class="text-body-secondary">
                        This week <strong class="text-heading"><?= $stats['signups_week'] ?></strong> ·
                        previous week <strong class="text-heading"><?= $stats['signups_prev_week'] ?></strong> ·
                        active this week <strong class="text-heading"><?= $stats['active_week'] ?></strong>
                      </small>
                      <small class="text-body-secondary">Participation, not compensation.</small>
                    </div>
                  </div>
                </div>

                <div class="col-xl-4" id="leadership">
                  <div class="card h-100">
                    <div class="card-header d-flex align-items-center justify-content-between">
                      <div>
                        <h5 class="card-title mb-0">Leadership path</h5>
                        <small class="text-body-secondary">Royalty &mdash; five stages</small>
                      </div>
                      <span class="badge rounded-pill bg-label-secondary">Concept</span>
                    </div>
                    <div class="card-body pt-2">
                      <ul class="list-group list-group-flush">
<?php foreach ($leadership as $index => $rank): ?>
                        <li class="list-group-item d-flex align-items-center gap-3 px-2<?= $index === 0 ? ' rank-row-active' : '' ?>">
                          <span class="badge rounded-pill bg-label-<?= $index === 0 ? 'primary' : 'secondary' ?>"><?= sprintf('%02d', $index + 1) ?></span>
                          <span class="text-heading flex-grow-1"><?= e($rank) ?></span>
                          <?php if ($index === 0): ?>
                            <small class="text-body-secondary">Entry stage</small>
                          <?php elseif ($index === count($leadership) - 1): ?>
                            <i class="icon-base bx bx-trophy text-warning"></i>
                          <?php else: ?>
                            <i class="icon-base bx bx-chevron-up text-body-secondary"></i>
                          <?php endif; ?>
                        </li>
<?php endforeach; ?>
                      </ul>
                      <p class="text-body-secondary small mb-0 mt-3">
                        Based on defined team-development milestones. Final terms are published separately.
                      </p>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Five dimensions + matrix -->
              <div class="row gy-4 mt-1">
                <div class="col-xl-7" id="dimensions">
                  <div class="card h-100">
                    <div class="card-header">
                      <h5 class="card-title mb-0">Five dimensions of progress</h5>
                      <small class="text-body-secondary">One sponsor network, several distinct mechanisms</small>
                    </div>
                    <ul class="list-group list-group-flush">
<?php foreach ($dimensions as $dimension): ?>
                      <li class="list-group-item px-4 py-3">
                        <div class="d-flex align-items-start justify-content-between gap-3">
                          <div>
                            <small class="text-uppercase text-body-secondary fw-semibold d-block mb-1"><?= e($dimension['index']) ?></small>
                            <h6 class="mb-1"><?= e($dimension['title']) ?></h6>
                            <p class="text-body-secondary small mb-0"><?= e($dimension['text']) ?></p>
                          </div>
                          <i class="icon-base bx bx-layer text-primary fs-4"></i>
                        </div>
                      </li>
<?php endforeach; ?>
                    </ul>
                    <div class="card-body pt-3">
                      <p class="text-body-secondary small mb-0">
                        Their qualification triggers and calculations are not interchangeable. The sandbox is the
                        best place to explore how the prototype behaves.
                      </p>
                    </div>
                  </div>
                </div>

                <div class="col-xl-5" id="matrix">
                  <div class="card h-100">
                    <div class="card-header d-flex align-items-center justify-content-between">
                      <div>
                        <h5 class="card-title mb-0">Matrix progression</h5>
                        <small class="text-body-secondary">Shared structure, community-wide</small>
                      </div>
                      <span class="badge rounded-pill bg-label-secondary">Illustrative</span>
                    </div>
                    <div class="card-body">
                      <div class="matrix-grid mb-4" aria-hidden="true">
<?php for ($cell = 0; $cell < 9; $cell++): ?>
                        <span class="matrix-cell<?= $cell === 4 ? ' is-center' : '' ?>"></span>
<?php endfor; ?>
                      </div>
                      <p class="text-body-secondary small mb-3">
                        General Harvest is a shared matrix where qualifying members contribute to the progression of
                        the larger community structure.
                      </p>
                      <div class="alert alert-primary d-flex align-items-start gap-2 mb-0 py-3" role="alert">
                        <i class="icon-base bx bx-info-circle fs-5"></i>
                        <div class="small">Illustrative interface. The production platform is still in development.</div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Where participation begins + journey -->
              <div class="row gy-4 mt-1">
                <div class="col-lg-6" id="participation">
                  <div class="card h-100">
                    <div class="card-header">
                      <h5 class="card-title mb-0">Where participation begins</h5>
                      <small class="text-body-secondary">A livelihood ecosystem needs a real foundation</small>
                    </div>
                    <div class="card-body">
                      <ul class="timeline mb-0">
<?php foreach ($participation as $step => $item): ?>
                        <li class="timeline-item timeline-item-transparent">
                          <span class="timeline-point timeline-point-primary"></span>
                          <div class="timeline-event">
                            <div class="timeline-header d-flex align-items-center justify-content-between mb-1">
                              <h6 class="mb-0"><?= e($item['title']) ?></h6>
                              <span class="badge rounded-pill bg-label-primary"><?= sprintf('%02d', $step + 1) ?></span>
                            </div>
                            <p class="mb-2 small text-body-secondary"><?= e($item['text']) ?></p>
                          </div>
                        </li>
<?php endforeach; ?>
                      </ul>
                    </div>
                  </div>
                </div>

                <div class="col-lg-6" id="journey">
                  <div class="card h-100">
                    <div class="card-header">
                      <h5 class="card-title mb-0">The journey</h5>
                      <small class="text-body-secondary">Start with participation, grow through connection</small>
                    </div>
                    <div class="card-body">
                      <div class="row g-3">
<?php foreach ($journey as $step): ?>
                        <div class="col-sm-6">
                          <div class="border rounded-3 p-3 h-100">
                            <small class="text-uppercase text-body-secondary fw-semibold d-block mb-1"><?= e($step['no']) ?></small>
                            <h6 class="mb-1"><?= e($step['title']) ?></h6>
                            <p class="text-body-secondary small mb-0"><?= e($step['text']) ?></p>
                          </div>
                        </div>
<?php endforeach; ?>
                      </div>
                      <p class="text-body-secondary small mb-0 mt-3">
                        A simple view of the journey the platform is being designed to support.
                      </p>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Community members + recent participation -->
              <div class="row gy-4 mt-1">
                <div class="col-xl-8" id="community">
                  <div class="card h-100">
                    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                      <div>
                        <h5 class="card-title mb-0">Community members</h5>
                        <small class="text-body-secondary">Real accounts in this preview, newest first</small>
                      </div>
                      <a href="<?= e(url('register')) ?>" class="btn btn-sm btn-label-primary">
                        <i class="icon-base bx bx-plus me-1"></i> Add member
                      </a>
                    </div>
                    <div class="card-datatable table-responsive">
                      <table class="table table-hover border-top" id="membersTable">
                        <thead>
                          <tr>
                            <th></th>
                            <th>Member</th>
                            <th>Joined</th>
                            <th>Last activity</th>
                          </tr>
                        </thead>
                        <tbody>
<?php foreach ($members as $member): ?>
<?php
    $joinedTs = strtotime((string) $member['created_at']);
    $loginTs  = strtotime((string) $member['last_login_at']);
?>
                          <tr>
                            <td>
                              <div class="avatar avatar-sm">
                                <span class="avatar-initial rounded-circle bg-label-primary">
                                  <?= e(initials((string) $member['username'])) ?>
                                </span>
                              </div>
                            </td>
                            <td>
                              <div class="d-flex flex-column">
                                <span class="text-heading fw-medium"><?= e($member['full_name'] ?: $member['username']) ?></span>
                                <small class="text-body-secondary"><?= e($member['email']) ?></small>
                              </div>
                            </td>
                            <td data-order="<?= (int) $joinedTs ?>">
                              <span class="text-nowrap"><?= $joinedTs !== false ? e(date('M j, Y', $joinedTs)) : '—' ?></span>
                            </td>
                            <td data-order="<?= (int) $loginTs ?>">
                              <span class="text-nowrap"><?= e(time_ago((string) $member['last_login_at'])) ?></span>
                            </td>
                          </tr>
<?php endforeach; ?>
<?php if ($members === []): ?>
                          <tr>
                            <td colspan="4" class="text-center text-body-secondary py-5">
                              No members have registered yet.
                            </td>
                          </tr>
<?php endif; ?>
                        </tbody>
                      </table>
                    </div>
                  </div>
                </div>

                <div class="col-xl-4">
                  <div class="card h-100">
                    <div class="card-header">
                      <h5 class="card-title mb-0">Recent participation</h5>
                      <small class="text-body-secondary">Latest community activity</small>
                    </div>
                    <div class="card-body">
<?php if ($events === []): ?>
                      <p class="text-body-secondary small mb-0">No activity recorded yet.</p>
<?php else: ?>
                      <ul class="timeline mb-0">
<?php foreach ($events as $event): ?>
                        <li class="timeline-item timeline-item-transparent">
                          <span class="timeline-point timeline-point-<?= e($event['tone']) ?>"></span>
                          <div class="timeline-event">
                            <div class="timeline-header d-flex justify-content-between align-items-center mb-1">
                              <h6 class="mb-0"><?= e($event['title']) ?></h6>
                              <small class="text-body-secondary text-nowrap ms-2"><?= e($event['when']) ?></small>
                            </div>
                            <p class="mb-2 small text-body-secondary"><?= e($event['text']) ?></p>
                          </div>
                        </li>
<?php endforeach; ?>
                      </ul>
<?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Questions + concept notes -->
              <div class="row gy-4 mt-1">
                <div class="col-xl-8" id="questions">
                  <div class="card h-100">
                    <div class="card-header">
                      <h5 class="card-title mb-0">A few things to know</h5>
                      <small class="text-body-secondary">Clarity comes first</small>
                    </div>
                    <div class="card-body">
                      <div class="accordion" id="conceptFaq">
<?php foreach ($faq as $index => $item): ?>
                        <div class="accordion-item">
                          <h2 class="accordion-header" id="faqHeading<?= $index ?>">
                            <button
                              class="accordion-button<?= $index === 0 ? '' : ' collapsed' ?>"
                              type="button"
                              data-bs-toggle="collapse"
                              data-bs-target="#faqCollapse<?= $index ?>"
                              aria-expanded="<?= $index === 0 ? 'true' : 'false' ?>"
                              aria-controls="faqCollapse<?= $index ?>"
                            >
                              <?= e($item['q']) ?>
                            </button>
                          </h2>
                          <div
                            id="faqCollapse<?= $index ?>"
                            class="accordion-collapse collapse<?= $index === 0 ? ' show' : '' ?>"
                            aria-labelledby="faqHeading<?= $index ?>"
                            data-bs-parent="#conceptFaq"
                          >
                            <div class="accordion-body text-body-secondary small"><?= e($item['a']) ?></div>
                          </div>
                        </div>
<?php endforeach; ?>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="col-xl-4">
                  <div class="card h-100">
                    <div class="card-header">
                      <h5 class="card-title mb-0">Concept notes</h5>
                      <small class="text-body-secondary">What this preview is — and is not</small>
                    </div>
                    <div class="card-body">
                      <div class="alert alert-warning d-flex align-items-start gap-2 py-3" role="alert">
                        <i class="icon-base bx bx-package fs-5"></i>
                        <div class="small">
                          <strong>Product information is presented separately.</strong>
                          This preview is not a product catalogue. Names, descriptions, ingredients, prices and any
                          health-related claims belong to the official catalogue.
                        </div>
                      </div>
                      <div class="alert alert-info d-flex align-items-start gap-2 py-3" role="alert">
                        <i class="icon-base bx bx-network-chart fs-5"></i>
                        <div class="small">
                          <strong>One sponsor network, several mechanisms.</strong>
                          General Harvest, Team Harvest, Royalty, Unilevel and Indirect Referral are modelled
                          separately and are not interchangeable.
                        </div>
                      </div>
                      <div class="alert alert-secondary d-flex align-items-start gap-2 mb-0 py-3" role="alert">
                        <i class="icon-base bx bx-info-circle fs-5"></i>
                        <div class="small">
                          Demonstration only. The sandbox uses illustrative figures and rules that may change
                          before the production platform is developed. No income, investment return or financial
                          benefit is offered or guaranteed.
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <script>
                window.ECOWEALTH_DASH = <?= json_encode([
                    'signups' => [
                        'labels' => $chartDates,
                        'values' => array_values($signupByDay),
                    ],
                ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>;
              </script>
            </div>
            <!-- / Content -->
<?php require __DIR__ . '/../app/layout/app_footer.php'; ?>
