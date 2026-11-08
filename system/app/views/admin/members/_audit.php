<?php
declare(strict_types=1);

/**
 * Admin member page — recent audit card.
 *
 * @var list<array<string, mixed>> $audits
 */
?>
                  <div class="card" data-live-region="member-audit">
                    <div class="card-header"><h5 class="card-title mb-0">Recent audit</h5></div>
                    <ul class="list-group list-group-flush">
<?php if ($audits === []): ?>
                      <li class="list-group-item text-body-secondary small">No audit entries yet.</li>
<?php endif; ?>
<?php foreach ($audits as $entry): ?>
                      <li class="list-group-item">
                        <div class="d-flex align-items-center justify-content-between gap-2">
                          <span class="small fw-medium"><?= e((string) $entry['action']) ?></span>
                          <span class="member-meta text-nowrap"><?= e(format_datetime((string) $entry['created_at'])) ?></span>
                        </div>
                        <div class="member-meta"><?= e((string) ($entry['meta_json'] ?? '')) ?></div>
                      </li>
<?php endforeach; ?>
                    </ul>
                  </div>
