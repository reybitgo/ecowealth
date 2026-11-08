<?php
declare(strict_types=1);

/* ==============================================================
 *  OrderController — the member's orders (plan §5.3–5.5, §7
 *  Phases 6–8; reference MemberController::shopOrders/shopOrder/
 *  submitShopProof/cancelShopOrder/confirmReceipt/shopInvoice)
 *
 *  Routes:
 *      GET  /orders                 history with status/search filters
 *      GET  /orders/{id}            order page: items, totals, timeline,
 *                                   payment instructions + proof panel,
 *                                   shipment tracking + receipt confirm
 *      POST /orders/{id}/proof      upload a manual payment proof
 *      POST /orders/{id}/cancel     unpaid orders only (P6-T11)
 *      POST /orders/{id}/confirm    delivered → completed (P8-T4)
 *      GET  /orders/{id}/invoice    printable snapshot, own orders only
 *                                   (P8-T5/T6/T7/T10)
 *
 *  Every read is scoped by the signed-in member; another member's id
 *  resolves to the 404 page, never to a data leak (P6-T8/P7-T9/P8-T7).
 * ============================================================== */

final class OrderController
{
    private OrderService $orders;
    private PaymentService $payments;
    private FulfilmentService $fulfilment;
    private InvoiceService $invoices;

    public function __construct()
    {
        $this->orders     = new OrderService();
        $this->payments   = new PaymentService();
        $this->fulfilment = new FulfilmentService();
        $this->invoices   = new InvoiceService();
    }

    /** @param array<string, string> $params */
    public function index(array $params = []): void
    {
        $user   = $this->member();
        $userId = (int) $user['id'];

        /* The same URL doubles as the JSON feed: X-Live: 1 returns the rows
         * and pagination instead of the layout. */
        if (Live::isFeedRequest()) {
            $this->listFeed($userId);
            return;
        }

        $isAdmin = is_admin($user);

        View::page($isAdmin ? 'admin' : 'member', 'shop/orders', $this->ordersContext($userId) + [
            'page_title'  => 'My orders',
            'current_nav' => $isAdmin ? 'admin.storefront' : 'orders',
            'page_css'    => ['assets/css/shop.css', 'assets/css/orders.css'],
            'live'        => Live::meta(
                Live::memberOrdersSurface($userId),
                Live::scope($_GET, $userId)
            ),
        ]);
    }

    /**
     * Everything the order history renders, built once for the page and its
     * feed.
     *
     * @return array<string, mixed>
     */
    public function ordersContext(int $userId): array
    {
        $filters = Order::normaliseFilters([
            'status' => Request::string('status'),
            'q'      => Request::string('q'),
            'method' => Request::string('method'),
            'from'   => Request::string('from'),
            'to'     => Request::string('to'),
        ]);

        $pager = Paginator::fromTableRequest(Order::countForUser($userId, $filters));

        return [
            'user_id' => $userId,
            'orders'  => Order::listForUser($userId, $filters, $pager),
            'pager'   => $pager,
            'filters' => $filters,
        ];
    }

    /** JSON feed for the member's order history. */
    private function listFeed(int $userId): void
    {
        session_write_close();

        $surface = Live::memberOrdersSurface($userId);

        try {
            $revision = Live::revision($surface);
            $etag     = Live::etag($surface, $revision, Live::scope($_GET, $userId));

            if (Live::matchesIfNoneMatch(Request::header('If-None-Match'), $etag)) {
                Live::notModified($etag);
            }

            Live::json($etag, $this->feedPayload($revision, $etag, $this->ordersContext($userId)));
        } catch (Throwable $e) {
            error_log('[ecowealth] member order list feed failed: ' . $e->getMessage());
            Live::jsonError('Could not refresh your orders. Please try again.', 500);
        }
    }

    /**
     * Render the order-history payload. Public so tests can assert the
     * fragments without going through Live::json()'s exit.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function feedPayload(int $revision, string $etag, array $data): array
    {
        $pager = $data['pager'];

        return [
            'ok'        => true,
            'surface'   => Live::memberOrdersSurface((int) $data['user_id']),
            'revision'  => $revision,
            'etag'      => $etag,
            'fragments' => [
                'member-orders-rows' => View::capture('shop/orders/_rows', [
                    'orders'  => $data['orders'],
                    'filters' => $data['filters'],
                ]),
                'member-orders-pagination' => View::capture('shop/orders/_pagination', [
                    'pager' => $pager,
                ]),
            ],
            'meta'      => [
                'page'      => $pager->page,
                'pages'     => $pager->pages,
                'total'     => $pager->total,
                'per_page'  => $pager->perPage,
                'canonical' => $this->canonicalOrdersUrl($pager),
            ],
        ];
    }

    /** The order-history URL with an out-of-range page clamped. */
    private function canonicalOrdersUrl(Paginator $pager): string
    {
        $query = [];

        foreach ($_GET as $key => $value) {
            if (is_scalar($value) && $key !== 'XTransformPort') {
                $query[(string) $key] = (string) $value;
            }
        }

        unset($query['page']);

        if ($pager->page > 1) {
            $query['page'] = (string) $pager->page;
        }

        $qs = http_build_query($query);

        return url('orders' . ($qs !== '' ? '?' . $qs : ''));
    }

    /** @param array<string, string> $params */
    public function show(array $params = []): void
    {
        $user    = $this->member();
        $userId  = (int) $user['id'];
        $orderId = (int) ($params['id'] ?? 0);

        if (Live::isFeedRequest()) {
            $this->detailFeed($userId, $orderId);
            return;
        }

        $order = Order::findForUser($orderId, $userId);

        if ($order === null) {
            View::error(404);
        }

        $isAdmin = is_admin($user);

        View::page($isAdmin ? 'admin' : 'member', 'shop/order', $this->orderContext($order) + [
            'page_title'  => 'Order ' . (string) $order['order_no'],
            'current_nav' => $isAdmin ? 'admin.storefront' : 'orders',
            'page_css'    => [
                'assets/css/shop.css',
                'assets/css/orders.css',
                'assets/vendor/libs/sweetalert2/sweetalert2.css',
            ],
            'page_js'     => [
                'assets/vendor/libs/sweetalert2/sweetalert2.js',
                'assets/js/app-confirm.js',
                'assets/js/admin-live.js',
            ],
            'live'        => Live::meta(
                Live::memberOrderSurface($orderId),
                $this->detailScope($userId)
            ),
        ]);
    }

    /**
     * ETag scope for one member order.
     *
     * The payment/correction window labels read the clock, so the hour is
     * part of the scope: a tab left open past a deadline revalidates on its
     * next poll instead of keeping the old "pay by" wording.
     */
    private function detailScope(int $userId): string
    {
        return Live::scope($_GET, $userId) . '|hour:' . date('Y-m-d H');
    }

    /**
     * Everything one member order page renders, built once for the page and
     * its feed. Public so tests can assert the real screen data.
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    public function orderContext(array $order): array
    {
        $orderId = (int) $order['id'];
        $status  = (string) $order['status'];
        $proofs  = PaymentProof::forOrder($orderId);
        $latest  = $proofs === [] ? null : $proofs[count($proofs) - 1];
        $window  = $this->payments->windowFor($order);
        $shipment = Shipment::latestFor($orderId);
        $invoice  = Invoice::latestFor($orderId);

        $deadline = $order['payment_deadline'] !== null ? (string) $order['payment_deadline'] : '';
        $overdue  = $deadline !== '' && strtotime($deadline) !== false && strtotime($deadline) < time();

        $instructions = [];

        if ($status === 'pending') {
            $instructions = match ((string) $order['payment_method']) {
                'gcash' => [
                    'GCash number' => (string) setting('payment_gcash_number', ''),
                    'Account name' => (string) setting('payment_gcash_name', ''),
                ],
                'maya' => [
                    'Maya number'  => (string) setting('payment_maya_number', ''),
                    'Account name' => (string) setting('payment_maya_name', ''),
                ],
                'bank' => [
                    'Bank'           => (string) setting('payment_bank_name', ''),
                    'Account name'   => (string) setting('payment_bank_account_name', ''),
                    'Account number' => (string) setting('payment_bank_account_number', ''),
                ],
                default => [],
            };
        }

        $fullAddress = implode(', ', array_filter([
            (string) $order['shipping_address'],
            (string) ($order['shipping_barangay'] ?? ''),
            (string) ($order['shipping_city'] ?? ''),
            (string) ($order['shipping_province'] ?? ''),
            (string) ($order['shipping_postal'] ?? ''),
        ]));

        return [
            'order'           => $order,
            'lines'           => Order::items($orderId),
            'events'          => Order::events($orderId),
            'proofs'          => $proofs,
            'latest_proof'    => $latest,
            'window'          => $window,
            'shipment'        => $shipment,
            'invoice'         => $invoice,
            'can_cancel'      => Order::canMemberCancel($order),
            'can_confirm'     => Order::canMemberConfirm($order),
            'auto_days'       => FulfilmentService::autoCompleteDays(),
            'status'          => $status,
            'order_id'        => $orderId,
            'overdue'         => $overdue,
            'window_open'     => (bool) ($window['open'] ?? false),
            'window_deadline' => $window['deadline'] ?? null,
            'is_manual'       => (string) $order['payment_method'] !== 'ewallet',
            'instructions'    => $instructions,
            'full_address'    => $fullAddress,
            'tracking'        => $shipment === null ? '' : trim((string) ($shipment['tracking_number'] ?? '')),
            'courier'         => $shipment === null ? '' : trim((string) ($shipment['courier'] ?? '')),
            'has_invoice'     => $invoice !== null || Order::isPaid($order),
            'invoice_url'     => url('orders/' . $orderId . '/invoice'),
        ];
    }

    /**
     * JSON feed for one open member order.
     *
     * The 304 check runs before orderContext(), so an unchanged order costs
     * one revision lookup and no reads. The status card carries the proof /
     * cancel / confirm forms, so the CSRF token is primed before the session
     * lock is released.
     */
    private function detailFeed(int $userId, int $orderId): void
    {
        csrf_token();
        session_write_close();

        $surface = Live::memberOrderSurface($orderId);

        try {
            $revision = Live::revision($surface);
            $etag     = Live::etag($surface, $revision, $this->detailScope($userId));

            if (Live::matchesIfNoneMatch(Request::header('If-None-Match'), $etag)) {
                Live::notModified($etag);
            }

            $order = Order::findForUser($orderId, $userId);

            if ($order === null) {
                Live::jsonError('Order not found.', 404);
            }

            Live::json($etag, $this->detailFeedPayload($revision, $etag, $this->orderContext($order)));
        } catch (Throwable $e) {
            error_log('[ecowealth] member order live feed failed: ' . $e->getMessage());
            Live::jsonError('Could not refresh this order. Please try again.', 500);
        }
    }

    /**
     * Render the member order payload. Public so tests can assert the
     * fragments without going through Live::json()'s exit.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function detailFeedPayload(int $revision, string $etag, array $data): array
    {
        $order = $data['order'];

        return [
            'ok'        => true,
            'surface'   => Live::memberOrderSurface((int) $order['id']),
            'revision'  => $revision,
            'etag'      => $etag,
            'fragments' => [
                'member-order-header' => View::capture('shop/order/_header', [
                    'order'       => $order,
                    'status'      => $data['status'],
                    'has_invoice' => $data['has_invoice'],
                    'invoice_url' => $data['invoice_url'],
                ]),
                'member-order-status' => View::capture('shop/order/_status', [
                    'order'           => $order,
                    'order_id'        => $data['order_id'],
                    'status'          => $data['status'],
                    'window'          => $data['window'],
                    'window_open'     => $data['window_open'],
                    'window_deadline' => $data['window_deadline'],
                    'is_manual'       => $data['is_manual'],
                    'overdue'         => $data['overdue'],
                    'latest_proof'    => $data['latest_proof'],
                    'instructions'    => $data['instructions'],
                    'can_confirm'     => $data['can_confirm'],
                    'can_cancel'      => $data['can_cancel'],
                    'auto_days'       => $data['auto_days'],
                    'courier'         => $data['courier'],
                    'tracking'        => $data['tracking'],
                ]),
                'member-order-proofs' => View::capture('shop/order/_proofs', [
                    'proofs' => $data['proofs'],
                    'window' => $data['window'],
                ]),
                'member-order-delivery' => View::capture('shop/order/_delivery', [
                    'order'        => $order,
                    'shipment'     => $data['shipment'],
                    'tracking'     => $data['tracking'],
                    'courier'      => $data['courier'],
                    'full_address' => $data['full_address'],
                ]),
                'member-order-payment' => View::capture('shop/order/_payment', [
                    'order'       => $order,
                    'has_invoice' => $data['has_invoice'],
                    'invoice_url' => $data['invoice_url'],
                ]),
                'member-order-history' => View::capture('shop/order/_history', [
                    'events' => $data['events'],
                ]),
            ],
            'meta'      => [
                'canonical' => $this->canonicalOrderUrl((int) $order['id']),
            ],
        ];
    }

    /** The order URL (keeps any extra query keys). */
    private function canonicalOrderUrl(int $orderId): string
    {
        $query = [];

        foreach ($_GET as $key => $value) {
            if (is_scalar($value) && $key !== 'XTransformPort') {
                $query[(string) $key] = (string) $value;
            }
        }

        $qs = http_build_query($query);

        return url('orders/' . $orderId . ($qs !== '' ? '?' . $qs : ''));
    }

    /**
     * POST /orders/{id}/proof — upload manual payment evidence (P7-T1).
     *
     * The service owns every rule (ownership, window, attempt cap, file
     * type/size, transaction); this method only maps a refusal back to
     * the order page with its field errors.
     *
     * @param array<string, string> $params
     */
    public function proof(array $params = []): void
    {
        $user    = $this->member();
        $orderId = (int) ($params['id'] ?? 0);
        $claim   = [
            'amount_sent'   => Request::post('amount_sent'),
            'reference_no'  => Request::post('reference_no'),
            'transfer_date' => Request::post('transfer_date'),
        ];

        try {
            $result = $this->payments->submitProof(
                (int) $user['id'],
                $orderId,
                $_FILES['proof_image'] ?? [],
                $claim
            );
        } catch (PaymentException $e) {
            if ($e->reason === 'not_found') {
                View::error(404);
            }

            if ($e->fieldErrors !== []) {
                errors_set($e->fieldErrors);
                old_set([
                    'amount_sent'   => (string) ($claim['amount_sent'] ?? ''),
                    'reference_no'  => (string) ($claim['reference_no'] ?? ''),
                    'transfer_date' => (string) ($claim['transfer_date'] ?? ''),
                ]);
            }

            flash_set('danger', $e->getMessage());
            redirect('orders/' . $orderId);
        } catch (Throwable $e) {
            error_log('[ecowealth] proof upload failed: ' . $e->getMessage());
            flash_set('danger', 'The payment proof could not be uploaded. Please try again.');
            redirect('orders/' . $orderId);
        }

        Live::publish([
            Live::memberOrdersSurface((int) $user['id']),
            Live::memberOrderSurface($orderId),
        ]);

        flash_set(
            'success',
            sprintf(
                'Payment proof submitted (attempt %d). We will verify it shortly.',
                (int) $result['attempt']
            )
        );
        redirect('orders/' . $orderId);
    }

    /** POST /orders/{id}/cancel */
    public function cancel(array $params = []): void
    {
        $user    = $this->member();
        $orderId = (int) ($params['id'] ?? 0);

        try {
            $this->orders->cancelByMember(
                (int) $user['id'],
                $orderId,
                Request::string('reason')
            );
        } catch (OrderException $e) {
            if ($e->reason === 'not_found') {
                View::error(404);
            }

            flash_set('danger', $e->getMessage());
            redirect('orders/' . $orderId);
        } catch (Throwable $e) {
            error_log('[ecowealth] order cancel failed: ' . $e->getMessage());
            flash_set('danger', 'The order could not be cancelled. Please try again.');
            redirect('orders/' . $orderId);
        }

        Live::publish([
            Live::memberOrdersSurface((int) $user['id']),
            Live::memberOrderSurface($orderId),
        ]);

        flash_set('success', 'Order cancelled. Any reserved stock has been released.');
        redirect('orders/' . $orderId);
    }

    /**
     * POST /orders/{id}/confirm — delivered → completed (P8-T4).
     *
     * The service scopes the order to the caller, so another member's
     * id is a 404 and a wrong status is refused with a clear message.
     *
     * @param array<string, string> $params
     */
    public function confirm(array $params = []): void
    {
        $user    = $this->member();
        $orderId = (int) ($params['id'] ?? 0);

        try {
            $this->fulfilment->confirmByMember((int) $user['id'], $orderId, Request::string('note'));
        } catch (FulfilmentException $e) {
            if ($e->reason === 'not_found') {
                View::error(404);
            }

            if ($e->fieldErrors !== []) {
                errors_set($e->fieldErrors);
                old_set(['note' => Request::string('note')]);
            }

            flash_set('danger', $e->getMessage());
            redirect('orders/' . $orderId);
        } catch (Throwable $e) {
            error_log('[ecowealth] order confirm failed: ' . $e->getMessage());
            flash_set('danger', 'The receipt could not be confirmed. Please try again.');
            redirect('orders/' . $orderId);
        }

        Live::publish([
            Live::memberOrdersSurface((int) $user['id']),
            Live::memberOrderSurface($orderId),
        ]);

        flash_set('success', 'Receipt confirmed — thank you! This order is now complete.');
        redirect('orders/' . $orderId);
    }

    /**
     * GET /orders/{id}/invoice — the printable snapshot (P8-T5/T6/T10).
     *
     * Only the member's own order is readable (P8-T7), and only once
     * the payment has been received. `ensureForOrder()` also backfills
     * an invoice for orders paid before Phase 8 shipped.
     *
     * @param array<string, string> $params
     */
    public function invoice(array $params = []): void
    {
        $user  = $this->member();
        $order = Order::findForUser((int) ($params['id'] ?? 0), (int) $user['id']);

        if ($order === null) {
            View::error(404);
        }

        $orderId  = (int) $order['id'];
        $document = $this->invoices->ensureForOrder($orderId);

        if ($document === null) {
            flash_set('warning', 'The invoice is available once this order has been paid.');
            redirect('orders/' . $orderId);
        }

        InvoicePage::send($document, $order, url('orders/' . $orderId));
    }

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
}
