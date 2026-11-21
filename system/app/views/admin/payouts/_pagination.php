<?php
declare(strict_types=1);

/**
 * Admin payout queue — pagination (live region).
 *
 * @var Paginator $pager
 */
?>
              <div data-live-region="payout-pagination">
<?= View::partial('pagination', [
    'pager'       => $pager,
    'pager_label' => 'Payout requests',
    'form_action' => url('admin/payouts'),
]) ?>
              </div>
