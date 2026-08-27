-- Migration 002: students register with their full name, and log in by email.
--
-- A username was never shown to anyone and duplicated what the email already
-- identifies. What the guard desk and the admin panel actually need is the
-- student's real name, which is why this replaces it.
--
-- Full names are NOT unique. Two students can genuinely share one, so the
-- UNIQUE constraint moves off this column entirely; email already carries it
-- and is now the login identifier.
--
-- Run once against an existing edutrack database. A fresh install gets all of
-- this from sql/schema.sql instead.

USE edutrack;

ALTER TABLE users
    CHANGE COLUMN username full_name VARCHAR(100) NOT NULL;

-- Drop the uniqueness that came with the old username column.
ALTER TABLE users
    DROP INDEX username;

ALTER TABLE users
    ADD INDEX idx_users_full_name (full_name);
