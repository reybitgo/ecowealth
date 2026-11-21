-- =============================================================
--  Phase 15 migration — hardening, reports and the audit desk
--
--  Applies to a Phase 14 database. Adds no columns and moves no
--  data: it only adds the indexes the Phase 15 dashboards, reports
--  and audit log read, so an admin screen stays well inside its
--  one-second budget once the shop has 200 products, 1 000+ orders
--  and 500+ ledger rows (plan §7 Phase 15, P15-T3).
--
--  `audit_log` itself was created by 001 (the plan allowed either
--  phase); this migration only indexes the columns the new audit
--  desk filters on. The DDL is repeated as CREATE TABLE IF NOT
--  EXISTS so a database that skipped 001's table still gains it.
--
--  Every ALTER below is guarded by an information_schema lookup, so
--  applying the migration a second time is a no-op (plan §9.3).
--
--      mysql -u root <db> < database/migrations/019_audit_and_reporting.sql
--
--  No `down` note: dropping these indexes is always safe, but it
--  would slow the reports down again without changing any data.
-- =============================================================

SET NAMES utf8mb4;

-- -------------------------------------------------------------
-- 1. audit_log — the admin audit desk (GET /admin/audit)
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_id     INT UNSIGNED NULL,
  actor_role   VARCHAR(20)  NULL,
  action       VARCHAR(80)  NOT NULL,
  subject_type VARCHAR(40)  NULL,
  subject_id   INT UNSIGNED NULL,
  meta_json    JSON         NULL,
  ip           VARCHAR(45)  NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_actor (actor_id, created_at),
  KEY idx_audit_subject (subject_type, subject_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- The audit list is ordered by id DESC and filtered by action and by
-- date, so one index leads on `action` and one on `created_at`.
SET @exists := (SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = 'audit_log'
                   AND index_name = 'idx_audit_action');
SET @sql := IF(@exists = 0,
  'ALTER TABLE audit_log ADD INDEX idx_audit_action (action, created_at)',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = 'audit_log'
                   AND index_name = 'idx_audit_created');
SET @sql := IF(@exists = 0,
  'ALTER TABLE audit_log ADD INDEX idx_audit_created (created_at)',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- -------------------------------------------------------------
-- 2. shop_orders — sales-by-day and the dashboards
--
--    The existing indexes lead on user_id and on status; a date
--    range over the whole shop had no index at all.
-- -------------------------------------------------------------
SET @exists := (SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = 'shop_orders'
                   AND index_name = 'idx_orders_created');
SET @sql := IF(@exists = 0,
  'ALTER TABLE shop_orders ADD INDEX idx_orders_created (created_at)',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Revenue counts orders by `paid_at`; cancelled orders are excluded
-- in the query, the index still narrows the range.
SET @exists := (SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = 'shop_orders'
                   AND index_name = 'idx_orders_paid');
SET @sql := IF(@exists = 0,
  'ALTER TABLE shop_orders ADD INDEX idx_orders_paid (paid_at)',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- -------------------------------------------------------------
-- 3. ewallet_ledger — the wallet statement export
-- -------------------------------------------------------------
SET @exists := (SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = 'ewallet_ledger'
                   AND index_name = 'idx_ewallet_created');
SET @sql := IF(@exists = 0,
  'ALTER TABLE ewallet_ledger ADD INDEX idx_ewallet_created (created_at, ref_type)',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- -------------------------------------------------------------
-- 4. commissions — commissions-by-level report
-- -------------------------------------------------------------
SET @exists := (SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = 'commissions'
                   AND index_name = 'idx_commissions_level');
SET @sql := IF(@exists = 0,
  'ALTER TABLE commissions ADD INDEX idx_commissions_level (level, status)',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- -------------------------------------------------------------
-- 5. reg_codes — registration-code revenue
-- -------------------------------------------------------------
SET @exists := (SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = 'reg_codes'
                   AND index_name = 'idx_reg_codes_created');
SET @sql := IF(@exists = 0,
  'ALTER TABLE reg_codes ADD INDEX idx_reg_codes_created (created_at, status)',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
