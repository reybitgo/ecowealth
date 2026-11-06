<?php
declare(strict_types=1);

/* ==============================================================
 *  AdminOrderController — the order desk (plan §7 Phases 6–7;
 *  reference AdminController::shopOrders/shopOrder/orderTransition/
 *  orderProofView)
 *
 *  Routes:
 *      GET  /admin/orders                 all orders, filters + stats
 *      GET  /admin/orders/{id}            one order + the payment review pane
 *      POST /admin/orders/{id}/transition verify / reject / cancel
 *      GET  /admin/proofs/{id}            stream one proof (guarded)
 *
 *  The admin guard is enforced by the router; the service additionally
 *  refuses any review of an order the signed-in admin placed (P7-T10).
 * ============================================================== */

final class AdminOrderController
{
    private PaymentService $payments;

    public function __construct()
    {
        $this->payments = new PaymentService();
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

        $pager  = Paginator::fromRequest(Order::countAdmin($filters), 20);
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
        } catch (Throwable $e) {
            error_log('[ecowealth] admin order transition failed: ' . $e->getMessage());
            flash_set('danger', 'The order could not be updated. Please try again.');
            redirect($back);
        }

        redirect($back);
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
