<?php
declare(strict_types=1);

/**
 * S-Login banner — shown on every member page while a superadmin is
 * impersonating (plan §3.4, §7 Phase 14).
 *
 * It is part of the member layout, not a dismissible notice: the
 * superadmin must always be able to see that this tab is a member
 * session and leave it. "Exit" is the only control; it POSTs to
 * /slogin/exit with the tab's token (url() adds it) and returns to the
 * console with the superadmin's own session intact.
 *
 * @var array<string, mixed>|null $me the impersonated member (auth_user())
 */

$me = auth_user();

if ($me === null) {
    return;
}

$impExpires  = (int) ($_SESSION['imp_expires'] ?? 0);
$impSuper    = (string) ($_SESSION['imp_created_by_username'] ?? '');
$impExpiresText = $impExpires > 0 ? date('M j, Y g:i A', $impExpires) : '';
?>
              <div class="alert alert-warning impersonation-banner d-flex flex-wrap align-items-center gap-3 mb-4" role="status" data-impersonation-banner>
                <span class="avatar avatar-sm flex-shrink-0">
                  <span class="avatar-initial rounded-circle bg-label-warning">
                    <i class="icon-base bx bx-shield-quarter"></i>
                  </span>
                </span>
                <div class="flex-grow-1">
                  <strong>Viewing as @<?= e((string) $me['username']) ?>.</strong>
                  This is a super-login session<?= $impSuper !== '' ? ' opened by @' . e($impSuper) : '' ?>.
<?php if ($impExpiresText !== ''): ?>
                  It ends automatically at <span class="text-nowrap"><?= e($impExpiresText) ?></span>.
<?php endif; ?>
                  Every page you open here is as this member — not as yourself.
                </div>
                <form method="POST" action="<?= e(url('slogin/exit')) ?>" class="flex-shrink-0">
                  <?= csrf_field() ?>
                  <button type="submit" class="btn btn-sm btn-warning">
                    <i class="icon-base bx bx-log-out me-1"></i> Exit member session
                  </button>
                </form>
              </div>
