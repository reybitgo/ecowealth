<?php
declare(strict_types=1);

/**
 * Genealogy — the direct-recruit pagination footer.
 *
 * @var Paginator $recruits_pager
 */
?>
                    <div class="card-body pt-2" data-live-region="genealogy-recruits-pagination">
<?= View::partial('pagination', ['pager' => $recruits_pager, 'pager_label' => 'Recruits']) ?>
                    </div>
