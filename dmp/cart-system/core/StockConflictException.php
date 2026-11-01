<?php

/**
 * @file   core/StockConflictException.php
 * @brief  Raised when a fulfilment transition cannot reserve or deduct stock.
 *
 * WHY A DEDICATED CLASS (defect, Phase 7):
 *   ShopOrder::transition() used to detect this condition by string-comparing
 *   $e->getMessage() === 'stock_conflict' inside a `catch (RuntimeException)`.
 *   PDOException EXTENDS RuntimeException, so every database error - a dead
 *   connection, a constraint violation, a syntax error - was swallowed into
 *   that branch. The handler then read $order['status'], which is undefined if
 *   the throw happened before the row was fetched, and reported the failure as
 *   a stock problem regardless of what actually went wrong.
 *
 *   A distinct class cannot be caught by accident, and the catch below can
 *   therefore stay as narrow as its intent.
 *
 * Every throw is accompanied by a rolled-back transaction, so the audit event
 * written afterwards records an attempted, refused handoff rather than a
 * partial one.
 */
class StockConflictException extends RuntimeException
{
    public function __construct(string $message = 'stock_conflict')
    {
        parent::__construct($message);
    }
}