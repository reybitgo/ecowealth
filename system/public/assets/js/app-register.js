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

  function postJson(url, data, options) {
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

    var init = {
      method: 'POST',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json'
      },
      body: body,
      credentials: 'same-origin'
    };

    if (options && options.signal) {
      init.signal = options.signal;
    }

    return fetch(url, init).then(function (response) {
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
      return;
    }

    /* The form is `novalidate`, so a missing placement would only come
     * back as a server error. Catch it here while the slot picker is on
     * screen and point the member at the right control. */
    if (positionGroup && positionGroup.getAttribute('aria-busy') === 'true') {
      event.preventDefault();
      setHint(positionHint, 'Still checking which sides are open — one moment.', '');
      return;
    }

    if (positionGroup) {
      var chosen = form.querySelector('[data-binary-position]:checked');

      if (!chosen) {
        event.preventDefault();

        if (positionGroup.classList.contains('is-unavailable')) {
          setHint(
            positionHint,
            'No open side under this upline. Choose another upline first.',
            'danger'
          );

          if (upline) {
            upline.focus();
          }
        } else {
          setHint(positionHint, 'Choose an open side before submitting.', 'danger');

          var firstOpen = form.querySelector('[data-binary-position]:not(:disabled)');

          if (firstOpen) {
            firstOpen.focus();
          }
        }
      }
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

  /* ---------- Password confirmation ------------------------- */
  var password = form.querySelector('#password');
  var passwordConfirm = form.querySelector('#password_confirm');
  var passwordConfirmHint = form.querySelector('[data-password-confirm-hint]');

  function syncPasswordConfirm() {
    if (!password || !passwordConfirm || !passwordConfirmHint) {
      return;
    }

    if (passwordConfirm.value === '') {
      setHint(passwordConfirmHint, '', '');
      passwordConfirm.classList.remove('is-invalid');
      return;
    }

    var match = password.value === passwordConfirm.value;

    setHint(
      passwordConfirmHint,
      match ? 'Passwords match.' : 'Passwords do not match yet.',
      match ? 'success' : 'danger'
    );
    passwordConfirm.classList.toggle('is-invalid', !match);
  }

  if (password) {
    password.addEventListener('input', syncPasswordConfirm);
  }

  if (passwordConfirm) {
    passwordConfirm.addEventListener('input', syncPasswordConfirm);
  }

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
  var sponsorDefault = sponsorHint
    ? sponsorHint.getAttribute('data-sponsor-default') || 'Optional — leave blank to join under the company account.'
    : '';
  var sponsorTimer = null;

  if (sponsor && sponsorHint) {
    sponsor.addEventListener('input', function () {
      var value = sponsor.value.trim();

      window.clearTimeout(sponsorTimer);

      if (value === '') {
        setHint(sponsorHint, sponsorDefault, '');
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

  /* ---------- Binary placement ------------------------------ */
  /* The register form posts `placement=1` to the same check-sponsor
   * endpoint: the `sponsor` field carries the binary upline (blank
   * means the sponsor/company default) and the response reports the
   * immediate left/right slots. */
  var upline = form.querySelector('[data-binary-upline-input]');
  var uplineHint = form.querySelector('[data-binary-upline-hint]');
  var uplineState = form.querySelector('[data-upline-state]');
  var uplineName = form.querySelector('[data-upline-name]');
  var uplineTag = form.querySelector('[data-upline-tag]');
  var uplineFeedback = form.querySelector('[data-upline-feedback]');
  var positionGroup = form.querySelector('[data-binary-slots]');
  var positionHint = form.querySelector('[data-binary-position-hint]');
  var positionFeedback = form.querySelector('[data-binary-position-feedback]');
  var positionRadios = form.querySelectorAll('[data-binary-position]');
  var summary = form.querySelector('[data-placement-summary]');
  var summaryUpline = form.querySelector('[data-summary-upline]');
  var summarySide = form.querySelector('[data-summary-side]');
  var uplineTimer = null;
  var uplineRequest = 0;
  var placementAbort = null;
  var placementTimeout = null;

  /* The picker is locked while availability is checked. A request that
   * never answers must not keep it locked, so every check is abandoned
   * after this many milliseconds and the side is left to the server. */
  var PLACEMENT_TIMEOUT = 8000;

  function placementSlot(side) {
    return form.querySelector('[data-binary-slot="' + side + '"]');
  }

  function slotParts(side) {
    var element = placementSlot(side);

    if (!element) {
      return null;
    }

    return {
      element: element,
      input: element.querySelector('[data-binary-position]'),
      status: element.querySelector('[data-slot-status]'),
      meta: element.querySelector('[data-slot-meta]'),
      reco: element.querySelector('[data-slot-reco]')
    };
  }

  function setUplineState(state) {
    if (!uplineState) {
      return;
    }

    var icon = uplineState.querySelector('i');

    uplineState.classList.remove('is-checking', 'is-valid', 'is-invalid');

    if (state) {
      uplineState.classList.add('is-' + state);
    }

    if (icon) {
      var iconName = state === 'checking'
        ? 'bx-loader-alt bx-spin'
        : state === 'valid'
          ? 'bx-check-circle'
          : state === 'invalid'
            ? 'bx-error-circle'
            : 'bx-user-circle';

      icon.className = 'icon-base bx ' + iconName;
    }
  }

  function clearUplineError() {
    if (upline) {
      upline.classList.remove('is-invalid');
      upline.removeAttribute('aria-invalid');
    }

    if (uplineFeedback) {
      uplineFeedback.remove();
      uplineFeedback = null;
    }
  }

  function clearPositionError() {
    if (positionGroup) {
      positionGroup.classList.remove('is-invalid', 'is-unavailable');
    }

    if (positionFeedback) {
      positionFeedback.remove();
      positionFeedback = null;
    }
  }

  function setSlot(side, slot, forcedState, suggested) {
    var parts = slotParts(side);

    if (!parts) {
      return;
    }

    var unavailable = forcedState === 'unavailable';
    var open = !unavailable && !!(slot && slot.free === true && slot.legal !== false);
    var flagged = open && suggested === true;

    parts.element.classList.remove('is-checking');
    parts.element.classList.toggle('is-open', open);
    parts.element.classList.toggle('is-occupied', !open);
    parts.element.classList.toggle('is-suggested', flagged);

    if (parts.reco) {
      parts.reco.classList.toggle('d-none', !flagged);
    }

    if (parts.input) {
      parts.input.disabled = !open;

      if (!open && parts.input.checked) {
        parts.input.checked = false;
      }
    }

    if (parts.status) {
      parts.status.className = 'binary-slot-status ' + (open ? 'is-open' : 'is-occupied');
      parts.status.textContent = unavailable
        ? 'Unavailable'
        : open
          ? 'Open slot'
          : (slot && slot.free === false ? 'Occupied' : 'Unavailable');
    }

    if (parts.meta) {
      if (unavailable) {
        parts.meta.textContent = 'Fix the upline first.';
      } else if (open) {
        parts.meta.textContent = suggested === true
          ? 'Open — suggested by the system. You can pick either side.'
          : 'Available — you can place the member here.';
      } else if (slot && slot.free === false) {
        parts.meta.textContent = slot.username
          ? 'Taken by @' + slot.username + '.'
          : 'This side is already taken.';
      } else {
        parts.meta.textContent = 'You cannot place the member on this side.';
      }
    }
  }

  function resetSlots(message) {
    ['left', 'right'].forEach(function (side) {
      var parts = slotParts(side);

      if (!parts) {
        return;
      }

      parts.element.classList.remove('is-open', 'is-occupied', 'is-suggested', 'is-checking');

      if (parts.reco) {
        parts.reco.classList.add('d-none');
      }

      if (parts.input) {
        parts.input.disabled = false;
      }

      if (parts.status) {
        parts.status.className = 'binary-slot-status is-neutral';
        parts.status.textContent = 'Select';
      }

      if (parts.meta) {
        parts.meta.textContent = message || 'Availability appears after the upline is checked.';
      }
    });

    if (positionGroup) {
      positionGroup.removeAttribute('aria-busy');
    }

    updateSummary();
  }

  /* While the upline is being looked up the slots must not look settled:
   * "Select" would invite a click on a side nobody has verified yet. */
  function setSlotsChecking() {
    ['left', 'right'].forEach(function (side) {
      var parts = slotParts(side);

      if (!parts) {
        return;
      }

      parts.element.classList.remove('is-open', 'is-occupied', 'is-suggested');
      parts.element.classList.add('is-checking');

      if (parts.reco) {
        parts.reco.classList.add('d-none');
      }

      if (parts.input) {
        /* A side may only be chosen once its availability is known: with
         * the radios live, a fast click would beat the suggestion and
         * could then be cleared when the answer arrives. A checked radio
         * keeps its state while disabled. */
        parts.input.disabled = true;
      }

      if (parts.status) {
        parts.status.className = 'binary-slot-status is-checking';
        parts.status.textContent = 'Checking…';
      }

      if (parts.meta) {
        parts.meta.textContent = 'Confirming which sides are open.';
      }
    });

    if (positionGroup) {
      positionGroup.setAttribute('aria-busy', 'true');
    }

    updateSummary();
  }

  function uplineLabel() {
    if (upline && upline.value.trim() !== '') {
      return '@' + upline.value.trim().replace(/^@+/, '');
    }

    var label = uplineName ? uplineName.textContent.trim() : '';

    return label === '' || label === 'Your sponsor' ? 'your sponsor' : label;
  }

  /* The picker's one-sentence confirmation: the upline it will write,
   * the side, and the fact that the choice outlives the form. */
  function updateSummary() {
    if (!summary) {
      return;
    }

    var chosen = form.querySelector('[data-binary-position]:checked');

    if (!chosen || chosen.disabled) {
      summary.classList.add('d-none');
      return;
    }

    if (summaryUpline) {
      summaryUpline.textContent = uplineLabel();
    }

    if (summarySide) {
      summarySide.textContent = chosen.value === 'left' ? 'Left side' : 'Right side';
    }

    summary.classList.remove('d-none');
  }

  function applyPlacement(data) {
    var slots = data.slots || {};
    var suggested = typeof data.suggested === 'string' ? data.suggested : '';
    var available = [];

    clearPositionError();

    if (positionGroup) {
      positionGroup.classList.remove('is-unavailable');
      positionGroup.removeAttribute('aria-busy');
    }

    ['left', 'right'].forEach(function (side) {
      var slot = slots[side] || { free: false, legal: false };

      setSlot(side, slot, null, side === suggested);

      if (slot.free === true && slot.legal !== false) {
        available.push(side);
      }
    });

    var checked = form.querySelector('[data-binary-position]:checked');

    if (available.length === 0) {
      if (positionGroup) {
        positionGroup.classList.add('is-unavailable');
      }

      setHint(
        positionHint,
        'No open side under @' + (data.username || 'that upline') + '. Choose another upline.',
        'danger'
      );
      updateSummary();
      return;
    }

    if (!checked || checked.disabled) {
      if (available.length === 1) {
        var only = form.querySelector('[data-binary-position][value="' + available[0] + '"]');

        if (only) {
          only.checked = true;
          clearPositionError();
          setHint(
            positionHint,
            'Only the ' + available[0] + ' side is open, so it was selected for you.',
            'success'
          );
          updateSummary();
          return;
        }
      }

      setHint(
        positionHint,
        suggested !== ''
          ? 'Both sides are open — the ' + suggested + ' side is suggested. Pick either one.'
          : 'Both sides are open. Choose where this member should sit.',
        ''
      );
      updateSummary();
      return;
    }

    setHint(positionHint, 'The ' + checked.value + ' side is open.', 'success');
    updateSummary();
  }

  function handlePlacementError(message) {
    setUplineState('invalid');

    if (upline && upline.value.trim() !== '') {
      upline.classList.add('is-invalid');
      upline.setAttribute('aria-invalid', 'true');
    }

    setHint(uplineHint, message, 'danger');

    ['left', 'right'].forEach(function (side) {
      setSlot(side, { free: false, legal: false }, 'unavailable');
    });

    if (positionGroup) {
      positionGroup.removeAttribute('aria-busy');
    }

    setHint(positionHint, 'Check the upline to see which sides are open.', '');
    updateSummary();
  }

  function checkPlacement() {
    if (!upline || !positionGroup) {
      return;
    }

    var value = upline.value.trim();
    var currentSponsor = sponsor ? sponsor.value.trim() : '';
    var request = ++uplineRequest;

    if (placementAbort) {
      placementAbort.abort();
    }

    window.clearTimeout(placementTimeout);

    placementAbort = typeof AbortController === 'function' ? new AbortController() : null;

    placementTimeout = window.setTimeout(function () {
      if (request !== uplineRequest) {
        return;
      }

      /* Invalidate the pending response and hand the picker back: the
       * member can choose now and the server validates the side. */
      uplineRequest += 1;

      if (placementAbort) {
        placementAbort.abort();
      }

      setUplineState('');
      resetSlots('Availability unknown — the server will confirm the side you pick.');
      setHint(
        uplineHint,
        'The availability check is taking too long. Choose a side — the server will confirm it.',
        ''
      );
      setHint(positionHint, 'Availability is unknown right now.', '');
    }, PLACEMENT_TIMEOUT);

    setUplineState('checking');
    setSlotsChecking();
    setHint(
      uplineHint,
      value === '' ? 'Checking your sponsor’s placement…' : 'Checking the upline and open sides…',
      ''
    );

    postJson(endpoints.sponsor, {
      sponsor: value,
      placement: '1',
      current_sponsor: currentSponsor
    }, { signal: placementAbort ? placementAbort.signal : null }).then(function (data) {
      if (request !== uplineRequest || upline.value.trim() !== value) {
        return;
      }

      window.clearTimeout(placementTimeout);

      if (!data || data.valid !== true) {
        if (data && data.message) {
          handlePlacementError(data.message);
        } else {
          setUplineState('');
          resetSlots();
          setHint(
            uplineHint,
            'Could not check the upline. You can still submit; the server will confirm.',
            ''
          );
        }

        return;
      }

      clearUplineError();
      setUplineState('valid');

      if (uplineName) {
        uplineName.textContent = '@' + (data.username || value);
      }

      if (uplineTag) {
        uplineTag.textContent = data.defaulted ? 'Default' : 'Selected';
      }

      setHint(
        uplineHint,
        data.defaulted
          ? 'Using @' + (data.username || value) + ' — leave this blank to keep your sponsor’s placement.'
          : 'Upline found: @' + (data.username || value) + '.',
        'success'
      );

      applyPlacement(data);
    });
  }

  if (upline && positionGroup) {
    upline.addEventListener('input', function () {
      uplineRequest += 1;
      clearUplineError();
      clearPositionError();
      setUplineState('');
      setSlotsChecking();

      window.clearTimeout(uplineTimer);
      setHint(
        uplineHint,
        upline.value.trim() === ''
          ? 'Using your sponsor’s placement — checking…'
          : 'Looking up this upline…',
        ''
      );
      uplineTimer = window.setTimeout(checkPlacement, 450);
    });

    Array.prototype.forEach.call(positionRadios, function (radio) {
      radio.addEventListener('change', function () {
        if (!radio.checked) {
          return;
        }

        clearPositionError();
        setHint(positionHint, 'The ' + radio.value + ' side is open.', 'success');
        updateSummary();
      });
    });

    /* A blank upline follows the sponsor; re-check availability when the
     * sponsor field changes. */
    if (sponsor) {
      sponsor.addEventListener('input', function () {
        if (upline.value.trim() !== '') {
          return;
        }

        uplineRequest += 1;
        window.clearTimeout(uplineTimer);
        setSlotsChecking();
        uplineTimer = window.setTimeout(checkPlacement, 550);
      });
    }

    /* Resolve the default (sponsor/company) placement on load. The slots
     * are locked first, so the 100 ms before the first answer cannot be
     * used to click a side whose availability is still unknown. */
    setSlotsChecking();
    window.setTimeout(checkPlacement, 100);
  }

  /* ---------- Registration code ----------------------------- */
  var codeInput = form.querySelector('[data-code-input]');
  var codeButton = form.querySelector('[data-code-validate]');
  var codeHint = form.querySelector('[data-code-hint]');
  var codeOk = form.querySelector('[data-code-ok]');
  var codeFeedback = form.querySelector('[data-code-feedback]');

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

  /* The server renders the last submit's field error above the hint. Once
   * the user edits the code or asks Validate to check a new value, that
   * error is stale: remove it (and the red input state) so the live check
   * is the only message under the field. */
  function clearCodeError() {
    if (codeInput) {
      codeInput.classList.remove('is-invalid');
    }

    if (codeFeedback) {
      codeFeedback.remove();
      codeFeedback = null;
    }
  }

  if (codeInput) {
    codeInput.addEventListener('input', function () {
      codeInput.value = formatCode(codeInput.value);
      clearCodeResult();
      clearCodeError();
      setHint(codeHint, 'The code determines the package you activate.', '');
    });
  }

  if (codeButton && codeInput) {
    codeButton.addEventListener('click', function () {
      var value = codeInput.value.trim();

      clearCodeError();
      clearCodeResult();

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
