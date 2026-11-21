<?php
declare(strict_types=1);

/* ==============================================================
 *  Invoice — immutable sales-invoice snapshots (plan §4.2, §5.7;
 *  Phase 8)
 *
 *  A row is frozen the moment an order reaches `paid` (e-wallet at
 *  placement, manual payment when an administrator verifies the
 *  proof). The printed columns are never UPDATEd: only
 *  `voided_at` / `void_reason` change when the payment is reversed.
 *  Lines live in `shop_invoice_items` and are written once too.
 *
 *  `InvoiceService` owns every write; this model only reads and
 *  inserts. Re-printing reads the same row, so number and content
 *  never drift (P8-T6).
 * ============================================================== */

final class Invoice
{
    /** Insert the document header. Returns the new invoice id. */
    public static function create(int $orderId, array $data, ?PDO $pdo = null): int
    {
        if ($orderId <= 0) {
            throw new InvalidArgumentException('An invoice needs an order id.');
        }

        $pdo = $pdo ?? db();

        $pdo->prepare(
            'INSERT INTO shop_invoices
               (order_id, invoice_no, status_at_issue, seller_name, seller_address, seller_tin,
                seller_phone, buyer_username, billing_name, billing_phone, shipping_name,
                shipping_address, payment_method, paid_at, subtotal, shipping_fee, total)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $orderId,
            (string) ($data['invoice_no'] ?? 'T' . bin2hex(random_bytes(9))),
            mb_substr((string) ($data['status_at_issue'] ?? 'paid'), 0, 20),
            mb_substr((string) ($data['seller_name'] ?? APP_NAME), 0, 160),
            self::nullable($data['seller_address'] ?? null, 500),
            self::nullable($data['seller_tin'] ?? null, 40),
            self::nullable($data['seller_phone'] ?? null, 40),
            self::nullable($data['buyer_username'] ?? null, 60),
            mb_substr((string) ($data['billing_name'] ?? ''), 0, 120),
            self::nullable($data['billing_phone'] ?? null, 40),
            mb_substr((string) ($data['shipping_name'] ?? ''), 0, 120),
            mb_substr((string) ($data['shipping_address'] ?? ''), 0, 255),
            mb_substr((string) ($data['payment_method'] ?? 'ewallet'), 0, 20),
            self::nullable($data['paid_at'] ?? null, 19),
            number_format((float) ($data['subtotal'] ?? 0), 2, '.', ''),
            number_format((float) ($data['shipping_fee'] ?? 0), 2, '.', ''),
            number_format((float) ($data['total'] ?? 0), 2, '.', ''),
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** Stamp the display number after the insert (the id makes it unique). */
    public static function setNumber(int $invoiceId, string $invoiceNo, ?PDO $pdo = null): void
    {
        ($pdo ?? db())->prepare('UPDATE shop_invoices SET invoice_no = ? WHERE id = ?')
            ->execute([mb_substr(trim($invoiceNo), 0, 32), $invoiceId]);
    }

    /** Append one frozen line. */
    public static function addItem(int $invoiceId, int $lineNo, array $line, ?PDO $pdo = null): void
    {
        ($pdo ?? db())->prepare(
            'INSERT INTO shop_invoice_items
               (invoice_id, line_no, description, sku, quantity, unit_price, amount)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $invoiceId,
            max(1, $lineNo),
            mb_substr((string) ($line['description'] ?? ''), 0, 160),
            self::nullable($line['sku'] ?? null, 64),
            max(1, (int) ($line['quantity'] ?? 1)),
            number_format((float) ($line['unit_price'] ?? 0), 2, '.', ''),
            number_format((float) ($line['amount'] ?? 0), 2, '.', ''),
        ]);
    }

    /** @return array<string, mixed>|null The newest snapshot for an order. */
    public static function latestFor(int $orderId, ?PDO $pdo = null): ?array
    {
        if ($orderId <= 0) {
            return null;
        }

        $stmt = ($pdo ?? db())->prepare(
            'SELECT * FROM shop_invoices WHERE order_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$orderId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null The newest live (unvoided) snapshot. */
    public static function liveFor(int $orderId, ?PDO $pdo = null): ?array
    {
        if ($orderId <= 0) {
            return null;
        }

        $stmt = ($pdo ?? db())->prepare(
            'SELECT * FROM shop_invoices WHERE order_id = ? AND voided_at IS NULL
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$orderId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public static function find(int $invoiceId, ?PDO $pdo = null): ?array
    {
        if ($invoiceId <= 0) {
            return null;
        }

        $stmt = ($pdo ?? db())->prepare('SELECT * FROM shop_invoices WHERE id = ? LIMIT 1');
        $stmt->execute([$invoiceId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> Printed lines, in order. */
    public static function items(int $invoiceId, ?PDO $pdo = null): array
    {
        if ($invoiceId <= 0) {
            return [];
        }

        $stmt = ($pdo ?? db())->prepare(
            'SELECT * FROM shop_invoice_items WHERE invoice_id = ? ORDER BY line_no ASC, id ASC'
        );
        $stmt->execute([$invoiceId]);

        return $stmt->fetchAll();
    }

    /** How many snapshots the order has (a reissue after a void would add one). */
    public static function countFor(int $orderId, ?PDO $pdo = null): int
    {
        if ($orderId <= 0) {
            return 0;
        }

        $stmt = ($pdo ?? db())->prepare('SELECT COUNT(*) FROM shop_invoices WHERE order_id = ?');
        $stmt->execute([$orderId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Void the newest live snapshot. Content stays frozen; only the
     * validity flag flips. No-op when the order has no live invoice.
     */
    public static function void(int $invoiceId, string $reason, ?PDO $pdo = null): void
    {
        $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? $reason);

        ($pdo ?? db())->prepare(
            'UPDATE shop_invoices SET voided_at = NOW(), void_reason = ?
              WHERE id = ? AND voided_at IS NULL'
        )->execute([
            $reason === '' ? null : mb_substr($reason, 0, 80),
            $invoiceId,
        ]);
    }

    private static function nullable(mixed $value, int $max): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
