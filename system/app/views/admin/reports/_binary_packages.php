<?php
declare(strict_types=1);

/**
 * Reports — Package binary by package card (plan 23D-S2).
 *
 * What each package contributed in the selected window: activations,
 * points placed, pairs paid and money credited. Points and money are
 * shown side by side, never added together (plan Rule 4).
 *
 * @var list<array{package_id:int, package:string, activations:int, points_placed:int, pairs:int, amount:float, recipients:int}> $package_binary_by_package
 * @var array{from:string, to:string, days:int, label:string} $range
 */

$package_binary_by_package = $package_binary_by_package ?? [];
$range = $range ?? ['from' => '', 'to' => '', 'days' => 0, 'label' => ''];
?>
              <div class="card h-100 report-binary-packages-card" data-live-region="report-binary-packages">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <div>
                    <h5 class="mb-1">Package binary — by package</h5>
                    <p class="text-body-secondary small mb-0">
                      Package activations and the points they placed, per package — <?= e($range['label']) ?>
                    </p>
                  </div>
                  <a href="<?= e(url('admin/commissions?ledger=binary&source=activation')) ?>" class="btn btn-sm btn-label-primary">Ledger</a>
                </div>
                <div class="card-body">
<?php if ($package_binary_by_package === []): ?>
<?php
    echo View::partial('empty_state', [
        'compact' => true,
        'icon'    => 'bx bx-package',
        'title'   => 'No package activations in this window',
        'text'    => 'Turn the stream on, flag a package and approve an activation.',
    ]);
?>
<?php else: ?>
                  <table class="table table-sm align-middle mb-0">
                    <thead>
                      <tr>
                        <th scope="col">Package</th>
                        <th scope="col" class="text-end">Activations</th>
                        <th scope="col" class="text-end">Points placed</th>
                        <th scope="col" class="text-end">Pairs paid</th>
                        <th scope="col" class="text-end">Amount</th>
                        <th scope="col" class="text-end">Recipients</th>
                      </tr>
                    </thead>
                    <tbody>
<?php foreach ($package_binary_by_package as $row): ?>
                      <tr>
                        <td class="text-nowrap small"><?= e((string) $row['package']) ?></td>
                        <td class="text-end text-nowrap small"><?= (int) $row['activations'] ?></td>
                        <td class="text-end text-nowrap small fw-semibold"><?= e(number_format((int) $row['points_placed'], 0, '.', '')) ?> pts</td>
                        <td class="text-end text-nowrap small"><?= (int) $row['pairs'] ?></td>
                        <td class="text-end text-nowrap small fw-semibold"><?= e(money((float) $row['amount'])) ?></td>
                        <td class="text-end text-nowrap small"><?= (int) $row['recipients'] ?></td>
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
