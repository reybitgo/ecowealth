<?php
declare(strict_types=1);

/* ==============================================================
 *  AdminOrderController — the order desk (plan §7 Phases 6–8;
 *  reference AdminController::shopOrders/shopOrder/orderTransition/
 *  orderProofView/orderShipment/orderInvoice)
 *
 *  Routes:
 *      GET  /admin/orders                 all orders, filters + stats
 *      GET  /admin/orders/{id}            one order + payment review
 *                                         + fulfilment panel
 *      POST /admin/orders/{id}/transition verify / reject / cancel
 *                                         + pack / ready to ship
 *      POST /admin/orders/{id}/ship       courier + tracking, stock deducted
 *      POST /admin/orders/{id}/deliver    POD note, shipment stamped
 *      GET  /admin/orders/{id}/invoice    printable snapshot
 *      GET  /admin/proofs/{id}            stream one proof (guarded)
 *
 *  The admin guard is enforced by the router; the payment service
 *  additionally refuses any review of an order the signed-in admin
 *  placed (P7-T10). Fulfilment is not a money decision, so an admin
 *  may ship and deliver their own order.
 * ============================================================== */

final class AdminOrderController
{
    private PaymentService $payments;
    private FulfilmentService $fulfilment;
    private InvoiceService $invoices;

    public function __construct()
    {
        $this->payments   = new PaymentService();
        $this->fulfilment = new FulfilmentService();
        $this->invoices   = new InvoiceService();
    }

    /** @param array<string, string> $params */
    public function index(array $params = []): void
    {
        /* The same URL is both the page and its feed: X-Live: 1 turns
         * the request into JSON fragments. Paginator::url() and the
         * per-page form therefore keep pointing at /admin/orders. */
        if (Live::isFeedRequest()) {
            $this->listFeed();
            return;
        }

        $data = $this->listContext();

        View::page('admin', 'admin/orders/index', $data + [
            'page_title'  => 'Orders',
            'current_nav' => 'admin.orders',
            'page_css'    => ['assets/css/shop.css', 'assets/css/orders.css'],
            'live'        => $this->listLiveMeta(),
        ]);
    }

    /**
     * Build the order-desk list once for both the page and its feed.
     *
     * @return array{
     *   orders: list<array<string, mixed>>,
     *   pager: Paginator,
     *   filters: array{type:string, q:string, status:string, method:string, from:string, to:string},
     *   stats: array{total:int, awaiting_payment:int, in_review:int, in_progress:int, completed:int, cancelled:int, revenue:float, today_count:int, today_total:float}
     * }
     */
    private function listContext(): array
    {
        $filters = Order::normaliseFilters([
            'status' => Request::string('status'),
            'q'      => Request::string('q'),
            'method' => Request::string('method'),
            'from'   => Request::string('from'),
            'to'     => Request::string('to'),
        ]);

        $pager = Paginator::fromTableRequest(Order::countAdmin($filters));

        return [
            'orders'  => Order::adminList($filters, $pager),
            'pager'   => $pager,
            'filters' => $filters,
            'stats'   => Order::stats(),
        ];
    }

    /**
     * ETag scope for the list.
     *
     * The "today" counters are relative to the current date, so the day is
     * part of the scope: a tab left open across midnight revalidates on its
     * next poll instead of showing yesterday's numbers.
     */
    private function listScope(int $userId): string
    {
        return Live::scope($_GET, $userId) . '|day:' . date('Y-m-d');
    }

    /** @return array{surface:string, enabled:bool, revision:int, etag:string, interval:int} */
    private function listLiveMeta(): array
    {
        return Live::meta('admin.orders', $this->listScope((int) (auth_user()['id'] ?? 0)));
    }

    /**
     * JSON feed for the open order desk.
     *
     * The 304 check runs before listContext(), so an unchanged surface
     * costs one primary-key lookup and no list/aggregate queries.
     */
    private function listFeed(): void
    {
        $userId = (int) (auth_user()['id'] ?? 0);

        /* No fragment renders a form, but keep the token primed in case a
         * later region does; the lock is released before any query work. */
        csrf_token();
        session_write_close();

        $surface = 'admin.orders';

        try {
            $revision = Live::revision($surface);
            $etag     = Live::etag($surface, $revision, $this->listScope($userId));

            if (Live::matchesIfNoneMatch(Request::header('If-None-Match'), $etag)) {
                Live::notModified($etag);
            }

            Live::json($etag, $this->feedPayload($revision, $etag, $this->listContext()));
        } catch (Throwable $e) {
            error_log('[ecowealth] order live feed failed: ' . $e->getMessage());
            Live::jsonError('Could not refresh the order list. Please try again.', 500);
        }
    }

    /**
     * Render the list feed payload. Public so the view tests can assert the
     * fragments without going through Live::json()'s exit.
     *
     * @param array{
     *   orders: list<array<string, mixed>>,
     *   pager: Paginator,
     *   filters: array{type:string, q:string, status:string, method:string, from:string, to:string},
     *   stats: array{total:int, awaiting_payment:int, in_review:int, in_progress:int, completed:int, cancelled:int, revenue:float, today_count:int, today_total:float}
     * } $data
     * @return array<string, mixed>
     */
    public function feedPayload(int $revision, string $etag, array $data): array
    {
        return [
            'ok'        => true,
            'surface'   => 'admin.orders',
            'revision'  => $revision,
            'etag'      => $etag,
            'fragments' => [
                'order-header' => View::capture('admin/orders/_header', [
                    'stats' => $data['stats'],
                ]),
                'order-stats' => View::capture('admin/orders/_stats', [
                    'stats' => $data['stats'],
                ]),
                'order-rows' => View::capture('admin/orders/_rows', [
                    'orders'  => $data['orders'],
                    'filters' => $data['filters'],
                ]),
                'order-pagination' => View::capture('admin/orders/_pagination', [
                    'pager'       => $data['pager'],
                    'pager_label' => 'Orders',
                ]),
            ],
            'meta' => [
                'page'      => $data['pager']->page,
                'pages'     => $data['pager']->pages,
                'total'     => $data['pager']->total,
                'per_page'  => $data['pager']->perPage,
                'canonical' => $this->canonicalListUrl($data['pager']),
            ],
        ];
    }

    /** The list URL with an out-of-range page clamped to a real page. */
    private function canonicalListUrl(Paginator $pager): string
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

        return url('admin/orders' . ($qs !== '' ? '?' . $qs : ''));
    }

    /**
     * One order's screen.
     *
     * The same URL doubles as the JSON feed (`X-Live: 1`). The status, the
     * payment review pane, fulfilment, items, history and each side card
     * are separate regions; a region that owns a form carries
     * `data-live-hold="dirty"` so the client keeps the user's unsaved input
     * and shows a "changed in another tab" banner instead of replacing it.
     *
     * @param array<string, string> $params
     */
    public function show(array $params = []): void
    {
        $orderId = (int) ($params['id'] ?? 0);

        if (Live::isFeedRequest()) {
            $this->detailFeed($orderId);
            return;
        }

        $order = Order::find($orderId);

        if ($order === null) {
            View::error(404);
        }

        View::page('admin', 'admin/orders/show', [
            'page_title'  => 'Order ' . (string) $order['order_no'],
            'current_nav' => 'admin.orders',
            'page_css'    => [
                'assets/css/shop.css',
                'assets/css/orders.css',
                'assets/vendor/libs/sweetalert2/sweetalert2.css',
            ],
            'page_js'     => [
                'assets/vendor/libs/sweetalert2/sweetalert2.js',
                'assets/js/app-confirm.js',
                'assets/js/app-ship.js',
                'assets/js/admin-live.js',
            ],
            'ctx'  => $this->orderContext($order),
            'live' => Live::meta(
                Live::orderSurface($orderId),
                Live::scope($_GET, (int) (auth_user()['id'] ?? 0))
            ),
        ]);
    }

    /**
     * Everything the order screen renders, built once for the page and its
     * feed. Public so the view tests can assert the real screen data.
     *
     * @return array<string, mixed>
     */
    public function orderContext(array $order): array
    {
        $admin    = auth_user();
        $adminId  = $admin === null ? 0 : (int) $admin['id'];
        $orderId  = (int) $order['id'];
        $status   = (string) $order['status'];
        $proofs   = PaymentProof::forOrder($orderId);
        $latest   = $proofs === [] ? null : $proofs[count($proofs) - 1];
        $isSelf   = $adminId > 0 && (int) $order['user_id'] === $adminId;
        $shipment = Shipment::latestFor($orderId);
        $invoice  = Invoice::latestFor($orderId);

        $courier  = $shipment === null ? '' : trim((string) ($shipment['courier'] ?? ''));
        $tracking = $shipment === null ? '' : trim((string) ($shipment['tracking_number'] ?? ''));

        $fullAddress = implode(', ', array_filter([
            (string) $order['shipping_address'],
            (string) ($order['shipping_barangay'] ?? ''),
            (string) ($order['shipping_city'] ?? ''),
            (string) ($order['shipping_province'] ?? ''),
            (string) ($order['shipping_postal'] ?? ''),
        ]));

        return [
            'order'            => $order,
            'lines'            => Order::items($orderId),
            'events'           => Order::events($orderId),
            'member'           => Wallet::account((int) $order['user_id']),
            'proofs'           => $proofs,
            'latest_proof'     => $latest,
            'window'           => $this->payments->windowFor($order),
            'is_self'          => $isSelf,
            'can_review'       => !$isSelf
                && $status === 'payment_review'
                && $latest !== null
                && (string) $latest['status'] === 'pending',
            'can_cancel'       => !$isSelf && Order::canAdminCancel($order),
            'refund_on_cancel' => !$isSelf && Order::isRefundable($order),
            'reject_reasons'   => PaymentService::REJECT_REASONS,
            'cancel_reasons'   => PaymentService::CANCEL_REASONS,
            'shipment'         => $shipment,
            'invoice'          => $invoice,
            'has_invoice'      => $invoice !== null || Order::isPaid($order),
            'invoice_url'      => url('admin/orders/' . $orderId . '/invoice'),
            'can_pack'         => $status === 'paid',
            'can_ready'        => $status === 'packing',
            'can_ship'         => $status === 'ready_to_ship',
            'can_deliver'      => $status === 'shipped',
            'can_confirm'      => Order::canMemberConfirm($order),
            'auto_days'        => FulfilmentService::autoCompleteDays(),
            'courier'          => $courier,
            'tracking'         => $tracking,
            'courier_choice'   => old('courier'),
            'full_address'     => $fullAddress,
        ];
    }

    /**
     * JSON feed for one open order page.
     *
     * The 304 check runs before the order read, so an unchanged order costs
     * one revision lookup. Fragments are rendered after the session lock is
     * released; the token is primed first because the forms carry it.
     */
    private function detailFeed(int $orderId): void
    {
        $userId = (int) (auth_user()['id'] ?? 0);

        csrf_token();
        session_write_close();

        $surface = Live::orderSurface($orderId);

        try {
            $revision = Live::revision($surface);
            $etag     = Live::etag($surface, $revision, Live::scope($_GET, $userId));

            if (Live::matchesIfNoneMatch(Request::header('If-None-Match'), $etag)) {
                Live::notModified($etag);
            }

            $order = Order::find($orderId);

            if ($order === null) {
                Live::jsonError('Order not found.', 404);
            }

            Live::json($etag, $this->detailFeedPayload($revision, $etag, $this->orderContext($order)));
        } catch (Throwable $e) {
            error_log('[ecowealth] order detail live feed failed: ' . $e->getMessage());
            Live::jsonError('Could not refresh this order. Please try again.', 500);
        }
    }

    /**
     * Render the detail feed payload. Public so tests can assert the
     * fragments without going through Live::json()'s exit.
     *
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    public function detailFeedPayload(int $revision, string $etag, array $ctx): array
    {
        $order = $ctx['order'];

        return [
            'ok'        => true,
            'surface'   => Live::orderSurface((int) $order['id']),
            'revision'  => $revision,
            'etag'      => $etag,
            'fragments' => [
                'order-status' => View::capture('admin/orders/_status', ['ctx' => $ctx]),
                'order-payment' => View::capture('admin/orders/_payment', ['ctx' => $ctx]),
                'order-fulfilment' => View::capture('admin/orders/_fulfilment', ['ctx' => $ctx]),
                'order-items' => View::capture('admin/orders/_items', ['ctx' => $ctx]),
                'order-timeline' => View::capture('admin/orders/_timeline', ['ctx' => $ctx]),
                'order-member' => View::capture('admin/orders/_member', ['ctx' => $ctx]),
                'order-delivery' => View::capture('admin/orders/_delivery', ['ctx' => $ctx]),
                'order-shipment' => View::capture('admin/orders/_shipment', ['ctx' => $ctx]),
                'order-payment-summary' => View::capture('admin/orders/_payment_summary', ['ctx' => $ctx]),
                'order-cancel' => View::capture('admin/orders/_cancel', ['ctx' => $ctx]),
            ],
            'meta'      => [
                'canonical' => url('admin/orders/' . (int) $order['id']),
            ],
        ];
    }

    /**
     * POST /admin/orders/{id}/transition — verify, reject or cancel.
     *
     * One route with an explicit `to` value instead of one route per
     * action: the service decides what each target may do, so a typo in
     * the form can never reach a money path.
     *
     * @param array<string, string> $params
     */
    public function transition(array $params = []): void
    {
        $admin   = $this->admin();
        $adminId = (int) $admin['id'];
        $orderId = (int) ($params['id'] ?? 0);
        $to      = strtolower(Request::string('to'));
        $back    = 'admin/orders/' . $orderId;

        try {
            switch ($to) {
                case 'paid':
                    $result = $this->payments->verify($adminId, $orderId, [
                        'amount_received'   => Request::post('amount_received'),
                        'payment_reference' => Request::post('payment_reference'),
                        'note'              => Request::post('note'),
                    ]);
                    flash_set('success', sprintf(
                        'Payment verified — %s marked paid.',
                        money((float) $result['total'])
                    ));
                    break;

                case 'payment_failed':
                    $this->payments->reject(
                        $adminId,
                        $orderId,
                        Request::string('reason_code'),
                        Request::string('note')
                    );
                    flash_set('success', 'Proof rejected. The order is now payment failed and the member can re-upload within the correction window.');
                    break;

                case 'packing':
                    $this->fulfilment->pack($adminId, $orderId, Request::string('note'));
                    flash_set('success', 'Packing started. The order is now Packing.');
                    break;

                case 'ready_to_ship':
                    $this->fulfilment->ready($adminId, $orderId, Request::string('note'));
                    flash_set('success', 'The order is packed and ready to ship.');
                    break;

                case 'cancelled':
                    $result = $this->payments->cancelByAdmin(
                        $adminId,
                        $orderId,
                        Request::string('reason_code'),
                        Request::string('note')
                    );
                    flash_set(
                        'success',
                        'Order cancelled.'
                        . ((float) $result['refunded'] > 0
                            ? ' ' . money((float) $result['refunded']) . ' refunded to the member e-wallet.'
                            : '')
                    );
                    break;

                default:
                    flash_set('danger', 'Unknown order action.');
                    redirect($back);
            }
        } catch (PaymentException $e) {
            if ($e->reason === 'not_found') {
                View::error(404);
            }

            if ($e->fieldErrors !== []) {
                errors_set($e->fieldErrors);
                old_set([
                    'amount_received'   => (string) Request::post('amount_received', ''),
                    'payment_reference' => (string) Request::post('payment_reference', ''),
                    'note'              => (string) Request::post('note', ''),
                ]);
            }

            flash_set('danger', $e->getMessage());
            redirect($back);
        } catch (FulfilmentException $e) {
            if ($e->reason === 'not_found') {
                View::error(404);
            }

            flash_set('danger', $e->getMessage());
            redirect($back);
        } catch (Throwable $e) {
            error_log('[ecowealth] admin order transition failed: ' . $e->getMessage());
            flash_set('danger', 'The order could not be updated. Please try again.');
            redirect($back);
        }

        /* The POST redirects to a normal page load; queue the surfaces so
         * that landing page broadcasts them to every other open tab. */
        Live::publish(['admin.orders', Live::orderSurface($orderId), 'admin.products']);

        redirect($back);
    }

    /**
     * POST /admin/orders/{id}/ship — courier + tracking, stock deducted
     * (P8-T1/T2/T3).
     *
     * The courier comes from a real `<select>` plus an optional
     * "Others" free-text field. `FulfilmentService::resolveCourier()`
     * turns that pair into the single stored value; a listed courier
     * ignores the text field, and "Others" requires it.
     *
     * @param array<string, string> $params
     */
    public function ship(array $params = []): void
    {
        $admin   = $this->admin();
        $adminId = (int) $admin['id'];
        $orderId = (int) ($params['id'] ?? 0);
        $back    = 'admin/orders/' . $orderId;

        /* The raw form values, preserved for old input on any refusal. */
        $form = [
            'courier'         => Request::string('courier'),
            'courier_other'   => Request::string('courier_other'),
            'tracking_number' => Request::string('tracking_number'),
            'notes'           => Request::string('notes'),
        ];

        $courier = FulfilmentService::resolveCourier($form);

        if ($courier['errors'] !== []) {
            errors_set($courier['errors']);
            old_set($form);
            flash_set('danger', (string) reset($courier['errors']));
            redirect($back);
        }

        $input = [
            'courier'         => $courier['value'],
            'tracking_number' => $form['tracking_number'],
            'notes'           => $form['notes'],
        ];

        try {
            $result = $this->fulfilment->ship($adminId, $orderId, $input);
        } catch (FulfilmentException $e) {
            if ($e->reason === 'not_found') {
                View::error(404);
            }

            if ($e->fieldErrors !== []) {
                errors_set($e->fieldErrors);
                old_set($form);
            }

            flash_set('danger', $e->getMessage());
            redirect($back);
        } catch (Throwable $e) {
            error_log('[ecowealth] order ship failed: ' . $e->getMessage());
            flash_set('danger', 'The order could not be shipped. Please try again.');
            redirect($back);
        }

        Live::publish(['admin.orders', Live::orderSurface($orderId), 'admin.products', 'admin.dashboard']);

        flash_set('success', sprintf(
            'Order shipped via %s — tracking %s. The stock has been deducted.',
            (string) $result['courier'],
            (string) $result['tracking_number']
        ));
        redirect($back);
    }

    /**
     * POST /admin/orders/{id}/deliver — shipped → delivered with an
     * optional proof-of-delivery note (P8-T4).
     *
     * @param array<string, string> $params
     */
    public function deliver(array $params = []): void
    {
        $admin   = $this->admin();
        $adminId = (int) $admin['id'];
        $orderId = (int) ($params['id'] ?? 0);
        $back    = 'admin/orders/' . $orderId;
        $note    = Request::string('note');

        $errors = FulfilmentService::validateDelivery(['note' => $note]);

        if ($errors !== []) {
            errors_set($errors);
            old_set(['note' => $note]);
            flash_set('danger', (string) reset($errors));
            redirect($back);
        }

        try {
            $this->fulfilment->deliver($adminId, $orderId, $note);
        } catch (FulfilmentException $e) {
            if ($e->reason === 'not_found') {
                View::error(404);
            }

            flash_set('danger', $e->getMessage());
            redirect($back);
        } catch (Throwable $e) {
            error_log('[ecowealth] order deliver failed: ' . $e->getMessage());
            flash_set('danger', 'The delivery could not be recorded. Please try again.');
            redirect($back);
        }

        flash_set('success', 'Order marked delivered. The member can now confirm receipt.');
        Live::publish(['admin.orders', Live::orderSurface($orderId)]);
        redirect($back);
    }

    /**
     * GET /admin/orders/{id}/invoice — the same immutable snapshot the
     * member sees, with a back link to the order desk (P8-T5/T6).
     *
     * @param array<string, string> $params
     */
    public function invoice(array $params = []): void
    {
        $order = Order::find((int) ($params['id'] ?? 0));

        if ($order === null) {
            View::error(404);
        }

        $orderId  = (int) $order['id'];
        $document = $this->invoices->ensureForOrder($orderId);

        if ($document === null) {
            flash_set('warning', 'The invoice is available once this order has been paid.');
            redirect('admin/orders/' . $orderId);
        }

        InvoicePage::send($document, $order, url('admin/orders/' . $orderId));
    }

    /**
     * GET /admin/proofs/{id} — stream one stored proof (P7-T9).
     *
     * The file lives under public/uploads/proofs with a random name and
     * is never linked directly: this guarded route is the only way to
     * read it, so an admin session is required and no index exists.
     *
     * @param array<string, string> $params
     */
    public function proof(array $params = []): never
    {
        $proof = PaymentProof::findWithOrder((int) ($params['id'] ?? 0));

        if ($proof === null) {
            $this->proofNotFound();
        }

        $relative = str_replace('\\', '/', (string) $proof['file_path']);
        $allowed  = str_starts_with($relative, 'uploads/proofs/') && !str_contains($relative, '..');
        $absolute = $allowed ? Upload::root() . substr($relative, strlen('uploads/')) : '';

        if (!$allowed || !is_file($absolute)) {
            $this->proofNotFound();
        }

        if (!headers_sent()) {
            header('Content-Type: ' . $this->proofMime($proof, $absolute));
            header('Content-Length: ' . (string) filesize($absolute));
            header('Content-Disposition: inline; filename="' . $this->proofFilename($proof, $absolute) . '"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store, max-age=0');
            header('Referrer-Policy: no-referrer');
        }

        readfile($absolute);
        exit;
    }

    /** @return array<string, mixed> */
    private function admin(): array
    {
        $user = auth_user();

        if ($user === null || !is_admin($user)) {
            View::error(403);
        }

        return $user;
    }

    private function proofNotFound(): never
    {
        if (!headers_sent()) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
        }

        echo 'Payment proof not found.';
        exit;
    }

    /** A DB value is never trusted as a header: keep a real MIME shape. */
    private function proofMime(array $proof, string $absolute): string
    {
        $mime = strtolower(trim((string) ($proof['mime'] ?? '')));

        if (preg_match('#^[a-z0-9][a-z0-9.+-]*/[a-z0-9][a-z0-9.+-]*$#', $mime) === 1) {
            return $mime;
        }

        if (class_exists('finfo')) {
            $sniffed = (new finfo(FILEINFO_MIME_TYPE))->file($absolute);

            if (is_string($sniffed) && preg_match('#^[a-z0-9][a-z0-9.+-]*/[a-z0-9][a-z0-9.+-]*$#', strtolower($sniffed)) === 1) {
                return strtolower($sniffed);
            }
        }

        return 'application/octet-stream';
    }

    private function proofFilename(array $proof, string $absolute): string
    {
        $extension = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));
        $extension = preg_match('/^[a-z0-9]{2,5}$/', $extension) === 1 ? $extension : 'bin';

        return sprintf(
            'proof-%s-a%d.%s',
            preg_replace('/[^A-Za-z0-9-]/', '', (string) $proof['order_no']) ?: 'order',
            (int) $proof['attempt_no'],
            $extension
        );
    }
}
