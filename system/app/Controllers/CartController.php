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
 *      XHR  → JSON { ok, count, items, subtotal, item, drawer_html, live }
 *      form → flash + redirect back (no-JS fallback, P4-T12)
 *
 *  The page itself is a live surface (`member.cart:{id}`): the same URL
 *  answers `X-Live: 1` with the `cart-content` fragment, and every write
 *  bumps the surface through CartService so the member's other tabs (and
 *  an open checkout tab) revalidate.
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

        /* The same URL doubles as the JSON feed for the cart body. The
         * body renders the quantity forms, so the CSRF token is primed
         * before the feed releases the session lock. */
        if (Live::isFeedRequest()) {
            $this->feed($userId);
            return;
        }

        View::page($isAdmin ? 'admin' : 'member', 'shop/cart', $this->cartContext($userId, true) + [
            'page_title'             => 'Cart',
            'current_nav'            => $isAdmin ? 'admin.cart' : 'cart',
            'nav_search_placeholder' => 'Search the shop…',
            'nav_search_url'         => url('shop'),
            'page_css'               => ['assets/css/shop.css'],
            'page_js'                => ['assets/js/admin-live.js'],
            'live'                   => Live::meta(
                Live::memberCartSurface($userId),
                $this->cartScope($userId)
            ),
        ]);
    }

    /**
     * Everything the cart page renders, built once for the page and its
     * feed. Public so the view tests can assert the real screen data.
     *
     * The page refreshes the price snapshots when it is opened (the cart
     * totals already read the live catalogue); the feed skips that write
     * because a poll is not a page view.
     *
     * @return array{user_id:int, state: array<string, mixed>, shop_open: bool}
     */
    public function cartContext(int $userId, bool $refreshPrices = false): array
    {
        return [
            'user_id'   => $userId,
            'state'     => $this->carts->state($userId, $refreshPrices),
            'shop_open' => setting_bool('shop_enabled', true),
        ];
    }

    /**
     * ETag scope for the cart page.
     *
     * The shop-open setting and the shared catalogue revision are part of
     * the scope: closing the shop, editing a product or reserving stock
     * must reach an open cart tab on its next poll without touching every
     * member's cart revision.
     */
    private function cartScope(int $userId): string
    {
        return Live::scope($_GET, $userId)
            . '|shop:' . (setting_bool('shop_enabled', true) ? '1' : '0')
            . '|catalog:' . Live::revisionOrZero('shop.catalog');
    }

    /**
     * JSON feed for the open cart page.
     *
     * The 304 check runs before the cart read, so an unchanged cart costs
     * one revision lookup and no line/stock queries.
     */
    private function feed(int $userId): void
    {
        csrf_token();
        session_write_close();

        $surface = Live::memberCartSurface($userId);

        try {
            $revision = Live::revision($surface);
            $etag     = Live::etag($surface, $revision, $this->cartScope($userId));

            if (Live::matchesIfNoneMatch(Request::header('If-None-Match'), $etag)) {
                Live::notModified($etag);
            }

            Live::json($etag, $this->feedPayload($revision, $etag, $this->cartContext($userId)));
        } catch (Throwable $e) {
            error_log('[ecowealth] cart live feed failed: ' . $e->getMessage());
            Live::jsonError('Could not refresh your cart. Please try again.', 500);
        }
    }

    /**
     * Render the cart feed. Public so tests can assert the fragment
     * without going through Live::json()'s exit.
     *
     * @param array{user_id:int, state: array<string, mixed>, shop_open: bool} $data
     * @return array<string, mixed>
     */
    public function feedPayload(int $revision, string $etag, array $data): array
    {
        $state  = $data['state'];
        $totals = $state['totals']
            ?? ['items' => 0, 'quantity' => 0, 'subtotal' => 0.0];

        return [
            'ok'        => true,
            'surface'   => Live::memberCartSurface((int) $data['user_id']),
            'revision'  => $revision,
            'etag'      => $etag,
            'fragments' => [
                'cart-content' => View::capture('shop/cart/_content', [
                    'state'     => $state,
                    'shop_open' => (bool) $data['shop_open'],
                ]),
            ],
            'meta'      => [
                'count'     => (int) $totals['quantity'],
                'items'     => (int) $totals['items'],
                'subtotal'  => round((float) $totals['subtotal'], 2),
                'canonical' => url('cart'),
            ],
        ];
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
        $userId    = $this->userId();
        $itemId    = (int) Request::post('item_id', 0);
        $quantity  = (int) Request::post('quantity', 0);
        $direction = strtolower(Request::string('direction'));

        $this->run(function () use ($userId, $itemId, $quantity, $direction): array {
            $stepping   = $direction === 'up' || $direction === 'down';
            $increasing = $direction === 'up' || (!$stepping && $quantity > 0);

            if (!setting_bool('shop_enabled', true) && $increasing) {
                throw new CartException('The shop is currently closed. Please try again later.', 'shop_closed');
            }

            /* The cart page's +/− buttons submit `direction` so they work with
             * JavaScript disabled; the script intercepts them and posts the
             * input value instead. */
            if ($stepping) {
                return $this->carts->adjustQuantity($userId, $itemId, $direction === 'up' ? 1 : -1);
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

        /* The cart page and an open checkout tab both re-render from these
         * rows. The revision bump is in CartService; this publishes when the
         * response is a normal redirect, and the JSON answer carries the
         * keys so app-cart.js can broadcast them to the member's other tabs
         * immediately. */
        $live = [Live::memberCartSurface($userId), Live::checkoutSurface($userId)];

        Live::publish($live);

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
                'live'               => $live,
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
