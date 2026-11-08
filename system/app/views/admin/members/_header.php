<?php
declare(strict_types=1);

/**
 * Admin member directory — heading row.
 *
 * Kept as its own region so the "joined this week" badge follows the
 * directory's counters, which are computed from CURDATE().
 *
 * @var array{total:int, active:int, pending:int, suspended:int, with_package:int, new_week:int} $stats
 */
?>
              <div
                class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4"
                data-live-region="member-header"
              >
                <div>
                  <h4 class="mb-1">Members</h4>
                  <p class="text-body-secondary mb-0">
                    Sponsor chain, package, status and wallet for every member account.
                  </p>
                </div>
                <span class="badge rounded-pill bg-label-primary"><?= (int) $stats['new_week'] ?> joined this week</span>
              </div>
