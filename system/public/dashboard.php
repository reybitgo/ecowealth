<?php
declare(strict_types=1);

/* ==============================================================
 *  Dashboard (protected page)
 * ============================================================== */
require __DIR__ . '/../app/bootstrap.php';

require_login();

$user = auth_user();

/* --- Live stats straight from MySQL ------------------------- */
$stats = ['total_users' => 0, 'total_admins' => 0, 'latest_member' => null, 'signups_today' => 0];
try {
    $stats['total_users'] = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $stmt = db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'");
    $stats['total_admins'] = (int) $stmt->fetchColumn();
    $stmt = db()->query('SELECT username FROM users ORDER BY created_at DESC, id DESC LIMIT 1');
    $stats['latest_member'] = $stmt->fetchColumn() ?: null;
    $stmt = db()->query('SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()');
    $stats['signups_today'] = (int) $stmt->fetchColumn();
} catch (Throwable $e) {
    error_log('[sneat-auth] dashboard stats: ' . $e->getMessage());
}

$loginAt   = $_SESSION['logged_in_at'] ?? null;
$authVia   = $_SESSION['auth_via'] ?? 'password';
$clientIp  = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '—');
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '—';

$page_title = 'Dashboard';

require __DIR__ . '/../app/layout/app_header.php';
?>
            <!-- Content -->
            <div class="container-xxl flex-grow-0 container-p-y">
              <?= flash_render() ?>

              <!-- Heading -->
              <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-5">
                <div>
                  <h4 class="fw-bold mb-1">Dashboard</h4>
                  <p class="text-body-secondary mb-0">
                    You are signed in as <strong><?= e($user['username']) ?></strong> — session protected by PHP &amp; MySQL.
                  </p>
                </div>
                <span class="badge rounded-pill bg-label-primary fs-6">
                  <i class="icon-base bx bx-shield-quarter me-1"></i> Authenticated
                </span>
              </div>

              <!-- Stats -->
              <div class="row gy-4 mb-4">
                <div class="col-sm-6 col-xl-3">
                  <div class="card h-100">
                    <div class="card-body">
                      <div class="d-flex align-items-center mb-2">
                        <div class="avatar me-2">
                          <span class="avatar-initial rounded bg-label-primary"><i class="icon-base bx bx-group"></i></span>
                        </div>
                      </div>
                      <h3 class="mb-0"><?= $stats['total_users'] ?></h3>
                      <small class="text-body-secondary d-block">Registered users</small>
                    </div>
                  </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                  <div class="card h-100">
                    <div class="card-body">
                      <div class="d-flex align-items-center mb-2">
                        <div class="avatar me-2">
                          <span class="avatar-initial rounded bg-label-warning"><i class="icon-base bx bx-user-check"></i></span>
                        </div>
                      </div>
                      <h3 class="mb-0"><?= $stats['signups_today'] ?></h3>
                      <small class="text-body-secondary d-block">Sign-ups today</small>
                    </div>
                  </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                  <div class="card h-100">
                    <div class="card-body">
                      <div class="d-flex align-items-center mb-2">
                        <div class="avatar me-2">
                          <span class="avatar-initial rounded bg-label-info"><i class="icon-base bx bx-user-pin"></i></span>
                        </div>
                      </div>
                      <h5 class="mb-0 pt-1"><?= e($stats['latest_member'] ?? '—') ?></h5>
                      <small class="text-body-secondary d-block">Newest member</small>
                    </div>
                  </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                  <div class="card h-100">
                    <div class="card-body">
                      <div class="d-flex align-items-center mb-2">
                        <div class="avatar me-2">
                          <span class="avatar-initial rounded bg-label-success"><i class="icon-base bx bx-time-five"></i></span>
                        </div>
                      </div>
                      <h5 class="mb-0 pt-1"><?= e(time_ago($user['last_login_at'])) ?></h5>
                      <small class="text-body-secondary d-block">Your last login</small>
                    </div>
                  </div>
                </div>
              </div>

              <div class="row gy-4">
                <!-- Account card -->
                <div class="col-lg-6">
                  <div class="card h-100" id="account">
                    <div class="card-header d-flex align-items-center justify-content-between">
                      <h5 class="card-title mb-0">My Account</h5>
                      <span class="badge rounded-pill <?= $user['role'] === 'admin' ? 'bg-label-warning' : 'bg-label-secondary' ?>">
                        <?= e(ucfirst($user['role'])) ?>
                      </span>
                    </div>
                    <div class="card-body">
                      <div class="d-flex align-items-center gap-3 pb-4 mb-4 border-bottom">
                        <div class="avatar avatar-xl">
                          <span class="avatar-initial rounded-circle bg-label-primary fs-3 fw-semibold">
                            <?= e(initials($user['username'])) ?>
                          </span>
                        </div>
                        <div>
                          <h5 class="mb-0"><?= e($user['full_name'] ?: $user['username']) ?></h5>
                          <small class="text-body-secondary">@<?= e($user['username']) ?></small>
                        </div>
                      </div>
                      <div class="table-responsive">
                        <table class="table table-borderless mb-0">
                          <tbody>
                            <tr>
                              <td class="text-body-secondary fw-medium ps-0 w-50">Email</td>
                              <td class="text-truncate"><?= e($user['email']) ?></td>
                            </tr>
                            <tr>
                              <td class="text-body-secondary fw-medium ps-0">User ID</td>
                              <td>#<?= (int) $user['id'] ?></td>
                            </tr>
                            <tr>
                              <td class="text-body-secondary fw-medium ps-0">Member since</td>
                              <td><?= e(format_datetime($user['created_at'])) ?></td>
                            </tr>
                            <tr>
                              <td class="text-body-secondary fw-medium ps-0">Last login</td>
                              <td><?= e(format_datetime($user['last_login_at'])) ?></td>
                            </tr>
                            <tr>
                              <td class="text-body-secondary fw-medium ps-0">Status</td>
                              <td>
                                <span class="badge rounded-pill bg-label-success">Active</span>
                              </td>
                            </tr>
                            <tr>
                              <td class="text-body-secondary fw-medium ps-0">Password</td>
                              <td><code>bcrypt-hashed (<?= e(APP_NAME) ?> never stores plain text)</code></td>
                            </tr>
                          </tbody>
                        </table>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Session card -->
                <div class="col-lg-6">
                  <div class="card h-100">
                    <div class="card-header d-flex align-items-center justify-content-between">
                      <h5 class="card-title mb-0">Session Details</h5>
                      <span class="badge rounded-pill bg-label-primary">
                        <i class="icon-base bx bx-key me-1"></i><?= e($authVia) ?>
                      </span>
                    </div>
                    <div class="card-body">
                      <div class="table-responsive">
                        <table class="table table-borderless mb-0">
                          <tbody>
                            <tr>
                              <td class="text-body-secondary fw-medium ps-0 w-50">Signed in</td>
                              <td><?= $loginAt ? e(date('M j, Y g:i A', (int) $loginAt)) : '—' ?></td>
                            </tr>
                            <tr>
                              <td class="text-body-secondary fw-medium ps-0">Client IP</td>
                              <td><?= e(explode(',', (string) $clientIp)[0]) ?></td>
                            </tr>
                            <tr>
                              <td class="text-body-secondary fw-medium ps-0">Session ID</td>
                              <td class="text-truncate"><code><?= e(session_id()) ?></code></td>
                            </tr>
                            <tr>
                              <td class="text-body-secondary fw-medium ps-0">Remember me</td>
                              <td>
                                <?= !empty($_COOKIE[REMEMBER_COOKIE])
                                    ? '<span class="badge rounded-pill bg-label-success">Enabled (30-day token)</span>'
                                    : '<span class="badge rounded-pill bg-label-secondary">Off</span>' ?>
                              </td>
                            </tr>
                            <tr>
                              <td class="text-body-secondary fw-medium ps-0 align-top">Browser</td>
                              <td class="small"><?= e($userAgent) ?></td>
                            </tr>
                          </tbody>
                        </table>
                      </div>
                      <div class="alert alert-primary d-flex align-items-start gap-2 mb-0 mt-4" role="alert">
                        <i class="icon-base bx bx-info-circle fs-4"></i>
                        <div class="small">
                          This page is protected: <code>require_login()</code> verifies the PHP session and
                          refreshes your account row from the <code>users</code> table on every request.
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            <!-- / Content -->
<?php require __DIR__ . '/../app/layout/app_footer.php'; ?>
