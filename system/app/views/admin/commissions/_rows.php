<?php
declare(strict_types=1);

/**
 * Admin commission ledger — rows.
 *
 * Each row is keyed by award id so the client patches only the rows that
 * changed; the links and the detail button carry data-live-focus so focus
 * survives a replacement.
 *
 * @var list<array<string, mixed>> $commissions
 * @var array{type:string, level:string, status:string, from:string, to:string, q:string} $filters
 */

$hasFilters = (string) $filters['type'] !== ''
    || (string) $filters['level'] !== ''
    || (string) $filters['status'] !== ''
    || (string) $filters['from'] !== ''
    || (string) $filters['to'] !== ''
    || (string) $filters['q'] !== '';
?>
                    <tbody data-live-region="commission-rows">
<?php if ($commissions === []): ?>
                      <tr>
                        <td colspan="8" class="py-0">
<?php
    echo View::partial('empty_state', [
        'icon'         => 'bx bx-line-chart',
        'title'        => $hasFilters ? 'No commissions match these filters' : 'No commissions yet',
        'text'         => $hasFilters
            ? 'Try a different member, type, level, status or date range.'
            : 'A direct referral is credited when a sponsored member activates a package.',
        'action_href'  => $hasFilters ? 'admin/commissions' : '',
        'action_label' => $hasFilters ? 'Clear filters' : '',
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
        'recipient'      => '@' . (string) $commission['recipient_username'],
        'recipient_url'  => url('admin/wallets/' . (int) $commission['user_id']),
        'source'         => '@' . (string) $commission['source_username'],
        'package'        => (string) $commission['package_name'],
        'type'           => commission_type_label($type),
        'level'          => commission_level_label($level),
        'amount'         => money($amount),
        'status'         => $credited ? 'Credited to the wallet' : ucfirst($status) . ' — no money moved',
        'reason'         => (string) ($commission['reason'] ?? ''),
        'created'        => format_datetime((string) $commission['created_at']),
        'activation'     => 'Activation #' . (int) $commission['activation_id'],
        'activation_url' => url('admin/activations?q=' . rawurlencode((string) $commission['source_username'])),
        'wallet'         => $credited ? 'E-wallet entry' : 'No wallet movement',
        'wallet_url'     => $credited ? url('admin/wallets/' . (int) $commission['user_id']) : '',
    ];
?>
                      <tr data-live-key="<?= $id ?>">
                        <td>
                          <a
                            href="<?= e(url('admin/members/' . (int) $commission['user_id'])) ?>"
                            class="text-body fw-medium"
                            data-live-focus="member"
                          >
                            @<?= e((string) $commission['recipient_username']) ?>
                          </a>
                          <?php if (trim((string) ($commission['recipient_name'] ?? '')) !== ''): ?>
                          <div class="small text-body-secondary"><?= e((string) $commission['recipient_name']) ?></div>
                          <?php endif; ?>
                          <div class="small text-body-secondary commission-meta-mobile">
                            #<?= (int) $commission['activation_id'] ?>
                            · <?= e(date('M j, Y', (int) strtotime((string) $commission['created_at']))) ?>
                          </div>
                        </td>
                        <td>
                          <span class="fw-medium">@<?= e((string) $commission['source_username']) ?></span>
                          <?php if (trim((string) ($commission['source_name'] ?? '')) !== ''): ?>
                          <div class="small text-body-secondary"><?= e((string) $commission['source_name']) ?></div>
                          <?php endif; ?>
                          <div class="small text-body-secondary"><?= e((string) $commission['package_name']) ?></div>
                        </td>
                        <td class="text-nowrap">
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
                        <td class="text-nowrap commission-col-activation">
                          <a
                            href="<?= e(url('admin/activations?q=' . rawurlencode((string) $commission['source_username']))) ?>"
                            class="text-body"
                            data-live-focus="activation"
                          >#<?= (int) $commission['activation_id'] ?></a>
                          <div class="small text-body-secondary"><?= e(payment_method_label((string) $commission['activation_method'])) ?></div>
                        </td>
                        <td class="text-nowrap commission-col-awarded">
                          <div><?= e(date('M j, Y', (int) strtotime((string) $commission['created_at']))) ?></div>
                          <div class="small text-body-secondary"><?= e(date('g:i A', (int) strtotime((string) $commission['created_at']))) ?></div>
                        </td>
                        <td class="text-end">
                          <button
                            type="button"
                            class="btn btn-sm btn-icon btn-label-secondary"
                            data-bs-toggle="tooltip"
                            title="Commission detail"
                            aria-label="Commission detail"
                            data-commission="<?= e((string) json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>"
                            data-live-focus="detail"
                          >
                            <i class="icon-base bx bx-show"></i>
                          </button>
                        </td>
                      </tr>
<?php endforeach; ?>
                    </tbody>
