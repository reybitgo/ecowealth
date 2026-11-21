<?php
declare(strict_types=1);

/**
 * Admin member directory — pagination footer.
 *
 * @var Paginator $pager
 */
?>
                <div class="card-body pt-2" data-live-region="member-pagination">
<?= View::partial('pagination', ['pager' => $pager, 'pager_label' => 'Members']) ?>
                </div>
