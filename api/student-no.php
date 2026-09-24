<?php

/**
 * The student number, exactly as the study load prints it under "Student No.":
 *
 *     C24-01-9477-MAN121
 *
 * a letter and two digits, two digits, four digits, then three letters and
 * three digits. One definition, so sign-up, the details form and student
 * verification can never disagree about what a valid number looks like.
 */

declare(strict_types=1);

const STUDENT_NO_PATTERN = '/^[A-Z]\d{2}-\d{2}-\d{4}-[A-Z]{3}\d{3}$/';
const STUDENT_NO_MESSAGE = 'Use the Student No. on your study load, like C24-01-9477-MAN121.';

/**
 * Capitals, and the dashes put back where they belong, so "c24019477man121"
 * and "C24-01-9477-MAN121" are the same number.
 */
function normalize_student_no(string $raw): string
{
    $chars = preg_replace('/[^A-Z0-9]/', '', strtoupper($raw));
    if (strlen($chars) !== 15) {
        return strtoupper(trim($raw));
    }
    return substr($chars, 0, 3) . '-' . substr($chars, 3, 2) . '-' . substr($chars, 5, 4) . '-' . substr($chars, 9);
}

function is_valid_student_no(string $studentNo): bool
{
    return preg_match(STUDENT_NO_PATTERN, $studentNo) === 1;
}

/**
 * Shown back to the student: the year and campus stay readable, the two
 * middle groups that make the number personal are hidden.
 * C24-01-9477-MAN121 becomes C24-••-••••-MAN121.
 */
function mask_student_no(string $studentNo): string
{
    if (is_valid_student_no($studentNo)) {
        return substr($studentNo, 0, 3) . '-••-••••-' . substr($studentNo, -6);
    }

    // Anything saved before the format was enforced: show the last four only.
    $length = strlen($studentNo);
    return $length <= 4
        ? str_repeat('•', $length)
        : str_repeat('•', $length - 4) . substr($studentNo, -4);
}
