-- =============================================================
--  Sneat Auth — MySQL schema
--  Login & Registration system (PHP + MySQL)
-- =============================================================

CREATE DATABASE IF NOT EXISTS sneat_auth
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'sneat_user'@'localhost' IDENTIFIED BY 'Sneat#2026Secure';
CREATE USER IF NOT EXISTS 'sneat_user'@'127.0.0.1' IDENTIFIED BY 'Sneat#2026Secure';
GRANT ALL PRIVILEGES ON sneat_auth.* TO 'sneat_user'@'localhost';
GRANT ALL PRIVILEGES ON sneat_auth.* TO 'sneat_user'@'127.0.0.1';
FLUSH PRIVILEGES;

USE sneat_auth;

-- -------------------------------------------------------------
-- Users
-- -------------------------------------------------------------
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

-- -------------------------------------------------------------
-- Remember-me tokens (selector : validator pair)
-- -------------------------------------------------------------
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

-- -------------------------------------------------------------
-- Demo account:  admin / Admin@123  (email: admin@sneat.dev)
-- -------------------------------------------------------------
INSERT INTO users (username, email, password_hash, full_name, role)
VALUES (
  'admin',
  'admin@sneat.dev',
  '$2y$10$RZGaudGtLXkgA.3YKStqFe2xtkHCH9SheBNEhk38d2JB9WWW/ctBa',
  'Sneat Administrator',
  'admin'
) ON DUPLICATE KEY UPDATE username = username;
