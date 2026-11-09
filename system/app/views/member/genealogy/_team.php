<?php
declare(strict_types=1);

/**
 * Genealogy — the team grouped by level (level 2 and deeper).
 *
 * A live region: a new registration can add a member at any depth, so
 * the whole level grid is replaced while the card header stays static.
 *
 * Each level card paginates on its own (ten rows by default), so one
 * crowded level never pushes the others off the page and the query keys
 * stay namespaced: ?level3_page=2&level3_per_page=5.
 *
 * @var array<int, list<array<string, mixed>>> $deeper       rows for the current page
 * @var array<int, Paginator>                 $level_pagers one pager per level
 */

$level_pagers = $level_pagers ?? [];
?>
              <div data-live-region="genealogy-team">
<?php if ($deeper === []): ?>
                <p class="text-body-secondary small mb-0">
                  No second-level members yet. Your direct recruits' own recruits will show up here.
                </p>
<?php else: ?>
                <div class="row g-4">
<?php foreach ($deeper as $level => $rows): ?>
<?php
    $pager      = $level_pagers[$level] ?? null;
    $levelTotal = $pager !== null ? $pager->total : count($rows);
?>
                  <div class="col-md-6 col-xl-4">
                    <div class="border rounded-3 p-3 h-100 team-level-card" data-pagination-anchor>
                      <div class="team-level-chip">
                        <span class="badge bg-label-primary">Level <?= (int) $level ?></span>
                        <span class="text-body-secondary small"><?= $levelTotal ?> member<?= $levelTotal === 1 ? '' : 's' ?></span>
                      </div>
                      <ul class="list-unstyled mb-0 small">
<?php foreach ($rows as $row): ?>
<?php
    /* The row reads "@member → @sponsor": the member this level holds
     * and the upline that put them there. The arrow is a quiet glyph
     * rather than "-->" so it renders as one sentence, and a hidden
     * "sponsored by" gives screen readers the relationship in words. */
    $sponsorUsername = $row['sponsor_username'] ?? null;
?>
                        <li class="d-flex align-items-center justify-content-between gap-2 py-1">
                          <span class="text-truncate">
                            <span class="fw-medium">@<?= e((string) $row['username']) ?></span>
<?php if ($sponsorUsername !== null): ?>
                            <span class="team-sponsor-arrow" aria-hidden="true">→</span>
                            <span class="visually-hidden">sponsored by</span>
                            <span class="text-body-secondary">@<?= e((string) $sponsorUsername) ?></span>
<?php endif; ?>
                            <?php if ($row['package_name'] !== null): ?>
                            <span class="text-body-secondary d-block member-meta"><?= e((string) $row['package_name']) ?></span>
                            <?php endif; ?>
                          </span>
                          <?= status_badge((string) $row['member_status']) ?>
                        </li>
<?php endforeach; ?>
                      </ul>
<?php if ($pager !== null && $pager->total > 0): ?>
                      <div class="team-level-pagination">
<?= View::partial('pagination', [
    'pager'            => $pager,
    'page_param'       => 'level' . $level . '_page',
    'per_page_param'   => 'level' . $level . '_per_page',
    'pagination_label' => 'Level ' . $level . ' pagination',
]) ?>
                      </div>
<?php endif; ?>
                    </div>
                  </div>
<?php endforeach; ?>
                </div>
<?php endif; ?>
              </div>
