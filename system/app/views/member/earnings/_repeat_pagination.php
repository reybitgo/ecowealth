<?php
declare(strict_types=1);

/**
 * Member repeat-purchase statement — pagination footer (Phase 17E).
 *
 * The paginator keeps every query parameter already on the URL, so the
 * `ledger=repeat` tab and its filters survive a page change.
 *
 * @var Paginator $pager
 */
?>
                <div class="card-body pt-2" data-live-region="earnings-repeat-pagination">
<?= View::partial('pagination', ['pager' => $pager, 'pager_label' => 'Awards']) ?>
                </div>
