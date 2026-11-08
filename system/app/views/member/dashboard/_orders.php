<?php
declare(strict_types=1);

/**
 * Member dashboard — the recent-orders card.
 *
 * A live region: every order write for this buyer bumps the per-member
 * dashboard surface (through Live::touchOrder()), so the five newest
 * orders and the header totals re-render in place. The card has no form,
 * so it is replaced freely.
 *
 * @var list<array<string, mixed>> $orders
 * @var array{total:int, open:int, completed:int, spent:float} $order_stats
 */

$orders     = $orders ?? [];
$orderStats = $order_stats ?? ['total' => 0, 'open' => 0, 'completed' => 0, 'spent' => 0.0];
?>
                  <div class="card h-100" data-live-region="dashboard-orders">
                    <div class="card-header d-flex align-items-center justify-content-between">
                      <div>
                        <h5 class="card-title mb-0">Recent orders</h5>
                        <small class="text-body-secondary">
                          <?= (int) $orderStats['total'] ?> order<?= $orderStats['total'] === 1 ? '' : 's' ?>
                          · <?= (int) $orderStats['open'] ?> open
                          · <?= e(money($orderStats['spent'])) ?> paid
                        </small>
                      </div>
                      <a href="<?= e(url('orders')) ?>" class="btn btn-sm btn-label-primary">All orders</a>
                    </div>
                    <div class="table-responsive">
                      <table class="table table-hover align-middle mb-0">
                        <thead>
                          <tr>
                            <th>Order</th>
                            <th>Placed</th>
                            <th class="text-end">Total</th>
                            <th>Status</th>
                          </tr>
                        </thead>
                        <tbody>
<?php if ($orders === []): ?>
                          <tr>
                            <td colspan="4" class="py-0">
<?php
    echo View::partial('empty_state', [
        'icon'         => 'bx bx-receipt',
        'title'        => 'No orders yet',
        'text'         => 'Your product orders will appear here once you check out.',
        'action_href'  => 'shop',
        'action_label' => 'Browse the shop',
    ]);
?>
                            </td>
                          </tr>
<?php endif; ?>
<?php foreach ($orders as $order): ?>
                          <tr>
                            <td>
                              <a href="<?= e(url('orders/' . (int) $order['id'])) ?>" class="text-body fw-medium">
                                <?= e((string) $order['order_no']) ?>
                              </a>
                              <div class="member-meta">
                                <?= (int) ($order['item_count'] ?? 0) ?> item<?= (int) ($order['item_count'] ?? 0) === 1 ? '' : 's' ?>
                              </div>
                            </td>
                            <td class="text-nowrap">
                              <span class="member-meta"><?= e(format_datetime((string) $order['created_at'])) ?></span>
                            </td>
                            <td class="text-end text-nowrap"><?= e(money((float) $order['total'])) ?></td>
                            <td><?= status_badge((string) $order['status']) ?></td>
                          </tr>
<?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  </div>
