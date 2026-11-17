<?php
declare(strict_types=1);

/**
 * Member General Harvest statement — the block roster (Phase 21F,
 * plan §10 Rules 8 and 13, Appendix C.3).
 *
 * Shows who occupies each position in one of the member's own blocks,
 * one page of 25 at a time. A member's username is shown only inside the
 * viewer's own sponsor subtree (D-20): *Direct* and *In your team* carry
 * a name; everyone else reads "a member outside your team" with the
 * position only. No other member's amount, wallet or rank is ever shown.
 *
 * The range, slot count and filled count are the service's
 * (`GeneralHarvestService::rosterFor()`); this view formats them.
 *
 * @var array<string, mixed>|null $roster GeneralHarvestService::rosterFor()
 */

$roster = is_array($roster ?? null) ? $roster : null;

if ($roster === null) {
    return;
}

$level   = (int) ($roster['level'] ?? 0);
$page    = max(1, (int) ($roster['page'] ?? 1));
$pages   = max(1, (int) ($roster['pages'] ?? 1));
$from    = (int) ($roster['from'] ?? 0);
$to      = (int) ($roster['to'] ?? 0);
$filled  = (int) ($roster['filled'] ?? 0);
$total   = (int) ($roster['total'] ?? 0);
$rows    = is_array($roster['rows'] ?? null) ? $roster['rows'] : [];

$rosterUrl = static fn (int $target): string => url(
    'earnings?ledger=general_harvest&roster_level=' . $level . '&roster_page=' . $target
);
?>
              <div class="card mb-4" data-live-region="earnings-general-roster">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <h5 class="card-title mb-0">Members in your level <?= $level ?> block</h5>
                  <span class="badge bg-label-info text-nowrap">
                    #<?= number_format($from) ?>–#<?= number_format($to) ?>
                    · <?= number_format($filled) ?> of <?= number_format($total) ?> filled
                  </span>
                </div>
                <div class="table-responsive">
                  <table class="table table-hover align-middle commission-table mb-0">
                    <thead>
                      <tr>
                        <th scope="col" class="text-end" style="width: 16%">Position</th>
                        <th scope="col" style="width: 44%">Member</th>
                        <th scope="col" style="width: 40%">Relation to you</th>
                      </tr>
                    </thead>
                    <tbody data-live-region="earnings-general-roster-rows">
<?php if ($rows === []): ?>
                      <tr>
                        <td colspan="3" class="py-3 text-body-secondary text-center">
                          No member has reached this block yet. It fills in the order members
                          qualified, from the whole network.
                        </td>
                      </tr>
<?php endif; ?>
<?php foreach ($rows as $row): ?>
<?php
    $pos      = (int) ($row['position'] ?? 0);
    $rel      = (string) ($row['relation'] ?? 'outside');
    $label    = match ($rel) {
        'self'    => 'You',
        'direct'  => 'Direct',
        'in-team' => 'In your team',
        default   => 'A member outside your team',
    };
    $tone     = match ($rel) {
        'self'    => 'success',
        'direct', 'in-team' => 'primary',
        default   => 'secondary',
    };
    $nameCell = $rel === 'outside'
        ? '<span class="text-body-secondary">a member outside your team</span>'
        : e((string) ($row['username'] ?? ''));
?>
                      <tr data-live-key="roster-<?= $pos ?>">
                        <td class="text-end text-nowrap fw-medium">#<?= number_format($pos) ?></td>
                        <td><?= $nameCell ?></td>
                        <td class="text-nowrap">
                          <span class="badge bg-label-<?= $tone ?> text-nowrap"><?= e($label) ?></span>
                        </td>
                      </tr>
<?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
<?php if ($pages > 1): ?>
                <div class="card-footer">
                  <ul class="pagination pagination-sm justify-content-center mb-0">
<?php if ($page > 1): ?>
                    <li class="page-item">
                      <a class="page-link" href="<?= e($rosterUrl($page - 1)) ?>">Previous</a>
                    </li>
<?php endif; ?>
<?php for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++): ?>
                    <li class="page-item<?= $p === $page ? ' active' : '' ?>">
                      <a class="page-link" href="<?= e($rosterUrl($p)) ?>"><?= $p ?></a>
                    </li>
<?php endfor; ?>
<?php if ($page < $pages): ?>
                    <li class="page-item">
                      <a class="page-link" href="<?= e($rosterUrl($page + 1)) ?>">Next</a>
                    </li>
<?php endif; ?>
                  </ul>
                </div>
<?php endif; ?>
              </div>
