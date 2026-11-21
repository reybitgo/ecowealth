<?php
declare(strict_types=1);

/**
 * Commission detail drawer (plan §7 Phase 10).
 *
 * One offcanvas shared by every row on the member earnings page and
 * the admin commission ledger. Each "Details" button carries the row
 * as a JSON `data-commission` attribute; assets/js/app-commissions.js
 * fills the fields and opens the drawer, so the markup stays static
 * and no request is needed to peek at an award.
 *
 * Every value is written with textContent (never innerHTML), so a
 * member's username can never inject markup into the drawer.
 *
 * @var bool $show_recipient  Admin view: include the recipient row
 */
$show_recipient = (bool) ($show_recipient ?? false);
?>
            <div
              class="offcanvas offcanvas-end commission-detail"
              tabindex="-1"
              id="commissionDetail"
              aria-labelledby="commissionDetailLabel"
            >
              <div class="offcanvas-header border-bottom">
                <h5 class="offcanvas-title d-flex align-items-center gap-2" id="commissionDetailLabel">
                  <i class="icon-base bx bx-line-chart"></i> Commission detail
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
              </div>
              <div class="offcanvas-body">
                <div class="d-flex align-items-center gap-3 mb-4">
                  <span class="avatar">
                    <span class="avatar-initial rounded bg-label-success">
                      <i class="icon-base bx bx-check-shield"></i>
                    </span>
                  </span>
                  <div>
                    <div class="h4 mb-0" data-detail="amount">—</div>
                    <small class="text-body-secondary" data-detail="status">—</small>
                  </div>
                </div>

                <dl class="row g-0 commission-detail-list mb-0">
                  <dt class="col-5">Award</dt>
                  <dd class="col-7" data-detail="id">—</dd>

<?php if ($show_recipient): ?>
                  <dt class="col-5">Recipient</dt>
                  <dd class="col-7">
                    <a href="#" data-detail-link="recipient" data-detail="recipient">—</a>
                  </dd>
<?php endif; ?>

                  <dt class="col-5">Source member</dt>
                  <dd class="col-7" data-detail="source">—</dd>

                  <dt class="col-5">Package</dt>
                  <dd class="col-7" data-detail="package">—</dd>

                  <dt class="col-5">Type</dt>
                  <dd class="col-7" data-detail="type">—</dd>

                  <dt class="col-5">Level</dt>
                  <dd class="col-7" data-detail="level">—</dd>

                  <dt class="col-5">Awarded</dt>
                  <dd class="col-7" data-detail="created">—</dd>

                  <dt class="col-5">Activation</dt>
                  <dd class="col-7">
                    <a href="#" data-detail-link="activation" data-detail="activation">—</a>
                  </dd>

                  <dt class="col-5">Wallet entry</dt>
                  <dd class="col-7">
                    <a href="#" data-detail-link="wallet" data-detail="wallet">—</a>
                  </dd>

                  <dt class="col-5 commission-detail-reason-row" data-detail-row="reason">Reason</dt>
                  <dd class="col-7 commission-detail-reason-row" data-detail-row="reason" data-detail="reason">—</dd>
                </dl>

                <div class="alert alert-info small mt-4 mb-0">
                  <strong>Awards are final.</strong>
                  A commission is one immutable row linked to its wallet movement; a correction is a new
                  adjustment, never an edit.
                </div>
              </div>
            </div>
