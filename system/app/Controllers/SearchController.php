<?php
declare(strict_types=1);

/* ==============================================================
 *  SearchController — one search across every destination the
 *  navbar field promises (UX-26)
 *
 *  Route:
 *      GET /search?q=…          grouped results
 *
 *  Admins search products, members, orders and registration codes;
 *  members search the catalogue and their own orders. Each group is
 *  a five-row preview of the same `q` the destination's own list
 *  accepts, so the full, filterable lists are reused, never copied
 *  here. Every preview links to its destination with the query kept.
 *
 *  The page is a snapshot, not a live surface: a result only means
 *  the query matched when it ran.
 * ============================================================== */

final class SearchController
{
    /** Rows previewed per destination before the "View all" link. */
    public const PER_GROUP = 5;

    /** @param array<string, string> $params */
    public function index(array $params = []): void
    {
        $query = trim(Request::string('q'));

        View::page(is_admin() ? 'admin' : 'member', 'search/index', $this->results($query) + [
            'page_title'       => $query === '' ? 'Search' : 'Search — ' . $query,
            'nav_search_value' => $query,
        ]);
    }

    /**
     * The groups the results page renders.
     *
     * Public so a test can assert the same data the page shows without
     * going through a request. An empty query returns no groups (the
     * page then explains what can be searched), and a group with no
     * match is left out so the page never prints empty cards.
     *
     * @return array{query:string, groups:list<array<string, mixed>>, total:int, scope:string, hint:string}
     */
    public function results(string $query): array
    {
        $query = trim($query);
        $admin = is_admin();
        $groups = [];

        if ($query !== '') {
            $groups[] = $this->productGroup($query, $admin);

            if ($admin) {
                $groups[] = $this->memberGroup($query);
                $groups[] = $this->orderGroup($query, true);
                $groups[] = $this->codeGroup($query);
            } else {
                $groups[] = $this->orderGroup($query, false);
            }

            $groups = array_values(array_filter(
                $groups,
                static fn (array $group): bool => (int) $group['total'] > 0
            ));
        }

        return [
            'query'  => $query,
            'groups' => $groups,
            'total'  => array_sum(array_map(
                static fn (array $group): int => (int) $group['total'],
                $groups
            )),
            'scope'  => $admin
                ? 'products, members, orders and registration codes'
                : 'products and your own orders',
            'hint'   => $admin
                ? 'Type a product name or SKU, a member, an order number or a registration code in the search box above.'
                : 'Type a product name or SKU, or one of your order numbers, in the search box above.',
        ];
    }

    /**
     * Catalogue matches.
     *
     * Admins see every product and open its editor; members see the
     * active catalogue and open the product page. Both previews reuse
     * the list the catalogue page renders.
     *
     * @return array<string, mixed>
     */
    private function productGroup(string $query, bool $admin): array
    {
        $filters = ['q' => $query, 'status' => '', 'unilevel' => '', 'binary' => ''];
        $pager   = new Paginator(
            $admin ? Product::countAdmin($filters) : Product::countCatalog($filters),
            self::PER_GROUP,
            1
        );
        $rows  = $pager->total === 0
            ? []
            : ($admin ? Product::adminList($filters, $pager) : Product::catalogList($filters, $pager));
        $items = [];

        foreach ($rows as $row) {
            $sku = trim((string) ($row['sku'] ?? ''));

            $items[] = [
                'title'    => (string) $row['name'],
                'subtitle' => $sku === '' ? '' : 'SKU ' . $sku,
                'note'     => money((float) $row['price']),
                'badge'    => $admin ? (string) $row['status'] : '',
                'url'      => $admin
                    ? url('admin/products/' . (int) $row['id'] . '/edit')
                    : url('shop/' . (int) $row['id']),
            ];
        }

        return $this->group(
            'products',
            'Products',
            'bx-package',
            $pager->total,
            $items,
            ($admin ? 'admin/products' : 'shop') . '?q=' . rawurlencode($query),
            'View all products'
        );
    }

    /**
     * Member-directory matches (admin only).
     *
     * @return array<string, mixed>
     */
    private function memberGroup(string $query): array
    {
        $filters = Member::normaliseFilters(['q' => $query]);
        $pager   = new Paginator(Member::countAdmin($filters), self::PER_GROUP, 1);
        $rows    = $pager->total === 0 ? [] : Member::adminList($filters, $pager);
        $items   = [];

        foreach ($rows as $row) {
            $name = trim((string) ($row['full_name'] ?? ''));

            $items[] = [
                'title'    => '@' . (string) $row['username'],
                'subtitle' => $name === '' ? (string) $row['email'] : $name,
                'note'     => trim((string) ($row['package_name'] ?? '')),
                'badge'    => (string) $row['member_status'],
                'url'      => url('admin/members/' . (int) $row['id']),
            ];
        }

        return $this->group(
            'members',
            'Members',
            'bx-group',
            $pager->total,
            $items,
            'admin/members?q=' . rawurlencode($query),
            'View all members'
        );
    }

    /**
     * Order matches — every order for an admin, only the signed-in
     * member's own orders otherwise. The order number is what the
     * list search already matches, so the same query works there.
     *
     * @return array<string, mixed>
     */
    private function orderGroup(string $query, bool $admin): array
    {
        $filters = Order::normaliseFilters(['q' => $query]);
        $me      = auth_user();
        $userId  = (int) ($me['id'] ?? 0);
        $total   = $admin
            ? Order::countAdmin($filters)
            : Order::countForUser($userId, $filters);
        $pager = new Paginator($total, self::PER_GROUP, 1);

        if ($total === 0) {
            $rows = [];
        } else {
            $rows = $admin
                ? Order::adminList($filters, $pager)
                : Order::listForUser($userId, $filters, $pager);
        }

        $items = [];

        foreach ($rows as $row) {
            $count = (int) ($row['item_count'] ?? 0);

            $items[] = [
                'title'    => (string) $row['order_no'],
                'subtitle' => $admin
                    ? '@' . (string) $row['username'] . ' · ' . $count . ' ' . ($count === 1 ? 'item' : 'items')
                    : $count . ' ' . ($count === 1 ? 'item' : 'items'),
                'note'     => money((float) $row['total']),
                'badge'    => (string) $row['status'],
                'url'      => url(($admin ? 'admin/orders/' : 'orders/') . (int) $row['id']),
            ];
        }

        return $this->group(
            'orders',
            'Orders',
            'bx-receipt',
            $total,
            $items,
            ($admin ? 'admin/orders' : 'orders') . '?q=' . rawurlencode($query),
            'View all orders'
        );
    }

    /**
     * Registration-code matches (admin only).
     *
     * A code has no detail page, so a row opens its own code list:
     * the destination's `q` filter narrows the table to that code.
     *
     * @return array<string, mixed>
     */
    private function codeGroup(string $query): array
    {
        $filters = RegCode::normaliseFilters(['q' => $query]);
        $pager   = new Paginator(RegCode::countAdmin($filters), self::PER_GROUP, 1);
        $rows    = $pager->total === 0 ? [] : RegCode::adminList($filters, $pager);
        $items   = [];

        foreach ($rows as $row) {
            $items[] = [
                'title'    => (string) $row['code'],
                'subtitle' => (string) $row['package_name'],
                'note'     => money((float) $row['price']),
                'badge'    => (string) $row['status'],
                'url'      => url('admin/codes?q=' . rawurlencode((string) $row['code'])),
            ];
        }

        return $this->group(
            'codes',
            'Registration codes',
            'bx-key',
            $pager->total,
            $items,
            'admin/codes?q=' . rawurlencode($query),
            'View all codes'
        );
    }

    /**
     * One destination's card data.
     *
     * @param list<array{title:string, subtitle:string, note:string, badge:string, url:string}> $rows
     * @return array<string, mixed>
     */
    private function group(
        string $key,
        string $label,
        string $icon,
        int $total,
        array $rows,
        string $allPath,
        string $allLabel
    ): array {
        return [
            'key'       => $key,
            'label'     => $label,
            'icon'      => $icon,
            'total'     => $total,
            'rows'      => $rows,
            'all_url'   => url($allPath),
            'all_label' => $allLabel,
        ];
    }
}
