-- Migration 004: record which migrations a database has had applied.
--
-- Until now nothing wrote down what had already run. The only way to answer
-- "is this database up to date?" was to inspect columns and infer, which is
-- exactly how sql/schema.sql was allowed to drift three migrations behind the
-- live tablespace: a fresh install from it came up missing the admins,
-- admin_audit and app_settings tables, and setup-check reported the database
-- as healthy because it only ever looked for the three original tables.
--
-- Run once against an existing edutrack database. A fresh install gets all of
-- this from sql/schema.sql instead, already marked as applied.

USE edutrack;

CREATE TABLE IF NOT EXISTS schema_migrations (
    version    VARCHAR(20)  PRIMARY KEY,   -- the file's numeric prefix, '001'
    filename   VARCHAR(100) NOT NULL,
    applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill for a database that predates this table.
--
-- Each row is recorded only if EVERY change that migration makes is present,
-- so this stays honest on a half-migrated database instead of claiming work
-- that was never done. MySQL DDL is not transactional: a migration that dies
-- partway leaves the statements it already ran in place, so checking only the
-- first thing a migration does would record it as complete and hide the rest.
-- INSERT IGNORE also makes re-running this file harmless.
--
-- 001: the throttling table, plus the indexes added alongside it.
INSERT IGNORE INTO schema_migrations (version, filename)
SELECT '001', '001_harden.sql' FROM DUAL
 WHERE (SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'login_attempts') = 1
   AND (SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND index_name IN ('idx_guard_codes_expires_at', 'idx_guard_codes_used',
                              'idx_users_email_verified', 'idx_users_created_at')) = 4;

-- 002: username became full_name, and its uniqueness was dropped.
INSERT IGNORE INTO schema_migrations (version, filename)
SELECT '002', '002_full_name.sql' FROM DUAL
 WHERE (SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'users'
           AND column_name = 'full_name') = 1
   AND (SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = 'users'
           AND column_name = 'full_name' AND non_unique = 0) = 0;

-- 003: the admin panel. Three tables AND four columns spread over two other
-- tables — the columns come last in the file, so they are what proves it ran
-- to the end.
INSERT IGNORE INTO schema_migrations (version, filename)
SELECT '003', '003_admin.sql' FROM DUAL
 WHERE (SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name IN ('admins', 'admin_audit', 'app_settings')) = 3
   AND (SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'users'
           AND column_name IN ('deactivated_at', 'last_login_at')) = 2
   AND (SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'guard_codes'
           AND column_name IN ('revoked_at', 'issued_by')) = 2;

-- 004: this file.
INSERT IGNORE INTO schema_migrations (version, filename)
VALUES ('004', '004_schema_migrations.sql');
