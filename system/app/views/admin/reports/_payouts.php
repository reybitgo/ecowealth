<?php
declare(strict_types=1);

/**
 * Reports — the payout register (withdrawal requests by status/method).
 *
 * The full request register is the CSV export; this card is its summary:
 * what is held right now, what completed, and how the window splits by
 * method.
 *
 * @var array{
 *   by_status:list<array{status:string,count:int,amount:float,fees:float}>,
 *   by_method:list<array{method:string,count:int,amount:float}>,
 *   held:float, held_requests:int, completed_amount:float, completed_fees:float
 * } $payouts
 * @var array{from:string, to:string, days:int, label:string} $range
 */

$payouts = array_merge(
    [
        'by_status'        => [],
        'by_method'        => [],
        'held'             => 0.0,
        'held_requests'    => 0,
        'completed_amount' => 0.0,
        'completed_fees'   => 0.0,
    ],
    $payouts ?? []
);
$range = $range ?? ['label' => ''];
?>
              <div class="card h-100 report-payouts-card" data-live-region="report-payouts">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <div>
                    <h5 class="mb-1">Payout register</h5>
                    <p class="text-body-secondary small mb-0">
                      Held now: <strong><?= e(money($payouts['held'])) ?></strong>
                      across <?= (int) $payouts['held_requests'] ?> request<?= (int) $payouts['held_requests'] === 1 ? '' : 's' ?>
                    </p>
                  </div>
                  <a href="<?= e(url('admin/payouts')) ?>" class="btn btn-sm btn-label-primary">Queue</a>
                </div>
                <div class="table-responsive">
                  <table class="table table-sm align-middle mb-0 report-table">
                    <thead>
                      <tr>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end">Requests</th>
                        <th scope="col" class="text-end">Amount</th>
                        <th scope="col" class="text-end">Fees</th>
                      </tr>
                    </thead>
                    <tbody>
<?php foreach ($payouts['by_status'] as $row): ?>
                      <tr>
                        <td><?= status_badge((string) $row['status']) ?></td>
                        <td class="text-end"><?= (int) $row['count'] ?></td>
                        <td class="text-end"><?= e(money($row['amount'])) ?></td>
                        <td class="text-end"><?= e(money($row['fees'])) ?></td>
                      </tr>
<?php endforeach; ?>
<?php if ($payouts['by_status'] === []): ?>
                      <tr><td colspan="4" class="text-body-secondary">No withdrawal requests in this window.</td></tr>
<?php endif; ?>
                    </tbody>
                  </table>
                </div>
                <div class="card-body border-top">
                  <p class="text-body-secondary small mb-2">By method</p>
<?php if ($payouts['by_method'] === []): ?>
                  <p class="text-body-secondary small mb-0">Nothing requested in this window.</p>
<?php else: ?>
                  <ul class="list-unstyled mb-0 report-method-list">
<?php foreach ($payouts['by_method'] as $row): ?>
                    <li class="d-flex align-items-center justify-content-between gap-2 py-1">
                      <span>
                        <span class="badge bg-label-secondary me-2"><?= e(strtoupper(str_replace('_', ' ', (string) $row['method']))) ?></span>
                        <?= (int) $row['count'] ?> request<?= (int) $row['count'] === 1 ? '' : 's' ?>
                      </span>
                      <span class="fw-semibold"><?= e(money($row['amount'])) ?></span>
                    </li>
<?php endforeach; ?>
                  </ul>
<?php endif; ?>
                </div>
                <div class="card-footer small text-body-secondary">
                  Completed all time <?= e(money($payouts['completed_amount'])) ?>
                  · service fees <?= e(money($payouts['completed_fees'])) ?>
                  · the held amount is refunded exactly once on rejection.
                </div>
              </div>
