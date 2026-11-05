/**
 * EcoWealth — confirmation modals
 * -------------------------------------------------
 * Any `<form data-confirm>` asks before submitting, using the theme's
 * SweetAlert2 when it is available and a native dialog as the fallback.
 *
 *   data-confirm-title    modal heading
 *   data-confirm-text     one-line explanation
 *   data-confirm-button   confirm button label
 *   data-confirm-tone     "danger" for a red confirm button
 *
 * The form still works without JavaScript (it posts immediately); the
 * server-side guard remains the source of truth.
 */
(function () {
  'use strict';

  var forms = document.querySelectorAll('form[data-confirm]');

  if (!forms.length) {
    return;
  }

  function submitNow(form) {
    form.setAttribute('data-confirm-approved', '1');
    form.submit();
  }

  forms.forEach(function (form) {
    form.addEventListener('submit', function (event) {
      if (form.getAttribute('data-confirm-approved') === '1') {
        return;
      }

      event.preventDefault();

      var title = form.getAttribute('data-confirm-title') || 'Are you sure?';
      var text = form.getAttribute('data-confirm-text') || '';
      var confirmText = form.getAttribute('data-confirm-button') || 'Confirm';
      var cancelText = form.getAttribute('data-confirm-cancel') || 'Keep it';
      var danger = form.getAttribute('data-confirm-tone') === 'danger';

      if (window.Swal && typeof window.Swal.fire === 'function') {
        var options = {
          icon: danger ? 'warning' : 'question',
          title: title,
          text: text,
          showCancelButton: true,
          confirmButtonText: confirmText,
          cancelButtonText: cancelText,
          focusCancel: true
        };

        if (danger) {
          options.confirmButtonColor = '#d33';
          options.didOpen = function (popup) {
            var button = popup.querySelector('.swal2-confirm');

            if (button) {
              button.style.setProperty('background-color', '#d33', 'important');
              button.style.setProperty('border-color', '#d33', 'important');
              button.style.setProperty('color', '#ffffff', 'important');
            }
          };
        }

        window.Swal.fire(options).then(function (result) {
          if (result.isConfirmed) {
            submitNow(form);
          }
        });

        return;
      }

      if (window.confirm(title + (text === '' ? '' : '\n\n' + text))) {
        submitNow(form);
      }
    });
  });
})();
