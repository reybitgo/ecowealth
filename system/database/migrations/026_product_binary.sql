-- =============================================================
--  Phase 22 migration — Product binary bonus
--
--  Applies to a Phase 21 database and adds:
--
--    binary_nodes     the placement tree and the cached point balances
--    binary_volume    the append-only, signed points ledger
--    binary_awards    the self/pair money ledger (escrowed)
--
--    products.binary_enabled
--    shop_orders.binary_position / binary_processed_at / binary_points
--                / binary_self_total / binary_pair_total / binary_total
--    shop_order_items.unit_points / points_total
--    ewallet_ledger.ref_type  + 'binary', 'binary_reversal'
--
--    ten settings keys (the master switch is off by default)
--
--  No backfill: orders paid before this migration are never awarded
--  and never place points. `binary_ledger_since` marks the instant the
--  stream may pay, so BinaryService::reconcile() can separate legacy
--  rows. Placement is backfilled by bin/binary_place_tree.php (Phase
--  22C), never by this file.
--
--      mysql -u root <db> < database/migrations/026_product_binary.sql
--
--  No `down` note: the tables and columns are additive. Dropping them
--  would erase the record of every point and every award.
-- =============================================================

SET NAMES utf8mb4;

-- 1. products -----------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'binary_enabled');
SET @sql := IF(@has = 0,
  'ALTER TABLE products ADD COLUMN binary_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER unilevel_base',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. shop_order_items --------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_order_items' AND COLUMN_NAME = 'unit_points');
SET @sql := IF(@has = 0,
  'ALTER TABLE shop_order_items ADD COLUMN unit_points INT UNSIGNED NOT NULL DEFAULT 0 AFTER base_total',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_order_items' AND COLUMN_NAME = 'points_total');
SET @sql := IF(@has = 0,
  'ALTER TABLE shop_order_items ADD COLUMN points_total BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER unit_points',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. shop_orders --------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders' AND COLUMN_NAME = 'binary_position');
SET @sql := IF(@has = 0,
  "ALTER TABLE shop_orders ADD COLUMN binary_position ENUM('left','right') NULL AFTER unilevel_total",
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders' AND COLUMN_NAME = 'binary_processed_at');
SET @sql := IF(@has = 0,
  'ALTER TABLE shop_orders ADD COLUMN binary_processed_at DATETIME NULL AFTER binary_position',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders' AND COLUMN_NAME = 'binary_points');
SET @sql := IF(@has = 0,
  'ALTER TABLE shop_orders ADD COLUMN binary_points BIGINT NOT NULL DEFAULT 0 AFTER binary_processed_at',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders' AND COLUMN_NAME = 'binary_self_total');
SET @sql := IF(@has = 0,
  'ALTER TABLE shop_orders ADD COLUMN binary_self_total DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER binary_points',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders' AND COLUMN_NAME = 'binary_pair_total');
SET @sql := IF(@has = 0,
  'ALTER TABLE shop_orders ADD COLUMN binary_pair_total DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER binary_self_total',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders' AND COLUMN_NAME = 'binary_total');
SET @sql := IF(@has = 0,
  'ALTER TABLE shop_orders ADD COLUMN binary_total DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER binary_pair_total',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders' AND INDEX_NAME = 'idx_orders_binary');
SET @sql := IF(@has = 0,
  'ALTER TABLE shop_orders ADD KEY idx_orders_binary (binary_processed_at)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders' AND INDEX_NAME = 'idx_orders_binary_side');
SET @sql := IF(@has = 0,
  'ALTER TABLE shop_orders ADD KEY idx_orders_binary_side (binary_position, paid_at)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. binary_nodes ------------------------------------------------
CREATE TABLE IF NOT EXISTS binary_nodes (
  user_id        INT UNSIGNED     NOT NULL,
  upline_id      INT UNSIGNED     NULL,
  side           ENUM('left','right') NULL,
  root_id        INT UNSIGNED     NULL,
  depth          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  path           VARCHAR(255)     NOT NULL DEFAULT '',
  state          ENUM('root','placed','floating') NOT NULL DEFAULT 'floating',
  left_points    BIGINT           NOT NULL DEFAULT 0,
  right_points   BIGINT           NOT NULL DEFAULT 0,
  left_credited  BIGINT           NOT NULL DEFAULT 0,
  right_credited BIGINT           NOT NULL DEFAULT 0,
  points_matched BIGINT           NOT NULL DEFAULT 0,
  pairs_paid     INT UNSIGNED     NOT NULL DEFAULT 0,
  personal_points BIGINT          NOT NULL DEFAULT 0,
  placed_at      DATETIME         NULL,
  placed_by      INT UNSIGNED     NULL,
  moved_at       DATETIME         NULL,
  moved_by       INT UNSIGNED     NULL,
  move_count     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at     TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  UNIQUE KEY uq_binary_slot (upline_id, side),
  KEY idx_binary_upline (upline_id),
  KEY idx_binary_path (path(24)),
  KEY idx_binary_root (root_id),
  KEY idx_binary_state (state),
  CONSTRAINT fk_bn_user   FOREIGN KEY (user_id)   REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_bn_upline FOREIGN KEY (upline_id) REFERENCES binary_nodes (user_id) ON DELETE CASCADE,
  CONSTRAINT fk_bn_root   FOREIGN KEY (root_id)   REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_bn_slot  CHECK ((upline_id IS NULL) = (side IS NULL)),
  CONSTRAINT chk_bn_self  CHECK (upline_id IS NULL OR upline_id <> user_id),
  CONSTRAINT chk_bn_depth CHECK (depth BETWEEN 0 AND 100)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 5. binary_volume -----------------------------------------------
CREATE TABLE IF NOT EXISTS binary_volume (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  node_user_id  INT UNSIGNED    NOT NULL,
  side          ENUM('left','right') NOT NULL,
  kind          ENUM('own','team','pair','pair_return','reversal','adjustment') NOT NULL,
  points        BIGINT          NOT NULL,
  order_id      INT UNSIGNED    NULL,
  order_item_id INT UNSIGNED    NULL,
  buyer_id      INT UNSIGNED    NULL,
  award_id      BIGINT UNSIGNED NULL,
  note          VARCHAR(160)    NULL,
  created_by    INT UNSIGNED    NULL,
  created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bv_line (node_user_id, order_item_id, side, kind),
  UNIQUE KEY uq_bv_award (award_id, side, kind),
  KEY idx_bv_node (node_user_id, side, created_at),
  KEY idx_bv_order (order_id),
  KEY idx_bv_buyer (buyer_id, created_at),
  KEY idx_bv_kind (kind, created_at),
  KEY idx_bv_award (award_id),
  CONSTRAINT fk_bv_node  FOREIGN KEY (node_user_id)  REFERENCES users (id)         ON DELETE CASCADE,
  CONSTRAINT fk_bv_buyer FOREIGN KEY (buyer_id)      REFERENCES users (id)         ON DELETE SET NULL,
  CONSTRAINT fk_bv_order FOREIGN KEY (order_id)      REFERENCES shop_orders (id)   ON DELETE CASCADE,
  CONSTRAINT fk_bv_item  FOREIGN KEY (order_item_id) REFERENCES shop_order_items (id) ON DELETE CASCADE,
  CONSTRAINT fk_bv_actor FOREIGN KEY (created_by)    REFERENCES users (id)         ON DELETE SET NULL,
  CONSTRAINT chk_bv_points CHECK (points <> 0),
  CONSTRAINT chk_bv_own    CHECK (kind <> 'own'    OR (points > 0 AND order_item_id IS NOT NULL AND award_id IS NULL)),
  CONSTRAINT chk_bv_team   CHECK (kind <> 'team'   OR (points > 0 AND order_item_id IS NOT NULL AND award_id IS NULL)),
  CONSTRAINT chk_bv_rev    CHECK (kind <> 'reversal' OR (points < 0 AND order_item_id IS NOT NULL)),
  CONSTRAINT chk_bv_pair   CHECK (kind <> 'pair'   OR (points < 0 AND award_id IS NOT NULL)),
  CONSTRAINT chk_bv_return CHECK (kind <> 'pair_return' OR (points > 0 AND award_id IS NOT NULL)),
  CONSTRAINT chk_bv_adj    CHECK (kind <> 'adjustment' OR points <> 0)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 6. binary_awards -----------------------------------------------
CREATE TABLE IF NOT EXISTS binary_awards (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind               ENUM('self','pair') NOT NULL,
  order_id           INT UNSIGNED    NOT NULL,
  buyer_id           INT UNSIGNED    NOT NULL,
  user_id            INT UNSIGNED    NOT NULL,
  side               ENUM('left','right') NULL,
  points             BIGINT          NOT NULL DEFAULT 0,
  pair_count         INT UNSIGNED    NOT NULL DEFAULT 0,
  pair_points        BIGINT UNSIGNED NOT NULL DEFAULT 0,
  rate_pct           DECIMAL(6,3)    NOT NULL DEFAULT 0.000,
  amount             DECIMAL(12,2)   NOT NULL,
  status             ENUM('pending','credited','voided','cancelled') NOT NULL DEFAULT 'pending',
  hold_days          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ledger_id          INT UNSIGNED    NULL,
  credited_at        DATETIME        NULL,
  reversal_ledger_id INT UNSIGNED    NULL,
  reversed_amount    DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  paid_at            DATETIME        NOT NULL,
  voided_at          DATETIME        NULL,
  void_reason        VARCHAR(160)    NULL,
  created_at         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_binary_award (order_id, user_id, kind),
  KEY idx_ba_user (user_id, created_at),
  KEY idx_ba_buyer (buyer_id, created_at),
  KEY idx_ba_order (order_id),
  KEY idx_ba_status (status, created_at),
  KEY idx_ba_user_status (user_id, status),
  KEY idx_ba_paid (paid_at),
  KEY idx_ba_ledger (ledger_id),
  CONSTRAINT fk_ba_order  FOREIGN KEY (order_id) REFERENCES shop_orders (id) ON DELETE CASCADE,
  CONSTRAINT fk_ba_buyer  FOREIGN KEY (buyer_id) REFERENCES users (id)       ON DELETE CASCADE,
  CONSTRAINT fk_ba_user   FOREIGN KEY (user_id)  REFERENCES users (id)       ON DELETE CASCADE,
  CONSTRAINT chk_ba_amount CHECK (amount >= 0 AND reversed_amount >= 0 AND reversed_amount <= amount),
  CONSTRAINT chk_ba_self   CHECK (kind <> 'self' OR (pair_count = 0 AND points > 0)),
  CONSTRAINT chk_ba_pair   CHECK (kind <> 'pair' OR (pair_count > 0 AND points = 2 * pair_count * pair_points))
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 6b. the volume → award link (binary_awards now exists) ---------
SET @has := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'binary_volume'
    AND CONSTRAINT_NAME = 'fk_bv_award');
SET @sql := IF(@has = 0,
  'ALTER TABLE binary_volume
     ADD CONSTRAINT fk_bv_award FOREIGN KEY (award_id) REFERENCES binary_awards (id) ON DELETE RESTRICT',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 7. ewallet_ledger.ref_type -------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ewallet_ledger'
    AND COLUMN_NAME = 'ref_type' AND COLUMN_TYPE LIKE '%binary%');
SET @sql := IF(@has = 0,
  'ALTER TABLE ewallet_ledger MODIFY COLUMN ref_type
     ENUM(''commission'',''payout'',''transfer'',''topup'',''registration'',
          ''shop_order'',''adjustment'',''refund'',''unilevel'',''unilevel_reversal'',
          ''royalty'',''team_harvest'',''general_harvest'',''binary'',''binary_reversal'') NOT NULL',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 8. settings ----------------------------------------------------
INSERT INTO settings (key_name, value) VALUES
  ('binary_enabled',           '0'),
  ('binary_self_pct',          '5.000'),
  ('binary_pair_points',       '1000'),
  ('binary_pair_pct',          '10.000'),
  ('binary_daily_pair_cap',    '20'),
  ('binary_requires_active',   '1'),
  ('binary_hold_days',         '7'),
  ('binary_release_enabled',   '1'),
  ('binary_reversal_enabled',  '1'),
  ('binary_ledger_since',      DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s'))
ON DUPLICATE KEY UPDATE key_name = key_name;
