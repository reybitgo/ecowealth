/**
 * EcoWealth — binary tree explorer (Phase 22G-S3, refreshed UI)
 * ------------------------------------------------------------
 * Reads the server-rendered seed JSON (#binaryTreeSeed) and draws the
 * placement tree as a responsive SVG.
 *
 * The card itself stays quiet: a status-coloured pill with the member's
 * username, an expander and an optional "+" for a free slot. Everything
 * else — package, joined date, placement, points and actions — lives in
 * the rich tooltip, so a deep tree never turns into a wall of badges.
 *
 * Interactions:
 *   • hover / focus a node    → tooltip follows the node
 *   • click / tap a node      → pin the tooltip (touch-friendly)
 *   • expander / + badge      → expand branch / open registration
 *   • drag, wheel, pinch      → pan and zoom
 *   • keyboard                → arrows walk and expand, Enter opens details
 *
 * Progressive enhancement: without JavaScript the no-JS semantic list
 * (22G-S2) is the document. If d3 fails to load, a notice appears and
 * the list stays usable. d3 is vendored locally (no CDN).
 *
 * Every number, permission and slot the JS draws comes from the server
 * seed; the JS never computes points, pairs or access.
 */

(function () {
  'use strict';

  /* ----------------------------------------------------------
   *  Only runs on the binary tree page.
   * ---------------------------------------------------------- */
  var seedEl = document.getElementById('binaryTreeSeed');
  if (!seedEl) {
    return;
  }

  var payload = {};
  try {
    payload = JSON.parse(seedEl.textContent || '{}');
  } catch (e) {
    return;
  }

  var tree     = (payload && payload.tree) || {};
  var viewerId = parseInt(payload.viewer || 0, 10) || 0;
  var isAdmin  = !!(payload.is_admin);
  var startDepth = Math.max(1, Math.min(5, parseInt(payload.depth || 3, 10) || 3));

  /* ----------------------------------------------------------
   *  DOM nodes
   * ---------------------------------------------------------- */
  var svgWrap  = document.getElementById('binaryTreeSvgWrap');
  var listWrap = document.getElementById('binaryTreeListWrap');
  var noticeEl = document.getElementById('binaryTreeNotice');

  if (!svgWrap) {
    svgWrap = document.createElement('div');
    svgWrap.id = 'binaryTreeSvgWrap';
    svgWrap.className = 'binary-tree-svg-wrap';
    seedEl.parentNode.insertBefore(svgWrap, seedEl.nextSibling);
  }

  /* ----------------------------------------------------------
   *  Responsive helpers
   * ---------------------------------------------------------- */
  var mobileMedia = window.matchMedia('(max-width: 767.98px)');
  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

  function bodyHas(cls) {
    return document.body.classList.contains(cls);
  }
  function mobileView() {
    return mobileMedia.matches;
  }
  function coarsePointer() {
    return window.matchMedia('(hover: none)').matches;
  }
  function nodeWidth()  {
    if (mobileView()) { return 126; }
    return bodyHas('binary-compact') ? 150 : 176;
  }
  function nodeHeight() {
    if (mobileView()) { return 38; }
    return bodyHas('binary-compact') ? 42 : 46;
  }
  function levelHeight() {
    if (mobileView()) { return 82; }
    return bodyHas('binary-compact') ? 96 : 112;
  }
  function siblingSpacing() {
    if (mobileView()) { return 144; }
    return bodyHas('binary-compact') ? 176 : 208;
  }
  function horizontalSiblingSpacing() {
    return nodeHeight() + 34;
  }

  /* ----------------------------------------------------------
   *  SVG
   * ---------------------------------------------------------- */
  var svg = d3.select(svgWrap).append('svg')
    .attr('class', 'binary-svg')
    .attr('width', '100%')
    .attr('height', '100%')
    .attr('preserveAspectRatio', 'xMidYMid meet')
    .style('font-family', 'inherit')
    .style('display', 'block');

  var defs = svg.append('defs');
  addGradient(defs, 'binaryGradActive', '#16a34a', '#0b7a45');
  addGradient(defs, 'binaryGradPending', '#f59e0b', '#d97706');
  addGradient(defs, 'binaryGradSuspended', '#ef4444', '#b91c1c');
  addGradient(defs, 'binaryGradInactive', '#94a3b8', '#64748b');

  function addGradient(parent, id, from, to) {
    var gradient = parent.append('linearGradient')
      .attr('id', id)
      .attr('x1', '0%').attr('y1', '0%')
      .attr('x2', '100%').attr('y2', '100%');
    gradient.append('stop').attr('offset', '0%').attr('stop-color', from);
    gradient.append('stop').attr('offset', '100%').attr('stop-color', to);
  }

  var g = svg.append('g').attr('class', 'binary-g');
  var linkLayer = g.append('g').attr('class', 'binary-links');
  var nodeLayer = g.append('g').attr('class', 'binary-nodes');

  var zoomBehavior = d3.zoom()
    .scaleExtent([0.25, 2.5])
    .clickDistance(6)
    .on('start', function () {
      svgWrap.classList.add('is-panning');
      hideTooltip(true);
    })
    .on('zoom', function (event) {
      g.attr('transform', event.transform);
    })
    .on('end', function () {
      svgWrap.classList.remove('is-panning');
    });

  svg.call(zoomBehavior);
  svg.on('dblclick.zoom', null);

  /* ----------------------------------------------------------
   *  State
   * ---------------------------------------------------------- */
  var expanded    = new Set();   /* node user-ids that are expanded */
  var nodesById   = new Map();   /* user-id -> node datum (latest) */
  var idByPath    = new Map();   /* materialised path -> user-id */
  var focusedId   = null;
  var focusIndex  = -1;
  var loading     = new Set();   /* user-ids currently fetching children */
  var orientation = bodyHas('binary-horizontal') ? 'horizontal' : 'vertical';
  var renderedNodes = [];
  var renderedById  = new Map();
  var tooltipEl = null;
  var tooltipHideTimer = null;
  var tooltipPinned = false;
  var tooltipDatum = null;
  var tooltipAnchorEl = null;

  /* ----------------------------------------------------------
   *  Small helpers
   * ---------------------------------------------------------- */
  function esc(text) {
    if (text == null) {
      return '';
    }
    return String(text)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function ucfirst(s) {
    if (!s) {
      return s;
    }
    return s.charAt(0).toUpperCase() + s.slice(1);
  }

  function clamp(value, min, max) {
    return Math.max(min, Math.min(max, value));
  }

  function statusOf(node) {
    var memberStatus = String((node && node.member_status) || '').toLowerCase();
    var baseStatus   = String((node && node.status) || '').toLowerCase();

    if (memberStatus === 'suspended') {
      return { key: 'suspended', label: 'Suspended' };
    }
    if (memberStatus === 'pending') {
      return { key: 'pending', label: 'Pending' };
    }
    if (memberStatus === 'inactive' || memberStatus === 'expired') {
      return { key: 'inactive', label: ucfirst(memberStatus) };
    }
    if (baseStatus === 'active') {
      return { key: 'active', label: 'Active' };
    }
    return {
      key: 'inactive',
      label: ucfirst(memberStatus || baseStatus || 'inactive')
    };
  }

  function freeSides(node) {
    var slots = (node && node.slots) || {};
    var sides = [];
    if (slots.left && slots.left.free) { sides.push('left'); }
    if (slots.right && slots.right.free) { sides.push('right'); }
    return sides;
  }

  function hasToggle(node) {
    return !!(node && ((node.children && node.children.length) || node.has_more));
  }

  function formatDate(value) {
    var raw = String(value || '').slice(0, 10);
    if (!/^\d{4}-\d{2}-\d{2}$/.test(raw)) {
      return raw || '—';
    }
    var parts = raw.split('-');
    var date  = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
    if (isNaN(date.getTime())) {
      return raw;
    }
    try {
      return date.toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric'
      });
    } catch (e) {
      return raw;
    }
  }

  function initials(name) {
    var value = String(name || '').replace(/^@/, '').trim();
    if (value === '') {
      return '?';
    }
    var bits = value.split(/[\s._-]+/).filter(Boolean);
    if (bits.length === 1) {
      return bits[0].charAt(0).toUpperCase();
    }
    return (bits[0].charAt(0) + bits[bits.length - 1].charAt(0)).toUpperCase();
  }

  function fitLabel(text, width) {
    var value = String(text || '');
    var maxChars = Math.max(7, Math.floor((width - 26) / 6.6));
    if (value.length <= maxChars) {
      return value;
    }
    return value.slice(0, maxChars - 1) + '\u2026';
  }

  function sideLabel(node) {
    if (!node || !node.depth) {
      return 'Root';
    }
    return node.side === 'left' ? 'Left leg' : (node.side === 'right' ? 'Right leg' : 'Unplaced');
  }

  function basePath() {
    return window.location.pathname.replace(/\/[^/]*$/, '/');
  }

  function registerLink(node, side) {
    return basePath()
      + 'register?sponsor=' + encodeURIComponent(node.username || '')
      + '&binary_position=' + encodeURIComponent(side)
      + '&return=genealogy';
  }

  function inviteLink(node) {
    return window.location.origin + basePath()
      + 'register?sponsor=' + encodeURIComponent(node.username || '')
      + '&ref=1';
  }

  function usernameForId(id) {
    var datum = nodesById.get(id);

    return datum ? (datum.username || '') : '';
  }

  /* ----------------------------------------------------------
   *  URL / history
   * ---------------------------------------------------------- */
  function setUrlParam(key, value) {
    try {
      var url = new URL(window.location.href);
      if (value === null || value === '') {
        url.searchParams.delete(key);
      } else {
        url.searchParams.set(key, String(value));
      }
      window.history.replaceState({}, '', url);
    } catch (e) {
      /* history may be unavailable in some contexts. */
    }
  }

  function getUrlParam(key) {
    try {
      return new URL(window.location.href).searchParams.get(key);
    } catch (e) {
      return null;
    }
  }

  /* ----------------------------------------------------------
   *  Notice
   * ---------------------------------------------------------- */
  function showNotice(msg, kind) {
    if (!noticeEl) {
      noticeEl = document.createElement('div');
      noticeEl.id = 'binaryTreeNotice';
      noticeEl.setAttribute('role', 'status');
      svgWrap.parentNode.insertBefore(noticeEl, svgWrap.nextSibling);
    }
    noticeEl.className = 'binary-tree-notice ' + (kind === 'warn' ? 'warn' : 'info');
    noticeEl.textContent = msg;
    noticeEl.style.display = '';
  }

  /* ----------------------------------------------------------
   *  Index the seed tree (user-id -> datum) for lookups.
   * ---------------------------------------------------------- */
  function resetIndex() {
    nodesById.clear();
    idByPath.clear();
  }

  function indexNodes(node) {
    if (!node || !node.id) {
      return;
    }
    nodesById.set(node.id, node);
    if (typeof node.path === 'string') {
      idByPath.set(node.path, node.id);
    }
    if (node.children) {
      node.children.forEach(indexNodes);
    }
  }

  /* ----------------------------------------------------------
   *  Ancestors / expansion
   * ---------------------------------------------------------- */
  function ancestorIds(id) {
    var chain = [];
    var cursor = nodesById.get(id);

    while (cursor && cursor.path) {
      var parentId = idByPath.get(cursor.path.slice(0, -1));
      if (!parentId || parentId === id) {
        break;
      }
      chain.push(parentId);
      cursor = nodesById.get(parentId);
      if (chain.length > 200) {
        break;
      }
    }

    return chain;
  }

  function expandAncestors(id) {
    ancestorIds(id).forEach(function (parentId) {
      expanded.add(parentId);
    });
  }

  function seedInitialExpansion() {
    var atDepth = mobileView() ? 1 : 2;

    nodesById.forEach(function (node, id) {
      if (node.children && node.children.length && (node.depth || 0) < atDepth) {
        expanded.add(id);
      }
    });
  }

  /* ----------------------------------------------------------
   *  Tooltip
   * ---------------------------------------------------------- */
  function ensureTooltip() {
    if (tooltipEl) {
      return tooltipEl;
    }

    tooltipEl = document.createElement('div');
    tooltipEl.id = 'binaryTreeTooltip';
    tooltipEl.className = 'binary-tree-tooltip';
    tooltipEl.setAttribute('role', 'dialog');
    tooltipEl.setAttribute('aria-label', 'Member details');
    tooltipEl.setAttribute('aria-hidden', 'true');

    tooltipEl.addEventListener('mouseenter', cancelTooltipHide);
    tooltipEl.addEventListener('mouseleave', scheduleTooltipHide);
    tooltipEl.addEventListener('focusin', cancelTooltipHide);
    tooltipEl.addEventListener('focusout', function (event) {
      if (!tooltipEl.contains(event.relatedTarget)) {
        scheduleTooltipHide();
      }
    });

    tooltipEl.addEventListener('click', function (event) {
      var close = event.target.closest('[data-bt-close]');
      if (close) {
        event.preventDefault();
        hideTooltip(true);
        return;
      }

      var copy = event.target.closest('[data-bt-copy]');
      if (copy) {
        event.preventDefault();
        var link = copy.getAttribute('data-bt-copy') || '';
        if (link) {
          copyText(link).then(function (ok) {
            if (ok) {
              showNotice('Invite link copied to the clipboard.', 'info');
              hideTooltip(true);
            } else {
              showNotice('Could not copy the invite link. Copy it from the genealogy page.', 'warn');
            }
          });
        }
        return;
      }

      /* A registration anchor is handled by the delegated listener in
       * app-binary-register.js; just get the tooltip out of the way. */
      if (event.target.closest('[data-binary-modal-trigger]')) {
        hideTooltip(true);
      }
    });

    tooltipEl.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        event.preventDefault();
        hideTooltip(true);
        if (tooltipAnchorEl && typeof tooltipAnchorEl.focus === 'function') {
          tooltipAnchorEl.focus();
        }
      }
    });

    document.body.appendChild(tooltipEl);

    return tooltipEl;
  }

  function copyText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text).then(function () {
        return true;
      }).catch(function () {
        return legacyCopy(text);
      });
    }
    return Promise.resolve(legacyCopy(text));
  }

  function legacyCopy(text) {
    var input = document.createElement('textarea');
    input.value = text;
    input.setAttribute('readonly', 'readonly');
    input.style.position = 'fixed';
    input.style.top = '-1000px';
    input.style.opacity = '0';
    document.body.appendChild(input);
    input.select();

    var ok = false;
    try {
      ok = document.execCommand('copy');
    } catch (e) {
      ok = false;
    }

    document.body.removeChild(input);
    return ok;
  }

  function cancelTooltipHide() {
    if (tooltipHideTimer) {
      window.clearTimeout(tooltipHideTimer);
      tooltipHideTimer = null;
    }
  }

  function scheduleTooltipHide() {
    if (tooltipPinned) {
      return;
    }
    cancelTooltipHide();
    tooltipHideTimer = window.setTimeout(function () {
      hideTooltip(true);
    }, 220);
  }

  function hideTooltip(force) {
    if (!tooltipEl) {
      return;
    }
    if (tooltipPinned && !force) {
      return;
    }
    cancelTooltipHide();
    if (force) {
      tooltipPinned = false;
    }
    tooltipEl.classList.remove('is-visible');
    tooltipEl.setAttribute('aria-hidden', 'true');
    tooltipDatum = null;
    tooltipAnchorEl = null;
    markInspected(null);
  }

  function markInspected(id) {
    if (!nodeLayer) {
      return;
    }
    nodeLayer.selectAll('g.binary-node').classed('is-inspected', function (d) {
      return id != null && d.data && d.data.id === id;
    });
  }

  function showTooltip(node, anchorEl, pin) {
    if (!node) {
      return;
    }
    if (tooltipPinned && !pin) {
      return;
    }

    ensureTooltip();
    cancelTooltipHide();

    tooltipPinned = !!pin;
    tooltipDatum = node;
    tooltipAnchorEl = anchorEl || null;

    tooltipEl.innerHTML = tooltipHtml(node);
    tooltipEl.classList.add('is-visible');
    tooltipEl.setAttribute('aria-hidden', 'false');

    markInspected(node.id);
    positionTooltip(anchorEl);
  }

  function positionTooltip(anchorEl) {
    if (!tooltipEl || !anchorEl) {
      return;
    }

    var rect = anchorEl.getBoundingClientRect();
    var margin = 10;
    var gap = mobileView() ? 8 : 12;
    var width = tooltipEl.offsetWidth;
    var height = tooltipEl.offsetHeight;
    var viewportW = window.innerWidth;
    var viewportH = window.innerHeight;
    var left;
    var top;

    if (mobileView()) {
      left = rect.left + rect.width / 2 - width / 2;

      var above = rect.top - height - gap;
      var below = rect.bottom + gap;

      if (above >= margin) {
        top = above;
      } else if (below + height <= viewportH - margin) {
        top = below;
      } else {
        top = Math.max(margin, viewportH - height - margin);
      }
    } else {
      left = rect.right + gap;
      if (left + width > viewportW - margin) {
        left = rect.left - width - gap;
      }
      if (left < margin) {
        left = rect.left + rect.width / 2 - width / 2;
      }

      top = rect.top + rect.height / 2 - height / 2;
    }

    left = clamp(left, margin, Math.max(margin, viewportW - width - margin));
    top  = clamp(top, margin, Math.max(margin, viewportH - height - margin));

    tooltipEl.style.left = Math.round(left) + 'px';
    tooltipEl.style.top = Math.round(top) + 'px';
  }

  function tooltipHtml(node) {
    var status = statusOf(node);
    var points = node.points || {};
    var free = freeSides(node);
    var name = (isAdmin && node.name) ? String(node.name) : '';
    var today = parseInt(points.pairs_today || 0, 10) || 0;
    var isYou = !!node.is_you;

    var html = [];
    html.push('<div class="bt-tip-head">');
    html.push('<span class="bt-tip-avatar is-' + esc(status.key) + '">' + esc(initials(node.username)) + '</span>');
    html.push('<div class="bt-tip-title">');
    html.push('<span class="bt-tip-name">@' + esc(node.username || 'member') + (isYou ? ' <span class="bt-tip-you">You</span>' : '') + '</span>');
    html.push('<span class="bt-tip-sub"><span class="bt-tip-dot is-' + esc(status.key) + '"></span>' + esc(status.label) + '<span class="bt-tip-sep">&middot;</span>' + esc(sideLabel(node)) + '</span>');
    html.push('</div>');
    html.push('<button type="button" class="bt-tip-close" data-bt-close aria-label="Close member details">&times;</button>');
    html.push('</div>');

    if (name !== '' && name !== String(node.username || '')) {
      html.push('<div class="bt-tip-fullname">' + esc(name) + '</div>');
    }

    html.push('<div class="bt-tip-rows">');
    html.push(tooltipRow('Package', node.package || 'No package'));
    html.push(tooltipRow('Joined', formatDate(node.joined)));
    html.push(tooltipRow('Placement', (node.depth || 0) === 0 ? 'Root of this view' : sideLabel(node) + ' · depth ' + (node.depth || 0)));
    html.push('</div>');

    html.push('<div class="bt-tip-stats">');
    html.push('<span class="bt-tip-stat"><span>Left</span><strong>' + (parseInt(points.left || 0, 10) || 0) + '</strong></span>');
    html.push('<span class="bt-tip-stat"><span>Right</span><strong>' + (parseInt(points.right || 0, 10) || 0) + '</strong></span>');
    html.push('<span class="bt-tip-stat"><span>Pairs</span><strong>' + (parseInt(points.pairs || 0, 10) || 0) + '</strong></span>');
    html.push('<span class="bt-tip-stat"><span>Today</span><strong>' + today + '</strong></span>');
    html.push('</div>');

    if (free.length) {
      html.push('<div class="bt-tip-open"><span class="bt-tip-open-label">Open slot' + (free.length === 1 ? '' : 's') + '</span>');
      free.forEach(function (side) {
        var label = side === 'left' ? 'Register left' : 'Register right';
        html.push('<a class="bt-tip-btn bt-tip-btn-primary" href="' + esc(registerLink(node, side)) + '"'
          + ' data-binary-modal-trigger data-binary-upline="' + esc(node.username || '') + '"'
          + ' data-binary-side="' + esc(side) + '"'
          + ' aria-label="' + esc(label + ' under @' + (node.username || '')) + '">'
          + '<span class="bt-tip-plus">+</span>' + esc(side === 'left' ? 'Left' : 'Right') + '</a>');
      });
      html.push('</div>');
    }

    if (isAdmin || isYou) {
      html.push('<div class="bt-tip-actions">');
      if (isAdmin) {
        html.push('<a class="bt-tip-link" href="' + esc(basePath() + 'admin/members/' + node.id) + '">View member</a>');
        html.push('<a class="bt-tip-link" href="' + esc(basePath() + 'admin/members/' + node.id) + '">Move placement</a>');
      }
      if (node.username) {
        html.push('<button type="button" class="bt-tip-link" data-bt-copy="' + esc(inviteLink(node)) + '">Copy invite</button>');
      }
      html.push('</div>');
    }

    return html.join('');
  }

  function tooltipRow(label, value) {
    return '<div class="bt-tip-row"><span>' + esc(label) + '</span><strong>' + esc(value) + '</strong></div>';
  }

  /* ----------------------------------------------------------
   *  Link / node classes
   * ---------------------------------------------------------- */
  function nodeClass(d) {
    var node = d.data || {};
    var cls = 'binary-node is-' + statusOf(node).key;

    if (node.is_you) {
      cls += ' is-you';
    }
    if (expanded.has(node.id)) {
      cls += ' is-expanded';
    } else if (hasToggle(node)) {
      cls += ' is-collapsed';
    }
    if (loading.has(node.id)) {
      cls += ' is-loading';
    }
    if (focusedId !== null && node.id === focusedId) {
      cls += ' is-focused';
    }
    if (tooltipEl && tooltipEl.classList.contains('is-visible') && tooltipDatum && tooltipDatum.id === node.id) {
      cls += ' is-inspected';
    }

    return cls;
  }

  function nodeAriaLabel(node) {
    var points = node.points || {};
    var label = '@' + (node.username || 'member') + ', ' + statusOf(node).label;

    if (node.is_you) {
      label += ', you';
    }
    label += ', ' + ((node.depth || 0) === 0 ? 'root' : sideLabel(node) + ', depth ' + (node.depth || 0));
    label += ', ' + (parseInt(points.left || 0, 10) || 0) + ' left points';
    label += ', ' + (parseInt(points.right || 0, 10) || 0) + ' right points';
    label += ', ' + (parseInt(points.pairs || 0, 10) || 0) + ' pairs';

    var free = freeSides(node);
    if (free.length) {
      label += ', open ' + free.join(' and ') + ' slot' + (free.length === 1 ? '' : 's');
    }

    return label;
  }

  function linkPath(link) {
    var source = link.source;
    var target = link.target;

    if (orientation === 'vertical') {
      var sy = source.cy + nodeHeight() / 2;
      var ty = target.cy - nodeHeight() / 2;
      var midY = (sy + ty) / 2;
      return 'M' + source.cx + ',' + sy
        + ' C' + source.cx + ',' + midY
        + ' ' + target.cx + ',' + midY
        + ' ' + target.cx + ',' + ty;
    }

    var sx = source.cx + nodeWidth() / 2;
    var tx = target.cx - nodeWidth() / 2;
    var midX = (sx + tx) / 2;
    return 'M' + sx + ',' + source.cy
      + ' C' + midX + ',' + source.cy
      + ' ' + midX + ',' + target.cy
      + ' ' + tx + ',' + target.cy;
  }

  /* ----------------------------------------------------------
   *  Render
   * ---------------------------------------------------------- */
  function render() {
    hideTooltip(true);

    var rootData = d3.hierarchy(tree, function (node) {
      if (!node || !node.children || node.children.length === 0) {
        return null;
      }
      return expanded.has(node.id) ? node.children : null;
    });

    var layout = d3.tree()
      .nodeSize(
        orientation === 'vertical'
          ? [siblingSpacing(), levelHeight()]
          : [horizontalSiblingSpacing(), levelHeight() + 30]
      )
      .separation(function (a, b) {
        return a.parent === b.parent ? 1 : 1.35;
      });

    var laidOut = layout(rootData);
    var nodes = laidOut.descendants();
    var links = laidOut.links();

    nodes.forEach(function (d) {
      if (orientation === 'vertical') {
        d.cx = d.x;
        d.cy = d.y + 58;
      } else {
        d.cx = d.y + 76;
        d.cy = d.x;
      }
    });

    /* ---- Links ---- */
    var link = linkLayer.selectAll('path.binary-link')
      .data(links, function (d) { return d.target.data.id; });

    link.exit().remove();

    link = link.enter().append('path')
      .attr('class', 'binary-link')
      .merge(link)
      .attr('d', linkPath);

    /* ---- Nodes ---- */
    var node = nodeLayer.selectAll('g.binary-node')
      .data(nodes, function (d) { return d.data.id; });

    node.exit().remove();

    var nodeEnter = node.enter().append('g')
      .attr('class', 'binary-node')
      .attr('role', 'treeitem')
      .attr('tabindex', '0')
      .on('pointerenter', function (event, d) {
        if (!coarsePointer() && !tooltipPinned) {
          showTooltip(d.data, this, false);
        }
      })
      .on('pointerleave', function () {
        if (!tooltipPinned) {
          scheduleTooltipHide();
        }
      })
      .on('focus', function (event, d) {
        showTooltip(d.data, this, false);
      })
      .on('blur', function () {
        if (!tooltipPinned) {
          scheduleTooltipHide();
        }
      })
      .on('click', function (event, d) {
        event.stopPropagation();
        if (event.defaultPrevented) {
          return;
        }

        if (coarsePointer()) {
          showTooltip(d.data, this, true);
          return;
        }

        if (hasToggle(d.data)) {
          toggleNode(d);
        } else {
          showTooltip(d.data, this, true);
          focusedId = d.data.id;
          updateNodeClasses();
          setUrlParam('focus', d.data.username || '');
        }
      })
      .on('keydown', function (event, d) {
        onNodeKey(event, d);
      });

    nodeEnter.append('rect')
      .attr('class', 'binary-node-halo')
      .attr('aria-hidden', 'true');

    nodeEnter.append('rect')
      .attr('class', 'binary-node-card');

    nodeEnter.append('text')
      .attr('class', 'binary-node-name')
      .attr('text-anchor', 'middle')
      .attr('dominant-baseline', 'middle')
      .attr('dy', '0.35em')
      .attr('aria-hidden', 'true');

    var addGroup = nodeEnter.append('g')
      .attr('class', 'binary-node-add')
      .attr('role', 'button')
      .attr('tabindex', '-1')
      .on('pointerenter', function (event) {
        event.stopPropagation();
      })
      .on('pointerleave', function (event) {
        event.stopPropagation();
      })
      .on('click', function (event, d) {
        event.preventDefault();
        event.stopPropagation();

        var free = freeSides(d.data);
        if (free.length === 1) {
          openRegister(d.data, free[0]);
        } else if (free.length > 1) {
          showTooltip(d.data, this.parentNode, true);
        }
      });

    addGroup.append('circle').attr('r', 9.5);
    addGroup.append('text')
      .attr('class', 'binary-node-add-icon')
      .attr('text-anchor', 'middle')
      .attr('dominant-baseline', 'middle')
      .attr('dy', '0.35em')
      .attr('aria-hidden', 'true')
      .text('+');
    addGroup.append('title');

    var toggleGroup = nodeEnter.append('g')
      .attr('class', 'binary-node-toggle')
      .attr('role', 'button')
      .attr('aria-label', 'Expand or collapse this branch')
      .on('pointerenter', function (event) {
        event.stopPropagation();
      })
      .on('pointerleave', function (event) {
        event.stopPropagation();
      })
      .on('click', function (event, d) {
        event.preventDefault();
        event.stopPropagation();
        toggleNode(d);
      });

    toggleGroup.append('circle').attr('r', 10.5);
    toggleGroup.append('text')
      .attr('class', 'binary-node-toggle-icon')
      .attr('text-anchor', 'middle')
      .attr('dominant-baseline', 'middle')
      .attr('dy', '0.35em')
      .attr('aria-hidden', 'true');
    toggleGroup.append('title');

    node = nodeEnter.merge(node);

    node
      .attr('class', nodeClass)
      .attr('transform', function (d) {
        return 'translate(' + d.cx + ',' + d.cy + ')';
      })
      .attr('aria-level', function (d) {
        return ((d.data && d.data.depth) || 0) + 1;
      })
      .attr('aria-expanded', function (d) {
        return hasToggle(d.data) ? (expanded.has(d.data.id) ? 'true' : 'false') : 'false';
      })
      .attr('aria-label', function (d) {
        return nodeAriaLabel(d.data);
      })
      .attr('data-node-id', function (d) { return d.data.id; });

    var width = nodeWidth();
    var height = nodeHeight();

    node.select('.binary-node-halo')
      .attr('x', -width / 2 - 3.5)
      .attr('y', -height / 2 - 3.5)
      .attr('width', width + 7)
      .attr('height', height + 7)
      .attr('rx', 13.5)
      .attr('ry', 13.5);

    node.select('.binary-node-card')
      .attr('x', -width / 2)
      .attr('y', -height / 2)
      .attr('width', width)
      .attr('height', height)
      .attr('rx', 10)
      .attr('ry', 10);

    node.select('.binary-node-name')
      .text(function (d) {
        return fitLabel(d.data.username || 'member', width);
      });

    node.select('.binary-node-add')
      .attr('transform', 'translate(' + (width / 2 - 1) + ',' + (-height / 2 + 1) + ')')
      .attr('display', function (d) {
        var free = freeSides(d.data);
        return free.length && statusOf(d.data).key === 'active' ? null : 'none';
      })
      .attr('aria-label', function (d) {
        var free = freeSides(d.data);
        return free.length ? 'Register a member under @' + (d.data.username || '') : null;
      })
      .select('title')
      .text(function (d) {
        var free = freeSides(d.data);
        return free.length ? 'Register into ' + free.join(' or ') + ' slot under @' + (d.data.username || '') : '';
      });

    node.select('.binary-node-toggle')
      .attr('transform', 'translate(0,' + (height / 2 + 16) + ')')
      .attr('display', function (d) {
        return hasToggle(d.data) ? null : 'none';
      })
      .attr('aria-label', function (d) {
        return (expanded.has(d.data.id) ? 'Collapse' : 'Expand') + ' the branch under @' + (d.data.username || '');
      })
      .classed('is-open', function (d) { return expanded.has(d.data.id); })
      .classed('is-loading', function (d) { return loading.has(d.data.id); })
      .select('title')
      .text(function (d) {
        if (loading.has(d.data.id)) {
          return 'Loading branch…';
        }
        return (expanded.has(d.data.id) ? 'Collapse' : 'Expand') + ' @' + (d.data.username || '');
      });

    node.select('.binary-node-toggle-icon')
      .attr('font-size', function (d) { return loading.has(d.data.id) ? '12px' : '15px'; })
      .text(function (d) {
        if (loading.has(d.data.id)) {
          return '\u2026';
        }
        return expanded.has(d.data.id) ? '\u2212' : '+';
      });

    renderedNodes = nodes;
    renderedById = new Map();
    nodes.forEach(function (d) {
      renderedById.set(d.data.id, d);
    });

    /* Re-index datums (lazy-loaded branches add new ids). */
    resetIndex();
    indexNodes(tree);

    /* ---- d3 active: hide the semantic list visually, keep in DOM ---- */
    document.documentElement.classList.add('binary-d3-active');
    if (listWrap) {
      listWrap.style.display = 'none';
    }
    svgWrap.style.display = '';
    svgWrap.setAttribute('aria-hidden', 'false');

    renderBreadcrumb();
    updateCanvasHint();

    svgWrap.classList.toggle('is-empty', nodes.length <= 1);
  }

  function updateNodeClasses() {
    nodeLayer.selectAll('g.binary-node')
      .attr('class', nodeClass);
  }

  function updateCanvasHint() {
    var hint = svgWrap.querySelector('.binary-tree-canvas-hint');
    if (!hint) {
      hint = document.createElement('div');
      hint.className = 'binary-tree-canvas-hint';
      hint.setAttribute('aria-hidden', 'true');
      svgWrap.appendChild(hint);
    }

    var root = nodesById.get(parseInt(payload.root || 0, 10));
    var rootFree = root ? freeSides(root) : [];
    var empty = renderedNodes.length <= 1;

    if (empty && rootFree.length) {
      hint.innerHTML = '<span class="binary-tree-hint-dot"></span> Tap the <strong>+</strong> on your node to place your first member';
    } else if (empty) {
      hint.innerHTML = '<span class="binary-tree-hint-dot"></span> Your tree is ready';
    } else {
      hint.innerHTML = '<span class="binary-tree-hint-dot"></span> Hover or tap a node for details'
        + '<span class="binary-tree-hint-sep">&middot;</span> Drag to pan'
        + '<span class="binary-tree-hint-sep d-none d-sm-inline">&middot;</span>'
        + '<span class="d-none d-sm-inline">Scroll or pinch to zoom</span>';
    }
  }

  function findNodeElement(id) {
    var found = null;

    nodeLayer.selectAll('g.binary-node').each(function (d) {
      if (d.data && d.data.id === id) {
        found = this;
      }
    });

    return found;
  }

  /* ----------------------------------------------------------
   *  Keyboard navigation
   * ---------------------------------------------------------- */
  function onNodeKey(event, d) {
    var id = d && d.data ? d.data.id : null;
    var key = event.key;
    var list = renderedNodes;
    var current = -1;

    for (var i = 0; i < list.length; i++) {
      if (list[i].data.id === id) {
        current = i;
        break;
      }
    }

    if (key === 'Escape') {
      hideTooltip(true);
      focusedId = null;
      focusIndex = -1;
      setUrlParam('focus', null);
      updateNodeClasses();
      event.preventDefault();
      return;
    }

    if (key === '+' || key === '=') {
      nudgeZoom(1.25);
      event.preventDefault();
      return;
    }
    if (key === '-') {
      nudgeZoom(0.8);
      event.preventDefault();
      return;
    }

    if (key === 'Home') {
      focusNodeByIndex(0);
      event.preventDefault();
      return;
    }
    if (key === 'End') {
      focusNodeByIndex(list.length - 1);
      event.preventDefault();
      return;
    }

    if (current === -1) {
      current = 0;
    }

    switch (key) {
      case 'ArrowDown':
        focusNodeByIndex(Math.min(current + 1, list.length - 1));
        break;
      case 'ArrowUp':
        focusNodeByIndex(Math.max(current - 1, 0));
        break;
      case 'ArrowRight':
        if (d && hasToggle(d.data) && !expanded.has(id)) {
          toggleNode(d);
          focusNodeElement(id);
        } else {
          focusNodeByIndex(Math.min(current + 1, list.length - 1));
        }
        break;
      case 'ArrowLeft':
        if (expanded.has(id)) {
          toggleNode(d);
          focusNodeElement(id);
        } else {
          var parentId = d && d.data ? ancestorIds(d.data.id)[0] : null;
          if (parentId) {
            var parentIndex = -1;
            for (var j = 0; j < list.length; j++) {
              if (list[j].data.id === parentId) {
                parentIndex = j;
                break;
              }
            }
            if (parentIndex !== -1) {
              focusNodeByIndex(parentIndex);
            }
          }
        }
        break;
      case 'Enter':
      case ' ':
        /* Enter opens the details for any node; the arrow keys expand and
         * collapse branches, so every action in the tooltip stays reachable
         * from the keyboard. */
        showTooltip(d.data, findNodeElement(id), true);
        window.setTimeout(function () {
          var close = tooltipEl && tooltipEl.querySelector('[data-bt-close]');
          if (close) {
            close.focus();
          }
        }, 0);
        event.preventDefault();
        return;
      default:
        return;
    }

    event.preventDefault();
  }

  function focusNodeElement(id) {
    var el = findNodeElement(id);
    if (el) {
      el.focus();
      var datum = renderedById.get(id);
      if (datum) {
        showTooltip(datum.data, el, false);
      }
    }
  }

  function focusNodeByIndex(idx) {
    if (renderedNodes.length === 0) {
      return;
    }

    idx = Math.max(0, Math.min(renderedNodes.length - 1, idx));
    focusIndex = idx;

    var datum = renderedNodes[idx];
    var id = datum.data.id;
    focusedId = id;

    updateNodeClasses();

    var el = findNodeElement(id);
    if (el) {
      el.focus();
      showTooltip(datum.data, el, false);
    }

    centerOn(datum, true);
    setUrlParam('focus', datum.data.username || '');
  }

  /* ----------------------------------------------------------
   *  Expand / collapse / lazy branch load
   * ---------------------------------------------------------- */
  function toggleNode(d) {
    var id = d.data.id;
    if (loading.has(id)) {
      return;
    }

    if (expanded.has(id)) {
      expanded.delete(id);
      render();
      return;
    }

    if (d.data.children && d.data.children.length) {
      expanded.add(id);
      render();
      return;
    }

    if (d.data.has_more) {
      loadBranch(d);
    }
  }

  function loadBranch(d) {
    var id = d.data.id;
    loading.add(id);
    render();

    var url = window.location.pathname + '?view=binary&format=json&root='
      + encodeURIComponent(id) + '&depth=2';

    fetch(url, {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    })
      .then(function (res) {
        if (!res.ok) {
          throw new Error('HTTP ' + res.status);
        }
        return res.json();
      })
      .then(function (data) {
        loading.delete(id);
        if (!data || !data.tree) {
          render();
          return;
        }
        var fetched = data.tree;
        d.data.children = (fetched.children || []).map(function (child) {
          return child;
        });
        expanded.add(id);
        render();
        showNotice('Branch loaded.', 'info');
      })
      .catch(function (err) {
        loading.delete(id);
        render();
        console.error('[ecowealth] binary tree branch load failed:', err);
        showNotice('Could not load this branch. Please try again.', 'warn');
      });
  }

  /* ----------------------------------------------------------
   *  Focus / center / breadcrumb
   * ---------------------------------------------------------- */
  function focusNode(id) {
    focusedId = id;
    expandAncestors(id);
    render();

    var datum = renderedById.get(id);
    if (datum) {
      centerOn(datum, true);
      var el = findNodeElement(id);
      if (el) {
        showTooltip(datum.data, el, false);
      }
    }

    setUrlParam('focus', usernameForId(id));
  }

  function centerOn(targetDatum, animate) {
    if (!targetDatum) {
      return;
    }

    var translate = d3.zoomIdentity
      .translate(svgWrap.clientWidth / 2 - targetDatum.cx, svgWrap.clientHeight / 2 - targetDatum.cy)
      .scale(1);

    if (animate === false || reduceMotion.matches) {
      svg.call(zoomBehavior.transform, translate);

      return;
    }

    svg.transition().duration(550).call(zoomBehavior.transform, translate);
  }

  function fitTree(animate) {
    if (!svgWrap) {
      return;
    }

    var bounds = g.node().getBBox();
    var width = svgWrap.clientWidth;
    var height = svgWrap.clientHeight;
    var bx = bounds.x;
    var by = bounds.y;
    var bw = bounds.width;
    var bh = bounds.height;

    if (bw <= 0 || bh <= 0) {
      return;
    }

    var scale = Math.min(0.9 * width / bw, 0.86 * height / bh, 1);
    scale = Math.max(scale, 0.25);

    var translate = d3.zoomIdentity
      .translate(width / 2 - scale * (bx + bw / 2), height / 2 - scale * (by + bh / 2))
      .scale(scale);

    if (animate === false || reduceMotion.matches) {
      svg.call(zoomBehavior.transform, translate);

      return;
    }

    svg.transition().duration(550).call(zoomBehavior.transform, translate);
  }

  function renderBreadcrumb() {
    var container = document.querySelector('.binary-tree-breadcrumb');
    if (!container) {
      return;
    }

    var parts = [];

    if (focusedId !== null) {
      var chain = [];
      var cursor = focusedId;

      while (cursor != null) {
        var datum = nodesById.get(cursor);
        if (!datum) {
          break;
        }
        chain.unshift(datum);
        cursor = idByPath.get(String(datum.path || '').slice(0, -1)) || null;
        if (chain.length > 200) {
          break;
        }
      }

      chain.forEach(function (node, index) {
        if (index > 0) {
          parts.push('<span class="sep">&#8250;</span>');
        }
        var isLast = index === chain.length - 1;
        if (isLast) {
          parts.push('<span class="current">@' + esc(node.username || '') + '</span>');
        } else {
          parts.push('<a href="#" data-focus-id="' + esc(node.id) + '">@' + esc(node.username || '') + '</a>');
        }
      });
    } else {
      parts.push('<span class="current">Root</span>');
    }

    container.innerHTML = parts.join(' ');
    container.querySelectorAll('a[data-focus-id]').forEach(function (a) {
      a.addEventListener('click', function (event) {
        event.preventDefault();
        focusNode(parseInt(a.getAttribute('data-focus-id'), 10));
      });
    });
  }

  /* ----------------------------------------------------------
   *  Registration / invite helpers
   * ---------------------------------------------------------- */
  function openRegister(node, side) {
    hideTooltip(true);

    if (window.EcoWealthBinaryRegister && typeof window.EcoWealthBinaryRegister.open === 'function') {
      window.EcoWealthBinaryRegister.open(node.username || '', side);
      return;
    }

    window.location.href = registerLink(node, side);
  }

  /* ----------------------------------------------------------
   *  Controls
   * ---------------------------------------------------------- */
  function bindControls() {
    function bind(selector, fn) {
      document.querySelectorAll(selector).forEach(function (el) {
        el.addEventListener('click', function (event) {
          event.preventDefault();
          fn();
        });
      });
    }

    bind('[data-action=fit]', function () { fitTree(true); });
    bind('[data-action=zoomin]', function () { nudgeZoom(1.25); });
    bind('[data-action=zoomout]', function () { nudgeZoom(0.8); });
    bind('[data-action=reset]', function () {
      hideTooltip(true);
      focusedId = null;
      focusIndex = -1;
      setUrlParam('focus', null);
      updateNodeClasses();
      fitTree(true);
    });

    bind('[data-action=expandall]', function () {
      nodesById.forEach(function (node, id) {
        if (node.children && node.children.length) {
          expanded.add(id);
        }
      });
      render();
      fitTree(true);
    });

    bind('[data-action=collapseall]', function () {
      expanded.clear();
      render();
      fitTree(true);
    });

    bind('[data-action=orientation]', function () {
      document.body.classList.toggle('binary-horizontal');
      orientation = bodyHas('binary-horizontal') ? 'horizontal' : 'vertical';
      render();
      fitTree(true);
    });

    bind('[data-action=compact]', function () {
      document.body.classList.toggle('binary-compact');
      render();
      fitTree(true);
    });

    bind('[data-action=backtome]', function () {
      if (viewerId) {
        focusNode(viewerId);
      }
    });

    /* Depth selector (reloads page with new depth — no-JS friendly). */
    var depthSel = document.getElementById('binaryTreeDepthSelect');
    if (depthSel) {
      depthSel.value = startDepth;
      depthSel.addEventListener('change', function () {
        setUrlParam('depth', depthSel.value);
        window.location.reload();
      });
    }

    /* Search (client-side over the loaded nodes). */
    var searchInput = document.getElementById('binaryTreeSearchInput');
    var searchClear = document.getElementById('binaryTreeSearchClear');
    if (searchInput) {
      var timer = null;
      searchInput.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () {
          doSearch(searchInput.value);
        }, 250);
      });
    }
    if (searchClear && searchInput) {
      searchClear.addEventListener('click', function () {
        searchInput.value = '';
        doSearch('');
        searchInput.focus();
      });
    }
  }

  function nudgeZoom(factor) {
    var current = d3.zoomTransform(svg.node());
    var next = current.scale(current.k * factor);
    svg.transition().duration(320).call(zoomBehavior.transform, next);
  }

  /* ----------------------------------------------------------
   *  Search (client-side over loaded nodes)
   * ---------------------------------------------------------- */
  function doSearch(term) {
    term = (term || '').trim().toLowerCase();

    if (!term) {
      focusedId = null;
      setUrlParam('focus', null);
      updateNodeClasses();
      showNotice('Search cleared.', 'info');
      return;
    }

    var found = null;
    nodesById.forEach(function (datum, id) {
      if (found) {
        return;
      }
      var haystack = ((datum.username || '') + ' ' + (datum.name || '')).toLowerCase();
      if (haystack.indexOf(term) !== -1) {
        found = id;
      }
    });

    if (found) {
      focusNode(found);
    } else {
      focusedId = null;
      setUrlParam('focus', null);
      updateNodeClasses();
      showNotice('No match in this tree.', 'info');
    }
  }

  /* ----------------------------------------------------------
   *  Dismissal
   * ---------------------------------------------------------- */
  function bindDismissal() {
    document.addEventListener('pointerdown', function (event) {
      if (!tooltipEl || !tooltipEl.classList.contains('is-visible')) {
        return;
      }
      if (tooltipEl.contains(event.target)) {
        return;
      }
      if (event.target.closest && event.target.closest('g.binary-node')) {
        return;
      }
      hideTooltip(true);
    });

    window.addEventListener('scroll', function (event) {
      if (!tooltipEl || !tooltipEl.classList.contains('is-visible')) {
        return;
      }
      /* Scrolling the sheet itself must not dismiss it. */
      if (event.target === tooltipEl || (event.target.nodeType === 1 && tooltipEl.contains(event.target))) {
        return;
      }
      hideTooltip(true);
    }, { passive: true, capture: true });

    window.addEventListener('resize', function () {
      hideTooltip(true);
    });
  }

  /* ----------------------------------------------------------
   *  Init
   * ---------------------------------------------------------- */
  function init() {
    document.documentElement.classList.add('binary-d3-active');
    if (listWrap) {
      listWrap.style.display = 'none';
    }
    svgWrap.style.display = '';
    svgWrap.setAttribute('aria-hidden', 'false');

    /* Make the tree container itself reachable by Tab. */
    svgWrap.setAttribute('tabindex', '0');
    svgWrap.setAttribute('role', 'tree');
    svgWrap.setAttribute('aria-label', 'Binary placement tree');

    resetIndex();
    indexNodes(tree);
    seedInitialExpansion();

    var focusParam = getUrlParam('focus');
    if (focusParam) {
      nodesById.forEach(function (datum, id) {
        if ((datum.username || '').toLowerCase() === focusParam.toLowerCase()) {
          focusedId = id;
          expandAncestors(id);
        }
      });
    }

    render();
    bindControls();
    bindDismissal();

    /* First paint: fit the whole visible tree once, instantly. */
    fitTree(false);

    /* ResizeObserver: re-lay out when the container changes size,
     * without reloading the page (§11.5 rule 12). */
    if (window.ResizeObserver) {
      var ro = new ResizeObserver(function () {
        render();
        fitTree(false);
      });
      ro.observe(svgWrap);
    } else {
      var resizeTimer = null;
      window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
          render();
          fitTree(false);
        }, 150);
      });
    }

    /* Live refresh: when the binary surface bumps (award or placement),
     * re-render the focused subtree preserving pan, zoom and expansion
     * (§11.1 Live, §11.5 rule 10). */
    document.addEventListener('ecowealth:live-applied', function (event) {
      try {
        var detail = event.detail || {};
        if (detail.surface !== 'member.binary:' + viewerId
          && detail.surface !== 'member.binary:' + (payload && payload.root || 0)) {
          return;
        }
      } catch (e) {
        return;
      }
      if (document.visibilityState === 'hidden') {
        return;
      }
      if (document.querySelector('.modal.show')) {
        return;
      }

      var currentTransform = svg.node() ? d3.zoomTransform(svg.node()) : null;
      var hadExpanded = new Set(expanded);
      var hadFocused = focusedId;

      var url = window.location.pathname + '?view=binary&format=json&root='
        + encodeURIComponent(payload && payload.root ? payload.root : viewerId)
        + '&depth=' + encodeURIComponent(startDepth);

      fetch(url, {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      })
        .then(function (res) {
          if (!res.ok) {
            throw new Error('HTTP ' + res.status);
          }
          return res.json();
        })
        .then(function (data) {
          if (!data || !data.tree) {
            return;
          }
          tree = data.tree;
          resetIndex();
          indexNodes(tree);

          expanded = new Set();
          hadExpanded.forEach(function (id) {
            if (nodesById.has(id)) {
              expanded.add(id);
            }
          });

          focusedId = hadFocused && nodesById.has(hadFocused) ? hadFocused : null;
          focusIndex = -1;

          render();

          if (currentTransform) {
            svg.call(zoomBehavior.transform, currentTransform);
          }

          showNotice('Tree updated', 'info');
        })
        .catch(function (err) {
          console.error('[ecowealth] binary tree live refresh failed:', err);
        });
    });
  }

  /* Guard: if d3 did not load, fail closed — the list stays usable. */
  if (typeof d3 === 'undefined') {
    showNotice('The tree view could not load its layout engine. The list below still works.', 'warn');
    return;
  }

  init();

})();
