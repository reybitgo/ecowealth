<?php
/**
 * @file   views/admin/shop_products.php
 * @brief  Product catalog management. Expects: $products (paginate()), $editProduct (?array), $pageTitle set in controller.
 */
?>
<?php require 'views/partials/head.php'; ?>
<?php require 'views/partials/sidebar_admin.php'; ?>
<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <?= render_flash() ?>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <div>
        <h4 class="mb-0">Shop Products</h4>
        <p class="text-muted mb-0" style="font-size:.8rem;">Manage the storefront catalog.</p>
      </div>
      <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#productModal"><i class="fa-solid fa-plus me-1"></i> New Product</button>
    </div>

    <div class="card">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead>
            <tr>
              <th style="padding-left:1.25rem;">Product</th>
              <th class="text-end">Price</th>
              <th class="text-center">Stock</th>
              <th class="text-center">Reserved</th>
              <th class="text-center">Available</th>
              <th class="text-center">Status</th>
              <th class="text-end" style="padding-right:1.25rem;white-space:nowrap;width:1%;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($products['data'])): ?>
              <tr><td colspan="7" class="text-center py-5 text-muted">
                <div style="font-size:2rem;opacity:.3;"><i class="fa-solid fa-box-open"></i></div>
                <div class="mt-2">No products yet. Click <strong>+ New Product</strong> to create the first one.</div>
              </td></tr>
            <?php else: foreach ($products['data'] as $p):
              $reserved = Product::reservedStock((int) $p['id']);
              $available = max(0, (int) $p['stock'] - $reserved);
              $imgUrl = !empty($p['image_url']) ? APP_URL . '/uploads/' . e($p['image_url']) : '';
            ?>
              <tr>
                <td style="padding-left:1.25rem;">
<div class="d-flex align-items-center gap-2">
                    <?php if (!empty($p['image_url'])): ?>
                      <img src="<?= APP_URL ?>/uploads/<?= e($p['image_url']) ?>" alt="" style="width:38px;height:38px;object-fit:cover;object-position:center;border-radius:.4rem;display:block;flex-shrink:0;">
                    <?php else: ?>
                      <div style="width:38px;height:38px;flex-shrink:0;border-radius:.4rem;background:#f4f6fb;display:flex;align-items:center;justify-content:center;line-height:1;"><span style="font-size:1.1rem;line-height:1;display:flex;align-items:center;justify-content:center;">🛍️</span></div>
                    <?php endif; ?>
                    <div>
                      <div class="fw-semibold"><?= e($p['name']) ?></div>
                      <?php if (!empty($p['sku'])): ?><div class="text-muted" style="font-size:.7rem;"><?= e($p['sku']) ?></div><?php endif; ?>
                    </div>
                  </div>
                </td>
                <td class="text-end font-mono"><?= fmt_money((float) $p['price']) ?></td>
                <td class="text-center font-mono"><?= (int) $p['stock'] ?></td>
                <td class="text-center font-mono text-warning" title="Held by active orders"><?= $reserved ?></td>
                <td class="text-center font-mono <?= $available <= 0 ? 'text-danger fw-bold' : 'text-success' ?>"><?= $available ?></td>
                <td class="text-center">
                  <span data-role="status-badge" class="<?= $p['status'] === 'active'
                      ? 'badge bg-success-subtle text-success'
                      : 'badge bg-secondary-subtle text-secondary' ?>">
                    <?= $p['status'] === 'active' ? '<i class="fa-solid fa-circle-check me-1"></i>Active' : '<i class="fa-solid fa-circle-xmark me-1"></i>Inactive' ?>
                  </span>
                </td>
                <td class="text-end" style="padding-right:1.25rem;white-space:nowrap;">
                  <?php
                  $ref  = $productRefs[(int) $p['id']] ?? ['orders' => 0, 'carts' => 0];
                  $why  = match (true) {
                      $ref['orders'] > 0 && $ref['carts'] > 0
                          => 'This product has been bought and is in a cart. It cannot be deleted.',
                      $ref['orders'] > 0
                          => 'This product has been bought. It cannot be deleted.',
                      $ref['carts'] > 0
                          => "This product is in a member's cart. It cannot be deleted.",
                      default
                          => '',
                  };
                  $blocked = $why !== '';
                  ?>
                  <div class="d-inline-flex align-items-center flex-nowrap gap-2">
                    <button type="button" class="btn btn-link p-0 border-0 text-secondary product-view-btn"
                            data-bs-toggle="modal" data-bs-target="#productViewModal"
                            data-id="<?= (int)$p['id'] ?>"
                            data-name="<?= e($p['name']) ?>"
                            data-sku="<?= e($p['sku'] ?? '') ?>"
                            data-price="<?= fmt_money((float)$p['price']) ?>"
                            data-stock="<?= (int)$p['stock'] ?>"
                            data-reserved="<?= $reserved ?>"
                            data-available="<?= $available ?>"
                            data-status="<?= $p['status'] === 'active' ? 'Active' : 'Inactive' ?>"
                            data-status-class="<?= $p['status'] === 'active' ? 'text-success fw-semibold' : 'text-secondary fw-semibold' ?>"
                            data-image="<?= $imgUrl ?>"
                            data-short="<?= e($p['short_description'] ?? '') ?>"
                            data-full="<?= e($p['description'] ?? '') ?>"
                            data-created="<?= !empty($p['created_at']) ? e($p['created_at']) : '-' ?>"
                            data-updated="<?= !empty($p['updated_at']) ? e($p['updated_at']) : '-' ?>"
                            title="View product details" aria-label="View <?= e($p['name']) ?>">
                      <i class="fa-solid fa-eye"></i>
                    </button>

                    <a href="<?= link_to('admin_shop', ['edit' => $p['id']]) ?>"
                       class="text-primary d-inline-block"
                       title="Edit product" aria-label="Edit <?= e($p['name']) ?>">
                      <i class="fa-solid fa-pen-to-square"></i>
                    </a>

                    <form method="post" action="<?= link_to('admin_delete_product') ?>" class="js-product-action-form d-inline"
                          data-action="delete" data-name="<?= e($p['name']) ?>"
                          data-blocked="<?= $blocked ? '1' : '0' ?>"
                          data-reason="<?= e($why) ?>">
                      <?= csrf_field() ?>
                      <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                      <button type="submit"
                              class="btn btn-link p-0 border-0 js-product-action<?= $blocked ? ' disabled-look' : '' ?>"
                              <?= $blocked ? 'aria-disabled="true"' : '' ?>
                              title="<?= $blocked ? e($why) : 'Delete product' ?>"
                              aria-label="Delete <?= e($p['name']) ?>">
                        <i class="fa-solid fa-trash<?= $blocked ? ' text-muted' : ' text-danger' ?>"></i>
                      </button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <?php if ($products['total_pages'] > 1): ?>
        <div class="card-footer"><?= pagination_links($products, link_to('admin_shop', ['per_page' => per_page()])) ?></div>
      <?php endif; ?>
    </div>

    <div class="text-muted mt-2" style="font-size:.75rem;">
      <i class="fa-solid fa-circle-info me-1"></i> Stock is absolute inventory. It is deducted automatically when an order is handed to the courier; adjust it here for deliveries, write-offs, and recounts.
    </div>

    <style>
      .btn.disabled-look {
        opacity: .45;
        cursor: not-allowed;
        pointer-events: none;
      }
      tr.js-row-removing { opacity: 0; transform: translateX(-8px); }
      tbody tr { transition: opacity .25s ease, transform .25s ease; }
      .product-view-btn { text-decoration: none; }
      .product-view-btn:focus,
      .product-view-btn:focus-visible,
      .js-product-action:focus,
      .js-product-action:focus-visible {
        box-shadow: none !important;
        outline: none !important;
      }
    </style>

    <script>
    (function () {
      var CSRF = '<?= e(csrf_token()) ?>';
      function copyFor(action, name) {
        return {
          title: 'Delete ' + name + '?',
          message: 'This permanently removes the product from the catalog. This cannot be undone.',
          confirmText: 'Delete',
          confirmClass: 'btn-danger',
        };
      }
      document.addEventListener('submit', function (e) {
        var form = e.target.closest('.js-product-action-form');
        if (!form) return;
        e.preventDefault();
        var btn = form.querySelector('.js-product-action');
        var name = form.dataset.name || 'this product';
        var action = form.dataset.action || 'delete';
        if (action === 'delete' && form.dataset.blocked === '1') {
          if (window.showToast) showToast(form.dataset.reason || 'Cannot delete.', 'error');
          return;
        }
        var copy = copyFor(action, name);
        showConfirm({
          size: 'sm',
          title: copy.title,
          message: copy.message,
          confirmText: copy.confirmText,
          confirmClass: copy.confirmClass,
          onConfirm: function () {
            if (btn) btn.disabled = true;
            var body = new URLSearchParams(new FormData(form));
            body.set('ajax', '1');
            fetch(form.action, {
              method: 'POST',
              headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': CSRF
              },
              body: body.toString()
            })
            .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Unexpected server response.' }; }); })
            .then(function (res) {
              if (!res || !res.ok) { if (btn) btn.disabled = false; if (window.showToast) showToast(res.message || 'Could not complete.', 'error'); return; }
              var row = form.closest('tr');
              if (row) { row.classList.add('js-row-removing'); window.setTimeout(function () { row.remove(); if (window.showToast) showToast(res.message || 'Product deleted.', 'success'); }, 250); }
            })
            .catch(function () { if (btn) btn.disabled = false; if (window.showToast) showToast('Could not reach the server. Nothing was deleted.', 'error'); });
          }
        });
      });
    })();
    document.addEventListener('DOMContentLoaded', function () {
      var viewModal = document.getElementById('productViewModal');
      if (!viewModal) return;
      var lastBtn = null;
      var base = '<?= link_to('admin_shop') ?>' + '&';
      viewModal.addEventListener('shown.bs.modal', function (event) {
        var btn = event.relatedTarget;
        if (!btn) return;
        lastBtn = btn;
        document.getElementById('pv-title').textContent = btn.dataset.name || 'Product Details';
        document.getElementById('pv-name').textContent = btn.dataset.name || '';
        document.getElementById('pv-id-display').textContent = btn.dataset.id || '';
        document.getElementById('pv-sku').textContent = btn.dataset.sku || '-';
        document.getElementById('pv-price').textContent = btn.dataset.price || '₱0.00';
        document.getElementById('pv-status').textContent = btn.dataset.status || '';
        document.getElementById('pv-status').className = btn.dataset.statusClass || 'fw-semibold';
        document.getElementById('pv-stock').textContent = btn.dataset.stock || '0';
        document.getElementById('pv-reserved').textContent = btn.dataset.reserved || '0';
        document.getElementById('pv-available').textContent = btn.dataset.available || '0';
        document.getElementById('pv-short').textContent = btn.dataset.short || '';
        document.getElementById('pv-full').textContent = btn.dataset.full || '';
        document.getElementById('pv-created-display').textContent = btn.dataset.created || '-';
        document.getElementById('pv-updated-display').textContent = btn.dataset.updated || '-';
        var img = document.getElementById('pv-image');
        var noImg = document.getElementById('pv-no-image');
        if (btn.dataset.image) {
          img.src = btn.dataset.image;
          img.alt = btn.dataset.name || 'Product image';
          img.classList.remove('d-none');
          noImg.classList.add('d-none');
        } else {
          img.classList.add('d-none');
          noImg.classList.remove('d-none');
        }
        var editLink = document.getElementById('pv-edit-link');
        editLink.href = base + 'edit=' + (btn.dataset.id || '');
      });

      var editModalEl = document.getElementById('productModal');
      var editModalForm = editModalEl.querySelector('form');
      var NEW_TITLE = '<i class="fa-solid fa-plus me-2"></i>New Product';
      var EDIT_TITLE = '<i class="fa-solid fa-pen-to-square me-2"></i>Edit Product';
      var CREATE_BTN = '<i class="fa-solid fa-plus me-1"></i>Create Product';
      var UPDATE_BTN = '<i class="fa-solid fa-check me-1"></i>Update Product';
      var titleEl = document.getElementById('editModalTitle');
      var submitEl = document.getElementById('editModalSubmit');
      var imgWrap = document.getElementById('editImagePreviewWrap');
      var imgEl = document.getElementById('editImagePreview');
      var imgCbox = document.getElementById('removeImage');

      function setField(sel, v) {
        var el = editModalForm.querySelector(sel);
        if (el) el.value = (v === null || v === undefined) ? '' : String(v);
      }

      function resetEditForm() {
        ['product_id', 'name', 'sku', 'price', 'stock', 'short_description', 'description'].forEach(function (n) {
          setField('[name="' + n + '"]', '');
        });
        var status = editModalForm.querySelector('[name="status"]');
        if (status) status.value = 'active';
        var file = editModalForm.querySelector('[name="image"]');
        if (file) file.value = '';
        if (imgEl) imgEl.src = '';
        if (imgWrap) imgWrap.style.display = 'none';
        if (imgCbox) imgCbox.checked = false;
        if (titleEl) titleEl.innerHTML = NEW_TITLE;
        if (submitEl) submitEl.innerHTML = CREATE_BTN;
      }

      var editBtn = document.getElementById('pv-edit-link');
      if (editBtn) editBtn.addEventListener('click', function (e) {
        if (!lastBtn) return;
        e.preventDefault();
        var d = lastBtn.dataset;
        setField('[name="product_id"]', d.id || '');
        setField('[name="name"]', d.name || '');
        setField('[name="sku"]', d.sku || '');
        var priceStr = String(d.price || '').replace(/[^\d.]/g, '');
        setField('[name="price"]', priceStr === '' ? '' : String(parseFloat(priceStr)));
        setField('[name="stock"]', d.stock || '');
        setField('[name="short_description"]', d.short || '');
        setField('[name="description"]', d.full || '');
        var statusSel = editModalForm.querySelector('[name="status"]');
        if (statusSel) statusSel.value = d.status === 'Active' ? 'active' : 'inactive';
        if (titleEl) titleEl.innerHTML = EDIT_TITLE;
        if (submitEl) submitEl.innerHTML = UPDATE_BTN;
        if (imgEl) imgEl.src = d.image || '';
        if (imgWrap) imgWrap.style.display = d.image ? '' : 'none';
        if (imgCbox) imgCbox.checked = false;
        var vm = bootstrap.Modal.getInstance(viewModal);
        if (vm) vm.hide();
        var em = bootstrap.Modal.getOrCreateInstance(editModalEl);
        em.show();
      });

      function stripEditParam() {
        var u = new URL(window.location.href);
        if (u.searchParams.has('edit')) {
          u.searchParams.delete('edit');
          history.replaceState(null, '', u.toString());
        }
      }

      editModalEl.addEventListener('hidden.bs.modal', function () {
        resetEditForm();
        stripEditParam();
      });
    });
    </script>
  </div>
</div>

<!-- PRODUCT MODAL (create/edit) -->
<div class="modal fade" id="productModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <form method="post" action="<?= link_to('admin_save_product') ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="product_id" value="<?= e($editProduct['id'] ?? '') ?>">
        <div class="modal-header">
          <h5 class="modal-title" id="editModalTitle"><?= ($editProduct ?? null) ? '<i class="fa-solid fa-pen-to-square me-2"></i>Edit Product' : '<i class="fa-solid fa-plus me-2"></i>New Product' ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label">Name *</label>
              <input type="text" name="name" class="form-control" maxlength="160" value="<?= e($editProduct['name'] ?? '') ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">SKU</label>
              <input type="text" name="sku" class="form-control" maxlength="60" value="<?= e($editProduct['sku'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Price (₱) *</label>
              <input type="number" name="price" class="form-control" min="0" step="0.01" value="<?= e($editProduct['price'] ?? '') ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Stock</label>
              <input type="number" name="stock" class="form-control" min="0" value="<?= e($editProduct['stock'] ?? 0) ?>">
              <div class="form-text">Absolute inventory — deducted at courier handoff.</div>
            </div>
            <div class="col-12">
              <label class="form-label">Short description</label>
              <input type="text" name="short_description" class="form-control" maxlength="255" value="<?= e($editProduct['short_description'] ?? '') ?>">
            </div>
            <div class="col-12">
              <label class="form-label">Full description</label>
              <textarea name="description" class="form-control" rows="4"><?= e($editProduct['description'] ?? '') ?></textarea>
            </div>
            <div class="col-12">
              <label class="form-label">Image</label>
              <div class="mb-2" id="editImagePreviewWrap"<?= !empty($editProduct['image_url']) ? '' : ' style="display:none;"' ?>>
                <img id="editImagePreview" src="<?= !empty($editProduct['image_url']) ? APP_URL . '/uploads/' . e($editProduct['image_url']) : '' ?>" alt="" style="width:72px;height:72px;object-fit:cover;border-radius:.5rem;">
                <div class="form-check mt-1">
                  <input class="form-check-input" type="checkbox" name="remove_image" id="removeImage" value="1">
                  <label class="form-check-label" for="removeImage" style="font-size:.82rem;">Remove current image</label>
                </div>
              </div>
              <input type="file" name="image" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
              <div class="form-text">JPG, PNG, GIF or WebP · max 5 MB<?= ($editProduct ?? null) ? ' · leave empty to keep the current image' : '' ?>.</div>
            </div>
            <div class="col-md-4">
              <label class="form-label">Status</label>
              <select name="status" class="form-select">
                <option value="active" <?= (($editProduct['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= (($editProduct['status'] ?? '') === 'inactive') ? 'selected' : '' ?>>Inactive</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <a href="<?= link_to('admin_shop') ?>" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</a>
          <button type="submit" id="editModalSubmit" class="btn btn-primary"><?= ($editProduct ?? null) ? '<i class="fa-solid fa-check me-1"></i>Update Product' : '<i class="fa-solid fa-plus me-1"></i>Create Product' ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- PRODUCT VIEW MODAL -->
<div class="modal fade" id="productViewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content border-0 shadow">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title d-flex align-items-center gap-2">
          <i class="fa-solid fa-box text-primary"></i>
          <span id="pv-title">Product Details</span>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body py-4">
        <div class="row g-4">
          <div class="col-md-5">
            <div class="card border-0 bg-light h-100">
              <div class="card-body d-flex align-items-center justify-content-center p-4">
                <img id="pv-image" src="" alt="Product image"
                     class="img-fluid rounded shadow-sm d-none"
                     style="max-height:320px; object-fit:cover; width:100%;">
                <div id="pv-no-image" class="text-center text-muted">
                  <div style="font-size:4rem;line-height:1;">🛍️</div>
                  <div class="mt-2 small">No image available</div>
                </div>
              </div>
            </div>
          </div>
          <div class="col-md-7">
            <div class="h-100">
              <div class="card-body p-0">
                <div class="row g-3">
                  <div class="col-12">
                    <h4 id="pv-name" class="fw-bold mb-1"></h4>
                    <div class="text-muted small">SKU: <span id="pv-sku" class="font-mono">-</span></div>
                  </div>
                  <div class="col-sm-6">
                    <div class="border rounded p-2">
                      <div class="text-muted small">Price</div>
                      <div id="pv-price" class="fs-5 fw-semibold text-primary font-mono"></div>
                    </div>
                  </div>
                  <div class="col-sm-6">
                    <div class="border rounded p-2">
                      <div class="text-muted small">Status</div>
                      <div id="pv-status"></div>
                    </div>
                  </div>
                  <div class="col-sm-4">
                    <div class="border rounded p-2">
                      <div class="text-muted small">Stock</div>
                      <div id="pv-stock" class="fw-semibold font-mono"></div>
                    </div>
                  </div>
                  <div class="col-sm-4">
                    <div class="border rounded p-2">
                      <div class="text-muted small">Reserved</div>
                      <div id="pv-reserved" class="fw-semibold font-mono text-warning"></div>
                    </div>
                  </div>
                  <div class="col-sm-4">
                    <div class="border rounded p-2">
                      <div class="text-muted small">Available</div>
                      <div id="pv-available" class="fw-semibold font-mono"></div>
                    </div>
                  </div>
                  <div class="col-12">
                    <div class="border rounded p-2">
                      <div class="text-muted small mb-1">Short Description</div>
                      <div id="pv-short" class="text-body"></div>
                    </div>
                  </div>
                  <div class="col-12">
                    <div class="border rounded p-2">
                      <div class="text-muted small mb-1">Full Description</div>
                      <div id="pv-full" class="text-body" style="white-space:pre-wrap;"></div>
                    </div>
                  </div>
                  <div class="col-12">
                    <hr class="my-2">
                    <div class="d-flex flex-wrap gap-3 small text-muted">
                      <div><strong>Product ID:</strong> <span id="pv-id-display" class="font-mono"></span></div>
                      <div><strong>Created:</strong> <span id="pv-created-display"></span></div>
                      <div><strong>Updated:</strong> <span id="pv-updated-display"></span></div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
        <a id="pv-edit-link" href="#" class="btn btn-primary">
          <i class="fa-solid fa-pen-to-square me-1"></i> Edit Product
        </a>
      </div>
    </div>
  </div>
</div>

<?php if ($editProduct ?? null): ?>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    new bootstrap.Modal(document.getElementById('productModal')).show();
  });
</script>
<?php endif; ?>
<?php require 'views/partials/footer.php'; ?>
