<?php
declare(strict_types=1);

/**
 * Admin — audit log (plan §7 Phase 15).
 *
 * Every audited admin / money action, newest first. Filterable by
 * action, subject, actor and date. The order-event trail and the
 * impersonation log keep their own pages; this list links to them.
 *
 * @var array{total:int, today:int, actors:int, settings:int} $stats
 * @var array{q:string, action:string, subject_type:string, from:string, to:string} $filters
 * @var Paginator $pager
 * @var list<array<string, mixed>> $entries
 * @var list<string> $actions
 * @var list<string> $subjects
 * @var string $audit_url
 * @var bool $has_filters
 */

$stats   = $stats ?? ['total' => 0, 'today' => 0, 'actors' => 0, 'settings' => 0];
$filters = $filters ?? AuditLog::filterDefaults();
$actions = $actions ?? [];
$subjects = $subjects ?? [];
$entries = $entries ?? [];
$auditUrl = $audit_url ?? url('admin/audit');

/**
 * One-line summary of an entry's meta payload.
 *
 * @param array<string, mixed> $meta
 */
$metaSummary = static function (array $meta): string {
    if ($meta === []) {
        return '';
    }

    if (isset($meta['section'])) {
        $changes = $meta['changes'] ?? [];
        $keys    = is_array($changes) ? array_keys($changes) : [];
        $label   = (string) $meta['section'];

        return $label . ($keys === [] ? '' : ' · ' . implode(', ', array_slice(array_map('strval', $keys), 0, 4)))
            . (count($keys) > 4 ? ' …' : '');
    }

    if (isset($meta['type'])) {
        $parts = [(string) $meta['type']];

        if (isset($meta['rows'])) {
            $parts[] = (int) $meta['rows'] . ' rows';
        }

        if (isset($meta['from'], $meta['to'])) {
            $parts[] = (string) $meta['from'] . ' → ' . (string) $meta['to'];
        }

        return implode(' · ', $parts);
    }

    if (isset($meta['username'])) {
        return (string) $meta['username'];
    }

    if (isset($meta['amount'])) {
        return money($meta['amount']);
    }

    $first = reset($meta);

    return is_scalar($first) ? (string) $first : '';
};

$cards = [
    ['label' => 'Entries', 'value' => number_format((int) $stats['total']), 'icon' => 'bx-list-ul', 'tone' => 'primary', 'hint' => 'Since the first audited action'],
    ['label' => 'Today', 'value' => number_format((int) $stats['today']), 'icon' => 'bx-calendar', 'tone' => 'info', 'hint' => 'Written since midnight'],
    ['label' => 'Actors', 'value' => number_format((int) $stats['actors']), 'icon' => 'bx-group', 'tone' => 'success', 'hint' => 'Distinct accounts'],
    ['label' => 'Settings changes', 'value' => number_format((int) $stats['settings']), 'icon' => 'bx-cog', 'tone' => 'warning', 'hint' => 'Every key change is recorded'],
];
?>
            <div class="container-xxl flex-grow-1 container-p-y audit-page">
              <?= flash_render() ?>

              <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
                <div>
                  <h4 class="mb-1">Audit log</h4>
                  <p class="text-body-secondary mb-0">
                    Every audited admin and money action, newest first. The table is append-only — an audit entry is
                    never edited or deleted.
                  </p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                  <a href="<?= e(url('admin/impersonations')) ?>" class="btn btn-label-secondary">
                    <i class="icon-base bx bx-shield-quarter me-1"></i> S-Login sessions
                  </a>
                  <a href="<?= e(url('admin/reports')) ?>" class="btn btn-label-primary">
                    <i class="icon-base bx bx-bar-chart-alt-2 me-1"></i> Reports
                  </a>
                </div>
              </div>

              <div class="row g-4 mb-6">
<?php foreach ($cards as $card): ?>
                <div class="col-6 col-xl-3">
                  <div class="card h-100 report-kpi-card">
                    <div class="card-body">
                      <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="avatar avatar-sm">
                          <span class="avatar-initial rounded bg-label-<?= e($card['tone']) ?>">
                            <i class="icon-base <?= e(icon_class($card['icon'])) ?>"></i>
                          </span>
                        </span>
                        <span class="text-body-secondary small text-end"><?= e($card['label']) ?></span>
                      </div>
                      <h4 class="mb-1 report-kpi-value"><?= e($card['value']) ?></h4>
                      <p class="text-body-secondary small mb-0"><?= e($card['hint']) ?></p>
                    </div>
                  </div>
                </div>
<?php endforeach; ?>
              </div>

              <div class="card">
                <div class="card-body border-bottom">
                  <form method="GET" action="<?= e($auditUrl) ?>" class="d-flex flex-wrap align-items-end gap-3">
                    <?= per_page_hidden() ?>
                    <div class="flex-grow-1" style="min-width: 14rem">
                      <label class="form-label" for="audit-q">Search</label>
                      <input
                        type="search"
                        name="q"
                        id="audit-q"
                        class="form-control"
                        value="<?= e($filters['q']) ?>"
                        placeholder="Action, actor or subject…"
                      />
                    </div>
                    <div style="min-width: 12rem">
                      <label class="form-label" for="audit-action">Action</label>
                      <select name="action" id="audit-action" class="form-select">
                        <option value="">All actions</option>
<?php foreach ($actions as $action): ?>
                        <option value="<?= e($action) ?>" <?= $filters['action'] === $action ? 'selected' : '' ?>><?= e($action) ?></option>
<?php endforeach; ?>
                      </select>
                    </div>
                    <div style="min-width: 11rem">
                      <label class="form-label" for="audit-subject">Subject</label>
                      <select name="subject_type" id="audit-subject" class="form-select">
                        <option value="">All subjects</option>
<?php foreach ($subjects as $subject): ?>
                        <option value="<?= e($subject) ?>" <?= $filters['subject_type'] === $subject ? 'selected' : '' ?>><?= e($subject) ?></option>
<?php endforeach; ?>
                      </select>
                    </div>
                    <div style="min-width: 9.75rem">
                      <label class="form-label" for="audit-from">From</label>
                      <input type="date" name="from" id="audit-from" class="form-control" value="<?= e($filters['from']) ?>" />
                    </div>
                    <div style="min-width: 9.75rem">
                      <label class="form-label" for="audit-to">To</label>
                      <input type="date" name="to" id="audit-to" class="form-control" value="<?= e($filters['to']) ?>" />
                    </div>
                    <div class="d-flex align-items-center gap-2">
                      <button type="submit" class="btn btn-primary">Filter</button>
<?php if ($has_filters): ?>
                      <a href="<?= e($auditUrl) ?>" class="btn btn-label-secondary">Clear</a>
<?php endif; ?>
                    </div>
                  </form>
                </div>

                <div class="card-header py-3">
                  <span class="text-body-secondary small"><?= e($pager->summary()) ?></span>
                </div>

                <div class="table-responsive">
                  <table class="table table-hover align-middle mb-0 report-table audit-table">
                    <thead>
                      <tr>
                        <th scope="col">When</th>
                        <th scope="col">Actor</th>
                        <th scope="col">Action</th>
                        <th scope="col">Subject</th>
                        <th scope="col">Details</th>
                        <th scope="col">IP</th>
                      </tr>
                    </thead>
                    <tbody>
<?php foreach ($entries as $entry): ?>
<?php
    $meta    = AuditLog::meta(isset($entry['meta_json']) ? (string) $entry['meta_json'] : null);
    $summary = $metaSummary($meta);
    $actor   = (string) ($entry['actor_username'] ?? '');
    $role    = (string) ($entry['actor_role'] ?? '');
    $subject = trim(
        (string) ($entry['subject_type'] ?? '')
        . ((int) ($entry['subject_id'] ?? 0) > 0 ? ' #' . (int) $entry['subject_id'] : '')
    );
?>
                      <tr>
                        <td class="text-nowrap" title="<?= e((string) $entry['created_at']) ?>">
                          <div class="fw-medium"><?= e(time_ago(isset($entry['created_at']) ? (string) $entry['created_at'] : '')) ?></div>
                          <small class="text-body-secondary"><?= e(format_datetime(isset($entry['created_at']) ? (string) $entry['created_at'] : '')) ?></small>
                        </td>
                        <td>
<?php if ($actor !== ''): ?>
                          <div class="fw-medium">@<?= e($actor) ?></div>
                          <small class="text-body-secondary"><?= e((string) ($entry['actor_name'] ?? '')) ?></small>
<?php else: ?>
                          <span class="text-body-secondary">system</span>
<?php endif; ?>
                          <?php if ($role !== ''): ?>
                          <span class="badge bg-label-secondary ms-1"><?= e($role) ?></span>
                          <?php endif; ?>
                        </td>
                        <td><code class="audit-action"><?= e((string) $entry['action']) ?></code></td>
                        <td class="text-body-secondary"><?= e($subject === '' ? '—' : $subject) ?></td>
                        <td class="audit-detail"><?= e($summary === '' ? '—' : $summary) ?></td>
                        <td class="text-body-secondary small text-nowrap"><?= e((string) ($entry['ip'] ?? '—')) ?></td>
                      </tr>
<?php endforeach; ?>
<?php if ($entries === []): ?>
                      <tr>
                        <td colspan="6" class="text-center text-body-secondary py-6">
                          No audit entries match these filters.
                        </td>
                      </tr>
<?php endif; ?>
                    </tbody>
                  </table>
                </div>

                <div class="card-footer d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <span class="text-body-secondary small"><?= e($pager->summary()) ?></span>
                  <?= View::partial('pagination', ['pager' => $pager]) ?>
                </div>
              </div>
            </div>
