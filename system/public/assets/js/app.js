/**
 * EcoWealth — shared shell behaviour
 * ----------------------------------
 * Makes the navbar quick-search a real control instead of decoration:
 * Enter jumps to the catalogue's own search results, "/" focuses it and
 * Escape clears it. Kept dependency-free so it runs before main.js.
 */
(function () {
  'use strict';

  /*
   * Browsers can restore the clicked action button's focus when the user
   * comes Back to a page, which made an Edit/Delete icon look pressed.
   * Drop that restored focus; normal tab focus still gets the focus ring.
   */
  window.addEventListener('pageshow', function () {
    var active = document.activeElement;

    if (active && active.matches && active.matches('.btn-icon[class*="btn-label-"]')) {
      active.blur();
    }
  });

  /*
   * Pagination keeps the table in view (plan §3.6).
   *
   * A rows-per-page or page-number change reloads the document; without
   * help the browser starts at the top and the table the user was
   * looking at disappears. Before such a navigation we remember where
   * the table's card sat in the viewport, and after the reload we put
   * it back at the same spot. The key lives in sessionStorage, so it is
   * scoped to this tab and cleared as soon as it is used.
   */
  (function () {
    var STORAGE_KEY = 'ecowealth:pagination-position';

    var read = function () {
      try {
        return JSON.parse(window.sessionStorage.getItem(STORAGE_KEY) || 'null');
      } catch (error) {
        return null;
      }
    };

    var clear = function () {
      try {
        window.sessionStorage.removeItem(STORAGE_KEY);
      } catch (error) {
        /* Storage may be disabled; nothing to clean up. */
      }
    };

    var remember = function (bar) {
      if (!bar) {
        return;
      }

      var card = bar.closest ? bar.closest('.card') : null;
      var anchor = card || bar;

      try {
        window.sessionStorage.setItem(STORAGE_KEY, JSON.stringify({
          id: bar.getAttribute('data-pagination') || '',
          offset: anchor.getBoundingClientRect().top
        }));
      } catch (error) {
        /* Private mode / storage disabled: fall back to the default jump. */
      }
    };

    var restore = function () {
      var saved = read();

      if (!saved || !saved.id) {
        return;
      }

      clear();

      var bar = document.querySelector('[data-pagination="' + saved.id + '"]');

      if (!bar) {
        return;
      }

      var card = bar.closest ? bar.closest('.card') : null;
      var anchor = card || bar;
      var top = anchor.getBoundingClientRect().top + window.scrollY - (saved.offset || 0);

      window.scrollTo(0, Math.max(0, top));
    };

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', restore);
    } else {
      restore();
    }

    /* A page-number or first/last link: remember before the document unloads. */
    document.addEventListener('click', function (event) {
      var link = event.target && event.target.closest
        ? event.target.closest('.pagination-bar .pagination a')
        : null;

      if (!link) {
        return;
      }

      remember(link.closest('[data-pagination]'));
    });

    /* A rows-per-page change submits its form: remember first. */
    document.addEventListener('change', function (event) {
      var select = event.target;

      if (!select || !select.matches || !select.matches('[data-per-page]')) {
        return;
      }

      remember(select.closest ? select.closest('[data-pagination]') : null);

      var form = select.form || (select.closest ? select.closest('form') : null);

      if (form) {
        form.submit();
      }
    });
  })();

  var input = document.getElementById('navbarQuickSearch');

  /*
   * Copy-to-clipboard for referral links and registration codes.
   * Any element carrying `data-copy="…"` copies that value; with
   * `data-copy-label` the pressed control briefly says "Copied".
   */
  document.addEventListener('click', function (event) {
    var trigger = event.target && event.target.closest ? event.target.closest('[data-copy]') : null;

    if (!trigger) {
      return;
    }

    var value = trigger.getAttribute('data-copy') || '';
    var done = function () {
      var original = trigger.getAttribute('data-copy-label') || '';

      trigger.classList.add('is-copied');

      if (original !== '') {
        trigger.setAttribute('data-copy-label', 'Copied');
        trigger.setAttribute('aria-label', 'Copied');
      }

      window.setTimeout(function () {
        trigger.classList.remove('is-copied');

        if (original !== '') {
          trigger.setAttribute('data-copy-label', original);
          trigger.setAttribute('aria-label', original);
        }
      }, 1500);
    };

    event.preventDefault();

    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(value).then(done, function () {});
      return;
    }

    /* Fallback for older browsers / insecure origins. */
    var helper = document.createElement('textarea');
    helper.value = value;
    helper.setAttribute('readonly', 'readonly');
    helper.style.position = 'fixed';
    helper.style.opacity = '0';
    document.body.appendChild(helper);
    helper.select();

    try {
      document.execCommand('copy');
      done();
    } catch (error) {
      /* Nothing else to do; the value is visible for manual copying. */
    }

    document.body.removeChild(helper);
  });

  if (!input) {
    return;
  }

  var target = input.getAttribute('data-search-url') || '';

  var go = function () {
    var query = (input.value || '').trim();

    if (query === '' || target === '') {
      return;
    }

    var separator = target.indexOf('?') === -1 ? '?' : '&';
    window.location.assign(target + separator + 'q=' + encodeURIComponent(query));
  };

  input.addEventListener('keydown', function (event) {
    /* A page widget (e.g. the member dashboard table filter) owns Enter. */
    if (event.defaultPrevented) {
      return;
    }

    if (event.key === 'Enter') {
      event.preventDefault();
      go();
      return;
    }

    if (event.key === 'Escape') {
      input.value = '';
      input.blur();
    }
  });

  /* "/" focuses search, the way most admin consoles behave. */
  document.addEventListener('keydown', function (event) {
    var tag = (document.activeElement && document.activeElement.tagName) || '';

    if (event.key !== '/' || /^(INPUT|TEXTAREA|SELECT)$/.test(tag)) {
      return;
    }

    event.preventDefault();
    input.focus();
  });
})();
