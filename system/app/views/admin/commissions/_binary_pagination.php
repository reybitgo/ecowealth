<?php
declare(strict_types=1);

/**
 * Admin Product Binary ledger — pagination footer (Phase 22E).
 *
 * The paginator keeps every query parameter already on the URL, so the
 * `ledger=binary` tab and its filters survive a page change.
 *
 * @var Paginator $pager
 */
?>
                <div class="card-body pt-2" data-live-region="binary-pagination">
<?= View::partial('pagination', ['pager' => $pager, 'pager_label' => 'Awards']) ?>
                </div>
