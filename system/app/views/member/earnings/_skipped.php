<?php
declare(strict_types=1);

/**
 * Member earnings statement — the skipped-award notice.
 *
 * Always present as a region; `hidden` while the member has no skipped
 * awards, so a first skipped award can appear live without the client
 * inserting a region it does not know.
 *
 * @var array{skipped:int} $totals
 */

$skipped = (int) ($totals['skipped'] ?? 0);
?>
              <div data-live-region="earnings-skipped"<?= $skipped > 0 ? '' : ' hidden' ?>>
<?php if ($skipped > 0): ?>
                <div class="alert alert-warning d-flex align-items-start gap-2 mb-4" role="alert">
                  <i class="icon-base bx bx-info-circle fs-4"></i>
                  <div class="small">
                    <strong><?= $skipped ?> award<?= $skipped === 1 ? ' was' : 's were' ?> skipped.</strong>
                    Your upline position was not eligible (no active package) when the activation happened, so no
                    money moved. Skipped awards are kept for the audit trail and never paid later.
                  </div>
                </div>
<?php endif; ?>
              </div>
