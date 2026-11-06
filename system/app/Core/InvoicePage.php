<?php
declare(strict_types=1);

/* ==============================================================
 *  InvoicePage — send the printable invoice document (Phase 8,
 *  plan §5.7)
 *
 *  Both invoice routes (member and admin) render the same standalone
 *  template, outside the app layout: a document, not an app page.
 *  This class owns the response headers so a controller only decides
 *  who may see the invoice and where "back" points.
 * ============================================================== */

final class InvoicePage
{
    /**
     * @param array{invoice: array<string, mixed>, items: list<array<string, mixed>>} $document
     * @param array<string, mixed> $order Live order row (id, status, order_no for the chrome)
     */
    public static function send(array $document, array $order, string $backUrl): void
    {
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: text/html; charset=utf-8');
            /* Private business document: never cached by a shared proxy
             * and never indexed. */
            header('Cache-Control: private, no-store, max-age=0');
            header('X-Robots-Tag: noindex');
            header('X-Content-Type-Options: nosniff');
        }

        View::render('shop/invoice', [
            'document' => $document,
            'order'    => $order,
            'back_url' => $backUrl,
        ]);
    }
}
