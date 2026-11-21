<?php
declare(strict_types=1);

/**
 * Reports — registration-code revenue by package.
 *
 * @var list<array{
 *   package_id:int, package:string, price:float,
 *   issued:int, used:int, unused:int, expired:int,
 *   issued_value:float, redeemed_value:float
 * }> $codes
 */

$codes = $codes ?? [];

$totals = ['issued' => 0, 'used' => 0, 'unused' => 0, 'expired' => 0, 'issued_value' => 0.0, 'redeemed_value' => 0.0];

foreach ($codes as $row) {
    $totals['issued'] += (int) $row['issued'];
    $totals['used'] += (int) $row['used'];
    $totals['unused'] += (int) $row['unused'];
    $totals['expired'] += (int) $row['expired'];
    $totals['issued_value'] += (float) $row['issued_value'];
    $totals['redeemed_value'] += (float) $row['redeemed_value'];
}
?>
              <div class="card report-codes-card" data-live-region="report-codes">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <div>
                    <h5 class="mb-1">Registration-code revenue</h5>
                    <p class="text-body-secondary small mb-0">
                      <?= $totals['issued'] ?> code<?= $totals['issued'] === 1 ? '' : 's' ?> issued ·
                      <?= $totals['used'] ?> redeemed · <?= e(money($totals['redeemed_value'])) ?> realised
                    </p>
                  </div>
                  <div class="d-flex gap-2">
                    <a href="<?= e(url('admin/codes')) ?>" class="btn btn-sm btn-label-primary">Codes</a>
                    <a href="<?= e(url('admin/codes/export')) ?>" class="btn btn-sm btn-label-secondary">
                      <i class="icon-base bx bx-download me-1"></i> CSV
                    </a>
                  </div>
                </div>
                <div class="table-responsive">
                  <table class="table table-sm align-middle mb-0 report-table">
                    <thead>
                      <tr>
                        <th scope="col">Package</th>
                        <th scope="col" class="text-end">Price</th>
                        <th scope="col" class="text-end">Issued</th>
                        <th scope="col" class="text-end">Redeemed</th>
                        <th scope="col" class="text-end">Unused</th>
                        <th scope="col" class="text-end">Expired</th>
                        <th scope="col" class="text-end">Issued value</th>
                        <th scope="col" class="text-end">Realised</th>
                      </tr>
                    </thead>
                    <tbody>
<?php foreach ($codes as $row): ?>
                      <tr>
                        <td class="fw-medium"><?= e((string) $row['package']) ?></td>
                        <td class="text-end"><?= e(money($row['price'])) ?></td>
                        <td class="text-end"><?= (int) $row['issued'] ?></td>
                        <td class="text-end"><?= (int) $row['used'] ?></td>
                        <td class="text-end"><?= (int) $row['unused'] ?></td>
                        <td class="text-end"><?= (int) $row['expired'] ?></td>
                        <td class="text-end"><?= e(money($row['issued_value'])) ?></td>
                        <td class="text-end"><?= e(money($row['redeemed_value'])) ?></td>
                      </tr>
<?php endforeach; ?>
<?php if ($codes === []): ?>
                      <tr><td colspan="8" class="text-body-secondary">No packages are configured yet.</td></tr>
<?php endif; ?>
                    </tbody>
                    <tfoot>
                      <tr class="fw-semibold">
                        <td colspan="2">Total</td>
                        <td class="text-end"><?= $totals['issued'] ?></td>
                        <td class="text-end"><?= $totals['used'] ?></td>
                        <td class="text-end"><?= $totals['unused'] ?></td>
                        <td class="text-end"><?= $totals['expired'] ?></td>
                        <td class="text-end"><?= e(money($totals['issued_value'])) ?></td>
                        <td class="text-end"><?= e(money($totals['redeemed_value'])) ?></td>
                      </tr>
                    </tfoot>
                  </table>
                </div>
                <div class="card-footer small text-body-secondary">
                  Codes are package-bound and single-use; realised value counts <code>used</code> codes only.
                </div>
              </div>
