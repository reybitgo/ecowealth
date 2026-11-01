/**
 * Eco-wealth — Growth overview widgets
 * ------------------------------------
 * Boots the community-growth chart and the members DataTable from the
 * payload printed by dashboard.php (window.ECOWEALTH_DASH).
 */
(function () {
  'use strict';

  var data = window.ECOWEALTH_DASH || {};
  var palette = (window.config && window.config.colors) || {};
  var muted = palette.textMuted || '#8a8f98';
  var border = palette.borderColor || '#e7e7e8';

  /* ---------------------------------------------------------------
   * Community growth (area chart, 7 / 14 / 30 day switcher)
   * Re-rendered per range: patching only `xaxis.categories` makes
   * ApexCharts stack the date labels.
   * ------------------------------------------------------------- */
  var signups = data.signups || { labels: [], values: [] };
  var areaEl = document.querySelector('#registrationsChart');
  var areaChart = null;
  var currentRange = 30;
  var lastWidth = 0;

  var buildAreaOptions = function (range) {
    return {
      chart: {
        type: 'area',
        height: 268,
        parentHeightOffset: 0,
        toolbar: { show: false },
        zoom: { enabled: false },
        animations: { speed: 350 }
      },
      series: [{ name: 'New members', data: signups.values.slice(-range) }],
      colors: [palette.primary || '#7367f0'],
      stroke: { curve: 'smooth', width: 2, lineCap: 'round' },
      fill: {
        type: 'gradient',
        gradient: { shadeIntensity: 1, opacityFrom: 0.42, opacityTo: 0.04, stops: [0, 90, 100] }
      },
      dataLabels: { enabled: false },
      grid: {
        borderColor: border,
        strokeDashArray: 4,
        padding: { left: 8, right: 8, top: -6, bottom: 4 }
      },
      xaxis: {
        categories: signups.labels.slice(-range),
        axisBorder: { show: false },
        axisTicks: { show: false },
        labels: {
          style: { colors: muted, fontSize: '11px' },
          rotate: 0,
          hideOverlappingLabels: true
        },
        tooltip: { enabled: false }
      },
      yaxis: {
        min: 0,
        forceNiceScale: true,
        tickAmount: 3,
        labels: {
          style: { colors: muted, fontSize: '11px' },
          formatter: function (value) { return Math.round(value); }
        }
      },
      markers: { size: 0, hover: { size: 4 } },
      tooltip: {
        shared: true,
        intersect: false,
        y: { formatter: function (value) { return value + (value === 1 ? ' member' : ' members'); } }
      },
      noData: { text: 'No new members in this window', style: { color: muted } }
    };
  };

  var renderArea = function (range) {
    if (!areaEl || !window.ApexCharts) {
      return;
    }

    if (areaChart) {
      areaChart.destroy();
      areaEl.innerHTML = '';
    }

    areaChart = new ApexCharts(areaEl, buildAreaOptions(range));
    areaChart.render();
    window.ECOWEALTH_REGISTRATIONS_CHART = areaChart;
    currentRange = range;
    lastWidth = Math.round(areaEl.clientWidth);
  };

  if (areaEl && window.ApexCharts) {
    renderArea(30);

    /*
     * ApexCharts only listens for window resizes. The content column changes
     * width without one (menu collapse, tablet rotation), so re-render when the
     * container itself resizes — otherwise the canvas keeps its old width.
     */
    if (window.ResizeObserver) {
      var resizeTimer = null;
      new window.ResizeObserver(function (entries) {
        var width = Math.round(entries[0].contentRect.width);
        if (!width || Math.abs(width - lastWidth) < 10) {
          return;
        }
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(function () { renderArea(currentRange); }, 120);
      }).observe(areaEl);
    }

    document.querySelectorAll('[data-chart-range]').forEach(function (item) {
      item.addEventListener('click', function (event) {
        event.preventDefault();

        var range = parseInt(item.getAttribute('data-chart-range'), 10);
        if (!range) {
          return;
        }

        renderArea(range);

        document.querySelectorAll('[data-chart-range]').forEach(function (el) {
          el.classList.toggle('active', el === item);
        });

        var label = document.querySelector('[data-chart-range-label]');
        if (label) {
          label.textContent = 'Last ' + range + ' days';
        }
      });
    });
  }

  /* ---------------------------------------------------------------
   * Community members table (DataTables)
   * Columns: avatar | member | joined | last activity
   * ------------------------------------------------------------- */
  var tableEl = document.querySelector('#membersTable');

  if (tableEl) {
    var options = {
      pageLength: 5,
      lengthMenu: [5, 10, 25, 50],
      order: [[2, 'desc']],
      columnDefs: [
        { orderable: false, targets: [0] },
        { searchable: false, targets: [0] }
      ],
      language: {
        search: '',
        searchPlaceholder: 'Search members…',
        lengthMenu: 'Show _MENU_',
        info: '_START_–_END_ of _TOTAL_ members',
        infoEmpty: 'No members',
        infoFiltered: '(filtered from _MAX_ total)',
        emptyTable: 'No members have registered yet.',
        zeroRecords: 'No member matches that search.',
        paginate: { first: 'First', last: 'Last', next: 'Next', previous: 'Previous' }
      }
    };

    var table = null;

    try {
      if (window.DataTable) {
        table = new window.DataTable(tableEl, options);
      } else if (window.jQuery && window.jQuery.fn && window.jQuery.fn.DataTable) {
        table = window.jQuery(tableEl).DataTable(options);
      }
    } catch (error) {
      /* A plain table is still perfectly readable without the plugin. */
      console.warn('[eco-wealth] DataTables init skipped:', error);
    }

    /* Navbar search filters the community table instead of navigating away. */
    var quickSearch = document.querySelector('#navbarQuickSearch');
    if (table && quickSearch) {
      quickSearch.addEventListener('input', function () {
        table.search(quickSearch.value).draw();
      });
      quickSearch.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
          event.preventDefault();
          var community = document.querySelector('#community');
          if (community) {
            community.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }
        }
      });
    }
  }

  /* ---------------------------------------------------------------
   * Active menu item (`.menu-item.active`) follows the section
   * in view, and stays on the clicked entry until it leaves the view.
   * ------------------------------------------------------------- */
  var spyLinks = Array.prototype.slice.call(document.querySelectorAll('#layout-menu [data-spy-target]'));
  var pillLinks = Array.prototype.slice.call(document.querySelectorAll('.nav-pills a[href^="#"]'));
  var spyIds = [];

  spyLinks.forEach(function (link) {
    var id = link.getAttribute('data-spy-target');
    if (id && spyIds.indexOf(id) === -1) {
      spyIds.push(id);
    }
  });

  /* Page order, so a shared row resolves left-to-right. */
  spyIds.sort(function (a, b) {
    var nodeA = document.getElementById(a);
    var nodeB = document.getElementById(b);
    if (!nodeA || !nodeB) {
      return 0;
    }
    return nodeA.compareDocumentPosition(nodeB) & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1;
  });

  var preferredId = null;
  var preferredUntil = 0;
  var currentActiveId = null;

  var markActive = function (id) {
    spyLinks.forEach(function (link) {
      var item = link.closest('.menu-item');
      if (item) {
        item.classList.toggle('active', link.getAttribute('data-spy-target') === id);
      }
    });

    pillLinks.forEach(function (link) {
      link.classList.toggle('active', link.getAttribute('href') === '#' + id);
    });

    currentActiveId = id;
  };

  var VIEW_LINE = 180;

  var sectionInView = function () {
    /* A click owns the highlight while its smooth scroll settles. */
    if (preferredId && Date.now() < preferredUntil) {
      return preferredId;
    }
    preferredId = null;

    /* Bottom of the page: the last section owns the highlight. */
    if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4) {
      for (var i = spyIds.length - 1; i >= 0; i--) {
        if (document.getElementById(spyIds[i])) {
          return spyIds[i];
        }
      }
    }

    var bestTop = -Infinity;
    var tied = [];

    spyIds.forEach(function (id) {
      var el = document.getElementById(id);
      if (!el) {
        return;
      }
      var box = el.getBoundingClientRect();
      if (box.top <= VIEW_LINE && box.bottom > VIEW_LINE) {
        if (box.top > bestTop + 1) {
          bestTop = box.top;
          tied = [id];
        } else if (Math.abs(box.top - bestTop) <= 1) {
          tied.push(id);
        }
      }
    });

    if (!tied.length) {
      return null;
    }

    /* Cards that share a row keep whichever is already highlighted. */
    if (currentActiveId && tied.indexOf(currentActiveId) !== -1) {
      return currentActiveId;
    }

    return tied[0];
  };

  if (spyIds.length) {
    var refreshActive = function () {
      var id = sectionInView();
      if (id) {
        markActive(id);
      }
    };

    var spyTicking = false;
    window.addEventListener('scroll', function () {
      if (spyTicking) {
        return;
      }
      spyTicking = true;
      window.requestAnimationFrame(function () {
        spyTicking = false;
        refreshActive();
      });
    }, { passive: true });

    window.addEventListener('resize', refreshActive);

    /* Clicking an entry highlights it immediately, before the scroll settles. */
    spyLinks.concat(pillLinks).forEach(function (link) {
      var id = link.getAttribute('data-spy-target') || (link.getAttribute('href') || '').replace('#', '');
      if (!id) {
        return;
      }
      link.addEventListener('click', function () {
        preferredId = id;
        preferredUntil = Date.now() + 1500;
        markActive(id);
      });
    });

    refreshActive();
  }

  /* ---------------------------------------------------------------
   * Smooth in-page navigation for the pills + menu links
   * ------------------------------------------------------------- */
  document.querySelectorAll('a[href^="#"]').forEach(function (link) {
    link.addEventListener('click', function (event) {
      var id = link.getAttribute('href');
      if (!id || id === '#') {
        return;
      }

      var target = document.querySelector(id);
      if (!target) {
        return;
      }

      event.preventDefault();
      target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      history.replaceState(null, '', id);
    });
  });
})();
