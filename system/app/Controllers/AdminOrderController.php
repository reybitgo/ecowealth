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
        $filters = Order::normaliseFilters([
            'status' => Request::string('status'),
            'q'      => Request::string('q'),
            'method' => Request::string('method'),
            'from'   => Request::string('from'),
            'to'     => Request::string('to'),
        ]);

        $pager  = Paginator::fromTableRequest(Order::countAdmin($filters));
        $orders = Order::adminList($filters, $pager);

        View::page('admin', 'admin/orders/index', [
            'page_title'  => 'Orders',
            'current_nav' => 'admin.orders',
            'page_css'    => ['assets/css/shop.css', 'assets/css/orders.css'],
            'orders'      => $orders,
            'pager'       => $pager,
            'filters'     => $filters,
            'stats'       => Order::stats(),
        ]);
    }

    /** @param array<string, string> $params */
    public function show(array $params = []): void
    {
        $order = Order::find((int) ($params['id'] ?? 0));

        if ($order === null) {
            View::error(404);
        }

        $admin    = auth_user();
        $adminId  = $admin === null ? 0 : (int) $admin['id'];
        $orderId  = (int) $order['id'];
        $member   = Wallet::account((int) $order['user_id']);
        $proofs   = PaymentProof::forOrder($orderId);
        $latest   = $proofs === [] ? null : $proofs[count($proofs) - 1];
        $isSelf   = $adminId > 0 && (int) $order['user_id'] === $adminId;

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
            ],
            'order'          => $order,
            'lines'          => Order::items($orderId),
            'events'         => Order::events($orderId),
            'member'         => $member,
            'proofs'         => $proofs,
            'latest_proof'   => $latest,
            'window'         => $this->payments->windowFor($order),
            'is_self'        => $isSelf,
            'can_review'     => !$isSelf
                && (string) $order['status'] === 'payment_review'
                && $latest !== null
                && (string) $latest['status'] === 'pending',
            'can_cancel'     => !$isSelf && Order::canAdminCancel($order),
            'refund_on_cancel' => !$isSelf && Order::isRefundable($order),
            'reject_reasons' => PaymentService::REJECT_REASONS,
            'cancel_reasons' => PaymentService::CANCEL_REASONS,
            'shipment'       => Shipment::latestFor($orderId),
            'invoice'        => Invoice::latestFor($orderId),
            'can_pack'       => (string) $order['status'] === 'paid',
            'can_ready'      => (string) $order['status'] === 'packing',
            'can_ship'       => (string) $order['status'] === 'ready_to_ship',
            'can_deliver'    => (string) $order['status'] === 'shipped',
            'can_confirm'    => Order::canMemberConfirm($order),
            'auto_days'      => FulfilmentService::autoCompleteDays(),
        ]);
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
