<?php
declare(strict_types=1);

/**
 * Referral link — the one referral widget in the member portal (UX-43).
 *
 * The dashboard hero, the Membership referral card and the Genealogy team
 * chain each carried their own copy of the link field, copy button and code
 * line, and the labels had already drifted ("Your referral link" vs
 * "Referral link", "Code" vs "Referral code"). Every page now renders this
 * partial, so the widget has one label, one code line and one copy button.
 *
 * @var string $link The absolute registration URL, already built.
 * @var string $code The member's referral code; an empty value renders an em dash.
 * @var string $id   Input id, unique on the page.
 * @var string $note Optional plain-text line after the code.
 */

$link = (string) ($link ?? '');
$code = (string) ($code ?? '');
$id   = (string) ($id ?? 'referralLink');
$note = (string) ($note ?? '');
?>
<label class="form-label small text-body-secondary mb-1" for="<?= e($id) ?>">Your referral link</label>
<div class="referral-box">
  <input
    type="text"
    class="form-control referral-link-input"
    id="<?= e($id) ?>"
    value="<?= e($link) ?>"
    readonly
    onfocus="this.select()"
  />
  <button
    type="button"
    class="btn btn-icon btn-text-secondary"
    data-copy="<?= e($link) ?>"
    data-copy-tooltip="Copy link"
    data-bs-toggle="tooltip"
    title="Copy link"
    aria-label="Copy referral link"
  ><i class="icon-base bx bx-copy"></i></button>
</div>
<div class="small text-body-secondary mt-2">
  Referral code <strong><?= e($code !== '' ? $code : '—') ?></strong><?php if ($note !== ''): ?> · <?= e($note) ?><?php endif; ?>
</div>
