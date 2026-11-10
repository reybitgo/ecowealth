<?php
declare(strict_types=1);

/**
 * Payouts — pagination for the member's withdrawal history (live region).
 *
 * @var Paginator $pager
 */
?>
              <div data-live-region="payout-pagination">
<?= View::partial('pagination', [
    'pager'       => $pager,
    'pager_label' => 'Payouts',
    'form_action' => url('payouts'),
]) ?>
              </div>
