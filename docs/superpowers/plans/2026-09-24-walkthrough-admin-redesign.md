# Walkthrough Admin Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Staff change a room's photos, swap two rooms or rename a room from one admin page, on a map organised as buildings, floors, locked fixed paths and per-room walks, imported once from folders.

**Architecture:** The map file keeps its `nodes` / `edges` / `rooms` shape and gains `buildings` plus `building`, `floor`, `role` on each node. One shared PHP library (`api/admin/walk-lib.php`) owns the model (names, ids, photo checks, shrinking, room walks) and is used by the command-line import tool and the new admin endpoints. Every map write goes through `graph-write.php`, which gains `update_graph()` (read, change, validate and write under one lock), new validation rules and the fixed-path lock.

**Tech Stack:** PHP 8.2 (XAMPP, GD + EXIF), vanilla JS (no bundler, CSP forbids inline scripts), Pannellum viewer (unchanged).

**Spec:** `docs/superpowers/specs/2026-09-24-walkthrough-admin-redesign-design.md`

**Execution method:** Native (this session), one reviewer at the end.

## Global Constraints

- Map file stays `assets/nodes/nodes-edges.json`; `nodes` / `edges` / `rooms` keep their shape; the gate keeps `type: "landmark"`.
- Every live-map write goes through `api/admin/graph-write.php` (lock, validate, snapshot to `storage/map-snapshots`, atomic rename).
- Photos: resized to 4096 px wide (never enlarged), WebP quality 80. Uploads ≤ 25 MB, type judged by content, ratio 1.9–2.1.
- No backup copies of photos. Old photos are deleted after the change that replaced them is saved. Only JSON snapshots are kept (last 20).
- Node id / image file: `GATE`, `MAIN-F3-PATH-02`, `MAIN-F3-FACULTY-01`, `MAIN-F3-FACULTY`; image file = id + `.webp`.
- Building code: folder name without the word "Building", upper-case letters and digits (`MAIN`, `SECOND`).
- Room `floor` strings: `"<N>ST FLOOR <BUILDING NAME>"`, e.g. `1ST FLOOR MAIN BUILDING`.
- Room names are stored in capitals; `Room 201` is stored as `201` (matches today's data and `enrollment-steps.json`).
- Admin endpoints: `admin_boot()` (session, CSRF, `can()`); `room.view` to read, `room.edit` to change.
- Admin pages: no inline scripts (CSP), `assets/css/admin.css` tokens, sentence case, 56px targets.
- Hard rules for this work: never rebuild or replace the live map or delete photos in `assets/nodes`; `--publish` is only run in a throw-away test copy; do not delete `storage/photo-originals-*` or `storage/photo-backups`; ask before editing `php.ini`; commit per task on branch `Mobile`, no push.
- Never reinstate or mention the removed guard desk / registration codes.

## Review Focus

1. **Two staff save at the same time.** Each save must be worked out from the map as it is at that moment, under the lock, so neither change is lost and no deleted photo is left referenced. Owned by Task 2 (`update_graph`) and Task 4 (`room-walk.php` does all map work inside the callback). Proof: code review of the callback boundary.
2. **A room walk bigger than `post_max_size` (40 MB).** PHP drops the whole body; the endpoint must say the photos were too large, not "choose photos". Owned by Task 4. Proof: Task 9 sends a 41 MB body with curl.
3. **A save refused after new photos were written.** No new file may be left behind and the old walk must stay live. Owned by Task 4 (shutdown cleanup). Proof: Task 9 adds a room with a duplicate name, then checks `storage/walk-uploads` and `assets/nodes` for leftovers.
4. **Renaming or removing a room the enrollment guide links to.** Rename must update `enrollment-steps.json` in place; remove must be refused with a reason. Owned by Task 5. Proof: Task 9 in the test copy.
5. **Photo folders with missing taken-times, non-360 photos, non-photo files, empty folders, duplicate names.** `--check` must report each, `--build` must refuse errors. Owned by Task 3. Proof: Task 3 sample run.

---

## File map

| File | Change | Responsibility |
|---|---|---|
| `api/admin/walk-lib.php` | create | Building/floor model: names, ids, photo checks, shrinking, room-walk graph edits, enrollment rename |
| `api/admin/graph-write.php` | modify | `update_graph()`, `validate_graph()` rules (one gate, rooms reachable, floor rules), fixed-path lock |
| `api/admin/thumb.php` | modify | Use `THUMBS_DIR` from walk-lib |
| `tools/import-photos.php` | create | `--make-sample`, `--check`, `--build`, `--publish`, `--rollback` |
| `api/admin/walkthrough.php` | create | GET buildings > floors > fixed path > rooms |
| `api/admin/room-walk.php` | create | POST a room's ordered photos (update or add) |
| `api/admin/room-action.php` | modify | `update_graph`; `swap`; rename relabels + syncs enrollment; remove deletes own photos, refused for enrollment rooms |
| `api/admin/photo-replace.php` | modify | `admin_boot`, no backup, WebP 80 via walk-lib |
| `api/admin/rooms.php` | modify | `structured` flag |
| `admin/walkthrough.html`, `admin/walkthrough.js` | create | The Walkthrough page |
| `assets/css/admin.css` | modify | Walkthrough page styles; drop navigation-map styles |
| `assets/js/admin/admin.js` | modify | Menu: Walkthrough replaces Navigation Map |
| `admin/navigation-map.html`, `admin/navigation-map.js` | delete | Replaced |
| `admin/rooms.js` | modify | No per-step replace; new floor labels; floor field only on the old map |
| `map/select-room.js`, `map/select-room.html` | modify | Group by building, then floor |
| `map/add_walk.py`, `map/build_node_graph_v2.py` | delete | Replaced by the import tool |
| `HANDOFF.md`, `.gitignore` | modify | Document the import; ignore `assets/nodes-new/` |

---

### Task 1: Shared walk library

**Files:**
- Create: `api/admin/walk-lib.php`
- Modify: `api/admin/thumb.php` (use `THUMBS_DIR`)

**Interfaces:**
- Produces (used by Tasks 3–5):
  - constants `PANORAMA_WIDTH=4096`, `PANORAMA_QUALITY=80`, `MAX_PHOTO_BYTES`, `MAX_WALK_PHOTOS=20`, `THUMBS_DIR`
  - `canonical_room_name(string): string`, `building_code(string): string`, `ordinal(int): string`, `floor_string(int, string): string`, `floor_number(string): ?int`, `name_slug(string): string`
  - `room_photo_ids(string $code, int $floor, string $room, int $count, callable $taken): string[]` (room id last)
  - `photo_problem(string $path): ?string`, `upload_problem(array $file): ?string`, `taken_time(string $path): ?string`
  - `gd_ready(): bool`, `write_panorama(string $source, string $target): void` (throws `RuntimeException`)
  - `map_has_buildings(array): bool`, `building_name(array, string): ?string`, `floor_point(array, string, int): ?string`, `fixed_path(array, string $point): string[]`, `room_own_nodes(array, string $roomNodeId): string[]`
  - `add_room_walk(array, string $code, int $floor, string $room, array $ids): array`, `drop_nodes(array, array $ids): array`, `relabel_room(array, string $roomNodeId, string $name): array`
  - `delete_photo_files(string $dir, array $nodes): void`, `enrollment_rename(string $old, string $new): bool`
- Consumes: `tour-gate.php` (`ENROLLMENT_STEPS_PATH`, `enrollment_room_names()`).

- [ ] **Step 1: Write `api/admin/walk-lib.php`** with the functions above. Key rules:
  - `canonical_room_name`: collapse whitespace, trim, `mb_strtoupper`; `ROOM 201` becomes `201`.
  - `building_code`: drop the word "building", keep `[A-Z0-9]`, max 12 chars.
  - `ordinal`: 1ST 2ND 3RD 4TH, 11TH 12TH 13TH, 21ST.
  - `name_slug`: ASCII transliteration, non-alphanumerics to `-`, max 24 chars, `ROOM` when empty.
  - `room_photo_ids`: `KEY-01…KEY-NN, KEY` for N−1 walk photos, KEY = `CODE-F<n>-<SLUG>`; first of KEY, KEY-V2 … KEY-V99 where no id is `$taken`.
  - `photo_problem`: getimagesize type JPEG/PNG/WebP; ratio 1.9–2.1; plain-language message.
  - `upload_problem`: upload error codes, 25 MB, `is_uploaded_file`, `photo_problem`, and more than 40 million pixels refused (the page shrinks first).
  - `taken_time`: EXIF `DateTimeOriginal` matching `YYYY:MM:DD HH:MM:SS`, not `0000…`.
  - `write_panorama`: decode by content type, palette to truecolor, shrink with `imagecopyresampled` when wider than 4096, `imagewebp` q80 to `target.tmp`, `rename` into place.
  - `room_own_nodes`: from the room node, follow neighbours with role `room-walk`; returns walk order (first walk photo … room).
  - `fixed_path`: from the floor point, follow `path` neighbours back to the gate; returns gate first.
  - `add_room_walk`: nodes `{node_id,label,image_file,type,building,floor,role}`; walk photos `type:"hallway", role:"room-walk", label:"On the way to <ROOM>"`; last `type:"room", role:"room", label:<ROOM>`; edges point → walk… → room.
  - `relabel_room`: room node label = name (both map kinds); own walk labels "On the way to <name>".
  - `enrollment_rename`: regex replace of `"room_name": "<old>"` values (formatting kept), JSON re-validated, temp + rename.

- [ ] **Step 2: Point `thumb.php` at `THUMBS_DIR`** (require walk-lib, drop its own `THUMB_DIR`).

- [ ] **Step 3: Lint and smoke-test**

Run:
```bash
php -l api/admin/walk-lib.php && php -l api/admin/thumb.php
php -r 'require "api/admin/walk-lib.php";
  foreach (["Room 201","  cashier  office ","Canteen and Auditorium"] as $n) echo canonical_room_name($n), "|";
  echo building_code("Main Building"), building_code("Second Building"), "|", ordinal(1), ordinal(2), ordinal(3), ordinal(11), ordinal(22), "|";
  echo floor_number("1st Floor"), floor_number("3rd floor"), var_export(floor_number("Fixed Path"), true), "|";
  $t = ["MAIN-F1-CASHIER-01" => 1]; echo implode(",", room_photo_ids("MAIN", 1, "CASHIER", 3, fn($id) => isset($t[$id]))), "|";
  echo name_slug("!!!"), name_slug("Deans Office");'
```
Expected: `201|CASHIER OFFICE|CANTEEN AND AUDITORIUM|MAINSECOND|1ST2ND3RD11TH22ND|13NULL|MAIN-F1-CASHIER-V2-01,MAIN-F1-CASHIER-V2-02,MAIN-F1-CASHIER-V2|ROOMDEANS-OFFICE`

- [ ] **Step 4: Commit** `feat: shared walk library for the building and floor map`

---

### Task 2: Map writes under one lock, new rules, fixed-path lock

**Files:**
- Modify: `api/admin/graph-write.php`

**Interfaces:**
- Produces: `update_graph(callable $change): string` (snapshot name; `$change(array $current): array`), `save_graph(array): string` (now a thin wrapper), `validate_floors(array): string[]`, `locked_photos_removed(array $before, array $after): string[]`.

- [ ] **Step 1: Extend `validate_graph()`**
  - More than one `landmark`: "More than one photo is marked as the starting point. Only the main gate may be."
  - Each listed room whose photo exists but is not reachable: `The room photo <node_id> cannot be reached from the gate.` (keyed by node id so a rename does not look like a new fault).
  - When `buildings` exists, append `validate_floors()`: exactly one `role:"gate"` node and it is the landmark; each floor's `point` exists with role `path`, same building and floor; every edge touching a `path` node joins the gate or a node of the same building and floor.

- [ ] **Step 2: Add `locked_photos_removed()`**: every `gate`/`path` node of the old map must still exist with the same role.

- [ ] **Step 3: Add `update_graph()`**: lock `nodes-edges.json.lock`, read the file, call `$change`, refuse introduced `validate_graph` errors plus `locked_photos_removed`, snapshot, temp write, rename. `save_graph($g)` becomes `update_graph(static fn () => $g)`.

- [ ] **Step 4: Prove the rules without touching the live map**

Run a scratch script that requires `graph-lib.php` + `graph-write.php` + `walk-lib.php`, defines a stub `json_fail`, and checks:
```
validate_graph(live map)            -> only faults that exist today (LINUX unreachable)
validate_graph(small structured)    -> []
second landmark                     -> "More than one photo..."
path node linked to another floor   -> "...is linked to a different floor."
locked_photos_removed(drop PATH-01) -> "...is locked and cannot be removed."
```
Expected: exactly those lines. `php -l api/admin/graph-write.php` clean.

- [ ] **Step 5: Commit** `feat: map writes under one lock, floor rules and locked fixed paths`

---

### Task 3: Import tool

**Files:**
- Create: `tools/import-photos.php`
- Modify: `.gitignore` (add `assets/nodes-new/`)

**Interfaces:**
- Consumes: walk-lib (names, ids, `photo_problem`, `taken_time`, `write_panorama`, `add_room_walk`), graph-write (`validate_graph`), tour-gate (`enrollment_room_names`).
- CLI:
  ```
  php tools/import-photos.php --make-sample DIR   (test tree, needs GD)
  php tools/import-photos.php --check  "C:\School Photos"
  php tools/import-photos.php --build  "C:\School Photos"   -> assets/nodes-new/
  php tools/import-photos.php --publish                       nodes-new -> nodes, old -> storage/map-previous
  php tools/import-photos.php --rollback                      back again
  ```
  Until GD is on in php.ini: `php -d extension=gd tools/import-photos.php ...`.

- [ ] **Step 1: Scanner** `scan_tree(string $root): array` → gate photo, buildings (name, fixed paths per floor, rooms per floor, ordered photos), errors, warnings, notes.
  - Errors: no `Main Gate` folder or not exactly one photo in it; building code empty or shared; no `Fixed Path`; folder that is not a floor name; empty fixed-path or room folder; unreadable or non-360 photo; duplicate room name (case-insensitive); a floor with rooms and no fixed path.
  - Warnings: non-photo files (skipped, e.g. `.insp`, `.txt`); a folder where any photo has no taken-time (ordered by file name instead); each room of today's map with no folder, or in the other building (Second Building list from the spec, `AUDITORIUM` expected as `CANTEEN AND AUDITORIUM`); each enrollment room name the new map lacks.
  - Notes: rooms that are new compared with today's map.
  - Photo order: taken-time then file name when every photo has one; natural file-name order otherwise.

- [ ] **Step 2: `--check`** prints errors, warnings, notes, then counts (buildings, floors, rooms, photos); exit 1 on errors.

- [ ] **Step 3: `--build`** refuses on errors; recreates `assets/nodes-new/`, copies `assets/nodes/.htaccess` into it (the folder must stay closed to browsers), writes each photo with `write_panorama`, builds the graph (GATE → floor paths → room walks, `buildings`, `rooms` with `floor_string`), `validate_graph` must be empty, writes `nodes-edges.json`, prints total MB and average KB.

- [ ] **Step 4: `--publish` / `--rollback`**: directory renames with rollback of the first rename when the second fails; refuse when `storage/map-previous` (publish) or `assets/nodes-new` (rollback) already exists; clear `storage/thumbs/*.webp` after either.

- [ ] **Step 5: `--make-sample DIR`**: 512×256 JPEGs with a big label drawn in, EXIF `DateTimeOriginal` written in for most, so file-name order and taken-time order differ. Tree: Main Gate (1); Main Building / Fixed Path / 1st Floor (2), 2nd Floor (3); 1st Floor / Cashier (3, taken-time reversed against names), Room 107 (2 + `square.jpg` non-360); 2nd Floor / Library (2, no EXIF), Store Room (empty), `readme.txt`.

- [ ] **Step 6: Run the sample**

```bash
php -d extension=gd tools/import-photos.php --make-sample "$SCRATCH/sample"
php -d extension=gd tools/import-photos.php --check "$SCRATCH/sample"
```
Expected errors: `square.jpg` not a 360 photo; `Store Room` is empty. Warnings: `readme.txt` skipped; Library ordered by file name; today's rooms missing (many, expected for a sample); enrollment rooms missing except `CASHIER` and `107`. Exit code 1.

Then delete `square.jpg` and `Store Room`, run `--check` (exit 0) and `--build`; confirm `assets/nodes-new/` holds `.htaccess`, `GATE.webp`, `MAIN-F1-PATH-01/02`, `MAIN-F2-PATH-01..03`, `MAIN-F1-CASHIER-01/02 + MAIN-F1-CASHIER` in taken-time order, `MAIN-F1-107-01 + MAIN-F1-107`, `MAIN-F2-LIBRARY-01 + MAIN-F2-LIBRARY`; JSON validates. Then remove `assets/nodes-new/` (sample only, never published here).

- [ ] **Step 7: Commit** `feat: import tool builds the map from photo folders`

---

### Task 4: Walkthrough read endpoint and room-walk save

**Files:**
- Create: `api/admin/walkthrough.php`, `api/admin/room-walk.php`

**Interfaces:**
- `GET walkthrough.php` (`room.view`) → `{ok, structured, buildings:[{code,name,floors:[{n,label,path:[nodeId…],rooms:[{name,nodeId,photos}]}]}], enrollmentRooms:[…]}`; `structured:false` on today's map.
- `POST room-walk.php` (`room.edit`, multipart) fields `mode` (update|add), `building`, `floor`, `room`, `count`, `photos[]` → `{ok,message,snapshot}`.

- [ ] **Step 1: `walkthrough.php`** as above; rooms per floor from each room node's `building`/`floor`, natural sort; `path` from `fixed_path()`.

- [ ] **Step 2: `room-walk.php`**
  1. `admin_boot('room.edit','POST')`.
  2. Empty `$_POST` and `$_FILES` with a body: 413 "The photos are too large to send together (the server takes up to <post_max_size>)."
  3. `gd_ready()` else 503 with the php.ini instruction.
  4. Normalise `$_FILES['photos']`; 1…min(20, `max_file_uploads`); `count` must equal the number received ("Some photos did not arrive"); `upload_problem` per photo, message names its position and file name.
  5. Holding folder `storage/walk-uploads/<random>`; a shutdown handler always empties it and, unless the map was saved, deletes photos already moved into `assets/nodes`.
  6. Shrink every photo into the holding folder first (slow part, outside the lock).
  7. `update_graph()` callback: require buildings and the floor point; update → find the room by exact name on that building+floor, record its own nodes; add → canonical name, 1–100 chars, not already used. `room_photo_ids()` with taken = id in map or file on disk. Move the files in, drop old nodes, `add_room_walk()`, point the room entry at the new room node (add: new entry with `floor_string`).
  8. After the save: delete old photo files and thumbnails, `audit_log('room.photos', …)`, `json_ok`.

- [ ] **Step 3: Lint** `php -l` both. Behaviour is proven in Task 9 (needs GD in Apache and a structured map).

- [ ] **Step 4: Commit** `feat: walkthrough endpoint and room walk upload`

---

### Task 5: Room actions and photo replace

**Files:**
- Modify: `api/admin/room-action.php`, `api/admin/photo-replace.php`, `api/admin/rooms.php`

- [ ] **Step 1: `room-action.php`** on `update_graph()`:
  - `rename`: `canonical_room_name`; `relabel_room`; on the old map the floor may still change; then `enrollment_rename(old,new)` (message says when the guide could not be updated).
  - `swap` (new): `name`, `otherName`, different; names trade places; both relabelled. Message: `"A" and "B" have swapped places.`
  - `remove`: refused with 409 when `enrollment_room_names()` holds the name; on the building map the room's own nodes are dropped and their files and thumbnails deleted after the save; the old map keeps today's behaviour (photos stay).
  - `relist`: unchanged behaviour, inside `update_graph`, relabels the node.
- [ ] **Step 2: `photo-replace.php`**: `admin_boot('room.edit','POST')`, `gd_ready`, `upload_problem`, `write_panorama` over the node's own file, delete its thumbnail, no backup, audit says the old picture was deleted.
- [ ] **Step 3: `rooms.php`**: add `'structured' => map_has_buildings($graph)`.
- [ ] **Step 4: Lint** all three; commit `feat: swap rooms, keep enrollment in step, no photo backups`

---

### Task 6: The Walkthrough admin page

**Files:**
- Create: `admin/walkthrough.html`, `admin/walkthrough.js`
- Modify: `assets/css/admin.css`, `assets/js/admin/admin.js`, `admin/rooms.js`
- Delete: `admin/navigation-map.html`, `admin/navigation-map.js`

Use the impeccable skill for the page. Match `rooms.html` structure (rail, `work-head`, `work-body`, `panel`), `DESIGN.md` tokens, 6px radius, 56px targets, sentence case, plain words, no ids on screen.

- [ ] **Step 1: Page skeleton** `walkthrough.html`: same head as `rooms.html` (`admin.css?v=12`), `<body class="walkthrough-page">`, rail, heading "Walkthrough", lede "Change the photos visitors walk through. Pick a building and floor, then a room.", two selects (Where, Room), `#floorView`, scripts `admin.js?v=12`, `walkthrough.js?v=1`.
- [ ] **Step 2: `walkthrough.js`**
  - Load `walkthrough.php`; `structured:false` shows a notice: the map has not been imported by building and floor yet, so this page has nothing to change.
  - Where: one option per building+floor, "Main Building – 1st Floor". Room: "All rooms on this floor" + rooms. Remember choice in `sessionStorage` (try/catch).
  - Fixed path strip: gate first, lock mark and "Fixed path — cannot be removed"; each picture has **Replace photo** (`room.edit`).
  - Room cards: room picture (last photo), name, "N photos from the floor point", "In the enrollment guide" note; **Update photos**, **Swap with…**, **Rename**, **Remove** (Remove disabled with the reason for enrollment rooms).
  - **Add a room** on each floor: name + photos, same editor.
  - Editor: `<input type=file multiple accept="image/jpeg,image/png,image/webp">`; for each file read EXIF `DateTimeOriginal` (small JPEG parser), else `lastModified`; sort; `createImageBitmap` to check 2:1 and shrink to ≤4096 wide JPEG 0.9 plus a 320-wide preview; list "Step 1 … Step N — this is the room"; drag to reorder plus Move up / Move down buttons (touch and keyboard); preview strip = fixed path thumbnails + new previews; **Save** uploads with `XMLHttpRequest` (progress), **Cancel** revokes object URLs, nothing sent.
  - Swap dialog lists every other room grouped by building and floor. Rename dialog: one field. Remove: `confirmAction` danger, says its photos are deleted for good.
  - After any save: toast, reload data, keep the chosen floor.
- [ ] **Step 3: CSS** under `/* walkthrough */` in `admin.css`: toolbar, path strip (horizontal scroll, 2:1 thumbs), room card grid, editor list, drag state, progress. Remove the navigation-map block (`.navigation-*`, `.route-*`).
- [ ] **Step 4: Menu** in `admin.js`: `{ href: 'walkthrough.html', label: 'Walkthrough', needs: 'room.view' }` replaces Navigation Map. Delete `navigation-map.*`.
- [ ] **Step 5: `rooms.js`**: remove `replacePhoto` and its button (route stays view-only); "Register Location" goes to `walkthrough.html`; `floorLabel()` reads both `1ST FLOOR ADMIN BUILDING` and `2ND FLOOR SECOND BUILDING` → "Second Building – 2nd Floor"; the edit dialog shows the floor field only when `structured` is false.
- [ ] **Step 6: Lint JS** with `node --check` on both files; commit `feat: walkthrough admin page`.

---

### Task 7: Room picker groups by building, then floor

**Files:**
- Modify: `map/select-room.js`, `map/select-room.html` (one style line, `?v=2`)

- [ ] **Step 1:** `floorOf()` also returns the building (text after "FLOOR", title case). Sections keyed building+floor, ordered by building name then floor. The first section of each floor number keeps `id="floor-N"` so home-page links still land; later ones get `floor-N-<n>`. Header shows the building name when the list has more than one building. Rail counts stay per floor number.
- [ ] **Step 2:** `node --check map/select-room.js`; commit `feat: room picker groups rooms by building`.

---

### Task 8: Retire the Python map tools, update docs

- [ ] Delete `map/add_walk.py`, `map/build_node_graph_v2.py`.
- [ ] `HANDOFF.md`: replace the add_walk / photo-backups text with the import tool, the Walkthrough page, the GD prerequisite, and `storage/map-previous`.
- [ ] Commit `chore: retire the python map tools`.

---

### Task 9: Verification

- [ ] **Step 1:** `php -l` every changed PHP file; `node --check` every changed JS file.
- [ ] **Step 2: Test copy.** `git worktree add ../EduTrack-wt --detach HEAD`; copy `api/config.php`; junction `vendor`. Build the sample there and `--publish` it (the test copy's map only). Confirm `--rollback` and `--publish` again.
- [ ] **Step 3: GD in Apache.** Ask the owner before editing `php.ini` (`extension=gd`), then Apache restart.
- [ ] **Step 4: Browser** at `http://localhost/EduTrack-wt/admin/walkthrough.html` (session cookie path is `/`, so a sign-in on the real panel carries over): update a room (order, preview, save), add a room, swap across floors, rename an enrollment room (check `assets/enrollment/enrollment-steps.json` in the copy), remove refused for it, remove another room (files gone), replace a fixed-path photo, and try removing a fixed path via a crafted request (refused). Visitor side: `map/select-room.html` grouping, walkthrough to a changed room, enrollment page link.
- [ ] **Step 5: Review Focus checks** 2 and 3 (curl 41 MB body; failed add leaves nothing in `storage/walk-uploads` or `assets/nodes`).
- [ ] **Step 6:** php-reviewer / security review of `walk-lib.php`, `room-walk.php`, `walkthrough.php`, `room-action.php`, `photo-replace.php`, `graph-write.php`, `tools/import-photos.php`; fix findings.
- [ ] **Step 7:** Remove the worktree; confirm the real `assets/nodes` is unchanged (`git status assets/nodes` clean).
