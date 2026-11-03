<?php
declare(strict_types=1);

/* ==============================================================
 *  ShopController — the member storefront (plan §7 Phase 2;
 *  reference: MemberController::shop + views/member/shop.php)
 *
 *  Phase 2 covers browsing: the catalogue, search, pagination and
 *  the product detail page. Cart and checkout are Phases 4–6, so
 *  the cards link to the detail page instead of posting to a cart.
 *
 *  Prices are display-only here; checkout re-reads them (Phase 6).
 * ============================================================== */

final class ShopController
{
    private const PER_PAGE = 12;

    /** @param array<string, string> $params */
    public function index(array $params = []): void
    {
        $shopOpen = setting_bool('shop_enabled', true);
        $filters  = ['q' => Request::string('q')];
        $products = [];
        $available = [];
        $total     = 0;

        if ($shopOpen) {
            $total    = Product::countCatalog($filters);
            $pager    = Paginator::fromRequest($total, self::PER_PAGE);
            $products = Product::catalogList($filters, $pager);
            $available = StockService::availableMap(array_map(
                static fn (array $row): int => (int) $row['id'],
                $products
            ));
        } else {
            $pager = new Paginator(0, self::PER_PAGE, 1);
        }

        View::page('member', 'shop/catalog', [
            'page_title'  => 'Shop',
            'current_nav' => 'shop',
            'products'    => $products,
            'available'   => $available,
            'pager'       => $pager,
            'filters'     => $filters,
            'shop_open'   => $shopOpen,
        ]);
    }

    /** @param array<string, string> $params */
    public function show(array $params = []): void
    {
        $product = Product::find((int) ($params['id'] ?? 0));

        /* Inactive products are hidden from the storefront entirely — a
         * direct URL resolves to the 404 page (P2-T5). */
        if ($product === null || $product['status'] !== 'active') {
            View::error(404);
        }

        $available = StockService::available((int) $product['id']);

        View::page('member', 'shop/product', [
            'page_title'  => (string) $product['name'],
            'current_nav' => 'shop',
            'product'     => $product,
            'available'   => $available,
            'stock'       => StockService::label($available),
            'shop_open'   => setting_bool('shop_enabled', true),
        ]);
    }
}
