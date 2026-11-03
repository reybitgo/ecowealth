-- =============================================================
--  Legacy fixture: the pre-Phase-1 EcoWealth database shape
--  (the `sneat_auth` schema from Phase 0: users + remember_tokens,
--  role ENUM('user','admin')).
--
--  MigrationTest loads this into ecowealth_sneat_migtest and then
--  applies database/migrations/001_settings_roles.sql to prove the
--  upgrade path works for an existing installation.
-- =============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  username      VARCHAR(50)      NOT NULL,
  email         VARCHAR(100)     NOT NULL,
  password_hash VARCHAR(255)     NOT NULL,
  full_name     VARCHAR(100)     DEFAULT NULL,
  role          ENUM('user','admin') NOT NULL DEFAULT 'user',
  is_active     TINYINT(1)       NOT NULL DEFAULT 1,
  last_login_at DATETIME         NULL DEFAULT NULL,
  created_at    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS remember_tokens (
  id         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED  NOT NULL,
  selector   CHAR(24)      NOT NULL,
  token_hash CHAR(64)      NOT NULL,
  expires_at DATETIME      NOT NULL,
  created_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_remember_selector (selector),
  KEY idx_remember_user (user_id),
  CONSTRAINT fk_remember_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Two accounts: an ordinary member and an administrator.
INSERT INTO users (id, username, email, password_hash, full_name, role, is_active) VALUES
  (1, 'legacy_user', 'legacy.user@example.com', '$2y$10$Zb7S7VhUUy1BqfkZz0pjJ.2x1YQr67cqdgExziYaSMKbFkhLx6ZvW', 'Legacy Member', 'user', 1),
  (2, 'legacy_admin', 'legacy.admin@example.com', '$2y$10$Zb7S7VhUUy1BqfkZz0pjJ.2x1YQr67cqdgExziYaSMKbFkhLx6ZvW', 'Legacy Admin', 'admin', 1);

-- A remembered device for the member, to prove the FK survives the ALTER.
INSERT INTO remember_tokens (user_id, selector, token_hash, expires_at) VALUES
  (1, 'aaaaaaaaaaaaaaaaaaaaaaaa', REPEAT('b', 64), DATE_ADD(NOW(), INTERVAL 30 DAY));
