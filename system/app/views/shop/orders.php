<?php
declare(strict_types=1);

/**
 * My orders — the member's order history (plan §5.3, Phase 6;
 * reference views/member/shop_orders.php).
 *
 * @var list<array<string, mixed>> $orders
 * @var Paginator $pager
 * @var array{status:string, method:string, from:string, to:string, q:string} $filters
 */

$hasFilters = $filters['status'] !== ''
    || $filters['method'] !== ''
    || $filters['from'] !== ''
    || $filters['to'] !== ''
    || $filters['q'] !== '';
?>
            <div class="container-xxl flex-grow-1 container-p-y orders-page">
              <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-6">
                <div>
                  <h4 class="mb-1">My orders</h4>
                  <p class="text-body-secondary mb-0">
                    Every order you have placed, newest first. Open one to see its items, delivery details and history.
                  </p>
                </div>
                <a href="<?= e(url('shop')) ?>" class="btn btn-label-primary">
                  <i class="icon-base bx bx-store me-1"></i> Continue shopping
                </a>
              </div>

              <div class="card">
                <div class="card-header">
                  <form method="GET" action="<?= e(url('orders')) ?>" class="d-flex flex-wrap align-items-end gap-3 order-filters">
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
                    <tbody>
<?php if ($orders === []): ?>
                      <tr>
                        <td colspan="7" class="py-0">
<?php
    echo View::partial('empty_state', [
        'icon'         => 'bx bx-receipt',
        'title'        => $hasFilters ? 'No orders match these filters' : 'No orders yet',
        'text'         => $hasFilters
            ? 'Try a different status, payment method or date range.'
            : 'When you check out, your orders and their progress appear here.',
        'action_href'  => $hasFilters ? 'orders' : 'shop',
        'action_label' => $hasFilters ? 'Clear filters' : 'Browse the shop',
    ]);
?>
                        </td>
                      </tr>
<?php endif; ?>
<?php foreach ($orders as $order): ?>
                      <tr>
                        <td>
                          <a href="<?= e(url('orders/' . (int) $order['id'])) ?>" class="fw-semibold text-body">
                            <?= e((string) $order['order_no']) ?>
                          </a>
                        </td>
                        <td class="text-nowrap small text-body-secondary"><?= e(format_datetime((string) $order['created_at'])) ?></td>
                        <td class="text-center"><?= (int) $order['item_count'] ?></td>
                        <td class="text-end text-nowrap fw-semibold"><?= e(money((float) $order['total'])) ?></td>
                        <td class="text-nowrap">
                          <i class="icon-base <?= e(payment_method_icon((string) $order['payment_method'])) ?> me-1 text-body-secondary"></i>
                          <?= e(payment_method_label((string) $order['payment_method'])) ?>
                        </td>
                        <td><?= status_badge((string) $order['status']) ?></td>
                        <td class="text-end">
                          <a href="<?= e(url('orders/' . (int) $order['id'])) ?>" class="btn btn-sm btn-label-primary">
                            View
                          </a>
                        </td>
                      </tr>
<?php endforeach; ?>
                    </tbody>
                  </table>
                </div>

                <div class="card-body pt-2">
<?= View::partial('pagination', ['pager' => $pager, 'pager_label' => 'Orders']) ?>
                </div>
              </div>
            </div>
