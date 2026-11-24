<?php
declare(strict_types=1);

/**
 * Admin settings — the Team Harvest bonus sub-block (Phase 20B, plan §6).
 *
 * ONE CARD, ONE FORM, ONE SAVE (Rule 11). This partial is rendered inside
 * the Commissions card, after the Royalty sub-block, so its switch, two
 * numbers and ten amounts post with the hidden `section=commissions` field
 * and save through AdminSettingsController::save().
 *
 * TWO REGIONS, TWO JOBS:
 *
 *   • The **editor** — the ten amount inputs, one grid cell each, so on a
 *     phone they stack (`col-12`) instead of forcing the page sideways.
 *     Below each input is its live cumulative, which
 *     app-team-harvest.js recomputes as the operator types.
 *   • The **generated ladder table** — read-only, and nothing here is typed
 *     twice (Rule 8): the slots, the teammates needed, the stored amount
 *     and the cumulative all come from `team_harvest_ladder`, which
 *     `TeamHarvestLevel` computes. It sits in `.table-responsive`, so the
 *     six columns scroll inside the card at 390 px and never move the page.
 *
 * With JavaScript off the form still saves and still validates (Rule 12);
 * only the live cumulative and the live total are missing.
 *
 * The four-state strip (Rules 6–7) is rendered from `team_harvest_status`;
 * it never claims the stream is live when it is unseeded or off.
 *
 * @var array<string, mixed> $settings
 * @var list<array<string, mixed>> $team_harvest_fields   the two knobs and the ten amounts
 * @var array<string, array{label:string, hint:string}> $team_harvest_toggles
 * @var array<int, string> $team_harvest_amounts          stored values, 0 as blank
 * @var array<string, mixed> $team_harvest_ladder         rows/total/max_levels/…
 * @var array<string, mixed> $team_harvest_status         state/seeded/queue/…
 * @var array<int, string> $team_harvest_preset           the reference ladder
 */

$settings     = $settings ?? [];
$teamFields   = $team_harvest_fields ?? [];
$teamToggles  = $team_harvest_toggles ?? [];
$teamAmounts  = $team_harvest_amounts ?? [];
$teamLadder   = $team_harvest_ladder ?? [];
$teamStatus   = $team_harvest_status ?? [];
$teamPreset   = $team_harvest_preset ?? [];

$value = static function (string $key) use ($settings): string {
    return old($key, (string) ($settings[$key] ?? ''));
};

$checked = static function (string $key) use ($settings): bool {
    return old($key, (string) ($settings[$key] ?? '0')) === '1';
};

$amountValue = static function (int $level) use ($teamAmounts): string {
    return old(
        TeamHarvestLevel::AMOUNT_FIELD . '.' . $level,
        (string) ($teamAmounts[$level] ?? '')
    );
};

$knobFields = array_values(array_filter(
    $teamFields,
    static fn (array $field): bool => (string) ($field['group'] ?? '') === 'knobs'
));

/** @var array<int, array<string, mixed>> $amountFields keyed by level */
$amountFields = [];

foreach ($teamFields as $field) {
    if ((string) ($field['group'] ?? '') === 'amounts') {
        $amountFields[(int) ($field['level'] ?? 0)] = $field;
    }
}

$rows = is_array($teamLadder['rows'] ?? null) ? $teamLadder['rows'] : [];
$rows = array_values(array_filter($rows, static fn ($row): bool => is_array($row)));

$total        = (float) ($teamLadder['total'] ?? 0);
$maxThreshold = (int) ($teamLadder['max_threshold'] ?? TeamHarvestLevel::threshold(TeamHarvestLevel::LEVEL_MAX));
$overCeiling  = $total > TeamHarvestLevel::MAX_TOTAL;

$state   = (string) ($teamStatus['state'] ?? 'unseeded');
$queue   = (int) ($teamStatus['queue'] ?? 0);
$since   = (string) ($teamStatus['seeded_at'] ?? '');
$owed    = (float) ($teamStatus['open_liability'] ?? 0.0);
$waiting = (int) ($teamStatus['pending_levels'] ?? 0);

/* The four states, in plan Rule 7 order. Each one names the single next
 * action; none may claim the stream is paying while it is unseeded or off. */
[$statusTone, $statusIcon, $statusTitle, $statusBody] = match ($state) {
    'seeded_off' => [
        'secondary',
        'bx-pause-circle',
        'Seeded and switched off.',
        sprintf(
            '%s positions recorded since %s. Positions are still being recorded; turn the switch on to start paying.',
            number_format($queue),
            e($since)
        ),
    ],
    'seeded_on' => [
        'success',
        'bx-check-shield',
        'Seeded, switch on — nothing is waiting.',
        sprintf(
            '%s positions recorded since %s and every completed level has been evaluated.',
            number_format($queue),
            e($since)
        ),
    ],
    'backlog' => [
        'info',
        'bx-time-five',
        'Seeded, switch on — levels are waiting to be evaluated.',
        sprintf(
            '%s %s complete since the baseline and not paid yet (%s). Run <code>bin/team_harvest_catchup.php</code> to settle the backlog.',
            number_format($waiting),
            $waiting === 1 ? 'level is' : 'levels are',
            money($owed)
        ),
    ],
    default => [
        'warning',
        'bx-error-circle',
        'Not seeded — no position has been assigned.',
        'Run <code>bin/harvest_queue_seed.php</code> before enabling; nothing can pay until the queue exists.',
    ],
};
?>
<hr class="my-5" />

                      <div data-team-harvest-card>
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                        <h6 class="mb-0">Team Harvest bonus</h6>
                        <span class="badge bg-label-primary">Levels 1–10 · 3×10 matrix</span>
                      </div>
                      <p class="text-body-secondary small mb-4">
                        Every qualified member holds a private <strong>3×10 matrix</strong>. Level 1 fills with 3
                        qualified teammates, level 2 with 9 more, level 3 with 27 more, and so on; a level pays its
                        amount once, when its block is full. This bonus never depends on purchases, rank or the
                        month — only on who qualified, and when. The direct-member gate and the payable depth are
                        the two numbers below; the ten amounts are the ladder. The stream ships
                        <strong>off</strong> and the ladder is <strong>unseeded</strong> until the seed job runs.
                      </p>

                        <div class="alert alert-<?= e($statusTone) ?> d-flex align-items-start gap-2" role="status"
                             data-team-harvest-status data-team-harvest-state="<?= e($state) ?>">
                          <i class="icon-base bx <?= e($statusIcon) ?> fs-5"></i>
                          <div>
                            <div class="fw-medium"><?= $statusTitle ?></div>
                            <div class="small"><?= $statusBody ?></div>
                          </div>
                        </div>

                        <div class="row g-4">
<?php foreach ($teamToggles as $key => $toggle): ?>
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
                            <div class="text-danger small mt-1"><?= e(error_for($key)) ?></div>
<?php endif; ?>
                          </div>
<?php endforeach; ?>
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

                        <?= View::capture('partials/ladder_toolbar', [
                            'ladder_kind'      => 'team',
                            'ladder_preset'    => $teamPreset,
                            'ladder_amounts'   => $teamAmounts,
                            'ladder_total'     => money(TeamHarvestLevel::total(TeamHarvestLevel::referencePreset())),
                            'ladder_note'      => 'the values 20A seeded',
                        ]) ?>

                        <div class="row g-4">
<?php foreach ($rows as $row): ?>
<?php
    $level    = (int) ($row['level'] ?? 0);
    $fieldKey = TeamHarvestLevel::AMOUNT_FIELD . '.' . $level;
    $paying   = (bool) ($row['paying'] ?? true);
?>
                          <div class="col-12 col-md-6" data-team-harvest-level="<?= $level ?>">
                            <label class="form-label d-flex flex-wrap align-items-center gap-1" for="setting-team_harvest_amounts-<?= $level ?>">
                              Level <?= $level ?>
                              <span class="badge bg-label-secondary"><?= number_format((int) ($row['slots'] ?? 0)) ?> slots</span>
<?php if (!$paying): ?>
                              <span class="badge bg-label-warning" data-team-harvest-paying="0">Not paid at this depth</span>
<?php else: ?>
                              <span class="badge bg-label-success" data-team-harvest-paying="1">Pays at this depth</span>
<?php endif; ?>
                            </label>
                            <div class="input-group">
                              <input type="number" min="0" max="<?= e(number_format(TeamHarvestLevel::MAX_AMOUNT, 2, '.', '')) ?>" step="0.01"
                                     name="<?= e(TeamHarvestLevel::AMOUNT_FIELD) ?>[<?= $level ?>]"
                                     id="setting-team_harvest_amounts-<?= $level ?>"
                                     class="form-control <?= invalid_class($fieldKey) ?>"
                                     value="<?= $amountValue($level) ?>"
                                     data-team-harvest-amount="<?= $level ?>"
                                     data-reference="<?= e((string) ($teamPreset[$level] ?? '')) ?>"
                                     data-team-harvest-saved="<?= e(number_format((float) ($row['amount'] ?? 0), 2, '.', '')) ?>"
                                     aria-label="Level <?= $level ?> amount" />
                              <span class="input-group-text"><?= e(CURRENCY_SYMBOL) ?></span>
                            </div>
<?php if (has_error($fieldKey)): ?>
                            <div class="invalid-feedback d-block"><?= e(error_for($fieldKey)) ?></div>
<?php else: ?>
                            <div class="form-text">
                              Fills at <?= number_format((int) ($row['threshold'] ?? 0)) ?> qualified teammates ·
                              cumulative after this level
                              <span class="fw-medium" data-team-harvest-cumulative="<?= $level ?>"><?= e(money((float) ($row['cumulative'] ?? 0))) ?></span>
                            </div>
<?php endif; ?>
                          </div>
<?php endforeach; ?>
                        </div>

                        <div class="d-flex flex-wrap align-items-center gap-2 mt-4">
                          <span class="badge bg-label-<?= $overCeiling ? 'danger' : 'success' ?>" data-team-harvest-total data-team-harvest-total-badge><?= e(money($total)) ?></span>
                          <span class="small text-body-secondary">full ladder · ceiling <?= e(money(TeamHarvestLevel::MAX_TOTAL)) ?></span>
                        </div>
<?php if (has_error(TeamHarvestLevel::SUM_KEY)): ?>
                        <div class="alert alert-danger py-2 mt-3" role="alert"><?= e(error_for(TeamHarvestLevel::SUM_KEY)) ?></div>
<?php endif; ?>

                        <p class="small mb-0 mt-3" data-team-harvest-sentence>
                          <i class="icon-base bx bx-info-circle me-1"></i>
                          The full ladder pays <strong data-team-harvest-total><?= e(money($total)) ?></strong> to one
                          member who gathers <strong><?= number_format($maxThreshold) ?></strong> qualified
                          teammates — this stream's per-member maximum.
                        </p>
                        <p class="text-body-secondary small mb-0 mt-2">
                          Changing an amount affects only levels that have not paid yet; every award keeps the amount
                          it was paid at. Slots, teammates needed and the cumulative column are generated from the
                          level's place in the matrix — they are structural, not settings. A level left at 0 still
                          completes and pays nothing, and can be paid once if you raise it later. Saving never moves
                          a member's position, and no award is written by this card.
                        </p>

                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-5 mb-2">
                          <h6 class="mb-0">Saved ladder</h6>
                          <span class="small text-body-secondary">What is stored now; the inputs above are what the next save writes.</span>
                        </div>
                        <div class="table-responsive">
                          <table class="table table-sm align-middle mb-0" data-team-harvest-table>
                            <thead>
                              <tr>
                                <th scope="col">Level</th>
                                <th scope="col" class="text-end">Slots</th>
                                <th scope="col" class="text-end">Teammates needed</th>
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
                              <tr data-team-harvest-row="<?= $level ?>" data-team-harvest-paying="<?= $paying ? '1' : '0' ?>">
                                <td class="fw-medium text-nowrap">Level <?= $level ?></td>
                                <td class="text-end" data-team-harvest-slots="<?= $level ?>"><?= number_format((int) ($row['slots'] ?? 0)) ?></td>
                                <td class="text-end" data-team-harvest-threshold="<?= $level ?>"><?= number_format((int) ($row['threshold'] ?? 0)) ?></td>
                                <td class="text-end text-nowrap" data-team-harvest-stored="<?= $level ?>"><?= e(money((float) ($row['amount'] ?? 0))) ?></td>
                                <td class="text-end text-nowrap" data-team-harvest-stored-cumulative="<?= $level ?>"><?= e(money((float) ($row['cumulative'] ?? 0))) ?></td>
                                <td class="text-nowrap">
<?php if ($paying): ?>
                                  <span class="badge bg-label-success">Paid</span>
<?php else: ?>
                                  <span class="badge bg-label-secondary">Not paid</span>
<?php endif; ?>
                                </td>
                              </tr>
<?php endforeach; ?>
                            </tbody>
                          </table>
                        </div>
                        <p class="text-body-secondary small mt-2 mb-0">
                          Levels above the configured depth complete on the board and are marked
                          <strong>not paid</strong>; their amounts are kept, and raising the depth pays them at the
                          next evaluation. The ten amounts must total no more than
                          <?= e(money(TeamHarvestLevel::MAX_TOTAL)) ?>.
                        </p>
                      </div>
