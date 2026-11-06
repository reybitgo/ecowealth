<?php
declare(strict_types=1);

/**
 * Admin order desk — every order, filters and headline numbers
 * (plan §7 Phases 6–7; reference views/admin/shop_orders.php).
 *
 * Phase 7 adds the review counter and the payment-review column; the
 * fulfilment actions (pack / ship / deliver) land in Phase 8.
 *
 * @var list<array<string, mixed>> $orders
 * @var Paginator $pager
 * @var array{status:string, method:string, from:string, to:string, q:string} $filters
 * @var array{total:int, awaiting_payment:int, in_review:int, in_progress:int, completed:int, cancelled:int, revenue:float, today_count:int, today_total:float} $stats
 */

$hasFilters = $filters['status'] !== ''
    || $filters['method'] !== ''
    || $filters['from'] !== ''
    || $filters['to'] !== ''
    || $filters['q'] !== '';

$cards = [
    ['label' => 'Orders', 'value' => (string) $stats['total'], 'hint' => $stats['today_count'] . ' today', 'icon' => 'bx-receipt', 'tone' => 'primary'],
    ['label' => 'Awaiting payment', 'value' => (string) $stats['awaiting_payment'], 'hint' => 'Manual payments pending', 'icon' => 'bx-time-five', 'tone' => 'warning'],
    ['label' => 'In progress', 'value' => (string) $stats['in_progress'], 'hint' => $stats['completed'] . ' completed', 'icon' => 'bx-package', 'tone' => 'info'],
    ['label' => 'Paid revenue', 'value' => money((float) $stats['revenue']), 'hint' => money((float) $stats['today_total']) . ' today', 'icon' => 'bx-line-chart', 'tone' => 'success'],
];
?>
            <div class="container-xxl flex-grow-1 container-p-y orders-page">
              <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-6">
                <div>
                  <h4 class="mb-1">Orders</h4>
                  <p class="text-body-secondary mb-0">
                    Every order, newest first. Open an order to verify or reject a manual payment proof.
                  </p>
                </div>
                <a href="<?= e(url('admin/orders?status=payment_review')) ?>" class="badge bg-label-info text-decoration-none">
                  <i class="icon-base bx bx-search-alt me-1"></i><?= (int) $stats['in_review'] ?> awaiting review
                </a>
              </div>

              <div class="row g-6 mb-6">
<?php foreach ($cards as $card): ?>
                <div class="col-6 col-xl-3">
                  <div class="card h-100 order-stat-card">
                    <div class="card-body">
                      <div class="d-flex align-items-center justify-content-between mb-3">
                        <span class="avatar avatar-sm">
                          <span class="avatar-initial rounded bg-label-<?= e($card['tone']) ?>">
                            <i class="icon-base <?= e(icon_class($card['icon'])) ?>"></i>
                          </span>
                        </span>
                        <span class="text-body-secondary small"><?= e($card['label']) ?></span>
                      </div>
                      <h4 class="mb-2 order-stat-value"><?= e($card['value']) ?></h4>
                      <p class="text-body-secondary small mb-0"><?= e($card['hint']) ?></p>
                    </div>
                  </div>
                </div>
<?php endforeach; ?>
              </div>

              <div class="card">
                <div class="card-header">
                  <form method="GET" action="<?= e(url('admin/orders')) ?>" class="d-flex flex-wrap align-items-end gap-3 order-filters">
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
                    <tbody>
<?php if ($orders === []): ?>
                      <tr>
                        <td colspan="8" class="py-0">
<?php
    echo View::partial('empty_state', [
        'icon'         => 'bx bx-receipt',
        'title'        => $hasFilters ? 'No orders match these filters' : 'No orders yet',
        'text'         => $hasFilters
            ? 'Try a different status, payment method or date range.'
            : 'Orders appear here as soon as members check out.',
        'action_href'  => $hasFilters ? 'admin/orders' : 'admin/products',
        'action_label' => $hasFilters ? 'Clear filters' : 'Manage products',
    ]);
?>
                        </td>
                      </tr>
<?php endif; ?>
<?php foreach ($orders as $order): ?>
                      <tr>
                        <td>
                          <a href="<?= e(url('admin/orders/' . (int) $order['id'])) ?>" class="fw-semibold text-body">
                            <?= e((string) $order['order_no']) ?>
                          </a>
                        </td>
                        <td>
                          <div class="fw-medium"><?= e((string) $order['username']) ?></div>
<?php if (trim((string) ($order['user_name'] ?? '')) !== ''): ?>
                          <div class="small text-body-secondary"><?= e((string) $order['user_name']) ?></div>
<?php endif; ?>
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
                          <a href="<?= e(url('admin/orders/' . (int) $order['id'])) ?>" class="btn btn-sm btn-label-primary">
                            Open
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
