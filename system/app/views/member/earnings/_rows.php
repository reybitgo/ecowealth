<?php
declare(strict_types=1);

/**
 * Member earnings statement — award rows.
 *
 * Each row is keyed by award id so the client patches only the awards
 * that changed; the details button carries data-live-focus so focus
 * survives a row replacement.
 *
 * @var list<array<string, mixed>> $commissions
 * @var array{type:string, level:string, status:string, from:string, to:string, q:string} $filters
 * @var string $wallet_url
 */

$hasFilters = (string) $filters['type'] !== ''
    || (string) $filters['level'] !== ''
    || (string) $filters['status'] !== ''
    || (string) $filters['from'] !== ''
    || (string) $filters['to'] !== ''
    || (string) $filters['q'] !== '';
?>
                    <tbody data-live-region="earnings-rows">
<?php if ($commissions === []): ?>
                      <tr>
                        <td colspan="7" class="py-0">
<?php
    echo View::partial('empty_state', [
        'icon'         => 'bx bx-line-chart',
        'title'        => $hasFilters ? 'No awards match these filters' : 'No commissions yet',
        'text'         => $hasFilters
            ? 'Try a different type, level, status or date range.'
            : 'A ₱500 direct referral lands here the moment someone you sponsored activates a package. '
                . 'Your e-wallet balance and statement are the other half of the picture.',
        'action_href'  => $hasFilters ? 'earnings' : 'wallet',
        'action_label' => $hasFilters ? 'Clear filters' : 'Open e-wallet',
    ]);
?>
                        </td>
                      </tr>
<?php endif; ?>
<?php foreach ($commissions as $commission): ?>
<?php
    $id       = (int) $commission['id'];
    $status   = (string) $commission['status'];
    $amount   = (float) $commission['amount'];
    $level    = (int) $commission['level'];
    $type     = (string) $commission['type'];
    $credited = $status === 'credited';
    $detail   = [
        'id'             => '#' . $id,
        'source'         => '@' . (string) $commission['source_username'],
        'package'        => (string) $commission['package_name'],
        'type'           => commission_type_label($type),
        'level'          => commission_level_label($level),
        'amount'         => money($amount),
        'status'         => $credited ? 'Credited to your e-wallet' : ucfirst($status) . ' — no money moved',
        'reason'         => (string) ($commission['reason'] ?? ''),
        'created'        => format_datetime((string) $commission['created_at']),
        'activation'     => 'Activation #' . (int) $commission['activation_id'],
        'activation_url' => url('membership'),
        'wallet'         => $credited ? 'E-wallet statement' : 'No wallet movement',
        'wallet_url'     => $credited ? $wallet_url : '',
    ];
?>
                      <tr data-live-key="<?= $id ?>">
                        <td class="text-nowrap commission-col-date">
                          <div><?= e(date('M j, Y', (int) strtotime((string) $commission['created_at']))) ?></div>
                          <div class="small text-body-secondary"><?= e(date('g:i A', (int) strtotime((string) $commission['created_at']))) ?></div>
                        </td>
                        <td class="commission-col-source">
                          <span class="fw-medium">@<?= e((string) $commission['source_username']) ?></span>
                          <?php if (trim((string) ($commission['source_name'] ?? '')) !== ''): ?>
                          <div class="small text-body-secondary"><?= e((string) $commission['source_name']) ?></div>
                          <?php endif; ?>
                          <div class="small text-body-secondary commission-meta-mobile">
                            <?= e((string) $commission['package_name']) ?>
                            · <?= e(commission_type_label($type)) ?>
                            · <?= e(commission_level_label($level)) ?>
                            · <?= e(date('M j, Y', (int) strtotime((string) $commission['created_at']))) ?>
                          </div>
                        </td>
                        <td class="text-nowrap commission-col-package"><?= e((string) $commission['package_name']) ?></td>
                        <td class="text-nowrap commission-col-type">
                          <span class="d-inline-flex align-items-center gap-2">
                            <i class="icon-base <?= e(commission_type_icon($type)) ?>"></i>
                            <span>
                              <?= e(commission_type_label($type)) ?>
                              <span class="d-block small text-body-secondary"><?= e(commission_level_label($level)) ?></span>
                            </span>
                          </span>
                        </td>
                        <td class="text-end text-nowrap fw-semibold <?= $credited ? 'commission-amount-credit' : 'text-body-secondary' ?>">
                          <?= e($credited ? money($amount, true) : money($amount)) ?>
<?php if (!$credited): ?>
                          <div class="small text-body-secondary fw-normal">not paid</div>
<?php endif; ?>
                        </td>
                        <td>
                          <?= status_badge($status) ?>
<?php if ($status === 'skipped' && (string) ($commission['reason'] ?? '') !== ''): ?>
                          <div class="small text-body-secondary commission-reason"><?= e((string) $commission['reason']) ?></div>
<?php endif; ?>
                        </td>
                        <td class="text-end">
                          <button
                            type="button"
                            class="btn btn-sm btn-icon btn-label-secondary"
                            data-bs-toggle="tooltip"
                            title="Commission detail"
                            aria-label="Commission detail"
                            data-live-focus="details"
                            data-commission="<?= e((string) json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>"
                          >
                            <i class="icon-base bx bx-show"></i>
                          </button>
                        </td>
                      </tr>
<?php endforeach; ?>
                    </tbody>
