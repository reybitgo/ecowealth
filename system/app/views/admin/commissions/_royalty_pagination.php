<?php
declare(strict_types=1);

/**
 * Admin Royalty ledger — pagination footer (Phase 19D).
 *
 * A unique live-region name for the third tab: the Royalty feed must
 * never patch the package or repeat pagination region.
 *
 * @var Paginator $pager
 */
?>
                <div class="card-body pt-2" data-live-region="commission-royalty-pagination">
<?= View::partial('pagination', ['pager' => $pager, 'pager_label' => 'Awards']) ?>
                </div>
