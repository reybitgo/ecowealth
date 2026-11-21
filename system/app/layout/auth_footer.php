        </div>
      </div>
    </div>
    <!-- / Content -->
    <!-- Core JS -->
    <!-- build:js assets/vendor/js/theme.js  -->
    <script src="<?= e(url('assets/vendor/libs/jquery/jquery.js')) ?>"></script>
    <script src="<?= e(url('assets/vendor/libs/popper/popper.js')) ?>"></script>
    <script src="<?= e(url('assets/vendor/js/bootstrap.js')) ?>"></script>
    <script src="<?= e(url('assets/vendor/libs/pickr/pickr.js')) ?>"></script>
    <script src="<?= e(url('assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js')) ?>"></script>
    <script src="<?= e(url('assets/vendor/libs/hammer/hammer.js')) ?>"></script>
    <script src="<?= e(url('assets/vendor/libs/i18n/i18n.js')) ?>"></script>
    <script src="<?= e(url('assets/vendor/js/menu.js')) ?>"></script>
    <!-- endbuild -->
    <!-- Vendors JS -->
    <script src="<?= e(url('assets/vendor/libs/@form-validation/popular.js')) ?>"></script>
    <script src="<?= e(url('assets/vendor/libs/@form-validation/bootstrap5.js')) ?>"></script>
    <script src="<?= e(url('assets/vendor/libs/@form-validation/auto-focus.js')) ?>"></script>
    <!-- Main JS -->
    <script src="<?= e(url('assets/js/main.js')) ?>"></script>
    <!-- Page JS -->
    <script src="<?= e(url('assets/js/pages-auth.js')) ?>"></script>
<?php foreach ((array) ($auth_page_js ?? []) as $auth_js): ?>
    <script src="<?= e(asset_url((string) $auth_js)) ?>"></script>
<?php endforeach; ?>
  </body>
</html>
<!-- beautify ignore:end -->
