<?php
declare(strict_types=1);

/**
 * Printable sales invoice (plan §5.7, Appendix D; Phase 8; reference
 * views/shop/invoice.php).
 *
 * A standalone document — never rendered inside the app layout. Every
 * printed value comes from the frozen `shop_invoices` snapshot, not from
 * the live order or the catalogue, so re-printing months later shows the
 * same seller, prices and totals (P8-T6/T10).
 *
 * @var array{invoice: array<string, mixed>, items: list<array<string, mixed>>} $document
 * @var array<string, mixed> $order
 * @var string $back_url
 */

$invoice  = $document['invoice'] ?? [];
$items    = $document['items'] ?? [];
$backUrl  = (string) ($back_url ?? url('orders'));
$invoiceNo = (string) ($invoice['invoice_no'] ?? '');
$issuedAt  = (string) ($invoice['issued_at'] ?? '');
$voidedAt  = $invoice['voided_at'] ?? null;
$voided    = $voidedAt !== null;
$voidReason = (string) ($invoice['void_reason'] ?? '');

$sellerName    = (string) ($invoice['seller_name'] ?? '');
$sellerAddress = (string) ($invoice['seller_address'] ?? '');
$sellerTin     = (string) ($invoice['seller_tin'] ?? '');
$sellerPhone   = (string) ($invoice['seller_phone'] ?? '');

$billingName  = (string) ($invoice['billing_name'] ?? '');
$billingPhone = (string) ($invoice['billing_phone'] ?? '');
$buyerUser    = (string) ($invoice['buyer_username'] ?? '');
$shippingName = (string) ($invoice['shipping_name'] ?? '');
$shippingAddr = (string) ($invoice['shipping_address'] ?? '');

$subtotal = (float) ($invoice['subtotal'] ?? 0);
$shipping = (float) ($invoice['shipping_fee'] ?? 0);
$total    = (float) ($invoice['total'] ?? 0);
$paidAt   = $invoice['paid_at'] === null || $invoice['paid_at'] === '' ? '' : (string) $invoice['paid_at'];
$statusAtIssue = (string) ($invoice['status_at_issue'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="robots" content="noindex,nofollow" />
<title>Invoice <?= e($invoiceNo) ?> — <?= e($sellerName) ?></title>
<style>
  :root {
    --ink: #1f2937;
    --muted: #6b7280;
    --line: #d1d5db;
    --brand: #1d4ed8;
  }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; }
  body {
    font-family: "Segoe UI", Arial, Helvetica, sans-serif;
    color: var(--ink);
    background: #f3f4f6;
    font-size: 14px;
    line-height: 1.5;
  }
  .toolbar {
    max-width: 190mm;
    margin: 16px auto 0;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
  }
  .toolbar a, .toolbar button {
    font: inherit;
    font-size: 13px;
    padding: 8px 16px;
    border-radius: 6px;
    border: 1px solid var(--line);
    background: #fff;
    color: var(--ink);
    text-decoration: none;
    cursor: pointer;
  }
  .toolbar button.primary {
    background: var(--brand);
    border-color: var(--brand);
    color: #fff;
  }
  .sheet {
    position: relative;
    width: 190mm;
    min-height: 277mm;
    margin: 16px auto 32px;
    padding: 16mm 14mm;
    background: #fff;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12);
  }
  .void-stamp {
    position: absolute;
    top: 42%;
    left: 50%;
    transform: translate(-50%, -50%) rotate(-16deg);
    border: 6px solid #dc2626;
    color: #dc2626;
    font-size: 4rem;
    font-weight: 800;
    letter-spacing: 10px;
    padding: 0.1rem 1.2rem;
    opacity: 0.45;
    pointer-events: none;
  }
  .doc-head {
    display: flex;
    justify-content: space-between;
    gap: 24px;
    border-bottom: 2px solid var(--ink);
    padding-bottom: 14px;
  }
  .seller h1 { margin: 0 0 4px; font-size: 1.15rem; }
  .seller div { font-size: 0.8rem; color: var(--muted); line-height: 1.55; }
  .doc-title { text-align: right; flex-shrink: 0; }
  .doc-title h2 { margin: 0; letter-spacing: 2px; font-size: 1.25rem; }
  .doc-title .no { font-family: "Courier New", monospace; font-weight: 700; margin-top: 6px; }
  .doc-title .meta { font-size: 0.78rem; color: var(--muted); margin-top: 6px; }
  .parties { display: flex; gap: 28px; margin: 22px 0; }
  .parties > div { flex: 1; }
  .parties h3 {
    font-size: 0.68rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--muted);
    margin: 0 0 6px;
  }
  .parties p { margin: 0; font-size: 0.85rem; line-height: 1.55; }
  table.items { width: 100%; border-collapse: collapse; margin-top: 6px; }
  table.items th {
    text-align: left;
    font-size: 0.68rem;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    color: var(--muted);
    border-bottom: 1px solid var(--line);
    padding: 8px 6px;
  }
  table.items td {
    font-size: 0.85rem;
    padding: 8px 6px;
    border-bottom: 1px solid #f1f1f1;
    vertical-align: top;
  }
  .num { text-align: right; font-family: "Courier New", monospace; white-space: nowrap; }
  .totals { width: 72mm; margin-left: auto; margin-top: 14px; }
  .totals table { width: 100%; border-collapse: collapse; }
  .totals td { font-size: 0.86rem; padding: 5px 6px; }
  .totals td.num { text-align: right; }
  .totals tr.grand td { border-top: 2px solid var(--ink); font-weight: 700; font-size: 1rem; padding-top: 8px; }
  .paybox {
    margin-top: 22px;
    border: 1px solid var(--line);
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 0.82rem;
  }
  .paybox h3 {
    font-size: 0.68rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--muted);
    margin: 0 0 4px;
  }
  .foot {
    margin-top: 28px;
    padding-top: 10px;
    border-top: 1px solid var(--line);
    font-size: 0.75rem;
    color: var(--muted);
    line-height: 1.6;
  }
  .badge {
    display: inline-block;
    font-size: 0.7rem;
    padding: 2px 8px;
    border-radius: 999px;
    background: #eef2ff;
    color: var(--brand);
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }
  .badge-void { background: #fee2e2; color: #b91c1c; }
  @page { size: A4; margin: 14mm; }
  @media print {
    body { background: #fff; }
    .toolbar { display: none; }
    .sheet {
      width: auto;
      min-height: 0;
      margin: 0;
      padding: 0;
      box-shadow: none;
    }
    table.items tr { page-break-inside: avoid; }
  }
</style>
</head>
<body>
  <div class="toolbar">
    <a href="<?= e($backUrl) ?>"><i aria-hidden="true">&#8592;</i> Back to order</a>
    <button type="button" class="primary" onclick="window.print()">Print / Save as PDF</button>
  </div>

  <main class="sheet">
<?php if ($voided): ?>
    <div class="void-stamp" aria-hidden="true">VOID</div>
<?php endif; ?>

    <header class="doc-head">
      <div class="seller">
        <h1><?= e($sellerName) ?></h1>
<?php if ($sellerAddress !== ''): ?>
        <div><?= nl2br(e($sellerAddress)) ?></div>
<?php endif; ?>
<?php if ($sellerTin !== ''): ?>
        <div>TIN: <?= e($sellerTin) ?></div>
<?php endif; ?>
<?php if ($sellerPhone !== ''): ?>
        <div><?= e($sellerPhone) ?></div>
<?php endif; ?>
      </div>
      <div class="doc-title">
        <h2>SALES INVOICE</h2>
        <div class="no"><?= e($invoiceNo) ?></div>
        <div class="meta">
          Order <?= e((string) ($order['order_no'] ?? '')) ?><br />
          Issued <?= e(format_datetime($issuedAt)) ?>
<?php if ($paidAt !== ''): ?>
          <br />Paid <?= e(format_datetime($paidAt)) ?>
<?php endif; ?>
        </div>
      </div>
    </header>

    <section class="parties">
      <div>
        <h3>Billed to</h3>
        <p>
          <strong><?= e($billingName) ?></strong>
<?php if ($buyerUser !== ''): ?>
          <br />@<?= e($buyerUser) ?>
<?php endif; ?>
<?php if ($billingPhone !== ''): ?>
          <br /><?= e($billingPhone) ?>
<?php endif; ?>
        </p>
      </div>
      <div>
        <h3>Ship to</h3>
        <p>
          <strong><?= e($shippingName) ?></strong>
<?php if ($shippingAddr !== ''): ?>
          <br /><?= e($shippingAddr) ?>
<?php endif; ?>
        </p>
      </div>
      <div>
        <h3>Invoice</h3>
        <p>
          <?= e(payment_method_label((string) ($invoice['payment_method'] ?? ''))) ?>
          <br />Status at issue: <span class="badge<?= $voided ? ' badge-void' : '' ?>"><?= e(ucfirst(str_replace('_', ' ', $statusAtIssue))) ?></span>
<?php if ($voided): ?>
          <br />Voided <?= e(format_datetime((string) $voidedAt)) ?><?= $voidReason !== '' ? ' — ' . e($voidReason) : '' ?>
<?php endif; ?>
        </p>
      </div>
    </section>

    <table class="items">
      <thead>
        <tr>
          <th scope="col" style="width: 6%">#</th>
          <th scope="col">Description</th>
          <th scope="col" style="width: 14%">SKU</th>
          <th scope="col" class="num" style="width: 10%">Qty</th>
          <th scope="col" class="num" style="width: 16%">Unit price</th>
          <th scope="col" class="num" style="width: 16%">Amount</th>
        </tr>
      </thead>
      <tbody>
<?php if ($items === []): ?>
        <tr><td colspan="6">No line items.</td></tr>
<?php endif; ?>
<?php foreach ($items as $index => $line): ?>
        <tr>
          <td><?= (int) $index + 1 ?></td>
          <td><?= e((string) ($line['description'] ?? '')) ?></td>
          <td><?= e((string) ($line['sku'] ?? '') !== '' ? (string) $line['sku'] : '—') ?></td>
          <td class="num"><?= (int) ($line['quantity'] ?? 0) ?></td>
          <td class="num"><?= e(money((float) ($line['unit_price'] ?? 0))) ?></td>
          <td class="num"><?= e(money((float) ($line['amount'] ?? 0))) ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>

    <div class="totals">
      <table>
        <tr>
          <td>Subtotal</td>
          <td class="num"><?= e(money($subtotal)) ?></td>
        </tr>
        <tr>
          <td>Shipping</td>
          <td class="num"><?= $shipping > 0 ? e(money($shipping)) : 'Free' ?></td>
        </tr>
        <tr class="grand">
          <td>Total</td>
          <td class="num"><?= e(money($total)) ?></td>
        </tr>
      </table>
    </div>

    <div class="paybox">
      <h3>Payment</h3>
      <?= e(payment_method_label((string) ($invoice['payment_method'] ?? ''))) ?>
<?php if (trim((string) ($order['payment_reference'] ?? '')) !== ''): ?>
      · reference <?= e((string) $order['payment_reference']) ?>
<?php endif; ?>
<?php if ($paidAt !== ''): ?>
      · received <?= e(format_datetime($paidAt)) ?>
<?php endif; ?>
    </div>

    <footer class="foot">
      <div>This invoice is a frozen snapshot of order <?= e((string) ($order['order_no'] ?? '')) ?>; its prices and totals do not change.</div>
<?php if ($voided): ?>
      <div><strong>This invoice has been voided.</strong> The document is kept for the record only.</div>
<?php else: ?>
      <div>This document is not a VAT official receipt; a VAT receipt will be issued separately once that module is enabled.</div>
<?php endif; ?>
      <div>Thank you for shopping with <?= e($sellerName) ?>.</div>
    </footer>
  </main>
</body>
</html>
