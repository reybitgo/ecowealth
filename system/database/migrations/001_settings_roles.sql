-- =============================================================
--  Phase 1 migration — settings, audit log, role rename
--
--  Applies to a pre-Phase-1 EcoWealth database (the `sneat_auth`
--  shaped schema: users.role ENUM('user','admin'), remember_tokens).
--  A fresh install does NOT run this file — database/schema.sql
--  already represents the post-migration state.
--
--  Apply with:
--      mysql -u root <db> < database/migrations/001_settings_roles.sql
--  then load the Phase 1 seeds:
--      mysql -u root <db> < database/seeds/001_settings.sql
--      mysql -u root <db> < database/seeds/002_accounts.sql
--
--  No `down` note: the role rename is lossless in the forward
--  direction (`member` → `user` is reversible), and dropping
--  settings/audit_log would lose configuration.
-- =============================================================

SET NAMES utf8mb4;

-- -------------------------------------------------------------
-- 1. settings — key/value configuration
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
  key_name   VARCHAR(80) NOT NULL,
  value      TEXT        NOT NULL,
  updated_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (key_name)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- 2. audit_log — admin/money actions
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

-- -------------------------------------------------------------
-- 3. users.role: 'user' → 'member', add 'superadmin'
--    Three steps so existing rows keep working at every point.
-- -------------------------------------------------------------
ALTER TABLE users
  MODIFY COLUMN role ENUM('user','member','admin','superadmin') NOT NULL DEFAULT 'user';

UPDATE users SET role = 'member' WHERE role = 'user';

ALTER TABLE users
  MODIFY COLUMN role ENUM('member','admin','superadmin') NOT NULL DEFAULT 'member';

-- Helper index for the admin member list (Phase 9) and guards.
-- Guarded so the migration can be re-run on a database that already has it.
SET @has_idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_role'
);
SET @sql := IF(@has_idx = 0,
  'ALTER TABLE users ADD KEY idx_users_role (role, is_active)',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
