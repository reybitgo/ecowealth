<?php
/**
 * @file   views/shop/invoice.php
 * @brief  Printable sales invoice for a shop order. Shared by the admin desk
 *         (AdminController::orderInvoice) and the buyer
 *         (MemberController::shopInvoice). Expects: $doc — an invoice document
 *         payload from ShopInvoice::docForOrder() (frozen snapshot), or
 *         ShopInvoice::build() (live preview). $doc['mode'] is one of
 *         preview | issued | void; issued/void render the frozen fields,
 *         preview renders live order data with an "issued on payment" note.
 */
$mode       = (string) ($doc['mode'] ?? 'preview');
$orderNo    = (string) $doc['order']['order_no'];
$order      = $doc['order'];
$items      = $doc['items'] ?? [];
$buyer      = is_array($doc['buyer'] ?? null) ? $doc['buyer'] : [];
$seller     = is_array($doc['seller'] ?? null) ? $doc['seller'] : [];
$subtotal   = (float) ($doc['subtotal'] ?? 0.0);
$total      = (float) ($doc['total'] ?? 0.0);
$amountPaid = (float) ($doc['amount_paid'] ?? 0.0);
$issued     = $mode !== 'preview';
$void       = $mode === 'void';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Invoice <?= e($orderNo) ?> — <?= e((string) $seller['name']) ?></title>
<meta name="robots" content="noindex,nofollow">
<style>
  :root { --ink:#1f2937; --muted:#6b7280; --line:#d1d5db; --brand:#1d4ed8; }
  * { box-sizing: border-box; }
  body { font-family: 'Segoe UI', Arial, sans-serif; color: var(--ink); margin: 0; background:#f3f4f6; }
  .sheet { position:relative; max-width: 820px; margin: 24px auto; background:#fff; padding: 40px; box-shadow:0 1px 3px rgba(0,0,0,.1); }
  .void-stamp { position:absolute; top:38%; left:50%; transform:translate(-50%,-50%) rotate(-16deg); border:6px solid #dc2626; color:#dc2626; font-size:4.5rem; font-weight:800; letter-spacing:10px; padding:.1rem 1.4rem; opacity:.5; pointer-events:none; }
  .toolbar { max-width: 820px; margin: 16px auto 0; display:flex; gap:8px; justify-content:flex-end; }
  .toolbar button { font:inherit; padding:8px 16px; border-radius:6px; border:1px solid var(--line); background:#fff; cursor:pointer; }
  .toolbar button.primary { background: var(--brand); border-color: var(--brand); color:#fff; }
  .doc-head { display:flex; justify-content:space-between; gap:24px; border-bottom:2px solid var(--ink); padding-bottom:16px; }
  .seller h1 { margin:0 0 4px; font-size:1.15rem; }
  .seller div { font-size:.8rem; color:var(--muted); line-height:1.5; }
  .doc-title { text-align:right; }
  .doc-title h2 { margin:0; letter-spacing:2px; font-size:1.3rem; }
  .doc-title .no { font-family:'JetBrains Mono', monospace; font-weight:600; margin-top:4px; }
  .doc-title .meta { font-size:.78rem; color:var(--muted); margin-top:6px; }
  .parties { display:flex; gap:32px; margin:24px 0; }
  .parties > div { flex:1; }
  .parties h3 { font-size:.7rem; text-transform:uppercase; letter-spacing:1px; color:var(--muted); margin:0 0 6px; }
  .parties div div { font-size:.84rem; line-height:1.5; }
  table.items { width:100%; border-collapse:collapse; margin-top:8px; }
  table.items th { text-align:left; font-size:.7rem; text-transform:uppercase; letter-spacing:.5px; color:var(--muted); border-bottom:1px solid var(--line); padding:8px 6px; }
  table.items td { font-size:.85rem; padding:8px 6px; border-bottom:1px solid #f1f1f1; vertical-align:top; }
  .num { text-align:right; font-family:'JetBrains Mono', monospace; }
  table.items th.num, table.items td.num { text-align:left; }
  .totals { width:280px; margin-left:auto; margin-top:16px; }
  .totals tr td { font-size:.86rem; padding:5px 6px; }
  .totals tr.grand td { border-top:2px solid var(--ink); font-weight:700; font-size:1rem; padding-top:8px; }
  .paybox { margin-top:24px; border:1px solid var(--line); border-radius:8px; padding:12px 16px; font-size:.82rem; }
  .paybox h3 { font-size:.7rem; text-transform:uppercase; letter-spacing:1px; color:var(--muted); margin:0 0 6px; }
  .foot { margin-top:32px; padding-top:12px; border-top:1px solid var(--line); font-size:.75rem; color:var(--muted); line-height:1.6; }
  .badge { display:inline-block; font-size:.7rem; padding:2px 8px; border-radius:999px; background:#eef2ff; color:var(--brand); text-transform:uppercase; letter-spacing:.5px; }
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
  <?php if ($void): ?><div class="void-stamp" aria-hidden="true">VOID</div><?php endif; ?>
  <div class="doc-head">
    <div class="seller">
      <h1><?= e((string) ($seller['name'] ?? '')) ?></h1>
      <?php if (!empty($seller['address'])): ?><div><?= nl2br(e((string) $seller['address'])) ?></div><?php endif; ?>
      <?php if (!empty($seller['tin'])): ?><div>TIN: <?= e((string) $seller['tin']) ?></div><?php endif; ?>
      <?php if (!empty($seller['phone'])): ?><div><?= e((string) $seller['phone']) ?></div><?php endif; ?>
      <?php if (!empty($seller['email'])): ?><div><?= e((string) $seller['email']) ?></div><?php endif; ?>
    </div>
    <div class="doc-title">
      <h2>SALES INVOICE</h2>
      <?php if ($issued): ?>
        <div class="no"><?= e((string) $doc['invoice_no']) ?></div>
        <div class="meta">
          Invoice for <?= e($orderNo) ?><br>
          Issued: <?= e(fmt_date((string) $doc['issued_at'])) ?><br>
          Status: <span class="badge"><?= e(str_replace('_', ' ', (string) $order['status'])) ?></span>
          <?php if ($void): ?>
            <br><span style="color:#dc2626;">Voided <?= e(fmt_date((string) $doc['voided_at'])) ?> — <?= e((string) ($doc['void_reason'] ?? '')) ?></span>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="no"><?= e($orderNo) ?></div>
        <div class="meta">
          Date: <?= e(fmt_date((string) ($order['paid_at'] !== '' ? $order['paid_at'] : $order['created_at']))) ?><br>
          Status: <span class="badge"><?= e(str_replace('_', ' ', (string) $order['status'])) ?></span>
          <br><span style="font-style:italic;">Preview — issued and frozen on payment</span>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="parties">
    <div>
      <h3>Billed To</h3>
      <div>
        <strong><?= e((string) ($order['billing_name'] ?? '') ?: (string) ($buyer['username'] ?? '')) ?></strong><br>
        <?php if (!empty($buyer['username'])): ?>@<?= e((string) $buyer['username']) ?><br><?php endif; ?>
        <?php if (!empty($order['billing_phone'])): ?><?= e((string) $order['billing_phone']) ?><br><?php endif; ?>
        <?php if (!empty($order['billing_email'])): ?><?= e((string) $order['billing_email']) ?><?php endif; ?>
      </div>
    </div>
    <div>
      <h3>Ship To</h3>
      <div>
        <strong><?= e((string) ($order['shipping_name'] ?? '')) ?></strong><br>
        <?php if (!empty($order['shipping_phone'])): ?><?= e((string) $order['shipping_phone']) ?><br><?php endif; ?>
        <?= e((string) ($order['shipping_address'] ?? '')) ?><br>
        <?= e(trim(implode(' ', array_filter([
            (string) ($order['shipping_city'] ?? ''),
            (string) ($order['shipping_province'] ?? ''),
            (string) ($order['shipping_postal'] ?? ''),
            (string) ($order['shipping_country'] ?? ''),
        ])))) ?>
      </div>
    </div>
  </div>

  <table class="items">
    <thead>
      <tr>
        <th style="width:46%;">Description</th>
        <th>SKU</th>
        <th class="num">Qty</th>
        <th class="num">Unit Price</th>
        <th class="num">Amount</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <td><?= e((string) $it['product_name']) ?></td>
          <td><?= e((string) ($it['product_sku'] ?? '—')) ?></td>
          <td class="num"><?= (int) $it['quantity'] ?></td>
          <td class="num"><?= fmt_money((float) $it['unit_price']) ?></td>
          <td class="num"><?= fmt_money((float) $it['total_price']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$items): ?>
        <tr><td colspan="5" style="color:var(--muted);">No line items recorded.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>

  <table class="totals">
    <tr><td>Subtotal</td><td class="num"><?= fmt_money($subtotal) ?></td></tr>
    <tr class="grand"><td>Total</td><td class="num"><?= fmt_money($total) ?></td></tr>
    <tr><td>Amount Paid</td><td class="num"><?= fmt_money($amountPaid) ?></td></tr>
  </table>

  <div class="paybox">
    <h3>Payment</h3>
    Method: <strong><?= e(shop_payment_method_label((string) $order['payment_method'])) ?></strong>
    <?php if (!empty($order['payment_reference'])): ?>
      · Reference: <span style="font-family:'JetBrains Mono', monospace;"><?= e((string) $order['payment_reference']) ?></span>
    <?php endif; ?>
    <?php if (!empty($order['paid_at'])): ?>
      · Paid <?= e(fmt_datetime((string) $order['paid_at'])) ?>
    <?php endif; ?>
    <?php if (!empty($order['notes_member'])): ?>
      <div style="margin-top:6px;color:var(--muted);">Buyer note: <?= e((string) $order['notes_member']) ?></div>
    <?php endif; ?>
  </div>

  <div class="foot">
    <?= e((string) $seller['vat']) ?><br>
    <?= e((string) $seller['footer']) ?>
  </div>
</div>
</body>
</html>
