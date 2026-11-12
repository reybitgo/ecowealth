<?php
declare(strict_types=1);

/**
 * Checkout — the order summary card body.
 *
 * A live region with no inputs: a cart change, a product price change or
 * a wallet movement re-renders the items, totals and wallet note. The
 * card footer (the Place order button) stays static so the terms script
 * keeps its binding.
 *
 * @var array{
 *   items: list<array<string, mixed>>,
 *   totals: array{items:int, quantity:int, subtotal:float}
 * } $state
 * @var array{balance:float, withdrawable:float} $wallet
 * @var array<string, mixed>|null $bonus_preview repeat-purchase preview (17E)
 */

$state  = $state ?? ['items' => [], 'totals' => ['items' => 0, 'quantity' => 0, 'subtotal' => 0.0]];
$wallet = $wallet ?? ['balance' => 0.0, 'withdrawable' => 0.0];

$items    = $state['items'] ?? [];
$totals   = $state['totals'] ?? ['items' => 0, 'quantity' => 0, 'subtotal' => 0.0];
$subtotal = (float) ($totals['subtotal'] ?? 0);
?>
                      <div class="card-body" data-live-region="checkout-summary">
                        <ul class="list-unstyled checkout-summary-items mb-0">
<?php foreach ($items as $item): ?>
                          <li class="d-flex justify-content-between gap-3 py-2 border-bottom">
                            <div class="min-w-0">
                              <div class="fw-medium"><?= e((string) $item['product_name']) ?></div>
                              <div class="small text-body-secondary"><?= (int) $item['quantity'] ?> × <?= e(money((float) $item['current_price'])) ?></div>
                            </div>
                            <span class="text-nowrap fw-semibold"><?= e(money((float) $item['line_total'])) ?></span>
                          </li>
<?php endforeach; ?>
                        </ul>

                        <div class="d-flex justify-content-between mt-4">
                          <span class="text-body-secondary">Items</span>
                          <span><?= (int) $totals['quantity'] ?></span>
                        </div>
                        <div class="d-flex justify-content-between mt-2">
                          <span class="text-body-secondary">Subtotal</span>
                          <span><?= e(money($subtotal)) ?></span>
                        </div>
                        <div class="d-flex justify-content-between mt-2">
                          <span class="text-body-secondary">Shipping</span>
                          <span class="text-body-secondary">Free</span>
                        </div>
                        <div class="d-flex justify-content-between pt-3 mt-3 border-top fw-semibold fs-5">
                          <span>Total</span>
                          <span><?= e(money($subtotal)) ?></span>
                        </div>

                        <div class="checkout-wallet-note mt-4">
                          <div class="d-flex align-items-center justify-content-between gap-2">
                            <span class="small text-body-secondary"><i class="icon-base bx bx-wallet me-1"></i> E-wallet</span>
                            <span class="fw-semibold"><?= e(money((float) $wallet['balance'])) ?></span>
                          </div>
                          <div class="small text-body-secondary mt-1">
                            <?= e(money((float) $wallet['withdrawable'])) ?> of it is withdrawable.
                            <a href="<?= e(url('wallet')) ?>">View wallet</a>
                          </div>
                        </div>

<?= View::capture('shop/checkout/_bonus', ['bonus_preview' => $bonus_preview ?? null]) ?>
                      </div>
