/**
 * EcoWealth — back-office dashboard widgets
 * -----------------------------------------
 * Boots the catalogue-value bar chart and the stock-health donut from
 * the payload printed by views/admin/dashboard.php (window.ECOWEALTH_ADMIN).
 * Every widget degrades to a readable empty state without ApexCharts.
 */
(function () {
  'use strict';

  var payload = window.ECOWEALTH_ADMIN || {};
  var palette = (window.config && window.config.colors) || {};

  var primary = palette.primary || '#7367f0';
  var success = palette.success || '#28c76f';
  var warning = palette.warning || '#ff9f43';
  var danger = palette.danger || '#ea5455';
  var muted = palette.textMuted || '#8a8f98';
  var border = palette.borderColor || '#e7e7e8';

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

  /* ---------------------------------------------------------------
   * Catalogue value (bar chart)
   * ------------------------------------------------------------- */
  var catalogue = payload.catalogue || {};
  var barEl = document.querySelector('#catalogueValueChart');
  var hasCatalogue = Array.isArray(catalogue.labels) && catalogue.labels.length > 0;

  if (barEl && window.ApexCharts && hasCatalogue) {
    new window.ApexCharts(barEl, {
      chart: {
        type: 'bar',
        height: 390,
        parentHeightOffset: 0,
        toolbar: { show: false },
        animations: { speed: 350 }
      },
      series: [{ name: 'Stock value', data: catalogue.values }],
      colors: [primary],
      plotOptions: {
        bar: {
          horizontal: true,
          barHeight: '58%',
          borderRadius: 5,
          borderRadiusApplication: 'end'
        }
      },
      dataLabels: { enabled: false },
      stroke: { width: 0 },
      grid: {
        borderColor: border,
        strokeDashArray: 4,
        xaxis: { lines: { show: true } },
        yaxis: { lines: { show: false } },
        padding: { left: 8, right: 12, top: -8, bottom: 0 }
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
          maxWidth: 160,
          style: { colors: muted, fontSize: '12px' }
        }
      },
      tooltip: {
        y: { formatter: function (value) { return php(value, true); } }
      },
      noData: { text: 'No catalogue data yet', style: { color: muted } }
    }).render();
  }

  /* ---------------------------------------------------------------
   * Stock health (donut)
   * ------------------------------------------------------------- */
  var stock = payload.stock || {};
  var donutEl = document.querySelector('#stockHealthChart');
  var stockValues = Array.isArray(stock.values) ? stock.values.map(Number) : [];
  var stockTotal = stockValues.reduce(function (sum, value) { return sum + value; }, 0);

  if (donutEl && window.ApexCharts && stockTotal > 0) {
    new window.ApexCharts(donutEl, {
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
    }).render();
  }
})();
