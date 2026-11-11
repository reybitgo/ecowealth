<?php
declare(strict_types=1);

/**
 * Super-login console (plan §3.4, §7 Phase 14; reference
 * views/auth/slogin.php).
 *
 * The superadmin types a member username (or picks one from the
 * datalist) and opens a URL-token member session in this tab. The
 * browser receives no cookie: the ticket is the `?imp=<nonce>` in the
 * URL, and only its SHA-256 is stored in the audit row. A session that
 * is lost cannot be resumed from here — that is the point.
 *
 * @var list<array<string, mixed>> $members  active member accounts for the datalist
 * @var list<array<string, mixed>> $active   live sessions (inside TTL)
 * @var list<array<string, mixed>> $recent   last sessions
 * @var array{active:int, expired:int, logged_out:int, all:int, overdue:int} $counts
 * @var string $notice     '' | expired | exited
 * @var string $audit_url
 * @var array{surface:string, enabled:bool, revision:int, etag:string, interval:int} $live
 *
 * One live surface (`admin.slogin`): the active and recent cards are the
 * regions. The member picker, the explainer and the page heading stay
 * outside them, so a background refresh never discards a typed username.
 */

$live = $live ?? [
    'surface'  => 'admin.slogin',
    'enabled'  => false,
    'revision' => 0,
    'etag'     => '',
    'interval' => 30000,
];
?>
            <div
              class="container-xxl flex-grow-1 container-p-y sl-login-page"
              data-live-surface="<?= e($live['surface']) ?>"
              data-live-enabled="<?= $live['enabled'] ? '1' : '0' ?>"
              data-live-revision="<?= (int) $live['revision'] ?>"
              data-live-etag="<?= e((string) $live['etag']) ?>"
              data-live-interval="<?= (int) $live['interval'] ?>"
            >
              <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-6">
                <div>
                  <h4 class="mb-1">
                    <i class="icon-base bx bx-shield-quarter me-1"></i> Super-login
                  </h4>
                  <p class="text-body-secondary mb-0">
                    Open any active member's portal in this tab — exactly as they see it — through an audited,
                    expiring URL-token session. Your own session stays signed in.
                  </p>
                </div>
                <div class="d-flex align-items-center gap-3">
                  <span class="small text-body-secondary" data-live-status aria-live="polite"></span>
                  <a href="<?= e($audit_url) ?>" class="btn btn-label-primary">
                    <i class="icon-base bx bx-history me-1"></i> Audit trail
                  </a>
                </div>
              </div>

<?php if ($notice === 'expired'): ?>
              <div class="alert alert-warning d-flex align-items-start gap-2 mb-6" role="alert">
                <i class="icon-base bx bx-time-five fs-4"></i>
                <div>
                  <strong>That member session has ended.</strong>
                  It expired, was opened from another network/browser, or the member account changed.
                  The audit trail records the end reason. Open a new session below.
                </div>
              </div>
<?php elseif ($notice === 'exited'): ?>
              <div class="alert alert-success d-flex align-items-start gap-2 mb-6" role="alert">
                <i class="icon-base bx bx-check-circle fs-4"></i>
                <div>
                  <strong>You exited the member session.</strong>
                  You are back in your own account; the impersonation was marked <em>logged out</em> in the audit.
                </div>
              </div>
<?php endif; ?>

              <div class="row g-6 mb-6">
                <div class="col-12 col-xl-5">
                  <div class="card h-100">
                    <div class="card-header">
                      <h5 class="mb-0">Open a member session</h5>
                    </div>
                    <div class="card-body">
                      <form method="POST" action="<?= e(url('slogin')) ?>" novalidate data-slogin-form>
                        <?= csrf_field() ?>
                        <div class="mb-4">
                          <label class="form-label" for="sloginUsername">Member username</label>
                          <div class="input-group">
                            <span class="input-group-text"><i class="icon-base bx bx-user"></i></span>
                            <input
                              type="text"
                              class="form-control <?= invalid_class('username') ?>"
                              id="sloginUsername"
                              name="username"
                              value="<?= old('username') ?>"
                              list="sloginMembers"
                              placeholder="e.g. juan"
                              autocomplete="off"
                              spellcheck="false"
                              maxlength="50"
                              required
                              autofocus
                            />
                          </div>
<?php if (has_error('username')): ?>
                          <div class="invalid-feedback d-block"><?= e(error_for('username')) ?></div>
<?php endif; ?>
                          <div class="form-text">No password needed — only active members can be opened.</div>
                        </div>
                        <datalist id="sloginMembers">
<?php foreach ($members as $member): ?>
                          <option value="<?= e((string) $member['username']) ?>"><?= e(trim((string) ($member['full_name'] ?? '')) !== '' ? (string) $member['full_name'] : (string) $member['username']) ?><?= (string) $member['member_status'] !== 'active' ? ' · ' . e(ucfirst((string) $member['member_status'])) : '' ?></option>
<?php endforeach; ?>
                        </datalist>
                        <button type="submit" class="btn btn-primary d-grid w-100" data-slogin-submit-lg>
                          <span data-slogin-label><i class="icon-base bx bx-log-in-circle me-1"></i> Open member session</span>
                        </button>
                      </form>
                    </div>
                  </div>
                </div>

                <div class="col-12 col-xl-7">
                  <div class="card h-100">
                    <div class="card-header">
                      <h5 class="mb-0">How this session works</h5>
                    </div>
                    <div class="card-body">
                      <ul class="list-unstyled mb-0 sl-facts">
                        <li class="d-flex align-items-start gap-3 mb-3">
                          <i class="icon-base bx bx-link-alt fs-4 text-primary"></i>
                          <div>
                            <strong>URL token, no cookie.</strong>
                            Every link, form and background refresh in the member tab carries <code>?imp=…</code>.
                            Closing the tab ends access; your superadmin cookie session is never modified.
                          </div>
                        </li>
                        <li class="d-flex align-items-start gap-3 mb-3">
                          <i class="icon-base bx bx-time-five fs-4 text-info"></i>
                          <div>
                            <strong>Expires after 8 hours.</strong>
                            The session also ends on an IP or browser change, or if the member is suspended.
                          </div>
                        </li>
                        <li class="d-flex align-items-start gap-3 mb-3">
                          <i class="icon-base bx bx-fingerprint fs-4 text-warning"></i>
                          <div>
                            <strong>Hashed nonce, full audit.</strong>
                            Only a SHA-256 of the token is stored; a lost link cannot be recovered or replayed from the database.
                          </div>
                        </li>
                        <li class="d-flex align-items-start gap-3">
                          <i class="icon-base bx bx-user-voice fs-4 text-danger"></i>
                          <div>
                            <strong>Everything is performed as the member.</strong>
                            The tab has no admin routes. Orders, transfers and other actions are recorded against the member, and the session is visible in the audit trail.
                          </div>
                        </li>
                      </ul>
                    </div>
                  </div>
                </div>
              </div>

              <div class="row g-6">
                <div class="col-12 col-xl-6">
<?= View::capture('auth/slogin/_active', ['active' => $active, 'counts' => $counts]) ?>
                </div>

                <div class="col-12 col-xl-6">
<?= View::capture('auth/slogin/_recent', ['recent' => $recent, 'counts' => $counts, 'audit_url' => $audit_url]) ?>
                </div>
              </div>
            </div>
