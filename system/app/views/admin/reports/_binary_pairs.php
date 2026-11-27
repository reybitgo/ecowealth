<?php
declare(strict_types=1);

/**
 * Reports — Product binary by kind and by day (Phase 22E).
 *
 * Self vs pair awards in the window, then the awards and points placed
 * per day so finance can see when the stream paid and when volume landed.
 *
 * @var list<array{kind:string, count:int, amount:float, recipients:int, credited:int, pending:int}> $binary_by_kind
 * @var list<array{day:string, awards:int, amount:float, pending_amount:float, points_left:int, points_right:int}> $binary_by_day
 * @var array{from:string, to:string, days:int, label:string} $range
 */

$binaryByKind = is_array($binary_by_kind ?? null) ? $binary_by_kind : [];
$binaryByDay  = is_array($binary_by_day ?? null)  ? $binary_by_day  : [];
$range        = $range ?? ['from' => '', 'to' => '', 'days' => 0, 'label' => ''];
?>
              <div class="card h-100 report-binary-pairs-card" data-live-region="report-binary-pairs">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <div>
                    <h5 class="mb-1">Self bonus vs pair bonus</h5>
                    <p class="text-body-secondary small mb-0">
                      Awards by kind in <?= e($range['label']) ?> — one self award per order, one pair
                      award per node when left and right volume match.
                    </p>
                  </div>
                </div>
                <div class="card-body">
<?php if ($binaryByKind === []): ?>
<?php
    echo View::partial('empty_state', [
        'compact' => true,
        'icon'    => 'bx bx-award',
        'title'   => 'No binary awards in this window',
        'text'    => 'Self and pair awards appear once left and right volume match.',
    ]);
?>
<?php else: ?>
                  <div class="table-responsive mb-4">
                    <table class="table table-sm align-middle mb-0">
                      <thead>
                        <tr>
                          <th scope="col">Kind</th>
                          <th scope="col" class="text-end">Awards</th>
                          <th scope="col" class="text-end">Amount</th>
                          <th scope="col" class="text-end">Recipients</th>
                          <th scope="col" class="text-end">Credited</th>
                          <th scope="col" class="text-end">Pending</th>
                        </tr>
                      </thead>
                      <tbody>
<?php foreach ($binaryByKind as $row): ?>
                        <tr>
                          <td class="text-nowrap fw-medium"><?= e(ucfirst((string) $row['kind'])) ?></td>
                          <td class="text-end"><?= (int) $row['count'] ?></td>
                          <td class="text-end text-nowrap"><?= e(money((float) $row['amount'])) ?></td>
                          <td class="text-end"><?= (int) $row['recipients'] ?></td>
                          <td class="text-end"><?= (int) $row['credited'] ?></td>
                          <td class="text-end"><?= (int) $row['pending'] ?></td>
                        </tr>
<?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
<?php endif; ?>

<?php if ($binaryByDay !== []): ?>
                  <hr class="my-3" />
                  <h6 class="mb-2">By day</h6>
                  <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                      <thead>
                        <tr>
                          <th scope="col">Day</th>
                          <th scope="col" class="text-end">Awards</th>
                          <th scope="col" class="text-end">Amount</th>
                          <th scope="col" class="text-end">Pending</th>
                          <th scope="col" class="text-end">Points L / R</th>
                        </tr>
                      </thead>
                      <tbody>
<?php foreach ($binaryByDay as $row): ?>
                        <tr>
                          <td class="text-nowrap fw-medium"><?= e(format_date((string) $row['day'])) ?></td>
                          <td class="text-end"><?= (int) $row['awards'] ?></td>
                          <td class="text-end text-nowrap"><?= e(money((float) $row['amount'])) ?></td>
                          <td class="text-end text-nowrap"><?= e(money((float) $row['pending_amount'])) ?></td>
                          <td class="text-end text-nowrap">
                            <?= number_format((int) $row['points_left']) ?> / <?= number_format((int) $row['points_right']) ?>
                          </td>
                        </tr>
<?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
<?php endif; ?>
                </div>
                <div class="card-footer small text-body-secondary">
                  One row per award kind and per day with at least one award; days without awards show zero.
                  Export this block with <code>Export CSV → Product binary bonus</code>.
                </div>
              </div>
