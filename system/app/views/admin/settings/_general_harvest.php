<?php
declare(strict_types=1);

/**
 * Admin settings — the General Harvest bonus sub-block (Phase 21B, plan §6).
 *
 * ONE CARD, ONE FORM, ONE SAVE (Rule 12). This partial is rendered inside
 * the Commissions card, after the Team Harvest sub-block, so its switch, its
 * depth and its ten amounts post with the hidden `section=commissions` field
 * and save through AdminSettingsController::save().
 *
 * THREE REGIONS, THREE JOBS:
 *
 *   • The **editor** — the switch, the payable depth and the ten amount
 *     inputs, one grid cell each, so on a phone they stack (`col-12`)
 *     instead of forcing the page sideways. Below each input is its live
 *     cumulative, which app-general-harvest.js recomputes as the operator
 *     types.
 *   • The **generated tables** — read-only, and nothing here is typed twice
 *     (Rules 7–8): the ladder's slots, the apex thresholds, the amounts and
 *     the cumulative all come from `general_harvest_ladder`, and the
 *     position table from `general_harvest_positions`, both computed by
 *     `GeneralHarvestLevel`. They sit in `.table-responsive`, so they scroll
 *     inside the card at 390 px and never move the page.
 *   • The **state strip and the calculator** (Rules 6, 9, 11) — the strip
 *     renders exactly one of four states and never claims the board is armed
 *     or paying when it is not; the calculator is labelled as an
 *     illustration and never claims money is owed.
 *
 * With JavaScript off the form still saves and still validates (Rule 12);
 * only the live cumulative, the live total and the calculator are missing.
 *
 * Arming is not reachable from this page (Rule 13): there is no field and no
 * button for it. While the board is armed the ten amounts are frozen (Rule
 * 14, D-23): they render disabled and the card says why.
 *
 * @var array<string, mixed> $settings
 * @var list<array<string, mixed>> $general_harvest_fields   the depth and the ten amounts
 * @var array<string, array{label:string, hint:string}> $general_harvest_toggles
 * @var array<int, string> $general_harvest_amounts          stored values, 0 as blank
 * @var array<string, mixed> $general_harvest_ladder         rows/total/max_levels/…
 * @var array{levels: list<int>, rows: list<array{position:int, seq:int, cells: array<int, int>}>} $general_harvest_positions
 * @var array<string, mixed> $general_harvest_board          status/queue/backlog/calculator
 * @var array<int, string> $general_harvest_preset           the reference ladder
 */

$settings    = $settings ?? [];
$ghFields    = $general_harvest_fields ?? [];
$ghToggles   = $general_harvest_toggles ?? [];
$ghAmounts   = $general_harvest_amounts ?? [];
$ghLadder    = $general_harvest_ladder ?? [];
$ghPositions = $general_harvest_positions ?? [];
$ghBoard     = $general_harvest_board ?? [];
$ghPreset    = $general_harvest_preset ?? [];

$value = static function (string $key) use ($settings): string {
    return old($key, (string) ($settings[$key] ?? ''));
};

$checked = static function (string $key) use ($settings): bool {
    return old($key, (string) ($settings[$key] ?? '0')) === '1';
};

$amountValue = static function (int $level) use ($ghAmounts): string {
    return old(
        GeneralHarvestLevel::AMOUNT_FIELD . '.' . $level,
        (string) ($ghAmounts[$level] ?? '')
    );
};

$knobFields = array_values(array_filter(
    $ghFields,
    static fn (array $field): bool => (string) ($field['group'] ?? '') === 'knobs'
));

/** @var array<int, array<string, mixed>> $amountFields keyed by level */
$amountFields = [];

foreach ($ghFields as $field) {
    if ((string) ($field['group'] ?? '') === 'amounts') {
        $amountFields[(int) ($field['level'] ?? 0)] = $field;
    }
}

$rows = is_array($ghLadder['rows'] ?? null) ? $ghLadder['rows'] : [];
$rows = array_values(array_filter($rows, static fn ($row): bool => is_array($row)));

$status      = is_array($ghBoard['status'] ?? null) ? $ghBoard['status'] : [];
$queue       = is_array($ghBoard['queue'] ?? null) ? $ghBoard['queue'] : [];
$backlog     = is_array($ghBoard['backlog'] ?? null) ? $ghBoard['backlog'] : [];
$calculator  = is_array($ghBoard['calculator'] ?? null) ? $ghBoard['calculator'] : [];

$total        = (float) ($ghLadder['total'] ?? 0);
$maxLevels    = (int) ($ghLadder['max_levels'] ?? GeneralHarvestLevel::maxLevels());
$depthTotal   = (float) ($ghLadder['depth_total'] ?? 0);
$apexN        = (int) ($ghLadder['apex_n'] ?? GeneralHarvestLevel::apexThreshold(GeneralHarvestLevel::LEVEL_MAX));
$node2N       = (int) ($ghLadder['node2_n'] ?? GeneralHarvestLevel::threshold(1, GeneralHarvestLevel::LEVEL_MAX));
$node3N       = (int) ($ghLadder['node3_n'] ?? GeneralHarvestLevel::threshold(2, GeneralHarvestLevel::LEVEL_MAX));
$referenceTotal = (float) ($ghLadder['reference_total'] ?? GeneralHarvestLevel::total(GeneralHarvestLevel::referencePreset()));
$overCeiling  = $total > GeneralHarvestLevel::MAX_TOTAL;

$armed       = (bool) ($status['armed'] ?? false);
$enabled     = (bool) ($status['enabled'] ?? GeneralHarvestLevel::enabled());
$state       = (string) ($status['state'] ?? GeneralHarvestBoard::STATE_UNARMED);
$n           = (int) ($queue['n'] ?? $status['n'] ?? 0);
$maxId       = (int) ($queue['max_id'] ?? 0);
$gapless     = (bool) ($queue['gapless'] ?? true);
$baselineN   = $status['baseline_n'] ?? null;
$armedAt     = (string) ($status['armed_at'] ?? '');
$settledN    = (int) ($status['settled_n'] ?? 0);
$backlogN    = (int) ($backlog['positions'] ?? ($status['backlog'] ?? 0));
$backlogCash = (float) ($backlog['amount'] ?? 0);

$calcN     = (int) ($calculator['n'] ?? $n);
$calcTotal = (float) ($calculator['total'] ?? 0);
$calcAwards = (int) ($calculator['awards'] ?? 0);

$positionLevels = is_array($ghPositions['levels'] ?? null) ? $ghPositions['levels'] : [1, 2, 3, 4];
$positionRows   = is_array($ghPositions['rows'] ?? null) ? $ghPositions['rows'] : [];

/* The four states, in plan Rule 9 order. Each one names the single next
 * action; none may claim the board is paying while it is unarmed or off. */
[$statusTone, $statusIcon, $statusTitle, $statusBody] = match ($state) {
    GeneralHarvestBoard::STATE_ARMED_OFF => [
        'secondary',
        'bx-pause-circle',
        'Armed and switched off.',
        sprintf(
            'The baseline of %s positions was recorded on %s. Positions are still being recorded; turn the switch on to start paying.',
            number_format((int) $baselineN),
            e($armedAt)
        ),
    ],
    GeneralHarvestBoard::STATE_ARMED_ON => [
        'success',
        'bx-check-shield',
        'Armed, switch on — nothing is waiting.',
        sprintf(
            'The baseline of %s positions was recorded on %s, the board is settled to %s and the queue is at %s.',
            number_format((int) $baselineN),
            e($armedAt),
            number_format($settledN),
            number_format($n)
        ),
    ],
    GeneralHarvestBoard::STATE_UNSETTLED => [
        'info',
        'bx-time-five',
        'Armed, switch on — positions are waiting to be settled.',
        sprintf(
            'The queue is at N = %s; the board is settled to %s. Run <code>bin/general_harvest_catchup.php</code> to settle %s %s (%s).',
            number_format($n),
            number_format($settledN),
            number_format($backlogN),
            $backlogN === 1 ? 'position' : 'positions',
            money($backlogCash)
        ),
    ],
    default => [
        'warning',
        'bx-error-circle',
        'Not armed — no baseline has been recorded.',
        'Run <code>bin/general_harvest_arm.php</code> before enabling; nothing can pay until the board has a baseline.',
    ],
};
?>
<hr class="my-5" />

<div data-general-harvest-card>
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
    <h6 class="mb-0">General Harvest bonus</h6>
    <span class="badge bg-label-primary">One board · levels 1–10</span>
  </div>
  <p class="text-body-secondary small mb-4">
    One board for the whole network. Every qualified member holds a <strong>node</strong> — their
    position in the Harvest queue minus one. Node <code>q</code>'s level 1 is the 3 positions after
    it, level 2 the next 9, level 3 the next 27, and so on; a level pays its amount once, when its
    block is full. A slot is filled by whoever qualified next — a direct member, an indirect one or a
    complete stranger's downline — so this bonus never depends on purchases, rank or the month, only
    on how far the queue has grown. The board ships <strong>unarmed</strong>: the amounts below are
    what a completed block pays, not a promise that anything is paying yet.
  </p>

  <div class="alert alert-<?= e($statusTone) ?> d-flex align-items-start gap-2" role="status"
       data-general-harvest-status data-general-harvest-state="<?= e($state) ?>">
    <i class="icon-base bx <?= e($statusIcon) ?> fs-5"></i>
    <div>
      <div class="fw-medium"><?= $statusTitle ?></div>
      <div class="small"><?= $statusBody ?></div>
      <div class="small mt-1">
        The queue is at <strong data-general-harvest-queue-n><?= number_format($n) ?></strong> qualified
        member<?= $n === 1 ? '' : 's' ?>.
<?php if (!$gapless): ?>
        <span class="text-danger">
          The queue is not gapless (count <?= number_format($n) ?>, highest position
          <?= number_format($maxId) ?>): the board arithmetic needs consecutive positions and the
          settlement will refuse to run until this is repaired.
        </span>
<?php endif; ?>
      </div>
    </div>
  </div>

<?php if ($armed): ?>
  <div class="alert alert-warning d-flex align-items-start gap-2" role="alert" data-general-harvest-frozen>
    <i class="icon-base bx bx-lock-alt fs-5"></i>
    <div class="small">
      The board is armed: <strong>the ten amounts are frozen</strong>. A business reset is the only way
      to change them, and it is safe only while nothing has been paid. The payable depth may still
      change, and the amounts it exposes are the ones below.
    </div>
  </div>
<?php endif; ?>

  <div class="row g-4">
<?php foreach ($ghToggles as $key => $toggle): ?>
<?php $key = (string) $key; ?>
    <div class="col-12">
      <div class="form-check form-switch">
        <input class="form-check-input <?= invalid_class($key) ?>" type="checkbox"
               role="switch" name="<?= e($key) ?>" value="1" id="setting-<?= e($key) ?>"
               <?= $checked($key) ? 'checked' : '' ?> />
        <label class="form-check-label" for="setting-<?= e($key) ?>">
          <?= e((string) $toggle['label']) ?>
        </label>
      </div>
      <p class="text-body-secondary small mb-0 ms-5 ps-2"><?= e((string) $toggle['hint']) ?></p>
<?php if (has_error($key)): ?>
      <div class="text-danger small mt-1" data-general-harvest-switch-error><?= e(error_for($key)) ?></div>
<?php endif; ?>
    </div>
<?php endforeach; ?>
<?php if (has_error(GeneralHarvestLevel::ARMED_KEY)): ?>
    <div class="col-12">
      <div class="alert alert-danger py-2 mb-0" role="alert" data-general-harvest-armed-error>
        <?= e(error_for(GeneralHarvestLevel::ARMED_KEY)) ?>
      </div>
    </div>
<?php endif; ?>
  </div>

  <div class="row g-6 mt-1">
<?php foreach ($knobFields as $field): ?>
<?php
    $key  = (string) $field['key'];
    $min  = $field['min'] ?? null;
    $max  = $field['max'] ?? null;
    $step = $field['step'] ?? null;
?>
    <div class="col-12 col-md-6">
      <label class="form-label" for="setting-<?= e($key) ?>"><?= e((string) $field['label']) ?></label>
      <div class="input-group">
        <input type="number"
               <?= $min !== null ? 'min="' . e((string) $min) . '" ' : '' ?><?= $max !== null ? 'max="' . e((string) $max) . '" ' : '' ?><?= $step !== null ? 'step="' . e((string) $step) . '" ' : '' ?>
               name="<?= e($key) ?>" id="setting-<?= e($key) ?>"
               class="form-control <?= invalid_class($key) ?>" value="<?= $value($key) ?>" />
        <span class="input-group-text"><?= e((string) ($field['suffix'] ?? '')) ?></span>
      </div>
<?php if (has_error($key)): ?>
      <div class="invalid-feedback d-block"><?= e(error_for($key)) ?></div>
<?php else: ?>
      <div class="form-text"><?= e((string) ($field['hint'] ?? '')) ?></div>
<?php endif; ?>
    </div>
<?php endforeach; ?>
  </div>

  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-5 mb-2">
    <h6 class="mb-0">The ten amounts</h6>
    <span class="small text-body-secondary">
      Blank and 0 mean the same: the level completes and pays nothing.
    </span>
  </div>

  <div class="row g-4">
<?php foreach ($rows as $row): ?>
<?php
    $level    = (int) ($row['level'] ?? 0);
    $fieldKey = GeneralHarvestLevel::AMOUNT_FIELD . '.' . $level;
    $paying   = (bool) ($row['paying'] ?? true);
    $hasCoin  = (bool) ($row['has_amount'] ?? false);
?>
    <div class="col-12 col-md-6" data-general-harvest-level="<?= $level ?>">
      <label class="form-label d-flex flex-wrap align-items-center gap-1"
             for="setting-general_harvest_amounts-<?= $level ?>">
        Level <?= $level ?>
        <span class="badge bg-label-secondary"><?= number_format((int) ($row['slots'] ?? 0)) ?> slots</span>
<?php if (!$paying): ?>
        <span class="badge bg-label-warning" data-general-harvest-paying="0">Not paying at this depth</span>
<?php elseif (!$hasCoin): ?>
        <span class="badge bg-label-danger" data-general-harvest-unpriced="1">No amount</span>
<?php else: ?>
        <span class="badge bg-label-success" data-general-harvest-paying="1">Pays at this depth</span>
<?php endif; ?>
      </label>
      <div class="input-group">
        <input type="number" min="0" max="<?= e(number_format(GeneralHarvestLevel::MAX_AMOUNT, 2, '.', '')) ?>" step="0.01"
               name="<?= e(GeneralHarvestLevel::AMOUNT_FIELD) ?>[<?= $level ?>]"
               id="setting-general_harvest_amounts-<?= $level ?>"
               class="form-control <?= invalid_class($fieldKey) ?>"
               value="<?= $amountValue($level) ?>"
               data-general-harvest-amount="<?= $level ?>"
               data-reference="<?= e((string) ($ghPreset[$level] ?? '')) ?>"
               <?= $armed ? 'disabled' : '' ?>
               aria-label="Level <?= $level ?> amount" />
        <span class="input-group-text"><?= e(CURRENCY_SYMBOL) ?></span>
      </div>
<?php if (has_error($fieldKey)): ?>
      <div class="invalid-feedback d-block"><?= e(error_for($fieldKey)) ?></div>
<?php else: ?>
      <div class="form-text">
        Apex needs N = <?= number_format((int) ($row['apex'] ?? 0)) ?> ·
        cumulative after this level
        <span class="fw-medium" data-general-harvest-cumulative="<?= $level ?>"><?= e(money((float) ($row['cumulative'] ?? 0))) ?></span>
      </div>
<?php endif; ?>
    </div>
<?php endforeach; ?>
  </div>

  <div class="d-flex flex-wrap align-items-center gap-2 mt-4">
    <span class="badge bg-label-<?= $overCeiling ? 'danger' : 'success' ?>" data-general-harvest-total data-general-harvest-total-badge><?= e(money($total)) ?></span>
    <span class="small text-body-secondary">full ladder · ceiling <?= e(money(GeneralHarvestLevel::MAX_TOTAL)) ?></span>
<?php if (!$armed): ?>
    <span class="ms-auto d-flex flex-wrap gap-2">
      <button type="button" class="btn btn-sm btn-label-primary" data-general-harvest-preset>
        <i class="icon-base bx bx-magic-wand me-1"></i> Load reference ladder
      </button>
      <button type="button" class="btn btn-sm btn-label-secondary" data-general-harvest-clear>
        <i class="icon-base bx bx-eraser me-1"></i> Clear
      </button>
    </span>
<?php endif; ?>
  </div>
  <p class="text-body-secondary small mb-0 mt-2" data-general-harvest-reference>
    Reference ladder: <?= e(money($referenceTotal)) ?> in total across the ten levels — the values 21A seeded.
<?php if (!$armed): ?>
    Loading it fills the inputs; nothing is stored until you save the card.
<?php endif; ?>
  </p>
<?php if (has_error(GeneralHarvestLevel::SUM_KEY)): ?>
  <div class="alert alert-danger py-2 mt-3" role="alert" data-general-harvest-sum-error><?= e(error_for(GeneralHarvestLevel::SUM_KEY)) ?></div>
<?php endif; ?>

  <p class="small mb-0 mt-3" data-general-harvest-sentence>
    <i class="icon-base bx bx-info-circle me-1"></i>
    The full ladder pays <strong data-general-harvest-total><?= e(money($total)) ?></strong> to
    <strong>node #1</strong> over ten levels and needs
    <strong data-general-harvest-apex-n><?= number_format($apexN) ?></strong> qualified members in the
    queue; node #2 needs <strong><?= number_format($node2N) ?></strong> and node #3 needs
    <strong><?= number_format($node3N) ?></strong>. At the configured depth of
    <strong data-general-harvest-depth><?= $maxLevels ?></strong> level<?= $maxLevels === 1 ? '' : 's' ?>
    this ladder pays <strong data-general-harvest-depth-total><?= e(money($depthTotal)) ?></strong> per node.
  </p>
  <p class="text-body-secondary small mb-0 mt-2">
    Slots, apex thresholds and the cumulative column are generated from a level's place on the board —
    they are structural, not settings. A block pays all or nothing: 26 of 27 positions pays ₱0.00, and
    the 27th pays the level's amount once. The amounts are per level, not per rank: every node is paid
    the same ladder. Nothing on this card writes an award, a wallet movement or a position.
  </p>

  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-5 mb-2">
    <h6 class="mb-0">Saved ladder</h6>
    <span class="small text-body-secondary">What is stored now; the inputs above are what the next save writes.</span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0" data-general-harvest-table>
      <thead>
        <tr>
          <th scope="col">Level</th>
          <th scope="col" class="text-end">Slots</th>
          <th scope="col" class="text-end">Apex needs N</th>
          <th scope="col" class="text-end">Stored amount</th>
          <th scope="col" class="text-end">Cumulative</th>
          <th scope="col">Depth</th>
        </tr>
      </thead>
      <tbody>
<?php foreach ($rows as $row): ?>
<?php
    $level  = (int) ($row['level'] ?? 0);
    $paying = (bool) ($row['paying'] ?? true);
?>
        <tr data-general-harvest-row="<?= $level ?>" data-general-harvest-paying="<?= $paying ? '1' : '0' ?>">
          <td class="fw-medium text-nowrap">Level <?= $level ?></td>
          <td class="text-end" data-general-harvest-slots="<?= $level ?>"><?= number_format((int) ($row['slots'] ?? 0)) ?></td>
          <td class="text-end" data-general-harvest-apex="<?= $level ?>"><?= number_format((int) ($row['apex'] ?? 0)) ?></td>
          <td class="text-end text-nowrap" data-general-harvest-stored="<?= $level ?>"><?= e(money((float) ($row['amount'] ?? 0))) ?></td>
          <td class="text-end text-nowrap" data-general-harvest-stored-cumulative="<?= $level ?>"><?= e(money((float) ($row['cumulative'] ?? 0))) ?></td>
          <td class="text-nowrap">
<?php if ($paying): ?>
            <span class="badge bg-label-success">Paid</span>
<?php else: ?>
            <span class="badge bg-label-secondary">Not paying at this depth</span>
<?php endif; ?>
          </td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="text-body-secondary small mt-2 mb-0">
    Levels above the configured depth complete on the board and are marked
    <strong>not paying at this depth</strong>; their amounts are kept, and raising the depth pays them
    from the current queue, once. The ten amounts must total no more than
    <?= e(money(GeneralHarvestLevel::MAX_TOTAL)) ?>.
  </p>

  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-5 mb-2">
    <h6 class="mb-0">Queue size each position needs</h6>
    <span class="small text-body-secondary">Generated from the block formula — not settings.</span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0" data-general-harvest-positions>
      <thead>
        <tr>
          <th scope="col">Position</th>
<?php foreach ($positionLevels as $level): ?>
          <th scope="col" class="text-end">Level <?= (int) $level ?></th>
<?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
<?php foreach ($positionRows as $row): ?>
<?php $position = (int) ($row['position'] ?? 0); ?>
        <tr data-general-harvest-position="<?= $position ?>">
          <td class="fw-medium text-nowrap">#<?= $position ?><span class="text-body-secondary small"> (node <?= (int) ($row['seq'] ?? 0) ?>)</span></td>
<?php foreach ($positionLevels as $level): ?>
          <td class="text-end" data-general-harvest-position-cell="<?= $position ?>:<?= (int) $level ?>"><?= number_format((int) (($row['cells'][(int) $level] ?? 0))) ?></td>
<?php endforeach; ?>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="text-body-secondary small mt-2 mb-0">
    Position #4's thresholds are the apex shifted one level (its level 1 is the apex's level 2),
    because node 3 is the apex's last child. Later positions move the whole staircase to the right.
  </p>

  <div class="mt-5" data-general-harvest-calculator>
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
      <h6 class="mb-0">If the queue reaches N</h6>
      <span class="badge bg-label-secondary">Illustration</span>
    </div>
    <div class="row g-4 align-items-end">
      <div class="col-12 col-md-4">
        <label class="form-label" for="general-harvest-calculator">Qualified members in the queue</label>
        <div class="input-group">
          <input type="number" min="0" step="1" id="general-harvest-calculator"
                 class="form-control" value="<?= $calcN ?>"
                 data-general-harvest-calculator-input
                 aria-label="Queue size for the illustration" />
          <span class="input-group-text">members</span>
        </div>
      </div>
      <div class="col-12 col-md-8">
        <ul class="list-unstyled small mb-0">
          <li>
            The whole board would have paid
            <strong data-general-harvest-calculator-total><?= e(money($calcTotal)) ?></strong>
            in <strong data-general-harvest-calculator-awards><?= number_format($calcAwards) ?></strong>
            awards.
          </li>
          <li class="text-body-secondary">
            This is an illustration, not a liability: the queue is at
            <?= number_format($n) ?> today, no award has been written, and nothing can pay until the
            board is armed (21C) and settled (21D). The figures follow the stored ladder above.
          </li>
        </ul>
      </div>
    </div>
  </div>
</div>
