<?php
declare(strict_types=1);

/**
 * Admin — one order: member, payment review, fulfilment, items, delivery
 * and history (plan §7 Phases 6–8; reference views/admin/shop_order.php).
 *
 * The page is one live surface. Each card is a region rendered by the same
 * partial the JSON feed uses; the two cards that own forms carry
 * data-live-hold="dirty" so the client keeps unsaved input and shows the
 * "changed in another tab" banner instead of replacing the user's fields.
 *
 * @var array<string, mixed>|null $ctx
 *
 * Standalone rendering (tests, future callers) may pass the flat variables
 * instead of $ctx; the fallback below still renders the whole screen.
 *
 * @var array<string, mixed> $order
 * @var list<array<string, mixed>>|null $lines
 * @var list<array<string, mixed>>|null $events
 * @var array<string, mixed>|null $member
 * @var list<array<string, mixed>>|null $proofs
 * @var array<string, mixed>|null $latest_proof
 * @var array{open:bool, reason:string, deadline:?string, used:int, left:int, max:int}|null $window
 * @var bool|null $is_self
 * @var bool|null $can_review
 * @var bool|null $can_cancel
 * @var bool|null $refund_on_cancel
 * @var array<string, string>|null $reject_reasons
 * @var array<string, string>|null $cancel_reasons
 * @var array<string, mixed>|null $shipment
 * @var array<string, mixed>|null $invoice
 * @var bool|null $can_pack
 * @var bool|null $can_ready
 * @var bool|null $can_ship
 * @var bool|null $can_deliver
 * @var bool|null $can_confirm
 * @var int|null $auto_days
 * @var array{surface:string, enabled:bool, revision:int, etag:string, interval:int}|null $live
 */

if (!isset($ctx) || !is_array($ctx)) {
    $order      = $order ?? [];
    $orderId    = (int) ($order['id'] ?? 0);
    $status     = (string) ($order['status'] ?? '');
    $flatProofs = $proofs ?? [];
    $flatShip   = $shipment ?? null;

    $ctx = [
        'order'            => $order,
        'lines'            => $lines ?? [],
        'events'           => $events ?? [],
        'member'           => $member ?? null,
        'proofs'           => $flatProofs,
        'latest_proof'     => $latest_proof
            ?? ($flatProofs === [] ? null : $flatProofs[count($flatProofs) - 1]),
        'window'           => $window
            ?? ['open' => false, 'reason' => 'status', 'deadline' => null, 'used' => count($flatProofs), 'left' => 0, 'max' => 3],
        'is_self'          => (bool) ($is_self ?? false),
        'can_review'       => (bool) ($can_review ?? false),
        'can_cancel'       => (bool) ($can_cancel ?? false),
        'refund_on_cancel' => (bool) ($refund_on_cancel ?? false),
        'reject_reasons'   => $reject_reasons ?? PaymentService::REJECT_REASONS,
        'cancel_reasons'   => $cancel_reasons ?? PaymentService::CANCEL_REASONS,
        'shipment'         => $shipment ?? null,
        'invoice'          => $invoice ?? null,
        'has_invoice'      => ($invoice ?? null) !== null || Order::isPaid($order),
        'invoice_url'      => url('admin/orders/' . $orderId . '/invoice'),
        'can_pack'         => (bool) ($can_pack ?? $status === 'paid'),
        'can_ready'        => (bool) ($can_ready ?? $status === 'packing'),
        'can_ship'         => (bool) ($can_ship ?? $status === 'ready_to_ship'),
        'can_deliver'      => (bool) ($can_deliver ?? $status === 'shipped'),
        'can_confirm'      => (bool) ($can_confirm ?? false),
        'auto_days'        => (int) ($auto_days ?? FulfilmentService::autoCompleteDays()),
        'courier'          => $flatShip === null ? '' : trim((string) ($flatShip['courier'] ?? '')),
        'tracking'         => $flatShip === null ? '' : trim((string) ($flatShip['tracking_number'] ?? '')),
        'courier_choice'   => old('courier'),
        'full_address'     => implode(', ', array_filter([
            (string) ($order['shipping_address'] ?? ''),
            (string) ($order['shipping_barangay'] ?? ''),
            (string) ($order['shipping_city'] ?? ''),
            (string) ($order['shipping_province'] ?? ''),
            (string) ($order['shipping_postal'] ?? ''),
        ])),
    ];
}

$order   = $ctx['order'];
$orderId = (int) $order['id'];
$isSelf  = (bool) $ctx['is_self'];

$live = $live ?? [
    'surface'  => Live::orderSurface($orderId),
    'enabled'  => false,
    'revision' => 0,
    'etag'     => '',
    'interval' => 30000,
];
?>
            <div
              class="container-xxl flex-grow-1 container-p-y order-detail-page"
              data-live-surface="<?= e($live['surface']) ?>"
              data-live-enabled="<?= $live['enabled'] ? '1' : '0' ?>"
              data-live-revision="<?= (int) $live['revision'] ?>"
              data-live-etag="<?= e((string) $live['etag']) ?>"
              data-live-interval="<?= (int) $live['interval'] ?>"
            >
              <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-6">
                <div>
                  <div class="d-flex flex-wrap align-items-center gap-2 small text-body-secondary mb-1">
                    <a href="<?= e(url('admin/orders')) ?>" class="text-body-secondary">Orders</a>
                    <i class="icon-base bx bx-chevron-right"></i>
                    <span><?= e((string) $order['order_no']) ?></span>
                    <span class="small text-body-secondary" data-live-status aria-live="polite"></span>
                  </div>
                  <h4 class="mb-1">Order <?= e((string) $order['order_no']) ?></h4>
                  <p class="text-body-secondary mb-0">
                    Placed <?= e(format_datetime((string) $order['created_at'])) ?>
                    · <?= e(payment_method_label((string) $order['payment_method'])) ?>
                  </p>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-2">
<?= View::capture('admin/orders/_status', ['ctx' => $ctx]) ?>
                  <a href="<?= e(url('admin/orders')) ?>" class="btn btn-label-secondary">
                    <i class="icon-base bx bx-arrow-back me-1"></i> Back to orders
                  </a>
                </div>
              </div>

<?php if ($isSelf): ?>
              <div class="alert alert-warning d-flex align-items-start gap-2 mb-6" role="alert">
                <i class="icon-base bx bx-shield-x fs-5"></i>
                <div>
                  <strong>You placed this order.</strong>
                  Payment review, rejection and cancellation are disabled for your own order — another
                  administrator has to act on it.
                </div>
              </div>
<?php endif; ?>

              <div
                class="alert alert-warning d-flex align-items-start gap-2 mb-6 d-none"
                data-live-stale
                role="status"
                aria-live="polite"
              >
                <i class="icon-base bx bx-error-circle fs-5"></i>
                <div>
                  <strong>Another administrator updated this order.</strong>
                  Your unsaved changes were kept, so the action forms may be out of date.
                  <a href="<?= e(url('admin/orders/' . $orderId)) ?>" class="alert-link" data-live-reload>Reload the order</a>
                  to see the latest actions.
                </div>
              </div>

              <div class="row g-6">
                <div class="col-lg-8">
<?= View::capture('admin/orders/_payment', ['ctx' => $ctx]) ?>

<?= View::capture('admin/orders/_fulfilment', ['ctx' => $ctx]) ?>

<?= View::capture('admin/orders/_items', ['ctx' => $ctx]) ?>

<?= View::capture('admin/orders/_timeline', ['ctx' => $ctx]) ?>
                </div>

                <div class="col-lg-4">
<?= View::capture('admin/orders/_member', ['ctx' => $ctx]) ?>

<?= View::capture('admin/orders/_delivery', ['ctx' => $ctx]) ?>

<?= View::capture('admin/orders/_shipment', ['ctx' => $ctx]) ?>

<?= View::capture('admin/orders/_payment_summary', ['ctx' => $ctx]) ?>

<?= View::capture('admin/orders/_cancel', ['ctx' => $ctx]) ?>
                </div>
              </div>
            </div>
