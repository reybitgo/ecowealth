<?php
$pageTitle = 'Upgrade Package';
require 'views/partials/head.php';
require 'views/partials/sidebar_member.php';

$currentPlans = $currentPlans ?? Package::plans((int)($current['id'] ?? 0));
$curPairing = (bool)($currentPlans['binary'] ?? false);
$selectedTarget = $selectedTarget ?? ($targets[0] ?? null);
$selectedPackageId = (int)($selectedPackageId ?? ($selectedTarget['id'] ?? 0));
$selectedPaymentMethod = ($selectedPaymentMethod ?? '') === 'ewallet' ? 'ewallet' : 'code';
$selectedUpline = $selectedUpline ?? null;
$selectedUplinePosition = in_array($selectedUplinePosition ?? '', ['left', 'right'], true) ? $selectedUplinePosition : '';
$binaryPlacementPreserved = (bool)($binaryPlacementPreserved ?? false);
$balance = (float)($balance ?? 0);
$packageData = array_map(fn($pkg) => [
    'id' => (int)$pkg['id'],
    'name' => (string)$pkg['name'],
    'entry' => (float)$pkg['entry_fee'],
    'diff' => (float)$pkg['diff'],
    'pairing' => (bool)($pkg['plans']['binary'] ?? false),
], $targets);
?>
<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <?= render_flash() ?>

    <header class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
      <div>
        <div class="stat-label mb-1">Membership</div>
        <h4 class="fw-800 mb-1">Upgrade package</h4>
        <p class="text-muted mb-0" style="font-size:.8rem;">Move to a higher package and pay only the difference in entry fee.</p>
      </div>
      <a href="<?= link_to('dashboard') ?>" class="btn btn-outline-secondary btn-sm">
        <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
        Back to dashboard
      </a>
    </header>

    <section class="card mb-3" aria-labelledby="currentPackageTitle">
      <div class="card-body d-flex flex-wrap align-items-center gap-3">
        <div class="stat-icon bg-primary-subtle mb-0" aria-hidden="true">
          <i class="fa-solid fa-layer-group"></i>
        </div>
        <div class="flex-grow-1" style="min-width:0;">
          <div class="stat-label mb-1">Current package</div>
          <h5 class="fw-bold mb-1" id="currentPackageTitle"><?= e($current['name'] ?? '—') ?></h5>
          <div class="text-muted" style="font-size:.75rem;">
            Entry fee <strong class="font-mono text-dark"><?= fmt_money($curFee ?? 0) ?></strong>
            <span class="mx-1">·</span>
            <?= $curPairing ? 'Binary network' : 'Non-binary package' ?>
          </div>
        </div>
        <span class="badge <?= $curPairing ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' ?>">
          <i class="fa-solid <?= $curPairing ? 'fa-sitemap' : 'fa-user-group' ?>" aria-hidden="true"></i>
          <?= $curPairing ? 'Binary enabled' : 'Non-binary' ?>
        </span>
      </div>
    </section>

    <?php if (empty($targets)): ?>
      <section class="card">
        <div class="card-body text-center py-5">
          <div class="stat-icon bg-secondary-subtle mx-auto" aria-hidden="true">
            <i class="fa-solid fa-check"></i>
          </div>
          <h5 class="fw-bold">No compatible upgrades available</h5>
          <p class="text-muted" style="font-size:.8rem;">
            You are currently on the highest compatible package, or no higher package is available right now.
          </p>
          <a href="<?= link_to('dashboard') ?>" class="btn btn-primary">
            <i class="fa-solid fa-house" aria-hidden="true"></i>
            Return to dashboard
          </a>
        </div>
      </section>
    <?php else: ?>
      <div class="row g-3 g-xxl-4 align-items-start">
        <div class="col-12 col-xxl-7">
          <section class="card" aria-labelledby="packageSelectionTitle">
            <div class="card-header d-flex justify-content-between align-items-center gap-2">
              <div>
                <h5 class="card-title" id="packageSelectionTitle">Choose an upgrade</h5>
                <p class="text-muted mb-0" style="font-size:.73rem;">Select one package to continue.</p>
              </div>
              <span class="badge bg-primary-subtle text-primary"><?= count($targets) ?> available</span>
            </div>
            <div class="card-body">
              <div class="alert alert-info d-flex gap-2 py-2 mb-3" style="font-size:.78rem;">
                <i class="fa-solid fa-circle-info mt-1" aria-hidden="true"></i>
                <span>Only packages that preserve your current Binary, Indirect Referral, and DFI benefits are shown.</span>
              </div>

              <fieldset class="border-0 p-0 m-0">
                <legend class="visually-hidden">Available upgrade packages</legend>
                <div class="choice-card-grid" id="packageOptions">
                  <?php foreach ($targets as $pkg):
                    $pkgPlans = $pkg['plans'] ?? Package::plans((int)$pkg['id']);
                    $isSelected = (int)$pkg['id'] === $selectedPackageId;
                  ?>
                    <label class="choice-card<?= $isSelected ? ' is-selected' : '' ?>"
                      data-id="<?= (int)$pkg['id'] ?>"
                      data-name="<?= e($pkg['name']) ?>"
                      data-entry="<?= (float)$pkg['entry_fee'] ?>"
                      data-diff="<?= (float)$pkg['diff'] ?>"
                      data-pairing="<?= !empty($pkgPlans['binary']) ? '1' : '0' ?>">
                      <input class="choice-card__input" type="radio"
                        name="upgrade_pkg" value="<?= (int)$pkg['id'] ?>"
                        id="upg<?= (int)$pkg['id'] ?>" <?= $isSelected ? 'checked' : '' ?>>
                      <span class="choice-card__mark" aria-hidden="true"></span>
                      <span class="choice-card__body">
                        <span class="d-flex flex-wrap justify-content-between align-items-baseline gap-2">
                          <span class="choice-card__title"><?= e($pkg['name']) ?></span>
                          <span class="text-muted" style="font-size:.72rem;">Entry <?= fmt_money((float)$pkg['entry_fee']) ?></span>
                        </span>
                        <span class="d-flex flex-wrap gap-1 mt-1">
                          <span class="badge <?= !empty($pkgPlans['binary']) ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' ?>">
                            <?= !empty($pkgPlans['binary']) ? 'Binary' : 'Non-binary' ?>
                          </span>
                          <?php if (!empty($pkgPlans['indirect'])): ?>
                            <span class="badge bg-primary-subtle text-primary">Indirect referral</span>
                          <?php endif; ?>
                          <?php if (!empty($pkgPlans['dfi'])): ?>
                            <span class="badge bg-warning-subtle text-warning">DFI enabled</span>
                          <?php endif; ?>
                        </span>
                        <span class="choice-card__meta mt-1">
                          <?php if (!empty($pkgPlans['binary'])): ?>
                            <span class="d-block"><i class="fa-solid fa-link" aria-hidden="true"></i> Pair bonus <strong class="font-mono text-dark"><?= fmt_money((float)$pkg['pairing_bonus']) ?></strong></span>
                            <span class="d-block"><i class="fa-solid fa-chart-simple" aria-hidden="true"></i> Daily pair cap <strong class="font-mono text-dark"><?= (int)$pkg['daily_pair_cap'] ?></strong></span>
                          <?php endif; ?>
                          <span class="d-block"><i class="fa-solid fa-user-plus" aria-hidden="true"></i> Direct bonus <strong class="font-mono text-dark"><?= fmt_money((float)$pkg['direct_ref_bonus']) ?></strong></span>
                          <?php if (!empty($pkgPlans['dfi'])): ?>
                            <span class="d-block"><i class="fa-solid fa-calendar-day" aria-hidden="true"></i> DFI <strong class="font-mono text-dark"><?= fmt_money((float)$pkg['daily_fixed_income']) ?>/day</strong></span>
                          <?php endif; ?>
                        </span>
                      </span>
                      <span class="choice-card__aside">
                        <span class="d-block stat-label">Upgrade fee</span>
                        <strong class="font-mono text-dark" style="font-size:1.05rem;"><?= fmt_money((float)$pkg['diff']) ?></strong>
                        <span class="d-block text-muted" style="font-size:.68rem;">Difference only</span>
                      </span>
                    </label>
                  <?php endforeach; ?>
                </div>
              </fieldset>
            </div>
          </section>

          <section id="binaryPlacementCard" class="card mt-3 d-none" aria-labelledby="binaryPlacementTitle" aria-hidden="true">
            <div class="card-header d-flex justify-content-between align-items-center gap-2">
              <div>
                <h5 class="card-title" id="binaryPlacementTitle">Binary placement</h5>
                <p class="text-muted mb-0" style="font-size:.73rem;">Choose an available position in the network.</p>
              </div>
              <span class="badge bg-warning-subtle text-warning">Required</span>
            </div>
            <div class="card-body">
              <div class="alert alert-primary d-flex gap-2 py-2 mb-3" style="font-size:.78rem;">
                <i class="fa-solid fa-sitemap mt-1" aria-hidden="true"></i>
                <span>This package joins the binary network. Select an active upline with an open position.</span>
              </div>

              <div class="mb-3">
                <label class="form-label" for="uplineSearch">Binary upline</label>
                <div class="combo">
                  <div class="combo__control">
                    <i class="fa-solid fa-magnifying-glass combo__icon" aria-hidden="true"></i>
                    <input type="text" id="uplineSearch" class="form-control"
                      placeholder="Type a member username" autocomplete="off"
                      autocapitalize="characters" spellcheck="false"
                      role="combobox" aria-autocomplete="list" aria-expanded="false"
                      aria-controls="uplineResults" aria-describedby="uplineStatus">
                    <button type="button" class="combo__clear" id="clearUplineSearch"
                      aria-label="Clear upline search" hidden>
                      <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                  </div>
                  <div id="uplineResults" class="combo__listbox" role="listbox" hidden></div>
                </div>
                <div class="form-text" id="uplineStatus" role="status" aria-live="polite">Enter at least 2 characters to search.</div>
              </div>

              <div class="card bg-light border-0 mb-3" id="selectedUplineBox">
                <div class="card-body py-2 d-flex align-items-center gap-2">
                  <div class="stat-icon bg-primary-subtle mb-0" style="width:36px;height:36px;font-size:1rem;" aria-hidden="true">
                    <i class="fa-solid fa-user-group"></i>
                  </div>
                  <div class="flex-grow-1" style="min-width:0;">
                    <strong class="d-block text-truncate" id="selectedUplineName">No upline selected</strong>
                    <span class="text-muted d-block" style="font-size:.72rem;" id="selectedUplineMeta">Search and select an eligible member.</span>
                  </div>
                  <button type="button" class="btn btn-sm btn-outline-primary flex-shrink-0" id="clearUplineBtn" hidden>Change</button>
                </div>
              </div>

              <fieldset class="border-0 p-0 m-0">
                <legend class="form-label">Binary position</legend>
                <div class="choice-card-grid choice-card-grid--2" id="positionOptions">
                  <label class="choice-card is-disabled" id="positionLeftCard" for="upg_pos_left">
                    <input class="choice-card__input" type="radio"
                      id="upg_pos_left" name="upg_binary_position" value="left" disabled>
                    <span class="choice-card__mark" aria-hidden="true"></span>
                    <span class="choice-card__body">
                      <strong class="d-block" style="font-size:.85rem;"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Left position</strong>
                      <small class="choice-card__meta d-block" id="upgLeftAvailability">Select an upline first</small>
                    </span>
                  </label>
                  <label class="choice-card is-disabled" id="positionRightCard" for="upg_pos_right">
                    <input class="choice-card__input" type="radio"
                      id="upg_pos_right" name="upg_binary_position" value="right" disabled>
                    <span class="choice-card__mark" aria-hidden="true"></span>
                    <span class="choice-card__body">
                      <strong class="d-block" style="font-size:.85rem;"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i> Right position</strong>
                      <small class="choice-card__meta d-block" id="upgRightAvailability">Select an upline first</small>
                    </span>
                  </label>
                </div>
                <div class="form-text" id="upgPosHint" role="status" aria-live="polite"></div>
              </fieldset>
            </div>
          </section>
        </div>

        <div class="col-12 col-xxl-5">
          <section class="card" aria-labelledby="paymentTitle">
            <div class="card-header d-flex justify-content-between align-items-center gap-2">
              <div>
                <h5 class="card-title" id="paymentTitle">Payment</h5>
                <p class="text-muted mb-0" style="font-size:.73rem;">Review and confirm your upgrade.</p>
              </div>
              <i class="fa-solid fa-credit-card text-muted" aria-hidden="true"></i>
            </div>
            <div class="card-body">
              <form method="POST" action="<?= link_to('do_upgrade') ?>" id="upgradeForm" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="package_id" id="upgPackageId" value="<?= $selectedPackageId ?>">
                <input type="hidden" name="binary_upline_id" id="upgUplineId" value="<?= (int)($selectedUpline['id'] ?? 0) ?>">
                <input type="hidden" name="binary_position" id="upgPosition" value="<?= e($selectedUplinePosition) ?>">

                <div class="alert alert-primary py-2 mb-3">
                  <div class="d-flex justify-content-between align-items-center gap-2" style="font-size:.72rem;">
                    <span class="text-uppercase fw-bold">Selected upgrade</span>
                    <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                  </div>
                  <strong class="d-block mt-1" id="upgSelectedPackageName"><?= e($selectedTarget['name'] ?? '') ?></strong>
                  <div class="d-flex flex-wrap justify-content-between gap-2 mt-1" style="font-size:.75rem;">
                    <span>New entry <b class="font-mono"><?= fmt_money((float)($selectedTarget['entry_fee'] ?? 0)) ?></b></span>
                    <span id="upgSelectedDifference">Fee <?= fmt_money((float)($selectedTarget['diff'] ?? 0)) ?></span>
                  </div>
                </div>

                <fieldset class="border-0 p-0 m-0 mb-3">
                  <legend class="form-label">Payment method</legend>
                  <div class="choice-card-grid" id="paymentOptions">
                    <label class="choice-card" id="walletPaymentOption" for="pay_ewlg">
                      <input class="choice-card__input" type="radio"
                        name="payment_method" id="pay_ewlg" value="ewallet" required>
                      <span class="choice-card__mark" aria-hidden="true"></span>
                      <span class="stat-icon bg-primary-subtle mb-0" style="width:36px;height:36px;font-size:1rem;" aria-hidden="true">
                        <i class="fa-solid fa-wallet"></i>
                      </span>
                      <span class="choice-card__body">
                        <strong class="d-block" style="font-size:.85rem;">E-Wallet</strong>
                        <small class="choice-card__meta d-block">Available balance <b class="font-mono text-dark"><?= fmt_money($balance) ?></b></small>
                        <span class="d-block mt-1" style="font-size:.72rem;" id="walletPaymentStatus" role="status" aria-live="polite"></span>
                      </span>
                    </label>
                    <label class="choice-card" id="codePaymentOption" for="pay_upcode">
                      <input class="choice-card__input" type="radio"
                        name="payment_method" id="pay_upcode" value="code" required>
                      <span class="choice-card__mark" aria-hidden="true"></span>
                      <span class="stat-icon bg-primary-subtle mb-0" style="width:36px;height:36px;font-size:1rem;" aria-hidden="true">
                        <i class="fa-solid fa-ticket"></i>
                      </span>
                      <span class="choice-card__body">
                        <strong class="d-block" style="font-size:.85rem;">Upgrade code</strong>
                        <small class="choice-card__meta d-block">Use a code issued for the selected package.</small>
                      </span>
                    </label>
                  </div>
                  <div class="alert alert-warning py-2 mt-2 mb-0" style="font-size:.75rem;" id="paymentNotice" role="status" aria-live="polite" hidden></div>
                </fieldset>

                <div class="card bg-light border-0 mb-3">
                  <div class="card-body py-2">
                    <table class="info-table" role="group" aria-label="Upgrade cost summary">
                      <tr>
                        <td>Current entry fee</td>
                        <td class="text-end fw-bold font-mono"><?= fmt_money($curFee ?? 0) ?></td>
                      </tr>
                      <tr>
                        <td>New entry fee</td>
                        <td class="text-end fw-bold font-mono" id="upgEntryFee"><?= fmt_money((float)($selectedTarget['entry_fee'] ?? 0)) ?></td>
                      </tr>
                      <tr>
                        <td class="text-dark">You pay</td>
                        <td class="text-end fw-bold font-mono text-success" id="upgFee" style="font-size:1rem;"><?= fmt_money((float)($selectedTarget['diff'] ?? 0)) ?></td>
                      </tr>
                      <tr id="walletRemainingRow">
                        <td>Wallet after upgrade</td>
                        <td class="text-end fw-bold font-mono" id="upgWalletRemaining">—</td>
                      </tr>
                    </table>
                  </div>
                </div>

                <div class="mb-3" id="codeInputSection" hidden>
                  <label class="form-label" for="upgrade_code">Upgrade code</label>
                  <div class="input-group">
                    <input type="text" name="upgrade_code" id="upgrade_code" class="form-control font-mono"
                      placeholder="UP-XXXX-XXXX-XXXX" maxlength="17" autocomplete="off"
                      autocapitalize="characters" spellcheck="false" aria-describedby="codeHint">
                    <button type="button" class="btn btn-outline-primary" id="validateCodeBtn">
                      <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                      Validate
                    </button>
                  </div>
                  <div class="form-text" id="codeHint" role="status" aria-live="polite">Enter a code for the selected package.</div>
                </div>

                <button type="submit" class="btn btn-primary w-100 py-3" id="upgSubmitBtn" disabled>
                  <i class="fa-solid fa-arrow-up-from-bracket" aria-hidden="true"></i>
                  Confirm upgrade
                </button>
                <div class="form-text text-center mt-2" id="upgBlockHint" role="status" aria-live="polite"></div>
                <p class="text-muted mt-2 mb-0 text-center" style="font-size:.7rem;line-height:1.5;">
                  Only the difference in entry fees is charged. Package downgrades are not allowed.
                </p>
              </form>
            </div>
          </section>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($targets)): ?>
<script>
(() => {
  const form = document.getElementById('upgradeForm');
  if (!form) return;

  const packages = <?= json_encode($packageData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const packageById = new Map(packages.map(pkg => [Number(pkg.id), pkg]));
  const currentPackageName = <?= json_encode($current['name'] ?? '', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const initialPackageId = <?= $selectedPackageId ?>;
  const initialUpline = <?= json_encode($selectedUpline, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const initialUplinePosition = <?= json_encode($selectedUplinePosition, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const currentPairing = <?= $curPairing ? 'true' : 'false' ?>;
  const binaryPlacementPreserved = <?= $binaryPlacementPreserved ? 'true' : 'false' ?>;
  const walletBalance = <?= json_encode((float)$balance) ?>;
  const uplinesApi = <?= json_encode(link_to('api_binary_uplines'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const codeApi = <?= json_encode(link_to('api_validate_upgrade_code'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const csrfToken = <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

  const packageInputs = Array.from(document.querySelectorAll('#packageOptions .choice-card__input'));
  const packageOptions = Array.from(document.querySelectorAll('#packageOptions .choice-card'));
  const packageIdInput = document.getElementById('upgPackageId');
  const selectedPackageName = document.getElementById('upgSelectedPackageName');
  const selectedDifference = document.getElementById('upgSelectedDifference');
  const entryFeeOutput = document.getElementById('upgEntryFee');
  const feeOutput = document.getElementById('upgFee');
  const walletRemainingOutput = document.getElementById('upgWalletRemaining');
  const walletRemainingRow = document.getElementById('walletRemainingRow');
  const walletInput = document.getElementById('pay_ewlg');
  const codePaymentInput = document.getElementById('pay_upcode');
  const walletPaymentOption = document.getElementById('walletPaymentOption');
  const walletPaymentStatus = document.getElementById('walletPaymentStatus');
  const paymentNotice = document.getElementById('paymentNotice');
  const codeSection = document.getElementById('codeInputSection');
  const codeInput = document.getElementById('upgrade_code');
  const validateCodeButton = document.getElementById('validateCodeBtn');
  const codeHint = document.getElementById('codeHint');
  const submitButton = document.getElementById('upgSubmitBtn');
  const blockHint = document.getElementById('upgBlockHint');
  const binaryCard = document.getElementById('binaryPlacementCard');
  const uplineSearch = document.getElementById('uplineSearch');
  const uplineResults = document.getElementById('uplineResults');
  const uplineStatus = document.getElementById('uplineStatus');
  const clearUplineSearchButton = document.getElementById('clearUplineSearch');
  const clearUplineButton = document.getElementById('clearUplineBtn');
  const selectedUplineName = document.getElementById('selectedUplineName');
  const selectedUplineMeta = document.getElementById('selectedUplineMeta');
  const uplineIdInput = document.getElementById('upgUplineId');
  const positionInput = document.getElementById('upgPosition');
  const positionInputs = Array.from(document.querySelectorAll('#positionOptions .choice-card__input'));
  const leftAvailability = document.getElementById('upgLeftAvailability');
  const rightAvailability = document.getElementById('upgRightAvailability');
  const positionHint = document.getElementById('upgPosHint');

  let currentPackage = packageById.get(initialPackageId) || null;
  let needsPlacement = false;
  let selectedUpline = initialUpline;
  let selectedUplinePosition = initialUplinePosition;
  let validatedCode = '';
  let validatedPackageId = 0;
  let codeValidateSequence = 0;
  let codeValidating = false;
  let walletAvailable = false;
  let searchTimer = null;
  let searchSequence = 0;
  let searchController = null;
  let resultItems = [];
  let activeResultIndex = -1;

  const fmtMoney = value => '₱' + Number(value || 0).toLocaleString('en-PH', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  });

  const escapeHtml = value => String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');

  const setHint = (element, message, state = '') => {
    element.textContent = message;
    element.classList.remove('text-success', 'text-danger');
    if (state === 'success') element.classList.add('text-success');
    else if (state === 'error') element.classList.add('text-danger');
  };

  const getPaymentMethod = () => codePaymentInput.checked ? 'code' : 'ewallet';

  const updatePaymentClasses = () => {
    walletPaymentOption.classList.toggle('is-selected', walletInput.checked);
    walletPaymentOption.classList.toggle('is-disabled', walletInput.disabled);
    document.getElementById('codePaymentOption').classList.toggle('is-selected', codePaymentInput.checked);
  };

  const updateWalletAvailability = (showNotice = false) => {
    const fee = currentPackage ? Number(currentPackage.diff) : 0;
    walletAvailable = walletBalance + 0.005 >= fee;
    walletInput.disabled = !walletAvailable;
    walletPaymentOption.classList.toggle('is-disabled', !walletAvailable);

    if (walletAvailable) {
      walletPaymentStatus.textContent = `Covers this upgrade with ${fmtMoney(walletBalance - fee)} remaining.`;
      walletPaymentStatus.classList.remove('text-danger');
      walletPaymentStatus.classList.add('text-success');
    } else {
      walletPaymentStatus.textContent = `Short by ${fmtMoney(Math.max(0, fee - walletBalance))}.`;
      walletPaymentStatus.classList.remove('text-success');
      walletPaymentStatus.classList.add('text-danger');
    }

    if (walletInput.checked && !walletAvailable) {
      codePaymentInput.checked = true;
      paymentNotice.textContent = 'E-Wallet cannot cover this package. An upgrade code is selected instead.';
      paymentNotice.hidden = false;
    } else if (!showNotice) {
      paymentNotice.hidden = true;
    }

    walletRemainingRow.hidden = getPaymentMethod() !== 'ewallet';
    walletRemainingOutput.textContent = walletAvailable ? fmtMoney(Math.max(0, walletBalance - fee)) : 'Insufficient balance';
  };

  const currentCode = () => codeInput.value.trim().toUpperCase();

  const isCodeValidated = () => Boolean(currentPackage)
    && validatedCode !== ''
    && validatedCode === currentCode()
    && validatedPackageId === Number(currentPackage.id);

  const resetCodeValidation = (message = 'Enter a code for the selected package.', invalidatePending = true) => {
    validatedCode = '';
    validatedPackageId = 0;
    if (invalidatePending) {
      codeValidateSequence += 1;
      codeValidating = false;
      validateCodeButton.disabled = false;
      validateCodeButton.removeAttribute('aria-busy');
    }
    setHint(codeHint, message);
  };

  const submitBlockedReason = () => {
    if (!currentPackage) return 'No upgrade package is available.';
    if (getPaymentMethod() === 'ewallet' && !walletAvailable) return 'Your e-wallet balance cannot cover this upgrade.';
    if (getPaymentMethod() === 'code' && codeValidating) return 'Checking your upgrade code…';
    if (getPaymentMethod() === 'code' && !isCodeValidated()) {
      return validatedCode !== '' ? 'The upgrade code changed. Validate it again.' : 'Validate your upgrade code to continue.';
    }
    if (needsPlacement && !selectedUpline) return 'Select a binary upline to continue.';
    if (needsPlacement && !selectedUplinePosition) return 'Select an available binary position to continue.';
    return '';
  };

  const updateSubmitState = () => {
    let ready = Boolean(currentPackage);
    if (ready && getPaymentMethod() === 'code' && !isCodeValidated()) ready = false;
    if (ready && needsPlacement && (!selectedUpline || !selectedUplinePosition)) ready = false;
    if (ready && getPaymentMethod() === 'ewallet' && !walletAvailable) ready = false;

    submitButton.disabled = !ready;
    submitButton.setAttribute('aria-disabled', ready ? 'false' : 'true');
    submitButton.title = ready ? '' : submitBlockedReason();

    const reason = ready ? '' : submitBlockedReason();
    blockHint.textContent = reason;
    blockHint.hidden = reason === '';
  };

  const updatePaymentUI = () => {
    const method = getPaymentMethod();
    codeSection.hidden = method !== 'code';
    codeInput.required = method === 'code';
    walletRemainingRow.hidden = method !== 'ewallet';
    if (method === 'ewallet' && walletAvailable) {
      walletRemainingOutput.textContent = fmtMoney(Math.max(0, walletBalance - Number(currentPackage.diff)));
    } else {
      walletRemainingOutput.textContent = '—';
    }
    updatePaymentClasses();
    updateSubmitState();
  };

  const updateSelectedUpline = () => {
    if (!selectedUpline) {
      selectedUplineName.textContent = 'No upline selected';
      selectedUplineMeta.textContent = 'Search and select an eligible member.';
      clearUplineButton.hidden = true;
      return;
    }

    selectedUplineName.textContent = `@${selectedUpline.username}`;
    const slots = [
      selectedUpline.left_free ? 'Left available' : 'Left full',
      selectedUpline.right_free ? 'Right available' : 'Right full'
    ].join(' · ');
    selectedUplineMeta.textContent = `${selectedUpline.package} · ${slots}`;
    clearUplineButton.hidden = false;
  };

  const updatePositionAvailability = () => {
    const leftInput = document.getElementById('upg_pos_left');
    const rightInput = document.getElementById('upg_pos_right');
    leftInput.disabled = !selectedUpline || !selectedUpline.left_free;
    rightInput.disabled = !selectedUpline || !selectedUpline.right_free;
    leftAvailability.textContent = selectedUpline ? (selectedUpline.left_free ? 'Position is available' : 'Position is occupied') : 'Select an upline first';
    rightAvailability.textContent = selectedUpline ? (selectedUpline.right_free ? 'Position is available' : 'Position is occupied') : 'Select an upline first';
    document.getElementById('positionLeftCard').classList.toggle('is-disabled', leftInput.disabled);
    document.getElementById('positionRightCard').classList.toggle('is-disabled', rightInput.disabled);
    positionInputs.forEach(input => {
      input.closest('.choice-card').classList.toggle('is-selected', input.checked);
    });
  };

  const clearUplineSelection = () => {
    selectedUpline = null;
    selectedUplinePosition = '';
    uplineIdInput.value = '';
    positionInput.value = '';
    positionInputs.forEach(input => { input.checked = false; });
    updateSelectedUpline();
    updatePositionAvailability();
    setHint(positionHint, '');
  };

  const applyUplineSelection = (upline, position = '') => {
    selectedUpline = upline;
    selectedUplinePosition = '';
    uplineIdInput.value = upline ? Number(upline.id) : '';
    positionInputs.forEach(input => { input.checked = false; });
    updateSelectedUpline();
    updatePositionAvailability();

    if (upline && ['left', 'right'].includes(position)) {
      const input = document.getElementById(`upg_pos_${position}`);
      if (input && !input.disabled) {
        input.checked = true;
        selectedUplinePosition = position;
        positionInput.value = position;
      }
    }
    setHint(positionHint, selectedUplinePosition ? 'Position selected.' : 'Select Left or Right.');
  };

  const closeUplineResults = () => {
    clearTimeout(searchTimer);
    if (searchController) {
      searchController.abort();
      searchController = null;
    }
    searchSequence += 1;
    uplineResults.hidden = true;
    uplineSearch.setAttribute('aria-expanded', 'false');
    uplineSearch.removeAttribute('aria-activedescendant');
    resultItems = [];
    activeResultIndex = -1;
  };

  const selectPackage = packageId => {
    const selected = packageById.get(Number(packageId));
    if (!selected) return;

    currentPackage = selected;
    packageIdInput.value = String(selected.id);
    packageInputs.forEach(input => { input.checked = Number(input.value) === Number(selected.id); });
    packageOptions.forEach(option => option.classList.toggle('is-selected', Number(option.dataset.id) === Number(selected.id)));

    selectedPackageName.textContent = selected.name;
    selectedDifference.textContent = `Fee ${fmtMoney(selected.diff)}`;
    entryFeeOutput.textContent = fmtMoney(selected.entry);
    feeOutput.textContent = fmtMoney(selected.diff);

    needsPlacement = !currentPairing && Boolean(selected.pairing) && !binaryPlacementPreserved;
    binaryCard.classList.toggle('d-none', !needsPlacement);
    binaryCard.setAttribute('aria-hidden', needsPlacement ? 'false' : 'true');
    uplineSearch.disabled = !needsPlacement;

    if (!needsPlacement) {
      clearUplineSelection();
      closeUplineResults();
      uplineSearch.value = '';
      clearUplineSearchButton.hidden = true;
    }

    resetCodeValidation();
    updateWalletAvailability();
    updatePaymentUI();
  };

  packageInputs.forEach(input => {
    input.addEventListener('change', () => {
      if (input.checked) selectPackage(input.value);
    });
  });

  walletInput.addEventListener('change', () => {
    paymentNotice.hidden = true;
    updatePaymentUI();
  });

  codePaymentInput.addEventListener('change', () => {
    paymentNotice.hidden = true;
    updatePaymentUI();
  });

  codeInput.addEventListener('input', () => {
    const clean = codeInput.value.replace(/[^A-Z0-9]/gi, '').toUpperCase().slice(0, 14);
    const parts = [
      clean.slice(0, 2),
      clean.slice(2, 6),
      clean.slice(6, 10),
      clean.slice(10, 14)
    ].filter(Boolean);
    codeInput.value = parts.join('-');
    resetCodeValidation();
  });

  const validateCode = async () => {
    if (!currentPackage) return;
    const code = currentCode();
    if (code.length !== 17) {
      resetCodeValidation('Enter the complete code, including UP- and all hyphens.');
      codeInput.focus();
      return;
    }

    const sequence = ++codeValidateSequence;
    const packageId = Number(currentPackage.id);
    codeValidating = true;
    validateCodeButton.disabled = true;
    validateCodeButton.setAttribute('aria-busy', 'true');
    setHint(codeHint, 'Checking this upgrade code…');
    updateSubmitState();

    try {
      const body = new FormData();
      body.append('code', code);
      body.append('package_id', String(packageId));
      body.append('csrf_token', csrfToken);
      const response = await fetch(codeApi, {
        method: 'POST',
        body,
        headers: { 'Accept': 'application/json' }
      });
      const data = await response.json();
      if (sequence !== codeValidateSequence) return;
      if (!response.ok || !data.valid) {
        resetCodeValidation(data.message || 'This upgrade code is invalid or does not match the selected package.', false);
        return;
      }
      if (currentCode() !== code || Number(packageIdInput.value) !== packageId) {
        resetCodeValidation('The package or code changed. Validate the code again.', false);
        return;
      }
      validatedCode = code;
      validatedPackageId = packageId;
      setHint(codeHint, `Code verified for ${data.package_name}.`, 'success');
    } catch (error) {
      if (sequence !== codeValidateSequence) return;
      resetCodeValidation('Unable to validate the code right now. Check your connection and try again.', false);
    } finally {
      if (sequence === codeValidateSequence) {
        codeValidating = false;
        validateCodeButton.disabled = false;
        validateCodeButton.removeAttribute('aria-busy');
        updateSubmitState();
      }
    }
  };

  validateCodeButton.addEventListener('click', validateCode);
  codeInput.addEventListener('blur', () => {
    if (currentCode().length === 17 && !isCodeValidated()) validateCode();
  });

  const setActiveResult = index => {
    if (!resultItems.length) return;
    activeResultIndex = (index + resultItems.length) % resultItems.length;
    resultItems.forEach((item, itemIndex) => {
      const active = itemIndex === activeResultIndex;
      item.classList.toggle('is-active', active);
      item.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    const active = resultItems[activeResultIndex];
    uplineSearch.setAttribute('aria-activedescendant', active.id);
    active.scrollIntoView({ block: 'nearest' });
  };

  const renderUplineResults = candidates => {
    uplineResults.replaceChildren();
    resultItems = [];
    activeResultIndex = -1;

    if (!candidates.length) {
      closeUplineResults();
      return;
    }

    candidates.forEach(candidate => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'combo__option';
      button.id = `uplineOption-${candidate.id}`;
      button.setAttribute('role', 'option');
      button.setAttribute('aria-selected', 'false');
      button.tabIndex = -1;

      const name = document.createElement('strong');
      name.textContent = `@${candidate.username}`;
      const meta = document.createElement('span');
      const slots = [
        candidate.left_free ? 'Left available' : 'Left full',
        candidate.right_free ? 'Right available' : 'Right full'
      ].join(' · ');
      meta.textContent = `${candidate.package} · ${slots}`;
      button.append(name, meta);
      button.addEventListener('mousedown', event => event.preventDefault());
      button.addEventListener('click', () => {
        applyUplineSelection(candidate);
        uplineSearch.value = '';
        clearUplineSearchButton.hidden = true;
        closeUplineResults();
        setHint(uplineStatus, `Selected @${candidate.username}. Choose an available position.`, 'success');
        updateSubmitState();
      });
      uplineResults.appendChild(button);
      resultItems.push(button);
    });

    uplineResults.hidden = false;
    uplineSearch.setAttribute('aria-expanded', 'true');
  };

  const searchUplines = async query => {
    const sequence = ++searchSequence;
    if (searchController) searchController.abort();
    searchController = new AbortController();
    clearUplineSearchButton.hidden = false;
    setHint(uplineStatus, 'Searching for eligible uplines…');

    try {
      const separator = uplinesApi.includes('?') ? '&' : '?';
      const response = await fetch(`${uplinesApi}${separator}q=${encodeURIComponent(query)}`, {
        signal: searchController.signal,
        headers: { 'Accept': 'application/json' }
      });
      if (!response.ok) throw new Error('Search failed');
      const data = await response.json();
      if (sequence !== searchSequence) return;
      const candidates = Array.isArray(data.candidates) ? data.candidates : [];
      renderUplineResults(candidates);
      setHint(uplineStatus, candidates.length
        ? `${candidates.length} eligible upline${candidates.length === 1 ? '' : 's'} found.`
        : 'No eligible uplines found.', candidates.length ? 'success' : '');
    } catch (error) {
      if (error.name === 'AbortError') return;
      setHint(uplineStatus, 'Unable to search uplines right now. Please try again.', 'error');
      closeUplineResults();
    }
  };

  uplineSearch.addEventListener('input', () => {
    clearTimeout(searchTimer);
    const query = uplineSearch.value.trim();
    clearUplineSearchButton.hidden = query.length === 0;
    searchSequence += 1;
    if (searchController) searchController.abort();

    if (selectedUpline && query) clearUplineSelection();
    if (query.length < 2) {
      closeUplineResults();
      setHint(uplineStatus, 'Enter at least 2 characters to search.');
      return;
    }
    searchTimer = setTimeout(() => searchUplines(query), 400);
  });

  uplineSearch.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
      event.preventDefault();
      closeUplineResults();
      return;
    }
    if (uplineResults.hidden) return;
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      setActiveResult(activeResultIndex + 1);
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      setActiveResult(activeResultIndex - 1);
    } else if (event.key === 'Enter' && activeResultIndex >= 0) {
      event.preventDefault();
      resultItems[activeResultIndex]?.click();
    }
  });

  clearUplineSearchButton.addEventListener('click', () => {
    uplineSearch.value = '';
    clearUplineSearchButton.hidden = true;
    closeUplineResults();
    setHint(uplineStatus, 'Enter at least 2 characters to search.');
    uplineSearch.focus();
  });

  clearUplineButton.addEventListener('click', () => {
    clearUplineSelection();
    closeUplineResults();
    setHint(uplineStatus, 'Search and select another upline.');
    uplineSearch.focus();
  });

  positionInputs.forEach(input => {
    input.addEventListener('change', () => {
      selectedUplinePosition = input.value;
      positionInput.value = input.value;
      updatePositionAvailability();
      setHint(positionHint, 'Position selected.', 'success');
      updateSubmitState();
    });
  });

  document.addEventListener('click', event => {
    if (!event.target.closest('.combo')) closeUplineResults();
  });

  form.addEventListener('submit', event => {
    event.preventDefault();
    if (submitButton.disabled || !currentPackage || document.querySelector('.modal.show')) return;

    if (getPaymentMethod() === 'code') {
      if (codeValidating) {
        setHint(codeHint, 'Still checking your upgrade code. Please wait a moment.', 'error');
        return;
      }
      if (!isCodeValidated()) {
        resetCodeValidation('Validate your upgrade code before continuing.');
        codeInput.focus();
        return;
      }
    }

    const method = getPaymentMethod() === 'code' ? 'Upgrade code' : 'E-Wallet';
    let placement = 'Not required for this package';
    if (binaryPlacementPreserved && !currentPairing && currentPackage.pairing) {
      placement = 'Existing binary position retained';
    } else if (needsPlacement && selectedUpline) {
      placement = `@${selectedUpline.username} · ${selectedUplinePosition === 'left' ? 'Left' : 'Right'} position`;
    }
    const message = `
      <table class="info-table">
        <tr><td>Current package</td><td><strong>${escapeHtml(currentPackageName)}</strong></td></tr>
        <tr><td>New package</td><td><strong>${escapeHtml(currentPackage.name)}</strong></td></tr>
        <tr><td>Amount</td><td><strong class="text-success font-mono">${fmtMoney(currentPackage.diff)}</strong></td></tr>
        <tr><td>Payment</td><td><strong>${escapeHtml(method)}</strong></td></tr>
        <tr><td>Placement</td><td><strong>${escapeHtml(placement)}</strong></td></tr>
      </table>`;

    if (typeof showConfirm !== 'function') {
      submitButton.disabled = true;
      submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Processing…';
      form.submit();
      return;
    }

    showConfirm({
      title: 'Confirm package upgrade',
      message,
      confirmText: 'Upgrade now',
      confirmClass: 'btn-primary',
      onConfirm: () => {
        submitButton.disabled = true;
        submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Processing…';
        form.submit();
      }
    });
  });

  selectPackage(initialPackageId);
  if (initialUpline) {
    applyUplineSelection(initialUpline, initialUplinePosition);
  } else {
    clearUplineSelection();
  }
  <?php if ($selectedPaymentMethod === 'ewallet'): ?>
    walletInput.checked = true;
  <?php else: ?>
    codePaymentInput.checked = true;
  <?php endif; ?>
  updateWalletAvailability();
  updatePaymentUI();
  updateSubmitState();
})();
</script>
<?php endif; ?>
<?php require 'views/partials/footer.php'; ?>

