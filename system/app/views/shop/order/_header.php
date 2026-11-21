<?php
declare(strict_types=1);

/**
 * Member order — identity header and total.
 *
 * @var array<string, mixed> $order
 * @var string $status
 * @var bool   $has_invoice
 * @var string $invoice_url
 */
?>
              <div
                class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-6"
                data-live-region="member-order-header"
              >
                <div>
                  <div class="d-flex align-items-center gap-2 small text-body-secondary mb-1">
                    <a href="<?= e(url('orders')) ?>" class="text-body-secondary">My orders</a>
                    <i class="icon-base bx bx-chevron-right"></i>
                    <span><?= e((string) $order['order_no']) ?></span>
                  </div>
                  <h4 class="mb-1">Order <?= e((string) $order['order_no']) ?></h4>
                  <p class="text-body-secondary mb-0">
                    Placed <?= e(format_datetime((string) $order['created_at'])) ?>
                    · <?= e(payment_method_label((string) $order['payment_method'])) ?>
                  </p>
                </div>
                <div class="text-md-end">
                  <?= status_badge($status) ?>
                  <div class="fs-4 fw-semibold mt-1"><?= e(money((float) $order['total'])) ?></div>
<?php if ($has_invoice): ?>
                  <a href="<?= e($invoice_url) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-label-primary mt-3">
                    <i class="icon-base bx bx-receipt me-1"></i> View / print invoice
                  </a>
<?php endif; ?>
                </div>
              </div>
