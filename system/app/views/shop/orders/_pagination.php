<?php
declare(strict_types=1);

/**
 * My orders — pagination footer.
 *
 * @var Paginator $pager
 */
?>
                <div class="card-body pt-2" data-live-region="member-orders-pagination">
<?= View::partial('pagination', ['pager' => $pager, 'pager_label' => 'Orders']) ?>
                </div>
