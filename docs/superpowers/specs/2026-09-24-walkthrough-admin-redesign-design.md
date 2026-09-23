# Walkthrough admin redesign: fixed floor paths, one-screen room updates

Date: 2026-09-24
Status: waiting for review

## Why

Changing the walkthrough today is too hard for the staff who have to do it:

- Replacing photos is one at a time, per route step, from Locations > View Route.
- Adding a room or re-recording a walk is command line only (`map/add_walk.py`).
- Photo names (`HALL-07`, `JUNC-12`) mean nothing, and many labels are wrong
  (`ROOM-105` labelled `101`).
- Old photos pile up in `storage/photo-backups/` forever.

Success: an older staff member can change a room's photos, swap two rooms, or
rename a room alone, without seeing a technical name, and the map stays small.

## What the owner asked for (agreed in conversation)

1. Each floor of each building has a **fixed path**: the walk from the main
   gate to that floor's point (1st floor: past the Registrar; 2nd floor: the
   stair edge; 3rd floor: the middle). Set once, locked, cannot be deleted.
2. **Update a room:** Building > Floor > pick a room > upload the photos from
   the floor point to the room in walking order. **The last photo is the room.**
   Preview, then Save.
3. **Swap two rooms** or **rename** a room without new photos (an office moved,
   the doors did not).
4. **Old photos are deleted** when a change is saved. No backup copies.
5. Every photo is **shrunk automatically** so the map stays small and fast.
6. The whole school is re-photographed and **imported from folders**; all
   photos get proper names.

## Assumptions (correct these if wrong)

- **Every walk starts at the one Main Gate**, including walks to the Second Building.
- **Each floor's fixed path starts right after the gate**, as the owner
  described ("from the gate through the third floor"). The 2nd and 3rd floor
  paths repeat the few ground-floor photos up to the stairs. This costs a few
  MB and removes any need to say where on the 1st floor the stairs are.
- **Each room's walk is its own photos**, from the floor point to the door.
  Two rooms on the same side hallway each carry their own hallway photos. This
  makes deleting safe and simple: a room's photos belong to that room only.
- A fixed-path photo can still be **replaced** (a hallway gets repainted); it
  can never be **deleted** or removed from the path.

## The photo folders (one-time import)

The owner copies photos, unrenamed, into:

```
C:\School Photos\
  Main Gate\                  one photo: the start of every walk
  Main Building\
    Fixed Path\
      1st Floor\              first photo after the gate -> 1st floor point
      2nd Floor\              first photo after the gate -> 2nd floor point
      3rd Floor\              first photo after the gate -> 3rd floor point
    1st Floor\
      Cashier\                floor point -> the Cashier (last photo = room)
      Registrar\
    2nd Floor\ ...
    3rd Floor\ ...
  Second Building\          same shape
```

- Folder names are what visitors see ("Cashier", "Room 201").
- Order inside a folder: the time the photo was taken (EXIF
  `DateTimeOriginal`), falling back to the file name. Copying keeps this;
  editing in some phone apps erases it, which the check reports.

### Import tool: `tools/import-photos.php` (run by the developer, not staff)

- `--check` reads the whole tree and changes nothing. It reports, per folder:
  empty folders, non-photos, non-360 photos (not ~2:1), photos with no taken-time,
  a missing Main Gate or Fixed Path, duplicate room names, and every room
  named in `assets/enrollment/enrollment-steps.json` that the new map lacks
  (the enrollment page links rooms by name).
- `--build` writes the new map to `assets/nodes-new/`, leaving the live map
  untouched: each photo resized to 4096 px wide, WebP quality 80.
- `--publish` swaps `assets/nodes-new/` in for `assets/nodes/` and moves the
  old map to `storage/map-previous/`. `--rollback` swaps it back. The old map
  is deleted by hand once the owner is happy.
- Replaces `map/add_walk.py` and `map/build_node_graph_v2.py`, which are
  removed.

## Rooms per building (from the owner, 2026-09-24)

The current map lists every room under "ADMIN BUILDING". These belong to the
Second Building, which has its own fixed 1st/2nd/3rd floor paths:

| Floor | Second Building rooms |
|---|---|
| 1st | 106, 107, 108, 110, Slab 3, Slab 1 |
| 2nd | 214, 215, Library |
| 3rd | Canteen / Auditorium (one room: the canteen is inside the auditorium) |

Everything else in today's room list stays in the Main Building. The import
`--check` compares the folder tree against this list and reports any room that
is missing or in the other building.

## Names

Staff never see these; they only need to be readable to a developer.

| Photo | Id |
|---|---|
| Main gate | `GATE` |
| Fixed path, Main 3rd floor, photo 2 | `MAIN-F3-PATH-02` |
| Walk to Faculty, Main 3rd floor, photo 1 | `MAIN-F3-FACULTY-01` |
| The Faculty room itself (last photo) | `MAIN-F3-FACULTY` |

Building code: the building folder name, upper-cased, letters and digits only,
short (`MAIN`, `SECOND`). Image file = id + `.webp`. Every node gets a correct
`label`, so the stale-label problem disappears.

## Map file

Stays `assets/nodes/nodes-edges.json`, same `nodes` / `edges` / `rooms` shape,
so the visitor walkthrough, enrollment page and guest tour keep working
unchanged (they walk edges breadth-first from the `landmark` node). Added:

```json
"buildings": [
  { "code": "MAIN", "name": "Main Building",
    "floors": [ { "n": 3, "point": "MAIN-F3-PATH-05" } ] }
]
```

and on each node `building`, `floor` and `role`: `gate` | `path` | `room-walk`
| `room`. `validate_graph()` enforces: exactly one gate; every `path` node is
connected only along its own floor's chain; every room is reachable; no
`path` or `gate` node is missing compared with the previous saved map (the
lock on fixed paths).

Room `floor` strings become `"<N>ST FLOOR <BUILDING NAME>"`, the format
`select-room.js` already parses. `select-room.js` groups by building, then
floor, so two buildings' 1st floors do not merge.

## Admin panel: new "Walkthrough" page

Replaces `admin/navigation-map.*` and the per-step "Replace this photo" in
Locations. Big buttons, plain words, no ids.

Two choices only, in this order:

1. **Where**: one list of every building and floor, each its own option:
   "Main Building – 1st Floor", "Main Building – 2nd Floor", …,
   "Second Building – 1st Floor", …. Each building has its own fixed paths.
2. **Room**: the rooms on that building and floor.

On the chosen floor the page shows:

3. **Fixed path** strip: small pictures from the gate to the floor point, with
   a lock. Each picture has **Replace photo** only.
4. **Rooms on this floor** as cards (picture of the room, name). Each card:
   - **Update photos**: pick files (phone or PC), shown in taken-time order
     with "Step 1 … Step N — this is the room" under the last. Staff can drag
     to reorder. The preview shows fixed path + new photos. **Save** or
     **Cancel**.
   - **Swap with…**: pick another room on any floor; names trade places.
   - **Rename**.
   - **Remove** (asks first; deletes that room's own photos only).
5. **Add a room** button on each floor: name + photos, same as Update.

### Server endpoints (all in `api/admin/`, existing session, CSRF and `can()` checks)

- `walkthrough.php` (GET): buildings > floors > fixed path > rooms, with
  thumbnails via the existing `thumb.php`.
- `room-walk.php` (POST, multipart, `room.edit`): building, floor, room name,
  ordered photos. Validates every photo (type by content, ~2:1, ≤25 MB), shrinks
  each, then in one step: writes new files, rewrites that room's nodes and
  edges, `save_graph()` (keeps its JSON snapshot), then deletes the room's old
  photo files and thumbnails. Any failure before the map is saved deletes the
  new files and leaves the old walk live.
- `room-action.php`: adds `swap`; `rename` and `remove` exist. `remove` also
  deletes the room's own photo files.
- `photo-replace.php`: no longer keeps a backup copy (rule 4); quality 80. It
  only ever swaps the picture of an existing photo, so it works on fixed-path
  photos without being able to remove them.

Preview happens in the browser from the chosen files, so nothing is uploaded
until Save, and Cancel leaves nothing behind on the server.

## Prerequisite

PHP's GD extension is off in `C:\xampp\php\php.ini` (`;extension=gd`). Resizing
needs it: enable it and restart Apache. EXIF is already on.

## Storage

Today: 113 photos, 52 MB, average 468 KB. Target: about 250–300 KB per photo
(WebP 80, 4096 wide) — an estimate to be confirmed on the real photos during
`--build`. No backup copies; only the small JSON map snapshots are kept (last
20). `storage/photo-backups/` and `storage/photo-originals-*` are deleted after
the new map is published and approved.

## Out of scope

- Arrows that turn (photos are taken facing forward; arrows stay forward/back).
- Staff running the full import from the panel (it is a one-time developer job).
- A separate start point for Building 2 (see Assumptions).

## How it is checked

No test suite exists. Checks: `php -l` on every changed file; `--check` and
`--build` run on a small sample tree (one building, two floors, three rooms,
including a non-360 photo and an empty folder) with the report compared to
what is expected; then in the browser: update a room, swap, rename, remove,
replace a fixed photo, confirm a fixed photo cannot be removed, and walk the
visitor walkthrough and enrollment page to a changed room.
