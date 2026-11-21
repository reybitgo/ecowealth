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
