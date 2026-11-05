/**
 * EcoWealth — checkout behaviour (Phase 6)
 * -------------------------------------------------
 * The terms checkbox gates the Place order button, so a member cannot
 * click a submit that the server would refuse. The server validation
 * stays the source of truth; this is only the immediate visual gate.
 */
(function () {
  'use strict';

  var terms = document.getElementById('terms');
  var button = document.getElementById('placeOrder');
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

  terms.addEventListener('change', sync);
  sync();
})();
