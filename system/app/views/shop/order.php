<?php
declare(strict_types=1);

/**
 * One order — status, items, delivery, payment, proof attempts, shipment
 * and history (plan §5.3–5.7, Phases 6–8; reference views/member/shop_order.php).
 *
 * One live surface per order (`member.order:{id}`). The header, status card,
 * proof attempts, delivery, payment summary and history are regions; the
 * status card owns the proof/cancel/confirm forms, so it is held while the
 * member has unsaved input. The item snapshot never changes and stays static.
 *
 * @var array<string, mixed> $order
 * @var list<array<string, mixed>> $lines
 * @var list<array<string, mixed>> $events
 * @var bool $can_cancel
 * @var list<array<string, mixed>> $proofs
 * @var array<string, mixed>|null $latest_proof
 * @var array{open:bool, reason:string, deadline:?string, used:int, left:int, max:int} $window
 * @var array<string, mixed>|null $shipment
 * @var array<string, mixed>|null $invoice
 * @var bool $can_confirm
 * @var int $auto_days
 * @var string $status
 * @var int $order_id
 * @var bool $overdue
 * @var bool $window_open
 * @var string|null $window_deadline
 * @var bool $is_manual
 * @var array<string, string> $instructions
 * @var string $full_address
 * @var string $tracking
 * @var string $courier
 * @var bool $has_invoice
 * @var string $invoice_url
 * @var array{surface:string, enabled:bool, revision:int, etag:string, interval:int} $live
 */

/* The controller passes the derived screen data; a direct view render (a
 * test, a future caller) gets the same values from the raw order row. */
$status = $status ?? (string) $order['status'];
$order_id = $order_id ?? (int) $order['id'];
$proofs = $proofs ?? [];
$window = $window ?? [
    'open'     => false,
    'reason'   => 'status',
    'deadline' => null,
    'used'     => count($proofs),
    'left'     => 0,
    'max'      => 3,
];
$latest_proof = $latest_proof ?? ($proofs === [] ? null : $proofs[count($proofs) - 1]);
$shipment = $shipment ?? null;
$invoice  = $invoice ?? null;

$deadline = $order['payment_deadline'] !== null ? (string) $order['payment_deadline'] : '';
$overdue  = $overdue ?? ($deadline !== '' && strtotime($deadline) !== false && strtotime($deadline) < time());

$window_open     = $window_open ?? (bool) ($window['open'] ?? false);
$window_deadline = $window_deadline ?? ($window['deadline'] ?? null);
$is_manual       = $is_manual ?? ((string) $order['payment_method'] !== 'ewallet');
$can_cancel      = $can_cancel ?? Order::canMemberCancel($order);
$can_confirm     = $can_confirm ?? Order::canMemberConfirm($order);
$auto_days       = $auto_days ?? FulfilmentService::autoCompleteDays();

$instructions = $instructions ?? [];

if ($instructions === [] && $status === 'pending') {
    $instructions = match ((string) $order['payment_method']) {
        'gcash' => [
            'GCash number' => (string) setting('payment_gcash_number', ''),
            'Account name' => (string) setting('payment_gcash_name', ''),
        ],
        'maya' => [
            'Maya number'  => (string) setting('payment_maya_number', ''),
            'Account name' => (string) setting('payment_maya_name', ''),
        ],
        'bank' => [
            'Bank'           => (string) setting('payment_bank_name', ''),
            'Account name'   => (string) setting('payment_bank_account_name', ''),
            'Account number' => (string) setting('payment_bank_account_number', ''),
        ],
        default => [],
    };
}

$full_address = $full_address ?? implode(', ', array_filter([
    (string) $order['shipping_address'],
    (string) ($order['shipping_barangay'] ?? ''),
    (string) ($order['shipping_city'] ?? ''),
    (string) ($order['shipping_province'] ?? ''),
    (string) ($order['shipping_postal'] ?? ''),
]));

$tracking     = $tracking ?? ($shipment === null ? '' : trim((string) ($shipment['tracking_number'] ?? '')));
$courier      = $courier ?? ($shipment === null ? '' : trim((string) ($shipment['courier'] ?? '')));
$has_invoice  = $has_invoice ?? ($invoice !== null || Order::isPaid($order));
$invoice_url  = $invoice_url ?? url('orders/' . $order_id . '/invoice');

$live = $live ?? [
    'surface'  => 'member.order:' . $order_id,
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
              <?= flash_render() ?>

              <div class="text-end">
                <span class="small text-body-secondary" data-live-status aria-live="polite"></span>
              </div>

              <div
                class="alert alert-warning d-flex align-items-start gap-2 mb-4 d-none"
                data-live-stale
                role="status"
                aria-live="polite"
              >
                <i class="icon-base bx bx-error-circle fs-5"></i>
                <div>
                  <strong>This order changed in another tab.</strong>
                  Your unsaved input was kept, so the page may be out of date.
                  <a href="<?= e(url('orders/' . $order_id)) ?>" class="alert-link" data-live-reload>
                    Reload the order
                  </a>
                  to see the latest.
                </div>
              </div>

<?= View::capture('shop/order/_header', [
    'order'       => $order,
    'status'      => $status,
    'has_invoice' => $has_invoice,
    'invoice_url' => $invoice_url,
]) ?>

              <div class="row g-6">
                <div class="col-lg-8">
<?= View::capture('shop/order/_status', [
    'order'           => $order,
    'order_id'        => $order_id,
    'status'          => $status,
    'window'          => $window,
    'window_open'     => $window_open,
    'window_deadline' => $window_deadline,
    'is_manual'       => $is_manual,
    'overdue'         => $overdue,
    'latest_proof'    => $latest_proof,
    'instructions'    => $instructions,
    'can_confirm'     => $can_confirm,
    'can_cancel'      => $can_cancel,
    'auto_days'       => $auto_days,
    'courier'         => $courier,
    'tracking'        => $tracking,
]) ?>

<?= View::capture('shop/order/_proofs', [
    'proofs' => $proofs,
    'window' => $window,
]) ?>

                  <div class="card">
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <h5 class="mb-0">Items</h5>
                      <span class="badge bg-label-primary"><?= count($lines) ?> line<?= count($lines) === 1 ? '' : 's' ?></span>
                    </div>
<?= View::partial('order_items', ['order' => $order, 'lines' => $lines]) ?>
                  </div>
                </div>

                <div class="col-lg-4">
<?= View::capture('shop/order/_delivery', [
    'order'        => $order,
    'shipment'     => $shipment,
    'tracking'     => $tracking,
    'courier'      => $courier,
    'full_address' => $full_address,
]) ?>

<?= View::capture('shop/order/_payment', [
    'order'       => $order,
    'has_invoice' => $has_invoice,
    'invoice_url' => $invoice_url,
]) ?>

<?= View::capture('shop/order/_bonus', ['order' => $order]) ?>

<?= View::capture('shop/order/_history', ['events' => $events]) ?>
                </div>
              </div>
            </div>
