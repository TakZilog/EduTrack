-- Migration 001: session hardening support.
--
-- Adds the throttling table and the indexes that were missing from the
-- original schema. Safe to run once against an existing edutrack database.
-- For a fresh install, use sql/schema.sql instead, which already includes
-- everything here.

USE edutrack;

CREATE TABLE IF NOT EXISTS login_attempts (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier   VARCHAR(255) NOT NULL,
    scope        VARCHAR(20)  NOT NULL,
    successful   TINYINT(1)   NOT NULL DEFAULT 0,
    attempted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_attempts_lookup (identifier, scope, attempted_at),
    INDEX idx_attempts_cleanup (attempted_at)
) ENGINE=InnoDB;

-- guard_codes.expires_at is swept on every code issuance and was unindexed.
ALTER TABLE guard_codes
    ADD INDEX idx_guard_codes_expires_at (expires_at),
    ADD INDEX idx_guard_codes_used (used);

-- Both columns are filtered on by the admin student list.
ALTER TABLE users
    ADD INDEX idx_users_email_verified (email_verified),
    ADD INDEX idx_users_created_at (created_at);
