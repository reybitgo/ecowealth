            <!-- Footer -->
            <footer class="content-footer footer bg-footer-theme">
              <div class="container-xxl">
                <div
                  class="footer-container d-flex align-items-center justify-content-between py-4 flex-md-row flex-column"
                >
                  <div class="mb-2 mb-md-0">
                    &#169;
                    <?= date('Y') ?> <?= e(APP_NAME) ?> — <?= e(APP_TAGLINE) ?>.
                    <span class="text-body-secondary">All rights reserved.</span>
                  </div>
                </div>
              </div>
            </footer>
            <!-- / Footer -->
            <div class="content-backdrop fade"></div>
          </div>
          <!-- Content wrapper -->
        </div>
        <!-- / Layout page -->
      </div>
      <!-- Overlay -->
      <div class="layout-overlay layout-menu-toggle"></div>
      <!-- Drag Target Area To SlideIn Menu On Small Screens -->
      <div class="drag-target"></div>
    </div>
    <!-- / Layout wrapper -->
<?php if (($layout ?? 'member') !== 'plain' && auth_check()): ?>
<?php
    /* Global cart drawer (plan §5.2, Phase 4). A page view never writes:
     * CartService::state() only reads the member's active cart. Guarded so a
     * deploy that lands before migration 004 cannot blank every page. */
    $cart_drawer_state = [
        'cart'   => null,
        'items'  => [],
        'totals' => ['items' => 0, 'quantity' => 0, 'subtotal' => 0.0],
    ];

    try {
        $cart_drawer_state = (new CartService())->state((int) auth_user()['id']);
    } catch (Throwable $e) {
        error_log('[ecowealth] cart drawer unavailable: ' . $e->getMessage());
    }
?>
<?= View::partial('cart_offcanvas', ['state' => $cart_drawer_state]) ?>
    <div
      id="cartConfig"
      hidden
      data-count-url="<?= e(url('cart/count')) ?>"
      data-add-url="<?= e(url('cart/add')) ?>"
      data-update-url="<?= e(url('cart/update')) ?>"
      data-remove-url="<?= e(url('cart/remove')) ?>"
      data-cart-url="<?= e(url('cart')) ?>"
      data-csrf="<?= e(csrf_token()) ?>"
    ></div>
<?php endif; ?>
    <!-- Toast host + cart behaviour (Phase 4) -->
    <div
      id="toastContainer"
      class="toast-container position-fixed end-0 p-3"
      aria-live="polite"
      aria-atomic="true"
    ></div>
    <!-- Core JS -->
    <!-- build:js assets/vendor/js/theme.js  -->
    <script src="<?= e(url('assets/vendor/libs/jquery/jquery.js')) ?>"></script>
    <script src="<?= e(url('assets/vendor/libs/popper/popper.js')) ?>"></script>
    <script src="<?= e(url('assets/vendor/js/bootstrap.js')) ?>"></script>
    <script src="<?= e(url('assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js')) ?>"></script>
    <script src="<?= e(url('assets/vendor/libs/hammer/hammer.js')) ?>"></script>
    <script src="<?= e(url('assets/vendor/libs/i18n/i18n.js')) ?>"></script>
    <script src="<?= e(url('assets/vendor/js/menu.js')) ?>"></script>
    <!-- endbuild -->
<?php foreach ($page_js as $js): ?>
    <script src="<?= e(asset_url($js)) ?>"></script>
<?php endforeach; ?>
    <!-- App JS -->
    <script src="<?= e(asset_url('assets/js/app.js')) ?>"></script>
    <script src="<?= e(asset_url('assets/js/app-cart.js')) ?>"></script>
    <!-- Main JS -->
    <script src="<?= e(url('assets/js/main.js')) ?>"></script>
  </body>
</html>
<!-- beautify ignore:end -->
