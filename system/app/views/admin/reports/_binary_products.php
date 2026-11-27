<?php
declare(strict_types=1);

/**
 * Reports — Product binary by product (Phase 22E).
 *
 * Points placed and awards per product in the window, biggest points
 * first — which products are driving the binary tree's volume.
 *
 * @var list<array{product_id:int, product:string, points_placed:int, awards:int, self_total:float, pair_total:float}> $binary_products
 * @var array{from:string, to:string, days:int, label:string} $range
 */

$binaryProducts = is_array($binary_products ?? null) ? $binary_products : [];
$range          = $range ?? ['from' => '', 'to' => '', 'days' => 0, 'label' => ''];
?>
              <div class="card h-100 report-binary-products-card" data-live-region="report-binary-products">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <div>
                    <h5 class="mb-1">Points placed by product</h5>
                    <p class="text-body-secondary small mb-0">
                      Binary points placed (own + team volume) per product in <?= e($range['label']) ?>.
                    </p>
                  </div>
                </div>
                <div class="card-body">
<?php if ($binaryProducts === []): ?>
<?php
    echo View::partial('empty_state', [
        'compact' => true,
        'icon'    => 'bx bx-package',
        'title'   => 'No product has placed binary points in this window',
        'text'    => 'Points appear once an order or activation places binary volume.',
    ]);
?>
<?php else: ?>
                  <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                      <thead>
                        <tr>
                          <th scope="col">Product</th>
                          <th scope="col" class="text-end">Points placed</th>
                          <th scope="col" class="text-end">Awards</th>
                          <th scope="col" class="text-end">Self</th>
                          <th scope="col" class="text-end">Pair</th>
                        </tr>
                      </thead>
                      <tbody>
<?php foreach ($binaryProducts as $row): ?>
                        <tr>
                          <td class="text-nowrap fw-medium"><?= e((string) $row['product']) ?></td>
                          <td class="text-end text-nowrap"><?= number_format((int) $row['points_placed']) ?> pts</td>
                          <td class="text-end"><?= (int) $row['awards'] ?></td>
                          <td class="text-end text-nowrap"><?= e(money((float) $row['self_total'])) ?></td>
                          <td class="text-end text-nowrap"><?= e(money((float) $row['pair_total'])) ?></td>
                        </tr>
<?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
<?php endif; ?>
                </div>
                <div class="card-footer small text-body-secondary">
                  Points are own + team volume from `binary_volume` (the ledger, not the cached node
                  balances). Awards count the binary awards on orders that placed that product's points.
                  Export this block with <code>Export CSV → Product binary bonus</code>.
                </div>
              </div>
