<?php
declare(strict_types=1);

/* ==============================================================
 *  AdminOrderController — the order desk (plan §7 Phase 6; reference
 *  AdminController::shopOrders/shopOrder)
 *
 *  Routes:
 *      GET /admin/orders        all orders, filters + stats
 *      GET /admin/orders/{id}   one order: member, items, totals, timeline
 *
 *  Read-only in this phase: payment verification (Phase 7) and
 *  fulfilment actions (Phase 8) add their own POST routes. The admin
 *  guard is enforced by the router, never by menu visibility.
 * ============================================================== */

final class AdminOrderController
{
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

        $member = Wallet::account((int) $order['user_id']);

        View::page('admin', 'admin/orders/show', [
            'page_title'  => 'Order ' . (string) $order['order_no'],
            'current_nav' => 'admin.orders',
            'page_css'    => ['assets/css/shop.css', 'assets/css/orders.css'],
            'order'       => $order,
            'lines'       => Order::items((int) $order['id']),
            'events'      => Order::events((int) $order['id']),
            'member'      => $member,
        ]);
    }
}
