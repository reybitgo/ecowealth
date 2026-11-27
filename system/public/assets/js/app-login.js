/**
 * EcoWealth — sign-in page
 *
 * One small behaviour: the password field's show/hide control. The
 * control is a real button, so the keyboard activates it the same way
 * a mouse does and assistive technology hears its state through
 * aria-pressed. The label names the next action ("Show password" while
 * the password is hidden), so the control never contradicts its state.
 */
(function () {
  'use strict';

  var toggle = document.querySelector('[data-password-toggle]');

  if (!toggle) {
    return;
  }

  var input = document.getElementById(toggle.getAttribute('aria-controls') || '');
  var icon = toggle.querySelector('i');

  if (!input) {
    return;
  }

  toggle.addEventListener('click', function () {
    var show = input.type === 'password';

    input.type = show ? 'text' : 'password';
    toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
    toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');

    if (icon) {
      icon.classList.toggle('bx-hide', !show);
      icon.classList.toggle('bx-show', show);
    }
  });
})();
