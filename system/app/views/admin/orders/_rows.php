<?php
declare(strict_types=1);

/**
 * Admin order desk table body.
 *
 * The initial page and the live feed render this same partial. Each row
 * carries data-live-key so the client patches only the rows that changed;
 * the links carry data-live-focus so focus survives a row replacement.
 *
 * @var list<array<string, mixed>> $orders
 * @var array{status:string, method:string, from:string, to:string, q:string} $filters
 */

$hasFilters = $filters['status'] !== ''
    || $filters['method'] !== ''
    || $filters['from'] !== ''
    || $filters['to'] !== ''
    || $filters['q'] !== '';
?>
                      <tbody data-live-region="order-rows">
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
                        <tr data-live-key="<?= (int) $order['id'] ?>">
                          <td>
                            <a
                              href="<?= e(url('admin/orders/' . (int) $order['id'])) ?>"
                              class="fw-semibold text-body"
                              data-live-focus="order"
                            >
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
                            <a
                              href="<?= e(url('admin/orders/' . (int) $order['id'])) ?>"
                              class="btn btn-sm btn-label-primary"
                              data-live-focus="open"
                            >
                              Open
                            </a>
                          </td>
                        </tr>
<?php endforeach; ?>
                      </tbody>
