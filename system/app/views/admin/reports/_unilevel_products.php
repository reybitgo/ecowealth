<?php
declare(strict_types=1);

/**
 * Reports — repeat-purchase money per product (Phase 17D, plan §8).
 *
 * Each product has its own ten-row ladder, so this is where a margin
 * review starts: how much a product's bonus cost in the window.
 *
 * @var list<array{product_id:int, product:string, count:int, amount:float, skipped:int}> $unilevel_products
 * @var array{from:string, to:string, days:int, label:string} $range
 */

$rows  = $unilevel_products ?? [];
$range = $range ?? ['label' => ''];
?>
              <div class="card h-100 report-unilevel-products-card" data-live-region="report-unilevel-products">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <div>
                    <h5 class="mb-1">Repeat-purchase by product</h5>
                    <p class="text-body-secondary small mb-0">
                      The ladder is per product — the first place to check a margin.
                    </p>
                  </div>
                  <a href="<?= e(url('admin/products?unilevel=on')) ?>" class="btn btn-sm btn-label-secondary">Configured products</a>
                </div>
                <div class="table-responsive">
                  <table class="table table-sm align-middle mb-0 report-table">
                    <thead>
                      <tr>
                        <th scope="col">Product</th>
                        <th scope="col" class="text-end">Awards</th>
                        <th scope="col" class="text-end">Amount</th>
                        <th scope="col" class="text-end">Skipped</th>
                      </tr>
                    </thead>
                    <tbody>
<?php foreach ($rows as $row): ?>
                      <tr>
                        <td>
                          <a href="<?= e(url('admin/products/' . (int) $row['product_id'] . '/edit')) ?>" class="text-body">
                            <?= e((string) $row['product']) ?>
                          </a>
                        </td>
                        <td class="text-end"><?= (int) $row['count'] ?></td>
                        <td class="text-end"><?= e(money($row['amount'])) ?></td>
                        <td class="text-end"><?= (int) $row['skipped'] ?></td>
                      </tr>
<?php endforeach; ?>
<?php if ($rows === []): ?>
                      <tr><td colspan="4" class="text-body-secondary">No product paid a repeat-purchase bonus in this window.</td></tr>
<?php endif; ?>
                    </tbody>
                  </table>
                </div>
                <div class="card-footer small text-body-secondary">
                  A product switched on with an empty ladder pays nothing — the product form shows its total.
                </div>
              </div>
