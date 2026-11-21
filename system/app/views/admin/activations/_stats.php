<?php
declare(strict_types=1);

/**
 * Admin activation queue — headline counters.
 *
 * @var array{total:int, review:int, approved:int, rejected:int, amount_review:float, amount_approved:float} $stats
 */
?>
              <div class="row g-4 mb-4" data-live-region="activation-stats">
                <div class="col-sm-6 col-xl-3">
                  <div class="card h-100">
                    <div class="card-body">
                      <p class="text-body-secondary mb-1 small">Awaiting review</p>
                      <h4 class="mb-1"><?= (int) $stats['review'] ?></h4>
                      <small class="text-body-secondary"><?= e(money($stats['amount_review'])) ?> claimed</small>
                    </div>
                  </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                  <div class="card h-100">
                    <div class="card-body">
                      <p class="text-body-secondary mb-1 small">Approved</p>
                      <h4 class="mb-1"><?= (int) $stats['approved'] ?></h4>
                      <small class="text-body-secondary"><?= e(money($stats['amount_approved'])) ?> activated</small>
                    </div>
                  </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                  <div class="card h-100">
                    <div class="card-body">
                      <p class="text-body-secondary mb-1 small">Rejected</p>
                      <h4 class="mb-1"><?= (int) $stats['rejected'] ?></h4>
                      <small class="text-body-secondary">member returned to pending</small>
                    </div>
                  </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                  <div class="card h-100">
                    <div class="card-body">
                      <p class="text-body-secondary mb-1 small">All requests</p>
                      <h4 class="mb-1"><?= (int) $stats['total'] ?></h4>
                      <small class="text-body-secondary">since launch</small>
                    </div>
                  </div>
                </div>
              </div>
