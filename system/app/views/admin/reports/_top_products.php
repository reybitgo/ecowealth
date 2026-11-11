<?php
declare(strict_types=1);

/**
 * Reports — best-selling products in the window.
 *
 * @var list<array{name:string, sku:?string, units:int, amount:float}> $top_products
 * @var array{from:string, to:string, days:int, label:string} $range
 */

$topProducts = $top_products ?? [];
$range = $range ?? ['label' => ''];
?>
              <div class="card h-100 report-top-card" data-live-region="report-top-products">
                <div class="card-header">
                  <h5 class="mb-1">Top products</h5>
                  <p class="text-body-secondary small mb-0">Units sold · <?= e((string) $range['label']) ?></p>
                </div>
                <div class="card-body">
<?php if ($topProducts === []): ?>
                  <div class="text-center text-body-secondary py-6">
                    <i class="icon-base bx bx-package icon-xl d-block mb-2"></i>
                    No product has sold in this window.
                  </div>
<?php else: ?>
                  <ul class="list-unstyled report-top-list mb-0">
<?php foreach ($topProducts as $index => $product): ?>
                    <li class="report-top-row">
                      <span class="report-top-rank"><?= $index + 1 ?></span>
                      <div class="flex-grow-1">
                        <div class="fw-medium"><?= e((string) $product['name']) ?></div>
                        <small class="text-body-secondary">
                          <?= (int) $product['units'] ?> unit<?= (int) $product['units'] === 1 ? '' : 's' ?>
<?php if ((string) ($product['sku'] ?? '') !== ''): ?>
                          · <?= e((string) $product['sku']) ?>
<?php endif; ?>
                        </small>
                      </div>
                      <span class="fw-semibold"><?= e(money($product['amount'])) ?></span>
                    </li>
<?php endforeach; ?>
                  </ul>
<?php endif; ?>
                </div>
                <div class="card-footer small text-body-secondary">
                  Derived from paid order lines — the same rows the invoice and the reservation ledger use.
                </div>
              </div>
