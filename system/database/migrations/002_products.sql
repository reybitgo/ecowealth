-- =============================================================
--  Phase 2 migration — products catalogue
--
--  Applies to a Phase 1 database (users, remember_tokens,
--  settings, audit_log). Adds the `products` table from
--  plan §4.2 / Appendix B.
--
--      mysql -u root <db> < database/migrations/002_products.sql
--      mysql -u root <db> < database/seeds/003_products.sql
--
--  No `down` note: the table is additive. Dropping it would lose
--  catalogue data and is never part of an upgrade.
-- =============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS products (
  id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  sku               VARCHAR(64)   NULL,
  name              VARCHAR(160)  NOT NULL,
  slug              VARCHAR(180)  NOT NULL,
  price             DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  pv                DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  stock             INT UNSIGNED  NOT NULL DEFAULT 0,
  image_url         VARCHAR(255)  NULL,
  short_description VARCHAR(255)  NULL,
  description       TEXT          NULL,
  status            ENUM('active','inactive') NOT NULL DEFAULT 'active',
  sort_order        INT           NOT NULL DEFAULT 0,
  created_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_products_sku (sku),
  UNIQUE KEY uq_products_slug (slug),
  KEY idx_products_status (status, sort_order)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
