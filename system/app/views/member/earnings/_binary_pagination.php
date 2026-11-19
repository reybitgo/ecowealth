<?php
declare(strict_types=1);

/**
 * Member Product binary statement — pagination footer (22F, plan §10).
 *
 * @var Paginator $pager
 */
?>
                <div class="card-body pt-2" data-live-region="earnings-binary-pagination">
<?= View::partial('pagination', ['pager' => $pager, 'pager_label' => 'Awards']) ?>
                </div>
