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
        $user    = $this->member();
        $userId  = (int) $user['id'];
        $filters = Order::normaliseFilters([
            'status' => Request::string('status'),
            'q'      => Request::string('q'),
            'method' => Request::string('method'),
            'from'   => Request::string('from'),
            'to'     => Request::string('to'),
        ]);

        $pager = Paginator::fromRequest(Order::countForUser($userId, $filters), 10);
        $orders = Order::listForUser($userId, $filters, $pager);

        $isAdmin = is_admin($user);

        View::page($isAdmin ? 'admin' : 'member', 'shop/orders', [
            'page_title'  => 'My orders',
            'current_nav' => $isAdmin ? 'admin.storefront' : 'orders',
            'page_css'    => ['assets/css/shop.css', 'assets/css/orders.css'],
            'orders'      => $orders,
            'pager'       => $pager,
            'filters'     => $filters,
        ]);
    }

    /** @param array<string, string> $params */
    public function show(array $params = []): void
    {
        $user  = $this->member();
        $order = Order::findForUser((int) ($params['id'] ?? 0), (int) $user['id']);

        if ($order === null) {
            View::error(404);
        }

        $isAdmin  = is_admin($user);
        $orderId  = (int) $order['id'];
        $proofs   = PaymentProof::forOrder($orderId);
        $window   = $this->payments->windowFor($order);
        $latest   = $proofs === [] ? null : $proofs[count($proofs) - 1];

        View::page($isAdmin ? 'admin' : 'member', 'shop/order', [
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
            ],
            'order'       => $order,
            'lines'       => Order::items($orderId),
            'events'      => Order::events($orderId),
            'can_cancel'  => Order::canMemberCancel($order),
            'proofs'      => $proofs,
            'latest_proof' => $latest,
            'window'      => $window,
            'shipment'    => Shipment::latestFor($orderId),
            'invoice'     => Invoice::latestFor($orderId),
            'can_confirm' => Order::canMemberConfirm($order),
            'auto_days'   => FulfilmentService::autoCompleteDays(),
        ]);
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
