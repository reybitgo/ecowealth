-- =============================================================
--  Phase 3 migration — associate packages
--
--  Applies to a Phase 2 database (users, remember_tokens,
--  settings, audit_log, products). Adds the package tables from
--  plan §4.2 / Appendix B:
--
--    packages         price, product value, direct bonus, status
--    package_levels   indirect referral amounts for levels 2–10
--                     (level 1 is the direct bonus, stored once on
--                      the package row — never duplicated here)
--    package_products package contents (product + quantity)
--
--      mysql -u root <db> < database/migrations/003_packages.sql
--      mysql -u root <db> < database/seeds/004_packages.sql
--
--  No `down` note: the tables are additive.
-- =============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS packages (
  id                    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  name                  VARCHAR(80)   NOT NULL,
  slug                  VARCHAR(100)  NOT NULL,
  image                 VARCHAR(255)  NULL,
  price                 DECIMAL(12,2) NOT NULL,
  product_value         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  direct_referral_bonus DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  indirect_enabled      TINYINT(1)    NOT NULL DEFAULT 1,
  description           TEXT          NULL,
  perks                 TEXT          NULL,
  status                ENUM('active','inactive') NOT NULL DEFAULT 'active',
  sort_order            INT           NOT NULL DEFAULT 0,
  created_at            TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_packages_slug (slug),
  KEY idx_packages_status (status, sort_order)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS package_levels (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  package_id INT UNSIGNED NOT NULL,
  level      TINYINT UNSIGNED NOT NULL,
  amount     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pkg_level (package_id, level),
  CONSTRAINT fk_pkg_levels_pkg FOREIGN KEY (package_id) REFERENCES packages (id) ON DELETE CASCADE,
  CONSTRAINT chk_pkg_level_range CHECK (level BETWEEN 2 AND 10)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS package_products (
  package_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  quantity   INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (package_id, product_id),
  CONSTRAINT fk_pkgprod_pkg  FOREIGN KEY (package_id) REFERENCES packages (id) ON DELETE CASCADE,
  CONSTRAINT fk_pkgprod_prod FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
