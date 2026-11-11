-- ===========================================================================
--  EcoWealth — production install bundle
--
--  GENERATED FILE — DO NOT EDIT.
--
--  database/install.sql is assembled from:
--      database/install/header.sql   (this file)
--      database/schema.sql
--      database/seeds/001_settings.sql
--      database/seeds/002_accounts.sql
--      database/seeds/003_products.sql
--      database/seeds/004_packages.sql
--      database/install/footer.sql
--
--  Rebuild:  php bin/build_install_sql.php
--  Verify:   php bin/build_install_sql.php --check
--  Edit the sources above, never the bundle: the test suite fails when the
--  committed bundle is older than its sources (plan IR-2, D-IR-01).
--
-- ---------------------------------------------------------------------------
--  ONE FILE, ONE EMPTY DATABASE
--
--  This bundle creates the schema and the starter data for a NEW EcoWealth
--  site:
--
--      27 tables · 45 settings · 2 staff accounts · 7 products
--      2 packages · 18 ladder levels · 10 package contents
--
--  It seeds NO demo members, NO demo registration codes and NO demo wallet
--  balances. Demo fixtures are a separate, explicit import:
--  database/seeds/005_demo_tree.sql (never on a production site).
--
--  It deliberately contains no CREATE DATABASE, USE, DELIMITER or SOURCE.
--  The caller selects the schema, so the same bytes install into any
--  database name on any machine.
--
-- ---------------------------------------------------------------------------
--  LOCAL INSTALL (MySQL CLI)
--
--      mysql -u root -e "CREATE DATABASE ecowealth_sneat
--                         CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
--      mysql -u root ecowealth_sneat < database/install.sql
--
--  ONLINE INSTALL (phpMyAdmin, no SSH)
--
--      1. hPanel → Databases → create the database and its MySQL user.
--      2. phpMyAdmin → select that database → Import → choose install.sql.
--      3. Select "Stop on error" and click Go. The footer prints the
--         verification counts; they must match the list above.
--
--  Server support: MySQL 8.0.16+ or MariaDB 10.4+
--  (the schema uses CHECK constraints and JSON).
--
-- ---------------------------------------------------------------------------
--  DEFAULT STAFF ACCOUNTS — CHANGE BOTH PASSWORDS IMMEDIATELY
--
--      admin      / Admin@123     role: admin
--      superadmin / Super@123     role: superadmin
--
--  These are published defaults, not secrets. Until both are changed from
--  Profile → Change password, treat the site as compromised. The guided
--  installers (bin/db_install.php, public/install.php) replace them with
--  operator-chosen passwords and never leave a default in place.
--
-- ---------------------------------------------------------------------------
--  FRESH INSTALL ONLY
--
--  There is no upgrade path in this file. An existing database is upgraded
--  with database/migrations/NNN_*.sql in numeric order. Importing this
--  bundle into a non-empty schema is refused by the guard below; if an
--  import is interrupted or a tool ignores the error, recover by dropping
--  and recreating the schema and importing once more — never by re-running
--  this bundle on top of the partial result.
--
--  The guard fails immediately when the schema already holds any table.
--  MySQL cannot SIGNAL outside a stored program (and DELIMITER is forbidden
--  here), so the failure is a deliberately missing table whose name is the
--  instruction: ecowealth_install_requires_an_empty_database.
--
--  On an empty schema the guard is a no-op ("DO 0"). With one unrelated
--  table it stops with ERROR 1146 (42S02) and a `mysql` run exits non-zero
--  before a single CREATE TABLE executes.
-- ===========================================================================

SET NAMES utf8mb4;

-- The bundle is fresh-install only. If the schema contains any table at all,
-- this block fails with "table doesn't exist" and stops a `mysql < install.sql`
-- run before it can half-apply anything.
SET @ew_present := (
  SELECT COUNT(*) FROM information_schema.tables
   WHERE table_schema = DATABASE()
);

SET @ew_guard := IF(
  @ew_present = 0,
  'DO 0',
  'SELECT * FROM `ecowealth_install_requires_an_empty_database`'
);

PREPARE ew_guard FROM @ew_guard;
EXECUTE ew_guard;
DEALLOCATE PREPARE ew_guard;

SET time_zone = '+08:00';
