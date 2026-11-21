<?php
declare(strict_types=1);

/**
 * Admin dashboard — orders by status (plan §7 Phase 15).
 *
 * @var array<string, mixed> $commerce
 */

$commerce = $commerce ?? [];
$orders   = (array) ($commerce['orders_by_status'] ?? []);
$open     = (int) ($commerce['open_orders'] ?? 0);
$total    = 0;

foreach ($orders as $row) {
    $total += (int) $row['count'];
}
?>
              <div class="card h-100" data-live-region="dashboard-order-status">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <div>
                    <h5 class="mb-1">Orders by status</h5>
                    <p class="text-body-secondary small mb-0"><?= $open ?> need action · <?= $total ?> total</p>
                  </div>
                  <a href="<?= e(url('admin/orders')) ?>" class="btn btn-sm btn-label-primary">Order desk</a>
                </div>
                <div class="table-responsive">
                  <table class="table table-sm align-middle mb-0">
                    <thead>
                      <tr>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end">Orders</th>
                        <th scope="col" class="text-end">Value</th>
                      </tr>
                    </thead>
                    <tbody>
<?php foreach ($orders as $row): ?>
                      <tr>
                        <td><?= status_badge((string) $row['status']) ?></td>
                        <td class="text-end"><?= (int) $row['count'] ?></td>
                        <td class="text-end"><?= e(money($row['amount'])) ?></td>
                      </tr>
<?php endforeach; ?>
<?php if ($orders === []): ?>
                      <tr><td colspan="3" class="text-body-secondary">No orders yet.</td></tr>
<?php endif; ?>
                    </tbody>
                  </table>
                </div>
              </div>
