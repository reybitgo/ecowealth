/**
 * EcoWealth — registration form behaviour (plan §7 Phase 9)
 * ---------------------------------------------------------
 * Progressive enhancement only. Without this file the form still works:
 * every mode panel is visible and the server validates the selected
 * mode's fields. With it:
 *
 *   · one mode panel is shown at a time
 *   · the sponsor / username fields are checked live
 *   · a registration code is formatted and validated on demand
 *   · the submit button stays disabled until the terms are accepted
 *
 * No dependency on jQuery or the theme's validation plugin.
 */
(function () {
  'use strict';

  var form = document.getElementById('registerForm');

  if (!form) {
    return;
  }

  var csrfInput = form.querySelector('input[name="_csrf"]');
  var submit = form.querySelector('[data-register-submit]');
  var terms = form.querySelector('[data-terms]');
  var panels = form.querySelectorAll('[data-mode-panel]');

  var endpoints = {
    code: form.getAttribute('data-validate-code') || '',
    username: form.getAttribute('data-check-username') || '',
    sponsor: form.getAttribute('data-check-sponsor') || ''
  };

  function postJson(url, data) {
    if (!url) {
      return Promise.resolve({ ok: false });
    }

    var body = new URLSearchParams();

    Object.keys(data).forEach(function (key) {
      body.append(key, data[key]);
    });

    if (csrfInput) {
      body.append('_csrf', csrfInput.value);
    }

    return fetch(url, {
      method: 'POST',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json'
      },
      body: body,
      credentials: 'same-origin'
    }).then(function (response) {
      return response.json();
    }).catch(function () {
      return { ok: false };
    });
  }

  function selectedMode() {
    var checked = form.querySelector('input[name="mode"]:checked');
    return checked ? checked.value : '';
  }

  function syncPanels() {
    var mode = selectedMode();

    Array.prototype.forEach.call(panels, function (panel) {
      panel.classList.toggle('is-active', panel.getAttribute('data-mode-panel') === mode);
    });
  }

  form.addEventListener('change', function (event) {
    if (event.target && event.target.name === 'mode') {
      syncPanels();
    }
  });

  syncPanels();

  /* ---------- Terms gate ------------------------------------ */
  function syncTerms() {
    if (!submit || !terms) {
      return;
    }

    submit.disabled = !terms.checked;
    submit.setAttribute('aria-disabled', terms.checked ? 'false' : 'true');
  }

  if (terms) {
    terms.addEventListener('change', syncTerms);
    terms.addEventListener('input', syncTerms);
    window.addEventListener('load', syncTerms);
    window.addEventListener('pageshow', syncTerms);
    syncTerms();
  }

  form.addEventListener('submit', function (event) {
    if (terms && !terms.checked) {
      event.preventDefault();
      terms.focus();
    }
  });

  /* ---------- Password visibility --------------------------- */
  Array.prototype.forEach.call(form.querySelectorAll('[data-password-toggle]'), function (toggle) {
    var input = toggle.parentNode ? toggle.parentNode.querySelector('input') : null;
    var icon = toggle.querySelector('i');

    if (!input) {
      return;
    }

    toggle.addEventListener('click', function () {
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';

      if (icon) {
        icon.classList.toggle('bx-hide', !show);
        icon.classList.toggle('bx-show', show);
      }
    });
  });

  /* ---------- Username availability ------------------------- */
  var username = form.querySelector('[data-username-input]');
  var usernameHint = form.querySelector('[data-username-hint]');
  var usernameTimer = null;

  function setHint(element, message, tone) {
    if (!element) {
      return;
    }

    element.textContent = message;
    element.className = 'form-text' + (tone ? ' text-' + tone : '');
  }

  if (username && usernameHint) {
    username.addEventListener('input', function () {
      var value = username.value.trim();

      window.clearTimeout(usernameTimer);

      if (value === '') {
        setHint(usernameHint, '3–50 letters, numbers, dot, underscore or dash.', '');
        return;
      }

      setHint(usernameHint, 'Checking availability…', '');

      usernameTimer = window.setTimeout(function () {
        postJson(endpoints.username, { username: value }).then(function (data) {
          if (username.value.trim() !== value) {
            return;
          }

          setHint(
            usernameHint,
            data.message || (data.available ? 'Username is available.' : 'That username is not available.'),
            data.available ? 'success' : 'danger'
          );
        });
      }, 500);
    });
  }

  /* ---------- Sponsor check --------------------------------- */
  var sponsor = form.querySelector('[data-sponsor-input]');
  var sponsorHint = form.querySelector('[data-sponsor-hint]');
  var sponsorTimer = null;

  if (sponsor && sponsorHint) {
    sponsor.addEventListener('input', function () {
      var value = sponsor.value.trim();

      window.clearTimeout(sponsorTimer);

      if (value === '') {
        setHint(sponsorHint, 'Every member joins under a sponsor.', '');
        return;
      }

      setHint(sponsorHint, 'Looking up the sponsor…', '');

      sponsorTimer = window.setTimeout(function () {
        postJson(endpoints.sponsor, { sponsor: value }).then(function (data) {
          if (sponsor.value.trim() !== value) {
            return;
          }

          if (!data.valid) {
            setHint(sponsorHint, data.message || 'No account or referral code matches that.', 'danger');
            return;
          }

          var label = 'Sponsor: @' + (data.username || value);

          if (data.referral_code) {
            label += ' · code ' + data.referral_code;
          }

          if (data.package_name) {
            label += ' · ' + data.package_name;
          }

          setHint(sponsorHint, label, 'success');
        });
      }, 500);
    });
  }

  /* ---------- Registration code ----------------------------- */
  var codeInput = form.querySelector('[data-code-input]');
  var codeButton = form.querySelector('[data-code-validate]');
  var codeHint = form.querySelector('[data-code-hint]');
  var codeOk = form.querySelector('[data-code-ok]');

  function formatCode(raw) {
    var clean = (raw || '').replace(/[^A-Za-z0-9]/g, '').toUpperCase().slice(0, 12);
    var groups = [];

    for (var i = 0; i < clean.length && groups.length < 3; i += 4) {
      groups.push(clean.slice(i, i + 4));
    }

    return groups.join('-');
  }

  function clearCodeResult() {
    if (codeOk) {
      codeOk.classList.add('d-none');
      codeOk.textContent = '';
    }
  }

  if (codeInput) {
    codeInput.addEventListener('input', function () {
      codeInput.value = formatCode(codeInput.value);
      clearCodeResult();
      setHint(codeHint, 'The code determines the package you activate.', '');
    });
  }

  if (codeButton && codeInput) {
    codeButton.addEventListener('click', function () {
      var value = codeInput.value.trim();

      if (value.replace(/-/g, '').length !== 12) {
        setHint(codeHint, 'Enter a complete code (XXXX-XXXX-XXXX).', 'danger');
        return;
      }

      codeButton.disabled = true;
      codeButton.textContent = 'Checking…';

      postJson(endpoints.code, { code: value }).then(function (data) {
        codeButton.disabled = false;
        codeButton.textContent = 'Validate';

        if (!data.valid) {
          clearCodeResult();
          setHint(codeHint, data.message || 'That code is not valid.', 'danger');
          return;
        }

        setHint(codeHint, 'Code is valid.', 'success');

        if (codeOk) {
          codeOk.textContent = data.message || ('Valid — ' + (data.package_name || 'package'));

          if (data.price_label) {
            codeOk.textContent += ' · ' + data.price_label;
          }

          codeOk.classList.remove('d-none');
        }
      });
    });
  }
})();
