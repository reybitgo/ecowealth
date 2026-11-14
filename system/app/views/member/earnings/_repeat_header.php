<?php
declare(strict_types=1);

/**
 * Member repeat-purchase statement — the card heading (Phase 17E, plan §9;
 * Phase 18 escrow copy, plan §10.3).
 *
 * The copy states the earning event and the cash event separately: the
 * award is **earned** when the Team chain order is paid, and **released**
 * to the e-wallet after the return window. Saying both keeps the page
 * from implying that an unpaid-out award was never earned (plan §10.3,
 * Appendix C.7).
 *
 * @var Paginator $pager
 */
?>
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3" data-live-region="earnings-repeat-header">
                  <div>
                    <h5 class="mb-1">Your repeat-purchase awards</h5>
                    <p class="text-body-secondary mb-0 small">
                      One row per paid order line, product and level — each one a purchase from your
                      <strong>Team chain</strong> that earned your level when it was paid. An award is
                      released to your e-wallet after the return window, and only when you are eligible
                      and Active that month.
                    </p>
                  </div>
                  <span class="badge bg-label-primary"><?= (int) $pager->total ?> award<?= $pager->total === 1 ? '' : 's' ?></span>
                </div>
