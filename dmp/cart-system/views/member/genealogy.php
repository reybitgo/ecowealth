<?php

/**
 * @file   views/member/genealogy.php
 * @brief  Member genealogy UI
 */
?>
<?php $pageTitle = $view === 'referral' ? 'Referral Network' : 'Binary Tree'; ?>
<?php require 'views/partials/head.php'; ?>
<?php require 'views/partials/sidebar_member.php'; ?>

<!-- D3.js CDN -->
<script src="https://d3js.org/d3.v7.min.js"></script>

<style>
  /* D3 Tree Container Styles */
  #treeContainer {
    width: 100%;
    min-height: 400px;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border-radius: 12px;
    position: relative;
    overflow: hidden;
  }

  /* Responsive SVG container */
  .svg-container {
    width: 100%;
    height: 100%;
    display: flex;
    justify-content: center;
    align-items: flex-start;
  }

  .svg-container svg {
    width: 100%;
    height: auto;
    max-height: 80vh;
  }

  /* Node Styles - Rounded Rectangle */
  .node rect {
    cursor: pointer;
    transition: all 0.3s ease;
    filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.1));
  }

  .node rect:hover {
    filter: drop-shadow(0 4px 12px rgba(0, 0, 0, 0.15));
    transform: scale(1.02);
  }

  .node.active rect {
    fill: #12a05c;
    stroke: #0d8a4d;
    stroke-width: 2px;
  }

  .node.suspended rect {
    fill: #e03434;
    stroke: #c22a2a;
    stroke-width: 2px;
  }

  .node.empty rect {
    fill: #f8fafc;
    stroke: #cbd5e1;
    stroke-width: 2px;
    stroke-dasharray: 5, 5;
  }

  .node.pending rect {
    fill: #f59e0b;
    stroke: #d97706;
    stroke-width: 2px;
  }

  .node.cd rect {
    fill: #b45309;
    stroke: #92400e;
    stroke-width: 2px;
  }

  /* Node Text */
  .node text {
    font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
    font-size: 11px;
    font-weight: 600;
    fill: white;
    text-anchor: middle;
    pointer-events: none;
  }

  .node.empty text {
    fill: #64748b;
    font-size: 10px;
  }

  /* Count badges below nodes */
  .node .count-text {
    font-size: 9px;
    fill: #64748b;
    font-weight: 500;
  }

  /* Links */
  .link {
    fill: none;
    stroke: #cbd5e1;
    stroke-width: 2px;
    transition: stroke 0.3s ease;
  }

  .link:hover {
    stroke: #94a3b8;
  }

  /* Empty slot indicator */
  .empty-indicator {
    fill: #e2e8f0;
    stroke: #cbd5e1;
    stroke-width: 1.5px;
  }

  /* Tooltip */
  #treeTooltip {
    position: fixed;
    background: #1a2035;
    color: #fff;
    border-radius: 10px;
    padding: 12px 16px;
    font-size: 0.8rem;
    pointer-events: none;
    z-index: 9999;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
    min-width: 180px;
    opacity: 0;
    transition: opacity 0.2s ease;
  }

  #treeTooltip.visible {
    opacity: 1;
  }

  /* Controls */
  .tree-controls {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }

  .tree-controls button {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    background: white;
    color: #475569;
    cursor: pointer;
    transition: all 0.2s ease;
  }

  .tree-controls button:hover {
    background: #f1f5f9;
    border-color: #cbd5e1;
  }

  /* Mobile optimizations */
  @media (max-width: 768px) {
    #treeContainer {
      min-height: 350px;
    }

    .node text {
      font-size: 9px;
    }

    .node .count-text {
      font-size: 8px;
    }

    .tree-controls button {
      width: 40px;
      height: 40px;
      font-size: 1.1rem;
    }

    #treeTooltip {
      font-size: 0.75rem;
      padding: 10px 12px;
      min-width: 150px;
    }
  }

  /* Loading state */
  #treeLoading {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 3rem 1rem;
    color: #6b7a99;
    gap: 0.5rem;
    font-size: 0.9rem;
  }

  /* Legend */
  .tree-legend {
    display: flex;
    gap: 1.5rem;
    flex-wrap: wrap;
    font-size: 0.75rem;
    color: #64748b;
    align-items: center;
  }

  .legend-item {
    display: flex;
    align-items: center;
    gap: 0.4rem;
  }

  .legend-dot {
    width: 12px;
    height: 12px;
    border-radius: 3px;
  }

  .legend-dot.active {
    background: #12a05c;
  }

  .legend-dot.suspended {
    background: #e03434;
  }

  .legend-dot.empty {
    background: #f8fafc;
    border: 2px dashed #cbd5e1;
  }

  .legend-dot.pending {
    background: #f59e0b;
  }

  .legend-dot.cd {
    background: #b45309;
  }
</style>

<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
      <ul class="nav nav-pills mb-0">
        <?php if ($pairingEnabled ?? true): ?>
          <li class="nav-item"><a class="nav-link <?= $view !== 'referral' ? 'active' : '' ?>" href="<?= link_to('genealogy', ['view' => 'binary']) ?>">🌳 Binary Tree</a></li>
        <?php endif; ?>
        <li class="nav-item"><a class="nav-link <?= $view === 'referral' || !($pairingEnabled ?? true) ? 'active' : '' ?>" href="<?= link_to('genealogy', ['view' => 'referral']) ?>">👥 Referral Network</a></li>
      </ul>
      <div class="ms-auto" style="min-width:220px;max-width:360px;width:100%;">
        <div class="input-group input-group-sm">
          <span class="input-group-text">🔗</span>
          <input type="text" class="form-control font-mono" id="refLink" readonly
            value="<?= APP_URL ?>/?page=register&sponsor=<?= urlencode($user['username']) ?>&ref=1">
          <button class="btn btn-outline-secondary" type="button" onclick="copyRefLink()" title="Copy">
            📋
          </button>
        </div>
      </div>
    </div>

    <script>
      function copyRefLink() {
        const el = document.getElementById('refLink');
        el.select();
        el.setSelectionRange(0, 99999);
        navigator.clipboard.writeText(el.value).then(() => {
          const btn = el.nextElementSibling;
          const old = btn.textContent;
          btn.textContent = '✓';
          setTimeout(() => btn.textContent = old, 1500);
        });
      }
    </script>

    <?php if ($view !== 'referral'): ?>
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
          <span class="card-title">🌳 Binary Tree</span>
          <div class="tree-controls">
            <button onclick="resetTree()" title="Reset View">⟲</button>
            <button onclick="zoomTree(0.8)" title="Zoom Out">−</button>
            <button onclick="zoomTree(1.25)" title="Zoom In">+</button>
            <button onclick="expandAll()" title="Expand All">⤢</button>
            <button onclick="collapseAll()" title="Collapse All">⤡</button>
          </div>
        </div>

        <div id="treeContainer">
          <div id="treeLoading">
            <div class="spinner-border spinner-border-sm text-primary"></div>
            <span>Loading tree…</span>
          </div>
          <div id="svgWrapper" class="svg-container"></div>
        </div>

        <div id="treeTooltip"></div>

        <div class="card-footer">
          <div class="tree-legend">
            <div class="legend-item">
              <div class="legend-dot active"></div>
              <span>Active</span>
            </div>
            <div class="legend-item">
              <div class="legend-dot suspended"></div>
              <span>Suspended</span>
            </div>
            <div class="legend-item">
              <div class="legend-dot pending"></div>
              <span>Pending</span>
            </div>
            <div class="legend-item">
              <div class="legend-dot cd"></div>
              <span>CD Active</span>
            </div>
            <span class="ms-auto text-muted">Click nodes to expand/collapse • Drag to pan</span>
          </div>
        </div>
      </div>

    <?php elseif ($showIndirect): ?>
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span class="card-title">👥 Referral Network</span>
          <span class="badge bg-secondary-subtle text-secondary"><?= count($indirect) ?> members</span>
        </div>
        <div class="card-body p-0">
          <?php if (empty($indirect)): ?>
            <div class="text-center py-5 text-muted">
              <div style="font-size:2.5rem;">👥</div>
              <p class="mt-2 mb-0">You haven't referred anyone yet.</p>
            </div>
            <?php else:
            $grouped = [];
            foreach ($indirect as $m) $grouped[$m['level']][] = $m;
            foreach ($grouped as $lvl => $members): ?>
              <div>
                <div class="d-flex align-items-center gap-2 px-3 py-2 cursor-pointer border-bottom"
                  style="background:#f8fafd;font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);"
                  onclick="toggleRef(<?= $lvl ?>)">
                  <span>Level <?= $lvl ?></span>
                  <span class="badge bg-primary-subtle text-primary"><?= count($members) ?></span>
                  <span id="refArrow<?= $lvl ?>" class="ms-auto">▼</span>
                </div>
                <div id="refLevel<?= $lvl ?>">
                  <?php foreach ($members as $m): ?>
                    <div class="d-flex align-items-center gap-3 px-3 py-2 border-bottom">
                      <div class="user-avatar"><?= strtoupper(substr($m['username'], 0, 1)) ?></div>
                      <div class="flex-grow-1">
                        <div class="fw-600" style="font-size:.825rem;">@<?= e($m['username']) ?><?= $m['full_name'] ? ' — ' . e($m['full_name']) : '' ?></div>
                        <div class="text-muted" style="font-size:.72rem;"><?= e($m['package_name'] ?? 'Member') ?> · Joined <?= fmt_date($m['joined_at']) ?></div>
                      </div>
                      <span class="badge <?= $m['status'] === 'active' ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger' ?>"><?= ucfirst($m['status']) ?></span>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
          <?php endforeach;
          endif; ?>
        </div>
      </div>
    <?php else: ?>
      <!-- Indirect disabled — show direct referrals table instead -->
      <div class="card">
        <div class="card-header">
          <form method="GET" action="<?= APP_URL ?>/" class="d-flex flex-wrap align-items-center justify-content-between gap-2">
            <input type="hidden" name="page" value="genealogy">
            <input type="hidden" name="view" value="referral">
            <?php if (is_imp_session()): ?><input type="hidden" name="imp" value="<?= e(session_id()) ?>"><?php endif; ?>
            <div class="d-flex align-items-center gap-2">
              <span class="card-title">👥 Direct Referrals</span>
              <span class="badge bg-secondary-subtle text-secondary"><?= $direct['total'] ?? 0 ?> members</span>
            </div>

            <!-- Rows per page -->
            <div class="d-flex align-items-center gap-2">
              <label for="perPageSelect" class="form-label mb-0 text-muted" style="font-size:.78rem;white-space:nowrap;">Rows per page</label>
              <select id="perPageSelect" name="per_page" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
                <?php foreach ([5, 10, 25, 50, 100] as $n): ?>
                  <option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </form>
        </div>
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead>
              <tr>
                <th>#</th>
                <th>Username</th>
                <th>Package</th>
                <th>Joined</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($direct['data'])): ?>
                <tr>
                  <td colspan="5" class="text-center py-5 text-muted">
                    <div style="font-size:2.5rem;">👥</div>
                    <p class="mt-2 mb-0">You haven't referred anyone yet.</p>
                  </td>
                </tr>
                <?php else: foreach ($direct['data'] as $i => $m): ?>
                  <tr>
                    <td class="td-muted" style="font-size:.7rem;"><?= ($direct['page'] - 1) * $direct['per_page'] + $i + 1 ?></td>
                    <td class="fw-bold">@<?= e($m['username']) ?></td>
                    <td><span class="badge bg-primary-subtle text-primary"><?= e($m['package_name'] ?? '—') ?></span></td>
                    <td class="td-muted" style="font-size:.75rem;"><?= fmt_date($m['joined_at']) ?></td>
                    <td>
                      <?php $b = $m['status'] === 'active' ? 'bg-success-subtle text-success' : ($m['status'] === 'suspended' ? 'bg-danger-subtle text-danger' : 'bg-warning-subtle text-warning'); ?>
                      <span class="badge <?= $b ?>"><?= ucfirst($m['status']) ?></span>
                    </td>
                  </tr>
              <?php endforeach;
              endif; ?>
            </tbody>
          </table>
        </div>
        <?php if (($direct['total_pages'] ?? 1) > 1): ?>
          <div class="card-footer"><?= pagination_links($direct, link_to('genealogy', ['view' => 'referral', 'per_page' => $perPage])) ?></div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($view !== 'referral'): ?>
  <script>
    // D3.js Binary Tree Visualization
    const API_URL = '<?= link_to('api_binary_tree', ['root' => $binaryRootId]) ?>';
    const API_URL_LAZY = '<?= link_to('api_binary_tree', ['depth' => 4]) ?>';
    let svg, g, tree, root, zoom;
    let currentScale = 1;
    let currentTranslate = [0, 0];

    // Node dimensions - proportional and responsive
    const nodeWidth = 140;
    const nodeHeight = 54;
    const nodeRadius = 8; // Rounded corners
    const levelHeight = 110; // Vertical spacing between levels
    const siblingSpacing = 170; // Horizontal spacing between siblings

    // Mobile detection for responsive sizing
    const isMobile = window.matchMedia('(max-width: 768px)').matches;
    const responsiveNodeWidth = isMobile ? 110 : nodeWidth;
    const responsiveNodeHeight = isMobile ? 48 : nodeHeight;
    const responsiveLevelHeight = isMobile ? 90 : levelHeight;
    const responsiveSiblingSpacing = isMobile ? 130 : siblingSpacing;

    async function loadTree() {
      const loader = document.getElementById('treeLoading');
      const wrapper = document.getElementById('svgWrapper');

      try {
        const res = await fetch(API_URL + '&depth=4');
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();

        loader.style.display = 'none';
        initD3Tree(data, wrapper);
      } catch (e) {
        loader.innerHTML = `<span style="color:#e03434;">⚠ Could not load tree. ${escHtml(e.message)}</span>`;
      }
    }

    // Hierarchy accessor — reusable for lazy loading
    const treeAccessor = d => {
      const children = [];
      if (d.left) children.push(d.left);
      if (d.right) children.push(d.right);
      // Add empty slot indicators if missing children (for visualization)
      if (!d.left && d.depth < 3) {
        children.push({
          username: 'Empty',
          status: 'empty',
          isPlaceholder: true,
          parent: d,
          depth: (d.depth || 0) + 1
        });
      }
      if (!d.right && d.depth < 3) {
        children.push({
          username: 'Empty',
          status: 'empty',
          isPlaceholder: true,
          parent: d,
          depth: (d.depth || 0) + 1
        });
      }
      return children.length > 0 ? children : null;
    };

    function initD3Tree(data, container) {
      // Clear previous
      container.innerHTML = '';

      // Get container dimensions
      const containerWidth = container.clientWidth || 800;
      const containerHeight = Math.max(window.innerHeight * 0.6, 500);

      // Create SVG with viewBox for responsiveness
      svg = d3.select(container)
        .append('svg')
        .attr('width', '100%')
        .attr('height', containerHeight)
        .attr('viewBox', `0 0 ${containerWidth} ${containerHeight}`)
        .attr('preserveAspectRatio', 'xMidYMid meet')
        .style('font-family', 'Plus Jakarta Sans, sans-serif');

      // Add zoom behavior
      zoom = d3.zoom()
        .scaleExtent([0.3, 3])
        .on('zoom', (event) => {
          g.attr('transform', event.transform);
          currentScale = event.transform.k;
          currentTranslate = [event.transform.x, event.transform.y];
        });

      svg.call(zoom);

      // Create main group for tree
      g = svg.append('g');

      // Convert data to hierarchy
      root = d3.hierarchy(data, treeAccessor);

      // Store original children for expand/collapse
      root.descendants().forEach(d => {
        d._children = d.children;
        // Collapse after level 1 initially for cleaner view
        if (d.depth > 1) {
          d.children = null;
        }
      });

      // Create tree layout with nodeSize for consistent spacing
      tree = d3.tree()
        .nodeSize([responsiveSiblingSpacing, responsiveLevelHeight])
        .separation((a, b) => (a.parent == b.parent ? 1 : 1.5));

      // Initial render - center the root node at top
      update(root);
      centerRoot();
    }

    function update(source) {
      // Compute new tree layout
      const treeData = tree(root);
      const nodes = treeData.descendants();
      const links = treeData.links();

      // Normalize for fixed-depth (vertical layout)
      nodes.forEach(d => {
        d.y = d.depth * responsiveLevelHeight + 60; // 60px padding from top
      });

      // ****************** Nodes ******************
      const node = g.selectAll('g.node')
        .data(nodes, d => d.id || (d.id = ++i));

      // Enter new nodes at parent's previous position
      const nodeEnter = node.enter().append('g')
        .attr('class', d => `node ${d.data.status || 'active'} ${d.data.isPlaceholder ? 'empty' : ''} ${d.data.cd_active ? 'cd' : ''}`)
        .attr('transform', d => `translate(${source.x0 || 0},${source.y0 || 0})`)
        .on('click', (event, d) => {
          // Skip click on empty-slot placeholders
          if (!d.data.isPlaceholder) {
            toggle(d);
          }
        })
        .on('mouseover', (event, d) => showTooltip(event, d))
        .on('mouseout', hideTooltip);

      // Add rounded rectangle
      nodeEnter.append('rect')
        .attr('width', d => d.data.isPlaceholder ? responsiveNodeWidth * 0.8 : responsiveNodeWidth)
        .attr('height', d => d.data.isPlaceholder ? responsiveNodeHeight * 0.8 : responsiveNodeHeight)
        .attr('x', d => d.data.isPlaceholder ? -(responsiveNodeWidth * 0.4) : -responsiveNodeWidth / 2)
        .attr('y', d => d.data.isPlaceholder ? -(responsiveNodeHeight * 0.4) : -responsiveNodeHeight / 2)
        .attr('rx', nodeRadius)
        .attr('ry', nodeRadius);

      // Add action-circle with user-plus icon (upper-right corner, half protruding)
      const actionR = 10;
      const actionGroup = nodeEnter.filter(d => !d.data.isPlaceholder && (!d.data.left || !d.data.right)).append('g')
        .attr('class', 'node-action-icon')
        .attr('transform', d => `translate(${responsiveNodeWidth / 2}, ${-responsiveNodeHeight / 2})`)
        .style('cursor', 'pointer')
        .style('opacity', 0.7)
        .on('mouseover', function(event) {
          event.stopPropagation();
          d3.select(this).style('opacity', 1);
        })
        .on('mouseout', function(event) {
          event.stopPropagation();
          d3.select(this).style('opacity', 0.7);
        })
        .on('click', function(event, d) {
          event.stopPropagation();
          openRegisterModal(d.data);
        });

      actionGroup.append('circle')
        .attr('r', actionR)
        .style('fill', '#3b6ff0')
        .style('stroke', '#fff')
        .style('stroke-width', 1.5)
        .style('filter', 'drop-shadow(0 1px 3px rgba(0,0,0,0.25))');

      actionGroup.append('foreignObject')
        .attr('x', -actionR)
        .attr('y', -actionR)
        .attr('width', actionR * 2)
        .attr('height', actionR * 2)
        .append('xhtml:i')
        .attr('class', 'fas fa-user-plus')
        .style('display', 'flex')
        .style('align-items', 'center')
        .style('justify-content', 'center')
        .style('width', '100%')
        .style('height', '100%')
        .style('color', '#fff')
        .style('font-size', '10px')
        .style('pointer-events', 'none');

      // Add username text (multi-line wrap for long names)
      nodeEnter.append('text')
        .attr('text-anchor', 'middle')
        .each(function(d) {
          const el = d3.select(this);
          const name = d.data.username || 'Unknown';
          const maxChars = isMobile ? 11 : 14;
          const lineHeight = isMobile ? 11 : 12;

          let lines = [];
          if (name.length <= maxChars) {
            lines = [name];
          } else {
            lines = [name.slice(0, maxChars), name.slice(maxChars)];
            if (lines[1].length > maxChars) {
              lines[1] = lines[1].slice(0, maxChars - 1) + '…';
            }
          }

          const startY = -(lines.length - 1) * lineHeight / 2;
          lines.forEach((line, i) => {
            el.append('tspan')
              .attr('x', 0)
              .attr('dy', i === 0 ? startY + 'px' : lineHeight + 'px')
              .text(line);
          });
        });

      // Add expand/collapse circle for nodes with children (loaded or not-yet-loaded)
      nodeEnter.filter(d => !d.data.isPlaceholder).append('circle')
        .attr('class', 'toggle-circle')
        .attr('r', d => d.data.hasMore ? 11 : 10)
        .attr('cy', d => responsiveNodeHeight / 2 + 12)
        .style('fill', '#fff')
        .style('stroke', d => d.data.hasMore ? '#3b6ff0' : '#94a3b8')
        .style('stroke-width', d => d.data.hasMore ? 2 : 1.5)
        .style('cursor', 'pointer')
        .style('display', d => (d.children || d._children || d.data.hasMore) ? null : 'none')
        .on('mouseover', event => event.stopPropagation())
        .on('mouseout', event => event.stopPropagation());

      // Add + / − text inside the toggle circle (perfectly centred)
      nodeEnter.filter(d => !d.data.isPlaceholder).append('text')
        .attr('class', 'toggle-text')
        .attr('x', 0)
        .attr('y', d => responsiveNodeHeight / 2 + 12)
        .attr('font-size', d => d.data.hasMore ? '22px' : '19px')
        .attr('font-weight', '700')
        .attr('text-anchor', 'middle')
        .attr('dominant-baseline', 'middle')
        .style('fill', d => d.data.hasMore ? '#3b6ff0' : '#64748b')
        .style('pointer-events', 'none')
        .style('display', d => (d.children || d._children || d.data.hasMore) ? null : 'none')
        .text(d => d.children ? '−' : '+');

      // Add count badges for non-empty nodes — hidden if leaf (no children at all)
      nodeEnter.filter(d => !d.data.isPlaceholder).append('text')
        .attr('class', 'count-text')
        .attr('dy', '1.8em')
        .style('fill', d => d.children ? 'rgba(255,255,255,0.8)' : '#64748b')
        .style('display', d => (d.children || d._children) ? null : 'none')
        .text(d => `L:${d.data.left_count || 0} R:${d.data.right_count || 0}`);

      // UPDATE
      const nodeUpdate = node.merge(nodeEnter).transition()
        .duration(500)
        .attr('transform', d => `translate(${d.x},${d.y})`);

      // Update rectangle dimensions on resize
      nodeUpdate.select('rect')
        .attr('width', d => d.data.isPlaceholder ? responsiveNodeWidth * 0.8 : responsiveNodeWidth)
        .attr('height', d => d.data.isPlaceholder ? responsiveNodeHeight * 0.8 : responsiveNodeHeight)
        .attr('x', d => d.data.isPlaceholder ? -(responsiveNodeWidth * 0.4) : -responsiveNodeWidth / 2)
        .attr('y', d => d.data.isPlaceholder ? -(responsiveNodeHeight * 0.4) : -responsiveNodeHeight / 2);

      // Update toggle circle visibility, colour & size
      nodeUpdate.select('.toggle-circle')
        .attr('r', d => d.data.hasMore ? 11 : 10)
        .attr('cy', d => responsiveNodeHeight / 2 + 12)
        .style('stroke', d => d.data.hasMore ? '#3b6ff0' : '#94a3b8')
        .style('stroke-width', d => d.data.hasMore ? 2 : 1.5)
        .style('display', d => (d.children || d._children || d.data.hasMore) ? null : 'none');

      // Update toggle text (+ / −)
      nodeUpdate.select('.toggle-text')
        .attr('y', d => responsiveNodeHeight / 2 + 12)
        .attr('font-size', d => d.data.hasMore ? '22px' : '19px')
        .style('fill', d => d.data.hasMore ? '#3b6ff0' : '#64748b')
        .style('display', d => (d.children || d._children || d.data.hasMore) ? null : 'none')
        .text(d => d.children ? '−' : '+');

      // Update count-text colour: white when expanded (has visible children), muted when collapsed/leaf
      // Also hide entirely if node has no children at all (free slot indicator)
      nodeUpdate.select('.count-text')
        .style('fill', d => d.children ? 'rgba(255,255,255,0.8)' : '#64748b')
        .style('display', d => (d.children || d._children) ? null : 'none');

      // Exit nodes
      const nodeExit = node.exit().transition()
        .duration(500)
        .attr('transform', d => `translate(${source.x},${source.y})`)
        .remove();

      nodeExit.select('rect').attr('r', 1e-6);
      nodeExit.select('text').style('fill-opacity', 1e-6);

      // ****************** Links ******************
      const link = g.selectAll('path.link')
        .data(links, d => d.target.id);

      // Enter new links at parent's previous position
      const linkEnter = link.enter().insert('path', 'g')
        .attr('class', 'link')
        .attr('d', d => {
          const o = {
            x: source.x0 || 0,
            y: source.y0 || 0
          };
          return diagonal(o, o);
        });

      // UPDATE
      const linkUpdate = link.merge(linkEnter).transition()
        .duration(500)
        .attr('d', d => diagonal(d.source, d.target));

      // Exit links
      link.exit().transition()
        .duration(500)
        .attr('d', d => {
          const o = {
            x: source.x,
            y: source.y
          };
          return diagonal(o, o);
        })
        .remove();

      // Store old positions for transition
      nodes.forEach(d => {
        d.x0 = d.x;
        d.y0 = d.y;
      });
    }

    // Curved path generator (vertical tree)
    function diagonal(s, d) {
      return `M ${s.x} ${s.y + responsiveNodeHeight/2}
          C ${s.x} ${(s.y + d.y) / 2},
            ${d.x} ${(s.y + d.y) / 2},
            ${d.x} ${d.y - responsiveNodeHeight/2}`;
    }

    // Lazy-load deeper levels when a node with hasMore is clicked
    async function loadChildren(d) {
      if (d.data.isLoading) return;
      d.data.isLoading = true;

      try {
        const res = await fetch(API_URL_LAZY + '&root=' + d.data.id);
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const subtree = await res.json();

        // Build a temporary hierarchy to get properly-wired children
        const tempRoot = d3.hierarchy(subtree, treeAccessor);

        // Reparent the loaded children to the clicked node
        if (tempRoot.children) {
          tempRoot.children.forEach(child => {
            child.parent = d;
            // Recursively set correct depths in the main tree
            function setDepth(node, depth) {
              node.depth = depth;
              const descendants = node.children || node._children;
              if (descendants) {
                descendants.forEach(c => setDepth(c, depth + 1));
              }
            }
            setDepth(child, d.depth + 1);
          });
          d._children = tempRoot.children;
        }
        d.data.hasMore = false;
      } catch (e) {
        console.error('Failed to load children', e);
        alert('Could not load deeper levels. Please try again.');
      } finally {
        d.data.isLoading = false;
      }
    }

    // Toggle children on click
    async function toggle(d) {
      // Lazy-load if children haven't been fetched yet
      if (d.data.hasMore && !d._children && !d.children) {
        await loadChildren(d);
      }

      if (d.children) {
        d._children = d.children;
        d.children = null;
      } else {
        d.children = d._children;
        d._children = null;
      }
      update(d);
      centerNode(d);
    }

    // Center tree on root node at top
    function centerRoot() {
      const svgWidth = svg.node().clientWidth || 800;
      const svgHeight = svg.node().clientHeight || 500;

      // Calculate transform to center root at top
      const x = svgWidth / 2;
      const y = 60; // Padding from top
      const k = isMobile ? 0.7 : 1;

      svg.transition().duration(750).call(
        zoom.transform,
        d3.zoomIdentity.translate(x, y).scale(k)
      );
    }

    // Center on specific node
    function centerNode(d) {
      const svgWidth = svg.node().clientWidth || 800;
      const svgHeight = svg.node().clientHeight || 500;

      const t = d3.zoomIdentity
        .translate(svgWidth / 2 - d.x * currentScale, 80 - d.y * currentScale)
        .scale(currentScale);

      svg.transition().duration(750).call(zoom.transform, t);
    }

    // Tooltip functions
    function showTooltip(event, d) {
      const tooltip = document.getElementById('treeTooltip');
      const data = d.data;

      let html = `<div style="font-weight:700;margin-bottom:6px;font-size:0.9rem;">@${data.username || 'Unknown'}</div>`;

      if (!data.isPlaceholder) {
        html += `<div style="color:rgba(255,255,255,0.7);font-size:0.75rem;line-height:1.4;">`;
        html += `${data.package || 'Member'} · ${data.joined || '—'}<br>`;
        html += `Left: ${data.left_count || 0} · Right: ${data.right_count || 0}<br>`;
        const statusColor = data.status === 'active' ? '#4ade80' : data.status === 'pending' ? '#fbbf24' : '#f87171';
        html += `Status: <span style="color:${statusColor};font-weight:600;">${data.status || 'active'}</span>`;
        if (data.cd_active) {
          html += ` &middot; <span style="color:#fbbf24;font-weight:600;">CD Active</span>`;
        }
        html += `</div>`;
      } else {
        html += `<div style="color:rgba(255,255,255,0.7);font-size:0.75rem;">Open slot available</div>`;
      }

      tooltip.innerHTML = html;
      tooltip.classList.add('visible');

      // Position tooltip with boundary checks
      const tooltipRect = tooltip.getBoundingClientRect();
      let left = event.clientX + 15;
      let top = event.clientY - 10;

      if (left + tooltipRect.width > window.innerWidth) {
        left = event.clientX - tooltipRect.width - 15;
      }
      if (top + tooltipRect.height > window.innerHeight) {
        top = event.clientY - tooltipRect.height - 10;
      }

      tooltip.style.left = left + 'px';
      tooltip.style.top = top + 'px';
    }

    function hideTooltip() {
      document.getElementById('treeTooltip').classList.remove('visible');
    }

    // Control functions
    function resetTree() {
      centerRoot();
    }

    function zoomTree(factor) {
      const newScale = Math.max(0.3, Math.min(3, currentScale * factor));
      svg.transition().duration(300).call(
        zoom.scaleTo,
        newScale
      );
    }

    function expandAll() {
      root.descendants().forEach(d => {
        if (d._children) {
          d.children = d._children;
          d._children = null;
        }
      });
      update(root);
      centerRoot();
    }

    function collapseAll() {
      root.descendants().forEach(d => {
        if (d.depth > 0 && d.children) {
          d._children = d.children;
          d.children = null;
        }
      });
      update(root);
      centerRoot();
    }

    // Counter for node IDs
    let i = 0;

    // Handle window resize
    let resizeTimeout;
    window.addEventListener('resize', () => {
      clearTimeout(resizeTimeout);
      resizeTimeout = setTimeout(() => {
        const newIsMobile = window.matchMedia('(max-width: 768px)').matches;
        if (newIsMobile !== isMobile) {
          location.reload();
        } else if (svg) {
          const container = document.getElementById('svgWrapper');
          const newHeight = Math.max(window.innerHeight * 0.6, 500);
          svg.attr('height', newHeight);
          centerRoot();
        }
      }, 250);
    });

    // Initialize
    loadTree();
  </script>
<?php endif; ?>

<script>
  function toggleRef(lvl) {
    const el = document.getElementById('refLevel' + lvl);
    const arrow = document.getElementById('refArrow' + lvl);
    if (!el) return;
    const hidden = el.style.display === 'none';
    el.style.display = hidden ? 'block' : 'none';
    arrow.textContent = hidden ? '▼' : '▶';
  }
</script>

<?php if ($view !== 'referral'): ?>
  <?php
  // Packages disabled (or none active) while free registration is on → there is
  // nothing to select, so the modal opens straight on Account Setup.
  $packagesDisabled = setting('packages_disabled', '0') === '1';
  $rmFreeEnabled    = setting('free_registration_enabled', '1') === '1';
  $skipSelectStep   = $rmFreeEnabled && ($packagesDisabled || empty($packages));
  // Mirror the register page: free first, then a usable e-wallet, else a code.
  $rmDefaultMethod  = $rmFreeEnabled ? 'free' : (($canUseEwallet ?? false) ? 'ewallet' : 'code');
  // Free keeps the payment chooser (panel 1) but collapses the step bar.
  $rmFreeMode       = !$skipSelectStep && $rmDefaultMethod === 'free';
  $rmStepOneLabel   = $rmDefaultMethod === 'ewallet' ? 'Select Package' : 'Payment';
  // Same gate as ?page=register: no placement controls when packages are
  // disabled or the Binary Pairing compensation is switched off.
  $rmBinaryUiEnabled = $binaryUiEnabled ?? binary_placement_ui_enabled($packages ?? []);
  ?>
  <script>
    let regCodeData = {},
      regSelectedPkg = {},
      regUsernameOk = false,
      regSlotData = {},
      regSponsorOk = false;
    let regCurrentStep = 1;
    let regBinaryEnabled = true;
    const PACKAGES = <?= json_encode(array_map(fn($p) => ['id' => $p['id'], 'name' => $p['name'], 'entry_fee' => fmt_money((float)$p['entry_fee']), 'pairing_bonus' => fmt_money((float)$p['pairing_bonus']), 'daily_pair_cap' => (int)$p['daily_pair_cap'], 'pairing_enabled' => (int)($p['pairing_enabled'] ?? 1) === 1], $packages ?? [])) ?>;
    const CSRF_TOKEN = '<?= csrf_token() ?>';
    const APP_URL_JS = '<?= APP_URL ?>';
    const IMP_QUERY = '<?= is_imp_session() ? '&imp=' . e(session_id()) : '' ?>';
    const CURRENT_USER = '<?= e($user["username"]) ?>';
    const REGISTRAR_HAS_BINARY = <?= $pairingEnabled ? 'true' : 'false' ?>;
    const RM_FREE_ENABLED = <?= $rmFreeEnabled ? 'true' : 'false' ?>;
    const RM_SKIP_SELECT_STEP = <?= $skipSelectStep ? 'true' : 'false' ?>;
    const RM_DEFAULT_METHOD = <?= json_encode($rmDefaultMethod) ?>;
    const RM_BINARY_UI_ENABLED = <?= $rmBinaryUiEnabled ? 'true' : 'false' ?>;
    let rmBinaryMode = 'manual',
      rmAutoSuggestion = null,
      rmAutoActive = false,
      rmUplineFree = {
        left: false,
        right: false
      };

    function rmTogglePw(id, btn) {
      const el = document.getElementById(id);
      el.type = el.type === 'password' ? 'text' : 'password';
      btn.textContent = el.type === 'password' ? '👁' : '🙈';
    }

    function rmSetBinaryEnabled(on) {
      regBinaryEnabled = !!on;
      const left = document.getElementById('rm_pos_left'),
        right = document.getElementById('rm_pos_right');
      if (left) left.required = regBinaryEnabled;
      if (right) right.required = regBinaryEnabled;
      if (!regBinaryEnabled && left && right) {
        left.checked = false;
        right.checked = false;
        document.getElementById('rm_binary_position').value = '';
        document.getElementById('rm_position_display').textContent = 'N/A';
      } else if (regBinaryEnabled && !document.getElementById('rm_binary_position').value) {
        rmApplyDefaultPosition();
      }
    }

    function rmApplyDefaultPosition() {
      const left = document.getElementById('rm_pos_left'),
        right = document.getElementById('rm_pos_right');
      const pos = document.getElementById('rm_binary_position'),
        disp = document.getElementById('rm_position_display');
      if (!left || !right || !pos || !disp) return;
      const lL = document.getElementById('rm_pos_label_left'),
        rL = document.getElementById('rm_pos_label_right');
      left.disabled = false;
      right.disabled = false;
      if (lL) lL.classList.remove('opacity-50');
      if (rL) rL.classList.remove('opacity-50');
      if (!rmUplineFree.left) {
        pos.value = 'left';
        left.checked = true;
        disp.textContent = 'Left';
        if (rmUplineFree.right) {
          right.disabled = true;
          if (rL) rL.classList.add('opacity-50');
        }
      } else if (!rmUplineFree.right) {
        pos.value = 'right';
        right.checked = true;
        disp.textContent = 'Right';
        left.disabled = true;
        if (lL) lL.classList.add('opacity-50');
      } else {
        pos.value = '';
        left.checked = false;
        right.checked = false;
        disp.textContent = 'N/A';
      }
    }

    function rmPaymentMethod() {
      if (RM_SKIP_SELECT_STEP) return 'free';
      return document.querySelector('[name="payment_method"]:checked')?.value || 'code';
    }

    function rmCurrentPairing() {
      const method = rmPaymentMethod();
      if (method === 'code') return !!(regCodeData && regCodeData.pairing_enabled);
      if (regSelectedPkg.pairing_enabled === true || regSelectedPkg.pairing_enabled === false) return regSelectedPkg.pairing_enabled;
      const single = document.getElementById('rm_packageId');
      if (single) return single.dataset.pairing === '1';
      const sel = document.getElementById('rm_packageSelect');
      if (sel && sel.value) return sel.options[sel.selectedIndex].dataset.pairing === '1';
      return false;
    }

    function rmRefreshAutoPreview() {
      const hint = document.getElementById('rm_autoPreviewHint');
      if (!hint) return;
      hint.textContent = 'Finding the best position…';
      rmAutoSuggestion = null;
      fetch(APP_URL_JS + '/?page=auto_select_upline' + IMP_QUERY)
        .then(r => r.json())
        .then(d => {
          if (!d.valid) {
            rmAutoSuggestion = {
              valid: false
            };
            hint.textContent = d.message || 'No position available — the member will become a binary network root.';
            return;
          }
          rmAutoSuggestion = d;
          hint.textContent = 'Auto will place this member under @' + d.upline_username + ' (' + d.position.charAt(0).toUpperCase() + d.position.slice(1) + ').';
        })
        .catch(() => {
          rmAutoSuggestion = {
            valid: false
          };
          hint.textContent = 'Could not load placement suggestion.';
        });
    }

    function rmApplyBinary() {
      const sec = document.getElementById('rm_binarySection');
      if (!sec) return;
      if (!RM_BINARY_UI_ENABLED) {
        // Gate off: never show placement controls, and clear their required
        // flags so a hidden radio cannot block submission.
        sec.style.display = 'none';
        rmSetBinaryEnabled(false);
        return;
      }
      const method = rmPaymentMethod();
      const pairing = rmCurrentPairing();
      const toggle = document.getElementById('rm_hasBinary');
      const modeSec = document.getElementById('rm_binaryModeSection');
      const manualSec = document.getElementById('rm_manualSection');
      const isAuto = document.querySelector('[name="binary_mode"]:checked')?.value === 'auto';
      let showAny = false,
        showManual = false;
      rmAutoActive = false;
      if (modeSec) modeSec.style.display = 'none';

      if (method === 'free') {
        // Free access is limited: no binary placement at registration. The
        // member chooses a binary connection when they activate.
        rmAutoActive = false;
        if (toggle) {
          toggle.checked = false;
          toggle.disabled = true;
          const hint = document.getElementById('rm_hasBinaryHint');
          if (hint) hint.textContent = 'Free members are not placed in the binary network until they activate.';
        }
      } else if (pairing) {
        if (REGISTRAR_HAS_BINARY) {
          if (toggle) {
            toggle.checked = true;
            toggle.disabled = true;
            document.getElementById('rm_hasBinaryHint').textContent = 'Binary placement is required for this package.';
          }
          showAny = showManual = true;
        } else {
          if (toggle) {
            toggle.checked = true;
            toggle.disabled = true;
          }
          showAny = true;
          showManual = !isAuto;
          rmAutoActive = isAuto;
          rmBinaryMode = isAuto ? 'auto' : 'manual';
          if (modeSec) modeSec.style.display = 'block';
          if (isAuto) rmRefreshAutoPreview();
        }
      }

      sec.style.display = showAny ? 'block' : 'none';
      if (manualSec) manualSec.style.display = showManual ? 'block' : 'none';
      rmSetBinaryEnabled(showManual);
    }

    function openRegisterModal(data) {
      const modal = document.getElementById('regModal');
      const form = document.getElementById('regModalForm');
      form.reset();
      regCodeData = {};
      regSelectedPkg = {};
      regUsernameOk = false;
      regSlotData = {};
      regCurrentStep = RM_SKIP_SELECT_STEP ? 2 : 1;
      // Reset always returns to the default method (free → e-wallet → code).
      const rmMethod = RM_DEFAULT_METHOD;
      const rmMethodRadio = document.getElementById('rm_pay_' + rmMethod);
      if (rmMethodRadio) rmMethodRadio.checked = true;
      document.getElementById('rm_referralMode').value = rmMethod === 'free' ? '1' : '';
      document.getElementById('rm_upline_username').value = data.username || '';
      document.getElementById('rm_upline_display').textContent = '@' + (data.username || '—');
      document.getElementById('rm_sponsor_username').value = CURRENT_USER;
      document.getElementById('rm_sponsor_display').textContent = '@' + CURRENT_USER;
      document.getElementById('rm_sponsorInput').value = CURRENT_USER;
      regSponsorOk = true;
      regSetHint('rm_sponsorHint', 'Sponsor is valid.', true);
      if (rmMethod === 'free') {
        document.getElementById('rm_codeSection').style.display = 'none';
        document.getElementById('rm_packageSection').style.display = 'none';
        document.getElementById('rm_packageInfo').classList.add('d-none');
        document.getElementById('rm_validatedCode').value = '';
        document.getElementById('rm_reg_code').removeAttribute('required');
        document.getElementById('rm_toStep2Btn').disabled = false;
      } else if (rmMethod === 'ewallet') {
        // A usable e-wallet opens straight on the package picker.
        document.getElementById('rm_codeSection').style.display = 'none';
        document.getElementById('rm_packageSection').style.display = 'block';
        document.getElementById('rm_packageInfo').classList.add('d-none');
        document.getElementById('rm_validatedCode').value = '';
        document.getElementById('rm_reg_code').removeAttribute('required');
        document.getElementById('rm_toStep2Btn').disabled = PACKAGES.length !== 1;
      } else {
        // Paid fallback: registration code.
        document.getElementById('rm_codeSection').style.display = 'block';
        document.getElementById('rm_packageSection').style.display = 'none';
        document.getElementById('rm_packageInfo').classList.add('d-none');
        document.getElementById('rm_validatedCode').value = '';
        document.getElementById('rm_reg_code').setAttribute('required', '');
        document.getElementById('rm_toStep2Btn').disabled = true;
      }
      const pkgCard = document.getElementById('rm_packageCard');
      if (pkgCard) pkgCard.classList.add('d-none');
      document.querySelectorAll('#rm_step2 input[name="password"],#rm_step2 input[name="password_confirm"]').forEach(p => {
        p.value = '';
        p.type = 'password';
      });
      document.getElementById('rm_binary_position').value = '';
      document.getElementById('rm_position_display').textContent = '—';
      document.querySelectorAll('input[name="binary_position_radio"]').forEach(r => {
        r.checked = false;
        r.disabled = false;
      });
      document.getElementById('rm_pos_label_left').classList.remove('opacity-50');
      document.getElementById('rm_pos_label_right').classList.remove('opacity-50');

      rmUplineFree = {
        left: data.left != null,
        right: data.right != null
      };
      rmApplyDefaultPosition();
      if (RM_SKIP_SELECT_STEP) {
        // Packages disabled: Account Setup is the first screen. Surface the
        // "no payment required now" notice that the Continue handler would
        // otherwise have shown.
        document.getElementById('rm_referralAlert').style.display = 'block';
      }
      regGoStep(regCurrentStep);
      rmApplyBinary();
      new bootstrap.Modal(modal).show();
    }

    function rmStepMode() {
      if (RM_SKIP_SELECT_STEP) return 'skip';
      return rmPaymentMethod() === 'free' ? 'free' : 'paid';
    }

    function rmPanelToIndicator(n) {
      return (rmStepMode() === 'free' && n === 1) ? 2 : n;
    }

    function rmStepOneLabel() {
      if (PACKAGES.length === 0) return 'Payment';
      return rmPaymentMethod() === 'ewallet' ? 'Select Package' : 'Payment';
    }

    function rmSyncStepBar() {
      const paid = rmStepMode() === 'paid';
      const ind1 = document.getElementById('rm_ind_1');
      if (ind1) ind1.style.display = paid ? '' : 'none';
      const dot2 = document.getElementById('rm_ind_2_dot');
      const dot3 = document.getElementById('rm_ind_3_dot');
      if (dot2) dot2.textContent = paid ? '2' : '1';
      if (dot3) dot3.textContent = paid ? '3' : '2';
      const label1 = document.getElementById('rm_ind_1_label');
      if (label1) label1.textContent = rmStepOneLabel();
      const active = rmPanelToIndicator(regCurrentStep);
      [1, 2, 3].forEach(i => {
        const el = document.getElementById('rm_ind_' + i);
        if (el) el.className = 'reg-step ' + (i < active ? 'done' : i === active ? 'active' : '');
      });
      const notice = document.getElementById('rm_freeLimitNotice');
      if (notice) notice.style.display = (rmStepMode() === 'free') ? '' : 'none';
    }

    function regGoStep(n) {
      regCurrentStep = n;
      const steps = RM_SKIP_SELECT_STEP ? [2, 3] : [1, 2, 3];
      steps.forEach(i => {
        const el = document.getElementById('rm_step' + i);
        if (el) el.style.display = i === n ? 'block' : 'none';
      });
      rmSyncStepBar();
    }

    function regSetHint(id, msg, ok) {
      const el = document.getElementById(id);
      if (!el) return;
      el.textContent = msg;
      el.className = 'form-text' + (ok === true ? ' text-success' : ok === false ? ' text-danger' : '');
    }

    function regResetCodeState() {
      document.getElementById('rm_packageInfo').classList.add('d-none');
      document.getElementById('rm_validatedCode').value = '';
      regSetHint('rm_codeHint', '', null);
      regCodeData = {};
      document.getElementById('rm_toStep2Btn').disabled = true;
    }

    document.addEventListener('DOMContentLoaded', function() {
      document.querySelectorAll('input[name="binary_position_radio"]').forEach(r => {
        r.addEventListener('change', function() {
          document.getElementById('rm_binary_position').value = this.value;
          document.getElementById('rm_position_display').textContent = this.value.charAt(0).toUpperCase() + this.value.slice(1);
        });
      });

      document.querySelectorAll('[name="binary_mode"]').forEach(r => {
        r.addEventListener('change', function() {
          rmBinaryMode = this.value;
          rmApplyBinary();
        });
      });
      const rmToggleEl = document.getElementById('rm_hasBinary');
      if (rmToggleEl) {
        rmToggleEl.addEventListener('change', rmApplyBinary);
      }

      document.querySelectorAll('[name="payment_method"]').forEach(r => {
        r.addEventListener('change', function() {
          regResetCodeState();
          const v = this.value,
            isFree = v === 'free';
          document.getElementById('rm_codeSection').style.display = 'none';
          document.getElementById('rm_packageSection').style.display = 'none';
          document.getElementById('rm_referralMode').value = isFree ? '1' : '';
          const codeInput = document.getElementById('rm_reg_code');
          if (isFree) {
            document.getElementById('rm_toStep2Btn').disabled = false;
            codeInput.removeAttribute('required');
          } else if (v === 'ewallet') {
            document.getElementById('rm_packageSection').style.display = 'block';
            document.getElementById('rm_toStep2Btn').disabled = PACKAGES.length !== 1;
            codeInput.removeAttribute('required');
          } else {
            document.getElementById('rm_codeSection').style.display = 'block';
            document.getElementById('rm_toStep2Btn').disabled = true;
            codeInput.setAttribute('required', '');
          }
          rmSyncStepBar();
          rmApplyBinary();
        });
      });

      document.getElementById('rm_validateCodeBtn').addEventListener('click', async function() {
        const code = document.getElementById('rm_reg_code').value.trim();
        const isCd = code.startsWith('CD-');
        const minLen = isCd ? 17 : 14;
        if (code.length < minLen) {
          regSetHint('rm_codeHint', isCd ? 'Enter a complete CD code (CD-XXXX-XXXX-XXXX)' : 'Enter a complete code (XXXX-XXXX-XXXX)', false);
          return;
        }
        this.disabled = true;
        this.textContent = '…';
        try {
          const fd = new FormData();
          fd.append('code', code);
          fd.append('csrf_token', CSRF_TOKEN);
          const d = await (await fetch(APP_URL_JS + '/?page=validate_code' + IMP_QUERY, {
            method: 'POST',
            body: fd
          })).json();
          if (d.valid) {
            regCodeData = d;
            rmApplyBinary();
            document.getElementById('rm_pkgName').textContent = d.package_name;
            document.getElementById('rm_pkgDetails').textContent = 'Entry: ' + d.entry_fee + ' · Pair volume: ' + d.volume + ' · Cap: ' + d.cap_pesos + '/day';
            document.getElementById('rm_packageInfo').classList.remove('d-none');
            document.getElementById('rm_validatedCode').value = code;
            document.getElementById('rm_toStep2Btn').disabled = false;
            regSetHint('rm_codeHint', '✓ Code is valid!', true);
          } else {
            regSetHint('rm_codeHint', d.message || 'Invalid code.', false);
          }
        } catch (e) {
          regSetHint('rm_codeHint', 'Network error.', false);
        }
        this.disabled = false;
        this.textContent = 'Validate';
      });

      document.getElementById('rm_reg_code').addEventListener('input', function() {
        let raw = this.value.replace(/[^A-Z0-9]/gi, '').toUpperCase(),
          formatted = '';
        if (raw.startsWith('CD')) {
          const body = raw.slice(2).slice(0, 12);
          const parts = [body.slice(0, 4), body.slice(4, 8), body.slice(8, 12)].filter(Boolean);
          formatted = 'CD' + (parts.length ? '-' + parts.join('-') : '');
        } else {
          const clean = raw.slice(0, 12);
          const parts = [clean.slice(0, 4), clean.slice(4, 8), clean.slice(8, 12)].filter(Boolean);
          formatted = parts.join('-');
        }
        this.value = formatted;
        regResetCodeState();
      });

      const rmPackageSelect = document.getElementById('rm_packageSelect');
      if (rmPackageSelect) {
        rmPackageSelect.addEventListener('change', function() {
          if (!this.value) {
            document.getElementById('rm_packageCard')?.classList.add('d-none');
            document.getElementById('rm_toStep2Btn').disabled = true;
            rmApplyBinary();
            return;
          }
          const opt = this.options[this.selectedIndex];
          regSelectedPkg = {
            id: this.value,
            name: opt.dataset.name,
            fee: opt.dataset.fee,
            bonus: opt.dataset.bonus,
            cap: opt.dataset.cap,
            pairing_enabled: opt.dataset.pairing === '1'
          };
          rmApplyBinary();
          const card = document.getElementById('rm_packageCard');
          if (card) {
            document.getElementById('rm_pkgCardName').textContent = regSelectedPkg.name;
            document.getElementById('rm_pkgCardDetails').textContent = 'Entry: ' + regSelectedPkg.fee + ' · Bonus: ' + regSelectedPkg.bonus + ' · Cap: ' + regSelectedPkg.cap + ' pairs/day';
            card.classList.remove('d-none');
          }
          regSetHint('rm_packageHint', '✓ Package selected.', true);
          document.getElementById('rm_toStep2Btn').disabled = false;
        });
      }

      document.getElementById('rm_toStep2Btn').addEventListener('click', () => {
        const isFree = document.querySelector('[name="payment_method"]:checked').value === 'free';
        document.getElementById('rm_referralAlert').style.display = isFree ? 'block' : 'none';
        regGoStep(2);
      });

      let rmUTimer;
      document.getElementById('rm_username').addEventListener('input', function() {
        regUsernameOk = false;
        clearTimeout(rmUTimer);
        const v = this.value.trim();
        if (v.length < 3) {
          regSetHint('rm_usernameHint', '', null);
          return;
        }
        regSetHint('rm_usernameHint', 'Checking…', null);
        rmUTimer = setTimeout(async () => {
          const d = await (await fetch(APP_URL_JS + '/?page=check_username&username=' + encodeURIComponent(v) + IMP_QUERY)).json();
          regUsernameOk = d.available;
          regSetHint('rm_usernameHint', d.message, d.available);
        }, 600);
      });

      let rmSTimer;
      document.getElementById('rm_sponsorInput').addEventListener('input', function() {
        regSponsorOk = false;
        clearTimeout(rmSTimer);
        const v = this.value.trim();
        if (!v) {
          regSetHint('rm_sponsorHint', '', null);
          return;
        }
        regSetHint('rm_sponsorHint', 'Checking…', null);
        rmSTimer = setTimeout(async () => {
          const d = await (await fetch(APP_URL_JS + '/?page=check_username&username=' + encodeURIComponent(v) + IMP_QUERY)).json();
          regSponsorOk = !d.available;
          regSetHint('rm_sponsorHint', regSponsorOk ? 'Sponsor is valid.' : 'Sponsor not found.', regSponsorOk);
        }, 600);
      });

      document.getElementById('rm_password_confirm').addEventListener('input', function() {
        const ok = document.getElementById('rm_password').value === this.value;
        regSetHint('rm_pwMatchHint', this.value ? (ok ? '✓ Passwords match.' : '✗ Passwords do not match.') : '', this.value ? ok : null);
      });

      document.getElementById('rm_toStep3Btn').addEventListener('click', function() {
        const pw = document.getElementById('rm_password').value,
          pwc = document.getElementById('rm_password_confirm').value,
          username = document.getElementById('rm_username').value.trim();
        if (!username) {
          regSetHint('rm_usernameHint', 'Username is required.', false);
          return;
        }
        if (!regUsernameOk) {
          regSetHint('rm_usernameHint', 'Please choose a valid, available username.', false);
          return;
        }
        const sponsorVal = document.getElementById('rm_sponsorInput').value.trim() || CURRENT_USER;
        if (!regSponsorOk && sponsorVal !== CURRENT_USER) {
          regSetHint('rm_sponsorHint', 'Please enter a valid sponsor.', false);
          return;
        }
        if (pw.length < 8) {
          alert('Password must be at least 8 characters.');
          return;
        }
        if (pw !== pwc) {
          regSetHint('rm_pwMatchHint', 'Passwords do not match.', false);
          return;
        }
        const pos = document.getElementById('rm_binary_position').value;
        if (regBinaryEnabled && !pos) {
          alert('Please select a binary position (Left or Right).');
          return;
        }
        const method = rmPaymentMethod(),
          isFree = method === 'free';
        document.getElementById('rm_revFreeRow').style.display = isFree ? '' : 'none';
        document.getElementById('rm_revPayRow').style.display = isFree ? 'none' : '';
        document.getElementById('rm_revCodeRow').style.display = (!isFree && method === 'code') ? '' : 'none';
        document.getElementById('rm_revPkgRow').style.display = isFree ? 'none' : '';
        if (!isFree) {
          document.getElementById('rm_rev_payment').textContent = method === 'code' ? '🎫 Registration Code' : '💳 E-Wallet';
          document.getElementById('rm_rev_code').textContent = document.getElementById('rm_validatedCode').value || '—';
          document.getElementById('rm_rev_package').textContent = method === 'code' ? (regCodeData.package_name || '—') : (regSelectedPkg.name || (PACKAGES.length === 1 ? PACKAGES[0].name : '—'));
        }
        document.getElementById('rm_rev_username').textContent = '@' + username;
        document.getElementById('rm_rev_sponsor').textContent = '@' + (document.getElementById('rm_sponsorInput').value.trim() || CURRENT_USER);
        const showBinary = regBinaryEnabled || rmAutoActive;
        const uRow = document.getElementById('rm_rev_uplineRow');
        if (uRow) uRow.style.display = showBinary ? '' : 'none';
        document.getElementById('rm_rev_upline').textContent = rmAutoActive ?
          (rmAutoSuggestion ? '@' + rmAutoSuggestion.upline_username + ' (auto)' : '(auto)') :
          ('@' + (document.getElementById('rm_upline_username').value || '—'));
        const pRow = document.getElementById('rm_rev_positionRow');
        if (pRow) pRow.style.display = showBinary ? '' : 'none';
        document.getElementById('rm_rev_position').textContent = rmAutoActive ? 'Auto (network)' : document.getElementById('rm_position_display').textContent;
        regGoStep(3);
      });

      document.getElementById('regModalForm').addEventListener('submit', function() {
        document.getElementById('rm_sponsor_username').value = document.getElementById('rm_sponsorInput').value.trim() || CURRENT_USER;
        const btn = document.getElementById('rm_submitBtn');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Creating account…';
      });
    });
  </script>

  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/auth.css?v=<?= filemtime(__DIR__ . '/../../assets/css/auth.css') ?>">
  <style>
    .reg-modal .modal-content {
      max-width: 560px;
      border-radius: 1.25rem;
      overflow: hidden;
      box-shadow: 0 24px 64px rgba(0, 0, 0, .25), 0 4px 16px rgba(0, 0, 0, .1);
    }

    .reg-modal .modal-dialog {
      max-width: 560px;
    }

    .reg-modal .auth-body {
      padding: 1.5rem 2.25rem;
    }

    .reg-modal .steps-bar {
      padding: .875rem 2.25rem;
    }

    .reg-modal .auth-footer-compact {
      text-align: center;
      padding: 0 2.25rem 1.25rem;
      font-size: .8rem;
      color: #6b7280;
    }

    .reg-modal .position-label {
      -webkit-hyphens: none;
      hyphens: none;
      white-space: nowrap;
      min-height: 42px;
    }

    @media(max-width:576px) {
      .reg-modal .modal-dialog {
        max-width: calc(100% - 1rem);
        margin: .5rem auto;
      }

      .reg-modal .auth-body {
        padding: 1.25rem 1rem;
      }

      .reg-modal .steps-bar {
        padding: .75rem 1rem;
      }

      .reg-modal .position-toggle {
        gap: .4rem;
      }

      .reg-modal .position-label {
        padding: .6rem .4rem;
        font-size: .72rem;
        min-height: 38px;
      }
    }

    @media(max-width:380px) {
      .reg-modal .position-toggle[style*="grid-template-columns:1fr 1fr 1fr"] {
        grid-template-columns: 1fr !important;
      }

      .reg-modal .position-toggle[style*="grid-template-columns:1fr 1fr 1fr"] .position-label {
        white-space: normal;
      }
    }
  </style>

  <div class="modal fade reg-modal" id="regModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="d-flex align-items-center gap-3 px-4 py-3 border-bottom" style="background:#f8fafd;">
          <div style="width:38px;height:38px;border-radius:.625rem;background:var(--primary);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;">
            <img src="<?= APP_URL ?>/assets/img/logo.png?v=<?= filemtime(__DIR__ . '/../../assets/img/logo.png') ?>" style="width:24px;height:24px;object-fit:contain;" alt="">
          </div>
          <div class="flex-grow-1">
            <div style="font-size:.875rem;font-weight:700;">Register New Member</div>
            <div style="font-size:.72rem;color:var(--muted);">Registering as <strong>@<?= e($user['username']) ?></strong></div>
          </div>
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        </div>
        <div class="steps-bar" id="rm_stepsBar">
          <div class="reg-step <?= (!$skipSelectStep && !$rmFreeMode) ? 'active' : '' ?>" id="rm_ind_1"
            <?= ($skipSelectStep || $rmFreeMode) ? 'style="display:none;"' : '' ?>>
            <div class="step-dot">1</div>
            <div class="step-text" id="rm_ind_1_label"><?= e($rmStepOneLabel) ?></div>
          </div>
          <div class="reg-step <?= ($skipSelectStep || $rmFreeMode) ? 'active' : '' ?>" id="rm_ind_2">
            <div class="step-dot" id="rm_ind_2_dot"><?= ($skipSelectStep || $rmFreeMode) ? '1' : '2' ?></div>
            <div class="step-text">Account Setup</div>
          </div>
          <div class="reg-step" id="rm_ind_3">
            <div class="step-dot" id="rm_ind_3_dot"><?= ($skipSelectStep || $rmFreeMode) ? '2' : '3' ?></div>
            <div class="step-text">Confirm</div>
          </div>
        </div>
        <form id="regModalForm" method="POST" action="<?= link_to('do_register') ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="validated_code" id="rm_validatedCode">
          <input type="hidden" name="sponsor_username" id="rm_sponsor_username">
          <input type="hidden" name="upline_username" id="rm_upline_username">
          <input type="hidden" name="binary_position" id="rm_binary_position">
          <input type="hidden" name="referral_mode" id="rm_referralMode" value="<?= ($skipSelectStep || $rmFreeMode) ? '1' : '' ?>">

          <!-- STEP 1 -->
          <div class="auth-body" id="rm_step1" <?= $skipSelectStep ? 'style="display:none;"' : '' ?>>
            <p class="text-muted mb-3" style="font-size:.85rem;">Choose payment method and package for the new member.</p>
            <div class="mb-3">
              <label class="form-label">Payment Method <span class="text-danger">*</span></label>
              <div class="position-toggle" style="grid-template-columns:<?= $rmFreeEnabled ? '1fr 1fr 1fr' : '1fr 1fr' ?>;">
                <?php if ($rmFreeEnabled): ?>
                  <div class="position-option"><input type="radio" id="rm_pay_free" name="payment_method" value="free" <?= $rmDefaultMethod === 'free' ? 'checked' : '' ?> required><label class="position-label" for="rm_pay_free">🎁 Free</label></div>
                <?php endif; ?>
                <div class="position-option"><input type="radio" id="rm_pay_code" name="payment_method" value="code" <?= $rmDefaultMethod === 'code' ? 'checked' : '' ?>><label class="position-label" for="rm_pay_code">🎫 Code</label></div>
                <div class="position-option"><input type="radio" id="rm_pay_ewallet" name="payment_method" value="ewallet" <?= $rmDefaultMethod === 'ewallet' ? 'checked' : '' ?>><label class="position-label" for="rm_pay_ewallet">💳 E-Wallet</label></div>
              </div>
            </div>
            <div id="rm_freeLimitNotice" class="alert alert-info py-2 mb-3" style="font-size:.8rem;display:none;">
              🎁 Free accounts are not placed in the binary network until the member activates.
            </div>
            <div id="rm_codeSection">
              <div class="mb-3">
                <label class="form-label">Registration Code <span class="text-danger">*</span></label>
                <div class="input-group">
                  <input type="text" id="rm_reg_code" name="reg_code" class="form-control font-mono" placeholder="XXXX-XXXX-XXXX" maxlength="18" style="text-transform:uppercase;letter-spacing:2px;font-size:1rem;" required>
                  <button type="button" class="btn btn-outline-primary" id="rm_validateCodeBtn">Validate</button>
                </div>
                <div class="form-text" id="rm_codeHint"></div>
              </div>
            </div>
            <div id="rm_packageSection" style="display:none;">
              <div class="mb-3">
                <label class="form-label">Package <span class="text-danger">*</span></label>
                <?php if (count($packages ?? []) === 1): ?>
                  <?php $mp = ($packages ?? [])[0]; ?>
                  <input type="hidden" name="package_id" id="rm_packageId" value="<?= (int)$mp['id'] ?>" data-pairing="<?= (int)($mp['pairing_enabled'] ?? 1) === 1 ? '1' : '0' ?>">
                  <div class="card border-primary">
                    <div class="card-body">
                      <div class="fw-bold text-primary"><?= e($mp['name']) ?></div>
                      <div style="font-size:.8rem;color:var(--muted);">Entry: <?= fmt_money((float)$mp['entry_fee']) ?> · Pair volume: <?= fmt_money((float)$mp['pairing_bonus']) ?> · Cap: <?= fmt_money((float)$mp['daily_pair_cap'] * (float)$mp['pairing_bonus']) ?>/day</div>
                    </div>
                  </div>
                  <div class="form-text text-success">✓ Package auto-selected.</div>
                <?php else: ?>
                  <select class="form-select" id="rm_packageSelect" name="package_id">
                    <option value="">Select a package…</option>
                    <?php foreach ($packages ?? [] as $pkg): ?>
                      <option value="<?= (int)$pkg['id'] ?>" data-name="<?= e($pkg['name']) ?>" data-fee="<?= fmt_money((float)$pkg['entry_fee']) ?>" data-bonus="<?= fmt_money((float)$pkg['pairing_bonus']) ?>" data-cap="<?= (int)$pkg['daily_pair_cap'] ?>" data-pairing="<?= (int)($pkg['pairing_enabled'] ?? 1) === 1 ? '1' : '0' ?>"><?= e($pkg['name']) ?> — <?= fmt_money((float)$pkg['entry_fee']) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <div class="form-text" id="rm_packageHint"></div>
                  <div id="rm_packageCard" class="code-verified d-none mt-2"><span style="font-size:1.2rem;">📦</span>
                    <div>
                      <div class="fw-bold" id="rm_pkgCardName"></div>
                      <div style="font-size:.75rem;margin-top:2px;" id="rm_pkgCardDetails"></div>
                    </div>
                  </div>
                <?php endif; ?>
              </div>
            </div>
            <div id="rm_packageInfo" class="code-verified d-none"><span style="font-size:1.2rem;">✅</span>
              <div>
                <div class="fw-bold" id="rm_pkgName"></div>
                <div style="font-size:.75rem;margin-top:2px;" id="rm_pkgDetails"></div>
              </div>
            </div>

            <?php if ($rmBinaryUiEnabled): ?>
            <div id="rm_binarySection">
              <?php if ($pairingEnabled && $rmFreeEnabled): ?>
                <div class="mb-3">
                  <label class="form-label">Binary Placement</label>
                  <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="rm_hasBinary" name="has_binary" value="1" checked>
                    <label class="form-check-label" for="rm_hasBinary">Has Binary</label>
                  </div>
                  <div class="form-text" id="rm_hasBinaryHint">Toggle ON to connect the member to the binary network now. Toggle OFF to defer the binary connection until the member activates.</div>
                </div>
              <?php endif; ?>
              <div id="rm_binaryModeSection" style="display:none;" class="mb-3">
                <label class="form-label">Binary Connection</label>
                <div class="position-toggle" style="grid-template-columns:1fr 1fr;">
                  <div class="position-option"><input type="radio" id="rm_mode_auto" name="binary_mode" value="auto" checked><label class="position-label" for="rm_mode_auto">✨ Auto</label></div>
                  <div class="position-option"><input type="radio" id="rm_mode_manual" name="binary_mode" value="manual"><label class="position-label" for="rm_mode_manual">🖐 Manual</label></div>
                </div>
                <p class="form-text" id="rm_autoPreviewHint">Auto places the new member at the best available position in the network.</p>
              </div>
              <div id="rm_manualSection">
                <div class="slot-status" style="margin-bottom:1rem;">
                  <span>↙ Upline: <strong id="rm_upline_display">—</strong></span>
                  <span>Position: <strong id="rm_position_display">—</strong></span>
                </div>
                <div style="font-size:.78rem;color:var(--muted);margin-bottom:1rem;">Sponsor: <strong id="rm_sponsor_display">—</strong></div>

                <!-- Position Selection -->
                <div class="mb-3">
                  <label class="form-label">Binary Position <span class="text-danger">*</span></label>
                  <div class="position-toggle" style="grid-template-columns:1fr 1fr;">
                    <div class="position-option"><input type="radio" id="rm_pos_left" name="binary_position_radio" value="left" required><label class="position-label" id="rm_pos_label_left" for="rm_pos_left">↙ Left</label></div>
                    <div class="position-option"><input type="radio" id="rm_pos_right" name="binary_position_radio" value="right"><label class="position-label" id="rm_pos_label_right" for="rm_pos_right">↘ Right</label></div>
                  </div>
                </div>
              </div>
            </div>
            <?php endif; ?>

            <button type="button" class="btn btn-primary w-100 btn-lg" id="rm_toStep2Btn" disabled>Continue →</button>
          </div>

          <!-- STEP 2 -->
          <div class="auth-body" id="rm_step2" <?= $skipSelectStep ? '' : 'style="display:none;"' ?>>
            <div id="rm_referralAlert" class="alert alert-info py-2 mb-3" style="font-size:.85rem;display:none;">🔗 No payment is required now — the member can activate their account later with a registration code or e-wallet.</div>
            <div class="mb-3">
              <label class="form-label">Username <span class="text-danger">*</span></label>
              <input type="text" id="rm_username" name="username" class="form-control" placeholder="3–40 chars, letters/numbers/_" minlength="3" maxlength="40" autocomplete="off" required>
              <div class="form-text" id="rm_usernameHint"></div>
            </div>
            <div class="row g-3 mb-3">
              <div class="col-md-6">
                <label class="form-label">Password <span class="text-danger">*</span></label>
                <div class="input-group">
                  <input type="password" id="rm_password" name="password" class="form-control" placeholder="Min. 8 characters" minlength="8" required>
                  <button type="button" class="btn btn-outline-secondary" onclick="rmTogglePw('rm_password',this)">👁</button>
                </div>
              </div>
              <div class="col-md-6">
                <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                <div class="input-group">
                  <input type="password" id="rm_password_confirm" name="password_confirm" class="form-control" placeholder="Repeat password" required>
                  <button type="button" class="btn btn-outline-secondary" onclick="rmTogglePw('rm_password_confirm',this)">👁</button>
                </div>
                <div class="form-text" id="rm_pwMatchHint"></div>
              </div>
            </div>
            <div class="mb-3">
              <label class="form-label">Sponsor Username <span class="text-danger">*</span></label>
              <input type="text" id="rm_sponsorInput" class="form-control" placeholder="Sponsor's username" autocomplete="off" required>
              <div class="form-text" id="rm_sponsorHint"></div>
            </div>
            <div class="d-flex gap-2">
              <?php if (!$skipSelectStep): ?>
                <button type="button" class="btn btn-outline-secondary" onclick="regGoStep(1)">← Back</button>
              <?php endif; ?>
              <button type="button" class="btn btn-primary flex-grow-1" id="rm_toStep3Btn">Review →</button>
            </div>
          </div>

          <!-- STEP 3 -->
          <div class="auth-body" id="rm_step3" style="display:none;">
            <p class="text-muted mb-3" style="font-size:.85rem;">Review before completing registration.</p>
            <div class="card mb-3">
              <div class="card-header"><span class="card-title">📋 Registration Summary</span></div>
              <div class="card-body">
                <table class="info-table">
                  <tr id="rm_revFreeRow" style="display:none;">
                    <td>Activation</td>
                    <td><span class="badge bg-warning text-dark">Pending</span></td>
                  </tr>
                  <tr id="rm_revPayRow">
                    <td>Payment</td>
                    <td id="rm_rev_payment">—</td>
                  </tr>
                  <tr id="rm_revCodeRow">
                    <td>Code</td>
                    <td><span class="reg-code" id="rm_rev_code">—</span></td>
                  </tr>
                  <tr id="rm_revPkgRow">
                    <td>Package</td>
                    <td id="rm_rev_package">—</td>
                  </tr>
                  <tr>
                    <td>Username</td>
                    <td id="rm_rev_username" class="fw-bold">—</td>
                  </tr>
                  <tr>
                    <td>Sponsor</td>
                    <td id="rm_rev_sponsor">—</td>
                  </tr>
                  <?php if ($rmBinaryUiEnabled): ?>
                  <tr id="rm_rev_uplineRow">
                    <td>Upline</td>
                    <td id="rm_rev_upline">—</td>
                  </tr>
                  <tr id="rm_rev_positionRow">
                    <td>Position</td>
                    <td id="rm_rev_position">—</td>
                  </tr>
                  <?php endif; ?>
                </table>
              </div>
            </div>
            <?php if ($rmBinaryUiEnabled): ?>
            <div class="alert alert-warning py-2 mb-3" style="font-size:.8rem;">⚠️ Binary position cannot be changed after registration.</div>
            <?php endif; ?>
            <div class="d-flex gap-2">
              <button type="button" class="btn btn-outline-secondary" onclick="regGoStep(2)">← Back</button>
              <button type="submit" class="btn btn-primary flex-grow-1 btn-lg" id="rm_submitBtn">✓ Complete Registration</button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php require 'views/partials/footer.php'; ?>