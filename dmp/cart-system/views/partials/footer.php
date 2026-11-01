<?php

/**
 * @file   views/partials/footer.php
 * @brief  Footer for member pages
 */
?>
<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- App JS -->
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= filemtime(__DIR__ . '/../../assets/js/app.js') ?>"></script>
<!-- Global off-canvas cart drawer -->
<?php require __DIR__ . '/cart_offcanvas.php';
?>
<!-- Toast container -->
<div id="toastContainer"></div>
</body>

</html>