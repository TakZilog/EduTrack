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
 *
 * One exception, for people enrolling who have no account yet: the walk from
 * the gate to each room the public enrollment map names (enrollment_route()).
 * Only the photos on those routes are open; the rest of the building is not.
 */
declare(strict_types=1);
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/session.php";

const TOUR_GRAPH_PATH       = __DIR__ . "/../assets/nodes/nodes-edges.json";
const ENROLLMENT_STEPS_PATH = __DIR__ . "/../assets/enrollment/enrollment-steps.json";

/** Why this visitor may not open the full tour, or null when they may. */
function tour_access_refusal(): ?array
{
    app_session_start();
    $id = $_SESSION["user_id"] ?? null;
    if ($id) {
        if (!isset($_SESSION['student_verified_at'])) {
            return ["Verify your student information before opening the room tour.", "verification_required"];
        }
        $s = get_db()->prepare("SELECT email_verified, deactivated_at, student_no, study_load_no FROM users WHERE id = ?");
        $s->execute([$id]);
        $u = $s->fetch();
        if ($u && (int) $u["email_verified"] === 1 && $u["deactivated_at"] === null) {
            if ($u["student_no"] !== null && $u["study_load_no"] !== null) {
                return null;
            }
            return ["Add your student ID number and study load number to open the full room tour.", "details_missing"];
        }
    }

    // Guest / enrolling visitor: no account, so no full room tour.
    return ["The full room tour is for enrolled students. Log in to continue.", "tour_locked"];
}

function require_tour_access(): void
{
    $refusal = tour_access_refusal();
    if ($refusal !== null) {
        json_fail(401, $refusal[0], ["code" => $refusal[1]]);
    }
}

function tour_graph(): array
{
    $graph = json_decode((string) @file_get_contents(TOUR_GRAPH_PATH), true);
    return is_array($graph) ? $graph : ["nodes" => [], "edges" => [], "rooms" => []];
}

/** Room names the public enrollment map links to. */
function enrollment_room_names(): array
{
    $steps = json_decode((string) @file_get_contents(ENROLLMENT_STEPS_PATH), true);
    $names = [];
    if (is_array($steps)) {
        array_walk_recursive($steps, static function ($v, $k) use (&$names) {
            if ($k === "room_name") { $names[(string) $v] = true; }
        });
    }
    // Array keys turn numeric names like "107" into ints; hand back strings.
    return array_map('strval', array_keys($names));
}

/**
 * The walk from the gate to one enrollment room, as a graph holding only the
 * photos on that walk. Null for any room the enrollment map does not name.
 *
 * Same start node and same breadth-first search as map/walkthrough.js, so the
 * page walks exactly this path whether it gets this or the full graph.
 */
function enrollment_route(string $room): ?array
{
    if (!in_array($room, enrollment_room_names(), true)) {
        return null;
    }

    $graph = tour_graph();
    $target = null;
    foreach ($graph["rooms"] as $r) {
        if ((string) $r["room_name"] === $room) { $target = $r; break; }
    }
    if ($target === null || $graph["nodes"] === []) {
        return null;
    }

    $start = $graph["nodes"][0]["node_id"];
    foreach ($graph["nodes"] as $n) {
        if (($n["type"] ?? "") === "landmark") { $start = $n["node_id"]; break; }
    }

    $adjacent = [];
    foreach ($graph["edges"] as $e) {
        $adjacent[$e["from_node"]][] = $e["to_node"];
        $adjacent[$e["to_node"]][]   = $e["from_node"];
    }

    $end   = $target["node_id"];
    $path  = $start === $end ? [$start] : null;
    $seen  = [$start => true];
    $queue = [[$start]];
    while ($path === null && $queue) {
        $walk = array_shift($queue);
        foreach ($adjacent[end($walk)] ?? [] as $next) {
            if (isset($seen[$next])) { continue; }
            $seen[$next] = true;
            $queue[] = [...$walk, $next];
            if ($next === $end) { $path = [...$walk, $next]; break; }
        }
    }
    if ($path === null) {
        return null;
    }

    $onPath = array_flip($path);
    return [
        "nodes" => array_values(array_filter($graph["nodes"], static fn ($n) => isset($onPath[$n["node_id"]]))),
        "edges" => array_values(array_filter($graph["edges"], static fn ($e) =>
            isset($onPath[$e["from_node"]], $onPath[$e["to_node"]]))),
        "rooms" => [$target],
    ];
}

/** Every photo on any enrollment route: what a guest may load. */
function enrollment_route_images(): array
{
    $files = [];
    foreach (enrollment_room_names() as $room) {
        foreach (enrollment_route($room)["nodes"] ?? [] as $n) {
            $files[(string) $n["image_file"]] = true;
        }
    }
    return $files;
}
