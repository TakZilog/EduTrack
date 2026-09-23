<?php
/**
 * Shared gate for the full room tour: a signed-in student whose email is
 * verified, whose account is not turned off, and who gave a student number
 * and study load number.
 *
 * Two refusals, so the page can send people to the right place:
 *   code "details_missing": signed in and verified, but an account from
 *                           before migration 006 with no enrolment numbers.
 *                           The fix is Auth/add-details.html, not the login.
 *   code "tour_locked":     anyone else (guest, unverified, turned off).
 */
declare(strict_types=1);
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/session.php";

function require_tour_access(): void
{
    app_session_start();
    $id = $_SESSION["user_id"] ?? null;
    if ($id) {
        if (!isset($_SESSION['student_verified_at'])) {
            json_fail(401, "Verify your student information before opening the room tour.", ["code" => "verification_required"]);
        }
        $s = get_db()->prepare("SELECT email_verified, deactivated_at, student_no, study_load_no FROM users WHERE id = ?");
        $s->execute([$id]);
        $u = $s->fetch();
        if ($u && (int) $u["email_verified"] === 1 && $u["deactivated_at"] === null) {
            if ($u["student_no"] !== null && $u["study_load_no"] !== null) {
                return;
            }
            json_fail(401, "Add your student ID number and study load number to open the full room tour.", ["code" => "details_missing"]);
        }
        json_fail(401, "The full room tour is for enrolled students. Log in to continue.", ["code" => "tour_locked"]);
    }

    // Guest / enrolling visitor: no account, so no full room tour.
    json_fail(401, "The full room tour is for enrolled students. Log in to continue.", ["code" => "tour_locked"]);
}
