-- EduTrack canonical schema (fresh install).
--
-- This file is the whole database. It is the consolidation of every migration
-- in sql/migrations/ up to and including 004, and it is verified against the
-- live tablespace (MySQL 8.4.3) on 2026-09-02.
--
-- It previously described only users, guard_codes and login_attempts, which
-- meant a fresh install came up without the admin panel's three tables and
-- without four columns the panel reads. The server started, the student side
-- worked, and every staff screen failed on first use. Anything added here from
-- now on must also arrive as a numbered file in sql/migrations/ so existing
-- databases can catch up, and the two must be kept saying the same thing.
--
-- Fresh install:      mysql -uroot < sql/schema.sql
-- Existing database:  run sql/migrations/*.sql in order instead
-- Either way, verify: php tools/setup-check.php
--
-- Note on guard_codes: it carries no student_name or student_id column, and
-- must not. The guard types nothing when issuing a code; who redeemed it is
-- recorded automatically at redemption through used_by_user_id.

CREATE DATABASE IF NOT EXISTS edutrack CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE edutrack;

/* -------------------------------------------------------------- students */

CREATE TABLE IF NOT EXISTS users (
    -- INT UNSIGNED rather than BIGINT: this counts enrolled students at one
    -- campus, and the column is a foreign key target in guard_codes. The
    -- 4-billion ceiling is not a real limit here.
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- Students sign up with their real name. It is deliberately NOT unique:
    -- two people genuinely can both be "Maria Santos". Email is the unique
    -- identifier and the one used to log in.
    full_name       VARCHAR(100) NOT NULL,
    email           VARCHAR(255) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    registered_with_code VARCHAR(10) NULL,
    email_verified  TINYINT(1)   NOT NULL DEFAULT 0,
    -- Soft delete. The admin student list hides these rows rather than
    -- removing them, so a redeemed code keeps pointing at a real account.
    deactivated_at  DATETIME     NULL,
    last_login_at   DATETIME     NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_users_full_name (full_name),
    INDEX idx_users_email_verified (email_verified),
    INDEX idx_users_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* ----------------------------------------------------------- guard codes */

CREATE TABLE IF NOT EXISTS guard_codes (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(10)  NOT NULL UNIQUE,
    used            TINYINT(1)   NOT NULL DEFAULT 0,
    used_by_user_id INT UNSIGNED NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at      DATETIME     NOT NULL,
    used_at         DATETIME     NULL,
    -- Revocation, so a mislaid code can be killed without deleting its
    -- history. A revoked code stays in the list with its issue time intact.
    revoked_at      DATETIME     NULL,
    issued_by       VARCHAR(50)  NULL,
    CONSTRAINT fk_guard_codes_user FOREIGN KEY (used_by_user_id) REFERENCES users(id),
    INDEX idx_guard_codes_expires_at (expires_at),
    INDEX idx_guard_codes_used (used)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* ------------------------------------------------------------ throttling */

-- Throttling for login, guard login, OTP verification and resend, and code
-- issuance. `identifier` is an email, an IP, or a session id depending on
-- scope; `successful = 1` rows are also used as plain action counters.
CREATE TABLE IF NOT EXISTS login_attempts (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier   VARCHAR(255) NOT NULL,
    scope        VARCHAR(20)  NOT NULL,
    successful   TINYINT(1)   NOT NULL DEFAULT 0,
    attempted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Equality columns first, then the range column the window is measured on.
    INDEX idx_attempts_lookup (identifier, scope, attempted_at),
    INDEX idx_attempts_cleanup (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* ----------------------------------------------------------- admin panel */

-- Admins live in their own table rather than as a role column on `users`.
-- Student accounts only exist behind a guard-issued code, and that physical
-- gate is the product's deliberate trust boundary. Putting admin rights on the
-- same table would mean a privilege bug could mint an admin through the
-- enrolment path. Separate table, seeded by hand: php tools/create-admin.php
CREATE TABLE IF NOT EXISTS admins (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(50)  NOT NULL UNIQUE,
    full_name     VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('super_admin','admin','faculty') NOT NULL DEFAULT 'faculty',
    active        TINYINT(1)   NOT NULL DEFAULT 1,
    last_login_at DATETIME     NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admins_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append only. Nothing in the application updates or deletes from this table.
CREATE TABLE IF NOT EXISTS admin_audit (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id    INT UNSIGNED NULL,          -- nulled rather than cascaded on delete
    admin_name  VARCHAR(50)  NOT NULL,      -- denormalised so history survives
    role        VARCHAR(20)  NOT NULL,
    action      VARCHAR(60)  NOT NULL,      -- 'student.verify', 'code.revoke'
    target_type VARCHAR(30)  NULL,
    target_id   VARCHAR(64)  NULL,
    detail      TEXT         NULL,          -- human-readable, shown in the panel
    ip          VARCHAR(45)  NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_created (created_at),
    INDEX idx_audit_admin (admin_id),
    INDEX idx_audit_action (action),
    CONSTRAINT fk_audit_admin FOREIGN KEY (admin_id)
        REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Operational values only. Never SMTP or database credentials.
CREATE TABLE IF NOT EXISTS app_settings (
    setting_key   VARCHAR(60) PRIMARY KEY,
    setting_value TEXT        NOT NULL,
    updated_by    INT UNSIGNED NULL,
    updated_at    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The defaults the panel starts from. INSERT IGNORE so re-running this file
-- against a configured database does not reset anyone's tuning.
INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
    ('code_lifetime_minutes', '60'),
    ('otp_lifetime_minutes',  '10'),
    ('login_max_attempts',    '5'),
    ('login_lockout_minutes', '15');

/* ---------------------------------------------------- migration tracking */

-- Which migration files this database has already had applied. Without it
-- there was no way to answer that question except by inspecting columns and
-- guessing, which is how sql/schema.sql drifted three migrations behind the
-- live tablespace without anyone noticing.
CREATE TABLE IF NOT EXISTS schema_migrations (
    version    VARCHAR(20)  PRIMARY KEY,   -- the file's numeric prefix, '001'
    filename   VARCHAR(100) NOT NULL,
    applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A fresh install of this file already contains everything those migrations
-- do, so they are recorded as applied and must not be run against it.
INSERT IGNORE INTO schema_migrations (version, filename) VALUES
    ('001', '001_harden.sql'),
    ('002', '002_full_name.sql'),
    ('003', '003_admin.sql'),
    ('004', '004_schema_migrations.sql');
