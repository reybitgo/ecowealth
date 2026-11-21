<?php
declare(strict_types=1);

/**
 * Member Royalty statement — the card heading (Phase 19E, plan §9,
 * Appendix C.3).
 *
 * The copy states the cash event, not the purchase event: one award per
 * closed month, calculated after the month ends. Saying it here keeps a
 * member from reading the table as a per-order commission.
 *
 * @var Paginator $pager
 */
?>
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3" data-live-region="earnings-royalty-header">
                  <div>
                    <h5 class="mb-1">Your Royalty awards</h5>
                    <p class="text-body-secondary mb-0 small">
                      One row per closed month. Royalty is paid once a month, after the month closes, when
                      your rank and your team's group sales meet the quota — never per order.
                    </p>
                  </div>
                  <span class="badge bg-label-primary"><?= (int) $pager->total ?> month<?= $pager->total === 1 ? '' : 's' ?></span>
                </div>
