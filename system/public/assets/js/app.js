/**
 * EcoWealth — shared shell behaviour
 * ----------------------------------
 * Makes the navbar quick-search a real control instead of decoration:
 * Enter jumps to the catalogue's own search results, "/" focuses it and
 * Escape clears it. Kept dependency-free so it runs before main.js.
 */
(function () {
  'use strict';

  var input = document.getElementById('navbarQuickSearch');

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
