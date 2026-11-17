<?php
declare(strict_types=1);

/**
 * Admin General Harvest ledger — rows (Phase 21E-S1, plan §9 Rules 5–6,
 * Appendix C.2).
 *
 * One row per block a node completed: the instant it was decided, the
 * member, the stored position, the node index, the level, the queue
 * length the block filled at, the threshold it crossed, the amount, the
 * status and the wallet movement.
 *
 * Rule 5: the **Position** and **Node** columns read `harvest_queue.id`
 * and `seq` from the ledger — the page never recomputes a position from a
 * count. Rule 6: every row walks to its member and to its wallet movement
 * in one click each; a `baseline` row has no movement and therefore shows
 * `—` instead of a link (it was never paid).
 *
 * @var list<array<string, mixed>> $awards
 * @var array{user_id:int, level:string, status:string, from:string, to:string, q:string} $filters
 */

$awards  = $awards ?? [];
$filters = $filters ?? GeneralHarvestAward::filterDefaults();

$hasFilters = (string) $filters['level'] !== ''
    || (string) $filters['status'] !== ''
    || (string) $filters['from'] !== ''
    || (string) $filters['to'] !== ''
    || (string) $filters['q'] !== '';

$ledgerUrl = url('admin/commissions?ledger=general_harvest');
?>
                    <tbody data-live-region="general-harvest-rows">
<?php if ($awards === []): ?>
                      <tr>
                        <td colspan="10" class="py-0">
<?php
    echo View::partial('empty_state', [
        'icon'  => 'bx bx-grid-alt',
        'title' => $hasFilters ? 'No General Harvest awards match these filters' : 'No block has completed yet',
        'text'  => $hasFilters
            ? 'Try a different level, status, date or member.'
            : 'A block\'s first level needs the queue to reach ' . GeneralHarvestLevel::threshold(0, 1)
                . ' qualified members.',
        'action_href'  => $hasFilters ? $ledgerUrl : '',
        'action_label' => $hasFilters ? 'Clear filters' : '',
    ]);
?>
                        </td>
                      </tr>
<?php endif; ?>
<?php foreach ($awards as $award): ?>
<?php
    $id       = (int) $award['id'];
    $status   = (string) $award['status'];
    $isPaid   = $status === GeneralHarvestAward::STATUS_CREDITED;
    $position = $award['position'] === null ? null : (int) $award['position'];
    $seq      = (int) ($award['seq'] ?? 0);
    $credited = (string) ($award['credited_at'] ?? '');
    $walletUrl = url('admin/wallets/' . (int) $award['user_id']);
    $memberUrl = url('admin/members/' . (int) $award['user_id']);
?>
                      <tr data-live-key="<?= $id ?>">
                        <td class="text-nowrap">
<?php if ($credited !== ''): ?>
                          <div><?= e(format_date($credited)) ?></div>
                          <div class="small text-body-secondary"><?= e(date('g:i A', (int) strtotime($credited))) ?></div>
<?php else: ?>
                          <span class="text-body-secondary small">—</span>
<?php endif; ?>
                        </td>
                        <td>
                          <a href="<?= e($memberUrl) ?>" class="text-body fw-medium">
                            @<?= e((string) $award['username']) ?>
                          </a>
                        </td>
                        <td class="text-nowrap">
<?php if ($position === null): ?>
                          <span class="text-body-secondary">—</span>
<?php else: ?>
                          <span class="badge bg-label-primary">#<?= $position ?></span>
<?php endif; ?>
                        </td>
                        <td class="text-nowrap fw-medium">Node <?= $seq ?></td>
                        <td class="text-nowrap fw-medium">Level <?= (int) $award['level'] ?></td>
                        <td class="text-end commission-num"><?= (int) $award['n_at_award'] ?></td>
                        <td class="text-end commission-num text-body-secondary"><?= (int) $award['threshold_n'] ?></td>
                        <td class="text-end commission-num fw-semibold <?= $isPaid ? 'commission-amount-credit' : '' ?>">
                          <?= e(money((float) $award['amount'], $isPaid)) ?>
<?php if (!$isPaid): ?>
                          <div class="small text-body-secondary fw-normal">not paid</div>
<?php endif; ?>
                        </td>
                        <td><?= status_badge($status, GeneralHarvestAward::STATUS_BADGES) ?></td>
                        <td class="text-end">
<?php if (($award['ledger_id'] ?? null) !== null): ?>
                          <a
                            href="<?= e($walletUrl) ?>"
                            class="btn btn-sm btn-icon btn-label-secondary"
                            title="Open the wallet movement (ledger #<?= (int) $award['ledger_id'] ?>)"
                            aria-label="Open the wallet movement"
                          >
                            <i class="icon-base bx bx-wallet"></i>
                          </a>
                          <div class="small text-body-secondary">#<?= (int) $award['ledger_id'] ?></div>
<?php else: ?>
                          <span class="text-body-secondary small" title="No wallet movement: recorded before go-live">—</span>
<?php endif; ?>
                        </td>
                      </tr>
<?php endforeach; ?>
                    </tbody>
