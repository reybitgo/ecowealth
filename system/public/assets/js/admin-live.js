/**
 * EcoWealth — live-page notices
 * -----------------------------------
 * Live surfaces with a form region (the admin order desk, wallet monitor and
 * member directory, and the member's own membership page) keep those regions
 * when the signed-in user has unsaved input: app-live.js applies the safe
 * regions and reports the held keys in `detail.held`. This script reveals the
 * page's `[data-live-stale]` banner when that happens and hides it again as
 * soon as a later payload applies without holding anything back.
 *
 * The banner is optional; pages without one simply do not load this file.
 * A `[data-live-reload]` link inside the banner is pointed at the current
 * URL (filters and page included) when the script starts.
 */
(function () {
  'use strict';

  var notice = document.querySelector('[data-live-stale]');

  if (!notice) {
    return;
  }

  var reload = notice.querySelector('[data-live-reload]');

  if (reload) {
    reload.setAttribute('href', window.location.href);
  }

  function isLiveSurface(key) {
    return typeof key === 'string' && key !== '';
  }

  document.addEventListener('ecowealth:live-applied', function (event) {
    var detail = event.detail || {};

    if (!isLiveSurface(detail.surface)) {
      return;
    }

    var held = Array.isArray(detail.held) ? detail.held : [];

    if (held.length > 0) {
      notice.classList.remove('d-none');
      return;
    }

    notice.classList.add('d-none');
  });
})();
