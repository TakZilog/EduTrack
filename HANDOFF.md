# EduTrack — Current State

Rewritten 2026-08-28. The previous version described a state that no longer
exists and several things that were never true; every claim below was checked
against the running system rather than carried forward.

## Where it runs

- Project root: `C:\laragon\www\EduTrack` (Laragon, not XAMPP)
- **There are exactly two addresses, and no others.** Use these; do not
  bookmark anything else.

  | Who | Address |
  | --- | --- |
  | This machine | `http://localhost/EduTrack/` |
  | The guard desk, or any other computer | `http://192.168.0.223/EduTrack/` |

  Both reach the same Apache and the same files. Every page uses relative
  paths, so no page is tied to a particular host.

  The `edutrack.test` and `vendor.test` names were removed on 2026-09-02.
  They were Laragon auto-vhosts nobody needed, and having four ways to open
  one site made it impossible to tell a wrong address from a broken one.
  `AutoVirtualHosts=0` in `C:\laragon\usr\laragon.ini` stops Laragon
  recreating them; the vhost files themselves are gone from
  `C:\laragon\etc\apache2\sites-enabled\`.

  Apache still listens on every interface (`Listen 80`), which is what lets
  the guard desk connect. The Windows Firewall rule "Apache HTTP Server" is
  already enabled and allows the inbound connection.

  `192.168.0.223` is a DHCP lease, so it moves. It was `192.168.0.127` in
  early September. Give this machine a DHCP reservation on the router
  (gateway `192.168.0.1`) for MAC `D8-43-AE-4B-6B-80` so the guard's
  bookmark stops breaking. Until that is done, check the current address
  with `ipconfig` whenever the guard desk cannot connect.
- MySQL 8.4 on port 3306, database `edutrack`
- PHP 8.3

This machine is Laragon-only. XAMPP is still installed at `C:\xampp` but every
one of its services — Apache, MySQL, Tomcat, FileZilla — is stopped and set to
Disabled, so none of them start at boot or contend for a port.

That was not always true. XAMPP's Apache and MariaDB used to run as automatic
services and won ports 80 and 3306 at every boot, which meant the site was
being served from a stale copy at `C:\xampp\htdocs\EduTrack\EduTrack` while the
database lived in `C:\xampp\mysql`. The `edutrack`, `campusvoice` and
`squishy_db` databases were dumped out of MariaDB 10.4 and loaded into Laragon's
MySQL 8.4 on 2026-09-01; `C:\xampp\mysql\data` is untouched and remains a
fallback. The stale web copy is unserved but still on disk — delete it once you
are confident nothing wants it.

To bring XAMPP back for another project, re-enable only the service you need
(`Set-Service mysql -StartupType Automatic`) and move Laragon off that port
first, or the two will fight over it again.

Run `php tools/setup-check.php` for a full environment report: PHP version and
extensions, config, database, schema, and campus map integrity.

## Stack

Plain HTML, CSS and vanilla JavaScript. PHP 8 with PDO. No build step, no
package manager on the frontend, deliberately. Composer is used only for
PHPMailer.

## Database

`sql/schema.sql` is canonical and matches the live database. Migrations in
`sql/migrations/` apply the same changes to an existing install.

- **users** — `full_name` (not unique), `email` (unique, the login identifier),
  `password_hash`, `registered_with_code`, `email_verified`, `deactivated_at`,
  `last_login_at`, `created_at`
- **guard_codes** — `code`, `used`, `used_by_user_id`, `revoked_at`,
  `issued_by`, timestamps. **No `student_name` or `student_id` columns.** An
  earlier note claimed these existed and were `NOT NULL`; the live tablespace
  was read directly and they do not, and never did. The guard types nothing
  when issuing a code: the link to a person is made at redemption through
  `used_by_user_id`.
- **admins** — separate from `users` on purpose. Student accounts sit behind a
  guard-issued code, and putting admin rights on that table would mean a
  privilege bug could mint an admin through the enrolment path.
- **admin_audit** — append-only. Nothing in the application updates or deletes it.
- **app_settings** — operational values only, never credentials.
- **login_attempts** — throttling for every sign-in path.

OTP codes live in `$_SESSION['otp']`, not the database. That is an accepted
tradeoff, not a gap.

## Accounts

- Student: registers with a guard code, then verifies by email. Logs in with
  **email**, not a username. Full names are not unique, so they cannot be the
  identifier.
- Guard: one shared passphrase in `api/config.php`. No individual accounts, so
  the log cannot tell two guards apart.
- Admin: three roles. `super_admin` (everything), `admin` (day to day),
  `faculty` (view only, codes redacted). Created with
  `php tools/create-admin.php`.

## The campus map

`assets/nodes/nodes-edges.json` plus 113 WebP panoramas, about 52 MB. Not in
the database, and that is fine for now.

**The map is not reproducible from the build script.** `build_node_graph_v2.py`
emits `N0001`-style ids and `type="unassigned"`; the live map uses
`GATE` / `HALL-01` / `ROOM-105` with types assigned by hand afterwards.
`walkthrough.js` finds the starting point with `type == "landmark"`, so a
rebuild would break the walkthrough. That script now refuses to overwrite an
existing map without `--force`.

To add a room, or re-record one with more photos:

    python map/add_walk.py "<walk folder>" --room "NAME" --floor "FLOOR" --dry-run
    python map/add_walk.py "<walk folder>" --room "NAME" --floor "FLOOR" --replace

It hashes the photos already on disk, recognises corridors you re-walked, and
only creates nodes for what it has never seen. Existing ids, types and labels
are never touched.

`assets/nodes/review_report.csv` is left over from the original build and
refers to `N0001`-style ids that no longer exist. It is stale and unused.

Node `label` values are unreliable: each is the folder that first produced that
photo, so a node now called `ROOM-105` can still carry the label `101`. The
room list is authoritative; the id is a better fallback than the label.

## Known open items

- **`LINUX` is unreachable.** Its three photos form an island with no link back
  to the gate, because the walk was recorded starting mid-building. Fixed by
  re-walking it from the gate with `add_walk.py --replace`.
- **No HTTPS.** The admin password and session cookie cross the network in
  cleartext. `Secure` is correctly absent from the cookie as a result.
- **No password change or reset** for admins. Recovery is
  `tools/create-admin.php` on the server.
- **Adding a room is command line only.** Replacing a single photo works in the
  panel; adding a room needs the image matching, which lives in Python.
- Guard codes are stored in plain text. Short-lived and single-use; an accepted
  tradeoff.

## Safety nets

- `storage/map-snapshots/` — the map before each change, last 20 kept
- `storage/photo-backups/` — the previous photo before each replacement
- `tools/reset-ip-allowlist.php` — clears an admin IP restriction that locked
  everyone out
- `tools/setup-check.php` — diagnoses the environment, read-only
