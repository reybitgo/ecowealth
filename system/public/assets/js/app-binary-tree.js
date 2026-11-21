/**
 * EcoWealth — binary tree d3 explorer (plan §11 Phase 22G-S3)
 * ------------------------------------------------------------
 * Reads the server-rendered seed JSON (#binaryTreeSeed) and draws the
 * placement tree as an SVG: zoom/pan, expand/collapse with lazy branch
 * loading, depth selector, orientation toggle, compact mode, search,
 * focus breadcrumb and URL deep-link state.
 *
 * Progressive enhancement: without JavaScript the no-JS semantic list
 * (22G-S2) is the document. If d3 fails to load, a notice appears and
 * the list stays usable. d3 is vendored locally (no CDN).
 *
 * Every number, permission and slot the JS draws comes from the server
 * seed; the JS never computes points, pairs or access. Registration is
 * not wired here (22G-S4 adds the in-node modal); the "+" slot links
 * simply open the prefilled registration form.
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
  var svgWrap   = document.getElementById('binaryTreeSvgWrap');
  var listWrap  = document.getElementById('binaryTreeListWrap');
  var noticeEl  = document.getElementById('binaryTreeNotice');

  if (!svgWrap) {
    svgWrap = document.createElement('div');
    svgWrap.id = 'binaryTreeSvgWrap';
    svgWrap.className = 'binary-tree-svg-wrap';
    seedEl.parentNode.insertBefore(svgWrap, seedEl.nextSibling);
  }

  /* ----------------------------------------------------------
   *  Layout constants (responsive)
   * ---------------------------------------------------------- */
  function bodyHas(cls) {
    return document.body.classList.contains(cls);
  }
  function nodeWidth()  { return bodyHas('binary-compact') ? 150 : 190; }
  function nodeHeight() { return bodyHas('binary-compact') ? 62  : 110; }
  function levelHeight(){ return bodyHas('binary-compact') ? 78  : 150; }
  function siblingSpacing(){ return bodyHas('binary-compact') ? 180 : 240; }

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

  svg.append('defs').append('marker')
    .attr('id', 'binary-arrow')
    .attr('viewBox', '0 0 10 10')
    .attr('refX', 9).attr('refY', 5)
    .attr('markerWidth', 6).attr('markerHeight', 6)
    .attr('orient', 'auto-start-reverse')
    .append('path')
    .attr('d', 'M 0 0 L 10 5 L 0 10 Z')
    .attr('fill', '#94a3b8');

  var g = svg.append('g').attr('class', 'binary-g');

  var zoomBehavior = d3.zoom()
    .scaleExtent([0.3, 2])
    .on('zoom', function (event) {
      g.attr('transform', event.transform);
    });

  svg.call(zoomBehavior);

  /* ----------------------------------------------------------
   *  State
   * ---------------------------------------------------------- */
  var expanded   = new Set();   /* node user-ids that are expanded */
  var nodesById  = new Map();   /* user-id -> node datum (latest) */
  var focusedId  = null;
  var focusIndex = -1;          /* index of the focused node in the flattened order */
  var loading    = new Set();   /* user-ids currently fetching children */
  var orientation = bodyHas('binary-horizontal') ? 'horizontal' : 'vertical';

  /* ----------------------------------------------------------
   *  Helpers
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

  function sideBadge(n) {
    return n.side === 'left' ? 'side-left' : (n.side === 'right' ? 'side-right' : '');
  }

  function statusBadge(n) {
    return (n.status || '') === 'active' ? 'bg-label-success' : 'bg-label-secondary';
  }

  function slotLink(n, side) {
    var slot = (n.slots || {})[side];
    if (!slot || !slot.free) {
      return '';
    }
    var url = 'register?sponsor=' + encodeURIComponent(esc(n.username || ''))
      + '&binary_position=' + encodeURIComponent(side)
      + '&return=genealogy';
    var label = side === 'left' ? '+ L' : '+ R';
    return '<a href="' + esc(url) + '" class="binary-slot-link btn btn-sm btn-outline-secondary" '
      + 'title="Register into ' + esc(ucfirst(side)) + ' slot under @' + esc(n.username || '') + '" '
      + 'aria-label="Register into ' + esc(ucfirst(side)) + ' slot under @' + esc(n.username || '') + '"'
      + '><i class="icon-base bx bx-plus"></i> ' + esc(label) + '</a>';
  }

  /* Build the HTML card inside a foreignObject. */
  function cardHtml(n) {
    var sideLabel = n.side === 'left' ? 'L' : (n.side === 'right' ? 'R' : '\u2014');
    var pts = n.points || {};
    var today = parseInt(pts.pairs_today || 0, 10);
    var html = [];
    html.push('<div class="binary-node-card-content">');
    html.push('<div class="d-flex align-items-center gap-1 mb-1">');
    html.push('<span class="badge rounded-pill ' + statusBadge(n) + ' small">' + esc(ucfirst(n.status || '')) + '</span>');
    if (n.is_you) {
      html.push('<span class="badge bg-label-info small you">You</span>');
    }
    html.push('<span class="badge rounded-pill ' + sideBadge(n) + ' small">' + esc(sideLabel) + '</span>');
    html.push('<span class="text-body-secondary small">depth ' + esc(n.depth != null ? n.depth : 0) + '</span>');
    html.push('</div>');
    var name = isAdmin && n.name ? n.name : null;
    html.push('<div class="node-username" title="' + esc(n.username || '') + '">' + esc(n.username || '') + '</div>');
    if (name && name !== (n.username || '')) {
      html.push('<div class="node-name">' + esc(name) + '</div>');
    }
    html.push('<div class="node-meta">');
    if (pts.left != null)  { html.push('<span class="pts left"><i class="icon-base bx bx-chevron-right"></i> L <strong>' + esc(pts.left) + '</strong></span>'); }
    if (pts.right != null) { html.push('<span class="pts right"><i class="icon-base bx bx-chevron-left"></i> R <strong>' + esc(pts.right) + '</strong></span>'); }
    if (pts.pairs != null) { html.push('<span class="pts pairs"><i class="icon-base bx bx-link"></i> P <strong>' + esc(pts.pairs) + '</strong></span>'); }
    if (today) { html.push('<span class="pts today"><i class="icon-base bx bx-time"></i> today <strong>' + esc(today) + '</strong></span>'); }
    html.push('</div>');
    html.push('<div class="joined">Joined ' + esc(n.joined || '') + '</div>');
    /* Admin-only node menu (P22G-T22): View member / Move placement. */
    if (isAdmin) {
      html.push('<div class="binary-node-admin mt-2">');
      html.push('<a href="' + esc('admin/members/' + n.id) + '" class="binary-node-action btn btn-sm btn-outline-secondary" title="View member" aria-label="View member"><i class="icon-base bx bx-user"></i> View member</a> ');
      html.push('<a href="' + esc('admin/members/' + n.id) + '" class="binary-node-action btn btn-sm btn-outline-secondary" title="Move placement" aria-label="Move placement"><i class="icon-base bx bx-transfer"></i> Move placement</a>');
      html.push('</div>');
    }
    /* Free-slot registration links (registration modal arrives in 22G-S4). */
    var slots = n.slots || {};
    var links = [];
    if (slots.left && slots.left.free)  { links.push(slotLink(n, 'left')); }
    if (slots.right && slots.right.free) { links.push(slotLink(n, 'right')); }
    if (links.length) {
      html.push('<div class="binary-slot-hint">' + links.join(' ') + '</div>');
    }
    html.push('</div>');
    return html.join('');
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
      window.history.pushState({}, '', url);
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
      svgWrap.parentNode.insertBefore(noticeEl, svgWrap.nextSibling);
    }
    noticeEl.className = 'binary-tree-notice ' + (kind === 'warn' ? 'warn' : 'info');
    noticeEl.textContent = msg;
    noticeEl.style.display = '';
  }

  /* ----------------------------------------------------------
   *  Index the seed tree (user-id -> datum) for lookups.
   * ---------------------------------------------------------- */
  function indexNodes(node) {
    if (!node || !node.id) {
      return;
    }
    nodesById.set(node.id, node);
    if (node.children) {
      node.children.forEach(indexNodes);
    }
  }

  /* ----------------------------------------------------------
   *  Seed tree -> d3 hierarchy root (preserves expansion state).
   * ---------------------------------------------------------- */
  function toHierarchy(datum, level) {
    var children = null;
    if (datum.children) {
      children = datum.children.map(function (c) {
        return toHierarchy(c, level + 1);
      });
    }
    return {
      data: datum,
      depth: level,
      id: 'n-' + datum.id,
      children: children,
      _children: null,
      has_more: !!datum.has_more
    };
  }

  /* ----------------------------------------------------------
   *  Render
   * ---------------------------------------------------------- */
  function render() {
    var rootDatum = toHierarchy(tree, 0);

    var treeLayout = d3.tree()
      .nodeSize([siblingSpacing(), levelHeight()])
      .separation(function (a, b) {
        return (a.parent === b.parent ? 1 : 1.5);
      });

    var root = d3.hierarchy(rootDatum.data, function (d) {
      if (d.data && d.data.children) {
        return d.data.children.map(function (c) {
          return toHierarchy(c, d.depth + 1);
        });
      }
      return null;
    });

    /* Apply saved expansion state: a node with children that is not
     * expanded is collapsed. */
    root.eachBefore(function (d) {
      if (d.data && d.data.children && !expanded.has(d.data.id)) {
        d.children = null;
      }
    });

    var treeData = treeLayout(root);
    var nodes = treeData.descendants();
    var links = treeData.links();

    /* Position nodes by orientation. d3.tree already put depth in d.y
     * and sibling spread in d.x; swap axes for horizontal layout. */
    nodes.forEach(function (d) {
      if (orientation === 'vertical') {
        d.y = (d.y || 0) + 60;
      } else {
        var tmp = d.x;
        d.x = (d.y || 0) + 60;
        d.y = tmp;
      }
    });

    /* ---- Links ---- */
    var linkGen = orientation === 'vertical'
      ? d3.linkHorizontal().x(function (d) { return d.x; }).y(function (d) { return d.y; })
      : d3.linkVertical().x(function (d) { return d.x; }).y(function (d) { return d.y; });

    var link = g.selectAll('path.binary-link').data(links, function (d) { return d.target.data.id; });
    link.exit().remove();
    var linkEnter = link.enter().append('path')
      .attr('class', 'binary-link')
      .attr('marker-end', 'url(#binary-arrow)');
    link = linkEnter.merge(link);
    link.attr('d', linkGen);

    /* ---- Nodes ---- */
    var node = g.selectAll('g.binary-node').data(nodes, function (d) { return d.data.id; });
    node.exit().remove();

    var nodeEnter = node.enter().append('g')
      .attr('class', function (d) {
        return nodeClass(d);
      })
      .attr('transform', function (d) {
        return 'translate(' + d.x + ',' + d.y + ')';
      })
      .attr('role', 'treeitem')
      .attr('aria-level', function (d) { return (d.data && d.data.depth != null) ? (d.data.depth + 1) : 1; })
      .attr('aria-expanded', function (d) {
        return (d.data && (d.data.children || d.data.has_more)) ? (expanded.has(d.data.id) ? 'true' : 'false') : 'false';
      })
      .attr('tabindex', '0')
      .attr('aria-label', function (d) {
        var n = d.data || {};
        var pts = n.points || {};
        var label = n.username || '';
        if (n.is_you) {
          label += ', you';
        }
        label += ', depth ' + (n.depth != null ? n.depth : 0);
        label += ', ' + (n.status || 'unknown') + ' status';
        if (pts.left != null) { label += ', left ' + pts.left + ' points'; }
        if (pts.right != null) { label += ', right ' + pts.right + ' points'; }
        if (pts.pairs != null) { label += ', ' + pts.pairs + ' pairs'; }
        var slots = n.slots || {};
        var free = [];
        if (slots.left && slots.left.free) { free.push('left'); }
        if (slots.right && slots.right.free) { free.push('right'); }
        if (free.length) { label += ', free ' + free.join(' and ') + ' slot(s)'; }
        return label;
      })
      .style('cursor', function (d) {
        return (d.data.children || d.data.has_more) ? 'pointer' : 'default';
      })
      .on('click', function (event, d) {
        event.stopPropagation();
        if (d.data.children || d.data.has_more) {
          toggleNode(d);
        } else if (d.data.id !== viewerId) {
          focusNode(d.data.id);
        }
      })
      .on('keydown', function (event, d) {
        onNodeKey(event, d);
      });

    nodeEnter.each(function (d) {
      var group = d3.select(this);
      var w = nodeWidth();
      var h = nodeHeight();

      group.append('rect')
        .attr('class', 'binary-node-card')
        .attr('width', w).attr('height', h)
        .attr('x', -w / 2).attr('y', -h / 2)
        .attr('rx', 8).attr('ry', 8);

      var fo = group.append('foreignObject')
        .attr('class', 'binary-node-card')
        .attr('width', w).attr('height', h)
        .attr('x', -w / 2).attr('y', -h / 2)
        .attr('pointer-events', 'none');

      fo.append('xhtml:div')
        .attr('class', 'binary-node-card-content')
        .html(cardHtml(d.data));
    });

    node.attr('transform', function (d) {
      return 'translate(' + d.x + ',' + d.y + ')';
    })
    .attr('class', function (d) {
      return nodeClass(d);
    })
    .attr('aria-expanded', function (d) {
      return (d.data && (d.data.children || d.data.has_more)) ? (expanded.has(d.data.id) ? 'true' : 'false') : 'false';
    })
    .attr('aria-label', function (d) {
      var n = d.data || {};
      var pts = n.points || {};
      var label = n.username || '';
      if (n.is_you) {
        label += ', you';
      }
      label += ', depth ' + (n.depth != null ? n.depth : 0);
      label += ', ' + (n.status || 'unknown') + ' status';
      if (pts.left != null) { label += ', left ' + pts.left + ' points'; }
      if (pts.right != null) { label += ', right ' + pts.right + ' points'; }
      if (pts.pairs != null) { label += ', ' + pts.pairs + ' pairs'; }
      return label;
    });

    /* Re-index datums (lazy-loaded branches add new ids). */
    indexNodes(tree);

    /* ---- Focus ---- */
    if (focusedId !== null) {
      var target = findNodeById(root, focusedId);
      if (target) {
        centerOn(target);
      }
    }

    /* ---- Breadcrumb ---- */
    renderBreadcrumb();

    /* ---- d3 active: hide the semantic list visually, keep in DOM ---- */
    document.documentElement.classList.add('binary-d3-active');
    if (listWrap) {
      listWrap.style.display = 'none';
    }
    svgWrap.style.display = '';
    svgWrap.setAttribute('aria-hidden', 'false');
  }

  function nodeClass(d) {
    var cls = 'binary-node';
    if (d.data.is_you) {
      cls += ' you';
    }
    if ((d.data.status || '') !== 'active') {
      cls += ' inactive';
    }
    if (!expanded.has(d.data.id) && (d.data.children || d.data.has_more)) {
      cls += ' collapsed';
    }
    if (focusedId !== null && d.data.id === focusedId) {
      cls += ' focused';
    }
    return cls;
  }

  function findNodeById(root, id) {
    var found = null;
    root.each(function (d) {
      if (d.data && d.data.id === id) {
        found = d;
      }
    });
    return found;
  }

  /* ----------------------------------------------------------
   *  Keyboard navigation (§11.5 rule 10: keyboard operable)
   * ---------------------------------------------------------- */
  function flattenedNodes() {
    var out = [];
    var stack = [{ datum: tree, depth: 0 }];
    while (stack.length) {
      var entry = stack.pop();
      var n = entry.datum;
      if (n && n.id) {
        out.push({ id: n.id, depth: entry.depth });
      }
      if (n && n.children) {
        for (var i = n.children.length - 1; i >= 0; i--) {
          stack.push({ datum: n.children[i], depth: entry.depth + 1 });
        }
      }
    }
    out.reverse();
    return out;
  }

  function focusNodeByIndex(idx) {
    var list = flattenedNodes();
    if (list.length === 0) {
      return;
    }
    idx = Math.max(0, Math.min(list.length - 1, idx));
    focusIndex = idx;
    var id = list[idx].id;
    focusedId = id;
    var target = findNodeById(toHierarchy(tree, 0), id);
    if (target) {
      centerOn(target);
    }
    render();
    setUrlParam('focus', (nodesById.get(id) || {}).username || '');
  }

  function onNodeKey(event, d) {
    var id = d && d.data ? d.data.id : null;
    var key = event.key;

    if (key === 'Escape') {
      /* Esc closes the modal if open, else clears focus. */
      var modal = document.getElementById('binaryRegisterModal');
      if (modal && !modal.classList.contains('d-none') && modal.style.display !== 'none') {
        var bs = null;
        try { bs = bootstrap.Modal.getInstance(modal); } catch (e) {}
        if (bs) { bs.hide(); }
      }
      focusedId = null;
      focusIndex = -1;
      setUrlParam('focus', null);
      render();
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
      focusNodeByIndex(flattenedNodes().length - 1);
      event.preventDefault();
      return;
    }

    var list = flattenedNodes();
    var current = id !== null ? list.findIndex(function (e) { return e.id === id; }) : -1;
    if (current === -1) {
      current = focusIndex >= 0 && focusIndex < list.length ? focusIndex : 0;
    }

    var next = current;
    switch (key) {
      case 'ArrowDown': next = Math.min(current + 1, list.length - 1); break;
      case 'ArrowUp':   next = Math.max(current - 1, 0); break;
      case 'ArrowRight':
        /* Expand if collapsed, else move to first child. */
        if (d && d.data && !expanded.has(id) && (d.data.children || d.data.has_more)) {
          toggleNode(d);
        } else {
          next = Math.min(current + 1, list.length - 1);
        }
        break;
      case 'ArrowLeft':
        if (expanded.has(id)) {
          expanded.delete(id);
          render();
        } else {
          next = Math.max(current - 1, 0);
        }
        break;
      case 'Enter':
      case ' ':
        if (d && d.data && (d.data.children || d.data.has_more)) {
          toggleNode(d);
        } else if (id !== null && id !== viewerId) {
          focusNode(id);
        }
        event.preventDefault();
        return;
      default:
        return;
    }

    if (next !== current) {
      focusNodeByIndex(next);
    }
    event.preventDefault();
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
    } else {
      expanded.add(id);
      render();
    }
  }

  function loadBranch(d) {
    var id = d.data.id;
    loading.add(id);

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
          return;
        }
        var fetched = data.tree;
        /* The fetched tree root is this same node; take its children. */
        d.data.children = (fetched.children || []).map(function (c) {
          return c;
        });
        indexNodes(fetched);
        expanded.add(id);
        render();
      })
      .catch(function (err) {
        loading.delete(id);
        console.error('[ecowealth] binary tree branch load failed:', err);
        showNotice('Could not load this branch. Please try again.', 'warn');
      });
  }

  /* ----------------------------------------------------------
   *  Focus / center / breadcrumb
   * ---------------------------------------------------------- */
  function focusNode(id) {
    focusedId = id;
    var username = usernameForId(id);
    setUrlParam('focus', username);
    render();
  }

  function usernameForId(id) {
    var datum = nodesById.get(id);
    return datum ? (datum.username || '') : '';
  }

  function centerOn(targetDatum) {
    var x = targetDatum.x;
    var y = targetDatum.y;
    var translate = d3.zoomIdentity
      .translate(svgWrap.clientWidth / 2 - x, svgWrap.clientHeight / 2 - y)
      .scale(1);
    svg.transition().duration(700).call(zoomBehavior.transform, translate);
  }

  function fitTree() {
    if (!svgWrap) {
      return;
    }
    var bounds = g.node().getBBox();
    var w = svgWrap.clientWidth;
    var h = svgWrap.clientHeight;
    var x = bounds.x, y = bounds.y, bw = bounds.width, bh = bounds.height;
    if (bw <= 0 || bh <= 0) {
      return;
    }
    var scale = Math.min(0.92 * w / bw, 0.9 * h / bh, 1);
    scale = Math.max(scale, 0.3);
    var translate = d3.zoomIdentity
      .translate(w / 2 - scale * (x + bw / 2), h / 2 - scale * (y + bh / 2))
      .scale(scale);
    svg.transition().duration(700).call(zoomBehavior.transform, translate);
  }

  function renderBreadcrumb() {
    var container = document.querySelector('.binary-tree-breadcrumb');
    if (!container) {
      return;
    }
    var parts = [];
    if (focusedId !== null) {
      var chain = ancestorChain(focusedId);
      chain.forEach(function (n, i) {
        if (i > 0) {
          parts.push('<span class="sep">&#8250;</span>');
        }
        var isLast = (i === chain.length - 1);
        if (isLast) {
          parts.push('<span class="current">' + esc(n.username || '') + '</span>');
        } else {
          parts.push('<a href="#" data-focus-id="' + esc(n.id) + '">' + esc(n.username || '') + '</a>');
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

  function ancestorChain(id) {
    var chain = [];
    var cursor = id;
    while (cursor != null) {
      var datum = nodesById.get(cursor);
      if (!datum) {
        break;
      }
      chain.unshift(datum);
      cursor = parentIdForPath(datum.path);
      if (chain.length > 200) {
        break;
      }
    }
    return chain;
  }

  function parentIdForPath(path) {
    if (!path || path.length <= 1) {
      return null;
    }
    var parentPath = path.slice(0, -1);
    for (var _id of nodesById.keys()) {
      var datum = nodesById.get(_id);
      if (datum && datum.path === parentPath) {
        return _id;
      }
    }
    return null;
  }

  /* ----------------------------------------------------------
   *  Controls
   * ---------------------------------------------------------- */
  function bindControls() {
    function bind(selector, fn) {
      var el = document.querySelector(selector);
      if (el) {
        el.addEventListener('click', function (event) {
          event.preventDefault();
          fn();
        });
      }
    }

    bind('[data-action=fit]', fitTree);
    bind('[data-action=zoomin]', function () { nudgeZoom(1.25); });
    bind('[data-action=zoomout]', function () { nudgeZoom(0.8); });
    bind('[data-action=reset]', function () {
      svg.transition().duration(500).call(zoomBehavior.transform, d3.zoomIdentity);
    });

    bind('[data-action=expandall]', function () {
      expandSubtree(toHierarchy(tree, 0));
      render();
    });
    bind('[data-action=collapseall]', function () {
      expanded.clear();
      render();
    });

    bind('[data-action=orientation]', function () {
      document.body.classList.toggle('binary-horizontal');
      orientation = bodyHas('binary-horizontal') ? 'horizontal' : 'vertical';
      render();
    });

    bind('[data-action=compact]', function () {
      document.body.classList.toggle('binary-compact');
      render();
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
    var searchInput = document.querySelector('#binaryTreeSearch input');
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
      });
    }
  }

  function expandSubtree(datum) {
    if (!datum) {
      return;
    }
    expanded.add(datum.data.id);
    if (datum.children) {
      datum.children.forEach(expandSubtree);
    } else if (datum.data.has_more) {
      loadBranch(datum);
    }
  }

  function nudgeZoom(factor) {
    var current = d3.zoomTransform(svg.node());
    svg.transition().duration(400).call(zoomBehavior.transform, current.scale(current.k * factor));
  }

  /* ----------------------------------------------------------
   *  Search (client-side over loaded nodes)
   * ---------------------------------------------------------- */
  function doSearch(term) {
    term = (term || '').trim().toLowerCase();
    if (!term) {
      focusedId = null;
      setUrlParam('focus', null);
      render();
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
      render();
      showNotice('No match in this tree.', 'info');
    }
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

    indexNodes(tree);

    var focusParam = getUrlParam('focus');
    if (focusParam) {
      nodesById.forEach(function (datum, id) {
        if ((datum.username || '').toLowerCase() === focusParam.toLowerCase()) {
          focusedId = id;
        }
      });
    }

    render();
    bindControls();

    /* ResizeObserver: re-lay out when the container changes size,
     * without reloading the page (§11.5 rule 12). */
    if (window.ResizeObserver) {
      var ro = new ResizeObserver(function () {
        render();
      });
      ro.observe(svgWrap);
    } else {
      var resizeTimer = null;
      window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
          render();
        }, 150);
      });
    }

    /* Live refresh: when the binary surface bumps (award or placement),
     * re-render the focused subtree preserving pan, zoom and expansion
     * (§11.1 Live, §11.5 rule 10). */
    document.addEventListener('ecowealth:live-applied', function (event) {
      try {
        var detail = event.detail || {};
        if (detail.surface !== 'member.binary:' + viewerId && detail.surface !== 'member.binary:' + (payload && payload.root || 0)) {
          return;
        }
      } catch (e) {
        return;
      }
      /* Only refresh when the tab is visible and no modal is open. */
      if (document.visibilityState === 'hidden') {
        return;
      }
      if (document.querySelector('.modal.show')) {
        return;
      }

      /* Remember pan/zoom/expansion state before re-render. */
      var currentTransform = svg.node() ? d3.zoomTransform(svg.node()) : null;
      var hadExpanded = new Set(expanded);
      var hadFocused = focusedId;

      /* Re-fetch the current subtree JSON so points/pairs/placement are fresh. */
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
          indexNodes(tree);

          /* Restore expansion for nodes that still exist. */
          expanded = new Set();
          hadExpanded.forEach(function (id) {
            if (nodesById.has(id)) {
              expanded.add(id);
            }
          });

          focusedId = hadFocused && nodesById.has(hadFocused) ? hadFocused : null;
          focusIndex = -1;

          /* Restore pan/zoom. */
          if (currentTransform) {
            svg.transition().duration(0).call(zoomBehavior.transform, currentTransform);
          }

          render();

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
