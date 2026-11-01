<?php
/**
 * @file   views/shop/refund_receipt.php
 * @brief  Printable credit note / refund receipt for a refund task. Rendered
 *         from AdminController::orderRefundReceipt. Expects: $refund, $order,
 *         $buyer, $seller.
 */
$orderNo   = (string) $order['order_no'];
$buyer     = is_array($buyer) ? $buyer : [];
$receiptNo = trim((string) ($refund['receipt_ref'] ?? ''));
if ($receiptNo === '') {
    $receiptNo = shop_credit_note_no($orderNo, (int) $refund['id']);
}
$method  = (string) $refund['method'];
$isCash  = $method !== 'ewallet';
$issued  = (string) ($refund['approved_at'] ?: $refund['created_at']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Credit Note <?= e($receiptNo) ?> — <?= e((string) $seller['name']) ?></title>
<meta name="robots" content="noindex,nofollow">
<style>
  :root { --ink:#1f2937; --muted:#6b7280; --line:#d1d5db; --brand:#b91c1c; }
  * { box-sizing: border-box; }
  body { font-family: 'Segoe UI', Arial, sans-serif; color: var(--ink); margin:0; background:#f3f4f6; }
  .sheet { max-width: 820px; margin: 24px auto; background:#fff; padding: 40px; box-shadow:0 1px 3px rgba(0,0,0,.1); }
  .toolbar { max-width: 820px; margin: 16px auto 0; display:flex; gap:8px; justify-content:flex-end; }
  .toolbar button { font:inherit; padding:8px 16px; border-radius:6px; border:1px solid var(--line); background:#fff; cursor:pointer; }
  .toolbar button.primary { background: var(--brand); border-color: var(--brand); color:#fff; }
  .doc-head { display:flex; justify-content:space-between; gap:24px; border-bottom:2px solid var(--ink); padding-bottom:16px; }
  .seller h1 { margin:0 0 4px; font-size:1.15rem; }
  .seller div { font-size:.8rem; color:var(--muted); line-height:1.5; }
  .doc-title { text-align:right; }
  .doc-title h2 { margin:0; letter-spacing:2px; font-size:1.15rem; color:var(--brand); }
  .doc-title .no { font-family:'JetBrains Mono', monospace; font-weight:600; margin-top:4px; }
  .doc-title .meta { font-size:.78rem; color:var(--muted); margin-top:6px; }
  .parties { display:flex; gap:32px; margin:24px 0; }
  .parties > div { flex:1; }
  .parties h3 { font-size:.7rem; text-transform:uppercase; letter-spacing:1px; color:var(--muted); margin:0 0 6px; }
  .parties div div { font-size:.84rem; line-height:1.5; }
  table.figures { width:100%; border-collapse:collapse; margin-top:8px; }
  table.figures td { font-size:.86rem; padding:9px 6px; border-bottom:1px solid #f1f1f1; }
  table.figures td:last-child { text-align:right; font-family:'JetBrains Mono', monospace; }
  table.figures tr.grand td { border-top:2px solid var(--ink); font-weight:700; font-size:1rem; padding-top:10px; color:var(--brand); }
  .reason { margin-top:20px; border:1px solid var(--line); border-radius:8px; padding:12px 16px; font-size:.84rem; }
  .reason h3 { font-size:.7rem; text-transform:uppercase; letter-spacing:1px; color:var(--muted); margin:0 0 6px; }
  .ack { margin-top:28px; display:flex; gap:40px; }
  .ack > div { flex:1; }
  .ack .line { border-bottom:1px solid var(--ink); height:36px; }
  .ack .cap { font-size:.72rem; color:var(--muted); margin-top:4px; }
  .foot { margin-top:32px; padding-top:12px; border-top:1px solid var(--line); font-size:.75rem; color:var(--muted); line-height:1.6; }
  .badge { display:inline-block; font-size:.7rem; padding:2px 8px; border-radius:999px; background:#fee2e2; color:var(--brand); text-transform:uppercase; letter-spacing:.5px; }
  @media print {
    body { background:#fff; }
    .toolbar { display:none; }
    .sheet { box-shadow:none; margin:0; max-width:none; padding:0; }
  }
</style>
</head>
<body>
<div class="toolbar">
  <button onclick="window.close()">Close</button>
  <button class="primary" onclick="window.print()">🖨 Print / Save as PDF</button>
</div>

<div class="sheet">
  <div class="doc-head">
    <div class="seller">
      <h1><?= e((string) $seller['name']) ?></h1>
      <?php if (!empty($seller['address'])): ?><div><?= nl2br(e((string) $seller['address'])) ?></div><?php endif; ?>
      <?php if (!empty($seller['tin'])): ?><div>TIN: <?= e((string) $seller['tin']) ?></div><?php endif; ?>
      <?php if (!empty($seller['phone'])): ?><div><?= e((string) $seller['phone']) ?></div><?php endif; ?>
      <?php if (!empty($seller['email'])): ?><div><?= e((string) $seller['email']) ?></div><?php endif; ?>
    </div>
    <div class="doc-title">
      <h2>CREDIT NOTE / REFUND RECEIPT</h2>
      <div class="no"><?= e($receiptNo) ?></div>
      <div class="meta">
        Date: <?= e(fmt_date($issued)) ?><br>
        Status: <span class="badge"><?= e(str_replace('_', ' ', (string) $refund['status'])) ?></span>
      </div>
    </div>
  </div>

  <div class="parties">
    <div>
      <h3>Refund To</h3>
      <div>
        <strong><?= e((string) ($order['billing_name'] ?? '') ?: (string) ($buyer['username'] ?? '')) ?></strong><br>
        <?php if (!empty($buyer['username'])): ?>@<?= e((string) $buyer['username']) ?><br><?php endif; ?>
        <?php if (!empty($order['billing_phone'])): ?><?= e((string) $order['billing_phone']) ?><br><?php endif; ?>
        <?php if (!empty($order['billing_email'])): ?><?= e((string) $order['billing_email']) ?><?php endif; ?>
      </div>
    </div>
    <div>
      <h3>Original Invoice</h3>
      <div>
        Invoice: <span style="font-family:'JetBrains Mono', monospace;"><?= e($orderNo) ?></span><br>
        Placed: <?= e(fmt_date((string) $order['created_at'])) ?><br>
        Order total: <?= fmt_money((float) $order['total_price']) ?>
      </div>
    </div>
  </div>

  <table class="figures">
    <tr><td>Refund amount</td><td><?= fmt_money((float) $refund['amount']) ?></td></tr>
    <tr><td>Reason</td><td style="font-family:inherit;"><?= e(shop_refund_cause_label((string) $refund['cause'])) ?></td></tr>
    <tr><td>Settlement route</td><td style="font-family:inherit;"><?= e(shop_refund_method_label($method)) ?></td></tr>
    <tr class="grand"><td>Total refunded</td><td><?= fmt_money((float) $refund['amount']) ?></td></tr>
  </table>

  <div class="reason">
    <h3>Settlement</h3>
    <?php if ($method === 'ewallet'): ?>
      Credited to the member's e-wallet on
      <?= e(fmt_datetime((string) ($refund['processed_at'] ?: $refund['approved_at']))) ?>.
    <?php else: ?>
      Paid out via <?= e(shop_refund_method_label($method)) ?>
      <?php if (!empty($refund['destination'])): ?> to <?= e((string) $refund['destination']) ?><?php endif; ?>
      <?php if (!empty($refund['processed_at'])): ?> on <?= e(fmt_datetime((string) $refund['processed_at'])) ?><?php endif; ?>.
    <?php endif; ?>
    <?php if (!empty($refund['note'])): ?>
      <div style="margin-top:6px;color:var(--muted);">Note: <?= e((string) $refund['note']) ?></div>
    <?php endif; ?>
  </div>

  <?php if ($isCash): ?>
    <div class="ack">
      <div>
        <div class="line"></div>
        <div class="cap">Received by (name &amp; signature)</div>
      </div>
      <div>
        <div class="line"></div>
        <div class="cap">Date received</div>
      </div>
    </div>
  <?php endif; ?>

  <div class="foot">
    Reference this credit note (<strong><?= e($receiptNo) ?></strong>) in any correspondence about invoice <?= e($orderNo) ?>.<br>
    <?= e((string) $seller['footer']) ?>
  </div>
</div>
</body>
</html>
