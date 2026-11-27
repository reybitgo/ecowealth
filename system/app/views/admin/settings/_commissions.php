<?php
declare(strict_types=1);

/**
 * Admin settings — the compensation switches (Phase 9–11 keys, exposed
 * in Phase 15), the repeat-purchase sub-block (Phase 17B), the Royalty
 * ladder (Phase 19B), the Team Harvest ladder (Phase 20B) and the
 * General Harvest board (Phase 21B).
 *
 * All five blocks are one card and one form: the hidden `section` field
 * still says `commissions`, so the repeat-purchase, Royalty, Team Harvest
 * and General Harvest keys save, validate and audit exactly like the
 * referral keys beside them (plan §13.5, §6 19B rule 10, §6 20B rule 11,
 * §6 21B rule 12).
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
 * @var list<array<string, mixed>> $general_harvest_fields
 * @var array<string, array{label:string,hint:string}> $general_harvest_toggles
 * @var array<int, string> $general_harvest_amounts
 * @var array<string, mixed> $general_harvest_ladder
 * @var array<string, mixed> $general_harvest_positions
 * @var array<string, mixed> $general_harvest_board
 * @var array<int, string> $general_harvest_preset
 * @var array{binary_on_zero_pv:int, binary_off:int} $binary_readiness
 * @var list<array<string, mixed>> $package_binary_fields
 * @var array<string, array{label:string, hint:string}> $package_binary_toggles
 * @var array{binary_on_zero_points:int, binary_off:int} $package_binary_readiness
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
$generalFields     = $general_harvest_fields ?? [];
$generalToggles    = $general_harvest_toggles ?? [];
$generalAmounts    = $general_harvest_amounts ?? [];
$generalLadder     = $general_harvest_ladder ?? [];
$generalPositions  = $general_harvest_positions ?? [];
$generalBoard      = $general_harvest_board ?? [];
$generalPreset     = $general_harvest_preset ?? [];
$binaryFields      = $binary_fields  ?? [];
$binaryToggles     = $binary_toggles ?? [];

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
                              <th scope="col">Rank</th>
                              <th scope="col">Requirement</th>
                              <th scope="col" class="text-end">Monthly group quota</th>
                              <th scope="col" class="text-end">Group %</th>
                              <th scope="col" class="text-end">Personal %</th>
                              <th scope="col" class="text-end">Own purchase</th>
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

<?= View::capture('admin/settings/_general_harvest', [
    'settings'                  => $settings,
    'general_harvest_fields'    => $generalFields,
    'general_harvest_toggles'   => $generalToggles,
    'general_harvest_amounts'   => $generalAmounts,
    'general_harvest_ladder'    => $generalLadder,
    'general_harvest_positions' => $generalPositions,
    'general_harvest_board'     => $generalBoard,
    'general_harvest_preset'    => $generalPreset,
]) ?>

<?php
$binaryEnabled   = (string) ($settings['binary_enabled'] ?? '0') === '1';
$pairPoints      = (string) ($settings['binary_pair_points'] ?? '1000');
$pairPct         = (string) ($settings['binary_pair_pct'] ?? '10.000');

$pairValue  = round((float) $pairPoints * (float) $pairPct / 100, 2);
$pairPointsLabel = number_format((int) $pairPoints, 0, '', ',');
$pairPctLabel    = rtrim(rtrim(number_format((float) $pairPct, 3, '.', ''), '0'), '.');
?>
                      <hr class="my-5" />

                      <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                        <h6 class="mb-0">Product binary</h6>
                        <span class="badge bg-label-<?= $binaryEnabled ? 'success' : 'secondary' ?>"><?= $binaryEnabled ? 'Configured — money engine arrives next' : 'Off — nothing pays' ?></span>
                      </div>
                      <p class="text-body-secondary small mb-4">
                        A placement tree over repeat-purchase points: the buyer earns a Self Bonus on their own
                        purchase, and every purchase points left against right to pay pairs up the binary tree.
                        Points and money stay in separate ledgers. This card only configures the stream — no
                        award is written until the money engine ships, and the master switch below is off by
                        default. A product controls only its switch and its P.V.; there is no per-product pair
                        size, pair rate or self rate.
                      </p>

                      <!-- Master switch first -->
                      <div class="row g-4">
                        <div class="col-12">
                          <div class="form-check form-switch">
                            <input class="form-check-input <?= invalid_class('binary_enabled') ?>" type="checkbox"
                                   role="switch" name="binary_enabled" value="1" id="setting-binary_enabled"
                                   <?= $checked('binary_enabled') ? 'checked' : '' ?> />
                            <label class="form-check-label" for="setting-binary_enabled">
                              <?= e((string) ($binaryToggles['binary_enabled']['label'] ?? 'Product binary enabled')) ?>
                            </label>
                          </div>
                          <p class="text-body-secondary small mb-0 ms-5 ps-2"><?= e((string) ($binaryToggles['binary_enabled']['hint'] ?? '')) ?></p>
<?php if ($binaryEnabled): ?>
                          <div class="text-warning small mt-1">Orders now place points and pay awards when the money engine is live. Turn this off to stop new awards.</div>
<?php endif; ?>
<?php if (has_error('binary_enabled')): ?>
                          <div class="text-danger small mt-1"><?= e(error_for('binary_enabled')) ?></div>
<?php endif; ?>
                        </div>
                      </div>

                      <hr class="my-4" />

                      <div class="row g-6">
<?php foreach ($binaryFields as $field): ?>
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
<?php foreach (array_slice($binaryToggles, 1) as $key => $toggle): ?>
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

                      <!-- Readiness panel -->
                      <?php
                      $readiness = $binary_readiness ?? [];
                      $onZeroPv  = (int) ($readiness['binary_on_zero_pv'] ?? 0);
                      $off       = (int) ($readiness['binary_off'] ?? 0);
                      ?>
                      <div class="card mt-6">
                        <div class="card-header"><h6 class="mb-0">Product binary readiness</h6></div>
                        <div class="card-body py-3">
                          <div class="row g-3">
                            <div class="col-md-3">
                              <p class="text-body-secondary small mb-1">Binary on, no points</p>
                              <strong class="d-block"><?= $onZeroPv ?></strong>
                              <p class="text-body-secondary small mb-0">
                                <?= $onZeroPv === 0 ? 'All flagged products earn points.' : sprintf('<a href="%s">%s product(s) flagged but earn 0 points — set a P.V. or switch them off.</a>', e(url('admin/products?binary=on')), $onZeroPv); ?>
                              </p>
                            </div>
                            <div class="col-md-3">
                              <p class="text-body-secondary small mb-1">Binary off</p>
                              <strong class="d-block"><?= $off ?></strong>
                              <p class="text-body-secondary small mb-0">
                                <?= $off === 0 ? 'Every product places points.' : sprintf('<a href="%s">%s product(s) do not place points.</a>', e(url('admin/products?binary=off')), $off); ?>
                              </p>
                            </div>
                            <div class="col-md-3">
                              <p class="text-body-secondary small mb-1">Global switch</p>
                              <strong class="d-block"><?= $binaryEnabled ? 'On' : 'Off' ?></strong>
                              <p class="text-body-secondary small mb-0"><?= $binaryEnabled ? 'The stream is live — new paid orders place points and pay.' : 'The stream is off. Nothing is paid.' ?></p>
                            </div>
                            <div class="col-md-3">
                              <p class="text-body-secondary small mb-1">Preset check</p>
                              <p class="text-body-secondary small mb-0">
                                <?= e($pairPointsLabel) ?> points per pair pays <?= e(money($pairValue)) ?> at <?= e($pairPctLabel) ?>%.
                              </p>
                            </div>
                          </div>
                          <div class="mt-3">
                            <a href="<?= e(url('admin/reports')) ?>" class="small text-decoration-none">
                              <i class="bx bx-compass me-1"></i> Open the health panel — the four Product binary checks render there, read-only.
                            </a>
                          </div>
                        </div>
                      </div>

                      <?php
                      $packageEnabled   = (string) ($settings['package_binary_enabled'] ?? '0') === '1';
                      $requiresPackage = (string) ($settings['package_binary_requires_package'] ?? '1') === '1';
                      $packagePts     = (int) setting('binary_pair_points', '1000');
                      $packagePct     = (float) setting('binary_pair_pct', '10.000');
                      $packageValue   = round($packagePts * $packagePct / 100, 2);
                      $packagePtsLabel = number_format((int) $packagePts, 0, '', ',');
                      $packagePctLabel = rtrim(rtrim(number_format((float) $packagePct, 3, '.', ''), '0'), '.');
                      ?>
                      <hr class="my-5" />

                      <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                        <h6 class="mb-0">Package binary</h6>
                        <span class="badge bg-label-<?= $packageEnabled ? 'success' : 'secondary' ?>"><?= $packageEnabled ? 'Configured — money engine arrives next' : 'Off — nothing pays' ?></span>
                      </div>
                      <p class="text-body-secondary small mb-4">
                        When a member's package purchase is approved, its points are contributed once to the
                        binary tree — team only, never to the buyer, never a self bonus. This card only configures
                        the stream: the master switch must be on and each package must carry its own switch and
                        points before anything is paid. There is no per-package pair rate, cap or hold — those
                        are the shared global settings beside it.
                      </p>

                      <!-- Master switch first -->
                      <div class="row g-4">
                        <div class="col-12">
                          <div class="form-check form-switch">
                            <input class="form-check-input <?= invalid_class('package_binary_enabled') ?>" type="checkbox"
                                   role="switch" name="package_binary_enabled" value="1" id="setting-package_binary_enabled"
                                   <?= $packageEnabled ? 'checked' : '' ?> />
                            <label class="form-check-label" for="setting-package_binary_enabled">
                              <?= e((string) ($package_binary_toggles['package_binary_enabled']['label'] ?? 'Package binary enabled')) ?>
                            </label>
                          </div>
                          <p class="text-body-secondary small mb-0 ms-5 ps-2"><?= e((string) ($package_binary_toggles['package_binary_enabled']['hint'] ?? '')) ?></p>
<?php if ($packageEnabled): ?>
                          <div class="text-warning small mt-1">Activations now place points and pay pairs when the money engine is live. Turn this off to stop new package awards.</div>
<?php endif; ?>
<?php if (has_error('package_binary_enabled')): ?>
                          <div class="text-danger small mt-1"><?= e(error_for('package_binary_enabled')) ?></div>
<?php endif; ?>
                        </div>
                      </div>

                      <div class="row g-4 mt-1">
<?php foreach ($package_binary_toggles as $key => $toggle): ?>
<?php $key = (string) $key; ?>
<?php if ($key === 'package_binary_enabled'): ?>
<?php continue; ?>
<?php endif; ?>
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

                      <!-- Readiness panel -->
                      <?php
                      $packageReadiness = $package_binary_readiness ?? [];
                      $onZeroPts  = (int) ($packageReadiness['binary_on_zero_points'] ?? 0);
                      $pkgOff     = (int) ($packageReadiness['binary_off'] ?? 0);
                      ?>
                      <div class="card mt-6">
                        <div class="card-header"><h6 class="mb-0">Package binary readiness</h6></div>
                        <div class="card-body py-3">
                          <div class="row g-3">
                            <div class="col-md-3">
                              <p class="text-body-secondary small mb-1">Binary on, no points</p>
                              <strong class="d-block"><?= $onZeroPts ?></strong>
                              <p class="text-body-secondary small mb-0">
                                <?= $onZeroPts === 0 ? 'All flagged packages earn points.' : sprintf('<a href="%s">%s package(s) flagged but earn 0 points — set points or switch them off.</a>', e(url('admin/packages?binary=on')), $onZeroPts); ?>
                              </p>
                            </div>
                            <div class="col-md-3">
                              <p class="text-body-secondary small mb-1">Binary off</p>
                              <strong class="d-block"><?= $pkgOff ?></strong>
                              <p class="text-body-secondary small mb-0">
                                <?= $pkgOff === 0 ? 'Every package places points.' : sprintf('<a href="%s">%s package(s) do not place points.</a>', e(url('admin/packages?binary=off')), $pkgOff); ?>
                              </p>
                            </div>
                            <div class="col-md-3">
                              <p class="text-body-secondary small mb-1">Global switch</p>
                              <strong class="d-block"><?= $packageEnabled ? 'On' : 'Off' ?></strong>
                              <p class="text-body-secondary small mb-0"><?= $packageEnabled ? 'The stream is live — new package activations place points.' : 'The stream is off. No activation places points.' ?></p>
                            </div>
                            <div class="col-md-3">
                              <p class="text-body-secondary small mb-1">Preset check</p>
                              <p class="text-body-secondary small mb-0">
                                <?= e($packagePtsLabel) ?> points per pair pays <?= e(money($packageValue)) ?> at <?= e($packagePctLabel) ?>%.
                              </p>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
