-- Migration 003: the admin panel.
--
-- Admins live in their own table rather than as a role column on `users`.
-- Student accounts only exist behind a guard-issued code, and that physical
-- gate is the product's deliberate trust boundary. Putting admin rights on the
-- same table would mean a privilege bug could mint an admin through the
-- enrolment path. Separate table, seeded by hand from the command line.

USE edutrack;

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
) ENGINE=InnoDB;

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
) ENGINE=InnoDB;

-- Operational values only. Never SMTP or database credentials.
CREATE TABLE IF NOT EXISTS app_settings (
    setting_key   VARCHAR(60) PRIMARY KEY,
    setting_value TEXT        NOT NULL,
    updated_by    INT UNSIGNED NULL,
    updated_at    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
    ('code_lifetime_minutes', '60'),
    ('otp_lifetime_minutes',  '10'),
    ('login_max_attempts',    '5'),
    ('login_lockout_minutes', '15');

-- Soft delete and last-seen for the student list.
ALTER TABLE users
    ADD COLUMN deactivated_at DATETIME NULL,
    ADD COLUMN last_login_at  DATETIME NULL;

-- Revocation, so a mislaid code can be killed without deleting its history.
ALTER TABLE guard_codes
    ADD COLUMN revoked_at DATETIME NULL,
    ADD COLUMN issued_by  VARCHAR(50) NULL;
