/**
 * EcoWealth — confirmation modals
 * -------------------------------------------------
 * Any `<form data-confirm>` asks before submitting, using the theme's
 * SweetAlert2 when it is available and a native dialog as the fallback.
 *
 *   data-confirm-title              modal heading
 *   data-confirm-text               one-line explanation
 *   data-confirm-button             confirm button label
 *   data-confirm-cancel             cancel button label
 *   data-confirm-tone               "danger" for a red confirm button
 *   data-confirm-prompt             field name: ask for a required reason
 *   data-confirm-prompt-label       label above the reason field
 *   data-confirm-prompt-placeholder  placeholder inside it
 *   data-confirm-prompt-max         maximum length (default 500)
 *   data-confirm-prompt-value       initial value (optional)
 *
 * A prompt form never asks the administrator to type in the row itself:
 * the answer is written to a hidden field of that name only after the
 * modal is confirmed, so an accidental click cannot post a decision.
 *
 * The handler is delegated from the document, so a form that a live
 * region swapped in (the order desk's verify / ship / cancel panels, the
 * payout and activation queues) still asks before it posts. The form
 * still works without JavaScript (it posts immediately); the server-side
 * guard remains the source of truth.
 */
(function () {
  'use strict';

  function fieldFor(form, name) {
    var fields = form.elements;

    for (var i = 0; i < fields.length; i++) {
      if (fields[i].name === name) {
        return fields[i];
      }
    }

    return null;
  }

  function setFieldValue(form, name, value) {
    var field = fieldFor(form, name);

    if (!field) {
      field = document.createElement('input');
      field.type = 'hidden';
      field.name = name;
      form.appendChild(field);
    }

    field.value = value;
  }

  function clean(value) {
    return String(value === null || value === undefined ? '' : value)
      .replace(/^\s+|\s+$/g, '');
  }

  /**
   * Submit once the question is answered.
   *
   * `requestSubmit()` re-fires the submit event instead of bypassing it:
   * app-live.js sees the approved form and performs its AJAX mutation,
   * while a plain form simply posts. The marker is removed immediately
   * after the dispatch, so a failed request asks again on the next click.
   */
  function submitNow(form) {
    form.setAttribute('data-confirm-approved', '1');

    try {
      if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
      } else {
        form.submit();
      }
    } finally {
      form.removeAttribute('data-confirm-approved');
    }
  }

  function promptMessage(label, max) {
    return 'Enter ' + label.toLowerCase() + ' (at most ' + max + ' characters).';
  }

  document.addEventListener('submit', function (event) {
    var form = event.target;

    if (!form || !form.matches || !form.matches('form[data-confirm]')) {
      return;
    }

    if (form.getAttribute('data-confirm-approved') === '1') {
      return;
    }

    event.preventDefault();

    var title = form.getAttribute('data-confirm-title') || 'Are you sure?';
    var text = form.getAttribute('data-confirm-text') || '';
    var confirmText = form.getAttribute('data-confirm-button') || 'Confirm';
    var cancelText = form.getAttribute('data-confirm-cancel') || 'Keep it';
    var danger = form.getAttribute('data-confirm-tone') === 'danger';
    var promptName = form.getAttribute('data-confirm-prompt') || '';
    var promptLabel = form.getAttribute('data-confirm-prompt-label') || 'Reason';
    var promptPlaceholder = form.getAttribute('data-confirm-prompt-placeholder') || '';
    var promptMax = parseInt(form.getAttribute('data-confirm-prompt-max'), 10);
    var promptField = promptName === '' ? null : fieldFor(form, promptName);
    var promptValue = form.getAttribute('data-confirm-prompt-value');

    if (!(promptMax > 0)) {
      promptMax = 500;
    }

    if (promptValue === null && promptField && promptField.type === 'hidden') {
      promptValue = promptField.value;
    }

    promptValue = promptValue || '';

    if (window.Swal && typeof window.Swal.fire === 'function') {
      var options = {
        icon: danger ? 'warning' : 'question',
        title: title,
        text: text,
        showCancelButton: true,
        confirmButtonText: confirmText,
        cancelButtonText: cancelText,
        focusCancel: promptName === '',
        focusConfirm: false
      };

      if (promptName !== '') {
        options.input = 'textarea';
        options.inputLabel = promptLabel;
        options.inputPlaceholder = promptPlaceholder;
        options.inputValue = promptValue;
        options.inputAttributes = {
          maxlength: promptMax,
          rows: 4,
          'aria-label': promptLabel
        };
        options.inputValidator = function (value) {
          var answer = clean(value);

          if (answer === '' || answer.length > promptMax) {
            return promptMessage(promptLabel, promptMax);
          }

          return undefined;
        };
      }

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
        if (!result.isConfirmed) {
          return;
        }

        if (promptName !== '') {
          setFieldValue(form, promptName, clean(result.value));
        }

        submitNow(form);
      });

      return;
    }

    /* Native fallback: a prompt is still required before the confirmation. */
    if (promptName !== '') {
      var typed = window.prompt(promptLabel, promptValue);

      if (typed === null) {
        return;
      }

      typed = clean(typed);

      if (typed === '' || typed.length > promptMax) {
        window.alert(promptMessage(promptLabel, promptMax));
        return;
      }

      if (!window.confirm(title + (text === '' ? '' : '\n\n' + text))) {
        return;
      }

      setFieldValue(form, promptName, typed);
      submitNow(form);
      return;
    }

    if (window.confirm(title + (text === '' ? '' : '\n\n' + text))) {
      submitNow(form);
    }
  });
})();
