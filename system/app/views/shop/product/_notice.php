<?php
declare(strict_types=1);

/**
 * Product detail — shop-closed notice.
 *
 * The wrapper always exists so the feed can reveal or hide the notice
 * without inserting a region the client does not know.
 *
 * @var bool $shop_open
 */
?>
              <div data-live-region="shop-product-notice"<?= $shop_open ? ' hidden' : '' ?>>
<?php if (!$shop_open): ?>
                <div class="alert alert-warning d-flex align-items-center gap-2" role="alert">
                  <i class="icon-base bx bx-store-alt fs-4"></i>
                  <span>The shop is currently closed. Please check back soon.</span>
                </div>
<?php endif; ?>
              </div>
