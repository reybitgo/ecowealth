<?php
declare(strict_types=1);

/**
 * Profile — the address-book list.
 *
 * The whole card is one live region. It renders no text input (the
 * default/delete buttons are hidden-CSRF forms), so a background refresh
 * can replace it safely while the member has unsaved input in another
 * card.
 *
 * @var list<array<string, mixed>> $addresses
 */

$addresses = $addresses ?? [];
?>
                  <div class="card mb-6" data-live-region="profile-addresses">
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <div>
                        <h5 class="mb-0">Address book</h5>
                        <p class="text-body-secondary small mb-0">The default address pre-fills checkout.</p>
                      </div>
                      <span class="badge bg-label-primary"><?= count($addresses) ?> saved</span>
                    </div>
<?php if ($addresses === []): ?>
                    <div class="card-body">
<?php
    echo View::partial('empty_state', [
        'icon'  => 'bx bx-map-pin',
        'title' => 'No saved addresses yet',
        'text'  => 'Add the address you want checkout to pre-fill.',
    ]);
?>
                    </div>
<?php else: ?>
                    <div class="card-body">
                      <div class="row g-4">
<?php foreach ($addresses as $address): ?>
                        <div class="col-12 col-md-6">
                          <div class="address-card<?= (int) $address['is_default'] === 1 ? ' is-default' : '' ?>" data-address-id="<?= (int) $address['id'] ?>">
                            <div class="d-flex align-items-start justify-content-between gap-2">
                              <div>
                                <div class="fw-semibold"><?= e((string) $address['recipient_name']) ?></div>
<?php if (trim((string) ($address['label'] ?? '')) !== ''): ?>
                                <div class="small text-body-secondary"><?= e((string) $address['label']) ?></div>
<?php endif; ?>
                              </div>
<?php if ((int) $address['is_default'] === 1): ?>
                              <span class="badge bg-label-primary">Default</span>
<?php endif; ?>
                            </div>
                            <div class="small text-body-secondary mt-2">
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
                            <div class="d-flex flex-wrap gap-2 mt-3">
                              <a href="<?= e(url('profile?edit=' . (int) $address['id'])) ?>" class="btn btn-sm btn-label-primary">Edit</a>
<?php if ((int) $address['is_default'] !== 1): ?>
                              <form method="POST" action="<?= e(url('profile/address/' . (int) $address['id'] . '/default')) ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-label-secondary">Make default</button>
                              </form>
<?php endif; ?>
                              <form
                                method="POST"
                                action="<?= e(url('profile/address/' . (int) $address['id'] . '/delete')) ?>"
                                data-confirm
                                data-confirm-title="Delete this address?"
                                data-confirm-text="It will be removed from your address book."
                                data-confirm-button="Delete address"
                                data-confirm-cancel="Keep it"
                                data-confirm-tone="danger"
                              >
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-label-danger">Delete</button>
                              </form>
                            </div>
                          </div>
                        </div>
<?php endforeach; ?>
                      </div>
                    </div>
<?php endif; ?>
                  </div>
