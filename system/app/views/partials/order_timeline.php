<?php
declare(strict_types=1);

/**
 * Order event timeline (plan §4.4, Phase 6).
 *
 * Every status row the order has ever had, oldest first. The actor is
 * "Member", "Admin" or "System"; when the actor still exists its
 * username is shown beside the label.
 *
 * @var list<array<string, mixed>> $events
 */
$events = $events ?? [];

$actorLabel = static function (array $event): string {
    $type     = (string) ($event['actor_type'] ?? 'system');
    $username = trim((string) ($event['actor_username'] ?? ''));

    $label = match ($type) {
        'member' => 'Member',
        'admin'  => 'Admin',
        default  => 'System',
    };

    return $username === '' ? $label : $label . ' · @' . $username;
};
?>
<?php if ($events === []): ?>
              <p class="text-body-secondary small mb-0">No status changes recorded yet.</p>
<?php else: ?>
              <ol class="order-timeline list-unstyled mb-0">
<?php foreach ($events as $event): ?>
                <li class="order-timeline-item">
                  <span class="order-timeline-dot" aria-hidden="true"></span>
                  <div class="order-timeline-body">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <div class="d-flex align-items-center gap-2">
<?php if ($event['from_status'] !== null && $event['from_status'] !== ''): ?>
                        <span class="small text-body-secondary"><?= e(ucfirst(str_replace('_', ' ', (string) $event['from_status']))) ?></span>
                        <i class="icon-base bx bx-right-arrow-alt text-body-secondary"></i>
<?php endif; ?>
                        <?= status_badge((string) $event['to_status']) ?>
                      </div>
                      <span class="small text-body-secondary text-nowrap"><?= e(format_datetime((string) $event['created_at'])) ?></span>
                    </div>
                    <div class="small text-body-secondary mt-1">
                      <?= e($actorLabel($event)) ?>
<?php if (trim((string) ($event['note'] ?? '')) !== ''): ?>
                      — <?= e((string) $event['note']) ?>
<?php endif; ?>
                    </div>
                  </div>
                </li>
<?php endforeach; ?>
              </ol>
<?php endif; ?>
