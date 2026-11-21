-- =============================================================
--  Phase 6 migration — addresses, orders, order items, events
--
--  Applies to a Phase 5 database and adds the commerce tables from
--  plan §4.2 / Appendix B:
--
--    addresses          member delivery / billing address book
--    shop_orders        order header + immutable address snapshot
--    shop_order_items   line snapshots + the stock reservation state
--    shop_order_events  append-only status audit trail
--
--  Reservation contract (plan §4.3, §5.1):
--    reserved   order placed, stock held
--    committed  payment confirmed (still held; stock is deducted at ship)
--    deducted   shipment created (Phase 8)
--    released   cancelled / payment failed → the hold is gone
--
--      mysql -u root <db> < database/migrations/006_orders.sql
--
--  No seed: orders are created by members.
--  No `down` note: the tables are additive. Dropping them would
--  destroy order history, so a rollback needs a deliberate dump.
-- =============================================================

SET NAMES utf8mb4;

-- -------------------------------------------------------------
-- 1. addresses — the member's address book (plan §5.3)
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS addresses (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        INT UNSIGNED NOT NULL,
  label          VARCHAR(40)  NULL,
  recipient_name VARCHAR(120) NOT NULL,
  phone          VARCHAR(40)  NULL,
  address_line   VARCHAR(255) NOT NULL,
  barangay       VARCHAR(120) NULL,
  city           VARCHAR(80)  NULL,
  province       VARCHAR(80)  NULL,
  postal         VARCHAR(20)  NULL,
  country        CHAR(2)      NOT NULL DEFAULT 'PH',
  billing_name   VARCHAR(120) NULL,
  billing_phone  VARCHAR(40)  NULL,
  notes          VARCHAR(500) NULL,
  is_default     TINYINT(1)   NOT NULL DEFAULT 0,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_addr_user (user_id, is_default),
  CONSTRAINT fk_addr_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- 2. shop_orders — one row per placed order (plan §4.4, §5.3)
--
--  The address columns are a snapshot: editing the address book
--  later never rewrites what an order was shipped to.
--  `order_no` is EW + yymmdd + 5-digit id, written after the insert
--  (the id is the unique part). `idempotency_key` is the unique
--  anchor that makes a double-submitted checkout return one order.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS shop_orders (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_no            VARCHAR(20)  NOT NULL,
  user_id             INT UNSIGNED NOT NULL,
  status              ENUM('pending','payment_review','payment_failed','paid','packing',
                           'ready_to_ship','shipped','delivered','completed','cancelled')
                      NOT NULL DEFAULT 'pending',
  payment_method      ENUM('ewallet','gcash','maya','bank') NOT NULL DEFAULT 'ewallet',
  payment_reference   VARCHAR(40)  NULL,
  idempotency_key     VARCHAR(64)  NULL,
  subtotal            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  shipping_fee        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total               DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  billing_name        VARCHAR(120) NOT NULL,
  billing_phone       VARCHAR(40)  NULL,
  shipping_name       VARCHAR(120) NOT NULL,
  shipping_phone      VARCHAR(40)  NULL,
  shipping_address    VARCHAR(255) NOT NULL,
  shipping_barangay   VARCHAR(120) NULL,
  shipping_city       VARCHAR(80)  NULL,
  shipping_province   VARCHAR(80)  NULL,
  shipping_postal     VARCHAR(20)  NULL,
  shipping_country    CHAR(2)      NOT NULL DEFAULT 'PH',
  notes_member        VARCHAR(500) NULL,
  notes_admin         VARCHAR(500) NULL,
  terms_version       VARCHAR(16)  NOT NULL DEFAULT 'v1',
  terms_accepted_at   DATETIME     NULL,
  payment_deadline    DATETIME     NULL,
  correction_deadline DATETIME     NULL,
  paid_at             DATETIME     NULL,
  packed_at           DATETIME     NULL,
  shipped_at          DATETIME     NULL,
  delivered_at        DATETIME     NULL,
  completed_at        DATETIME     NULL,
  cancelled_at        DATETIME     NULL,
  cancelled_reason    VARCHAR(160) NULL,
  created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_shop_orders_no (order_no),
  UNIQUE KEY uq_shop_orders_idem (idempotency_key),
  KEY idx_orders_user_status (user_id, status, created_at),
  KEY idx_orders_status (status, payment_deadline),
  CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- 3. shop_order_items — line snapshots + reservation state
--
--  product_name / sku / unit_price are copied at placement so a
--  later catalogue edit (or a deleted product) never changes what
--  the member bought. `line_total` is the priced line.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS shop_order_items (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id          INT UNSIGNED NOT NULL,
  product_id        INT UNSIGNED NOT NULL,
  product_name      VARCHAR(160) NOT NULL,
  product_sku       VARCHAR(64)  NULL,
  quantity          INT UNSIGNED NOT NULL DEFAULT 1,
  unit_price        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  line_total        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  reservation_state ENUM('reserved','committed','deducted','released') NOT NULL DEFAULT 'reserved',
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_order_items_order (order_id),
  KEY idx_order_items_product (product_id, reservation_state),
  CONSTRAINT fk_order_items_order   FOREIGN KEY (order_id) REFERENCES shop_orders (id) ON DELETE CASCADE,
  CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- 4. shop_order_events — append-only status audit trail
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS shop_order_events (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id    INT UNSIGNED NOT NULL,
  from_status VARCHAR(20)  NULL,
  to_status   VARCHAR(20)  NOT NULL,
  actor_type  ENUM('member','admin','system') NOT NULL,
  actor_id    INT UNSIGNED NULL,
  reason_code VARCHAR(40)  NULL,
  note        VARCHAR(500) NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_order_events_order (order_id, created_at),
  CONSTRAINT fk_order_events_order FOREIGN KEY (order_id) REFERENCES shop_orders (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
