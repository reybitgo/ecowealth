<?php
declare(strict_types=1);

/**
 * Admin — the shared reference-ladder toolbar.
 *
 * One panel, three ladders: the Team Harvest and General Harvest cards on
 * Settings → Commissions, and the repeat-purchase ladder on the product form.
 * Each one offers the same action — fill the ten level inputs with the
 * reference ladder that shipped with the stream — and each one used to hide
 * it in a small button with the preset's total in a paragraph beside it and
 * no confirmation afterwards.
 *
 * The panel answers the three questions an operator has before pressing it:
 *
 *   • What will it load?     the preset total is part of the panel's copy,
 *                            not a number to hunt for under the grid.
 *   • Is it already loaded?  a badge renders "Matches reference" / "Custom
 *                            ladder" / "Empty ladder" from the stored values
 *                            server-side, and app-ladder-toolbar.js switches
 *                            it to "Unsaved changes" once the inputs drift
 *                            from those stored values.
 *   • Can I take it back?    the script prints an aria-live confirmation with
 *                            a one-click Undo, so neither fill nor clear can
 *                            lose typed work.
 *
 * The buttons fill inputs and nothing else: the form still stores nothing
 * until it is saved, and with JavaScript off they are inert `type="button"`
 * controls while the form keeps saving and validating exactly as before.
 *
 * The wiring is carried by data attributes (`data-ladder-card`,
 * `data-ladder-field`, `data-ladder-saved`, `data-ladder-unit`) so one script
 * serves a money ladder with centavos and a rate ladder with three decimals.
 *
 * @var string            $ladder_kind      'team', 'general' or 'unilevel'
 * @var array<int,mixed>  $ladder_preset    the ten reference values, by level
 * @var array<int,mixed>  $ladder_amounts   the ten stored values, by level
 * @var string            $ladder_total     the preset's full-ladder total, formatted
 * @var string            $ladder_note      what the preset is ('the values 20A seeded')
 * @var string            $ladder_save_word the noun the save applies to ('card', 'product')
 * @var bool              $ladder_locked    the ladder is frozen; no fill/clear offered
 */

$kind     = (string) ($ladder_kind ?? 'team');
$preset   = is_array($ladder_preset ?? null) ? $ladder_preset : [];
$amounts  = is_array($ladder_amounts ?? null) ? $ladder_amounts : [];
$total    = (string) ($ladder_total ?? '');
$note     = (string) ($ladder_note ?? '');
$saveWord = (string) ($ladder_save_word ?? 'card');
$locked   = (bool) ($ladder_locked ?? false);

$shape = match ($kind) {
    'general' => [
        'prefix'   => 'general-harvest',
        'card'     => '[data-general-harvest-card]',
        'field'    => '[data-general-harvest-amount]',
        'saved'    => 'data-general-harvest-saved',
        'unit'     => 'money',
        'lockNote' => 'Frozen while the board is armed',
    ],
    'unilevel' => [
        'prefix'   => 'unilevel',
        'card'     => '[data-unilevel-card]',
        'field'    => '[data-unilevel-rate]',
        'saved'    => 'data-unilevel-saved',
        'unit'     => 'percent',
        'lockNote' => 'Locked',
    ],
    default => [
        'prefix'   => 'team-harvest',
        'card'     => '[data-team-harvest-card]',
        'field'    => '[data-team-harvest-amount]',
        'saved'    => 'data-team-harvest-saved',
        'unit'     => 'money',
        'lockNote' => 'Frozen while the board is armed',
    ],
};

$prefix = $shape['prefix'];
$scale  = $shape['unit'] === 'percent' ? 1000 : 100;

/* Server-rendered state, so the badge is right on the first paint and with
 * JavaScript off. Compared at the ladder's own precision — a stored "1000"
 * and a preset "1000.00" are the same amount, and 2.5% is not 2.5005%. */
$matches = true;
$empty   = true;

for ($level = 1; $level <= 10; $level++) {
    $value = (int) round((float) ($amounts[$level] ?? 0) * $scale);

    if ($value !== (int) round((float) ($preset[$level] ?? 0) * $scale)) {
        $matches = false;
    }

    if ($value !== 0) {
        $empty = false;
    }
}

$stateText = $matches ? 'Matches reference' : ($empty ? 'Empty ladder' : 'Custom ladder');
$stateTone = $matches ? 'success' : 'secondary';

$helpId = $prefix . '-reference-help';
?>
<div class="border rounded-3 p-3 p-md-4 mb-4"
     data-ladder-toolbar
     data-ladder-unit="<?= e($shape['unit']) ?>"
     data-ladder-card="<?= e($shape['card']) ?>"
     data-ladder-field="<?= e($shape['field']) ?>"
     data-ladder-saved="<?= e($shape['saved']) ?>"
     data-ladder-save-word="<?= e($saveWord) ?>"
     data-<?= $prefix ?>-toolbar>
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
    <div class="d-flex flex-wrap align-items-center gap-2">
      <span class="fw-semibold">Reference ladder</span>
      <span class="badge bg-label-<?= e($stateTone) ?>" data-ladder-state data-<?= $prefix ?>-state><?= e($stateText) ?></span>
    </div>
<?php if ($locked): ?>
    <span class="badge bg-label-secondary d-inline-flex align-items-center gap-1">
      <i class="bx bx-lock-alt"></i> <?= e($shape['lockNote']) ?>
    </span>
<?php else: ?>
    <div class="d-flex flex-wrap gap-2" role="group" aria-label="Reference ladder actions">
      <button type="button" class="btn btn-sm btn-label-primary" data-ladder-preset data-<?= $prefix ?>-preset aria-describedby="<?= e($helpId) ?>">
        <i class="icon-base bx bx-list-check me-1"></i> Load reference ladder
      </button>
      <button type="button" class="btn btn-sm btn-label-secondary" data-ladder-clear data-<?= $prefix ?>-clear aria-describedby="<?= e($helpId) ?>">
        <i class="icon-base bx bx-eraser me-1"></i> Clear ten amounts
      </button>
    </div>
<?php endif; ?>
  </div>
  <p class="text-body-secondary small mb-0 mt-2" id="<?= e($helpId) ?>" data-ladder-reference data-<?= $prefix ?>-reference>
    <?= e($total) ?> in total across the ten levels — <?= e($note) ?>.
<?php if (!$locked): ?>
    Loading it fills the inputs; nothing is stored until you save the <?= e($saveWord) ?>.
<?php endif; ?>
  </p>
  <div class="small mt-2 d-none" role="status" aria-live="polite" aria-atomic="true" data-ladder-feedback data-<?= $prefix ?>-feedback></div>
</div>
