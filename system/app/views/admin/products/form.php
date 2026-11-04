<?php
declare(strict_types=1);

/**
 * Admin product form — create and edit share this page
 * (plan §7 Phase 2; reference views/admin/shop_products.php + the
 * theme's app-ecommerce-product-add.html layout).
 *
 * @var array<string, mixed>|null $product Existing row when editing
 * @var array<string, mixed>       $current Values to render (old input wins below)
 */

$isEdit  = $product !== null;
$id      = $isEdit ? (int) $product['id'] : 0;
$image   = UploadService::url($current['image_url'] ?? null);
$slug    = $isEdit ? (string) ($product['slug'] ?? '') : '';
$errors  = errors_all();
?>
            <div class="container-xxl flex-grow-1 container-p-y">
              <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-6">
                <div>
                  <h4 class="mb-1"><?= $isEdit ? 'Edit product' : 'New product' ?></h4>
                  <p class="text-body-secondary mb-0">
                    <?= $isEdit
                        ? 'Update the catalogue entry — changes show in the shop immediately.'
                        : 'Add a product to the catalogue. It goes live as soon as it is saved as active.' ?>
                  </p>
                </div>
                <a href="<?= e(url('admin/products')) ?>" class="btn btn-label-secondary">
                  <i class="icon-base bx bx-arrow-back me-1"></i> Back to products
                </a>
              </div>

<?php if ($errors !== []): ?>
              <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
                <i class="icon-base bx bx-error-circle fs-4"></i>
                <div>Please fix the highlighted field<?= count($errors) === 1 ? '' : 's' ?> and try again.</div>
              </div>
<?php endif; ?>

              <form method="POST" action="<?= e(url('admin/products/save')) ?>" enctype="multipart/form-data" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="product_id" value="<?= $id ?>" />

                <div class="row g-6">
                  <div class="col-xl-8">
                    <div class="card mb-6">
                      <div class="card-header"><h5 class="mb-0">Details</h5></div>
                      <div class="card-body">
                        <div class="mb-4">
                          <label for="productName" class="form-label">Product name <span class="text-danger">*</span></label>
                          <input
                            type="text"
                            class="form-control <?= e(invalid_class('name')) ?>"
                            id="productName"
                            name="name"
                            maxlength="160"
                            value="<?= old('name', (string) ($current['name'] ?? '')) ?>"
                            required
                          />
<?php if (has_error('name')): ?>
                          <div class="invalid-feedback d-block"><?= e(error_for('name')) ?></div>
<?php endif; ?>
                          <div class="form-text">Between 2 and 160 characters.</div>
                        </div>

                        <div class="row">
                          <div class="col-md-6 mb-4">
                            <label for="productSku" class="form-label">SKU</label>
                            <input
                              type="text"
                              class="form-control <?= e(invalid_class('sku')) ?>"
                              id="productSku"
                              name="sku"
                              maxlength="64"
                              pattern="[A-Za-z0-9._\-]+"
                              value="<?= old('sku', (string) ($current['sku'] ?? '')) ?>"
                            />
<?php if (has_error('sku')): ?>
                            <div class="invalid-feedback d-block"><?= e(error_for('sku')) ?></div>
<?php endif; ?>
                            <div class="form-text">Optional, unique. Letters, numbers, dot, dash, underscore.</div>
                          </div>

                          <div class="col-md-6 mb-4">
                            <label for="productSlug" class="form-label">Slug</label>
                            <input
                              type="text"
                              class="form-control"
                              id="productSlug"
                              value="<?= e($slug !== '' ? $slug : 'Generated from the name') ?>"
                              readonly
                            />
                            <div class="form-text"><?= $isEdit ? 'Stable after creation.' : 'Set automatically when the product is created.' ?></div>
                          </div>
                        </div>

                        <div class="mb-4">
                          <label for="productShort" class="form-label">Short description</label>
                          <input
                            type="text"
                            class="form-control <?= e(invalid_class('short_description')) ?>"
                            id="productShort"
                            name="short_description"
                            maxlength="255"
                            value="<?= old('short_description', (string) ($current['short_description'] ?? '')) ?>"
                          />
<?php if (has_error('short_description')): ?>
                          <div class="invalid-feedback d-block"><?= e(error_for('short_description')) ?></div>
<?php endif; ?>
                          <div class="form-text">One line shown on the product card.</div>
                        </div>

                        <div class="mb-2">
                          <label for="productDescription" class="form-label">Description</label>
                          <textarea
                            class="form-control <?= e(invalid_class('description')) ?>"
                            id="productDescription"
                            name="description"
                            rows="7"
                            maxlength="5000"
                          ><?= old('description', (string) ($current['description'] ?? '')) ?></textarea>
<?php if (has_error('description')): ?>
                          <div class="invalid-feedback d-block"><?= e(error_for('description')) ?></div>
<?php endif; ?>
                          <div class="form-text">Up to 5,000 characters. Shown on the product detail page.</div>
                        </div>
                      </div>
                    </div>
                  </div>

                  <div class="col-xl-4">
                    <div class="card mb-6">
                      <div class="card-header"><h5 class="mb-0">Pricing &amp; stock</h5></div>
                      <div class="card-body">
                        <div class="mb-4">
                          <label for="productPrice" class="form-label">Price <span class="text-danger">*</span></label>
                          <div class="input-group">
                            <span class="input-group-text"><?= e(CURRENCY_SYMBOL) ?></span>
                            <input
                              type="number"
                              class="form-control <?= e(invalid_class('price')) ?>"
                              id="productPrice"
                              name="price"
                              min="0.01"
                              step="0.01"
                              value="<?= old('price', $isEdit ? number_format((float) $product['price'], 2, '.', '') : '') ?>"
                              required
                            />
                          </div>
<?php if (has_error('price')): ?>
                          <div class="invalid-feedback d-block"><?= e(error_for('price')) ?></div>
<?php endif; ?>
                        </div>

                        <div class="mb-4">
                          <label for="productStock" class="form-label">Stock on hand <span class="text-danger">*</span></label>
                          <input
                            type="number"
                            class="form-control <?= e(invalid_class('stock')) ?>"
                            id="productStock"
                            name="stock"
                            min="0"
                            step="1"
                            value="<?= old('stock', (string) ($current['stock'] ?? '0')) ?>"
                            required
                          />
<?php if (has_error('stock')): ?>
                          <div class="invalid-feedback d-block"><?= e(error_for('stock')) ?></div>
<?php endif; ?>
                          <div class="form-text">Physical count. Orders reserve stock in a later phase.</div>
                        </div>

                        <div class="mb-4">
                          <label for="productSort" class="form-label">Sort order</label>
                          <input
                            type="number"
                            class="form-control <?= e(invalid_class('sort_order')) ?>"
                            id="productSort"
                            name="sort_order"
                            min="0"
                            step="1"
                            value="<?= old('sort_order', (string) ($current['sort_order'] ?? '0')) ?>"
                          />
<?php if (has_error('sort_order')): ?>
                          <div class="invalid-feedback d-block"><?= e(error_for('sort_order')) ?></div>
<?php endif; ?>
                          <div class="form-text">Lower numbers appear first.</div>
                        </div>

                        <div class="mb-0">
                          <label for="productStatus" class="form-label">Status <span class="text-danger">*</span></label>
<?php $currentStatus = old('status', (string) ($current['status'] ?? 'active')); ?>
                          <select class="form-select <?= e(invalid_class('status')) ?>" id="productStatus" name="status">
                            <option value="active" <?= $currentStatus === 'active' ? 'selected' : '' ?>>Active — visible in the shop</option>
                            <option value="inactive" <?= $currentStatus === 'inactive' ? 'selected' : '' ?>>Inactive — hidden from the shop</option>
                          </select>
<?php if (has_error('status')): ?>
                          <div class="invalid-feedback d-block"><?= e(error_for('status')) ?></div>
<?php endif; ?>
                        </div>
                      </div>
                    </div>

                    <div class="card">
                      <div class="card-header"><h5 class="mb-0">Image</h5></div>
                      <div class="card-body">
                        <div class="mb-4 text-center">
                          <img
                            id="imagePreview"
                            src="<?= e($image ?? '') ?>"
                            alt="Product image preview"
                            class="rounded w-100 <?= $image === null ? 'd-none' : '' ?>"
                            style="max-height: 220px; object-fit: contain; background: #f4f6fb"
                          />
                          <div id="imagePreviewEmpty" class="rounded d-flex align-items-center justify-content-center text-body-secondary <?= $image !== null ? 'd-none' : '' ?>" style="height: 160px; background: #f4f6fb">
                            <div class="text-center">
                              <img
                                src="<?= e(url('assets/img/ecowealth-mark.png')) ?>"
                                alt=""
                                aria-hidden="true"
                                class="admin-image-placeholder d-block mx-auto mb-2"
                              />
                              <span class="small">No image yet</span>
                            </div>
                          </div>
                        </div>

                        <div class="mb-3">
                          <label for="productImage" class="form-label">Upload image</label>
                          <input
                            type="file"
                            class="form-control <?= e(invalid_class('image')) ?>"
                            id="productImage"
                            name="image"
                            accept="image/jpeg,image/png,image/webp"
                          />
<?php if (has_error('image')): ?>
                          <div class="invalid-feedback d-block"><?= e(error_for('image')) ?></div>
<?php endif; ?>
                          <div class="form-text">JPG, PNG or WebP, up to 5 MB. Uploading replaces the current image.</div>
                        </div>

<?php if ($image !== null): ?>
                        <div class="form-check mb-0">
                          <input class="form-check-input" type="checkbox" value="1" id="removeImage" name="remove_image" />
                          <label class="form-check-label" for="removeImage">Remove the current image</label>
                        </div>
<?php endif; ?>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="d-flex flex-wrap gap-2 mt-6">
                  <button type="submit" class="btn btn-primary">
                    <i class="icon-base bx bx-check me-1"></i> <?= $isEdit ? 'Save changes' : 'Create product' ?>
                  </button>
                  <a href="<?= e(url('admin/products')) ?>" class="btn btn-label-secondary">Cancel</a>
                </div>
              </form>
            </div>

            <script>
              document.addEventListener('DOMContentLoaded', function () {
                var input = document.getElementById('productImage');
                var preview = document.getElementById('imagePreview');
                var empty = document.getElementById('imagePreviewEmpty');
                if (!input || !preview || !empty) return;

                input.addEventListener('change', function () {
                  var file = input.files && input.files[0];
                  if (!file) return;
                  if (!/^image\/(jpeg|png|webp)$/.test(file.type)) return;

                  preview.src = URL.createObjectURL(file);
                  preview.classList.remove('d-none');
                  empty.classList.add('d-none');
                });
              });
            </script>
