<?php
declare(strict_types=1);

/**
 * Order line items + totals (plan §5.3, Phase 6).
 *
 * The rows are the immutable snapshots written at placement — they
 * never follow a later catalogue edit. Shared by the member order page
 * and the admin order desk.
 *
 * @var array<string, mixed> $order
 * @var list<array<string, mixed>> $lines
 */
?>
              <div class="table-responsive">
                <table class="table align-middle order-items-table mb-0">
                  <thead>
                    <tr>
                      <th scope="col">Item</th>
                      <th scope="col" class="text-center">Qty</th>
                      <th scope="col" class="text-end">Unit price</th>
                      <th scope="col" class="text-end">Line total</th>
                    </tr>
                  </thead>
                  <tbody>
<?php foreach ($lines as $line): ?>
                    <tr>
                      <td>
                        <div class="fw-medium"><?= e((string) $line['product_name']) ?></div>
<?php if (trim((string) ($line['product_sku'] ?? '')) !== ''): ?>
                        <div class="small text-body-secondary"><?= e((string) $line['product_sku']) ?></div>
<?php endif; ?>
                      </td>
                      <td class="text-center"><?= (int) $line['quantity'] ?></td>
                      <td class="text-end text-nowrap"><?= e(money((float) $line['unit_price'])) ?></td>
                      <td class="text-end text-nowrap fw-semibold"><?= e(money((float) $line['line_total'])) ?></td>
                    </tr>
<?php endforeach; ?>
                  </tbody>
                </table>
              </div>

              <div class="order-totals border-top">
                <div class="d-flex justify-content-between py-1">
                  <span class="text-body-secondary">Subtotal</span>
                  <span><?= e(money((float) $order['subtotal'])) ?></span>
                </div>
                <div class="d-flex justify-content-between py-1">
                  <span class="text-body-secondary">Shipping</span>
                  <span class="text-body-secondary"><?= (float) $order['shipping_fee'] > 0 ? e(money((float) $order['shipping_fee'])) : 'Free' ?></span>
                </div>
                <div class="d-flex justify-content-between pt-2 mt-1 border-top fw-semibold">
                  <span>Total</span>
                  <span><?= e(money((float) $order['total'])) ?></span>
                </div>
              </div>
