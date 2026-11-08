<?php
declare(strict_types=1);

/**
 * Genealogy — the team badges in the page heading.
 *
 * A live region: a registration adds members to the team (and possibly a
 * new depth), so both counters refresh in place.
 *
 * @var int $team_count
 * @var int $levels_used
 */
?>
              <div class="d-flex gap-2 flex-wrap" data-live-region="genealogy-header">
                <span class="badge rounded-pill bg-label-primary"><?= (int) $team_count ?> in team</span>
                <span class="badge rounded-pill bg-label-secondary"><?= (int) $levels_used ?> level<?= (int) $levels_used === 1 ? '' : 's' ?></span>
              </div>
