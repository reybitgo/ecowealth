<?php
declare(strict_types=1);

/**
 * Admin repeat-purchase ledger — pagination footer (Phase 17D).
 *
 * The paginator keeps every query parameter already on the URL, so the
 * `ledger=repeat` tab and its filters survive a page change.
 *
 * @var Paginator $pager
 */
?>
                <div class="card-body pt-2" data-live-region="repeat-pagination">
<?= View::partial('pagination', ['pager' => $pager, 'pager_label' => 'Awards']) ?>
                </div>
