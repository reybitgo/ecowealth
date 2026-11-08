<?php
declare(strict_types=1);

/**
 * Admin package list — pagination footer.
 *
 * @var Paginator $pager
 */
?>
                <div class="card-body pt-2" data-live-region="package-pagination">
<?= View::partial('pagination', ['pager' => $pager, 'pager_label' => 'Packages']) ?>
                </div>
