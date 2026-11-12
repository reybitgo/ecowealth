-- ===========================================================================
--  EcoWealth — install footer (plan IR-2, §6.3–6.5)
--
--  Included in database/install.sql as the last two sections:
--
--      INSTALL METADATA  — the three install_* settings
--      VERIFICATION      — the query pack printed by a `mysql` import
--
--  The line containing @@VERIFICATION@@ is consumed by
--  bin/build_install_sql.php: it separates the two sections and becomes
--  the VERIFICATION banner. Do not remove it.
--
--  INSERT IGNORE rather than ON DUPLICATE KEY UPDATE … VALUES(): the latter
--  is deprecated in MySQL 8.0.20+ and unsupported by older MariaDB. The
--  bundle is fresh-install only, so "ignore an existing key" is the right
--  behaviour for a re-run of a hand-picked section.
-- ===========================================================================

INSERT IGNORE INTO settings (key_name, value) VALUES
  ('install_version',      '1.0.0'),
  ('install_channel',      'install.sql'),
  ('install_completed_at', DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s'));

-- @@VERIFICATION@@
-- ---------------------------------------------------------------------------
--  Verification pack — run after any import (plan §6.5, Appendix E)
--
--  Expected on a correct production import:
--      tables_created = 31    settings_rows = 54    staff_rows    = 2
--      members_rows   = 0     products_rows = 7     packages_rows = 2
--      levels_rows    = 18    contents_rows = 10    codes_rows    = 0
--
--  A reset is expected to add audit_log rows, live_revisions rows and the
--  generated registration codes; a fresh install has none of them.
-- ---------------------------------------------------------------------------

SELECT
  (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE())                       AS tables_created,
  (SELECT COUNT(*) FROM settings)                          AS settings_rows,
  (SELECT COUNT(*) FROM users WHERE role IN ('admin','superadmin')) AS staff_rows,
  (SELECT COUNT(*) FROM users WHERE role = 'member')       AS members_rows,
  (SELECT COUNT(*) FROM products)                          AS products_rows,
  (SELECT COUNT(*) FROM packages)                          AS packages_rows,
  (SELECT COUNT(*) FROM package_levels)                    AS levels_rows,
  (SELECT COUNT(*) FROM package_products)                  AS contents_rows,
  (SELECT COUNT(*) FROM reg_codes)                         AS codes_rows;

SELECT key_name, value FROM settings
 WHERE key_name IN ('site_name','install_version','install_channel','install_completed_at')
 ORDER BY key_name;

SELECT username, role, member_status, is_active
  FROM users WHERE role IN ('admin','superadmin') ORDER BY id;
