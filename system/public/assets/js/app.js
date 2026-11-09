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

    /* Scroll back to the block the user was paging: the nearest explicit
     * anchor (a compact card with its own pager, such as a level card in
     * Team by level) wins over the whole section card. */
    var anchorOf = function (bar) {
      if (!bar.closest) {
        return bar;
      }

      return bar.closest('[data-pagination-anchor]') || bar.closest('.card') || bar;
    };

    var remember = function (bar) {
      if (!bar) {
        return;
      }

      var anchor = anchorOf(bar);

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

      var anchor = anchorOf(bar);
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
   *
   * Any element carrying `data-copy="…"` copies that value. On success
   * the copy icon turns into a check mark, the button text (when it has
   * one, marked `data-copy-text`) reads "Copied", and the Bootstrap
   * tooltip switches to "Copied!" for ~1.8 s — the same feedback the
   * Sneat reference uses for its token-contract copy control.
   *
   * Hover text comes from `data-copy-tooltip` (Bootstrap clears the
   * native `title` once a tooltip is initialised, so the explicit
   * attribute is the stable source). Tooltips are initialised here as a
   * fallback in case the theme did not pick the element up.
   */
  var copyTooltipFor = function (trigger) {
    return window.bootstrap && bootstrap.Tooltip ? bootstrap.Tooltip.getInstance(trigger) : null;
  };

  var setCopyTooltip = function (trigger, text) {
    trigger.setAttribute('title', text);
    trigger.setAttribute('data-bs-original-title', text);

    var tooltip = copyTooltipFor(trigger);

    if (tooltip) {
      tooltip.setContent({ '.tooltip-inner': text });
      tooltip.update();
    }
  };

  (function () {
    var initTooltips = function () {
      if (!window.bootstrap || !bootstrap.Tooltip) {
        return;
      }

      document.querySelectorAll('[data-copy]').forEach(function (trigger) {
        if (bootstrap.Tooltip.getInstance(trigger)) {
          return;
        }

        new bootstrap.Tooltip(trigger, { trigger: 'hover focus' });
      });
    };

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', initTooltips);
    } else {
      initTooltips();
    }
  })();

  document.addEventListener('click', function (event) {
    var trigger = event.target && event.target.closest ? event.target.closest('[data-copy]') : null;

    if (!trigger) {
      return;
    }

    var value = trigger.getAttribute('data-copy') || '';

    /* Capture the resting state once, so a second click during the
     * "Copied" window still restores the real label/tooltip. */
    if (!trigger.dataset.copyOriginal) {
      var restingLabel = trigger.querySelector('[data-copy-text]');

      trigger.dataset.copyOriginal = '1';
      trigger.dataset.copyTitle = trigger.getAttribute('data-copy-tooltip') || trigger.getAttribute('title') || 'Copy';
      trigger.dataset.copyLabel = restingLabel ? restingLabel.textContent : '';
      trigger.dataset.copyAria = trigger.getAttribute('aria-label') || 'Copy';
    }

    var resting = {
      title: trigger.dataset.copyTitle,
      label: trigger.dataset.copyLabel,
      aria: trigger.dataset.copyAria
    };

    var done = function () {
      var icon = trigger.querySelector('i.icon-base') || trigger.querySelector('i');
      var label = trigger.querySelector('[data-copy-text]');
      var tooltip = copyTooltipFor(trigger);

      if (trigger.copyTimer) {
        window.clearTimeout(trigger.copyTimer);
      }

      trigger.classList.add('is-copied');
      trigger.setAttribute('aria-label', 'Copied');

      if (icon) {
        icon.classList.remove('bx-copy');
        icon.classList.add('bx-check');
      }

      if (label) {
        label.textContent = 'Copied';
      }

      setCopyTooltip(trigger, 'Copied!');

      if (tooltip) {
        tooltip.show();
      }

      trigger.copyTimer = window.setTimeout(function () {
        trigger.classList.remove('is-copied');
        trigger.setAttribute('aria-label', resting.aria);

        if (icon) {
          icon.classList.remove('bx-check');
          icon.classList.add('bx-copy');
        }

        if (label && resting.label !== '') {
          label.textContent = resting.label;
        }

        setCopyTooltip(trigger, resting.title);

        if (tooltip) {
          tooltip.hide();
        }
      }, 1800);
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
