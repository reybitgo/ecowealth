<?php
declare(strict_types=1);

/**
 * Admin Team Harvest ledger — pagination footer (Phase 20E).
 *
 * A unique live-region name for the fourth tab: the Team Harvest feed must
 * never patch the package, repeat or Royalty pagination region.
 *
 * @var Paginator $pager
 */
?>
                <div class="card-body pt-2" data-live-region="team-harvest-pagination">
<?= View::partial('pagination', ['pager' => $pager, 'pager_label' => 'Awards']) ?>
                </div>
