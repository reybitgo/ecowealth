<?php
declare(strict_types=1);

/**
 * Admin settings — the compensation switches (Phase 9–11 keys, exposed
 * in Phase 15), the repeat-purchase sub-block (Phase 17B), the Royalty
 * ladder (Phase 19B) and the Team Harvest ladder (Phase 20B).
 *
 * All four blocks are one card and one form: the hidden `section` field
 * still says `commissions`, so the repeat-purchase, Royalty and Team
 * Harvest keys save, validate and audit exactly like the referral keys
 * beside them (plan §13.5, §6 19B rule 10, §6 20B rule 11).
 *
 * @var array<string, mixed> $settings
 * @var list<array{key:string, label:string, rule:string, suffix:string, hint:string}> $commission_fields
 * @var array<string, array{label:string, hint:string}> $commission_toggles
 * @var list<array<string, mixed>> $repeat_fields
 * @var array<string, array{label:string, hint:string}> $repeat_toggles
 * @var list<array<string, mixed>> $royalty_fields
 * @var array<string, array{label:string, hint:string}> $royalty_toggles
 * @var array<string, mixed> $royalty_ladder
 * @var list<array<string, mixed>> $team_harvest_fields
 * @var array<string, array{label:string, hint:string}> $team_harvest_toggles
 * @var array<int, string> $team_harvest_amounts
 * @var array<string, mixed> $team_harvest_ladder
 * @var array<string, mixed> $team_harvest_status
 * @var array<int, string> $team_harvest_preset
 */

$settings          = $settings ?? [];
$commissionFields  = $commission_fields ?? [];
$commissionToggles = $commission_toggles ?? [];
$repeatFields      = $repeat_fields ?? [];
$repeatToggles     = $repeat_toggles ?? [];
$royaltyFields     = $royalty_fields ?? [];
$royaltyToggles    = $royalty_toggles ?? [];
$royaltyLadder     = $royalty_ladder ?? [];
$teamFields        = $team_harvest_fields ?? [];
$teamToggles       = $team_harvest_toggles ?? [];
$teamAmounts       = $team_harvest_amounts ?? [];
$teamLadder        = $team_harvest_ladder ?? [];
$teamStatus        = $team_harvest_status ?? [];
$teamPreset        = $team_harvest_preset ?? [];

$value = static function (string $key) use ($settings): string {
    return old($key, (string) ($settings[$key] ?? ''));
};

$checked = static function (string $key) use ($settings): bool {
    return old($key, (string) ($settings[$key] ?? '0')) === '1';
};
?>
                    <div class="card-body" data-live-region="settings-commissions" data-live-hold="dirty">
                      <div class="row g-6">
<?php foreach ($commissionFields as $field): ?>
<?php $key = (string) $field['key']; ?>
                        <div class="col-md-6">
                          <label class="form-label" for="setting-<?= e($key) ?>"><?= e((string) $field['label']) ?></label>
                          <div class="input-group">
                            <input type="number" min="2" max="10" step="1" name="<?= e($key) ?>" id="setting-<?= e($key) ?>"
                                   class="form-control <?= invalid_class($key) ?>" value="<?= $value($key) ?>" />
                            <span class="input-group-text"><?= e((string) $field['suffix']) ?></span>
                          </div>
<?php if (has_error($key)): ?>
                          <div class="invalid-feedback d-block"><?= e(error_for($key)) ?></div>
<?php else: ?>
                          <div class="form-text"><?= e((string) $field['hint']) ?></div>
<?php endif; ?>
                        </div>
<?php endforeach; ?>
                      </div>

                      <hr class="my-5" />

                      <div class="row g-4">
<?php foreach ($commissionToggles as $key => $toggle): ?>
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

                      <hr class="my-5" />

                      <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                        <h6 class="mb-0">Repeat-purchase bonus</h6>
                        <span class="badge bg-label-primary">Levels 1–10, per product</span>
                      </div>
                      <p class="text-body-secondary small mb-4">
                        Earned on every <strong>paid shop order</strong> by the buyer's sponsor chain, at the ladder
                        each product carries. The award is held until delivery plus the release delay, then credited
                        to the upline's withdrawable wallet. Independent of the referral bonuses above: it has its
                        own master switch, release switch, Active rule and reversal rule. The per-product ladder is
                        edited on the product form; this block holds the values that apply to every award.
                        Cancelling an order is what cancels a pending award, and that action is off by default
                        (<strong>Shop → Enable Cancel of Order</strong>).
                      </p>

                      <div class="row g-6">
<?php foreach ($repeatFields as $field): ?>
<?php
    $key  = (string) $field['key'];
    $min  = $field['min'] ?? null;
    $max  = $field['max'] ?? null;
    $step = $field['step'] ?? null;
?>
                        <div class="col-md-6">
                          <label class="form-label" for="setting-<?= e($key) ?>"><?= e((string) $field['label']) ?></label>
                          <div class="input-group">
                            <input type="number"
                                   <?= $min !== null ? 'min="' . e((string) $min) . '" ' : '' ?><?= $max !== null ? 'max="' . e((string) $max) . '" ' : '' ?><?= $step !== null ? 'step="' . e((string) $step) . '" ' : '' ?>
                                   name="<?= e($key) ?>" id="setting-<?= e($key) ?>"
                                   class="form-control <?= invalid_class($key) ?>" value="<?= $value($key) ?>" />
                            <span class="input-group-text"><?= e((string) $field['suffix']) ?></span>
                          </div>
<?php if (has_error($key)): ?>
                          <div class="invalid-feedback d-block"><?= e(error_for($key)) ?></div>
<?php else: ?>
                          <div class="form-text"><?= e((string) $field['hint']) ?></div>
<?php endif; ?>
                        </div>
<?php endforeach; ?>
                      </div>

                      <div class="row g-4 mt-1">
<?php foreach ($repeatToggles as $key => $toggle): ?>
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

                      <hr class="my-5" />

                      <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                        <h6 class="mb-0">Royalty bonus</h6>
                        <span class="badge bg-label-primary">Monthly · 5 ranks</span>
                      </div>
                      <p class="text-body-secondary small mb-4">
                        A monthly override on the sponsor tree, paid once per closed calendar month to
                        <strong>Supervisor, Manager and Director</strong> only. Ranks are derived from the tree on every
                        evaluation and never stored: a Leader is 10 direct members, and each rank above it needs the
                        rank below plus 10 more rank holders anywhere in its downline. A member's own paid purchases
                        sit inside group sales and are paid the personal rate again, so they earn
                        <strong>group&nbsp;% + personal&nbsp;%</strong>. Missing the group quota by one centavo pays
                        nothing, including the personal percentage. The stream ships <strong>off</strong>.
                        Leader is not a paying rank.
                      </p>

                      <div class="row g-4">
<?php foreach ($royaltyToggles as $key => $toggle): ?>
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

<?php foreach (['requirements' => 'Rank requirements', 'quotas' => 'Monthly group quota', 'rates' => 'Payout rates'] as $groupKey => $groupLabel): ?>
<?php $groupFields = array_values(array_filter($royaltyFields, static fn (array $field): bool => (string) ($field['group'] ?? '') === $groupKey)); ?>
<?php if ($groupFields !== []): ?>
                      <h6 class="mt-5 mb-3"><?= e($groupLabel) ?></h6>
                      <div class="row g-6">
<?php foreach ($groupFields as $field): ?>
<?php
    $key  = (string) $field['key'];
    $min  = $field['min'] ?? null;
    $max  = $field['max'] ?? null;
    $step = $field['step'] ?? null;
?>
                        <div class="col-md-6">
                          <label class="form-label" for="setting-<?= e($key) ?>"><?= e((string) $field['label']) ?></label>
                          <div class="input-group">
                            <input type="number"
                                   <?= $min !== null ? 'min="' . e((string) $min) . '" ' : '' ?><?= $max !== null ? 'max="' . e((string) $max) . '" ' : '' ?><?= $step !== null ? 'step="' . e((string) $step) . '" ' : '' ?>
                                   name="<?= e($key) ?>" id="setting-<?= e($key) ?>"
                                   class="form-control <?= invalid_class($key) ?>" value="<?= $value($key) ?>" />
                            <span class="input-group-text"><?= e((string) $field['suffix']) ?></span>
                          </div>
<?php if (has_error($key)): ?>
                          <div class="invalid-feedback d-block"><?= e(error_for($key)) ?></div>
<?php else: ?>
                          <div class="form-text"><?= e((string) $field['hint']) ?></div>
<?php endif; ?>
                        </div>
<?php endforeach; ?>
                      </div>
<?php endif; ?>
<?php endforeach; ?>

<?php if ($royaltyLadder['ranks'] ?? []): ?>
                      <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-5 mb-2">
                        <h6 class="mb-0">Saved ladder</h6>
                        <span class="small text-body-secondary">What is stored now; the inputs above are what the next save writes.</span>
                      </div>
                      <div class="table-responsive" data-royalty-ladder>
                        <table class="table table-sm align-middle mb-0">
                          <thead>
                            <tr>
                              <th>Rank</th>
                              <th>Requirement</th>
                              <th class="text-end">Monthly group quota</th>
                              <th class="text-end">Group %</th>
                              <th class="text-end">Personal %</th>
                              <th class="text-end">Own purchase</th>
                            </tr>
                          </thead>
                          <tbody>
<?php foreach ($royaltyLadder['ranks'] as $row): ?>
<?php $tone = RoyaltyRank::LABELS[(int) $row['level']]['tone'] ?? 'secondary'; ?>
                            <tr data-royalty-rank="<?= e((string) $row['slug']) ?>">
                              <td><span class="badge bg-label-<?= e($tone) ?>"><?= e((string) $row['name']) ?></span></td>
                              <td class="small"><?= e((string) $row['requirement']) ?></td>
                              <td class="text-end"><?= $row['quota'] === null ? '<span class="text-body-secondary">—</span>' : e(money((float) $row['quota'])) ?></td>
                              <td class="text-end"><?= $row['group_pct'] === null ? '<span class="text-body-secondary">—</span>' : e(number_format((float) $row['group_pct'], 3, '.', ',') . '%') ?></td>
                              <td class="text-end"><?= $row['personal_pct'] === null ? '<span class="text-body-secondary">—</span>' : e(number_format((float) $row['personal_pct'], 3, '.', ',') . '%') ?></td>
                              <td class="text-end"><?= $row['own_pct'] === null ? '<span class="text-body-secondary">—</span>' : e(number_format((float) $row['own_pct'], 3, '.', ',') . '%') ?></td>
                            </tr>
<?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                      <p class="text-body-secondary small mt-2 mb-0">
                        Own purchase is group&nbsp;% + personal&nbsp;%, the effective rate on a paying member's own paid
                        purchases. Ranks are derived, not stored: a saved change applies from the next close, and a
                        month that is already closed keeps the ladder it was evaluated with.
                      </p>
<?php endif; ?>

<?= View::capture('admin/settings/_team_harvest', [
    'settings'             => $settings,
    'team_harvest_fields'  => $teamFields,
    'team_harvest_toggles' => $teamToggles,
    'team_harvest_amounts' => $teamAmounts,
    'team_harvest_ladder'  => $teamLadder,
    'team_harvest_status'  => $teamStatus,
    'team_harvest_preset'  => $teamPreset,
]) ?>
                    </div>
