<?php
declare(strict_types=1);

/**
 * Send funds — pagination for the recent-transfer list (live region).
 *
 * The select keeps the current filters and resets to page 1; the feed
 * replaces this region with the list so both always agree.
 *
 * @var Paginator $pager
 */
?>
              <div data-live-region="member-transfer-pagination">
<?= View::partial('pagination', [
    'pager'        => $pager,
    'pager_label'  => 'Transfers',
    'form_action'  => url('wallet/transfer'),
]) ?>
              </div>
