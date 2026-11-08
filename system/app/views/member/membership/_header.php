<?php
declare(strict_types=1);

/**
 * Membership page — heading and status badge.
 *
 * @var string $status
 * @var string $status_label
 * @var string $status_tone
 */
?>
              <div
                class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4"
                data-live-region="membership-header"
              >
                <div>
                  <h4 class="mb-1">Membership</h4>
                  <p class="text-body-secondary mb-0">
                    Your associate status, package and activation history.
                  </p>
                </div>
                <?= status_badge($status, ['active' => [$status_label, $status_tone]]) ?>
              </div>
