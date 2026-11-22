-- =============================================================
--  Phase 23A migration — Package Binary Bonus foundation
--
--  Amends the Phase 22 binary ledgers so they can carry a package
--  activation as a second source, and adds the package and
--  activation columns plus the three package-binary settings.
--
--    packages.binary_enabled / binary_points
--    activations.binary_processed_at / binary_points / binary_total
--    binary_awards.source / activation_id  (order_id becomes nullable)
--    binary_volume.source / activation_id  (order_id/order_item_id nullable)
--    chk_bv_own / chk_bv_team re-created to accept activation rows
--    3 settings: package_binary_enabled, package_binary_requires_package,
--                package_binary_ledger_since
--
--  No backfill: activations approved before this migration are never
--  awarded. `package_binary_ledger_since` marks the instant the stream
--  may pay.
--
--      mysql -u root <db> < database/migrations/027_package_binary.sql
--
--  Idempotent: every ALTER is guarded by an information_schema probe;
--  every DROP CHECK is guarded by information_schema.CHECK_CONSTRAINTS;
--  the settings use ON DUPLICATE KEY UPDATE.
-- =============================================================

SET NAMES utf8mb4;

-- 1. packages -----------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'packages' AND COLUMN_NAME = 'binary_enabled');
SET @sql := IF(@has = 0,
  'ALTER TABLE packages ADD COLUMN binary_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER product_value',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'packages' AND COLUMN_NAME = 'binary_points');
SET @sql := IF(@has = 0,
  'ALTER TABLE packages ADD COLUMN binary_points INT UNSIGNED NOT NULL DEFAULT 0 AFTER binary_enabled',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. activations --------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activations' AND COLUMN_NAME = 'binary_processed_at');
SET @sql := IF(@has = 0,
  'ALTER TABLE activations ADD COLUMN binary_processed_at DATETIME NULL AFTER commission_processed_at',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activations' AND COLUMN_NAME = 'binary_points');
SET @sql := IF(@has = 0,
  'ALTER TABLE activations ADD COLUMN binary_points BIGINT NOT NULL DEFAULT 0 AFTER binary_processed_at',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activations' AND COLUMN_NAME = 'binary_total');
SET @sql := IF(@has = 0,
  'ALTER TABLE activations ADD COLUMN binary_total DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER binary_points',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activations' AND INDEX_NAME = 'idx_activations_binary');
SET @sql := IF(@has = 0,
  'ALTER TABLE activations ADD KEY idx_activations_binary (binary_processed_at)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. binary_awards ------------------------------------------------
-- order_id must accept NULL (an activation award has no order)
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'binary_awards' AND COLUMN_NAME = 'order_id'
    AND IS_NULLABLE = 'YES');
SET @sql := IF(@has = 0,
  'ALTER TABLE binary_awards MODIFY COLUMN order_id INT UNSIGNED NULL',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'binary_awards' AND COLUMN_NAME = 'source');
SET @sql := IF(@has = 0,
  "ALTER TABLE binary_awards ADD COLUMN source ENUM('order','activation') NOT NULL DEFAULT 'order' AFTER kind",
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'binary_awards' AND COLUMN_NAME = 'activation_id');
SET @sql := IF(@has = 0,
  'ALTER TABLE binary_awards ADD COLUMN activation_id INT UNSIGNED NULL AFTER order_id',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'binary_awards' AND INDEX_NAME = 'uq_binary_award_activation');
SET @sql := IF(@has = 0,
  'ALTER TABLE binary_awards ADD UNIQUE KEY uq_binary_award_activation (activation_id, user_id, kind)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'binary_awards' AND INDEX_NAME = 'idx_ba_activation');
SET @sql := IF(@has = 0,
  'ALTER TABLE binary_awards ADD KEY idx_ba_activation (activation_id)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'binary_awards' AND CONSTRAINT_NAME = 'fk_ba_activation');
SET @sql := IF(@has = 0,
  'ALTER TABLE binary_awards ADD CONSTRAINT fk_ba_activation FOREIGN KEY (activation_id) REFERENCES activations (id) ON DELETE CASCADE',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'chk_ba_source');
SET @sql := IF(@has = 0,
  'ALTER TABLE binary_awards ADD CONSTRAINT chk_ba_source CHECK (
    (source = ''order'' AND order_id IS NOT NULL AND activation_id IS NULL) OR
    (source = ''activation'' AND activation_id IS NOT NULL AND order_id IS NULL)
  )',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. binary_volume ------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'binary_volume' AND COLUMN_NAME = 'order_id'
    AND IS_NULLABLE = 'YES');
SET @sql := IF(@has = 0,
  'ALTER TABLE binary_volume MODIFY COLUMN order_id INT UNSIGNED NULL',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'binary_volume' AND COLUMN_NAME = 'order_item_id'
    AND IS_NULLABLE = 'YES');
SET @sql := IF(@has = 0,
  'ALTER TABLE binary_volume MODIFY COLUMN order_item_id INT UNSIGNED NULL',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'binary_volume' AND COLUMN_NAME = 'source');
SET @sql := IF(@has = 0,
  "ALTER TABLE binary_volume ADD COLUMN source ENUM('order','activation') NOT NULL DEFAULT 'order' AFTER kind",
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'binary_volume' AND COLUMN_NAME = 'activation_id');
SET @sql := IF(@has = 0,
  'ALTER TABLE binary_volume ADD COLUMN activation_id INT UNSIGNED NULL AFTER order_item_id',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'binary_volume' AND INDEX_NAME = 'uq_bv_activation');
SET @sql := IF(@has = 0,
  'ALTER TABLE binary_volume ADD UNIQUE KEY uq_bv_activation (node_user_id, activation_id, side, kind)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'binary_volume' AND INDEX_NAME = 'idx_bv_activation');
SET @sql := IF(@has = 0,
  'ALTER TABLE binary_volume ADD KEY idx_bv_activation (activation_id)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'binary_volume' AND CONSTRAINT_NAME = 'fk_bv_activation');
SET @sql := IF(@has = 0,
  'ALTER TABLE binary_volume ADD CONSTRAINT fk_bv_activation FOREIGN KEY (activation_id) REFERENCES activations (id) ON DELETE CASCADE',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'chk_bv_source');
SET @sql := IF(@has = 0,
  'ALTER TABLE binary_volume ADD CONSTRAINT chk_bv_source CHECK (
    (source = ''order'' AND (order_id IS NOT NULL OR order_item_id IS NOT NULL) AND activation_id IS NULL) OR
    (source = ''activation'' AND activation_id IS NOT NULL AND order_id IS NULL AND order_item_id IS NULL)
  )',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Re-create chk_bv_own / chk_bv_team so an activation row is valid --
SET @has := (SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'chk_bv_own');
SET @sql := IF(@has > 0,
  'ALTER TABLE binary_volume DROP CHECK chk_bv_own',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'chk_bv_team');
SET @sql := IF(@has > 0,
  'ALTER TABLE binary_volume DROP CHECK chk_bv_team',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'chk_bv_own');
SET @sql := IF(@has = 0,
  'ALTER TABLE binary_volume ADD CONSTRAINT chk_bv_own CHECK (
    kind <> ''own'' OR (points > 0 AND award_id IS NULL AND
      ((source = ''order'' AND order_item_id IS NOT NULL) OR (source = ''activation'' AND activation_id IS NOT NULL)))
  )',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'chk_bv_team');
SET @sql := IF(@has > 0,
  'ALTER TABLE binary_volume DROP CHECK chk_bv_team',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'chk_bv_team');
SET @sql := IF(@has = 0,
  'ALTER TABLE binary_volume ADD CONSTRAINT chk_bv_team CHECK (
    kind <> ''team'' OR (points > 0 AND award_id IS NULL AND
      ((source = ''order'' AND order_item_id IS NOT NULL) OR (source = ''activation'' AND activation_id IS NOT NULL)))
  )',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- chk_bv_rev must also accept activation-sourced reversal rows (order_item_id IS NULL) --
SET @has := (SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'chk_bv_rev');
SET @sql := IF(@has > 0,
  'ALTER TABLE binary_volume DROP CHECK chk_bv_rev',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'chk_bv_rev');
SET @sql := IF(@has = 0,
  'ALTER TABLE binary_volume ADD CONSTRAINT chk_bv_rev CHECK (
    kind <> ''reversal'' OR (points < 0 AND
      ((source = ''order'' AND order_item_id IS NOT NULL) OR (source = ''activation'' AND activation_id IS NOT NULL)))
  )',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5. settings -----------------------------------------------------
INSERT INTO settings (key_name, value) VALUES
  ('package_binary_enabled',            '0'),
  ('package_binary_requires_package',   '1'),
  ('package_binary_ledger_since',       DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s'))
ON DUPLICATE KEY UPDATE key_name = key_name;
