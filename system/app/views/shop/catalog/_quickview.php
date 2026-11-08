<?php
declare(strict_types=1);

/**
 * Storefront catalogue — quick-view payload.
 *
 * The JSON is a region of its own so a background refresh can update prices,
 * stock labels and descriptions without replacing the grid's modal element.
 * The delegated click handler reads it afresh on every open.
 *
 * @var list<array<string, mixed>> $products
 * @var array<int, int>            $available
 */

$quickViewData = [];

foreach ($products as $product) {
    if (empty($product['description'])) {
        continue;
    }

    $quickViewData[(int) $product['id']] = [
        'name'        => (string) $product['name'],
        'description' => (string) $product['description'],
        'price'       => money((float) $product['price']),
        'stock'       => StockService::label((int) ($available[(int) $product['id']] ?? 0))['label'],
        'image'       => UploadService::url($product['image_url'] ?? null),
        'url'         => url('shop/' . (int) $product['id']),
    ];
}
?>
              <div data-live-region="shop-catalog-quickview">
<?php if ($quickViewData !== []): ?>
                <script type="application/json" id="productQuickViewData"><?= json_encode($quickViewData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>
              </div>
