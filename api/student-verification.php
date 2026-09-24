<?php

declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/student-no.php';
require __DIR__ . '/session.php';
require __DIR__ . '/rate-limit.php';

app_session_start();
security_headers();
require_post();
csrf_check();

const VERIFICATION_TTL = 600;
const VERIFY_FALLBACK_MESSAGE = 'Student verification could not be completed. Please check your credentials and enrollment information.';

// Verification confirms the student who is already signed in; it is not a way
// in. Looking an account up by student ID alone signed in anyone who had seen
// that ID, and it is printed on the school ID card.
$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId === 0) {
    json_fail(401, 'Log in first.', ['code' => 'tour_locked']);
}

$input = json_input();
$action = (string) ($input['action'] ?? '');
$studentNo = normalize_student_id((string) ($input['studentId'] ?? ''));
$schoolYear = trim((string) ($input['schoolYear'] ?? ''));
$semester = trim((string) ($input['semester'] ?? ''));

$studentIdEnabled = strtolower(trim((string) setting('student_id_verification_enabled', '1'))) !== '0';
$requireActiveStudyLoad = strtolower(trim((string) setting('require_active_study_load_enabled', '1'))) !== '0';
$verifyCurrentSemesterEnabled = strtolower(trim((string) setting('verify_current_semester_enabled', '1'))) !== '0';
$verifyCurrentSchoolYearEnabled = strtolower(trim((string) setting('verify_current_school_year_enabled', '1'))) !== '0';

$ip = client_ip();
rate_limit_check($ip, 'student_verify', 8, 15, 'Too many verification attempts. Wait %d minutes and try again.');

if ($action === 'lookup') {
    if ($studentIdEnabled && !is_valid_student_id($studentNo)) {
        json_fail(400, STUDENT_ID_MESSAGE);
    }
    if (($verifyCurrentSemesterEnabled && $semester !== '1') || ($verifyCurrentSchoolYearEnabled && $schoolYear !== '2026-2027')) {
        rate_limit_record($ip, 'student_verify', false);
        json_fail(401, VERIFY_FALLBACK_MESSAGE, ['code' => 'verification_failed']);
    }

    $stmt = get_db()->prepare(
        'SELECT id, full_name, email, student_no, study_load_no
           FROM users
          WHERE student_no = ?
            AND id = ?
            AND email_verified = 1
            AND deactivated_at IS NULL' . ($requireActiveStudyLoad ? ' AND study_load_no IS NOT NULL' : '')
    );
    $stmt->execute([$studentNo, $userId]);
    $user = $stmt->fetch();

    if (!$user) {
        rate_limit_record($ip, 'student_verify', false);
        json_fail(401, VERIFY_FALLBACK_MESSAGE, ['code' => 'verification_failed']);
    }

    $_SESSION['student_verification'] = [
        'user_id' => (int) $user['id'],
        'student_no' => $user['student_no'],
        'expires_at' => time() + VERIFICATION_TTL,
    ];
    rate_limit_record($ip, 'student_verify', true);

    json_ok([
        'studentName' => $user['full_name'],
        'maskedStudentId' => mask_student_id($user['student_no']),
        'program' => 'BS Information Technology',
    ]);
}

if ($action === 'verify-study-load') {
    $pending = $_SESSION['student_verification'] ?? null;
    if (!is_array($pending) || (int) ($pending['expires_at'] ?? 0) < time() || (int) ($pending['user_id'] ?? 0) !== $userId) {
        unset($_SESSION['student_verification']);
        json_fail(401, VERIFY_FALLBACK_MESSAGE, ['code' => 'verification_expired']);
    }

    $stmt = get_db()->prepare(
        'SELECT id, full_name, email, student_no, study_load_no
           FROM users
          WHERE id = ?
            AND email_verified = 1
            AND deactivated_at IS NULL
            AND student_no = ?' . ($requireActiveStudyLoad ? ' AND study_load_no IS NOT NULL' : '')
    );
    $stmt->execute([(int) $pending['user_id'], (string) $pending['student_no']]);
    $user = $stmt->fetch();

    if (!$user) {
        unset($_SESSION['student_verification']);
        rate_limit_record($ip, 'student_verify', false);
        json_fail(401, VERIFY_FALLBACK_MESSAGE, ['code' => 'verification_failed']);
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['student_verified_at'] = time();
    unset($_SESSION['student_verification']);
    rate_limit_record($ip, 'student_verify', true);

    json_ok([
        'studentName' => $user['full_name'],
        'maskedStudentId' => mask_student_id($user['student_no']),
        'program' => 'BS Information Technology',
    ]);
}

json_fail(400, 'That verification step is not recognised.');

