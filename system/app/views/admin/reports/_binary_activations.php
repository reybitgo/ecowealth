<?php
declare(strict_types=1);

/**
 * Reports — Package binary by activation card (plan 23D-S2).
 *
 * What each activation placed and paid in the selected window, newest
 * first. Points and money are shown side by side, never added together
 * (plan Rule 4).
 *
 * @var list<array{activation_id:int, user_id:int, username:string, package:string, points:int, pairs:int, amount:float, status:string, processed_at:?string}> $package_binary_by_activation
 * @var array{from:string, to:string, days:int, label:string} $range
 */

$package_binary_by_activation = $package_binary_by_activation ?? [];
$range = $range ?? ['from' => '', 'to' => '', 'days' => 0, 'label' => ''];
?>
              <div class="card h-100 report-binary-activations-card" data-live-region="report-binary-activations">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <div>
                    <h5 class="mb-1">Package binary — by activation</h5>
                    <p class="text-body-secondary small mb-0">
                      Each processed activation: points placed, pairs paid and money credited — <?= e($range['label']) ?>
                    </p>
                  </div>
                  <a href="<?= e(url('admin/commissions?ledger=binary&source=activation')) ?>" class="btn btn-sm btn-label-primary">Ledger</a>
                </div>
                <div class="card-body">
<?php if ($package_binary_by_activation === []): ?>
<?php
    echo View::partial('empty_state', [
        'compact' => true,
        'icon'    => 'bx bx-package',
        'title'   => 'No processed package activations in this window',
        'text'    => 'Each approved activation appears here once it places points.',
    ]);
?>
<?php else: ?>
                  <table class="table table-sm align-middle mb-0 report-table">
                    <thead>
                      <tr>
                        <th scope="col" class="text-nowrap">Activation</th>
                        <th scope="col" class="text-nowrap">Member</th>
                        <th scope="col" class="text-nowrap">Package</th>
                        <th scope="col" class="text-end">Points placed</th>
                        <th scope="col" class="text-end">Pairs paid</th>
                        <th scope="col" class="text-end">Amount</th>
                        <th scope="col" class="text-nowrap">Status</th>
                        <th scope="col" class="text-nowrap">Processed</th>
                      </tr>
                    </thead>
                    <tbody>
<?php foreach ($package_binary_by_activation as $row): ?>
                      <tr>
                        <td class="text-nowrap small">#<?= (int) $row['activation_id'] ?></td>
                        <td class="text-nowrap small">@<?= e((string) $row['username']) ?></td>
                        <td class="text-nowrap small"><?= e((string) $row['package']) ?></td>
                        <td class="text-end text-nowrap small fw-semibold"><?= e(number_format((int) $row['points'], 0, '.', '')) ?> pts</td>
                        <td class="text-end text-nowrap small"><?= (int) $row['pairs'] ?></td>
                        <td class="text-end text-nowrap small fw-semibold"><?= e(money((float) $row['amount'])) ?></td>
                        <td class="text-nowrap small"><?= e(ucfirst((string) $row['status'])) ?></td>
                        <td class="text-nowrap small">
                          <?= $row['processed_at'] !== null ? e(date('M j, Y g:i A', (int) strtotime((string) $row['processed_at']))) : '—' ?>
                        </td>
                      </tr>
<?php endforeach; ?>
                    </tbody>
                  </table>
<?php endif; ?>
                </div>
                <div class="card-footer small text-body-secondary">
                  Export this block with <code>Export CSV → Package binary bonus</code>.
                </div>
              </div>
