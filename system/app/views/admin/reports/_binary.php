<?php
declare(strict_types=1);

/**
 * Reports — Product binary bonus summary card (Phase 22E, plan §9).
 *
 * What the stream earned and placed in the selected window: released
 * money, the pending escrow liability, the cancelled and voided counts,
 * the unrecovered shortfall, and the points placed left vs right — shown
 * side by side, never added together (plan Rule 4).
 *
 * The numbers come from `Report::binarySummary()`, the same read the
 * admin ledger's header uses, so the reports desk and the ledger cannot
 * disagree (plan Rule 4, P22E-T14).
 *
 * @var array{
 *   amount:float, count:int, cancelled:int, cancelled_total:float,
 *   voided:int, voided_total:float, unrecovered:float, recipients:int,
 *   all_time:float, this_month:float, pending_amount:float,
 *   pending_count:int, self_total:float, pair_total:float,
 *   points_left:int, points_right:int
 * } $binary
 * @var array{from:string, to:string, days:int, label:string} $range
 */

$binary = array_merge([
    'amount' => 0.0, 'count' => 0, 'cancelled' => 0, 'cancelled_total' => 0.0,
    'voided' => 0, 'voided_total' => 0.0, 'unrecovered' => 0.0, 'recipients' => 0,
    'all_time' => 0.0, 'this_month' => 0.0, 'pending_amount' => 0.0,
    'pending_count' => 0, 'self_total' => 0.0, 'pair_total' => 0.0,
    'points_left' => 0, 'points_right' => 0,
], $binary ?? []);
$range = $range ?? ['from' => '', 'to' => '', 'days' => 0, 'label' => ''];

$pendingCount  = (int) $binary['pending_count'];
$cancelledCount = (int) $binary['cancelled'];
$voidedCount    = (int) $binary['voided'];
$pointsPlaced   = (int) $binary['points_left'] + (int) $binary['points_right'];
?>
              <div class="card h-100 report-binary-card" data-live-region="report-binary">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <div>
                    <h5 class="mb-1">Product binary bonus</h5>
                    <p class="text-body-secondary small mb-0">
                      Self bonus and left/right pair matching on paid repeat purchases — <?= e($range['label']) ?>
                    </p>
                  </div>
                  <a href="<?= e(url('admin/commissions?ledger=binary')) ?>" class="btn btn-sm btn-label-primary">Ledger</a>
                </div>
                <div class="card-body">
                  <div class="d-flex align-items-baseline gap-2 mb-3">
                    <h3 class="mb-0"><?= e(money($binary['amount'])) ?></h3>
                    <span class="text-body-secondary small">credited in this window</span>
                  </div>

                  <div class="alert alert-warning d-flex align-items-start justify-content-between gap-2 mb-3" role="status">
                    <div>
                      <strong>Pending liability</strong>
                      <div class="small">
                        Pending release — <?= $pendingCount ?> award<?= $pendingCount === 1 ? '' : 's' ?>
                        earned but not in a wallet yet. The credited figure above is released money only.
                      </div>
                    </div>
                    <span class="fw-semibold text-nowrap"><?= e(money($binary['pending_amount'])) ?></span>
                  </div>

                  <div class="row g-3">
                    <div class="col-6 col-md-3">
                      <div class="text-body-secondary small">Awards</div>
                      <div class="fw-semibold"><?= (int) $binary['count'] ?></div>
                    </div>
                    <div class="col-6 col-md-3">
                      <div class="text-body-secondary small">Recipients</div>
                      <div class="fw-semibold"><?= (int) $binary['recipients'] ?></div>
                    </div>
                    <div class="col-6 col-md-3">
                      <div class="text-body-secondary small">Points placed</div>
                      <div class="fw-semibold"><?= number_format($pointsPlaced) ?> pts</div>
                    </div>
                    <div class="col-6 col-md-3">
                      <div class="text-body-secondary small">Self vs pair</div>
                      <div class="fw-semibold">
                        <?= e(money($binary['self_total'])) ?> · <?= e(money($binary['pair_total'])) ?>
                      </div>
                    </div>
                    <div class="col-6 col-md-3">
                      <div class="text-body-secondary small">Left / right</div>
                      <div class="fw-semibold text-nowrap">
                        <?= number_format((int) $binary['points_left']) ?> / <?= number_format((int) $binary['points_right']) ?> pts
                      </div>
                    </div>
                    <div class="col-6 col-md-3">
                      <div class="text-body-secondary small">Cancelled before release</div>
                      <div class="fw-semibold"><?= $cancelledCount ?> <span class="small fw-normal text-body-secondary">— never paid; no shortfall</span></div>
                    </div>
                    <div class="col-6 col-md-3">
                      <div class="text-body-secondary small">Voided (reversed)</div>
                      <div class="fw-semibold"><?= $voidedCount ?></div>
                    </div>
                    <div class="col-12">
                      <div class="text-body-secondary small">Unrecovered shortfall</div>
                      <div class="fw-semibold"><?= e(money($binary['unrecovered'])) ?></div>
                    </div>
                  </div>
<?php if ($binary['unrecovered'] > 0): ?>
                  <div class="alert alert-warning small mt-3 mb-0" role="alert">
                    <strong><?= e(money($binary['unrecovered'])) ?> unrecovered.</strong>
                    A cancelled order clawed back only what the wallets still held.
                  </div>
<?php endif; ?>
                </div>
                <div class="card-footer small text-body-secondary">
                  All time <?= e(money($binary['all_time'])) ?> · this month <?= e(money($binary['this_month'])) ?> ·
                  self bonus <?= e(money($binary['self_total'])) ?> · pair bonus <?= e(money($binary['pair_total'])) ?> ·
                  Export this block with <code>Export CSV → Product binary bonus</code>.
                </div>
              </div>
