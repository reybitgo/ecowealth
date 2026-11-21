<?php
declare(strict_types=1);

/**
 * My orders — order rows.
 *
 * Each row is keyed by order id so the client patches only the orders that
 * changed; the number and View links carry data-live-focus so focus survives
 * a replacement.
 *
 * @var list<array<string, mixed>> $orders
 * @var array{status:string, method:string, from:string, to:string, q:string} $filters
 */

$hasFilters = (string) $filters['status'] !== ''
    || (string) $filters['method'] !== ''
    || (string) $filters['from'] !== ''
    || (string) $filters['to'] !== ''
    || (string) $filters['q'] !== '';
?>
                    <tbody data-live-region="member-orders-rows">
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
                      <tr data-live-key="<?= (int) $order['id'] ?>">
                        <td>
                          <a
                            href="<?= e(url('orders/' . (int) $order['id'])) ?>"
                            class="fw-semibold text-body"
                            data-live-focus="order"
                          >
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
                          <a
                            href="<?= e(url('orders/' . (int) $order['id'])) ?>"
                            class="btn btn-sm btn-label-primary"
                            data-live-focus="view"
                          >
                            View
                          </a>
                        </td>
                      </tr>
<?php endforeach; ?>
                    </tbody>
