<?php
declare(strict_types=1);

/**
 * Product detail — the "no longer available" card.
 *
 * Replaces the product card when a product is deleted or deactivated while
 * its page is open. The card keeps the same region wrapper, so a later
 * reactivation can restore the real product.
 */
?>
              <div data-live-region="shop-product-card">
                <div class="card">
                  <div class="card-body py-10 text-center">
                    <span class="avatar avatar-lg mb-4">
                      <span class="avatar-initial rounded-circle bg-label-secondary">
                        <i class="icon-base bx bx-package fs-4"></i>
                      </span>
                    </span>
                    <h5 class="mb-2">This product is no longer available</h5>
                    <p class="text-body-secondary mb-4">
                      It may have been removed or taken out of the catalogue. Browse the shop for something else.
                    </p>
                    <a href="<?= e(url('shop')) ?>" class="btn btn-primary">
                      <i class="icon-base bx bx-store me-1"></i> Back to the shop
                    </a>
                  </div>
                </div>
              </div>
