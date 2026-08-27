<?php

/**
 * The admin activity log.
 *
 * Every action that changes something writes one row here, through this one
 * helper, so the log cannot drift away from what actually happened. Nothing in
 * the application ever updates or deletes a row.
 */

declare(strict_types=1);

require_once __DIR__ . '/../db.php';

/**
 * @param string $action     dotted verb, e.g. 'student.verify'
 * @param string $detail     a plain sentence, shown as-is in the panel
 */
function audit_log(
    string $action,
    ?string $targetType = null,
    ?string $targetId = null,
    string $detail = ''
): void {
    try {
        $stmt = get_db()->prepare(
            'INSERT INTO admin_audit (admin_id, admin_name, role, action, target_type, target_id, detail, ip)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $_SESSION['admin_id']   ?? null,
            $_SESSION['admin_name'] ?? 'unknown',
            $_SESSION['admin_role'] ?? 'none',
            $action,
            $targetType,
            $targetId,
            $detail !== '' ? $detail : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        // A failed write here must never take down the action being logged.
        error_log('[EduTrack] audit write failed: ' . $e->getMessage());
    }
}
