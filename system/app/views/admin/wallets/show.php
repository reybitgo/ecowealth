<?php
declare(strict_types=1);

/**
 * Admin — one member's wallet: both buckets, the top-up form, the
 * statement and the top-up history (plan §5.8, §7 Phase 5; reference
 * views/admin/ewallet_topup.php).
 *
 * @var array<string, mixed> $member
 * @var list<array<string, mixed>> $ledger
 * @var Paginator $pager
 * @var array{rows:int, credits:float, debits:float, net:float} $totals
 * @var array{type:string, ref_type:string, from:string, to:string, q:string} $filters
 * @var string $statement_url
 * @var string $statement_title
 * @var bool $show_member
 * @var list<array<string, mixed>> $topups
 * @var Paginator $topups_pager
 */

$total        = round((float) $member['ewallet_balance'], 2);
$withdrawable = round((float) $member['withdrawable_balance'], 2);
$locked       = round($total - $withdrawable, 2);

/* Member status, not sign-in state: a pending member has is_active = 1
 * so they can activate later, but the badge must read Pending. */
$memberStatus = (string) ($member['member_status'] ?? '');

if ($memberStatus === '') {
    $memberStatus = (int) ($member['is_active'] ?? 0) === 1 ? 'active' : 'inactive';
}

$roleTone     = match ((string) $member['role']) {
    'superadmin' => 'danger',
    'admin'      => 'info',
    default      => 'secondary',
};
?>
            <div class="container-xxl flex-grow-1 container-p-y wallet-page">
              <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-6">
                <div>
                  <div class="d-flex align-items-center gap-2 small text-body-secondary mb-1">
                    <a href="<?= e(url('admin/wallets')) ?>" class="text-body-secondary">E-wallet monitor</a>
                    <i class="icon-base bx bx-chevron-right"></i>
                    <span>Wallet</span>
                  </div>
                  <h4 class="mb-1">@<?= e((string) $member['username']) ?></h4>
                  <p class="text-body-secondary mb-0">
                    <?= e(trim((string) ($member['full_name'] ?? '')) !== '' ? (string) $member['full_name'] : (string) $member['email']) ?>
                    · <?= e((string) $member['email']) ?>
                  </p>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-2">
                  <span class="badge bg-label-<?= e($roleTone) ?>"><?= e(ucfirst((string) $member['role'])) ?></span>
                  <?= status_badge($memberStatus) ?>
                  <a href="<?= e(url('admin/wallets')) ?>" class="btn btn-label-secondary">
                    <i class="icon-base bx bx-arrow-back me-1"></i> Back to monitor
                  </a>
                </div>
              </div>

              <div class="row g-6 mb-6">
                <div class="col-12 col-md-4">
                  <div class="card h-100 wallet-stat-card">
                    <div class="card-body">
                      <div class="d-flex align-items-center justify-content-between mb-3">
                        <span class="avatar avatar-sm">
                          <span class="avatar-initial rounded bg-label-primary"><i class="icon-base bx bx-wallet"></i></span>
                        </span>
                        <span class="text-body-secondary small">Total balance</span>
                      </div>
                      <h4 class="mb-0"><?= e(money($total)) ?></h4>
                    </div>
                  </div>
                </div>
                <div class="col-12 col-md-4">
                  <div class="card h-100 wallet-stat-card">
                    <div class="card-body">
                      <div class="d-flex align-items-center justify-content-between mb-3">
                        <span class="avatar avatar-sm">
                          <span class="avatar-initial rounded bg-label-success"><i class="icon-base bx bx-money-withdraw"></i></span>
                        </span>
                        <span class="text-body-secondary small">Withdrawable</span>
                      </div>
                      <h4 class="mb-0"><?= e(money($withdrawable)) ?></h4>
                    </div>
                  </div>
                </div>
                <div class="col-12 col-md-4">
                  <div class="card h-100 wallet-stat-card">
                    <div class="card-body">
                      <div class="d-flex align-items-center justify-content-between mb-3">
                        <span class="avatar avatar-sm">
                          <span class="avatar-initial rounded bg-label-warning"><i class="icon-base bx bx-lock-alt"></i></span>
                        </span>
                        <span class="text-body-secondary small">Non-withdrawable</span>
                      </div>
                      <h4 class="mb-0"><?= e(money($locked)) ?></h4>
                    </div>
                  </div>
                </div>
              </div>

              <div class="row g-6">
                <div class="col-lg-8 order-2 order-lg-1">
<?=
    View::partial('wallet_statement', [
        'filters'         => $filters,
        'ledger'          => $ledger,
        'pager'           => $pager,
        'totals'          => $totals,
        'statement_url'   => $statement_url,
        'statement_title' => $statement_title,
        'show_member'     => false,
        'empty_title'     => 'This wallet has no movements yet',
        'empty_text'      => 'Credit it with a top-up, or wait for its first commission.',
    ])
?>
                </div>

                <div class="col-lg-4 order-1 order-lg-2">
                  <div class="card mb-6" id="topup">
                    <div class="card-header">
                      <h5 class="mb-1">Credit this wallet</h5>
                      <p class="text-body-secondary small mb-0">
                        Top-ups credit the <strong>non-withdrawable</strong> bucket: the member can spend them in the
                        shop but cannot cash them out.
                      </p>
                    </div>
                    <div class="card-body">
                      <form method="POST" action="<?= e(url('admin/wallets/' . (int) $member['id'] . '/credit')) ?>">
                        <?= csrf_field() ?>

                        <div class="mb-4">
                          <label class="form-label" for="topup-amount">Amount</label>
                          <div class="input-group">
                            <span class="input-group-text"><?= e(CURRENCY_SYMBOL) ?></span>
                            <input
                              type="number"
                              step="0.01"
                              min="0.01"
                              max="9999999999.99"
                              name="amount"
                              id="topup-amount"
                              class="form-control <?= e(invalid_class('amount')) ?>"
                              value="<?= old('amount') ?>"
                              placeholder="0.00"
                              required
                              autocomplete="off"
                            />
                          </div>
                          <?php if (has_error('amount')): ?>
                            <div class="invalid-feedback d-block"><?= e(error_for('amount')) ?></div>
                          <?php endif; ?>
                        </div>

                        <div class="mb-4">
                          <label class="form-label" for="topup-note">Note <span class="text-body-secondary">(optional)</span></label>
                          <textarea
                            name="note"
                            id="topup-note"
                            rows="2"
                            maxlength="255"
                            class="form-control <?= e(invalid_class('note')) ?>"
                            placeholder="Reason for this credit…"
                          ><?= old('note') ?></textarea>
                          <?php if (has_error('note')): ?>
                            <div class="invalid-feedback d-block"><?= e(error_for('note')) ?></div>
                          <?php endif; ?>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">
                          <i class="icon-base bx bx-plus-circle me-1"></i> Credit wallet
                        </button>
                      </form>
                    </div>
                    <div class="card-footer small text-body-secondary">
                      Recorded three ways: a top-up row, a ledger row and an audit-log entry — all in one transaction.
                    </div>
                  </div>

                  <div class="card">
                    <div class="card-header">
                      <h5 class="mb-0">Top-up history</h5>
                    </div>
                    <div class="table-responsive">
                      <table class="table table-sm align-middle mb-0">
                        <thead>
                          <tr>
                            <th scope="col">Date</th>
                            <th scope="col" class="text-end">Amount</th>
                            <th scope="col">By</th>
                          </tr>
                        </thead>
                        <tbody>
<?php if ($topups === []): ?>
                          <tr>
                            <td colspan="3" class="text-center text-body-secondary small py-4">No top-ups yet.</td>
                          </tr>
<?php endif; ?>
<?php foreach ($topups as $topup): ?>
                          <tr>
                            <td class="small text-nowrap">
                              <div><?= e(format_datetime((string) $topup['created_at'])) ?></div>
<?php if (trim((string) ($topup['note'] ?? '')) !== ''): ?>
                              <div class="text-body-secondary wallet-ref-note"><?= e((string) $topup['note']) ?></div>
<?php endif; ?>
                            </td>
                            <td class="text-end text-nowrap fw-semibold wallet-amount-positive"><?= e(money((float) $topup['amount'], true)) ?></td>
                            <td><span class="badge bg-label-info"><?= e((string) $topup['admin_username']) ?></span></td>
                          </tr>
<?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                    <div class="card-body py-3">
<?= View::partial('pagination', ['pager' => $topups_pager, 'page_param' => 'topup_page']) ?>
                    </div>
                  </div>
                </div>
              </div>
            </div>
