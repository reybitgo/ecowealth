<?php
declare(strict_types=1);

/* ==============================================================
 *  ShopController — the member storefront (plan §7 Phase 2;
 *  reference: MemberController::shop + views/member/shop.php)
 *
 *  Phase 2 covers browsing: the catalogue, search, pagination and
 *  the product detail page. Cart and checkout are Phases 4–6.
 *
 *  Prices are display-only here; checkout re-reads them (Phase 6).
 *
 *  Both pages are live surfaces: `shop.catalog` for the grid and
 *  `shop.product:{id}` for one product. The detail surface is per
 *  product, so a stock change on one product never refreshes another
 *  product's open page.
 * ============================================================== */

final class ShopController
{
    private const PER_PAGE = 12;

    /** @param array<string, string> $params */
    public function index(array $params = []): void
    {
        /* The same URL doubles as the JSON feed: X-Live: 1 returns the
         * regions instead of the layout, so pagination links and the search
         * form keep pointing at /shop. */
        if (Live::isFeedRequest()) {
            $this->catalogFeed();
            return;
        }

        $isAdmin = is_admin();

        View::page($isAdmin ? 'admin' : 'member', 'shop/catalog', $this->catalogContext() + [
            'page_title'             => 'Shop',
            'current_nav'            => $isAdmin ? 'admin.storefront' : 'shop',
            'nav_search_placeholder' => 'Search the shop…',
            'nav_search_url'         => url('shop'),
            'page_css'               => ['assets/css/shop.css'],
            'live'                   => Live::meta(
                'shop.catalog',
                $this->catalogScope((int) (auth_user()['id'] ?? 0))
            ),
        ]);
    }

    /**
     * ETag scope for the catalogue.
     *
     * The shop-open setting is part of the scope: a tab left open when an
     * administrator closes (or opens) the shop revalidates on its next poll
     * instead of keeping the old closed/open state.
     */
    private function catalogScope(int $userId): string
    {
        return Live::scope($_GET, $userId) . '|shop:' . (setting_bool('shop_enabled', true) ? '1' : '0');
    }

    /**
     * Everything the catalogue renders, built once for the page and feed.
     *
     * @return array<string, mixed>
     */
    public function catalogContext(): array
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

        return [
            'products'      => $products,
            'available'     => $available,
            'pager'         => $pager,
            'filters'       => $filters,
            'shop_open'     => $shopOpen,
            'bonus_totals'  => $this->bonusTotals($products),
        ];
    }

    /**
     * The repeat-purchase badge total for a page of products, in **one**
     * batched query (17E; plan §9 Rule 3).
     *
     * A product contributes nothing when the global stream is off, when
     * its own switch is off, or when its ladder adds up to 0% — the badge
     * says "up to", so it must never describe a ladder that cannot pay.
     *
     * @param list<array<string, mixed>> $products
     * @return array<int, float> product id => ladder total percent
     */
    private function bonusTotals(array $products): array
    {
        if ($products === [] || !UnilevelService::enabled()) {
            return [];
        }

        $enabled = [];

        foreach ($products as $product) {
            if ((int) ($product['unilevel_enabled'] ?? 0) === 1) {
                $enabled[] = (int) $product['id'];
            }
        }

        if ($enabled === []) {
            return [];
        }

        return array_filter(
            ProductUnilevel::totalsMap($enabled),
            static fn (float $total): bool => $total > 0
        );
    }

    /**
     * JSON feed for the open catalogue.
     *
     * The 304 check runs before catalogContext(), so an unchanged surface
     * costs one revision lookup and no list/stock queries. The cards carry
     * the add-to-cart forms, so the CSRF token is primed before the session
     * lock is released.
     */
    private function catalogFeed(): void
    {
        $userId = (int) (auth_user()['id'] ?? 0);

        csrf_token();
        session_write_close();

        $surface = 'shop.catalog';

        try {
            $revision = Live::revision($surface);
            $etag     = Live::etag($surface, $revision, $this->catalogScope($userId));

            if (Live::matchesIfNoneMatch(Request::header('If-None-Match'), $etag)) {
                Live::notModified($etag);
            }

            Live::json($etag, $this->feedPayload($revision, $etag, $this->catalogContext()));
        } catch (Throwable $e) {
            error_log('[ecowealth] shop catalogue feed failed: ' . $e->getMessage());
            Live::jsonError('Could not refresh the shop. Please try again.', 500);
        }
    }

    /**
     * Render the catalogue payload. Public so tests can assert the
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
            'surface'   => 'shop.catalog',
            'revision'  => $revision,
            'etag'      => $etag,
            'fragments' => [
                'shop-catalog-heading' => View::capture('shop/catalog/_heading', [
                    'pager'   => $pager,
                    'filters' => $data['filters'],
                ]),
                'shop-catalog-results' => View::capture('shop/catalog/_results', [
                    'products'     => $data['products'],
                    'available'    => $data['available'],
                    'pager'        => $pager,
                    'filters'      => $data['filters'],
                    'shop_open'    => $data['shop_open'],
                    'bonus_totals' => $data['bonus_totals'] ?? [],
                ]),
                'shop-catalog-quickview' => View::capture('shop/catalog/_quickview', [
                    'products'  => $data['products'],
                    'available' => $data['available'],
                ]),
            ],
            'meta'      => [
                'page'      => $pager->page,
                'pages'     => $pager->pages,
                'total'     => $pager->total,
                'per_page'  => $pager->perPage,
                'canonical' => $this->canonicalCatalogUrl($pager),
            ],
        ];
    }

    /** The catalogue URL with an out-of-range page clamped. */
    private function canonicalCatalogUrl(Paginator $pager): string
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

        return url('shop' . ($qs !== '' ? '?' . $qs : ''));
    }

    /** @param array<string, string> $params */
    public function show(array $params = []): void
    {
        $productId = (int) ($params['id'] ?? 0);

        if (Live::isFeedRequest()) {
            $this->productFeed($productId);
            return;
        }

        $product = Product::find($productId);

        /* Inactive products are hidden from the storefront entirely — a
         * direct URL resolves to the 404 page (P2-T5). */
        if ($product === null || $product['status'] !== 'active') {
            View::error(404);
        }

        $isAdmin = is_admin();

        View::page($isAdmin ? 'admin' : 'member', 'shop/product', $this->productContext($product) + [
            'page_title'             => (string) $product['name'],
            'current_nav'            => $isAdmin ? 'admin.storefront' : 'shop',
            'nav_search_placeholder' => 'Search the shop…',
            'nav_search_url'         => url('shop'),
            'page_css'               => ['assets/css/shop.css'],
            'page_js'                => ['assets/js/admin-live.js'],
            'live'                   => Live::meta(
                Live::shopProductSurface($productId),
                $this->productScope((int) (auth_user()['id'] ?? 0))
            ),
        ]);
    }

    /**
     * ETag scope for one product page.
     *
     * The shop-open setting is part of the scope for the same reason as the
     * catalogue: closing the shop must reach an already-open product page on
     * its next poll without a product write.
     */
    private function productScope(int $userId): string
    {
        return Live::scope($_GET, $userId) . '|shop:' . (setting_bool('shop_enabled', true) ? '1' : '0');
    }

    /**
     * Everything one product page renders, built once for the page and its
     * feed. Public so the view tests can assert the real screen data.
     *
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    public function productContext(array $product): array
    {
        $available = StockService::available((int) $product['id']);

        return [
            'product'   => $product,
            'available' => $available,
            'stock'     => StockService::label($available),
            'shop_open' => setting_bool('shop_enabled', true),
            'bonus'     => $this->productBonus($product),
        ];
    }

    /**
     * The repeat-purchase ladder as this page may show it (17E, §14.10).
     *
     * `quoteLine()` is the 17C preview API: it prices the configured
     * ladder on the product's current base and never evaluates a chain
     * or an Active flag, which is exactly why every sentence around it
     * says "up to" (plan §9 Rule 1). The panel and the badge are
     * absent when the global stream is off, the product's switch is
     * off, or the ladder adds up to 0% — a promise that cannot pay is
     * never rendered. The quote carries the monthly-minimum facts as
     * well, so the member panel can state the Team chain rule in the
     * same voice as the Active panel.
     *
     * @param array<string, mixed> $product
     * @return array<string, mixed>|null
     */
    private function productBonus(array $product): ?array
    {
        if (!UnilevelService::enabled() || (int) ($product['unilevel_enabled'] ?? 0) !== 1) {
            return null;
        }

        $quote       = (new UnilevelService())->quoteLine((int) $product['id'], 1);
        $totalPercent = 0.0;
        $paying      = 0;

        foreach ($quote['levels'] as $level) {
            $totalPercent += (float) $level['rate'];

            if ((float) $level['rate'] > 0) {
                $paying++;
            }
        }

        if ($totalPercent <= 0 || (float) $quote['total'] <= 0) {
            return null;
        }

        $quote['total_percent']    = round($totalPercent, 3);
        $quote['paying_levels']    = $paying;
        $quote['requires_active']  = setting_bool('unilevel_requires_active_upline', true);
        $quote['active_threshold'] = UnilevelCycle::threshold();

        return $quote;
    }

    /**
     * JSON feed for one open product page.
     *
     * The 304 check runs before the product read, so an unchanged product
     * costs one revision lookup. The add-to-cart form carries a CSRF field,
     * so the token is primed before the session lock is released.
     */
    private function productFeed(int $productId): void
    {
        $userId = (int) (auth_user()['id'] ?? 0);

        csrf_token();
        session_write_close();

        $surface = Live::shopProductSurface($productId);

        try {
            $revision = Live::revision($surface);
            $etag     = Live::etag($surface, $revision, $this->productScope($userId));

            if (Live::matchesIfNoneMatch(Request::header('If-None-Match'), $etag)) {
                Live::notModified($etag);
            }

            $product = Product::find($productId);

            if ($product === null || $product['status'] !== 'active') {
                Live::json($etag, $this->unavailablePayload($revision, $etag, $productId));
                return;
            }

            Live::json($etag, $this->productFeedPayload($revision, $etag, $this->productContext($product)));
        } catch (Throwable $e) {
            error_log('[ecowealth] shop product live feed failed: ' . $e->getMessage());
            Live::jsonError('Could not refresh this product. Please try again.', 500);
        }
    }

    /**
     * Render the product payload. Public so tests can assert the fragments
     * without going through Live::json()'s exit.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function productFeedPayload(int $revision, string $etag, array $data): array
    {
        $product = $data['product'];

        return [
            'ok'        => true,
            'surface'   => Live::shopProductSurface((int) $product['id']),
            'revision'  => $revision,
            'etag'      => $etag,
            'fragments' => [
                'shop-product-heading' => View::capture('shop/product/_heading', [
                    'product' => $product,
                ]),
                'shop-product-notice' => View::capture('shop/product/_notice', [
                    'shop_open' => $data['shop_open'],
                ]),
                'shop-product-card' => View::capture('shop/product/_card', [
                    'product'   => $product,
                    'available' => $data['available'],
                    'stock'     => $data['stock'],
                    'shop_open' => $data['shop_open'],
                    'bonus'     => $data['bonus'] ?? null,
                ]),
            ],
            'meta'      => [
                'canonical' => $this->canonicalProductUrl((int) $product['id']),
            ],
        ];
    }

    /**
     * The payload for a product that disappeared or was deactivated while
     * its page was open. The surface stays alive and shows an "unavailable"
     * card instead of retrying a JSON 404 forever.
     *
     * Public so tests can assert the fragments without going through
     * Live::json()'s exit.
     *
     * @return array<string, mixed>
     */
    public function unavailablePayload(int $revision, string $etag, int $productId): array
    {
        return [
            'ok'        => true,
            'surface'   => Live::shopProductSurface($productId),
            'revision'  => $revision,
            'etag'      => $etag,
            'fragments' => [
                'shop-product-heading' => View::capture('shop/product/_heading', [
                    'product' => null,
                ]),
                'shop-product-notice' => View::capture('shop/product/_notice', [
                    'shop_open' => true,
                ]),
                'shop-product-card' => View::capture('shop/product/_unavailable'),
            ],
            'meta'      => [
                'canonical' => $this->canonicalProductUrl($productId),
            ],
        ];
    }

    /** The product URL (keeps any extra query keys, like the other feeds). */
    private function canonicalProductUrl(int $productId): string
    {
        $query = [];

        foreach ($_GET as $key => $value) {
            if (is_scalar($value) && $key !== 'XTransformPort') {
                $query[(string) $key] = (string) $value;
            }
        }

        $qs = http_build_query($query);

        return url('shop/' . $productId . ($qs !== '' ? '?' . $qs : ''));
    }
}
