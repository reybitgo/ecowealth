<?php
declare(strict_types=1);

/**
 * Admin wallet page — the audited top-up form.
 *
 * The card owns a POST form, so the whole card is a live region held while
 * the administrator has unsaved input; the page's stale banner explains.
 *
 * @var array<string, mixed> $member
 */
?>
                  <div
                    class="card mb-6"
                    id="topup"
                    data-live-region="wallet-topup-form"
                    data-live-hold="dirty"
                  >
                    <div class="card-header">
                      <h5 class="mb-1">Credit this wallet</h5>
                      <p class="text-body-secondary small mb-0">
                        Top-ups credit the <strong>non-withdrawable</strong> bucket: the member can spend them in the
                        shop but cannot cash them out.
                      </p>
                    </div>
                    <div class="card-body">
                      <form
                        method="POST"
                        action="<?= e(url('admin/wallets/' . (int) $member['id'] . '/credit')) ?>"
                        data-confirm
                        data-confirm-title="Credit this wallet?"
                        data-confirm-text="The amount is added to the non-withdrawable balance immediately and recorded in the ledger and audit log. Check the amount and note before continuing."
                        data-confirm-button="Credit wallet"
                        data-confirm-cancel="Not yet"
                      >
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
