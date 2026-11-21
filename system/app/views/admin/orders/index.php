<?php
declare(strict_types=1);

/**
 * Admin order desk — every order, filters and headline numbers
 * (plan §7 Phases 6–7; reference views/admin/shop_orders.php).
 *
 * The page is one live surface. The heading counter, stat cards, rows and
 * pagination are separate regions rendered by the same partials the JSON
 * feed uses; the filter form stays outside them so a background refresh
 * never steals focus or discards an unsubmitted search.
 *
 * @var list<array<string, mixed>> $orders
 * @var Paginator $pager
 * @var array{status:string, method:string, from:string, to:string, q:string} $filters
 * @var array{total:int, awaiting_payment:int, in_review:int, in_progress:int, completed:int, cancelled:int, revenue:float, today_count:int, today_total:float} $stats
 * @var array{surface:string, enabled:bool, revision:int, etag:string, interval:int} $live
 */

$hasFilters = $filters['status'] !== ''
    || $filters['method'] !== ''
    || $filters['from'] !== ''
    || $filters['to'] !== ''
    || $filters['q'] !== '';

$live = $live ?? [
    'surface'  => 'admin.orders',
    'enabled'  => false,
    'revision' => 0,
    'etag'     => '',
    'interval' => 30000,
];
?>
            <div
              class="container-xxl flex-grow-1 container-p-y orders-page"
              data-live-surface="<?= e($live['surface']) ?>"
              data-live-enabled="<?= $live['enabled'] ? '1' : '0' ?>"
              data-live-revision="<?= (int) $live['revision'] ?>"
              data-live-etag="<?= e((string) $live['etag']) ?>"
              data-live-interval="<?= (int) $live['interval'] ?>"
            >
<?= View::capture('admin/orders/_header', ['stats' => $stats]) ?>

<?= View::capture('admin/orders/_stats', ['stats' => $stats]) ?>

              <div class="card">
                <div class="card-header">
                  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
                    <div class="d-flex align-items-center gap-3">
                      <h5 class="mb-0">All orders</h5>
                      <span class="small text-body-secondary" data-live-status aria-live="polite"></span>
                    </div>
                  </div>
                  <form method="GET" action="<?= e(url('admin/orders')) ?>" class="d-flex flex-wrap align-items-end gap-3 order-filters">
                    <?= per_page_hidden() ?>
                    <div class="order-filter-field order-filter-wide">
                      <label class="form-label" for="admin-order-q">Search</label>
                      <input type="search" name="q" id="admin-order-q" class="form-control" value="<?= e($filters['q']) ?>" placeholder="Order # or member…" />
                    </div>
                    <div class="order-filter-field">
                      <label class="form-label" for="admin-order-status">Status</label>
                      <select name="status" id="admin-order-status" class="form-select">
                        <option value="">All statuses</option>
<?php foreach (Order::STATUSES as $status): ?>
                        <option value="<?= e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>>
                          <?= e(ucfirst(str_replace('_', ' ', $status))) ?>
                        </option>
<?php endforeach; ?>
                      </select>
                    </div>
                    <div class="order-filter-field">
                      <label class="form-label" for="admin-order-method">Payment</label>
                      <select name="method" id="admin-order-method" class="form-select">
                        <option value="">All payments</option>
<?php foreach (Order::PAYMENT_METHODS as $method): ?>
                        <option value="<?= e($method) ?>" <?= $filters['method'] === $method ? 'selected' : '' ?>>
                          <?= e(payment_method_label($method)) ?>
                        </option>
<?php endforeach; ?>
                      </select>
                    </div>
                    <div class="order-filter-field order-filter-date">
                      <label class="form-label" for="admin-order-from">From</label>
                      <input type="date" name="from" id="admin-order-from" class="form-control" value="<?= e($filters['from']) ?>" />
                    </div>
                    <div class="order-filter-field order-filter-date">
                      <label class="form-label" for="admin-order-to">To</label>
                      <input type="date" name="to" id="admin-order-to" class="form-control" value="<?= e($filters['to']) ?>" />
                    </div>
                    <div class="d-flex gap-2">
                      <button type="submit" class="btn btn-primary">Filter</button>
<?php if ($hasFilters): ?>
                      <a href="<?= e(url('admin/orders')) ?>" class="btn btn-label-secondary">Clear</a>
<?php endif; ?>
                    </div>
                  </form>
                </div>

                <div class="table-responsive">
                  <table class="table table-hover align-middle mb-0 order-list-table">
                    <thead>
                      <tr>
                        <th scope="col">Order</th>
                        <th scope="col">Member</th>
                        <th scope="col">Placed</th>
                        <th scope="col" class="text-center">Items</th>
                        <th scope="col" class="text-end">Total</th>
                        <th scope="col">Payment</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end">Actions</th>
                      </tr>
                    </thead>
<?= View::capture('admin/orders/_rows', ['orders' => $orders, 'filters' => $filters]) ?>
                  </table>
                </div>

<?= View::capture('admin/orders/_pagination', ['pager' => $pager, 'pager_label' => 'Orders']) ?>
              </div>
            </div>
