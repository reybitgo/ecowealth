/**
 * EcoWealth — admin registration codes (plan §7 Phase 9)
 * ------------------------------------------------------
 * Pre-fills the price of a new code batch from the package's own
 * package price when the admin picks a package. Purely a convenience:
 * the price is a free field and the server validates it.
 */
(function () {
  'use strict';

  var packageSelect = document.querySelector('[data-code-package]');
  var priceInput = document.querySelector('[data-code-price]');

  if (!packageSelect || !priceInput) {
    return;
  }

  packageSelect.addEventListener('change', function () {
    var option = packageSelect.options[packageSelect.selectedIndex];
    var price = option ? option.getAttribute('data-price') : '';

    if (price && (priceInput.value === '' || priceInput.getAttribute('data-prefilled') === '1')) {
      priceInput.value = price;
      priceInput.setAttribute('data-prefilled', '1');
    }
  });

  /* Stop overwriting a hand-typed price. */
  priceInput.addEventListener('input', function () {
    priceInput.setAttribute('data-prefilled', '0');
  });

  /* First render: fill an empty price from the current selection. */
  packageSelect.dispatchEvent(new Event('change'));
})();
