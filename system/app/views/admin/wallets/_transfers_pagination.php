<?php
declare(strict_types=1);

/**
 * Admin wallet monitor — pagination for the transfers tab.
 *
 * @var Paginator $transfers_pager
 */
?>
              <div class="card-body" data-live-region="wallet-transfers-pagination">
<?= View::partial('pagination', [
    'pager'       => $transfers_pager,
    'pager_label' => 'Transfers',
    'form_action' => url('admin/wallets?tab=transfers'),
]) ?>
              </div>
