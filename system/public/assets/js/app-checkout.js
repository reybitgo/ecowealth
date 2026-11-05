/**
 * EcoWealth — checkout behaviour (Phase 6)
 * -------------------------------------------------
 * The terms checkbox gates the Place order button, so a member cannot
 * click a submit that the server would refuse. The server validation
 * stays the source of truth; this is only the immediate visual gate.
 *
 * The gate is re-applied on change/input and after every page show,
 * including a bfcache restore, so a failed submit followed by a back
 * navigation cannot leave the button enabled against unticked terms.
 */
(function () {
  'use strict';

  var bound = false;

  function applyGate() {
    var terms = document.getElementById('terms');
    var button = document.querySelector('[data-checkout-submit]');
    var hint = document.querySelector('[data-terms-hint]');

    if (!terms || !button) {
      return;
    }

    function sync() {
      var accepted = terms.checked;

      button.disabled = !accepted;
      button.setAttribute('aria-disabled', accepted ? 'false' : 'true');

      if (hint) {
        hint.classList.toggle('d-none', accepted);
      }
    }

    if (!bound) {
      terms.addEventListener('change', sync);
      terms.addEventListener('input', sync);
      window.addEventListener('pageshow', sync);
      bound = true;
    }

    sync();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', applyGate);
  } else {
    applyGate();
  }

  window.addEventListener('load', applyGate);
})();
