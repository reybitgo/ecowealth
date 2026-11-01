<?php

/**
 * @file   views/member/profile.php
 * @brief  Member profile UI
 */
?>
<?php $pageTitle = 'Profile & Settings'; ?>
<?php require 'views/partials/head.php'; ?>
<?php require 'views/partials/sidebar_member.php'; ?>
<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <?= render_flash() ?>
    <form method="POST" action="<?= link_to('save_profile') ?>" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <div class="row g-3">
        <!-- Left col -->
        <div class="col-12 col-lg-4 d-flex flex-column gap-3">
          <!-- Photo -->
          <div class="card">
            <div class="card-header"><span class="card-title">🖼 Profile Photo</span></div>
            <div class="card-body d-flex align-items-center gap-3 flex-wrap">
              <div id="avatarWrap" style="width:72px;height:72px;border-radius:50%;overflow:hidden;background:var(--primary-light);display:flex;align-items:center;justify-content:center;font-size:1.75rem;font-weight:700;color:var(--primary);border:3px solid #dde3ef;flex-shrink:0;">
                <?php if ($user['photo']): ?><img id="photoPreview" src="<?= APP_URL ?>/uploads/<?= e($user['photo']) ?>" style="width:100%;height:100%;object-fit:cover;"><?php else: ?><span id="photoInitial"><?= strtoupper(substr($user['username'], 0, 1)) ?></span><?php endif; ?>
              </div>
              <div>
                <label class="btn btn-outline-primary btn-sm cursor-pointer">📷 Change Photo
                  <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="d-none" onchange="previewPhoto(this)">
                </label>
                <p class="text-muted mb-0 mt-1" style="font-size:.72rem;">JPEG/PNG/WebP · Max 2MB</p>
              </div>
            </div>
          </div>
          <!-- Account info (read-only) -->
          <div class="card">
            <div class="card-header"><span class="card-title">ℹ Account Info</span></div>
            <div class="card-body">
              <table class="info-table">
                <tr>
                  <td>Username</td>
                  <td><strong>@<?= e($user['username']) ?></strong></td>
                </tr>
                <tr>
                  <td>Package</td>
                  <td><?= e($user['package_name'] ?? '—') ?></td>
                </tr>
                <tr>
                  <td>Sponsor</td>
                  <td><?= isset($user['sponsor_username']) && $user['sponsor_username'] ? '@' . e($user['sponsor_username']) : '—' ?></td>
                </tr>
                <?php if (User::isPrimaryAdmin((int)($user['id'] ?? 0)) || Auth::isSuperadmin() || Package::hasPairing((int)($user['package_id'] ?? 0))): ?>
                <tr>
                  <td>Upline</td>
                  <td><?php $bpu = $user['binary_parent_username'] ?? null;
                      echo $bpu ? '@' . e($bpu) . ' (' . e($user['binary_position'] ?? '') . ')' : '—'; ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                  <td>Joined</td>
                  <td><?= fmt_datetime($user['joined_at']) ?></td>
                </tr>
              </table>
            </div>
          </div>
        </div>
        <!-- Right col -->
        <div class="col-12 col-lg-8 d-flex flex-column gap-3">
          <div class="card">
            <div class="card-header"><span class="card-title">👤 Personal Information</span></div>
            <div class="card-body">
              <div class="mb-3"><label class="form-label">Full Name</label><input type="text" name="full_name" class="form-control" value="<?= e($user['full_name'] ?? '') ?>" placeholder="Your full name"></div>
              <div class="row g-3">
                <div class="col-md-6 mb-3"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= e($user['email'] ?? '') ?>" placeholder="email@example.com"></div>
                <div class="col-md-6 mb-3"><label class="form-label">Mobile</label><input type="tel" name="mobile" class="form-control" value="<?= e($user['mobile'] ?? '') ?>" placeholder="09XXXXXXXXX"></div>
              </div>
              <div class="mb-0"><label class="form-label">Address</label><textarea name="address" class="form-control" rows="2" placeholder="Your address"><?= e($user['address'] ?? '') ?></textarea></div>
            </div>
          </div>
          <div class="card">
            <div class="card-header"><span class="card-title">💳 Payout Information</span></div>
            <div class="card-body">
              <p class="text-muted mb-3" style="font-size:.8rem;">Save your payout accounts. You can choose which one to use when requesting a withdrawal.</p>

              <?php $gcashEnabled = setting('gcash_enabled', '1') === '1'; ?>
              <?php $mayaEnabled  = setting('maya_enabled', '1') === '1'; ?>

              <?php if ($gcashEnabled): ?>
                <div class="mb-3">
                  <label class="form-label">
                    <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/3/33/GCash_logo.svg/32px-GCash_logo.svg.png" alt="" style="height:16px;vertical-align:middle;margin-right:.35rem;"> GCash Number
                  </label>
                  <input type="tel" name="gcash_number" class="form-control" inputmode="numeric"
                    value="<?= e($user['gcash_number'] ?? '') ?>" placeholder="09XXXXXXXXX">
                </div>
              <?php endif; ?>

              <?php if ($mayaEnabled): ?>
                <div class="mb-3">
                  <label class="form-label">
                    <span style="color:#48b0db;font-weight:700;margin-right:.35rem;">●</span>Maya Number
                  </label>
                  <input type="tel" name="maya_number" class="form-control" inputmode="numeric"
                    value="<?= e($user['maya_number'] ?? '') ?>" placeholder="09XXXXXXXXX">
                </div>
              <?php endif; ?>

              <div class="mb-3">
                <label class="form-label">
                  <span style="color:#26a17b;font-weight:700;margin-right:.35rem;">₮</span>USDT TRC20 Address
                </label>
                <input type="text" name="usdt_trc20_address" class="form-control font-mono"
                  value="<?= e($user['usdt_trc20_address'] ?? '') ?>" placeholder="T..." maxlength="42" spellcheck="false">
                <div class="form-text">TRC20 addresses start with <strong>T</strong> and are 34 characters.</div>
              </div>

              <div class="mb-0">
                <label class="form-label">
                  <span style="color:#f0b90b;font-weight:700;margin-right:.35rem;">₮</span>USDT BEP20 Address
                </label>
                <input type="text" name="usdt_bep20_address" class="form-control font-mono"
                  value="<?= e($user['usdt_bep20_address'] ?? '') ?>" placeholder="0x..." maxlength="42" spellcheck="false">
                <div class="form-text">BEP20 addresses start with <strong>0x</strong> and are 42 characters.</div>
              </div>

              <?php if (!$gcashEnabled || !$mayaEnabled): ?>
                <div class="alert alert-info mt-3 mb-0 py-2" style="font-size:.75rem;">
                  <strong>ℹ Note:</strong>
                  <?php if (!$gcashEnabled && !$mayaEnabled): ?>
                    GCash and Maya payouts are currently disabled by the administrator. Only USDT TRC20 is available.
                  <?php elseif (!$gcashEnabled): ?>
                    GCash payouts are currently disabled by the administrator.
                  <?php else: ?>
                    Maya payouts are currently disabled by the administrator.
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
          <div class="card">
            <div class="card-header"><span class="card-title">🔒 Change Password</span></div>
            <div class="card-body">
              <div class="mb-3"><label class="form-label">Current Password</label>
                <div class="input-group">
                  <input type="password" name="current_password" id="cur_pw" class="form-control" placeholder="Current password" autocomplete="current-password">
                  <button type="button" class="btn btn-outline-secondary" onclick="togglePw('cur_pw',this)">👁</button>
                </div>
              </div>
              <div class="row g-3">
                <div class="col-md-6 mb-3"><label class="form-label">New Password</label>
                  <div class="input-group">
                    <input type="password" name="new_password" id="new_pw" class="form-control" minlength="8" placeholder="Min. 8 characters" autocomplete="new-password">
                    <button type="button" class="btn btn-outline-secondary" onclick="togglePw('new_pw',this)">👁</button>
                  </div>
                </div>
                <div class="col-md-6 mb-3"><label class="form-label">Confirm New</label>
                  <div class="input-group">
                    <input type="password" name="new_password_confirm" id="confirm_pw" class="form-control" placeholder="Repeat password" autocomplete="new-password">
                    <button type="button" class="btn btn-outline-secondary" onclick="togglePw('confirm_pw',this)">👁</button>
                  </div>
                </div>
              </div>
              <p class="form-text mb-0">Leave blank to keep your current password.</p>
            </div>
          </div>
          <button type="submit" class="btn btn-primary btn-lg w-100">💾 Save Changes</button>
        </div>
      </div>
    </form>

    <!-- ── Delivery address book (own forms; NOT nested in the profile form) ── -->
    <div class="card mt-3" id="addresses">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span class="card-title">📍 Delivery Addresses</span>
        <button type="button" class="btn btn-sm btn-primary js-address-add">+ Add address</button>
      </div>
      <div class="card-body">
        <p class="text-muted mb-3" style="font-size:.8rem;">Save the addresses you deliver to. At checkout you can pick one to prefill the Delivery details — you can still edit them there.</p>
        <?php if (empty($addresses)): ?>
          <p class="text-muted mb-0" style="font-size:.85rem;">No saved addresses yet.</p>
        <?php else: ?>
          <div class="d-flex flex-column gap-2">
            <?php foreach ($addresses as $a): ?>
              <div class="border rounded p-2 d-flex justify-content-between align-items-start gap-2 flex-wrap">
                <div style="font-size:.85rem;">
                  <div class="fw-semibold">
                    <?= !empty($a['label']) ? e($a['label']) : 'Address' ?>
                    <?php if ((int) $a['is_default'] === 1): ?><span class="badge bg-success ms-1">Default</span><?php endif; ?>
                  </div>
                  <div><?= e($a['recipient_name']) ?><?= !empty($a['phone']) ? ' · ' . e($a['phone']) : '' ?></div>
                  <div class="text-muted"><?= e(implode(', ', array_filter([$a['address_line'], $a['barangay'] ?? '', $a['city'] ?? '', $a['province'] ?? '', $a['region'] ?? '']))) ?><?= !empty($a['postal']) ? ' ' . e($a['postal']) : '' ?></div>
                </div>
                <div class="d-flex gap-1 flex-shrink-0">
                  <button type="button" class="btn btn-sm btn-outline-secondary js-address-edit"
                          data-id="<?= (int) $a['id'] ?>"
                          data-label="<?= e($a['label']) ?>"
                          data-recipient="<?= e($a['recipient_name']) ?>"
                          data-phone="<?= e($a['phone']) ?>"
                          data-address="<?= e($a['address_line']) ?>"
                          data-barangay="<?= e($a['barangay'] ?? '') ?>"
                          data-city="<?= e($a['city']) ?>"
                          data-province="<?= e($a['province']) ?>"
                          data-region="<?= e($a['region'] ?? '') ?>"
                          data-postal="<?= e($a['postal']) ?>"
                          data-region-code="<?= e($a['region_code'] ?? '') ?>"
                          data-province-code="<?= e($a['province_code'] ?? '') ?>"
                          data-city-code="<?= e($a['city_code'] ?? '') ?>"
                          data-barangay-code="<?= e($a['barangay_code'] ?? '') ?>"
                          data-billing-name="<?= e($a['billing_name'] ?? '') ?>"
                          data-billing-phone="<?= e($a['billing_phone'] ?? '') ?>"
                          data-notes="<?= e($a['notes_member'] ?? '') ?>"
                          data-default="<?= (int) $a['is_default'] ?>">Edit</button>
                  <?php if ((int) $a['is_default'] !== 1): ?>
                    <form method="post" action="<?= link_to('shop_address_default') ?>">
                      <?= csrf_field() ?>
                      <input type="hidden" name="address_id" value="<?= (int) $a['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-outline-success">Set default</button>
                    </form>
                  <?php endif; ?>
                  <form method="post" action="<?= link_to('shop_address_delete') ?>" class="js-address-delete-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="address_id" value="<?= (int) $a['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                  </form>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Add / edit address modal -->
    <div class="modal fade" id="addressModal" tabindex="-1" aria-labelledby="addressModalTitle" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <form method="post" action="<?= link_to('shop_address_save') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="address_id" id="addr_id" value="">
            <div class="modal-header">
              <h5 class="modal-title" id="addressModalTitle">Add delivery address</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <div class="mb-2">
                <label class="form-label" for="addr_label">Label</label>
                <input type="text" id="addr_label" name="label" class="form-control" maxlength="40" placeholder="Home, Office…">
              </div>
              <div class="row g-2">
                <div class="col-md-7 mb-2">
                  <label class="form-label" for="addr_recipient">Recipient name *</label>
                  <input type="text" id="addr_recipient" name="recipient_name" class="form-control" maxlength="120" required>
                </div>
                <div class="col-md-5 mb-2">
                  <label class="form-label" for="addr_phone">Phone</label>
                  <input type="text" id="addr_phone" name="phone" class="form-control" maxlength="40">
                </div>
              </div>
              <div class="mb-2">
                <label class="form-label" for="addr_loc">Region, Province, City, Barangay</label>
                <button type="button" id="addr_loc" class="form-control text-start js-psgc-trigger" aria-haspopup="dialog">
                  <span class="text-muted js-psgc-placeholder">Select region, province, city, barangay…</span>
                </button>
                <input type="hidden" name="region" id="addr_region" value="">
                <input type="hidden" name="province" id="addr_province" value="">
                <input type="hidden" name="city" id="addr_city" value="">
                <input type="hidden" name="barangay" id="addr_barangay" value="">
                <input type="hidden" name="region_code" id="addr_region_code" value="">
                <input type="hidden" name="province_code" id="addr_province_code" value="">
                <input type="hidden" name="city_code" id="addr_city_code" value="">
                <input type="hidden" name="barangay_code" id="addr_barangay_code" value="">
              </div>
              <div class="mb-2">
                <label class="form-label" for="addr_address">Street Name, Building, House No. *</label>
                <input type="text" id="addr_address" name="address_line" class="form-control" maxlength="255" placeholder="Street name, building, house no." required>
              </div>
              <div class="mb-2" style="max-width:160px;">
                <label class="form-label" for="addr_postal">Postal</label>
                <input type="text" id="addr_postal" name="postal" class="form-control" maxlength="20" inputmode="numeric">
              </div>
              <div class="row g-2">
                <div class="col-md-6 mb-2">
                  <label class="form-label" for="addr_billing_name">Billing name</label>
                  <input type="text" id="addr_billing_name" name="billing_name" class="form-control" maxlength="120">
                  <div class="form-text">Name shown on the payment records.</div>
                </div>
                <div class="col-md-6 mb-2">
                  <label class="form-label" for="addr_billing_phone">Billing phone</label>
                  <input type="text" id="addr_billing_phone" name="billing_phone" class="form-control" maxlength="40">
                </div>
              </div>
              <div class="mb-2">
                <label class="form-label" for="addr_notes_member">Delivery notes</label>
                <textarea id="addr_notes_member" name="notes_member" class="form-control" rows="2" maxlength="500" placeholder="Landmarks, preferred times…"></textarea>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_default" id="addr_default" value="1">
                <label class="form-check-label" for="addr_default">Set as default</label>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-primary">Save address</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
<?php require 'views/partials/address_picker.php'; ?>
<script>
  function previewPhoto(input) {
    if (!input.files || !input.files[0]) return;
    const reader = new FileReader();
    reader.onload = e => {
      document.getElementById('avatarWrap').innerHTML = '<img src="' + e.target.result + '" style="width:100%;height:100%;object-fit:cover;">';
    };
    reader.readAsDataURL(input.files[0]);
  }

  function togglePw(id, btn) {
    const el = document.getElementById(id);
    el.type = el.type === 'password' ? 'text' : 'password';
    btn.textContent = el.type === 'password' ? '👁' : '🙈';
  }

  // ── Delivery address book ──────────────────────────────────
  // Must wait for DOMContentLoaded: the Bootstrap bundle and app.js are
  // loaded by the footer *after* this inline script, so `bootstrap` and
  // `showConfirm` do not exist yet when this block is first parsed.
  document.addEventListener('DOMContentLoaded', function () {
    var modalEl = document.getElementById('addressModal');
    if (!modalEl || typeof bootstrap === 'undefined') return;
    var modal = new bootstrap.Modal(modalEl);
    var fields = {
      id:            'addr_id',
      label:         'addr_label',
      recipient:     'addr_recipient',
      phone:         'addr_phone',
      address:       'addr_address',
      barangay:      'addr_barangay',
      city:          'addr_city',
      province:      'addr_province',
      region:        'addr_region',
      postal:        'addr_postal',
      regionCode:    'addr_region_code',
      provinceCode:  'addr_province_code',
      cityCode:      'addr_city_code',
      barangayCode:  'addr_barangay_code',
      billingName:   'addr_billing_name',
      billingPhone:  'addr_billing_phone',
      notes:         'addr_notes_member'
    };
    function set(name, value) {
      var el = document.getElementById(fields[name]);
      if (el) el.value = value == null ? '' : value;
    }
    function esc(s) {
      return String(s).replace(/[&<>"']/g, function (c) { return '&#' + c.charCodeAt(0) + ';'; });
    }
    // The trigger shows the chosen chain; the individual levels live in hidden
    // inputs so the same form still posts region/province/city/barangay.
    function renderLoc() {
      var trigger = document.getElementById('addr_loc');
      if (!trigger) return;
      var parts = ['barangay', 'city', 'province', 'region'].map(function (k) {
        var el = document.getElementById(fields[k]);
        return el ? el.value.trim() : '';
      }).filter(Boolean);
      trigger.innerHTML = parts.length
        ? '<span>' + parts.map(esc).join(', ') + '</span>'
        : '<span class="text-muted js-psgc-placeholder">Select region, province, city, barangay…</span>';
    }
    function open(mode, data) {
      document.getElementById('addressModalTitle').textContent =
        mode === 'edit' ? 'Edit delivery address' : 'Add delivery address';
      set('id', data && data.id ? data.id : '');
      set('label', data ? data.label : '');
      set('recipient', data ? data.recipient : '');
      set('phone', data ? data.phone : '');
      set('address', data ? data.address : '');
      set('barangay', data ? data.barangay : '');
      set('city', data ? data.city : '');
      set('province', data ? data.province : '');
      set('region', data ? data.region : '');
      set('postal', data ? data.postal : '');
      set('regionCode', data ? data.regionCode : '');
      set('provinceCode', data ? data.provinceCode : '');
      set('cityCode', data ? data.cityCode : '');
      set('barangayCode', data ? data.barangayCode : '');
      set('billingName', data ? data.billingName : '');
      set('billingPhone', data ? data.billingPhone : '');
      set('notes', data ? data.notes : '');
      renderLoc();
      document.getElementById('addr_default').checked = !!(data && data.isDefault);
      modal.show();
    }

    // Region → Province → City → Barangay picker (psgc.cloud). On commit the
    // chosen chain fills the hidden fields; the parent modal stays open.
    PsgcPicker.attach({
      trigger: document.getElementById('addr_loc'),
      onPick: function (picked) {
        set('region', picked.region ? picked.region.name : '');
        set('province', picked.province ? picked.province.name : '');
        set('city', picked.city ? picked.city.name : '');
        set('barangay', picked.barangay ? picked.barangay.name : '');
        set('regionCode', picked.region ? picked.region.code : '');
        set('provinceCode', picked.province ? picked.province.code : '');
        set('cityCode', picked.city ? picked.city.code : '');
        set('barangayCode', picked.barangay ? picked.barangay.code : '');
        renderLoc();
      }
    });

    var addBtn = document.querySelector('.js-address-add');
    if (addBtn) addBtn.addEventListener('click', function () { open('add', null); });

    document.querySelectorAll('.js-address-edit').forEach(function (btn) {
      btn.addEventListener('click', function () {
        open('edit', {
          id:           btn.dataset.id,
          label:        btn.dataset.label,
          recipient:    btn.dataset.recipient,
          phone:        btn.dataset.phone,
          address:      btn.dataset.address,
          barangay:     btn.dataset.barangay,
          city:         btn.dataset.city,
          province:     btn.dataset.province,
          region:       btn.dataset.region,
          postal:       btn.dataset.postal,
          regionCode:   btn.dataset.regionCode,
          provinceCode: btn.dataset.provinceCode,
          cityCode:     btn.dataset.cityCode,
          barangayCode: btn.dataset.barangayCode,
          billingName:  btn.dataset.billingName,
          billingPhone: btn.dataset.billingPhone,
          notes:        btn.dataset.notes,
          isDefault:    btn.dataset.default === '1'
        });
      });
    });

    // Delete confirmation via the app modal (native confirm() is blocked in
    // some embedded webviews).
    document.addEventListener('submit', function (e) {
      var form = e.target.closest('.js-address-delete-form');
      if (!form) return;
      e.preventDefault();
      showConfirm({
        size: 'sm',
        title: 'Delete this address?',
        message: 'This removes the saved address from your profile. This cannot be undone.',
        confirmText: 'Delete',
        confirmClass: 'btn-danger',
        onConfirm: function () { form.submit(); }
      });
    });
  });
</script>
<?php require 'views/partials/footer.php'; ?>