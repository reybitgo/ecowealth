/**
 * Ship form — reveal the "Other courier" name only when it is needed.
 *
 * Progressive enhancement only: the field is rendered visible by
 * default, so the form works with JavaScript disabled (the server
 * ignores the free text unless "Others" is selected). With this script
 * the field hides while a listed courier is chosen and requires the
 * name when "Others" is picked.
 */
(() => {
  'use strict';

  const select = document.getElementById('ship-courier');
  const wrap = document.querySelector('[data-courier-other]');

  if (!select || !wrap) {
    return;
  }

  const input = wrap.querySelector('input[name="courier_other"]');
  const otherValue = wrap.getAttribute('data-courier-other-value') || 'Others';

  const sync = () => {
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

  select.addEventListener('change', sync);
  sync();
})();
