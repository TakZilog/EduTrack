<?php
/**
 * Adds the enrolment numbers to the signed-in student's own account.
 *
 * For accounts created before migration 006, which have no student number or
 * study load number and so cannot open the full room tour. Same formats and
 * the same one-account-per-student-number rule as api/register.php. Only
 * fills empty fields: an account that already has its numbers is refused, so
 * this cannot be used to swap a student number onto someone else's account.
 */

declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/session.php';

app_session_start();
security_headers();
require_post();
csrf_check();

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    json_fail(401, 'Log in first.', ['code' => 'tour_locked']);
}

$input       = json_input();
$studentNo   = strtoupper(trim((string) ($input['studentNo'] ?? '')));
$studyLoadNo = strtoupper(trim((string) ($input['studyLoadNo'] ?? '')));

if (!preg_match('/^[A-Z0-9-]{4,20}$/', $studentNo)) {
    json_fail(400, 'Enter your student ID number exactly as it appears on your school ID.');
}
if (!preg_match('/^[A-Z0-9-]{3,30}$/', $studyLoadNo)) {
    json_fail(400, 'Enter the number printed on your study load.');
}

$pdo = get_db();

$stmt = $pdo->prepare('SELECT email_verified, deactivated_at, student_no, study_load_no FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user || $user['deactivated_at'] !== null || (int) $user['email_verified'] !== 1) {
    json_fail(401, 'Log in first.', ['code' => 'tour_locked']);
}
if ($user['student_no'] !== null && $user['study_load_no'] !== null) {
    json_fail(409, 'Your enrolment details are already on file. Ask the campus office if they need changing.');
}

$stmt = $pdo->prepare('SELECT id FROM users WHERE student_no = ? AND id <> ?');
$stmt->execute([$studentNo, $userId]);
if ($stmt->fetch()) {
    json_fail(400, 'That student ID number already has an account. Ask the campus office if this is a mistake.');
}

try {
    $pdo->prepare('UPDATE users SET student_no = ?, study_load_no = ? WHERE id = ? AND (student_no IS NULL OR study_load_no IS NULL)')
        ->execute([$studentNo, $studyLoadNo, $userId]);
} catch (PDOException $e) {
    // The unique index is the last word if two requests race on one number.
    json_fail(400, 'That student ID number already has an account. Ask the campus office if this is a mistake.');
}

json_ok();
