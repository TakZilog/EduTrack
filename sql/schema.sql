-- EduTrack canonical schema (fresh install).
--
-- Verified against the live MariaDB tablespace on 2026-08-26: guard_codes
-- carries no student_name or student_id columns. The earlier note in
-- HANDOFF.md claiming otherwise is out of date, and api/issue-code.php is
-- correct as written. The guard types nothing when issuing a code; who used it
-- is recorded automatically at redemption through used_by_user_id.
--
-- For an existing database, run the files in sql/migrations/ instead.

CREATE DATABASE IF NOT EXISTS edutrack CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE edutrack;

CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- Students sign up with their real name. It is deliberately NOT unique:
    -- two people genuinely can both be "Maria Santos". Email is the unique
    -- identifier and the one used to log in.
    full_name       VARCHAR(100) NOT NULL,
    email           VARCHAR(255) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    registered_with_code VARCHAR(10) NULL,
    email_verified  TINYINT(1)   NOT NULL DEFAULT 0,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_users_full_name (full_name),
    INDEX idx_users_email_verified (email_verified),
    INDEX idx_users_created_at (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS guard_codes (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(10)  NOT NULL UNIQUE,
    used            TINYINT(1)   NOT NULL DEFAULT 0,
    used_by_user_id INT UNSIGNED NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at      DATETIME     NOT NULL,
    used_at         DATETIME     NULL,
    CONSTRAINT fk_guard_codes_user FOREIGN KEY (used_by_user_id) REFERENCES users(id),
    INDEX idx_guard_codes_expires_at (expires_at),
    INDEX idx_guard_codes_used (used)
) ENGINE=InnoDB;

-- Throttling for login, guard login, OTP verification and resend, and code
-- issuance. `identifier` is an email, an IP, or a session id depending on
-- scope; `successful = 1` rows are also used as plain action counters.
CREATE TABLE IF NOT EXISTS login_attempts (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier   VARCHAR(255) NOT NULL,
    scope        VARCHAR(20)  NOT NULL,
    successful   TINYINT(1)   NOT NULL DEFAULT 0,
    attempted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_attempts_lookup (identifier, scope, attempted_at),
    INDEX idx_attempts_cleanup (attempted_at)
) ENGINE=InnoDB;
