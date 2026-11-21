<?php
declare(strict_types=1);

/**
 * Admin order desk pagination region.
 *
 * The same URL serves the page and its feed, so the pagination partial's
 * links keep pointing at /admin/orders rather than a feed path.
 *
 * @var Paginator $pager
 * @var string    $pager_label
 */

$pager_label = $pager_label ?? 'Orders';
?>
                <div class="card-body pt-2" data-live-region="order-pagination">
<?= View::partial('pagination', ['pager' => $pager, 'pager_label' => $pager_label]) ?>
                </div>
