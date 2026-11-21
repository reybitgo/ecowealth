<?php
declare(strict_types=1);

/**
 * Admin activation queue — pagination footer.
 *
 * @var Paginator $pager
 */
?>
                <div class="card-body pt-2" data-live-region="activation-pagination">
<?= View::partial('pagination', ['pager' => $pager, 'pager_label' => 'Activations']) ?>
                </div>
