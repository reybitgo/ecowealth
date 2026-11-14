<?php
declare(strict_types=1);

/**
 * Member wallet — the "withdraw earnings" card (live region).
 *
 * The statement answers "what happened"; the send-funds card answers
 * "what can I pass on"; this one answers "what can I take out". It shows
 * the withdrawable bucket, the floor and whether a request is already
 * open, so the member knows before opening the payout page.
 *
 * Phase 18 adds the escrow reminder (plan §11.1): repeat-purchase money
 * pending release is named here, under the withdrawable figure it is
 * missing from, with a link to the statement that shows why.
 *
 * @var array<string, mixed> $payout
 * @var array{total:float, count:int, next_release_at:?string, undelivered:int, overdue:int, release_enabled:bool} $pending_repeat
 */

$payout = $payout ?? [];
$pending_repeat = is_array($pending_repeat ?? null) ? $pending_repeat : [];

$withdrawable = (float) ($payout['withdrawable'] ?? 0);
$min          = (float) ($payout['min'] ?? 0);
$open         = $payout['open'] ?? null;
$canRequest   = (bool) ($payout['can_request'] ?? false);
$pendingTotal = round((float) ($pending_repeat['total'] ?? 0), 2);
?>
              <div class="card mb-6 wallet-payout-card" data-live-region="member-wallet-payout">
                <div class="card-body">
                  <div class="d-flex flex-wrap align-items-center justify-content-between gap-4">
                    <div class="d-flex align-items-start gap-3">
                      <span class="avatar avatar-md flex-shrink-0">
                        <span class="avatar-initial rounded-circle bg-label-success">
                          <i class="icon-base bx bx-money-withdraw icon-md"></i>
                        </span>
                      </span>
                      <div>
                        <h5 class="mb-1">Withdraw earnings</h5>
                        <p class="text-body-secondary small mb-0">
<?php if (is_array($open)): ?>
                          You have a <?= e((string) $open['status']) ?> request for
                          <?= e(money((float) $open['amount'])) ?> — the amount is held until it is processed.
<?php elseif ($canRequest): ?>
                          Take out up to <?= e(money($withdrawable)) ?> by GCash, Maya or USDT.
                          Minimum <?= e(money($min)) ?>.
<?php else: ?>
                          Only commission earnings are withdrawable. You have
                          <?= e(money($withdrawable)) ?> available<?= $min > 0 ? ' (minimum ' . e(money($min)) . ')' : '' ?>.
<?php endif; ?>
                        </p>
<?php if ($pendingTotal > 0): ?>
                        <p class="small text-body-secondary mb-0 mt-1">
                          <i class="icon-base bx bx-time-five me-1"></i>
                          <?= e(money($pendingTotal)) ?> pending release — not withdrawable yet.
                          <a href="<?= e(url('earnings?ledger=repeat')) ?>">Repeat-purchase statement</a>
                        </p>
<?php endif; ?>
                      </div>
                    </div>
                    <a href="<?= e(url('payouts')) ?>" class="btn btn-primary">
                      <i class="icon-base bx bx-money-withdraw me-1"></i> Payouts
                    </a>
                  </div>
                </div>
              </div>
