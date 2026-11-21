<?php
declare(strict_types=1);

/**
 * Admin wallet monitor — top-up pagination.
 *
 * @var Paginator $topups_pager
 */
?>
                <div class="card-body pt-2" data-live-region="wallet-topups-pagination">
<?= View::partial('pagination', ['pager' => $topups_pager, 'pager_label' => 'Top-ups']) ?>
                </div>
