<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$input = admin_boot('student.view', 'POST');

$action = (string) ($input['action'] ?? '');
$id     = (int) ($input['id'] ?? 0);

/*
  The permission for this specific action is checked before the student is
  looked up. Checking afterwards let a view-only account tell an existing
  student from a missing one by the difference between 404 and 403, and that
  path also skipped the audit log.
*/
$needed = match ($action) {
    'verify'                   => 'student.verify',
    'deactivate', 'reactivate' => 'student.deactivate',
    'delete'                   => 'student.delete',
    default                    => null,
};

if ($needed === null) {
    json_fail(400, 'That action is not recognised.');
}

if (!can($needed)) {
    audit_log('access.denied', 'permission', $needed,
        'Tried to change a student account without permission.');
    json_fail(403, 'Your account does not have access to that.');
}

if ($id <= 0) {
    json_fail(400, 'No student was chosen.');
}

$pdo  = get_db();
$stmt = $pdo->prepare('SELECT id, full_name, email, email_verified, deactivated_at FROM users WHERE id = ?');
$stmt->execute([$id]);
$student = $stmt->fetch();

if (!$student) {
    json_fail(404, 'That student no longer exists. The list may be out of date, so please refresh.');
}

$name = $student['full_name'];

switch ($action) {
    case 'verify':
        if ((int) $student['email_verified'] === 1) {
            json_fail(400, $name . ' is already verified.');
        }

        $pdo->prepare('UPDATE users SET email_verified = 1 WHERE id = ?')->execute([$id]);
        audit_log('student.verify', 'student', (string) $id, "Marked {$name} as verified by hand.");

        json_ok(['message' => $name . ' can now sign in.']);
        // no break, json_ok exits

    case 'deactivate':
        if ($student['deactivated_at'] !== null) {
            json_fail(400, $name . ' is already turned off.');
        }

        $pdo->prepare('UPDATE users SET deactivated_at = NOW() WHERE id = ?')->execute([$id]);
        audit_log('student.deactivate', 'student', (string) $id, "Turned off {$name}'s account.");

        json_ok(['message' => $name . ' can no longer sign in.']);

    case 'reactivate':
        if ($student['deactivated_at'] === null) {
            json_fail(400, $name . ' is already active.');
        }

        $pdo->prepare('UPDATE users SET deactivated_at = NULL WHERE id = ?')->execute([$id]);
        audit_log('student.reactivate', 'student', (string) $id, "Turned {$name}'s account back on.");

        json_ok(['message' => $name . ' can sign in again.']);

    case 'delete':
        // Typing the email is the confirmation. It is the unique field, so it
        // cannot be confused between two students with the same name.
        if (strtolower(trim((string) ($input['confirmEmail'] ?? ''))) !== strtolower($student['email'])) {
            json_fail(400, 'To delete this student, type their email address exactly.');
        }

        try {
            $pdo->beginTransaction();
            // The guard code keeps its history, it just stops pointing at a row
            // that is about to disappear.
            $pdo->prepare('UPDATE guard_codes SET used_by_user_id = NULL WHERE used_by_user_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            json_fail(500, 'The student could not be deleted. Nothing was changed.');
        }

        audit_log('student.delete', 'student', (string) $id, "Permanently deleted {$name} ({$student['email']}).");

        json_ok(['message' => $name . ' has been deleted.']);
}

// Unreachable: the match above rejects any action not listed there.
json_fail(400, 'That action is not recognised.');
