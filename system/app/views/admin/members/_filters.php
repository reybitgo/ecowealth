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
 * @var array{q:string, status:string, package:string} $filters
 * @var list<array{id:int, name:string, members:int}> $packages
 */

$query      = (string) $filters['q'];
$status     = (string) $filters['status'];
$package    = (string) $filters['package'];
$hasFilters = $query !== '' || $status !== '' || $package !== '';
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
                    <button type="submit" class="btn btn-label-primary">Filter</button>
<?php if ($hasFilters): ?>
                    <a href="<?= e(url('admin/members')) ?>" class="btn btn-label-secondary">Clear</a>
<?php endif; ?>
                  </form>
                </div>
