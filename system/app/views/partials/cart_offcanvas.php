<?php
declare(strict_types=1);

/**
 * Global cart drawer (plan §5.2, Phase 4; reference
 * views/partials/cart_offcanvas.php).
 *
 * Rendered from app/layout/app_footer.php for every signed-in session so
 * the drawer (and its badge) is present on all member pages. The body is
 * a partial of its own because the cart endpoints re-render exactly that
 * fragment after a mutation.
 *
 * A page view never creates a cart row: the state comes from
 * CartService::state(), which only reads (Cart::active()).
 *
 * @var array{items: list<array<string, mixed>>, totals: array{items: int, quantity: int, subtotal: float}} $state
 */

$state  = $state ?? ['cart' => null, 'items' => [], 'totals' => ['items' => 0, 'quantity' => 0, 'subtotal' => 0.0]];
$totals = $state['totals'] ?? ['quantity' => 0];
?>
            <div
              class="offcanvas offcanvas-end cart-drawer"
              tabindex="-1"
              id="cartOffcanvas"
              aria-labelledby="cartOffcanvasLabel"
            >
              <div class="offcanvas-header border-bottom">
                <h5 class="offcanvas-title d-flex align-items-center gap-2" id="cartOffcanvasLabel">
                  <i class="icon-base bx bx-cart icon-md"></i>
                  Cart
                  <span class="badge rounded-pill bg-label-primary" data-cart-count><?= (int) ($totals['quantity'] ?? 0) ?></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
              </div>
              <div class="offcanvas-body p-0 d-flex flex-column" id="cartOffcanvasBody">
<?= View::partial('cart_offcanvas_body', ['state' => $state]) ?>
              </div>
            </div>
