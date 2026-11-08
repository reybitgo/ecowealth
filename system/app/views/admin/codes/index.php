<?php
declare(strict_types=1);

/**
 * Admin registration codes (plan §5.10, §7 Phase 9;
 * reference views/admin/codes.php).
 *
 * One card to issue a batch, one to filter/export, then the list with
 * the counters above it.
 *
 * One live surface: the five counters, the rows and the pagination are
 * regions. The issue and filter forms stay outside them, so a background
 * refresh never steals focus or discards unsubmitted input.
 *
 * @var array{total:int, unused:int, used:int, expired:int, revenue:float} $stats
 * @var array{q:string, status:string, package:string} $filters
 * @var Paginator                  $pager
 * @var list<array<string, mixed>> $codes
 * @var list<array<string, mixed>> $packages
 * @var array{surface:string, enabled:bool, revision:int, etag:string, interval:int} $live
 */

$query      = (string) $filters['q'];
$status     = (string) $filters['status'];
$packageId  = (string) $filters['package'];
$hasFilters = $query !== '' || $status !== '' || $packageId !== '';

$exportQuery = http_build_query(array_filter([
    'q'       => $query,
    'status'  => $status,
    'package' => $packageId,
], static fn (string $value): bool => $value !== ''));

$live = $live ?? [
    'surface'  => 'admin.codes',
    'enabled'  => false,
    'revision' => 0,
    'etag'     => '',
    'interval' => 30000,
];
?>
            <div
              class="container-xxl flex-grow-1 container-p-y code-books"
              data-live-surface="<?= e($live['surface']) ?>"
              data-live-enabled="<?= $live['enabled'] ? '1' : '0' ?>"
              data-live-revision="<?= (int) $live['revision'] ?>"
              data-live-etag="<?= e((string) $live['etag']) ?>"
              data-live-interval="<?= (int) $live['interval'] ?>"
            >
              <?= flash_render() ?>

              <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
                <div>
                  <h4 class="mb-1">Registration codes</h4>
                  <p class="text-body-secondary mb-0">
                    Package-bound, one-time codes sold offline. Redemption is single-use and audited.
                  </p>
                </div>
                <a
                  href="<?= e(url('admin/codes/export' . ($exportQuery === '' ? '' : '?' . $exportQuery))) ?>"
                  class="btn btn-label-primary"
                >
                  <i class="icon-base bx bx-download me-1"></i> Export CSV
                </a>
              </div>

<?= View::capture('admin/codes/_stats', ['stats' => $stats]) ?>

              <div class="row g-4 mb-4">
                <!-- Issue -->
                <div class="col-xl-7">
                  <div class="card h-100" id="codes-issue">
                    <div class="card-header">
                      <h5 class="card-title mb-0">Issue a batch</h5>
                      <small class="text-body-secondary">One to 500 codes, all bound to the same package and expiry.</small>
                    </div>
                    <form method="POST" action="<?= e(url('admin/codes/generate')) ?>" class="card-body">
                      <?= csrf_field() ?>
                      <div class="row g-3">
                        <div class="col-md-6">
                          <label class="form-label" for="code-package">Package</label>
                          <select
                            class="form-select<?= has_error('package_id') ? ' is-invalid' : '' ?>"
                            id="code-package"
                            name="package_id"
                            required
                            data-code-package
                          >
                            <option value="">Choose a package…</option>
<?php foreach ($packages as $package): ?>
                            <option
                              value="<?= (int) $package['id'] ?>"
                              data-price="<?= e(money_amount((float) $package['price'])) ?>"
<?= (string) old('package_id') === (string) $package['id'] ? ' selected' : '' ?>
                            ><?= e((string) $package['name']) ?> · <?= e(money((float) $package['price'])) ?><?= $package['status'] !== 'active' ? ' (inactive)' : '' ?></option>
<?php endforeach; ?>
                          </select>
<?php if (has_error('package_id')): ?>
                          <div class="invalid-feedback"><?= e(error_for('package_id')) ?></div>
<?php endif; ?>
                        </div>

                        <div class="col-md-3">
                          <label class="form-label" for="code-quantity">Quantity</label>
                          <input
                            type="number"
                            class="form-control<?= has_error('quantity') ? ' is-invalid' : '' ?>"
                            id="code-quantity"
                            name="quantity"
                            min="1"
                            max="500"
                            step="1"
                            value="<?= old('quantity', '10') ?>"
                            required
                          />
<?php if (has_error('quantity')): ?>
                          <div class="invalid-feedback"><?= e(error_for('quantity')) ?></div>
<?php endif; ?>
                        </div>

                        <div class="col-md-3">
                          <label class="form-label" for="code-price">Price each</label>
                          <input
                            type="text"
                            inputmode="decimal"
                            class="form-control<?= has_error('price') ? ' is-invalid' : '' ?>"
                            id="code-price"
                            name="price"
                            value="<?= old('price') ?>"
                            placeholder="0.00"
                            required
                            data-code-price
                          />
<?php if (has_error('price')): ?>
                          <div class="invalid-feedback"><?= e(error_for('price')) ?></div>
<?php endif; ?>
                        </div>

                        <div class="col-md-6">
                          <label class="form-label" for="code-expiry">
                            Expiry date <span class="text-body-secondary">(optional)</span>
                          </label>
                          <input
                            type="date"
                            class="form-control<?= has_error('expires_at') ? ' is-invalid' : '' ?>"
                            id="code-expiry"
                            name="expires_at"
                            value="<?= old('expires_at') ?>"
                            min="<?= e(date('Y-m-d', strtotime('+1 day'))) ?>"
                          />
<?php if (has_error('expires_at')): ?>
                          <div class="invalid-feedback"><?= e(error_for('expires_at')) ?></div>
<?php endif; ?>
                        </div>

                        <div class="col-md-6">
                          <label class="form-label" for="code-type">Type</label>
                          <select class="form-select" id="code-type" name="code_type">
                            <option value="registration">Registration code</option>
                            <option value="upgrade" disabled>Upgrade code — later phase</option>
                          </select>
                          <div class="form-text">Upgrade codes are reserved for the package-upgrade module.</div>
                        </div>
                      </div>

                      <div class="d-flex align-items-center justify-content-between gap-3 mt-4 flex-wrap">
                        <div class="form-text mb-0">
                          The codes are generated inside one transaction and audited.
                        </div>
                        <button type="submit" class="btn btn-primary">
                          <i class="icon-base bx bx-plus-circle me-1"></i> Generate codes
                        </button>
                      </div>
                    </form>
                  </div>
                </div>

                <!-- Filter -->
                <div class="col-xl-5">
                  <div class="card h-100">
                    <div class="card-header">
                      <h5 class="card-title mb-0">Filter</h5>
                      <small class="text-body-secondary">The list and the export both follow these filters.</small>
                    </div>
                    <form method="GET" action="<?= e(url('admin/codes')) ?>" class="card-body">
                      <?= per_page_hidden() ?>
                      <div class="mb-3">
                        <label class="form-label" for="code-q">Search</label>
                        <input
                          type="search"
                          class="form-control"
                          id="code-q"
                          name="q"
                          value="<?= e($query) ?>"
                          placeholder="Code, member or issuer…"
                        />
                      </div>
                      <div class="mb-3">
                        <label class="form-label" for="code-status">Status</label>
                        <select class="form-select" id="code-status" name="status">
                          <option value="">All statuses</option>
<?php foreach (['unused', 'used', 'expired'] as $option): ?>
                          <option value="<?= e($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= e(ucfirst($option)) ?></option>
<?php endforeach; ?>
                        </select>
                      </div>
                      <div class="mb-3">
                        <label class="form-label" for="code-package-filter">Package</label>
                        <select class="form-select" id="code-package-filter" name="package">
                          <option value="">All packages</option>
<?php foreach ($packages as $package): ?>
                          <option value="<?= (int) $package['id'] ?>" <?= $packageId === (string) $package['id'] ? 'selected' : '' ?>>
                            <?= e((string) $package['name']) ?>
                          </option>
<?php endforeach; ?>
                        </select>
                      </div>
                      <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-grow-1">Apply filters</button>
<?php if ($hasFilters): ?>
                        <a href="<?= e(url('admin/codes')) ?>" class="btn btn-label-secondary">Clear</a>
<?php endif; ?>
                      </div>
                    </form>
                  </div>
                </div>
              </div>

              <!-- List -->
              <div class="card">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <h5 class="card-title mb-0">Code list</h5>
                  <span class="small text-body-secondary" data-live-status aria-live="polite"></span>
                </div>
                <div class="table-responsive">
                  <table class="table table-hover align-middle mb-0">
                    <thead>
                      <tr>
                        <th>Code</th>
                        <th>Package</th>
                        <th class="text-end">Price</th>
                        <th>Status</th>
                        <th>Used by</th>
                        <th>Issued</th>
                        <th>Expires</th>
                      </tr>
                    </thead>
<?= View::capture('admin/codes/_rows', ['codes' => $codes, 'filters' => $filters]) ?>
                  </table>
                </div>
<?= View::capture('admin/codes/_pagination', ['pager' => $pager]) ?>
              </div>
            </div>
