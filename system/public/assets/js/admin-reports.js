/**
 * EcoWealth — admin reports widgets (Phase 15)
 * --------------------------------------------
 * Boots the sales-by-day area chart from the payload printed by
 * views/admin/reports/index.php (window.ECOWEALTH_REPORTS).
 *
 * The reports page is a live surface. When app-live.js swaps the sales
 * card it dispatches `ecowealth:live-applied` with the new chart data;
 * this script destroys the old ApexCharts instance and renders the new
 * one. Without ApexCharts the card keeps its table and totals.
 *
 * It also drives the section menu (UX-14): the ten report families sit on
 * one page, so the shared `.section-nav` bar jumps to a family and keeps
 * the link for the section in view active. Plain anchors work without
 * this script; the CSS scroll-margin keeps a jump clear of the sticky bar.
 */
(function () {
  'use strict';

  var palette = (window.config && window.config.colors) || {};

  var primary = palette.primary || '#7367f0';
  var muted = palette.textMuted || '#8a8f98';
  var border = palette.borderColor || '#e7e7e8';

  var salesChart = null;

  var phpCompact = function (value) {
    var amount = Number(value || 0);

    if (Math.abs(amount) >= 1000000) {
      return '₱' + (amount / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
    }

    if (Math.abs(amount) >= 1000) {
      return '₱' + (amount / 1000).toFixed(1).replace(/\.0$/, '') + 'K';
    }

    return '₱' + amount;
  };

  var php = function (value) {
    return '₱' + Number(value || 0).toLocaleString('en-PH', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
  };

  function destroyChart(chart) {
    if (chart && typeof chart.destroy === 'function') {
      try {
        chart.destroy();
      } catch (error) {
        /* The old container may already be detached; nothing to clean. */
      }
    }
  }

  function renderSales(sales) {
    destroyChart(salesChart);
    salesChart = null;

    sales = sales || {};

    var el = document.querySelector('#reportSalesChart');
    var labels = Array.isArray(sales.labels) ? sales.labels : [];
    var values = Array.isArray(sales.values) ? sales.values.map(Number) : [];
    var total = values.reduce(function (sum, value) { return sum + value; }, 0);

    if (!el || !window.ApexCharts || labels.length === 0 || total <= 0) {
      return;
    }

    salesChart = new window.ApexCharts(el, {
      chart: {
        type: 'area',
        height: 260,
        parentHeightOffset: 0,
        toolbar: { show: false },
        animations: { speed: 350 },
        fontFamily: 'inherit'
      },
      series: [{ name: 'Sales', data: values }],
      colors: [primary],
      stroke: { curve: 'smooth', width: 2 },
      fill: {
        type: 'gradient',
        gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.05, stops: [0, 90, 100] }
      },
      dataLabels: { enabled: false },
      markers: { size: 0, hover: { size: 5 } },
      grid: {
        borderColor: border,
        strokeDashArray: 4,
        padding: { left: 8, right: 12, top: -8, bottom: 0 },
        xaxis: { lines: { show: false } },
        yaxis: { lines: { show: true } }
      },
      xaxis: {
        categories: labels,
        tickAmount: Math.min(8, Math.max(2, labels.length - 1)),
        labels: { style: { colors: muted, fontSize: '11px' }, rotate: 0, hideOverlappingLabels: true },
        axisBorder: { show: false },
        axisTicks: { show: false }
      },
      yaxis: {
        labels: {
          style: { colors: muted, fontSize: '11px' },
          formatter: phpCompact
        }
      },
      tooltip: {
        y: { formatter: php }
      },
      noData: { text: 'No paid sales yet', style: { color: muted } }
    });

    salesChart.render();
  }

  function render(payload) {
    renderSales((payload || {}).sales || {});
  }

  /* Initial page load. */
  render(window.ECOWEALTH_REPORTS || {});

  /* A live region was replaced by app-live.js. Only re-render the chart
   * when its own card was actually applied. */
  document.addEventListener('ecowealth:live-applied', function (event) {
    var detail = event.detail || {};

    if (detail.surface !== 'admin.reports' || !detail.payload) {
      return;
    }

    var applied = detail.applied || [];

    if (applied.indexOf('report-sales') !== -1) {
      renderSales((detail.payload.data || {}).sales || {});
    }
  });

  window.EcoWealthReports = { render: render };
})();

/* ---------- Section menu (UX-14) ---------- */
(function () {
  'use strict';

  var root = document.querySelector('[data-report-sections]');

  if (!root) {
    return;
  }

  var links = Array.prototype.slice.call(root.querySelectorAll('[data-report-section]'));
  var sections = links
    .map(function (link) {
      var section = document.getElementById(link.getAttribute('data-report-section'));

      return section ? { link: link, section: section } : null;
    })
    .filter(Boolean);

  if (sections.length === 0) {
    return;
  }

  var list = root.querySelector('.reports-tab-list');
  var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  var navbar = document.getElementById('layout-navbar');
  var active = null;

  /* Keep the selected link inside the horizontally scrolling strip. */
  var reveal = function (link) {
    if (!list || list.scrollWidth <= list.clientWidth) {
      return;
    }

    var linkRect = link.getBoundingClientRect();
    var listRect = list.getBoundingClientRect();

    if (linkRect.left < listRect.left) {
      list.scrollLeft -= listRect.left - linkRect.left + 12;
    } else if (linkRect.right > listRect.right) {
      list.scrollLeft += linkRect.right - listRect.right + 12;
    }
  };

  var setActive = function (link) {
    if (active === link) {
      return;
    }

    active = link;

    links.forEach(function (item) {
      var selected = item === link;

      item.classList.toggle('active', selected);

      if (selected) {
        item.setAttribute('aria-current', 'location');
      } else {
        item.removeAttribute('aria-current');
      }
    });

    reveal(link);
  };

  /* Everything above this line is the fixed navbar plus the sticky bar,
   * so the first section whose top has crossed it is the active one. */
  var readingLine = function () {
    var navbarBottom = navbar ? navbar.getBoundingClientRect().bottom : 0;
    var tabsHeight = root.getBoundingClientRect().height;
    var sticky = window.getComputedStyle(root).position === 'sticky';

    return navbarBottom + (sticky ? tabsHeight : 0) + 24;
  };

  var sync = function () {
    var line = readingLine();
    var current = sections[0];

    for (var i = 0; i < sections.length; i++) {
      if (sections[i].section.getBoundingClientRect().top > line) {
        break;
      }

      current = sections[i];
    }

    /* The short last section may never reach the line; still select it
     * once the page is scrolled to the bottom. */
    var atBottom = window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4;

    if (atBottom) {
      current = sections[sections.length - 1];
    }

    setActive(current.link);
  };

  var goTo = function (link, updateHash, moveFocus) {
    var section = document.getElementById(link.getAttribute('data-report-section'));

    if (!section) {
      return;
    }

    section.scrollIntoView({
      behavior: reducedMotion.matches ? 'auto' : 'smooth',
      block: 'start'
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

  links.forEach(function (link) {
    link.addEventListener('click', function (event) {
      /* Modified clicks keep the browser's own behaviour (new tab, etc.). */
      if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return;
      }

      event.preventDefault();

      /* A keyboard activation moves focus to the section, like a native
       * fragment link; a mouse click leaves focus on the link. */
      goTo(link, true, event.detail === 0);
    });
  });

  var ticking = false;
  var onScroll = function () {
    if (ticking) {
      return;
    }

    ticking = true;
    window.requestAnimationFrame(function () {
      sync();
      ticking = false;
    });
  };

  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('resize', onScroll);
  window.addEventListener('popstate', onScroll);
  window.addEventListener('hashchange', onScroll);

  /* A direct #report-… link or a Back/Forward jump: the browser has
   * already scrolled there, so only the active link needs updating. */
  var fromHash = function () {
    var id = '';

    try {
      id = decodeURIComponent(window.location.hash.slice(1));
    } catch (error) {
      id = '';
    }

    var entry = sections.filter(function (item) {
      return item.section.id === id;
    })[0];

    if (entry) {
      setActive(entry.link);
    } else {
      sync();
    }
  };

  fromHash();
})();
