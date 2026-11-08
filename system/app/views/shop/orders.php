<?php
declare(strict_types=1);

/**
 * My orders — the member's order history (plan §5.3, Phase 6;
 * reference views/member/shop_orders.php).
 *
 * One live surface per member (`member.orders:{id}`): the rows and the
 * pagination are regions. The filter form stays outside them, so a
 * background refresh never steals focus or discards an unsubmitted filter.
 *
 * @var list<array<string, mixed>> $orders
 * @var Paginator $pager
 * @var array{status:string, method:string, from:string, to:string, q:string} $filters
 * @var array{surface:string, enabled:bool, revision:int, etag:string, interval:int} $live
 */

$hasFilters = $filters['status'] !== ''
    || $filters['method'] !== ''
    || $filters['from'] !== ''
    || $filters['to'] !== ''
    || $filters['q'] !== '';

$live = $live ?? [
    'surface'  => 'member.orders:0',
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
              <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-6">
                <div>
                  <h4 class="mb-1">My orders</h4>
                  <p class="text-body-secondary mb-0">
                    Every order you have placed, newest first. Open one to see its items, delivery details and history.
                    <span class="small text-body-secondary" data-live-status aria-live="polite"></span>
                  </p>
                </div>
                <a href="<?= e(url('shop')) ?>" class="btn btn-label-primary">
                  <i class="icon-base bx bx-store me-1"></i> Continue shopping
                </a>
              </div>

              <div class="card">
                <div class="card-header">
                  <form method="GET" action="<?= e(url('orders')) ?>" class="d-flex flex-wrap align-items-end gap-3 order-filters">
                    <?= per_page_hidden() ?>
                    <div class="order-filter-field">
                      <label class="form-label" for="order-filter-status">Status</label>
                      <select name="status" id="order-filter-status" class="form-select">
                        <option value="">All statuses</option>
<?php foreach (Order::STATUSES as $status): ?>
                        <option value="<?= e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>>
                          <?= e(ucfirst(str_replace('_', ' ', $status))) ?>
                        </option>
<?php endforeach; ?>
                      </select>
                    </div>
                    <div class="order-filter-field">
                      <label class="form-label" for="order-filter-method">Payment</label>
                      <select name="method" id="order-filter-method" class="form-select">
                        <option value="">All payments</option>
<?php foreach (Order::PAYMENT_METHODS as $method): ?>
                        <option value="<?= e($method) ?>" <?= $filters['method'] === $method ? 'selected' : '' ?>>
                          <?= e(payment_method_label($method)) ?>
                        </option>
<?php endforeach; ?>
                      </select>
                    </div>
                    <div class="order-filter-field order-filter-date">
                      <label class="form-label" for="order-filter-from">From</label>
                      <input type="date" name="from" id="order-filter-from" class="form-control" value="<?= e($filters['from']) ?>" />
                    </div>
                    <div class="order-filter-field order-filter-date">
                      <label class="form-label" for="order-filter-to">To</label>
                      <input type="date" name="to" id="order-filter-to" class="form-control" value="<?= e($filters['to']) ?>" />
                    </div>
                    <div class="order-filter-field order-filter-wide">
                      <label class="form-label" for="order-filter-q">Order number</label>
                      <input type="search" name="q" id="order-filter-q" class="form-control" value="<?= e($filters['q']) ?>" placeholder="EW…" />
                    </div>
                    <div class="d-flex gap-2">
                      <button type="submit" class="btn btn-primary">Filter</button>
<?php if ($hasFilters): ?>
                      <a href="<?= e(url('orders')) ?>" class="btn btn-label-secondary">Clear</a>
<?php endif; ?>
                    </div>
                  </form>
                </div>

                <div class="table-responsive">
                  <table class="table table-hover align-middle mb-0 order-list-table">
                    <thead>
                      <tr>
                        <th scope="col">Order</th>
                        <th scope="col">Placed</th>
                        <th scope="col" class="text-center">Items</th>
                        <th scope="col" class="text-end">Total</th>
                        <th scope="col">Payment</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end">Actions</th>
                      </tr>
                    </thead>
<?= View::capture('shop/orders/_rows', ['orders' => $orders, 'filters' => $filters]) ?>
                  </table>
                </div>

<?= View::capture('shop/orders/_pagination', ['pager' => $pager]) ?>
              </div>
            </div>
