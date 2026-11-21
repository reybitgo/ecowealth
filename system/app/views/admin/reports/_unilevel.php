<?php
declare(strict_types=1);

/**
 * Reports — repeat-purchase bonus summary (Phase 17D/18, plan §8, §19.6).
 *
 * What the ladder earned on paid shop orders in the selected window.
 * `amount` is released money (in a wallet); `pending_amount` /
 * `pending_count` are the escrow liability (earned, not paid yet), and
 * `cancelled_count` was never paid at all, so it is not a shortfall.
 * `unrecovered` is the part of a voided award the wallet could not
 * return after a post-release reversal.
 *
 * The numbers come from `Report::unilevelSummary()`, the same read the
 * admin ledger's header uses, so the two screens cannot disagree.
 *
 * @var array{
 *   amount:float, count:int, skipped:int, voided:int, unrecovered:float,
 *   recipients:int, all_time:float, this_month:float,
 *   pending_amount:float, pending_count:int, cancelled_count:int
 * } $unilevel
 * @var array{from:string, to:string, days:int, label:string} $range
 */

$unilevel = array_merge([
    'amount' => 0.0, 'count' => 0, 'skipped' => 0, 'voided' => 0,
    'unrecovered' => 0.0, 'recipients' => 0, 'all_time' => 0.0, 'this_month' => 0.0,
    'pending_amount' => 0.0, 'pending_count' => 0, 'cancelled_count' => 0,
], $unilevel ?? []);
$range = $range ?? ['label' => ''];

$pendingCount  = (int) $unilevel['pending_count'];
$cancelledCount = (int) $unilevel['cancelled_count'];
?>
              <div class="card h-100 report-unilevel-card" data-live-region="report-unilevel">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <div>
                    <h5 class="mb-1">Repeat-purchase bonus</h5>
                    <p class="text-body-secondary small mb-0">
                      Repeat-purchase money earned by the buyer's sponsor chain — <?= e($range['label']) ?>
                    </p>
                  </div>
                  <a href="<?= e(url('admin/commissions?ledger=repeat')) ?>" class="btn btn-sm btn-label-primary">Ledger</a>
                </div>
                <div class="card-body">
                  <div class="d-flex align-items-baseline gap-2 mb-3">
                    <h3 class="mb-0"><?= e(money($unilevel['amount'])) ?></h3>
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
                    <span class="fw-semibold text-nowrap"><?= e(money($unilevel['pending_amount'])) ?></span>
                  </div>

                  <div class="row g-3">
                    <div class="col-6">
                      <div class="text-body-secondary small">Awards</div>
                      <div class="fw-semibold"><?= (int) $unilevel['count'] ?></div>
                    </div>
                    <div class="col-6">
                      <div class="text-body-secondary small">Recipients</div>
                      <div class="fw-semibold"><?= (int) $unilevel['recipients'] ?></div>
                    </div>
                    <div class="col-6">
                      <div class="text-body-secondary small">Skipped</div>
                      <div class="fw-semibold"><?= (int) $unilevel['skipped'] ?></div>
                    </div>
                    <div class="col-6">
                      <div class="text-body-secondary small">Voided</div>
                      <div class="fw-semibold"><?= (int) $unilevel['voided'] ?></div>
                    </div>
                    <div class="col-12">
                      <div class="text-body-secondary small">Cancelled before release</div>
                      <div class="fw-semibold"><?= $cancelledCount ?> <span class="small fw-normal text-body-secondary">— never paid; no shortfall</span></div>
                    </div>
                  </div>
<?php if ($unilevel['unrecovered'] > 0): ?>
                  <div class="alert alert-warning small mt-3 mb-0" role="alert">
                    <strong><?= e(money($unilevel['unrecovered'])) ?> unrecovered.</strong>
                    A cancelled order clawed back only what the wallets still held.
                  </div>
<?php endif; ?>
                </div>
                <div class="card-footer small text-body-secondary">
                  All time <?= e(money($unilevel['all_time'])) ?> · this month <?= e(money($unilevel['this_month'])) ?> ·
                  pending release is earned money outside the wallet; a skipped row is an audit note, never money.
                </div>
              </div>
