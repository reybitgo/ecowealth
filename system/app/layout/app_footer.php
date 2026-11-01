            <!-- Footer -->
            <footer class="content-footer footer bg-footer-theme">
              <div class="container-xxl">
                <div
                  class="footer-container d-flex align-items-center justify-content-between py-4 flex-md-row flex-column"
                >
                  <div class="mb-2 mb-md-0">
                    &#169;
                    <?= date('Y') ?> — Sneat login &amp; registration demo, made with
                    <span class="text-danger">&#x2764;</span> using
                    <a href="https://www.php.net" target="_blank" rel="noopener" class="footer-link">PHP <?= PHP_VERSION ?></a>
                    &amp;
                    <a href="https://www.mysql.com" target="_blank" rel="noopener" class="footer-link">MySQL</a>.
                  </div>
                  <div class="d-none d-lg-inline-block">
                    Template by
                    <a
                      href="https://themeselection.com/item/sneat-bootstrap-html-admin-template/"
                      target="_blank"
                      rel="noopener"
                      class="footer-link"
                      >ThemeSelection</a
                    >
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
    <!-- Main JS -->
    <script src="<?= e(url('assets/js/main.js')) ?>"></script>
  </body>
</html>
<!-- beautify ignore:end -->
