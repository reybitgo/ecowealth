/**
 * EcoWealth — admin order page
 * -----------------------------
 * The order page is a live surface (see docs/live-updates.md). Its read-only
 * cards refresh in place; the payment, fulfilment and cancel cards own forms,
 * so app-live.js never replaces them while the administrator has unsaved
 * input. When that happens it dispatches `ecowealth:live-applied` with the
 * held region keys, and this script reveals the stale banner that points at
 * a fresh reload.
 *
 * The banner clears itself as soon as a later refresh applies without
 * holding anything back.
 */
(function () {
  'use strict';

  var notice = document.querySelector('[data-live-stale]');

  if (!notice) {
    return;
  }

  function surfaceFor(key) {
    return typeof key === 'string' && key.indexOf('admin.orders:') === 0;
  }

  document.addEventListener('ecowealth:live-applied', function (event) {
    var detail = event.detail || {};

    if (!surfaceFor(detail.surface)) {
      return;
    }

    var held = Array.isArray(detail.held) ? detail.held : [];

    if (held.length > 0) {
      notice.classList.remove('d-none');
      return;
    }

    if (detail.changed) {
      notice.classList.add('d-none');
    }
  });

  /* A native AJAX form that app-live.js submits (not used on this page yet)
   * can hide the banner optimistically once it succeeds. */
  window.EcoWealthAdminOrder = {
    clearNotice: function () {
      notice.classList.add('d-none');
    }
  };
})();
