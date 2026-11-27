<?php
declare(strict_types=1);

/**
 * Admin activation — package binary outcome card (plan 23D-S2).
 *
 * Read-only: shows what one activation placed and paid in the package
 * binary stream. Points and money are shown side by side, never added
 * together (plan Rule 4).
 *
 * @var array<string,mixed> $activation  activation row incl. binary_points, binary_total, binary_processed_at
 * @var array{activation_id:int, points:int, pairs:int, amount:float, awards:list<array<string,mixed>>, volume:list<array<string,mixed>>}|null $binary
 * @var string              $wallet_url  base URL for admin/wallets/{id}
 */

$activationId = (int) ($activation['id'] ?? 0);
$binaryPoints = (int) ($activation['binary_points'] ?? 0);
$binaryTotal  = (float) ($activation['binary_total'] ?? 0);
$processedAt  = (string) ($activation['binary_processed_at'] ?? '');
$status       = (string) ($activation['status'] ?? '');

$awards   = $binary !== null ? (array) ($binary['awards'] ?? []) : [];
$volume   = $binary !== null ? (array) ($binary['volume'] ?? []) : [];
$points   = $binary !== null ? (int) ($binary['points'] ?? $binaryPoints) : $binaryPoints;
$pairs    = $binary !== null ? (int) ($binary['pairs'] ?? 0) : 0;
$amount   = $binary !== null ? (float) ($binary['amount'] ?? $binaryTotal) : $binaryTotal;

$teamRows = array_values(array_filter($volume, fn (array $r): bool => (string) ($r['kind'] ?? '') === 'team'));
$pairAwards = array_values(array_filter($awards, fn (array $a): bool => (string) ($a['kind'] ?? '') === 'pair'));

$hasBinary = $processedAt !== '' || $binaryPoints > 0 || $binaryTotal > 0.0;
$nothingPlaced = $hasBinary && $binaryPoints === 0 && $binaryTotal === 0.0;
$reason = '';
if ($nothingPlaced) {
    if ((string) ($activation['status'] ?? '') !== 'approved') {
        $reason = 'Not approved — no package binary volume placed.';
    } elseif ((int) ($activation['binary_points'] ?? 0) === 0) {
        $reason = 'No points were placed: the package is not binary-enabled or contributes 0 points.';
    } else {
        $reason = 'No points were placed: the member is not placed in the binary tree.';
    }
}
?>
                  <div class="card mb-6" data-live-region="activation-binary-<?= $activationId ?>">
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <h5 class="card-title mb-0">Package binary</h5>
                      <span class="badge bg-label-<?= $hasBinary ? 'success' : 'secondary' ?>">
                        <?= $hasBinary ? 'Processed' : 'Not processed' ?>
                      </span>
                    </div>
                    <div class="card-body">
<?php if (!$hasBinary): ?>
                      <p class="text-body-secondary small mb-0">
                        <strong>No package binary outcome.</strong> This activation has not been processed
                        by the package binary stream.
                      </p>
<?php elseif ($nothingPlaced): ?>
                      <p class="text-warning small mb-0">
                        <strong>Nothing placed.</strong> <?= e($reason) ?>
                      </p>
<?php else: ?>
                      <div class="d-flex align-items-baseline gap-2 mb-3">
                        <h3 class="mb-0"><?= e(money($amount)) ?></h3>
                        <span class="text-body-secondary small">paid in package pairs</span>
                      </div>

                      <div class="row g-3">
                        <div class="col-6 col-md-3">
                          <div class="text-body-secondary small">Points placed</div>
                          <div class="fw-semibold"><?= e(number_format($points, 0, '.', '')) ?> pts</div>
                        </div>
                        <div class="col-6 col-md-3">
                          <div class="text-body-secondary small">Pairs paid</div>
                          <div class="fw-semibold"><?= $pairs ?></div>
                        </div>
                        <div class="col-6 col-md-3">
                          <div class="text-body-secondary small">Volume rows</div>
                          <div class="fw-semibold"><?= count($teamRows) ?></div>
                        </div>
                        <div class="col-6 col-md-3">
                          <div class="text-body-secondary small">Award rows</div>
                          <div class="fw-semibold"><?= count($pairAwards) ?></div>
                        </div>
                        <div class="col-6 col-md-3">
                          <div class="text-body-secondary small">Processed at</div>
                          <div class="fw-semibold text-nowrap">
                            <?= $processedAt !== '' ? e(format_datetime($processedAt)) : '—' ?>
                          </div>
                        </div>
                        <div class="col-6 col-md-3">
                          <div class="text-body-secondary small">Activation status</div>
                          <div class="fw-semibold"><?= e(ucfirst($status)) ?></div>
                        </div>
                      </div>

<?php if ($pairAwards !== []): ?>
                      <div class="mb-3">
                        <div class="small text-uppercase text-body-secondary mb-1">Pair awards</div>
                        <table class="table table-sm align-middle mb-0">
                          <thead>
                            <tr>
                              <th scope="col" class="text-nowrap" style="width:22%">When</th>
                              <th scope="col" class="text-end" style="width:16%">Recipient</th>
                              <th scope="col" class="text-end" style="width:16%">Pairs</th>
                              <th scope="col" class="text-end" style="width:16%">Amount</th>
                              <th scope="col" class="text-end" style="width:14%">Status</th>
                              <th scope="col">Wallet</th>
                            </tr>
                          </thead>
                          <tbody>
<?php foreach ($pairAwards as $award): ?>
<?php
    $awardStatus = (string) ($award['status'] ?? '');
    $awardAmount = (float) ($award['amount'] ?? 0);
    $awardUser   = (string) ($award['recipient_username'] ?? '');
    $ledgerId    = (int) ($award['ledger_id'] ?? 0);
?>
                            <tr>
                              <td class="text-nowrap small">
                                <?= e(format_date((string) ($award['created_at'] ?? ''))) ?>
                              </td>
                              <td class="text-end text-nowrap small">
                                @<?= e($awardUser) ?>
                              </td>
                              <td class="text-end text-nowrap small fw-semibold">
                                <?= (int) ($award['pair_count'] ?? 0) ?>
                              </td>
                              <td class="text-end text-nowrap small fw-semibold">
                                <?= e(money($awardAmount)) ?>
                              </td>
                              <td class="text-nowrap small">
                                <?= status_badge($awardStatus, BinaryAward::STATUS_BADGES) ?>
                              </td>
                              <td class="text-body-secondary small text-end">
<?php if ($ledgerId > 0): ?>
                                <a href="<?= e($wallet_url . '/' . (int) ($award['user_id'] ?? 0)) ?>" class="text-body">
                                  #<?= $ledgerId ?>
                                </a>
<?php else: ?>
                                —
<?php endif; ?>
                              </td>
                            </tr>
<?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
<?php endif; ?>
<?php endif; ?>
                    </div>
                  </div>
