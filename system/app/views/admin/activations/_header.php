<?php
declare(strict_types=1);

/**
 * Admin activation queue — heading row.
 *
 * @var array{total:int, review:int, approved:int, rejected:int, amount_review:float, amount_approved:float} $stats
 */
?>
              <div
                class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4"
                data-live-region="activation-header"
              >
                <div>
                  <h4 class="mb-1">Activations</h4>
                  <p class="text-body-secondary mb-0">
                    Review payment proofs and approve package activations. Approval runs the commission hook once.
                  </p>
                </div>
                <span class="badge rounded-pill bg-label-warning">
                  <?= (int) $stats['review'] ?> awaiting review
                </span>
              </div>
