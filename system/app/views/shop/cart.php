<?php
declare(strict_types=1);

/**
 * Full cart page (plan §5.2, Phase 4; reference views/member/cart.php).
 *
 * The desktop table is the primary list; every mutation also has a plain
 * POST form so the page works with JavaScript disabled (P4-T12). The
 * drawer is patched by app-cart.js from the server's own partial, so the
 * badge and the list can never disagree.
 *
 * One live surface per member (`member.cart:{id}`). Everything that
 * changes — the shop-closed notice, the stock warnings, the line table
 * and the order summary — lives in the single `cart-content` region, so
 * an emptied or refilled cart swaps whole. That region owns the quantity
 * forms, so it carries `data-live-hold="dirty"`: a background refresh
 * keeps a typed quantity and shows the stale banner instead.
 *
 * @var array{
 *   cart: array<string, mixed>|null,
 *   items: list<array<string, mixed>>,
 *   totals: array{items: int, quantity: int, subtotal: float},
 *   stock_errors: list<string>
 * } $state
 * @var bool $shop_open
 * @var array{surface:string, enabled:bool, revision:int, etag:string, interval:int} $live
 */

$state = $state ?? [
    'cart'         => null,
    'items'        => [],
    'totals'       => ['items' => 0, 'quantity' => 0, 'subtotal' => 0.0],
    'stock_errors' => [],
];
$shop_open = (bool) ($shop_open ?? true);

$live = $live ?? [
    'surface'  => Live::memberCartSurface((int) (auth_user()['id'] ?? 0)),
    'enabled'  => false,
    'revision' => 0,
    'etag'     => '',
    'interval' => 30000,
];
?>
            <div
              class="container-xxl flex-grow-1 container-p-y cart-page"
              data-cart-page
              data-live-surface="<?= e($live['surface']) ?>"
              data-live-enabled="<?= $live['enabled'] ? '1' : '0' ?>"
              data-live-revision="<?= (int) $live['revision'] ?>"
              data-live-etag="<?= e((string) $live['etag']) ?>"
              data-live-interval="<?= (int) $live['interval'] ?>"
            >
              <div class="alert alert-warning d-flex align-items-start gap-2 mb-4 d-none" data-live-stale role="status" aria-live="polite">
                <i class="icon-base bx bx-error-circle fs-5"></i>
                <div>
                  <strong>Your cart changed in another tab.</strong>
                  Your unsaved quantity was kept, so this page may be out of date.
                  <a href="<?= e(url('cart')) ?>" class="alert-link" data-live-reload>
                    Reload the page
                  </a>
                  to see the latest.
                </div>
              </div>

              <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-6">
                <div>
                  <h4 class="mb-1">Your cart</h4>
                  <p class="text-body-secondary mb-0">
                    Prices and stock stay in sync with the catalogue while this page is open.
                    <span class="small text-body-secondary" data-live-status aria-live="polite"></span>
                  </p>
                </div>
                <a href="<?= e(url('shop')) ?>" class="btn btn-label-primary">
                  <i class="icon-base bx bx-arrow-back me-1"></i> Continue shopping
                </a>
              </div>

<?= View::capture('shop/cart/_content', ['state' => $state, 'shop_open' => $shop_open]) ?>
            </div>
