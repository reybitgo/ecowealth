/**
 * EcoWealth — back-office dashboard widgets
 * -----------------------------------------
 * Boots the catalogue-value bar chart and the stock-health donut from the
 * payload printed by views/admin/dashboard.php (window.ECOWEALTH_ADMIN).
 *
 * The dashboard is a live surface. When app-live.js swaps the catalogue or
 * stock card, it dispatches `ecowealth:live-applied` with the new chart data;
 * this script destroys the old ApexCharts instance and renders the new one.
 *
 * Every widget degrades to a readable empty state without ApexCharts.
 */
(function () {
  'use strict';

  var palette = (window.config && window.config.colors) || {};

  var primary = palette.primary || '#7367f0';
  var success = palette.success || '#28c76f';
  var warning = palette.warning || '#ff9f43';
  var danger = palette.danger || '#ea5455';
  var muted = palette.textMuted || '#8a8f98';
  var border = palette.borderColor || '#e7e7e8';

  var catalogueChart = null;
  var stockChart = null;

  var php = function (value, decimals) {
    return '₱' + Number(value || 0).toLocaleString('en-PH', {
      minimumFractionDigits: decimals ? 2 : 0,
      maximumFractionDigits: decimals ? 2 : 0
    });
  };

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

  function destroyChart(chart) {
    if (chart && typeof chart.destroy === 'function') {
      try {
        chart.destroy();
      } catch (error) {
        /* The old container may already be detached; nothing to clean. */
      }
    }
  }

  /* ---------------------------------------------------------------
   * Catalogue value (bar chart)
   * ------------------------------------------------------------- */
  function renderCatalogue(catalogue) {
    destroyChart(catalogueChart);
    catalogueChart = null;

    catalogue = catalogue || {};

    var barEl = document.querySelector('#catalogueValueChart');
    var hasCatalogue = Array.isArray(catalogue.labels) && catalogue.labels.length > 0
      && (catalogue.values || []).reduce(function (sum, value) { return sum + Number(value || 0); }, 0) > 0;

    if (!barEl || !window.ApexCharts || !hasCatalogue) {
      return;
    }

    /* Size the canvas to the rows instead of a fixed 390px: a short list then
     * sits centred instead of floating in empty space, and a longer one gets
     * room for readable labels. Eight rows plus the total row is the cap the
     * server sends. */
    var rows    = catalogue.labels.length;
    var height  = Math.max(220, Math.min(420, rows * 44 + 32));
    var compact = window.matchMedia('(max-width: 575.98px)').matches;

    /* Long product names would push the plot off a phone screen, so the axis
     * shortens them and the tooltip keeps the full name. The folded row reads
     * "Other +131" instead of "Other 131 products". */
    var axisLabel = function (value) {
      var text  = String(value);
      var other = text.match(/^Other (\d+) products?$/);

      if (other) {
        return compact ? 'Other +' + other[1] : text;
      }

      var max = compact ? 14 : 28;

      return text.length > max ? text.slice(0, max - 1) + '…' : text;
    };

    catalogueChart = new window.ApexCharts(barEl, {
      chart: {
        type: 'bar',
        height: height,
        parentHeightOffset: 0,
        toolbar: { show: false },
        animations: { speed: 350 }
      },
      series: [{ name: 'Stock value', data: catalogue.values }],
      colors: [primary],
      plotOptions: {
        bar: {
          horizontal: true,
          barHeight: '56%',
          borderRadius: 5,
          borderRadiusApplication: 'end',
          /* Value sits just past the end of each bar, never inside it. */
          dataLabels: { position: 'right' }
        }
      },
      dataLabels: {
        /* A phone has room for bars or for values, not both; tap shows the
         * value through the tooltip. */
        enabled: !compact,
        textAnchor: 'start',
        offsetX: 8,
        formatter: phpCompact,
        style: { fontSize: '11px', fontWeight: 600, colors: [muted] }
      },
      stroke: { width: 0 },
      grid: {
        borderColor: border,
        strokeDashArray: 4,
        xaxis: { lines: { show: true } },
        yaxis: { lines: { show: false } },
        /* Room on the right for the value labels. */
        padding: { left: 8, right: compact ? 12 : 52, top: -8, bottom: 0 }
      },
      xaxis: {
        categories: catalogue.labels,
        tickAmount: 4,
        labels: {
          style: { colors: muted, fontSize: '11px' },
          formatter: phpCompact
        },
        axisBorder: { show: false },
        axisTicks: { show: false }
      },
      yaxis: {
        labels: {
          maxWidth: compact ? 110 : 220,
          formatter: axisLabel,
          style: { colors: muted, fontSize: '12px' }
        }
      },
      tooltip: {
        y: { formatter: function (value) { return php(value, true); } }
      },
      noData: { text: 'No catalogue data yet', style: { color: muted } }
    });

    catalogueChart.render();
  }

  /* ---------------------------------------------------------------
   * Stock health (donut)
   * ------------------------------------------------------------- */
  function renderStock(stock) {
    destroyChart(stockChart);
    stockChart = null;

    stock = stock || {};

    var donutEl = document.querySelector('#stockHealthChart');
    var stockValues = Array.isArray(stock.values) ? stock.values.map(Number) : [];
    var stockTotal = stockValues.reduce(function (sum, value) { return sum + value; }, 0);

    if (!donutEl || !window.ApexCharts || stockTotal <= 0) {
      return;
    }

    stockChart = new window.ApexCharts(donutEl, {
      chart: {
        type: 'donut',
        height: 220,
        parentHeightOffset: 0,
        animations: { speed: 350 }
      },
      series: stockValues,
      labels: stock.labels || [],
      colors: [success, warning, danger],
      stroke: { width: 0 },
      legend: { show: false },
      dataLabels: { enabled: false },
      plotOptions: {
        pie: {
          donut: {
            size: '74%',
            labels: {
              show: true,
              name: {
                show: true,
                offsetY: -8,
                style: { fontSize: '12px', color: muted, fontFamily: 'inherit' }
              },
              value: {
                show: true,
                offsetY: 4,
                formatter: function (value) { return value; },
                style: { fontSize: '1.25rem', fontWeight: 600, color: palette.headingColor || '#2f3349' }
              },
              total: {
                show: true,
                label: 'Products',
                color: muted,
                formatter: function () { return String(stockTotal); },
                style: { fontSize: '12px', fontFamily: 'inherit' }
              }
            }
          }
        }
      },
      tooltip: {
        y: {
          formatter: function (value) {
            return value + (Number(value) === 1 ? ' product' : ' products');
          }
        }
      }
    });

    stockChart.render();
  }

  function render(payload) {
    payload = payload || {};

    renderCatalogue(payload.catalogue || {});
    renderStock(payload.stock || {});
  }

  /* Initial page load. */
  render(window.ECOWEALTH_ADMIN || {});

  /* A live region was replaced by app-live.js. Only re-render a chart
   * whose own card was actually applied (a deferred card may still be
   * waiting for focus to leave). */
  document.addEventListener('ecowealth:live-applied', function (event) {
    var detail = event.detail || {};

    if (detail.surface !== 'admin.dashboard' || !detail.payload) {
      return;
    }

    var data = detail.payload.data || {};
    var applied = detail.applied || [];

    if (applied.indexOf('dashboard-catalogue') !== -1) {
      renderCatalogue(data.catalogue || {});
    }

    if (applied.indexOf('dashboard-stock') !== -1) {
      renderStock(data.stock || {});
    }
  });

  window.EcoWealthAdminDashboard = { render: render };
})();
