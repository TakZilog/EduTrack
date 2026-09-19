-- Migration 005: remove registration codes.
--
-- Students register with an email address and verify it by OTP. The one-time
-- registration code table, the column that recorded which code an account
-- used, and the setting that controlled how long a code lasted are all gone.
--
-- Run once against an existing edutrack database. A fresh install gets this
-- from sql/schema.sql instead, already marked as applied. Safe to re-run.

USE edutrack;

DROP TABLE IF EXISTS guard_codes;

-- MySQL 8 has no DROP COLUMN IF EXISTS, so check first.
SET @has_col := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'users'
       AND column_name  = 'registered_with_code'
);
SET @sql := IF(@has_col > 0,
    'ALTER TABLE users DROP COLUMN registered_with_code',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

DELETE FROM app_settings WHERE setting_key = 'code_lifetime_minutes';

INSERT IGNORE INTO schema_migrations (version, filename)
VALUES ('005', '005_remove_codes.sql');
