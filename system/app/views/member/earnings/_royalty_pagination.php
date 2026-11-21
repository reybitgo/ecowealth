<?php
declare(strict_types=1);

/**
 * Member Royalty statement — pagination footer (Phase 19E).
 *
 * A live region of its own, like the package and repeat statements: a
 * page change never rewrites the rows of another tab. The paginator
 * keeps every query parameter already on the URL, so `ledger=royalty`
 * and its month/rank/status filters survive a page change.
 *
 * @var Paginator $pager
 */
?>
                <div class="card-body pt-2" data-live-region="earnings-royalty-pagination">
<?= View::partial('pagination', ['pager' => $pager, 'pager_label' => 'Months']) ?>
                </div>
