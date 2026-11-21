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
 * Phase 21F adds the **node** beside it (`Harvest #13 · node 12`): the
 * 0-based place on the shared General Harvest board, which is the position
 * minus one (§12.1). Both numbers come from the controller's one queue
 * read, never from a view-side computation.
 *
 * @var int      $team_count
 * @var int      $levels_used
 * @var int|null $harvest_position
 * @var int|null $harvest_node
 */

$harvest_position = $harvest_position ?? null;
$harvest_node     = $harvest_node ?? null;
?>
              <div class="d-flex gap-2 flex-wrap" data-live-region="genealogy-header">
                <span class="badge rounded-pill bg-label-primary"><?= (int) $team_count ?> in team</span>
                <span class="badge rounded-pill bg-label-secondary"><?= (int) $levels_used ?> level<?= (int) $levels_used === 1 ? '' : 's' ?></span>
<?php if ($harvest_position !== null): ?>
                <span class="badge rounded-pill bg-label-success" title="Your Harvest board position">
                  <i class="icon-base bx bx-trophy me-1"></i>Harvest #<?= (int) $harvest_position ?><?php if ($harvest_node !== null): ?><span class="fw-normal text-body-secondary"> · node <?= (int) $harvest_node ?></span><?php endif; ?>
                </span>
<?php endif; ?>
              </div>
