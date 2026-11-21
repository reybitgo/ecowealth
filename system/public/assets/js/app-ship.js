/**
 * Ship form — reveal the "Other courier" name only when it is needed.
 *
 * Progressive enhancement only: the field is rendered visible by
 * default, so the form works with JavaScript disabled (the server
 * ignores the free text unless "Others" is selected). With this script
 * the field hides while a listed courier is chosen and requires the
 * name when "Others" is picked.
 *
 * The order page is a live surface: the ship panel can be replaced by a
 * background refresh, so the listener is attached to the new select and
 * the initial sync runs again after every swap.
 */
(() => {
  'use strict';

  const sync = () => {
    const select = document.getElementById('ship-courier');
    const wrap = document.querySelector('[data-courier-other]');

    if (!select || !wrap) {
      return;
    }

    const input = wrap.querySelector('input[name="courier_other"]');
    const otherValue = wrap.getAttribute('data-courier-other-value') || 'Others';

    const chosen = select.value;
    const isOther = chosen === otherValue;

    /* Placeholder and "Others" keep the field visible; a listed
     * courier hides it. `hidden` is the no-style fallback, so the
     * toggle works even without the theme's CSS. */
    wrap.hidden = chosen !== '' && !isOther;

    if (input) {
      input.required = isOther;
    }
  };

  const bind = (select) => {
    if (!select || select.getAttribute('data-ship-bound') === '1') {
      return;
    }

    select.setAttribute('data-ship-bound', '1');
    select.addEventListener('change', sync);
  };

  const init = () => {
    const select = document.getElementById('ship-courier');

    if (!select) {
      return;
    }

    bind(select);
    sync();
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  document.addEventListener('ecowealth:live-applied', init);
})();
