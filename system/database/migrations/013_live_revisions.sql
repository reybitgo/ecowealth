-- =============================================================
--  Live-update migration — surface revisions
--
--  Applies to a Phase 10 database and adds the tiny revision
--  ledger used by the no-refresh page updates:
--
--    live_revisions   one row per live surface ("admin.products", …)
--
--  Why this table exists
--
--    A dashboard or list page needs a cheap way to ask "did
--    anything change since my last poll?" without re-running its
--    list queries. One row per surface gives that check a
--    primary-key lookup; an unchanged surface answers 304 Not
--    Modified before any page data is read or rendered.
--
--    The row is created lazily by Live::touch() on the first write.
--    Missing rows read as revision 0, so a fresh install needs no
--    seed data.
--
--  Notes
--
--    • `revision` is monotonic and incremented atomically with
--      INSERT … ON DUPLICATE KEY UPDATE revision = revision + 1.
--    • This is not a cache: the page still reads MySQL on every
--      real refresh. The table only says whether a refresh is due.
--    • Deleting a row would make the surface look unchanged (0),
--      so rows are never removed by the app.
--
--      mysql -u root <db> < database/migrations/013_live_revisions.sql
--
--  No `down` note: the table is additive and contains no business
--  records; dropping it only forces the next write to start at 1.
-- =============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS live_revisions (
  surface    VARCHAR(64)     NOT NULL,
  revision   BIGINT UNSIGNED NOT NULL DEFAULT 1,
  updated_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (surface)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
