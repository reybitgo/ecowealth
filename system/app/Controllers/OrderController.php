<?php
declare(strict_types=1);

/* ==============================================================
 *  OrderController — the member's orders (plan §5.3, §7 Phase 6;
 *  reference MemberController::shopOrders/shopOrder/cancelOrder)
 *
 *  Routes:
 *      GET  /orders                 history with status/search filters
 *      GET  /orders/{id}            order page: items, totals, timeline
 *      POST /orders/{id}/cancel     unpaid orders only (P6-T11)
 *
 *  Every read is scoped by the signed-in member; another member's id
 *  resolves to the 404 page, never to a data leak (P6-T8).
 * ============================================================== */

final class OrderController
{
    private OrderService $orders;

    public function __construct()
    {
        $this->orders = new OrderService();
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

        $isAdmin = is_admin($user);

        View::page($isAdmin ? 'admin' : 'member', 'shop/order', [
            'page_title'  => 'Order ' . (string) $order['order_no'],
            'current_nav' => $isAdmin ? 'admin.storefront' : 'orders',
            'page_css'    => ['assets/css/shop.css', 'assets/css/orders.css'],
            'order'       => $order,
            'lines'       => Order::items((int) $order['id']),
            'events'      => Order::events((int) $order['id']),
            'can_cancel'  => Order::canMemberCancel($order),
        ]);
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
