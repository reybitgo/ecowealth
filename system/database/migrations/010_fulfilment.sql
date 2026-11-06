-- =============================================================
--  Phase 8 migration — shipments, invoices and auto-complete
--
--  Applies to a Phase 7 database and adds the fulfilment tables
--  from plan §4.2 / Appendix B, §7 Phase 8:
--
--    shop_shipments       courier + tracking for one order
--    shop_invoices        frozen document snapshot, issued on `paid`
--    shop_invoice_items   the printed lines of that snapshot
--
--  and the one setting the auto-complete command reads:
--
--    shop_autocomplete_enabled  1 = delivered orders are completed
--                                   after shop_autocomplete_days
--                               0 = the job skips every run
--
--  Reservation contract (plan §5.1): shipping is the point where
--  `reserved`/`committed` becomes `deducted` and `products.stock` is
--  decremented. The shipment row is written in the same transaction
--  as the status change, so an order can never be `shipped` without
--  its tracking row (or vice versa).
--
--  Invoices are snapshots: the printed columns are never UPDATEd;
--  only `voided_at` / `void_reason` change when the payment is
--  reversed. Numbering is INV + yymmdd + the 5-digit row id (plan
--  §5.7), generated after the insert because the id is unique.
--
--      mysql -u root <db> < database/migrations/010_fulfilment.sql
--
--  No seed: shipments and invoices are created by the app.
--  No `down` note: the tables are additive. Dropping them would
--  destroy shipping history and issued documents, so a rollback
--  needs a deliberate dump.
-- =============================================================

SET NAMES utf8mb4;

-- -------------------------------------------------------------
-- 1. shop_shipments — courier + tracking (plan §5.5)
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS shop_shipments (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id        INT UNSIGNED NOT NULL,
  courier         VARCHAR(40)  NULL,
  tracking_number VARCHAR(80)  NULL,
  delivered_at    DATETIME     NULL,
  notes           VARCHAR(255) NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_shipments_order (order_id),
  CONSTRAINT fk_shipments_order FOREIGN KEY (order_id) REFERENCES shop_orders (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- 2. shop_invoices — one immutable snapshot per issued invoice
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS shop_invoices (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id         INT UNSIGNED NOT NULL,
  invoice_no       VARCHAR(32)  NOT NULL,
  issued_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status_at_issue  VARCHAR(20)  NOT NULL,
  seller_name      VARCHAR(160) NOT NULL,
  seller_address   VARCHAR(500) NULL,
  seller_tin       VARCHAR(40)  NULL,
  seller_phone     VARCHAR(40)  NULL,
  buyer_username   VARCHAR(60)  NULL,
  billing_name     VARCHAR(120) NOT NULL,
  billing_phone    VARCHAR(40)  NULL,
  shipping_name    VARCHAR(120) NOT NULL,
  shipping_address VARCHAR(255) NOT NULL,
  payment_method   VARCHAR(20)  NOT NULL,
  paid_at          DATETIME     NULL,
  subtotal         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  shipping_fee     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  voided_at        DATETIME     NULL,
  void_reason      VARCHAR(80)  NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_invoices_no (invoice_no),
  KEY idx_invoices_order (order_id),
  CONSTRAINT fk_invoices_order FOREIGN KEY (order_id) REFERENCES shop_orders (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- 3. shop_invoice_items — the frozen printed lines
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS shop_invoice_items (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  invoice_id  INT UNSIGNED NOT NULL,
  line_no     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  description VARCHAR(160) NOT NULL,
  sku         VARCHAR(64)  NULL,
  quantity    INT UNSIGNED NOT NULL DEFAULT 1,
  unit_price  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  amount      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id),
  KEY idx_invoice_items_invoice (invoice_id),
  CONSTRAINT fk_invoice_items_invoice FOREIGN KEY (invoice_id) REFERENCES shop_invoices (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- 4. settings — the auto-complete kill switch (default on)
--
--  Idempotent: never overwrites a value an admin already chose.
-- -------------------------------------------------------------
INSERT INTO settings (key_name, value) VALUES
  ('shop_autocomplete_enabled', '1')
ON DUPLICATE KEY UPDATE key_name = key_name;
