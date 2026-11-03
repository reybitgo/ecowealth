<?php
declare(strict_types=1);

/* ==============================================================
 *  ShopController — the member storefront (reference: shop)
 *
 *  Phase 1 ships the guarded shell only: the catalogue, product
 *  detail, cart badge and search arrive in Phase 2. Keeping the
 *  route live now proves the member guard and the base-path maths
 *  from a real routed page (P1-T1, P1-T5).
 * ============================================================== */

final class ShopController
{
    /** @param array<string, string> $params */
    public function index(array $params = []): void
    {
        View::page('member', 'shop/placeholder', [
            'page_title'  => 'Shop',
            'current_nav' => 'shop',
            'shop_open'   => setting_bool('shop_enabled', true),
        ]);
    }
}
