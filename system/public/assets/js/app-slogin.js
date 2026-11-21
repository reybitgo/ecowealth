/**
 * EcoWealth — super-login console (plan §7 Phase 14)
 *
 * One small behaviour: a submitted session request disables its button
 * and says what is happening, so a double click cannot open two
 * sessions. Everything else (who may be impersonated, the token, the
 * TTL) is decided by the server.
 */
(function () {
  'use strict';

  var form = document.querySelector('[data-slogin-form]');

  if (!form) {
    return;
  }

  form.addEventListener('submit', function () {
    var buttons = form.querySelectorAll('[data-slogin-submit], [data-slogin-submit-lg]');
    var label = form.querySelector('[data-slogin-label]');

    Array.prototype.forEach.call(buttons, function (button) {
      button.disabled = true;
    });

    if (label) {
      label.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Opening member session…';
    }
  });
})();
