<?php
declare(strict_types=1);

/**
 * Genealogy — the team badges in the page heading.
 *
 * A live region: a registration adds members to the team (and possibly a
 * new depth), so both counters refresh in place.
 *
 * Phase 20F adds the reader's own **Harvest position** badge. It is the
 * stored queue position (`harvest_queue.id`), shown only when the member
 * holds one: a member who has not qualified reads no badge at all, rather
 * than a "0" that would look like a rank.
 *
 * @var int      $team_count
 * @var int      $levels_used
 * @var int|null $harvest_position
 */

$harvest_position = $harvest_position ?? null;
?>
              <div class="d-flex gap-2 flex-wrap" data-live-region="genealogy-header">
                <span class="badge rounded-pill bg-label-primary"><?= (int) $team_count ?> in team</span>
                <span class="badge rounded-pill bg-label-secondary"><?= (int) $levels_used ?> level<?= (int) $levels_used === 1 ? '' : 's' ?></span>
<?php if ($harvest_position !== null): ?>
                <span class="badge rounded-pill bg-label-success" title="Your Team Harvest position">
                  <i class="icon-base bx bx-trophy me-1"></i>Harvest #<?= (int) $harvest_position ?>
                </span>
<?php endif; ?>
              </div>
