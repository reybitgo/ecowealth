<?php
declare(strict_types=1);

/**
 * Wallet statement — filters, ledger table, running balance and totals
 * (plan §5.8, §7 Phase 5).
 *
 * Shared by the member statement (`/wallet`) and the admin wallet page,
 * so both roles read exactly the same rows and totals.
 *
 * @var array{type:string, ref_type:string, from:string, to:string, q:string} $filters
 * @var list<array<string, mixed>> $ledger
 * @var Paginator                  $pager
 * @var array{rows:int, credits:float, debits:float, net:float} $totals
 * @var string  $statement_url   Already resolved through url()
 * @var string  $statement_title
 * @var bool    $show_member     Admin view: add the member column
 * @var string  $empty_title
 * @var string  $empty_text
 * @var array<string, string> $hidden_params Extra GET parameters (e.g. the monitor tab)
 */

$filters        = $filters ?? Wallet::filterDefaults();
$ledger         = $ledger ?? [];
$totals         = $totals ?? ['rows' => 0, 'credits' => 0.0, 'debits' => 0.0, 'net' => 0.0];
$statement_url  = $statement_url ?? url('wallet');
$statement_title = $statement_title ?? 'Statement';
$show_member    = (bool) ($show_member ?? false);
$empty_title    = $empty_title ?? 'No wallet movements yet';
$empty_text     = $empty_text ?? 'Money movements appear here as they happen — commissions, top-ups, purchases and payouts.';
$hidden_params  = $hidden_params ?? [];

$hasFilters = ($filters['type'] ?? '') !== ''
    || ($filters['ref_type'] ?? '') !== ''
    || ($filters['from'] ?? '') !== ''
    || ($filters['to'] ?? '') !== ''
    || ($filters['q'] ?? '') !== '';

$columns = $show_member ? 6 : 5;
?>
              <div class="card wallet-statement">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3">
                  <div>
                    <h5 class="mb-1"><?= e($statement_title) ?></h5>
                    <p class="text-body-secondary mb-0 small">
                      Every movement is one immutable ledger row; the balance after each row is recorded with it.
                    </p>
                  </div>
                  <span class="badge bg-label-primary"><?= (int) $totals['rows'] ?> movement<?= (int) $totals['rows'] === 1 ? '' : 's' ?></span>
                </div>

                <div class="card-body border-bottom">
                  <form method="GET" action="<?= e($statement_url) ?>" class="d-flex flex-wrap align-items-end gap-3 wallet-filters">
<?php foreach ($hidden_params as $paramName => $paramValue): ?>
                    <input type="hidden" name="<?= e((string) $paramName) ?>" value="<?= e((string) $paramValue) ?>" />
<?php endforeach; ?>
                    <div class="wallet-filter-field">
                      <label class="form-label" for="wallet-filter-type">Type</label>
                      <select name="type" id="wallet-filter-type" class="form-select">
                        <option value="">All types</option>
                        <option value="credit" <?= ($filters['type'] ?? '') === 'credit' ? 'selected' : '' ?>>Credit</option>
                        <option value="debit" <?= ($filters['type'] ?? '') === 'debit' ? 'selected' : '' ?>>Debit</option>
                      </select>
                    </div>

                    <div class="wallet-filter-field wallet-filter-wide">
                      <label class="form-label" for="wallet-filter-ref">Source</label>
                      <select name="ref_type" id="wallet-filter-ref" class="form-select">
                        <option value="">All sources</option>
<?php foreach (WalletService::REF_TYPES as $refType): ?>
                        <option value="<?= e($refType) ?>" <?= ($filters['ref_type'] ?? '') === $refType ? 'selected' : '' ?>>
                          <?= e(wallet_ref_label($refType)) ?>
                        </option>
<?php endforeach; ?>
                      </select>
                    </div>

                    <div class="wallet-filter-field wallet-filter-date">
                      <label class="form-label" for="wallet-filter-from">From</label>
                      <input type="date" name="from" id="wallet-filter-from" class="form-control" value="<?= e($filters['from'] ?? '') ?>" />
                    </div>

                    <div class="wallet-filter-field wallet-filter-date">
                      <label class="form-label" for="wallet-filter-to">To</label>
                      <input type="date" name="to" id="wallet-filter-to" class="form-control" value="<?= e($filters['to'] ?? '') ?>" />
                    </div>

                    <div class="wallet-filter-field wallet-filter-wide">
                      <label class="form-label" for="wallet-filter-q">Note</label>
                      <input type="search" name="q" id="wallet-filter-q" class="form-control" value="<?= e($filters['q'] ?? '') ?>" placeholder="Search notes…" />
                    </div>

                    <div class="wallet-filter-actions">
                      <button type="submit" class="btn btn-primary">Filter</button>
<?php if ($hasFilters): ?>
                      <a href="<?= e($statement_url) ?>" class="btn btn-label-secondary ms-2">
                        <i class="icon-base bx bx-x me-1"></i> Clear filters
                      </a>
<?php endif; ?>
                    </div>
                  </form>
                </div>

                <div class="table-responsive">
                  <table class="table table-hover align-middle wallet-ledger-table mb-0">
                    <thead>
                      <tr>
<?php if ($show_member): ?>
                        <th scope="col">Member</th>
<?php endif; ?>
                        <th scope="col" class="wallet-col-date">Date</th>
                        <th scope="col" class="wallet-col-type">Type</th>
                        <th scope="col">Source</th>
                        <th scope="col" class="text-end">Amount</th>
                        <th scope="col" class="text-end wallet-col-balance">Balance after</th>
                      </tr>
                    </thead>
                    <tbody>
<?php if ($ledger === []): ?>
                      <tr>
                        <td colspan="<?= (int) $columns ?>" class="py-0">
<?php
    echo View::partial('empty_state', [
        'icon'  => 'bx bx-wallet',
        'title' => $empty_title,
        'text'  => $empty_text,
    ]);
?>
                        </td>
                      </tr>
<?php endif; ?>
<?php foreach ($ledger as $row): ?>
<?php
    $isCredit = ((string) $row['type']) === 'credit';
    $amount   = (float) $row['amount'];
    $refType  = (string) $row['ref_type'];
    $when     = format_datetime((string) $row['created_at']);
?>
                      <tr>
<?php if ($show_member): ?>
                        <td>
                          <a href="<?= e(url('admin/wallets/' . (int) $row['user_id'])) ?>" class="text-body fw-medium">
                            <?= e((string) $row['username']) ?>
                          </a>
                          <?php if (trim((string) ($row['user_name'] ?? '')) !== ''): ?>
                            <div class="small text-body-secondary"><?= e((string) $row['user_name']) ?></div>
                          <?php endif; ?>
                        </td>
<?php endif; ?>
                        <td class="text-nowrap wallet-col-date">
                          <div class="small"><?= e($when) ?></div>
                        </td>
                        <td class="wallet-col-type">
                          <span class="badge bg-label-<?= $isCredit ? 'success' : 'danger' ?>">
                            <i class="icon-base bx <?= $isCredit ? 'bx-down-arrow-circle' : 'bx-up-arrow-circle' ?> me-1"></i>
                            <?= $isCredit ? 'Credit' : 'Debit' ?>
                          </span>
                        </td>
                        <td>
                          <div class="d-flex align-items-center gap-2">
                            <span class="wallet-ref-icon">
                              <i class="icon-base <?= e(wallet_ref_icon($refType)) ?>"></i>
                            </span>
                            <div class="min-w-0">
                              <div class="fw-medium"><?= e(wallet_ref_label($refType)) ?></div>
<?php if (trim((string) ($row['note'] ?? '')) !== ''): ?>
                              <div class="small text-body-secondary wallet-ref-note"><?= e((string) $row['note']) ?></div>
<?php endif; ?>
                              <div class="small text-body-secondary wallet-date-mobile"><?= e($when) ?> · <?= $isCredit ? 'Credit' : 'Debit' ?></div>
                            </div>
                          </div>
                        </td>
                        <td class="text-end text-nowrap fw-semibold <?= $isCredit ? 'wallet-amount-positive' : 'wallet-amount-negative' ?>">
                          <?= e(money($isCredit ? $amount : -$amount, true)) ?>
                          <div class="small text-body-secondary fw-normal wallet-balance-mobile">
                            Balance <?= e(money((float) $row['balance_after'])) ?>
                          </div>
                        </td>
                        <td class="text-end text-nowrap wallet-col-balance">
                          <div><?= e(money((float) $row['balance_after'])) ?></div>
                          <div class="small text-body-secondary">
                            <?= e(money((float) $row['withdrawable_after'])) ?> withdrawable
                          </div>
                        </td>
                      </tr>
<?php endforeach; ?>
                    </tbody>
                  </table>
                </div>

                <div class="card-body">
                  <div class="wallet-ledger-totals">
                    <div class="d-flex flex-wrap gap-4">
                      <div>
                        <span class="text-body-secondary small d-block">Credits</span>
                        <span class="fw-semibold wallet-amount-positive"><?= e(money((float) $totals['credits'])) ?></span>
                      </div>
                      <div>
                        <span class="text-body-secondary small d-block">Debits</span>
                        <span class="fw-semibold wallet-amount-negative"><?= e(money((float) $totals['debits'])) ?></span>
                      </div>
                      <div>
                        <span class="text-body-secondary small d-block">Net</span>
                        <span class="fw-semibold"><?= e(money((float) $totals['net'], true)) ?></span>
                      </div>
                    </div>
                  </div>

<?= View::partial('pagination', ['pager' => $pager, 'pager_label' => 'Movements']) ?>
                </div>
              </div>
