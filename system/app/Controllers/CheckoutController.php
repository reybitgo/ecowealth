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
        $user = $this->member();

        if (!setting_bool('shop_enabled', true)) {
            flash_set('warning', 'The shop is currently closed. Please try again later.');
            redirect('cart');
        }

        $state = $this->carts->state((int) $user['id'], true);

        if ($state['items'] === []) {
            flash_set('info', 'Your cart is empty. Add a product before checking out.');
            redirect('shop');
        }

        if ($state['stock_errors'] !== []) {
            flash_set('warning', (string) $state['stock_errors'][0]);
            redirect('cart');
        }

        $addresses = $this->addresses->forUser((int) $user['id']);
        $selected  = $this->selectedAddress((int) $user['id'], $addresses, (int) Request::query('address', 0));
        $prefill   = $this->prefill($user, $selected);

        $wallet       = Wallet::account((int) $user['id']) ?? [];
        $balance      = round((float) ($wallet['ewallet_balance'] ?? 0), 2);
        $withdrawable = round((float) ($wallet['withdrawable_balance'] ?? 0), 2);
        $total        = round((float) $state['totals']['subtotal'], 2);

        $methods = [
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

        $isAdmin = is_admin($user);

        View::page($isAdmin ? 'admin' : 'member', 'shop/checkout', [
            'page_title'      => 'Checkout',
            'current_nav'     => $isAdmin ? 'admin.storefront' : 'cart',
            'page_css'        => ['assets/css/shop.css', 'assets/css/orders.css'],
            'page_js'         => ['assets/js/app-checkout.js'],
            'state'           => $state,
            'addresses'       => $addresses,
            'selected'        => $selected,
            'prefill'         => $prefill,
            'wallet'          => ['balance' => $balance, 'withdrawable' => $withdrawable],
            'payment_methods' => $methods,
            'terms_version'   => OrderService::TERMS_VERSION,
            'idempotency_key' => $this->newIdempotencyKey(),
            'shop_open'       => true,
        ]);
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
