<?php
declare(strict_types=1);

/**
 * Checkout — the product-binary position card (22F, plan §14.6,
 * Appendix C.2).
 *
 * A radio choice between Left and Right, required when the cart carries
 * eligible points. The position is snapshotted on the order at placement
 * and never changes after. Member copy is downward-only: the card states
 * the buyer's own outcome (points, self bonus, own-node pairs) and never
 * names an upline or sponsor.
 *
 * @var array<string, mixed>|null $binary_preview  BinaryService::quoteCart()
 * @var string                    $binary_position posted position value ('' | 'left' | 'right')
 * @var bool                      $is_placed       the member has a binary node
 * @var bool                      $stream_on       BinaryService::enabled()
 */

$preview = $binary_preview ?? null;
$streamOn = (bool) $stream_on;
$isPlaced = (bool) $is_placed;
$position = (string) ($binary_position ?? '');

$eligible = $preview !== null && (bool) ($preview['eligible'] ?? false);
$points   = (int) ($preview['points'] ?? 0);
$self     = (float) ($preview['self'] ?? 0.0);
$selfPct  = round((float) setting_float('binary_self_pct', 5.0), 3);

/* Per-side quote: how many pairs this cart would make if placed on that
 * side, against the buyer's current node balances. */
$leftQuote  = $streamOn && $isPlaced && $eligible
    ? (new BinaryService())->quotePosition(
        $preview['lines'] ?? [], 'left', null, (int) (auth_user()['id'] ?? 0)
    )
    : ['side' => 'left', 'points' => 0, 'pairs' => 0, 'amount' => 0.0, 'left' => 0, 'right' => 0];
$rightQuote = $streamOn && $isPlaced && $eligible
    ? (new BinaryService())->quotePosition(
        $preview['lines'] ?? [], 'right', null, (int) (auth_user()['id'] ?? 0)
    )
    : ['side' => 'right', 'points' => 0, 'pairs' => 0, 'amount' => 0.0, 'left' => 0, 'right' => 0];

$hasError = (bool) (has_error('binary_position') ?: false);
$positionError = $hasError ? e(error_for('binary_position')) : '';
?>
<?php if (!$streamOn || !$eligible || !$isPlaced): ?>
    <?php if (!$streamOn): ?>
        <?php return; ?>
    <?php endif; ?>
    <?php if (!$eligible): ?>
        <?php return; ?>
    <?php endif; ?>
    <?php /* !$isPlaced: honest unplaced notice, no radio choice */ ?>
    <div class="card mb-6 checkout-binary-card" data-live-region="checkout-binary-unplaced">
        <div class="card-body">
            <div class="d-flex align-items-start gap-3">
                <span class="avatar avatar-sm flex-shrink-0">
                    <span class="avatar-initial rounded-circle bg-label-secondary">
                        <i class="icon-base bx bx-user-x"></i>
                    </span>
                </span>
                <div>
                    <h6 class="mb-1">Product binary</h6>
                    <p class="text-body-secondary mb-2">
                        You are not placed in the binary tree yet, so this order will not place points.
                        Ask your upline or an administrator to place you.
                    </p>
                    <p class="small text-body-secondary mb-0">
                        Once you are placed, every repeat purchase can go on the left or the right of
                        your tree and count towards your next pair.
                    </p>
                </div>
            </div>
        </div>
    </div>
<?php return; ?>
<?php endif; ?>

<div class="card mb-6 checkout-binary-card" data-live-region="checkout-binary-position">
    <div class="card-header d-flex align-items-center justify-content-between gap-2">
        <h5 class="mb-0">Place this purchase</h5>
    </div>
    <div class="card-body">
        <p class="text-body-secondary small mb-3">
            Choose the side of your binary tree this order is placed on. Your points count towards
            your next pair.
        </p>

        <div class="row g-3">
            <div class="col-6">
                <div class="form-check position-choice<?= $hasError ? ' is-invalid' : '' ?>">
                    <input
                        class="form-check-input"
                        type="radio"
                        name="binary_position"
                        id="binary-position-left"
                        value="left"
                        <?= $position === 'left' ? 'checked' : '' ?>
                        <?= $hasError ? 'aria-invalid="true"' : '' ?>
                    />
                    <label class="form-check-label" for="binary-position-left">
                        <strong>Left</strong>
                        <span class="d-block small text-body-secondary mt-1">
                            <?= e((string) $leftQuote['points']) ?> pts ·
                            you earn <?= e(money($self)) ?> on this order ·
                            <?= e((string) (int) $leftQuote['pairs']) ?> pair<?= (int) $leftQuote['pairs'] === 1 ? '' : 's' ?> now
                        </span>
                    </label>
                </div>
            </div>
            <div class="col-6">
                <div class="form-check position-choice<?= $hasError ? ' is-invalid' : '' ?>">
                    <input
                        class="form-check-input"
                        type="radio"
                        name="binary_position"
                        id="binary-position-right"
                        value="right"
                        <?= $position === 'right' ? 'checked' : '' ?>
                        <?= $hasError ? 'aria-invalid="true"' : '' ?>
                    />
                    <label class="form-check-label" for="binary-position-right">
                        <strong>Right</strong>
                        <span class="d-block small text-body-secondary mt-1">
                            <?= e((string) $rightQuote['points']) ?> pts ·
                            you earn <?= e(money($self)) ?> on this order ·
                            <?= e((string) (int) $rightQuote['pairs']) ?> pair<?= (int) $rightQuote['pairs'] === 1 ? '' : 's' ?> now
                        </span>
                    </label>
                </div>
            </div>
        </div>

        <?php if ($hasError): ?>
            <div class="invalid-feedback d-block mt-2"><?= $positionError ?></div>
        <?php endif; ?>

        <p class="small text-body-secondary mt-3 mb-0">
            <strong>The position is final once the order is placed.</strong>
        </p>
    </div>
</div>
