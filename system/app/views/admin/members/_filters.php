<?php
declare(strict_types=1);

/**
 * Admin member directory — search/filter form.
 *
 * The form is a live region because the package options carry member
 * counts, but it holds unsaved input: app-live.js keeps it (and the typed
 * search) when the administrator is mid-edit and the page shows its stale
 * banner instead.
 *
 * Phase 20E adds the Team Harvest filter: `harvest=yes` keeps only members
 * with a stored queue position and `harvest=no` only those without one. The
 * two values are the whole vocabulary — an unknown value is "no filter"
 * (Rule 9), so the select can never invent a third state.
 *
 * @var array{q:string, status:string, package:string, harvest:string, board:string, placement:string} $filters
 * @var list<array{id:int, name:string, members:int}> $packages
 */

$query      = (string) $filters['q'];
$status     = (string) $filters['status'];
$package    = (string) $filters['package'];
$harvest    = (string) ($filters['harvest'] ?? '');
$board      = (string) ($filters['board'] ?? '');
$placement  = (string) ($filters['placement'] ?? '');
$hasFilters = $query !== '' || $status !== '' || $package !== '' || $harvest !== '' || $board !== '' || $placement !== '';
?>
                <div
                  class="card-body py-3 border-top"
                  data-live-region="member-filters"
                  data-live-hold="dirty"
                >
                  <form method="GET" action="<?= e(url('admin/members')) ?>" class="membership-filters">
                    <?= per_page_hidden() ?>
                    <div class="filter-field filter-field-wide">
                      <label class="form-label" for="member-filter-q">Search</label>
                      <input
                        type="search"
                        class="form-control"
                        id="member-filter-q"
                        name="q"
                        value="<?= e($query) ?>"
                        placeholder="Username, name, email, sponsor or code…"
                      />
                    </div>
                    <div class="filter-field">
                      <label class="form-label" for="member-filter-status">Status</label>
                      <select class="form-select" id="member-filter-status" name="status">
                        <option value="">All statuses</option>
<?php foreach (['active', 'pending', 'inactive', 'suspended'] as $option): ?>
                        <option value="<?= e($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= e(ucfirst($option)) ?></option>
<?php endforeach; ?>
                      </select>
                    </div>
                    <div class="filter-field">
                      <label class="form-label" for="member-filter-package">Package</label>
                      <select class="form-select" id="member-filter-package" name="package">
                        <option value="">All packages</option>
                        <option value="none" <?= $package === 'none' ? 'selected' : '' ?>>No package</option>
<?php foreach ($packages as $row): ?>
                        <option value="<?= (int) $row['id'] ?>" <?= $package === (string) $row['id'] ? 'selected' : '' ?>>
                          <?= e($row['name']) ?> (<?= (int) $row['members'] ?>)
                        </option>
<?php endforeach; ?>
                      </select>
                    </div>
                    <div class="filter-field">
                      <label class="form-label" for="member-filter-harvest">Team Harvest</label>
                      <select class="form-select" id="member-filter-harvest" name="harvest">
                        <option value="">All</option>
                        <option value="yes" <?= $harvest === 'yes' ? 'selected' : '' ?>>In the Harvest queue</option>
                        <option value="no" <?= $harvest === 'no' ? 'selected' : '' ?>>Not qualified</option>
                      </select>
                    </div>
                    <div class="filter-field">
                      <label class="form-label" for="member-filter-board">General Harvest</label>
                      <select class="form-select" id="member-filter-board" name="board">
                        <option value="">All</option>
                        <option value="paid" <?= $board === 'paid' ? 'selected' : '' ?>>Has a paid level</option>
                        <option value="none" <?= $board === 'none' ? 'selected' : '' ?>>No paid level</option>
                      </select>
                    </div>
                    <div class="filter-field">
                      <label class="form-label" for="member-filter-placement">Binary placement</label>
                      <select class="form-select" id="member-filter-placement" name="placement">
                        <option value="">All</option>
                        <option value="floating" <?= $placement === 'floating' ? 'selected' : '' ?>>Floating nodes</option>
                        <option value="no-node" <?= $placement === 'no-node' ? 'selected' : '' ?>>No binary node</option>
                      </select>
                    </div>
                    <div class="filter-actions">
                      <button type="submit" class="btn btn-primary">Filter</button>
<?php if ($hasFilters): ?>
                      <a href="<?= e(url('admin/members')) ?>" class="btn btn-label-secondary"><i class="icon-base bx bx-x me-1"></i> Clear filters</a>
<?php endif; ?>
                    </div>
                  </form>
                </div>
