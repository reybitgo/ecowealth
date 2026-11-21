<?php
declare(strict_types=1);

/**
 * Member Team Harvest statement — pagination footer (Phase 20F).
 *
 * A live region of its own, like the package, repeat and Royalty
 * statements: a page change never rewrites the matrix above it. The
 * paginator keeps every query parameter already on the URL, so
 * `ledger=team_harvest` and its level/status/date filters survive a page
 * change.
 *
 * @var Paginator $pager
 */
?>
                <div class="card-body pt-2" data-live-region="earnings-team-pagination">
<?= View::partial('pagination', ['pager' => $pager, 'pager_label' => 'Levels']) ?>
                </div>
