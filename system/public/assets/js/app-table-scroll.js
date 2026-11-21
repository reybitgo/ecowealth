/**
 * Top horizontal scrollbar for a wide table.
 *
 * Some tables are wider than their card. Browsers put the native
 * horizontal scrollbar at the bottom of the scroll area, so the user has
 * to scroll to the bottom of a long table before they can pan sideways.
 * This draws a small, always-visible track + thumb at the top of the
 * card and keeps it in sync with the native scroll area.
 *
 * Markup contract:
 *
 *   <div data-table-scroll>
 *     <div data-table-scroll-top>
 *       <div data-table-scroll-thumb></div>
 *     </div>
 *     <div data-table-scroll-body> ... table ... </div>
 *   </div>
 *
 * The top scrollbar is hidden when the table fits the card. It is
 * re-measured after a live-region refresh (and on resize), because the
 * row count and column widths can change without a page reload.
 */
(function () {
  'use strict';

  var SELECTOR = '[data-table-scroll]';

  function setup(wrapper) {
    if (wrapper.getAttribute('data-table-scroll-ready') === '1') {
      return;
    }

    var top = wrapper.querySelector('[data-table-scroll-top]');
    var thumb = top ? top.querySelector('[data-table-scroll-thumb]') : null;
    var body = wrapper.querySelector('[data-table-scroll-body]');

    if (!top || !thumb || !body) {
      return;
    }

    wrapper.setAttribute('data-table-scroll-ready', '1');

    var dragging = false;
    var dragStartX = 0;
    var dragStartScroll = 0;
    var maxScroll = 0;
    var maxLeft = 0;

    function draw() {
      var clientWidth = body.clientWidth;
      var scrollWidth = body.scrollWidth;

      maxScroll = Math.max(0, scrollWidth - clientWidth);

      if (maxScroll <= 1) {
        top.hidden = true;
        return;
      }

      top.hidden = false;

      var trackWidth = top.clientWidth;
      var thumbWidth = Math.max(40, Math.round(trackWidth * clientWidth / scrollWidth));
      maxLeft = Math.max(0, trackWidth - thumbWidth);

      var left = maxScroll === 0 ? 0 : Math.round((body.scrollLeft / maxScroll) * maxLeft);

      thumb.style.width = thumbWidth + 'px';
      thumb.style.transform = 'translateX(' + left + 'px)';
    }

    function scrollTo(clientX) {
      if (maxLeft <= 0) {
        return;
      }

      var rect = top.getBoundingClientRect();
      var ratio = (clientX - rect.left - (parseFloat(thumb.style.width) || 0) / 2) / maxLeft;
      var clamped = Math.max(0, Math.min(1, ratio));

      body.scrollLeft = Math.round(clamped * maxScroll);
      draw();
    }

    top.addEventListener('pointerdown', function (event) {
      if (maxScroll <= 1) {
        return;
      }

      event.preventDefault();
      dragging = true;
      dragStartX = event.clientX;
      dragStartScroll = body.scrollLeft;
      top.classList.add('is-dragging');
      top.setPointerCapture(event.pointerId);

      if (!event.target.closest('[data-table-scroll-thumb]')) {
        scrollTo(event.clientX);
      }
    });

    top.addEventListener('pointermove', function (event) {
      if (!dragging || maxLeft <= 0) {
        return;
      }

      var delta = event.clientX - dragStartX;
      body.scrollLeft = dragStartScroll + Math.round((delta / maxLeft) * maxScroll);
      draw();
    });

    function endDrag(event) {
      if (!dragging) {
        return;
      }

      dragging = false;
      top.classList.remove('is-dragging');

      try {
        top.releasePointerCapture(event.pointerId);
      } catch (error) {
        /* The pointer capture may already be gone. */
      }
    }

    top.addEventListener('pointerup', endDrag);
    top.addEventListener('pointercancel', endDrag);

    body.addEventListener('scroll', draw, { passive: true });
    window.addEventListener('resize', draw);

    if (window.ResizeObserver) {
      new ResizeObserver(draw).observe(body);
    }

    if (window.MutationObserver) {
      new MutationObserver(draw).observe(body, { childList: true, subtree: true });
    }

    document.addEventListener('ecowealth:live-applied', draw);

    if (document.readyState === 'complete') {
      draw();
    } else {
      window.addEventListener('load', draw);
    }

    draw();
  }

  function init() {
    var wrappers = document.querySelectorAll(SELECTOR);

    for (var i = 0; i < wrappers.length; i++) {
      setup(wrappers[i]);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
