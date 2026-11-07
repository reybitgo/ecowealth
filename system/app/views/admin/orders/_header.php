<?php
declare(strict_types=1);

/**
 * Admin order desk — page heading and the review counter.
 *
 * The counter is its own live region: another administrator verifying or
 * rejecting a proof changes it without a reload. The filter form below is
 * deliberately outside every region, so a background refresh never steals
 * focus or discards an unsubmitted search.
 *
 * @var array{in_review:int} $stats
 */

$inReview = (int) ($stats['in_review'] ?? 0);
?>
              <div
                class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-6"
                data-live-region="order-header"
              >
                <div>
                  <h4 class="mb-1">Orders</h4>
                  <p class="text-body-secondary mb-0">
                    Every order, newest first. Open an order to verify or reject a manual payment proof.
                  </p>
                </div>
                <a
                  href="<?= e(url('admin/orders?status=payment_review')) ?>"
                  class="badge bg-label-info text-decoration-none"
                >
                  <i class="icon-base bx bx-search-alt me-1"></i><?= $inReview ?> awaiting review
                </a>
              </div>
