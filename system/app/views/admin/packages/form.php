<?php
declare(strict_types=1);

/**
 * Admin package form — create and edit share this page
 * (plan §7 Phase 3; reference views/admin/packages.php, binary fields dropped).
 *
 * Level 1 is the direct referral bonus and is rendered read-only,
 * bound to the "Direct referral bonus" field; the editable matrix is
 * levels 2–10 only.
 *
 * @var array<string, mixed>|null $package
 * @var array<string, mixed>       $current
 * @var array<int, string>         $levels            level 2–10 => posted/DB value
 * @var list<array{product_id: int|string, quantity: int|string}> $contents
 * @var list<array{id:int, name:string, sku:?string, price:string, status:string}> $content_options
 */

$isEdit  = $package !== null;
$id      = $isEdit ? (int) $package['id'] : 0;
$image   = UploadService::url($current['image'] ?? null);
$slug    = $isEdit ? (string) ($package['slug'] ?? '') : '';
$errors  = errors_all();

$directBonus = (string) ($current['direct_referral_bonus'] ?? '');

$renderOptions = static function (int|string $selected) use ($content_options): string {
    $html = '<option value="">— choose a product —</option>';

    foreach ($content_options as $option) {
        $isSelected = (int) $selected === (int) $option['id'];
        $label      = $option['name'] . ($option['status'] !== 'active' ? ' (inactive)' : '');

        $html .= '<option value="' . (int) $option['id'] . '"' . ($isSelected ? ' selected' : '') . '>'
            . e($label) . '</option>';
    }

    return $html;
};

$contentRows = $contents === [] ? [['product_id' => 0, 'quantity' => 1]] : $contents;
?>
            <div class="container-xxl flex-grow-1 container-p-y">
              <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-6">
                <div>
                  <h4 class="mb-1"><?= $isEdit ? 'Edit package' : 'New package' ?></h4>
                  <p class="text-body-secondary mb-0">
                    <?= $isEdit
                        ? 'Update the package, its referral ladder and its contents.'
                        : 'Define an associate package, the referral amounts it pays and what it contains.' ?>
                  </p>
                </div>
                <a href="<?= e(url('admin/packages')) ?>" class="btn btn-label-secondary">
                  <i class="icon-base bx bx-arrow-back me-1"></i> Back to packages
                </a>
              </div>

<?php if ($errors !== []): ?>
              <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
                <i class="icon-base bx bx-error-circle fs-4"></i>
                <div>Please fix the highlighted field<?= count($errors) === 1 ? '' : 's' ?> and try again.</div>
              </div>
<?php endif; ?>

              <form method="POST" action="<?= e(url('admin/packages/save')) ?>" enctype="multipart/form-data" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="package_id" value="<?= $id ?>" />

                <div class="row g-6">
                  <div class="col-xl-8">
                    <div class="card mb-6">
                      <div class="card-header"><h5 class="mb-0">Package details</h5></div>
                      <div class="card-body">
                        <div class="row">
                          <div class="col-md-8 mb-4">
                            <label for="packageName" class="form-label">Package name <span class="text-danger">*</span></label>
                            <input
                              type="text"
                              class="form-control <?= e(invalid_class('name')) ?>"
                              id="packageName"
                              name="name"
                              maxlength="80"
                              value="<?= e((string) ($current['name'] ?? '')) ?>"
                              required
                            />
<?php if (has_error('name')): ?>
                            <div class="invalid-feedback d-block"><?= e(error_for('name')) ?></div>
<?php endif; ?>
                          </div>

                          <div class="col-md-4 mb-4">
                            <label for="packageSlug" class="form-label">Slug</label>
                            <input
                              type="text"
                              class="form-control"
                              id="packageSlug"
                              value="<?= e($slug !== '' ? $slug : 'Generated from the name') ?>"
                              readonly
                            />
                            <div class="form-text"><?= $isEdit ? 'Stable after creation.' : 'Set automatically on create.' ?></div>
                          </div>
                        </div>

                        <div class="mb-4">
                          <label for="packageDescription" class="form-label">Description</label>
                          <textarea
                            class="form-control <?= e(invalid_class('description')) ?>"
                            id="packageDescription"
                            name="description"
                            rows="3"
                            maxlength="5000"
                          ><?= e((string) ($current['description'] ?? '')) ?></textarea>
<?php if (has_error('description')): ?>
                          <div class="invalid-feedback d-block"><?= e(error_for('description')) ?></div>
<?php endif; ?>
                        </div>

                        <div class="mb-0">
                          <label for="packagePerks" class="form-label">Perks</label>
                          <textarea
                            class="form-control <?= e(invalid_class('perks')) ?>"
                            id="packagePerks"
                            name="perks"
                            rows="4"
                            maxlength="5000"
                          ><?= e((string) ($current['perks'] ?? '')) ?></textarea>
<?php if (has_error('perks')): ?>
                          <div class="invalid-feedback d-block"><?= e(error_for('perks')) ?></div>
<?php endif; ?>
                          <div class="form-text">One perk per line — shown as a list on the packages page.</div>
                        </div>
                      </div>
                    </div>

                    <div class="card mb-6">
                      <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <h5 class="mb-0">Referral ladder</h5>
                        <span class="badge bg-label-primary">Levels 1–10</span>
                      </div>
                      <div class="card-body">
                        <p class="text-body-secondary small mb-4">
                          Level 1 is the <strong>direct referral bonus</strong> — it is saved once, on the package.
                          Levels 2–10 are the unilevel amounts paid further up the sponsor chain.
                        </p>

                        <div class="table-responsive">
                          <table class="table align-middle mb-0">
                            <thead>
                              <tr>
                                <th scope="col" style="width: 30%">Level</th>
                                <th scope="col">Amount</th>
                                <th scope="col" style="width: 34%">Note</th>
                              </tr>
                            </thead>
                            <tbody>
                              <tr class="table-active">
                                <td class="fw-medium">Level 1 — Direct referral</td>
                                <td>
                                  <span class="fw-semibold" id="level1Display"><?= e(money((float) $directBonus)) ?></span>
                                </td>
                                <td class="small text-body-secondary">Read-only — edit the direct bonus</td>
                              </tr>
<?php for ($level = Package::LEVEL_MIN; $level <= Package::LEVEL_MAX; $level++): ?>
<?php $value = (string) ($levels[$level] ?? ''); ?>
                              <tr>
                                <td>Level <?= $level ?></td>
                                <td>
                                  <div class="input-group input-group-sm" style="max-width: 12rem">
                                    <span class="input-group-text"><?= e(CURRENCY_SYMBOL) ?></span>
                                    <input
                                      type="number"
                                      class="form-control <?= e(invalid_class('level_' . $level)) ?>"
                                      name="levels[<?= $level ?>]"
                                      min="0"
                                      max="1000000"
                                      step="0.01"
                                      value="<?= e($value) ?>"
                                      aria-label="Level <?= $level ?> amount"
                                    />
                                  </div>
<?php if (has_error('level_' . $level)): ?>
                                  <div class="invalid-feedback d-block"><?= e(error_for('level_' . $level)) ?></div>
<?php endif; ?>
                                </td>
                                <td class="small text-body-secondary"><?= $level <= 3 ? 'Pays the upline ' . ($level - 1) . ' step' . ($level === 2 ? '' : 's') . ' above the sponsor' : '' ?></td>
                              </tr>
<?php endfor; ?>
                            </tbody>
                          </table>
                        </div>
                      </div>
                    </div>

                    <div class="card">
                      <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <h5 class="mb-0">Package contents</h5>
                        <button type="button" class="btn btn-sm btn-label-primary js-add-content">
                          <i class="icon-base bx bx-plus me-1"></i> Add product
                        </button>
                      </div>
                      <div class="card-body">
<?php if (has_error('contents')): ?>
                        <div class="alert alert-danger py-2" role="alert"><?= e(error_for('contents')) ?></div>
<?php endif; ?>

                        <div class="js-content-rows">
<?php foreach ($contentRows as $row): ?>
                          <div class="row g-2 align-items-start mb-2 js-content-row">
                            <div class="col-md-7">
                              <select name="content_product_id[]" class="form-select form-select-sm" aria-label="Product">
                                <?= $renderOptions($row['product_id'] ?? 0) ?>
                              </select>
                            </div>
                            <div class="col-5 col-md-3">
                              <input
                                type="number"
                                name="content_quantity[]"
                                class="form-control form-control-sm"
                                min="1"
                                max="1000"
                                step="1"
                                value="<?= (int) ($row['quantity'] ?? 1) ?>"
                                aria-label="Quantity"
                              />
                            </div>
                            <div class="col-2 col-md-2">
                              <button type="button" class="btn btn-sm btn-icon btn-label-danger js-remove-content" title="Remove">
                                <i class="icon-base bx bx-trash"></i>
                              </button>
                            </div>
                          </div>
<?php endforeach; ?>
                        </div>

                        <p class="text-body-secondary small mb-0 mt-2">
                          Only active products can be added. Pick the same product twice and the quantities are merged.
                        </p>
                      </div>
                    </div>
                  </div>

                  <div class="col-xl-4">
                    <div class="card mb-6">
                      <div class="card-header"><h5 class="mb-0">Pricing &amp; bonuses</h5></div>
                      <div class="card-body">
                        <div class="mb-4">
                          <label for="packagePrice" class="form-label">Price <span class="text-danger">*</span></label>
                          <div class="input-group">
                            <span class="input-group-text"><?= e(CURRENCY_SYMBOL) ?></span>
                            <input
                              type="number"
                              class="form-control <?= e(invalid_class('price')) ?>"
                              id="packagePrice"
                              name="price"
                              min="0.01"
                              step="0.01"
                              value="<?= e((string) ($current['price'] ?? '')) ?>"
                              required
                            />
                          </div>
<?php if (has_error('price')): ?>
                          <div class="invalid-feedback d-block"><?= e(error_for('price')) ?></div>
<?php endif; ?>
                        </div>

                        <div class="mb-4">
                          <label for="packageValue" class="form-label">Product value</label>
                          <div class="input-group">
                            <span class="input-group-text"><?= e(CURRENCY_SYMBOL) ?></span>
                            <input
                              type="number"
                              class="form-control <?= e(invalid_class('product_value')) ?>"
                              id="packageValue"
                              name="product_value"
                              min="0"
                              step="0.01"
                              value="<?= e((string) ($current['product_value'] ?? '')) ?>"
                            />
                          </div>
<?php if (has_error('product_value')): ?>
                          <div class="invalid-feedback d-block"><?= e(error_for('product_value')) ?></div>
<?php endif; ?>
                          <div class="form-text">Display value of the bundle.</div>
                        </div>

                        <div class="mb-4">
                          <label for="packageDirect" class="form-label">Direct referral bonus (level 1) <span class="text-danger">*</span></label>
                          <div class="input-group">
                            <span class="input-group-text"><?= e(CURRENCY_SYMBOL) ?></span>
                            <input
                              type="number"
                              class="form-control <?= e(invalid_class('direct_referral_bonus')) ?>"
                              id="packageDirect"
                              name="direct_referral_bonus"
                              min="0"
                              step="0.01"
                              value="<?= e((string) ($current['direct_referral_bonus'] ?? '')) ?>"
                              required
                            />
                          </div>
<?php if (has_error('direct_referral_bonus')): ?>
                          <div class="invalid-feedback d-block"><?= e(error_for('direct_referral_bonus')) ?></div>
<?php endif; ?>
                          <div class="form-text">Paid to the sponsor. It is the Level 1 row of the ladder.</div>
                        </div>

                        <div class="mb-4">
                          <label for="packageSort" class="form-label">Sort order</label>
                          <input
                            type="number"
                            class="form-control <?= e(invalid_class('sort_order')) ?>"
                            id="packageSort"
                            name="sort_order"
                            min="0"
                            step="1"
                            value="<?= e((string) ($current['sort_order'] ?? '0')) ?>"
                          />
<?php if (has_error('sort_order')): ?>
                          <div class="invalid-feedback d-block"><?= e(error_for('sort_order')) ?></div>
<?php endif; ?>
                          <div class="form-text">Lower numbers appear first.</div>
                        </div>

                        <div class="mb-4">
                          <label for="packageStatus" class="form-label">Status <span class="text-danger">*</span></label>
<?php $currentStatus = (string) ($current['status'] ?? 'active'); ?>
                          <select class="form-select <?= e(invalid_class('status')) ?>" id="packageStatus" name="status">
                            <option value="active" <?= $currentStatus === 'active' ? 'selected' : '' ?>>Active — offered to members</option>
                            <option value="inactive" <?= $currentStatus === 'inactive' ? 'selected' : '' ?>>Inactive — hidden from members</option>
                          </select>
<?php if (has_error('status')): ?>
                          <div class="invalid-feedback d-block"><?= e(error_for('status')) ?></div>
<?php endif; ?>
                        </div>

                        <div class="form-check form-switch mb-0">
                          <input type="hidden" name="indirect_enabled" value="0" />
                          <input
                            class="form-check-input"
                            type="checkbox"
                            role="switch"
                            id="packageIndirect"
                            name="indirect_enabled"
                            value="1"
                            <?= (string) ($current['indirect_enabled'] ?? '1') === '1' ? 'checked' : '' ?>
                          />
                          <label class="form-check-label" for="packageIndirect">Pay the 2–10 unilevel ladder</label>
                        </div>
                      </div>
                    </div>

                    <div class="card">
                      <div class="card-header"><h5 class="mb-0">Image</h5></div>
                      <div class="card-body">
                        <div class="mb-4 text-center">
                          <img
                            id="packageImagePreview"
                            src="<?= e($image ?? '') ?>"
                            alt="Package image preview"
                            class="rounded w-100 <?= $image === null ? 'd-none' : '' ?>"
                            style="max-height: 180px; object-fit: contain; background: #f4f6fb"
                          />
                          <div id="packageImageEmpty" class="rounded d-flex align-items-center justify-content-center text-body-secondary <?= $image !== null ? 'd-none' : '' ?>" style="height: 140px; background: #f4f6fb">
                            <div class="text-center">
                              <i class="icon-base bx bx-image-add icon-xl d-block mb-1"></i>
                              <span class="small">No image yet</span>
                            </div>
                          </div>
                        </div>

                        <div class="mb-3">
                          <label for="packageImage" class="form-label">Upload image</label>
                          <input
                            type="file"
                            class="form-control <?= e(invalid_class('image')) ?>"
                            id="packageImage"
                            name="image"
                            accept="image/jpeg,image/png,image/webp"
                          />
<?php if (has_error('image')): ?>
                          <div class="invalid-feedback d-block"><?= e(error_for('image')) ?></div>
<?php endif; ?>
                          <div class="form-text">JPG, PNG or WebP, up to 5 MB.</div>
                        </div>

<?php if ($image !== null): ?>
                        <div class="form-check mb-0">
                          <input class="form-check-input" type="checkbox" value="1" id="removePackageImage" name="remove_image" />
                          <label class="form-check-label" for="removePackageImage">Remove the current image</label>
                        </div>
<?php endif; ?>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="d-flex flex-wrap gap-2 mt-6">
                  <button type="submit" class="btn btn-primary">
                    <i class="icon-base bx bx-check me-1"></i> <?= $isEdit ? 'Save changes' : 'Create package' ?>
                  </button>
                  <a href="<?= e(url('admin/packages')) ?>" class="btn btn-label-secondary">Cancel</a>
                </div>
              </form>
            </div>

            <template id="contentRowTemplate">
              <div class="row g-2 align-items-start mb-2 js-content-row">
                <div class="col-md-7">
                  <select name="content_product_id[]" class="form-select form-select-sm" aria-label="Product">
                    <?= $renderOptions(0) ?>
                  </select>
                </div>
                <div class="col-5 col-md-3">
                  <input type="number" name="content_quantity[]" class="form-control form-control-sm" min="1" max="1000" step="1" value="1" aria-label="Quantity" />
                </div>
                <div class="col-2 col-md-2">
                  <button type="button" class="btn btn-sm btn-icon btn-label-danger js-remove-content" title="Remove">
                    <i class="icon-base bx bx-trash"></i>
                  </button>
                </div>
              </div>
            </template>

            <script>
              document.addEventListener('DOMContentLoaded', function () {
                /* Level 1 mirrors the direct bonus field. */
                var direct = document.getElementById('packageDirect');
                var level1 = document.getElementById('level1Display');
                if (direct && level1) {
                  var render = function () {
                    var value = parseFloat(direct.value || '0');
                    if (isNaN(value)) value = 0;
                    level1.textContent = '₱' + value.toLocaleString('en-PH', {
                      minimumFractionDigits: 2,
                      maximumFractionDigits: 2
                    });
                  };
                  direct.addEventListener('input', render);
                  render();
                }

                /* Contents repeater. */
                var rows = document.querySelector('.js-content-rows');
                var template = document.getElementById('contentRowTemplate');
                var addButton = document.querySelector('.js-add-content');
                if (!rows || !template || !addButton) return;

                addButton.addEventListener('click', function () {
                  rows.appendChild(template.content.cloneNode(true));
                });

                rows.addEventListener('click', function (event) {
                  var button = event.target.closest('.js-remove-content');
                  if (!button) return;
                  var row = button.closest('.js-content-row');
                  if (row) row.remove();
                });
              });
            </script>
