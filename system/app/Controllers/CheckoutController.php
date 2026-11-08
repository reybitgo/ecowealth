<?php
declare(strict_types=1);

/* ==============================================================
 *  CheckoutController — cart → order (plan §5.3, §7 Phase 6;
 *  reference MemberController::checkout/placeOrder)
 *
 *  Routes:
 *      GET  /checkout   address picker + payment + terms + idempotency key
 *      POST /checkout   place the order (OrderService owns the transaction)
 *
 *  The page only offers the e-wallet when the balance covers the
 *  total; the server re-checks it inside the placement transaction.
 *  Field errors and the typed address survive a failed attempt.
 * ============================================================== */

final class CheckoutController
{
    private CartService $carts;
    private AddressService $addresses;
    private OrderService $orders;

    public function __construct()
    {
        $this->carts     = new CartService();
        $this->addresses = new AddressService();
        $this->orders    = new OrderService();
    }

    /** @param array<string, string> $params */
    public function index(array $params = []): void
    {
        $user   = $this->member();
        $userId = (int) $user['id'];

        /* The same URL doubles as the JSON feed for the address picker,
         * the order summary and the payment/wallet card. The delivery
         * form and the terms stay outside every region. */
        if (Live::isFeedRequest()) {
            $this->feed($userId);
            return;
        }

        if (!setting_bool('shop_enabled', true)) {
            flash_set('warning', 'The shop is currently closed. Please try again later.');
            redirect('cart');
        }

        $state = $this->carts->state($userId, true);

        if ($state['items'] === []) {
            flash_set('info', 'Your cart is empty. Add a product before checking out.');
            redirect('shop');
        }

        if ($state['stock_errors'] !== []) {
            flash_set('warning', (string) $state['stock_errors'][0]);
            redirect('cart');
        }

        $isAdmin = is_admin($user);

        View::page($isAdmin ? 'admin' : 'member', 'shop/checkout', $this->checkoutContext($user) + [
            'page_title'      => 'Checkout',
            'current_nav'     => $isAdmin ? 'admin.storefront' : 'cart',
            'page_css'        => ['assets/css/shop.css', 'assets/css/orders.css'],
            'page_js'         => ['assets/js/app-checkout.js', 'assets/js/admin-live.js'],
            'idempotency_key' => $this->newIdempotencyKey(),
            'shop_open'       => true,
            'live'            => Live::meta(
                Live::checkoutSurface($userId),
                $this->checkoutScope($userId)
            ),
        ]);
    }

    /**
     * Everything the checkout page renders, built once for the page and
     * its feed. Public so the view tests can assert the real screen data.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function checkoutContext(array $user): array
    {
        $userId = (int) $user['id'];
        $state  = $this->carts->state($userId, true);

        $addresses = $this->addresses->forUser($userId);
        $selected  = $this->selectedAddress($userId, $addresses, (int) Request::query('address', 0));

        $walletRow    = Wallet::account($userId) ?? [];
        $balance      = round((float) ($walletRow['ewallet_balance'] ?? 0), 2);
        $withdrawable = round((float) ($walletRow['withdrawable_balance'] ?? 0), 2);
        $total        = round((float) ($state['totals']['subtotal'] ?? 0), 2);

        return [
            'user_id'         => $userId,
            'state'           => $state,
            'addresses'       => $addresses,
            'selected'        => $selected,
            'selected_id'     => $selected === null ? 0 : (int) $selected['id'],
            'prefill'         => $this->prefill($user, $selected),
            'wallet'          => ['balance' => $balance, 'withdrawable' => $withdrawable],
            'payment_methods' => $this->paymentMethods($balance, $total),
            'terms_version'   => OrderService::TERMS_VERSION,
            'posted'          => old_payload(),
        ];
    }

    /**
     * The payment options, with the e-wallet only offered when its
     * balance covers the order.
     *
     * @return array<string, array{label:string, icon:string, hint:string, available:bool, balance?:float}>
     */
    private function paymentMethods(float $balance, float $total): array
    {
        return [
            'ewallet' => [
                'label'     => 'E-wallet',
                'icon'      => 'bx-wallet',
                'balance'   => $balance,
                'hint'      => $balance >= $total
                    ? 'Pay immediately from your wallet balance.'
                    : 'Not enough for this order — choose another method or top up.',
                'available' => $balance >= $total,
            ],
            'gcash' => [
                'label'     => 'GCash',
                'icon'      => 'bx-mobile-alt',
                'hint'      => 'Send the payment, then upload the proof from the order page.',
                'available' => true,
            ],
            'maya' => [
                'label'     => 'Maya',
                'icon'      => 'bx-mobile',
                'hint'      => 'Send the payment, then upload the proof from the order page.',
                'available' => true,
            ],
            'bank' => [
                'label'     => 'Bank transfer',
                'icon'      => 'bx-credit-card',
                'hint'      => 'Transfer to the company account, then upload the proof from the order page.',
                'available' => true,
            ],
        ];
    }

    /**
     * ETag scope for the checkout page.
     *
     * The query string keeps the selected address; the shared shop
     * revision revalidates the summary when a product price or the
     * available stock changes, without a per-member fan-out.
     */
    private function checkoutScope(int $userId): string
    {
        return Live::scope($_GET, $userId)
            . '|shop:' . Live::revisionOrZero('shop.catalog');
    }

    /**
     * JSON feed for the open checkout page.
     *
     * The 304 check runs before checkoutContext(), so an unchanged page
     * costs two revision lookups and no cart/address/wallet queries. No
     * fragment renders a CSRF field, so the session lock is released
     * immediately.
     */
    private function feed(int $userId): void
    {
        session_write_close();

        $surface = Live::checkoutSurface($userId);

        try {
            $revision = Live::revision($surface);
            $etag     = Live::etag($surface, $revision, $this->checkoutScope($userId));

            if (Live::matchesIfNoneMatch(Request::header('If-None-Match'), $etag)) {
                Live::notModified($etag);
            }

            $user = Member::find($userId);

            if ($user === null) {
                Live::jsonError('Member not found.', 404);
            }

            Live::json($etag, $this->feedPayload($revision, $etag, $this->checkoutContext($user)));
        } catch (Throwable $e) {
            error_log('[ecowealth] checkout live feed failed: ' . $e->getMessage());
            Live::jsonError('Could not refresh the checkout. Please try again.', 500);
        }
    }

    /**
     * Render the checkout payload. Public so tests can assert the
     * fragments without going through Live::json()'s exit.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function feedPayload(int $revision, string $etag, array $data): array
    {
        return [
            'ok'        => true,
            'surface'   => Live::checkoutSurface((int) $data['user_id']),
            'revision'  => $revision,
            'etag'      => $etag,
            'fragments' => [
                'checkout-addresses' => View::capture('shop/checkout/_addresses', [
                    'addresses'   => $data['addresses'],
                    'selected_id' => $data['selected_id'],
                ]),
                'checkout-summary' => View::capture('shop/checkout/_summary', [
                    'state'  => $data['state'],
                    'wallet' => $data['wallet'],
                ]),
                'checkout-payment' => View::capture('shop/checkout/_payment', [
                    'payment_methods' => $data['payment_methods'],
                    'posted'          => $data['posted'],
                ]),
            ],
            'meta'      => [
                'canonical' => $this->canonicalCheckoutUrl($data['selected']),
            ],
        ];
    }

    /**
     * The checkout URL with a stale `address` id removed.
     *
     * The picker falls back to the default when the requested address was
     * deleted; the URL should not keep pointing at it.
     */
    private function canonicalCheckoutUrl(?array $selected): string
    {
        $query = [];

        foreach ($_GET as $key => $value) {
            if (is_scalar($value) && $key !== 'XTransformPort') {
                $query[(string) $key] = (string) $value;
            }
        }

        $requested = isset($query['address']) ? (int) $query['address'] : 0;

        if ($selected === null || ($requested > 0 && $requested !== (int) $selected['id'])) {
            unset($query['address']);
        }

        $qs = http_build_query($query);

        return url('checkout' . ($qs !== '' ? '?' . $qs : ''));
    }

    /** POST /checkout */
    public function place(array $params = []): void
    {
        $user = $this->member();

        $input = [
            'billing_name'      => Request::string('billing_name'),
            'billing_phone'     => Request::string('billing_phone'),
            'shipping_name'     => Request::string('shipping_name'),
            'shipping_phone'    => Request::string('shipping_phone'),
            'shipping_address'  => Request::string('shipping_address'),
            'shipping_barangay' => Request::string('shipping_barangay'),
            'shipping_city'     => Request::string('shipping_city'),
            'shipping_province' => Request::string('shipping_province'),
            'shipping_postal'   => Request::string('shipping_postal'),
            'notes_member'      => Request::string('notes_member'),
            'payment_method'    => Request::string('payment_method'),
            'idempotency_key'   => Request::string('idempotency_key'),
            'terms'             => Request::post('terms') === '1' ? '1' : '',
            'save_address'      => Request::post('save_address') === '1',
        ];

        try {
            $result = $this->orders->placeFromCart((int) $user['id'], $input);
        } catch (OrderException $e) {
            $errors = $e->fieldErrors !== []
                ? $e->fieldErrors
                : ['checkout' => $e->getMessage()];

            errors_set($errors);
            old_payload_set($input);
            flash_set('danger', $e->fieldErrors !== [] ? 'Please fix the highlighted checkout fields.' : $e->getMessage());
            redirect('checkout');
        } catch (Throwable $e) {
            error_log('[ecowealth] checkout failed: ' . $e->getMessage());
            old_payload_set($input);
            flash_set('danger', 'Could not place the order. Please try again.');
            redirect('checkout');
        }

        /* Keep the typed address for next time. A failure here must never
         * turn a placed order into an error page. */
        if ($input['save_address'] === true) {
            try {
                $this->addresses->saveFromCheckout((int) $user['id'], $input);
            } catch (Throwable $e) {
                error_log('[ecowealth] checkout address save failed: ' . $e->getMessage());
            }
        }

        old_clear();
        old_payload_clear();

        $publish = [
            Live::memberOrdersSurface((int) $user['id']),
            Live::memberOrderSurface((int) $result['order_id']),
        ];

        /* An e-wallet payment debits the wallet inside the placement
         * transaction, so another open statement tab must revalidate too. */
        if ((string) ($result['status'] ?? '') === 'paid') {
            $publish[] = Live::memberWalletSurface((int) $user['id']);
        }

        /* Checkout can append the typed address to the address book, so an
         * open profile page and another checkout tab revalidate. */
        if ($input['save_address'] === true) {
            $publish[] = Live::profileSurface((int) $user['id']);
            $publish[] = Live::checkoutSurface((int) $user['id']);
        }

        Live::publish($publish);

        flash_set(
            'success',
            $result['status'] === 'paid'
                ? 'Order placed and paid. Thank you!'
                : 'Order placed. Your payment is pending — the payment window is shown on the order page.'
        );

        redirect('orders/' . (int) $result['order_id']);
    }

    /* ----------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------- */

    /** @return array<string, mixed> */
    private function member(): array
    {
        $user = auth_user();

        if ($user === null) {
            flash_set('warning', 'Please sign in to continue.');
            redirect('login');
        }

        return $user;
    }

    /**
     * @param list<array<string, mixed>> $addresses
     * @return array<string, mixed>|null
     */
    private function selectedAddress(int $userId, array $addresses, int $requestedId): ?array
    {
        if ($requestedId > 0) {
            $requested = Address::findOwned($requestedId, $userId);

            if ($requested !== null) {
                return $requested;
            }
        }

        foreach ($addresses as $address) {
            if ((int) $address['is_default'] === 1) {
                return $address;
            }
        }

        return $addresses[0] ?? null;
    }

    /**
     * @param array<string, mixed>      $user
     * @param array<string, mixed>|null $address
     * @return array<string, string>
     */
    private function prefill(array $user, ?array $address): array
    {
        $fullName = trim((string) ($user['full_name'] ?? ''));

        if ($address === null) {
            return [
                'billing_name'      => $fullName,
                'billing_phone'     => '',
                'shipping_name'     => $fullName,
                'shipping_phone'    => '',
                'shipping_address'  => '',
                'shipping_barangay' => '',
                'shipping_city'     => '',
                'shipping_province' => '',
                'shipping_postal'   => '',
                'notes_member'      => '',
            ];
        }

        $recipient = (string) ($address['recipient_name'] ?? '');

        return [
            'billing_name'      => (string) ($address['billing_name'] ?? '') !== '' ? (string) $address['billing_name'] : $recipient,
            'billing_phone'     => (string) ($address['billing_phone'] ?? '') !== '' ? (string) $address['billing_phone'] : (string) ($address['phone'] ?? ''),
            'shipping_name'     => $recipient,
            'shipping_phone'    => (string) ($address['phone'] ?? ''),
            'shipping_address'  => (string) ($address['address_line'] ?? ''),
            'shipping_barangay' => (string) ($address['barangay'] ?? ''),
            'shipping_city'     => (string) ($address['city'] ?? ''),
            'shipping_province' => (string) ($address['province'] ?? ''),
            'shipping_postal'   => (string) ($address['postal'] ?? ''),
            'notes_member'      => (string) ($address['notes'] ?? ''),
        ];
    }

    private function newIdempotencyKey(): string
    {
        return bin2hex(random_bytes(16));
    }
}
