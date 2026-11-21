/**
 * EcoWealth — binary tree in-node registration (plan §11 Phase 22G-S4)
 * ------------------------------------------------------------
 * Wires the free-slot "+" triggers on the binary tree page to the
 * in-node registration modal: live validation (username, sponsor),
 * fetch submit through the existing register.php, and graceful
 * degradation to a plain link without JavaScript.
 *
 * The modal markup (#binaryRegisterModal) is rendered by
 * views/member/genealogy/_register_modal.php. This script only
 * enhances it; without it, each "+" is an ordinary link to the
 * prefilled registration form.
 */

(function () {
  'use strict';

  var modalEl   = document.getElementById('binaryRegisterModal');
  var formEl    = document.getElementById('binaryRegisterForm');
  var submitBtn = document.getElementById('binaryModalSubmit');
  var globalErr = document.querySelector('.global-error');
  var placement = document.getElementById('binaryModalPlacement');
  var fullForm  = document.getElementById('binaryModalFullFormLink');
  var registerUrl = modalEl ? modalEl.getAttribute('data-register-url') : '';

  if (!modalEl || !formEl) {
    return;
  }

  var bsModal = null;
  try { bsModal = new bootstrap.Modal(modalEl); } catch (e) {}

  /* ----------------------------------------------------------
   *  Open from a + slot link or an invite action
   * ---------------------------------------------------------- */
  function openFor(el) {
    if (!el) { return; }
    var upline = el.getAttribute('data-binary-upline') || '';
    var side   = el.getAttribute('data-binary-side') || '';

    var sponsorInput = formEl.querySelector('input[name="sponsor"]');
    var uplineInput  = formEl.querySelector('input[name="binary_upline"]');
    var positionInput = formEl.querySelector('input[name="binary_position"]');

    if (sponsorInput)  { sponsorInput.value = upline; }
    if (uplineInput)   { uplineInput.value  = upline; }
    if (positionInput) { positionInput.value = side; }

    if (placement) {
      placement.innerHTML = 'Upline: <span class="fw-bold">@' + upline + '</span> &middot; Side: <span class="fw-badge">' + side.charAt(0).toUpperCase() + side.slice(1) + '</span>';
    }

    if (fullForm && registerUrl) {
      fullForm.href = registerUrl + '?sponsor=' + encodeURIComponent(upline)
        + '&binary_position=' + encodeURIComponent(side)
        + '&return=genealogy';
    }

    var usernameEl = document.getElementById('binaryModalUsername');
    if (usernameEl) { usernameEl.value = ''; }

    clearErrors();

    if (bsModal) { bsModal.show(); }
  }

  document.querySelectorAll('[data-binary-modal-trigger]').forEach(function (el) {
    el.addEventListener('click', function (event) {
      event.preventDefault();
      openFor(el);
    });
  });

  /* ----------------------------------------------------------
   *  Live validation
   * ---------------------------------------------------------- */
  function clearErrors() {
    formEl.querySelectorAll('.is-invalid').forEach(function (el) { el.classList.remove('is-invalid'); });
    formEl.querySelectorAll('.invalid-feedback').forEach(function (el) { el.remove(); });
    if (globalErr) {
      globalErr.classList.add('d-none');
      globalErr.textContent = '';
    }
  }

  function showFieldError(field, message) {
    var input = formEl.querySelector('[name="' + field + '"]');
    if (!input) { return; }
    input.classList.add('is-invalid');
    var div = document.createElement('div');
    div.className = 'invalid-feedback';
    div.textContent = message;
    input.parentNode.appendChild(div);
  }

  function showGlobalError(message) {
    if (!globalErr) { return; }
    globalErr.textContent = message;
    globalErr.classList.remove('d-none');
  }

  var timer = null;
  var usernameInput = formEl.querySelector('[name="username"]');
  if (usernameInput) {
    usernameInput.addEventListener('input', function () {
      clearTimeout(timer);
      var username = this.value.trim();
      if (username.length < 3) { return; }
      timer = setTimeout(function () {
        fetch(formEl.getAttribute('data-check-username'), {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'username=' + encodeURIComponent(username)
        })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (!data.available) {
              showFieldError('username', data.message || 'This username is already taken.');
            }
          });
      }, 400);
    });
  }

  var sponsorInput = formEl.querySelector('[name="sponsor"]');
  if (sponsorInput) {
    sponsorInput.addEventListener('blur', function () {
      var sponsor = this.value.trim();
      if (sponsor === '') { return; }
      fetch(formEl.getAttribute('data-check-sponsor'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'sponsor=' + encodeURIComponent(sponsor)
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data.valid) {
            showFieldError('sponsor', data.message || 'No account or referral code matches that.');
          }
        });
    });
  }

  var codeBtn = formEl.querySelector('[data-binary-modal-validate-code]');
  if (codeBtn) {
    codeBtn.addEventListener('click', function () {
      var codeInput = formEl.querySelector('[name="reg_code"]');
      var code = codeInput ? codeInput.value.trim() : '';
      if (code === '') { return; }
      fetch(formEl.getAttribute('data-validate-code'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'code=' + encodeURIComponent(code)
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          var okEl = formEl.querySelector('[data-binary-modal-code-ok]');
          var fbEl = formEl.querySelector('[data-binary-modal-code-feedback]');
          if (!data.valid) {
            if (fbEl) { fbEl.textContent = data.message; fbEl.classList.remove('d-none'); }
            if (okEl) { okEl.classList.add('d-none'); }
          } else {
            if (fbEl) { fbEl.classList.add('d-none'); }
            if (okEl) {
              okEl.classList.remove('d-none');
              okEl.innerHTML = 'Code valid &mdash; ' + (data.package_name || '');
            }
          }
        });
    });
  }

  /* ----------------------------------------------------------
   *  Submit via fetch
   * ---------------------------------------------------------- */
  formEl.addEventListener('submit', function (event) {
    event.preventDefault();
    clearErrors();

    var formData = new FormData(formEl);

    fetch(formEl.getAttribute('action'), {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: formData
    })
      .then(function (response) {
        if (response.ok && response.redirected) {
          var url = response.url || '';
          if (url.indexOf('genealogy') !== -1) {
            window.location.href = url + (url.indexOf('?') === -1 ? '?' : '&') + 'view=binary';
          } else {
            window.location.href = url;
          }
          return;
        }
        return response.json();
      })
      .then(function (data) {
        if (data && data.ok === false) {
          showGlobalError(data.message || 'Registration failed.');
        }
      })
      .catch(function (err) {
        console.error('[ecowealth] binary registration failed:', err);
        showGlobalError('The account could not be created. Please try again.');
      })
      .finally(function () {
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.textContent = 'Create account';
        }
      });
  });

  if (submitBtn) {
    submitBtn.addEventListener('click', function () {
      submitBtn.disabled = true;
      submitBtn.textContent = 'Creating&hellip;';
    });
  }

})();
