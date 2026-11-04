<?php
declare(strict_types=1);

/* ==============================================================
 *  CartController — the persistent cart (plan §7 Phase 4;
 *  reference: MemberController::cart/addToCart/updateCartItem/
 *  removeCartItem + views/member/cart.php, cart_offcanvas.php)
 *
 *  Routes (Appendix A):
 *      GET  /cart          the full cart page
 *      GET  /cart/count    JSON: badge count + the refreshed drawer
 *      POST /cart/add
 *      POST /cart/update
 *      POST /cart/remove
 *
 *  Every mutation answers two ways (§3.6):
 *      XHR  → JSON { ok, count, items, subtotal, item, drawer_html }
 *      form → flash + redirect back (no-JS fallback, P4-T12)
 *
 *  The router owns the guard (member) and the central CSRF check.
 * ============================================================== */

final class CartController
{
    private CartService $carts;

    public function __construct()
    {
        $this->carts = new CartService();
    }

    /** @param array<string, string> $params */
    public function index(array $params = []): void
    {
        $userId  = $this->userId();
        $isAdmin = is_admin();
        $state   = $this->carts->state($userId, true);

        View::page($isAdmin ? 'admin' : 'member', 'shop/cart', [
            'page_title'             => 'Cart',
            'current_nav'            => $isAdmin ? 'admin.storefront' : 'cart',
            'nav_search_placeholder' => 'Search the shop…',
            'nav_search_url'         => url('shop'),
            'page_css'               => ['assets/css/shop.css'],
            'state'                  => $state,
            'shop_open'              => setting_bool('shop_enabled', true),
        ]);
    }

    /** JSON: { ok, count, items, subtotal, drawer_html } (GET /cart/count). */
    public function count(array $params = []): void
    {
        $userId = $this->userId();
        $state  = $this->carts->state($userId);
        $totals = $state['totals'];

        json_response([
            'ok'                 => true,
            'count'              => $totals['quantity'],
            'items'              => $totals['items'],
            'subtotal'           => round($totals['subtotal'], 2),
            'subtotal_formatted' => money($totals['subtotal']),
            'drawer_html'        => View::partial('cart_offcanvas_body', ['state' => $state]),
        ]);
    }

    /** POST /cart/add */
    public function add(array $params = []): void
    {
        $userId    = $this->userId();
        $productId = (int) Request::post('product_id', 0);
        $quantity  = (int) Request::post('quantity', 1);

        $this->run(function () use ($userId, $productId, $quantity): array {
            if (!setting_bool('shop_enabled', true)) {
                throw new CartException('The shop is currently closed. Please try again later.', 'shop_closed');
            }

            return $this->carts->add($userId, $productId, $quantity);
        }, 'Added to cart.');
    }

    /** POST /cart/update */
    public function update(array $params = []): void
    {
        $userId  = $this->userId();
        $itemId  = (int) Request::post('item_id', 0);
        $quantity = (int) Request::post('quantity', 0);

        $this->run(function () use ($userId, $itemId, $quantity): array {
            if (!setting_bool('shop_enabled', true) && $quantity > 0) {
                throw new CartException('The shop is currently closed. Please try again later.', 'shop_closed');
            }

            return $this->carts->updateQuantity($userId, $itemId, $quantity);
        }, null);
    }

    /** POST /cart/remove */
    public function remove(array $params = []): void
    {
        $userId = $this->userId();
        $itemId = (int) Request::post('item_id', 0);

        $this->run(fn (): array => $this->carts->remove($userId, $itemId), 'Item removed from your cart.');
    }

    /* ----------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------- */

    private function userId(): int
    {
        $user = auth_user();

        return $user === null ? 0 : (int) $user['id'];
    }

    /**
     * Run one cart mutation and answer JSON or redirect + flash.
     *
     * @param callable(): array<string, mixed> $operation
     */
    private function run(callable $operation, ?string $successMessage): void
    {
        $userId  = $this->userId();
        $isAjax  = Request::wantsJson();

        try {
            $result = $operation();
        } catch (CartException $e) {
            $this->fail($e->getMessage(), $e->reason, $isAjax);
        } catch (InvalidArgumentException $e) {
            $this->fail($e->getMessage(), 'cart', $isAjax);
        } catch (Throwable $e) {
            error_log('[ecowealth] cart mutation failed: ' . $e->getMessage());
            $this->fail('Could not update your cart. Please try again.', 'server_error', $isAjax);
        }

        $message = $successMessage
            ?? ($result['removed'] ? 'Item removed from your cart.' : 'Cart updated.');

        if ($isAjax) {
            $state  = $this->carts->state($userId);
            $totals = $state['totals'];

            json_response([
                'ok'                 => true,
                'message'            => $message,
                'count'              => $totals['quantity'],
                'items'              => $totals['items'],
                'subtotal'           => round($totals['subtotal'], 2),
                'subtotal_formatted' => money($totals['subtotal']),
                'item'               => $result['item'] ?? null,
                'removed'            => (bool) ($result['removed'] ?? false),
                'drawer_html'        => View::partial('cart_offcanvas_body', ['state' => $state]),
            ]);
        }

        flash_set('success', $message);
        $this->redirectBack('cart');
    }

    private function fail(string $message, string $code, bool $isAjax): never
    {
        if ($isAjax) {
            json_response([
                'ok'      => false,
                'code'    => $code,
                'message' => $message,
            ], 400);
        }

        flash_set('danger', $message);
        $this->redirectBack('cart');
    }

    /**
     * Redirect to the referring page (the catalogue or the product the
     * member added from) or the cart when there is no usable referer.
     */
    private function redirectBack(string $fallback): never
    {
        redirect_back($fallback);
    }
}
