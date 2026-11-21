<?php
declare(strict_types=1);

/**
 * Checkout — the saved-address picker.
 *
 * The wrapper is always present so the card can appear live after the
 * first address is saved (or disappear after the last one is deleted).
 * The card holds only links, never a text input, so it is safe to replace
 * while the delivery form keeps the member's unsaved input.
 *
 * @var list<array<string, mixed>> $addresses
 * @var int $selected_id
 */

$addresses   = $addresses ?? [];
$selected_id = (int) ($selected_id ?? 0);
?>
                    <div data-live-region="checkout-addresses"<?= $addresses === [] ? ' hidden' : '' ?>>
<?php if ($addresses !== []): ?>
                      <div class="card mb-6 checkout-card">
                        <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                          <div>
                            <h5 class="mb-0">Saved addresses</h5>
                            <p class="text-body-secondary small mb-0">Pick one to fill the form below, or edit it first.</p>
                          </div>
                          <a href="<?= e(url('profile')) ?>" class="btn btn-sm btn-label-primary">
                            <i class="icon-base bx bx-plus me-1"></i> Manage addresses
                          </a>
                        </div>
                        <div class="card-body">
                          <div class="row g-3">
<?php foreach ($addresses as $address): ?>
<?php $isSelected = (int) $address['id'] === $selected_id; ?>
                            <div class="col-12 col-md-6">
                              <a
                                href="<?= e(url('checkout?address=' . (int) $address['id'])) ?>"
                                class="checkout-address-card<?= $isSelected ? ' is-selected' : '' ?>"
                                aria-current="<?= $isSelected ? 'true' : 'false' ?>"
                              >
                                <div class="d-flex align-items-start justify-content-between gap-2">
                                  <span class="fw-semibold"><?= e((string) $address['recipient_name']) ?></span>
                                  <span class="d-flex flex-column align-items-end gap-1">
<?php if ((int) $address['is_default'] === 1): ?>
                                    <span class="badge bg-label-primary">Default</span>
<?php endif; ?>
<?php if ($isSelected): ?>
                                    <span class="badge bg-label-success">Selected</span>
<?php endif; ?>
                                  </span>
                                </div>
<?php if (trim((string) ($address['label'] ?? '')) !== ''): ?>
                                <div class="small text-body-secondary"><?= e((string) $address['label']) ?></div>
<?php endif; ?>
                                <div class="small text-body-secondary mt-1">
                                  <?= e(implode(', ', array_filter([
                                      (string) $address['address_line'],
                                      (string) ($address['barangay'] ?? ''),
                                      (string) ($address['city'] ?? ''),
                                      (string) ($address['province'] ?? ''),
                                      (string) ($address['postal'] ?? ''),
                                  ]))) ?>
                                </div>
<?php if (trim((string) ($address['phone'] ?? '')) !== ''): ?>
                                <div class="small text-body-secondary mt-1"><?= e((string) $address['phone']) ?></div>
<?php endif; ?>
                              </a>
                            </div>
<?php endforeach; ?>
                          </div>
                        </div>
                      </div>
<?php endif; ?>
                    </div>
