<?php
declare(strict_types=1);

/**
 * Admin settings — the placeholder-accounts notice.
 *
 * Always present as a region; hidden while the receiving accounts look
 * configured, so the first real account saved hides it live.
 *
 * @var bool $manual_open
 */

$manualOpen = (bool) ($manual_open ?? true);
?>
              <div data-live-region="settings-notice"<?= $manualOpen ? ' hidden' : '' ?>>
<?php if (!$manualOpen): ?>
                <div class="alert alert-warning d-flex align-items-start gap-2 mb-6" role="alert">
                  <i class="icon-base bx bx-error-circle fs-5"></i>
                  <div>
                    <strong>The receiving accounts still look like placeholders.</strong>
                    Replace them with the real GCash / Maya / bank accounts before you accept manual payments;
                    buyers copy them from the checkout and order pages.
                  </div>
                </div>
<?php endif; ?>
              </div>
