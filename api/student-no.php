<?php

/**
 * The two numbers a student signs up with. One definition each, so sign-up,
 * the details form and student verification can never disagree about what a
 * valid number looks like. Auth/auth.js holds the same rules for the forms.
 *
 *   Student ID number    on the school ID card, 11 digits:   24001177500
 *   Study load number    printed on the study load:          C24-01-9477-MAN121
 *
 * Stored in users.student_no and users.study_load_no. One account per number.
 */

declare(strict_types=1);

const STUDENT_ID_PATTERN = '/^\d{11}$/';
const STUDENT_ID_MESSAGE = 'Use the 11-digit number on your school ID, like 24001177500.';

const STUDY_LOAD_PATTERN = '/^[A-Z]\d{2}-\d{2}-\d{4}-[A-Z]{3}\d{3}$/';
const STUDY_LOAD_MESSAGE = 'Use the number on your study load, like C24-01-9477-MAN121.';

/**
 * Spaces and dashes out, so "2400 1177 500" is the same number. Letters stay,
 * so a study load number typed here fails instead of being read as digits.
 */
function normalize_student_id(string $raw): string
{
    return preg_replace('/[\s-]/', '', trim($raw)) ?? '';
}

function is_valid_student_id(string $studentId): bool
{
    return preg_match(STUDENT_ID_PATTERN, $studentId) === 1;
}

/**
 * Capitals, and the dashes put back where they belong, so "c24019477man121"
 * and "C24-01-9477-MAN121" are the same number.
 */
function normalize_study_load_no(string $raw): string
{
    $chars = preg_replace('/[^A-Z0-9]/', '', strtoupper($raw));
    if (strlen($chars) !== 15) {
        return strtoupper(trim($raw));
    }
    return substr($chars, 0, 3) . '-' . substr($chars, 3, 2) . '-' . substr($chars, 5, 4) . '-' . substr($chars, 9);
}

function is_valid_study_load_no(string $studyLoadNo): bool
{
    return preg_match(STUDY_LOAD_PATTERN, $studyLoadNo) === 1;
}

/**
 * Shown back to the student: the last four digits only.
 * 24001177500 becomes •••••••7500.
 */
function mask_student_id(string $studentId): string
{
    $length = strlen($studentId);
    return $length <= 4
        ? str_repeat('•', $length)
        : str_repeat('•', $length - 4) . substr($studentId, -4);
}
