# EduTrack — Current State

Rewritten 2026-08-28. The previous version described a state that no longer
exists and several things that were never true; every claim below was checked
against the running system rather than carried forward.

## Where it runs

- Project root: `C:\xampp\htdocs\EduTrack` (XAMPP)
- **There is exactly one address.** Use this; do not bookmark anything else.
  `http://localhost/EduTrack/`

  Every page uses relative paths.
- MySQL 8.4 on port 3306, database `edutrack`
- PHP 8.3

This machine now uses XAMPP exclusively. Make sure XAMPP's Apache and MySQL services are running.

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
  `password_hash`, `email_verified`, `deactivated_at`, `last_login_at`,
  `created_at`
- **admins** — separate from `users` on purpose. Anyone can register a student
  account, so putting admin rights on that table would mean a privilege bug
  could mint an admin through the enrolment path.
- **admin_audit** — append-only. Nothing in the application updates or deletes it.
- **app_settings** — operational values only, never credentials.
- **login_attempts** — throttling for every sign-in path.

OTP codes live in `$_SESSION['otp']`, not the database. That is an accepted
tradeoff, not a gap.

## Accounts

- Student: registers, then verifies by email. Logs in with **email**, not a
  username. Full names are not unique, so they cannot be the identifier.
- Admin: three roles. `super_admin` (everything), `admin` (day to day),
  `faculty` (view only). Created with
  `php tools/create-admin.php`.

## The campus map

`assets/nodes/nodes-edges.json` plus WebP panoramas. Not in the database, and
that is fine for now. `walkthrough.js` walks the links breadth-first from the
node with `type == "landmark"` (the gate).

**Today's map is the old hand-built one** (`GATE` / `HALL-01` / `ROOM-105`,
113 photos). Its node `label` values are unreliable, and its hallway photos
are shared between rooms. It is being replaced by a map built from folders.

**The building-and-floor map** (`api/admin/walk-lib.php` describes it): one
gate; for each floor of each building a locked *fixed path* from the gate to
that floor's point; each room its own short walk from the floor point, the
last photo being the room. Ids read `MAIN-F3-PATH-02`, `MAIN-F3-FACULTY-01`,
`MAIN-F3-FACULTY`. Nodes carry `building`, `floor` and `role`; the map lists
`buildings`. The gate and fixed paths can have their pictures replaced but can
never be removed (`graph-write.php`).

It is built once by the developer from a folder tree, with the photos shrunk
to 4096 px WebP 80:

    php tools/import-photos.php --check "C:\School Photos"   report only
    php tools/import-photos.php --build "C:\School Photos"   writes assets/nodes-new/
    php tools/import-photos.php --publish                     puts it live
    php tools/import-photos.php --rollback                    puts the old map back

The folder layout is in the header of `tools/import-photos.php`.
`--make-sample <folder>` makes a small tree to try it on. Shrinking needs
PHP's GD extension: turn on `extension=gd` in `C:\xampp\php\php.ini` and
restart Apache (until then, run the tool as `php -d extension=gd ...`).

After that, staff change it on the **Walkthrough** page of the staff panel:
choose a room's photos again, add, swap, rename or remove a room, or replace
a fixed-path picture. Old photos are deleted when a change is saved.

`assets/nodes/review_report.csv` is left over from the original build and
refers to `N0001`-style ids that no longer exist. It is stale and unused.

## Known open items

- **`LINUX` is unreachable** on today's map. Its three photos form an island
  with no link back to the gate. The folder import replaces it.
- **No HTTPS.** The admin password and session cookie cross the network in
  cleartext. `Secure` is correctly absent from the cookie as a result.
- **Admin passwords** are changed and reset on Users & Access. If nobody who
  can sign in remembers theirs: `php tools/create-admin.php --reset
  --username=NAME` on the server. A reset signs out every session that used
  the old password, and turning an account off ends its sessions at once.
- **The Walkthrough page needs the building map.** On today's map it says so
  and offers nothing to change until the folder import is published.

## Safety nets

- `storage/map-snapshots/` — the map file before each change, last 20 kept.
  Photos are not kept: a replaced photo is deleted.
- `storage/map-previous/` — the whole previous map after `--publish`, until
  `--rollback` or until it is deleted by hand
- `storage/photo-backups/`, `storage/photo-originals-*` — photos from before
  the folder import; delete them once the new map is live and approved
- `tools/reset-ip-allowlist.php` — clears an admin IP restriction that locked
  everyone out
- `tools/setup-check.php` — diagnoses the environment, read-only
