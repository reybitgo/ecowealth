<?php

/**
 * Immutable sales-invoice snapshots.
 *
 * An invoice is frozen at the moment the order reaches `paid`:
 * ShopOrder::applySideEffects() calls issue() inside the transition's own
 * transaction, so the snapshot commits with the status change or not at all.
 * Rows are append-only — the printed columns are never UPDATEd; only
 * voided_at/void_reason change (payment reverted, order cancelled). Re-payment
 * after a void appends a new row (invoice_no INV-…-R2, …).
 *
 * docForOrder() is the read entry point: snapshot when one exists, live build
 * (preview) otherwise — the "live while open, frozen on issue" contract.
 */
class ShopInvoice
{
    /** Bump when the printed layout/column set changes (new rows only). */
    public const VERSION = 1;

    /**
     * Build the document payload from live data. Shared by the pre-payment
     * preview and by issue() — the "build invoice from order" function.
     *
     * Returned shape ($doc) — load() must mirror it exactly:
     *   mode           'preview'
     *   invoice_no     null (issue() stamps it into the row)
     *   issued_at/voided_at/void_reason  null
     *   order          order_no, status, created_at, paid_at, payment_*,
     *                  notes_member, billing_*, shipping_*, total_price
     *   buyer          username
     *   seller         shop_seller_identity() (name, address, tin, phone,
     *                  email, footer, vat, currency)
     *   items          product_name, product_sku, quantity, unit_price, total_price
     *   subtotal/total/amount_paid  float
     */
    public static function build(int $orderId): ?array
    {
        $order = ShopOrder::findWithItems($orderId);
        if (!$order) {
            return null;
        }
        $buyer  = User::find((int) $order['member_id']);
        $seller = shop_seller_identity();
        $items  = $order['items'] ?? [];

        $subtotal = 0.0;
        foreach ($items as $it) {
            $subtotal += (float) $it['total_price'];
        }
        $status = (string) $order['status'];
        $paid   = !in_array($status, ['pending', 'payment_review', 'payment_failed', 'cancelled'], true);
        $total  = (float) $order['total_price'];

        return [
            'mode'        => 'preview',
            'invoice_no'  => null,
            'issued_at'   => null,
            'voided_at'   => null,
            'void_reason' => null,
            'order'       => [
                'order_no'          => (string) $order['order_no'],
                'status'            => $status,
                'created_at'        => (string) $order['created_at'],
                'paid_at'           => (string) ($order['paid_at'] ?? ''),
                'payment_method'    => (string) $order['payment_method'],
                'payment_reference' => (string) ($order['payment_reference'] ?? ''),
                'notes_member'      => (string) ($order['notes_member'] ?? ''),
                'billing_name'      => (string) $order['billing_name'],
                'billing_phone'     => (string) ($order['billing_phone'] ?? ''),
                'billing_email'     => (string) ($order['billing_email'] ?? ''),
                'shipping_name'     => (string) $order['shipping_name'],
                'shipping_phone'    => (string) ($order['shipping_phone'] ?? ''),
                'shipping_address'  => (string) $order['shipping_address'],
                'shipping_city'     => (string) ($order['shipping_city'] ?? ''),
                'shipping_province' => (string) ($order['shipping_province'] ?? ''),
                'shipping_postal'   => (string) ($order['shipping_postal'] ?? ''),
                'shipping_country'  => (string) ($order['shipping_country'] ?? ''),
                'total_price'       => $total,
            ],
            'buyer'       => ['username' => is_array($buyer) ? (string) ($buyer['username'] ?? '') : ''],
            'seller'      => $seller,
            'items'       => array_values($items),
            'subtotal'    => $subtotal,
            'total'       => $total,
            'amount_paid' => $paid ? $total : 0.0,
        ];
    }

    /**
     * Freeze the document for a just-paid order. Runs inside the transition's
     * transaction (same PDO, row already locked FOR UPDATE), reads the order
     * post-update, and is idempotent: a non-void row already present means
     * "already issued" and nothing is written.
     */
    public static function issue(int $orderId, PDO $pdo, string $actorType = 'system', ?int $actorId = null): ?int
    {
        $st = $pdo->prepare("SELECT id FROM shop_invoices WHERE order_id = ? AND voided_at IS NULL LIMIT 1");
        $st->execute([$orderId]);
        if ($st->fetchColumn()) {
            return null;
        }

        $doc = self::build($orderId);
        if (!$doc) {
            return null;
        }

        $seqSt = $pdo->prepare("SELECT COUNT(*) FROM shop_invoices WHERE order_id = ?");
        $seqSt->execute([$orderId]);
        $seq       = (int) $seqSt->fetchColumn();
        $orderNo   = $doc['order']['order_no'];
        $invoiceNo = $seq === 0 ? 'INV-' . $orderNo : 'INV-' . $orderNo . '-R' . ($seq + 1);

        $o = $doc['order'];
        $s = $doc['seller'];
        $ins = $pdo->prepare(
            "INSERT INTO shop_invoices
                (order_id, invoice_no, issued_at, issued_by_type, issued_by_id,
                 snapshot_version, order_no, order_created_at, status_at_issue,
                 seller_name, seller_address, seller_tin, seller_phone, seller_email,
                 seller_footer, seller_vat_note, currency,
                 billing_name, billing_phone, billing_email,
                 shipping_name, shipping_phone, shipping_address, shipping_city,
                 shipping_province, shipping_postal, shipping_country,
                 payment_method, payment_reference, paid_at, notes_member, buyer_username,
                 subtotal, total, amount_paid)
             VALUES (?, ?, NOW(), ?, ?,
                 ?, ?, ?, ?,
                 ?, ?, ?, ?, ?,
                 ?, ?, ?,
                 ?, ?, ?,
                 ?, ?, ?, ?,
                 ?, ?, ?,
                 ?, ?, ?, ?, ?,
                 ?, ?, ?)"
        );
        $ins->execute([
            $orderId, $invoiceNo,
            in_array($actorType, ['system', 'admin'], true) ? $actorType : 'system',
            $actorId,
            self::VERSION,
            $orderNo,
            $o['created_at'] !== '' ? $o['created_at'] : null,
            $o['status'],
            $s['name'] ?? '',
            ($s['address'] ?? '') !== '' ? mb_substr((string) $s['address'], 0, 500) : null,
            ($s['tin'] ?? '') !== '' ? $s['tin'] : null,
            ($s['phone'] ?? '') !== '' ? $s['phone'] : null,
            ($s['email'] ?? '') !== '' ? $s['email'] : null,
            ($s['footer'] ?? '') !== '' ? mb_substr((string) $s['footer'], 0, 500) : null,
            ($s['vat'] ?? '') !== '' ? mb_substr((string) $s['vat'], 0, 500) : null,
            ($s['currency'] ?? '') !== '' ? $s['currency'] : 'PHP',
            $o['billing_name'],
            $o['billing_phone'] !== '' ? $o['billing_phone'] : null,
            $o['billing_email'] !== '' ? $o['billing_email'] : null,
            $o['shipping_name'],
            $o['shipping_phone'] !== '' ? $o['shipping_phone'] : null,
            $o['shipping_address'] !== '' ? $o['shipping_address'] : null,
            $o['shipping_city'] !== '' ? $o['shipping_city'] : null,
            $o['shipping_province'] !== '' ? $o['shipping_province'] : null,
            $o['shipping_postal'] !== '' ? $o['shipping_postal'] : null,
            $o['shipping_country'] !== '' ? mb_substr($o['shipping_country'], 0, 2) : null,
            $o['payment_method'],
            $o['payment_reference'] !== '' ? $o['payment_reference'] : null,
            $o['paid_at'] !== '' ? $o['paid_at'] : null,
            $o['notes_member'] !== '' ? mb_substr($o['notes_member'], 0, 500) : null,
            $doc['buyer']['username'] !== '' ? mb_substr($doc['buyer']['username'], 0, 60) : null,
            $doc['subtotal'], $doc['total'], $doc['amount_paid'],
        ]);
        $invoiceId = (int) $pdo->lastInsertId();

        $li = $pdo->prepare(
            "INSERT INTO shop_invoice_items (invoice_id, line_no, description, sku, quantity, unit_price, amount)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $lineNo = 0;
        foreach ($doc['items'] as $it) {
            $li->execute([
                $invoiceId,
                ++$lineNo,
                mb_substr((string) ($it['product_name'] ?? ''), 0, 160),
                isset($it['product_sku']) && $it['product_sku'] !== '' ? mb_substr((string) $it['product_sku'], 0, 64) : null,
                max(1, (int) ($it['quantity'] ?? 1)),
                (float) ($it['unit_price'] ?? 0),
                (float) ($it['total_price'] ?? 0),
            ]);
        }
        return $invoiceId;
    }

    /**
     * Mark the newest unvoided snapshot void. Content stays frozen; only the
     * validity flag flips. No-op when the order was never issued.
     */
    public static function voidLatest(int $orderId, PDO $pdo, string $reason): void
    {
        $pdo->prepare(
            "UPDATE shop_invoices SET voided_at = NOW(), void_reason = ?
              WHERE order_id = ? AND voided_at IS NULL
              ORDER BY id DESC LIMIT 1"
        )->execute([mb_substr($reason, 0, 80), $orderId]);
    }

    /** Newest snapshot row for the order (voided or not); null when never issued. */
    public static function latestRowFor(int $orderId): ?array
    {
        $st = db()->prepare("SELECT * FROM shop_invoices WHERE order_id = ? ORDER BY id DESC LIMIT 1");
        $st->execute([$orderId]);
        return $st->fetch() ?: null;
    }

    /** Snapshot row → $doc payload (same shape as build()). */
    public static function load(array $row): ?array
    {
        $st = db()->prepare("SELECT * FROM shop_invoice_items WHERE invoice_id = ? ORDER BY line_no, id");
        $st->execute([(int) $row['id']]);
        $items = [];
        $subtotal = 0.0;
        foreach ($st->fetchAll() as $li) {
            $amount = (float) $li['amount'];
            $subtotal += $amount;
            $items[] = [
                'product_name' => (string) $li['description'],
                'product_sku'  => (string) ($li['sku'] ?? ''),
                'quantity'     => (int) $li['quantity'],
                'unit_price'   => (float) $li['unit_price'],
                'total_price'  => $amount,
            ];
        }

        $voidedAt = (string) ($row['voided_at'] ?? '');
        return [
            'mode'        => $voidedAt !== '' ? 'void' : 'issued',
            'invoice_no'  => (string) $row['invoice_no'],
            'issued_at'   => (string) $row['issued_at'],
            'voided_at'   => $voidedAt,
            'void_reason' => (string) ($row['void_reason'] ?? ''),
            'order'       => [
                'order_no'          => (string) $row['order_no'],
                'status'            => (string) $row['status_at_issue'],
                'created_at'        => (string) ($row['order_created_at'] ?? ''),
                'paid_at'           => (string) ($row['paid_at'] ?? ''),
                'payment_method'    => (string) $row['payment_method'],
                'payment_reference' => (string) ($row['payment_reference'] ?? ''),
                'notes_member'      => (string) ($row['notes_member'] ?? ''),
                'billing_name'      => (string) $row['billing_name'],
                'billing_phone'     => (string) ($row['billing_phone'] ?? ''),
                'billing_email'     => (string) ($row['billing_email'] ?? ''),
                'shipping_name'     => (string) $row['shipping_name'],
                'shipping_phone'    => (string) ($row['shipping_phone'] ?? ''),
                'shipping_address'  => (string) ($row['shipping_address'] ?? ''),
                'shipping_city'     => (string) ($row['shipping_city'] ?? ''),
                'shipping_province' => (string) ($row['shipping_province'] ?? ''),
                'shipping_postal'   => (string) ($row['shipping_postal'] ?? ''),
                'shipping_country'  => (string) ($row['shipping_country'] ?? ''),
                'total_price'       => (float) $row['total'],
            ],
            'buyer'       => ['username' => (string) ($row['buyer_username'] ?? '')],
            'seller'      => [
                'name'     => (string) $row['seller_name'],
                'address'  => (string) ($row['seller_address'] ?? ''),
                'tin'      => (string) ($row['seller_tin'] ?? ''),
                'phone'    => (string) ($row['seller_phone'] ?? ''),
                'email'    => (string) ($row['seller_email'] ?? ''),
                'footer'   => (string) ($row['seller_footer'] ?? ''),
                'vat'      => (string) ($row['seller_vat_note'] ?? ''),
                'currency' => (string) $row['currency'],
            ],
            'items'       => $items,
            'subtotal'    => $subtotal,
            'total'       => (float) $row['total'],
            'amount_paid' => (float) $row['amount_paid'],
        ];
    }

    /**
     * Read entry point for both invoice pages: the frozen snapshot when one
     * exists, otherwise a live preview build (order not yet paid, or paid
     * before the snapshot feature — see tmp/invoices/backfill_invoices.php).
     */
    public static function docForOrder(int $orderId): ?array
    {
        $row = self::latestRowFor($orderId);
        if ($row) {
            return self::load($row);
        }
        return self::build($orderId);
    }
}
