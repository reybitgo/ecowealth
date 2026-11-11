/* ==============================================================
 *  Admin settings — section menu
 *
 *  The eight settings cards save independently but share one long page.
 *  The pill bar under the heading jumps to a card, and the pill for the
 *  section currently in view stays active while the page scrolls. Plain
 *  anchors remain the fallback when JavaScript is unavailable; the CSS
 *  scroll-margin keeps a jumped-to card clear of the sticky bar.
 * ============================================================== */

(() => {
  'use strict';

  const root = document.querySelector('[data-settings-tabs]');

  if (!root) {
    return;
  }

  const links = Array.from(root.querySelectorAll('[data-settings-tab]'));
  const sections = links
    .map((link) => {
      const section = document.getElementById(link.dataset.settingsTab);

      return section ? { link, section } : null;
    })
    .filter(Boolean);

  if (sections.length === 0) {
    return;
  }

  const list = root.querySelector('.settings-tab-list');
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const navbar = document.getElementById('layout-navbar');
  let active = null;

  /* Keep the selected pill inside the horizontally scrolling strip. */
  const reveal = (link) => {
    if (!list || list.scrollWidth <= list.clientWidth) {
      return;
    }

    const linkRect = link.getBoundingClientRect();
    const listRect = list.getBoundingClientRect();

    if (linkRect.left < listRect.left) {
      list.scrollLeft -= listRect.left - linkRect.left + 12;
    } else if (linkRect.right > listRect.right) {
      list.scrollLeft += linkRect.right - listRect.right + 12;
    }
  };

  const setActive = (link) => {
    if (active === link) {
      return;
    }

    active = link;

    links.forEach((item) => {
      const selected = item === link;

      item.classList.toggle('active', selected);

      if (selected) {
        item.setAttribute('aria-current', 'location');
      } else {
        item.removeAttribute('aria-current');
      }
    });

    reveal(link);
  };

  /* Everything above this line is the fixed navbar plus the sticky tab
   * bar, so the first card whose top has crossed it is the active one. */
  const readingLine = () => {
    const navbarBottom = navbar ? navbar.getBoundingClientRect().bottom : 0;
    const tabsHeight = root.getBoundingClientRect().height;
    const sticky = window.getComputedStyle(root).position === 'sticky';

    return navbarBottom + (sticky ? tabsHeight : 0) + 24;
  };

  const sync = () => {
    const line = readingLine();
    let current = sections[0];

    for (const entry of sections) {
      if (entry.section.getBoundingClientRect().top > line) {
        break;
      }

      current = entry;
    }

    /* A short last card may never reach the line; still select it once the
     * page is scrolled to the bottom. */
    const atBottom = window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4;

    if (atBottom) {
      current = sections[sections.length - 1];
    }

    setActive(current.link);
  };

  const goTo = (link, updateHash, moveFocus = false) => {
    const section = document.getElementById(link.dataset.settingsTab);

    if (!section) {
      return;
    }

    section.scrollIntoView({
      behavior: reducedMotion.matches ? 'auto' : 'smooth',
      block: 'start',
    });

    if (moveFocus) {
      section.setAttribute('tabindex', '-1');
      section.focus({ preventScroll: true });
    }

    if (updateHash && window.location.hash !== '#' + section.id) {
      window.history.pushState(null, '', '#' + section.id);
    }

    setActive(link);
  };

  links.forEach((link) => {
    link.addEventListener('click', (event) => {
      /* Modified clicks keep the browser's own behaviour (new tab, etc.). */
      if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return;
      }

      event.preventDefault();

      /* A keyboard activation moves focus to the card, like a native
       * fragment link; a mouse click leaves focus on the pill. */
      goTo(link, true, event.detail === 0);
    });
  });

  let ticking = false;
  const onScroll = () => {
    if (ticking) {
      return;
    }

    ticking = true;
    window.requestAnimationFrame(() => {
      sync();
      ticking = false;
    });
  };

  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('resize', onScroll);
  window.addEventListener('popstate', onScroll);
  window.addEventListener('hashchange', onScroll);

  /* A save returns to /admin/settings#settings-<section>; the browser has
   * already scrolled there, so only the active pill needs updating. */
  const fromHash = () => {
    let id = '';

    try {
      id = decodeURIComponent(window.location.hash.slice(1));
    } catch (error) {
      id = '';
    }

    const entry = sections.find((item) => item.section.id === id);

    if (entry) {
      setActive(entry.link);
    } else {
      sync();
    }
  };

  fromHash();
})();
