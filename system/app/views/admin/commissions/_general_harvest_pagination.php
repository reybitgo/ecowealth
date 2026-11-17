<?php
declare(strict_types=1);

/**
 * Admin General Harvest ledger — pagination footer (Phase 21E-S1).
 *
 * A unique live-region name for the fifth tab: the General Harvest feed
 * must never patch the package, repeat, Royalty or Team Harvest pagination
 * region.
 *
 * @var Paginator $pager
 */
?>
                <div class="card-body pt-2" data-live-region="general-harvest-pagination">
<?= View::partial('pagination', ['pager' => $pager, 'pager_label' => 'Awards']) ?>
                </div>
