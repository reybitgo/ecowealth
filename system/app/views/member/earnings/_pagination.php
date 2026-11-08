<?php
declare(strict_types=1);

/**
 * Member earnings statement — pagination footer.
 *
 * @var Paginator $pager
 */
?>
                <div class="card-body pt-2" data-live-region="earnings-pagination">
<?= View::partial('pagination', ['pager' => $pager, 'pager_label' => 'Awards']) ?>
                </div>
