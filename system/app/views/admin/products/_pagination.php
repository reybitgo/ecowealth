<?php
declare(strict_types=1);

/**
 * Admin product pagination region.
 *
 * The same path is used for the page and the live feed, so the
 * pagination partial's links keep pointing at /admin/products.
 *
 * @var Paginator $pager
 * @var string    $pager_label
 */

$pager_label = $pager_label ?? 'Products';
?>
                <div class="card-body pt-2" data-live-region="product-pagination">
<?= View::partial('pagination', ['pager' => $pager, 'pager_label' => $pager_label]) ?>
                </div>
