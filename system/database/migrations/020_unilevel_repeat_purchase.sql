-- =============================================================
--  Phase 17 migration — unilevel repeat-purchase bonus
--
--  Applies to a Phase 16 database and adds:
--
--    product_unilevel_levels   per-product ladder (levels 1…10)
--    unilevel_awards           the repeat-purchase money ledger
--    unilevel_activity         the per-period Active snapshot
--    unilevel_periods          the closed-month header
--
--    products.unilevel_enabled / unilevel_base
--    shop_orders.unilevel_processed_at / unilevel_total
--    shop_order_items.unit_base / base_total
--    ewallet_ledger.ref_type  + 'unilevel', 'unilevel_reversal'
--
--    six settings keys (the master switch is off by default)
--
--  No backfill: orders paid before this migration are never awarded
--  (plan D-16). `unilevel_ledger_since` marks the instant the stream
--  may pay, so UnilevelService::reconcile() can separate legacy rows.
--
--      mysql -u root <db> < database/migrations/020_unilevel_repeat_purchase.sql
--
--  No `down` note: the tables and columns are additive. Dropping them
--  would erase the record of every repeat-purchase award.
-- =============================================================

SET NAMES utf8mb4;

-- 1. products — the per-product switch and base ----------------
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'unilevel_enabled');
SET @sql := IF(@has = 0,
  'ALTER TABLE products ADD COLUMN unilevel_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER pv',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'unilevel_base');
SET @sql := IF(@has = 0,
  "ALTER TABLE products ADD COLUMN unilevel_base ENUM('auto','price','pv') NOT NULL DEFAULT 'auto' AFTER unilevel_enabled",
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. shop_order_items — the placement snapshot ----------------
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_order_items' AND COLUMN_NAME = 'unit_base');
SET @sql := IF(@has = 0,
  'ALTER TABLE shop_order_items ADD COLUMN unit_base DECIMAL(12,2) NULL AFTER line_total',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_order_items' AND COLUMN_NAME = 'base_total');
SET @sql := IF(@has = 0,
  'ALTER TABLE shop_order_items ADD COLUMN base_total DECIMAL(12,2) NULL AFTER unit_base',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. shop_orders — the idempotency stamp and the order total ----
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders' AND COLUMN_NAME = 'unilevel_processed_at');
SET @sql := IF(@has = 0,
  'ALTER TABLE shop_orders ADD COLUMN unilevel_processed_at DATETIME NULL AFTER paid_at',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders' AND COLUMN_NAME = 'unilevel_total');
SET @sql := IF(@has = 0,
  'ALTER TABLE shop_orders ADD COLUMN unilevel_total DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER unilevel_processed_at',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders' AND INDEX_NAME = 'idx_orders_unilevel');
SET @sql := IF(@has = 0,
  'ALTER TABLE shop_orders ADD KEY idx_orders_unilevel (unilevel_processed_at)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders' AND INDEX_NAME = 'idx_orders_user_paid');
SET @sql := IF(@has = 0,
  'ALTER TABLE shop_orders ADD KEY idx_orders_user_paid (user_id, paid_at)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. product_unilevel_levels — the ladder (1…10) ---------------
CREATE TABLE IF NOT EXISTS product_unilevel_levels (
  id         INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  product_id INT UNSIGNED     NOT NULL,
  level      TINYINT UNSIGNED NOT NULL,
  percent    DECIMAL(6,3)     NOT NULL DEFAULT 0.000,
  PRIMARY KEY (id),
  UNIQUE KEY uq_product_unilevel (product_id, level),
  CONSTRAINT fk_pul_product  FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
  CONSTRAINT chk_pul_level   CHECK (level BETWEEN 1 AND 10),
  CONSTRAINT chk_pul_percent CHECK (percent >= 0 AND percent <= 100)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 5. unilevel_awards — the money ledger -------------------------
CREATE TABLE IF NOT EXISTS unilevel_awards (
  id                 BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  order_id           INT UNSIGNED     NOT NULL,
  order_item_id      INT UNSIGNED     NOT NULL,
  product_id         INT UNSIGNED     NOT NULL,
  buyer_id           INT UNSIGNED     NOT NULL,
  user_id            INT UNSIGNED     NOT NULL,
  level              TINYINT UNSIGNED NOT NULL,
  base_amount        DECIMAL(12,2)    NOT NULL,
  base_source        ENUM('line','product') NOT NULL DEFAULT 'line',
  rate_pct           DECIMAL(6,3)     NOT NULL,
  amount             DECIMAL(12,2)    NOT NULL,
  status             ENUM('credited','skipped','voided') NOT NULL DEFAULT 'credited',
  reason             VARCHAR(120)     NULL,
  ledger_id          INT UNSIGNED     NULL,
  reversal_ledger_id INT UNSIGNED     NULL,
  reversed_amount    DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
  paid_at            DATETIME         NOT NULL,
  voided_at          DATETIME         NULL,
  void_reason        VARCHAR(160)     NULL,
  created_at         TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_unilevel_award (order_item_id, user_id, level),
  KEY idx_unilevel_user (user_id, created_at),
  KEY idx_unilevel_order (order_id),
  KEY idx_unilevel_buyer (buyer_id, created_at),
  KEY idx_unilevel_product (product_id, created_at),
  KEY idx_unilevel_status (status, created_at),
  KEY idx_unilevel_paid (paid_at),
  KEY idx_unilevel_ledger (ledger_id),
  CONSTRAINT fk_ua_order     FOREIGN KEY (order_id)      REFERENCES shop_orders (id)      ON DELETE CASCADE,
  CONSTRAINT fk_ua_item      FOREIGN KEY (order_item_id) REFERENCES shop_order_items (id) ON DELETE CASCADE,
  CONSTRAINT fk_ua_product   FOREIGN KEY (product_id)    REFERENCES products (id)         ON DELETE RESTRICT,
  CONSTRAINT fk_ua_buyer     FOREIGN KEY (buyer_id)      REFERENCES users (id)            ON DELETE CASCADE,
  CONSTRAINT fk_ua_recipient FOREIGN KEY (user_id)       REFERENCES users (id)            ON DELETE CASCADE,
  CONSTRAINT chk_ua_level    CHECK (level BETWEEN 1 AND 10),
  CONSTRAINT chk_ua_amount   CHECK (amount >= 0 AND reversed_amount >= 0 AND reversed_amount <= amount)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 6. unilevel_activity — the close snapshot ---------------------
CREATE TABLE IF NOT EXISTS unilevel_activity (
  period             CHAR(7)       NOT NULL,
  user_id            INT UNSIGNED  NOT NULL,
  personal_purchases DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  qualified          TINYINT(1)    NOT NULL DEFAULT 0,
  evaluated_at       DATETIME      NOT NULL,
  PRIMARY KEY (period, user_id),
  KEY idx_unilevel_activity_user (user_id, period),
  CONSTRAINT fk_ua_activity_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT chk_ua_period CHECK (period REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$')
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 7. unilevel_periods — the closed-month header -----------------
CREATE TABLE IF NOT EXISTS unilevel_periods (
  period          CHAR(7)       NOT NULL,
  closed_at       DATETIME      NOT NULL,
  closed_by       INT UNSIGNED  NULL,
  threshold       DECIMAL(12,2) NOT NULL,
  members_total   INT UNSIGNED  NOT NULL DEFAULT 0,
  qualified_count INT UNSIGNED  NOT NULL DEFAULT 0,
  award_total     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (period),
  CONSTRAINT fk_up_closer FOREIGN KEY (closed_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_up_period CHECK (period REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$')
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 8. ewallet_ledger.ref_type — the two new movement kinds -------
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ewallet_ledger'
    AND COLUMN_NAME = 'ref_type' AND COLUMN_TYPE LIKE '%unilevel%');
SET @sql := IF(@has = 0,
  'ALTER TABLE ewallet_ledger MODIFY COLUMN ref_type
     ENUM(''commission'',''payout'',''transfer'',''topup'',''registration'',
          ''shop_order'',''adjustment'',''refund'',''unilevel'',''unilevel_reversal'') NOT NULL',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 9. settings — every stream off until an operator turns it on ---
INSERT INTO settings (key_name, value) VALUES
  ('unilevel_enabled',                 '0'),
  ('unilevel_max_levels',              '10'),
  ('unilevel_requires_active_upline',  '1'),
  ('unilevel_active_threshold',        '500'),
  ('unilevel_reversal_enabled',        '1'),
  ('unilevel_ledger_since',            DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s'))
ON DUPLICATE KEY UPDATE key_name = key_name;
