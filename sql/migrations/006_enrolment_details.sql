-- Migration 006: enrolment details on student accounts.
--
-- The full room tour is for enrolled students only. At registration a student
-- gives their student ID number and the number on their study load, alongside
-- the email code they already confirm. The student number is unique: one
-- account per enrolled student.
--
-- Existing accounts keep NULL here and are asked for both on their next
-- sign-in before the tour opens.

USE edutrack;

ALTER TABLE users
    ADD COLUMN student_no    VARCHAR(20) NULL AFTER email,
    ADD COLUMN study_load_no VARCHAR(30) NULL AFTER student_no,
    ADD UNIQUE KEY uq_users_student_no (student_no);

INSERT IGNORE INTO schema_migrations (version, filename)
VALUES ('006', '006_enrolment_details.sql');
