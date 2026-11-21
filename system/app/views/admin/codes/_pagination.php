<?php
declare(strict_types=1);

/**
 * Admin registration codes — pagination footer.
 *
 * @var Paginator $pager
 */
?>
                <div class="card-body pt-2" data-live-region="code-pagination">
<?= View::partial('pagination', ['pager' => $pager, 'pager_label' => 'Codes']) ?>
                </div>
