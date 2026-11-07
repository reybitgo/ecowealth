<?php
declare(strict_types=1);

/**
 * Admin wallet monitor — member directory pagination.
 *
 * @var Paginator $members_pager
 */
?>
                <div class="card-body pt-2" data-live-region="wallet-members-pagination">
<?= View::partial('pagination', ['pager' => $members_pager, 'pager_label' => 'Members']) ?>
                </div>
