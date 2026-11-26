<?php
declare(strict_types=1);

/**
 * Member Product binary statement — the Self Bonus card (22F, plan §10,
 * Appendix C.8).
 *
 * The one place in the application where the buyer is a recipient. The
 * copy says so plainly: "You earn on your own purchase: 5% of its points."
 * It is never called a rebate, cashback, refund or discount.
 *
 * @var array{
 *   count:int, credited:float, pending:float, pending_count:int,
 *   self_total:float, pair_total:float, self_count:int, pair_count:int,
 *   next_release_at:?string
 * } $totals
 */

$totals = array_merge(
    [
        'count'          => 0,
        'credited'       => 0.0,
        'pending'        => 0.0,
        'pending_count'  => 0,
        'self_total'     => 0.0,
        'pair_total'     => 0.0,
        'self_count'     => 0,
        'pair_count'     => 0,
        'next_release_at'=> null,
    ],
    $totals ?? []
);

$selfTotal = round((float) $totals['self_total'], 2);
$selfCount = (int) $totals['self_count'];
?>
              <div class="card mb-6" data-live-region="earnings-binary-self">
                <div class="card-body">
                  <div class="d-flex align-items-start justify-content-between gap-3">
                    <div>
                      <h5 class="mb-1">You earn on your own purchase</h5>
                      <p class="text-body-secondary mb-2">
                        Your Self Bonus is <strong>5% of your order's points</strong>, paid once per
                        order with no Active gate. It lands here pending and is released to your
                        e-wallet after delivery + <?= BinaryService::holdDays() ?> days.
                      </p>
                      <p class="mb-0 small text-body-secondary">
                        <strong><?= money($selfTotal) ?></strong> self bonus across
                        <strong><?= $selfCount ?></strong> order<?= $selfCount === 1 ? '' : 's' ?>.
                      </p>
                    </div>
                    <span class="badge bg-label-primary fs-4"><?= money($selfTotal) ?></span>
                  </div>
                </div>
              </div>
