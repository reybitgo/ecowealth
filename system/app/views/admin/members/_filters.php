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
 * @var array{q:string, status:string, package:string, harvest:string} $filters
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
                    <input
                      type="search"
                      class="form-control"
                      name="q"
                      value="<?= e($query) ?>"
                      placeholder="Username, name, email, sponsor or code…"
                      aria-label="Search members"
                    />
                    <select class="form-select" name="status" aria-label="Filter by status">
                      <option value="">All statuses</option>
<?php foreach (['active', 'pending', 'inactive', 'suspended'] as $option): ?>
                      <option value="<?= e($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= e(ucfirst($option)) ?></option>
<?php endforeach; ?>
                    </select>
                    <select class="form-select" name="package" aria-label="Filter by package">
                      <option value="">All packages</option>
                      <option value="none" <?= $package === 'none' ? 'selected' : '' ?>>No package</option>
<?php foreach ($packages as $row): ?>
                      <option value="<?= (int) $row['id'] ?>" <?= $package === (string) $row['id'] ? 'selected' : '' ?>>
                        <?= e($row['name']) ?> (<?= (int) $row['members'] ?>)
                      </option>
<?php endforeach; ?>
                    </select>
                    <select class="form-select" name="harvest" aria-label="Filter by Team Harvest queue">
                      <option value="">Team Harvest: all</option>
                      <option value="yes" <?= $harvest === 'yes' ? 'selected' : '' ?>>In the Harvest queue</option>
                      <option value="no" <?= $harvest === 'no' ? 'selected' : '' ?>>Not qualified</option>
                    </select>
                    <select class="form-select" name="board" aria-label="Filter by General Harvest board">
                      <option value="">General Harvest: all</option>
                      <option value="paid" <?= $board === 'paid' ? 'selected' : '' ?>>Has a paid level</option>
                      <option value="none" <?= $board === 'none' ? 'selected' : '' ?>>No paid level</option>
                    </select>
                    <select class="form-select" name="placement" aria-label="Filter by binary placement">
                      <option value="">Binary placement: all</option>
                      <option value="floating" <?= $placement === 'floating' ? 'selected' : '' ?>>Floating nodes</option>
                      <option value="no-node" <?= $placement === 'no-node' ? 'selected' : '' ?>>No binary node</option>
                    </select>
                    <button type="submit" class="btn btn-primary">Filter</button>
<?php if ($hasFilters): ?>
                    <a href="<?= e(url('admin/members')) ?>" class="btn btn-label-secondary"><i class="icon-base bx bx-x me-1"></i> Clear filters</a>
<?php endif; ?>
                  </form>
                </div>
