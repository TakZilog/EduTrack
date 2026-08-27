"""
Add a room to the campus map without rebuilding it.

build_node_graph_v2.py builds the whole map from scratch. That is no longer
safe to run: it emits N0001-style ids and type="unassigned", while the live map
uses GATE / HALL-01 / ROOM-105 with real types that were assigned by hand.
Re-running it would throw all of that away, and walkthrough.js finds the
starting point with type == "landmark", so the walkthrough would stop working.

This tool adds to the existing map instead. It hashes the node photos already
in assets/nodes/, matches a new walk against them, and only creates nodes for
the parts of the walk it has never seen. Every existing id, type, label, edge
and room entry is left exactly as it is.

    # See what would happen, change nothing:
    python map/add_walk.py "C:/photos/3RD FLOOR/301B" --room "301B" \
        --floor "3RD FLOOR ADMIN BUILDING" --dry-run

    # Actually add it:
    python map/add_walk.py "C:/photos/3RD FLOOR/301B" --room "301B" \
        --floor "3RD FLOOR ADMIN BUILDING"

    # Add every room folder under a tree shaped <FLOOR>/<ROOM>/photos:
    python map/add_walk.py "C:/photos" --batch --dry-run

A walk is one folder of photos taken in order, from the gate to the room's
door. Name them so they sort correctly: start.jpg, 1.jpg, 2.jpg, ... The last
photo is the door and always becomes a new node.
"""

import argparse
import json
import re
import shutil
import sys
from datetime import datetime
from pathlib import Path

try:
    from PIL import Image
    import imagehash
except ImportError:
    sys.exit(
        "This tool needs Pillow and imagehash:\n"
        "    pip install Pillow imagehash"
    )

PROJECT = Path(__file__).resolve().parent.parent
NODES_DIR = PROJECT / "assets" / "nodes"
GRAPH_PATH = NODES_DIR / "nodes-edges.json"
SNAPSHOT_DIR = PROJECT / "storage" / "map-snapshots"

# Matching thresholds, carried over from build_node_graph_v2.py.
# Looser for the first photo, because entrance shots vary more.
FIRST_IMAGE_THRESHOLD = 8
STEP_THRESHOLD = 6

IMG_EXTS = {".jpg", ".jpeg", ".png", ".webp"}
MAX_WIDTH = 4096
WEBP_QUALITY = 90


def natural_sort_key(path: Path):
    """start.jpg first, then 1, 2, 10 in numeric order, then anything else."""
    stem = path.stem.lower()
    if "start" in stem:
        return (0, 0, "")
    try:
        return (1, int(stem), "")
    except ValueError:
        return (2, 0, stem)


def photos_in(folder: Path):
    imgs = [p for p in folder.iterdir() if p.suffix.lower() in IMG_EXTS]
    imgs.sort(key=natural_sort_key)
    return imgs


def hash_image(path: Path):
    with Image.open(path) as im:
        return imagehash.phash(im)


def save_optimized(src: Path, dest: Path):
    with Image.open(src) as im:
        im = im.convert("RGB")
        if im.width > MAX_WIDTH:
            ratio = MAX_WIDTH / im.width
            im = im.resize((MAX_WIDTH, int(im.height * ratio)), Image.Resampling.LANCZOS)
        im.save(dest, "WEBP", quality=WEBP_QUALITY)


def slugify(name: str) -> str:
    return re.sub(r"[^A-Z0-9]+", "-", name.upper()).strip("-")


class Map:
    """The live map, loaded once and added to."""

    def __init__(self):
        if not GRAPH_PATH.exists():
            sys.exit(f"No map found at {GRAPH_PATH}")

        self.graph = json.loads(GRAPH_PATH.read_text(encoding="utf-8"))
        self.nodes = self.graph["nodes"]
        self.edges = self.graph["edges"]
        self.rooms = self.graph["rooms"]

        self.by_id = {n["node_id"]: n for n in self.nodes}
        self.hashes = {}
        self.new_node_ids = []

        # Who connects to whom, both directions, for local matching.
        self.neighbours = {n["node_id"]: set() for n in self.nodes}
        for e in self.edges:
            self.neighbours.setdefault(e["from_node"], set()).add(e["to_node"])
            self.neighbours.setdefault(e["to_node"], set()).add(e["from_node"])

        self.entrances = [
            n["node_id"] for n in self.nodes if n.get("type") == "landmark"
        ]
        if not self.entrances:
            sys.exit(
                'No starting point in the map: no node has type "landmark". '
                "The walkthrough needs one, so this tool will not guess."
            )

    def load_hashes(self):
        """Hash the node photos already on disk. This is the match set."""
        print(f"Reading {len(self.nodes)} photos already in the map...")
        missing = []
        for node in self.nodes:
            path = NODES_DIR / node["image_file"]
            if not path.exists():
                missing.append(node["image_file"])
                continue
            self.hashes[node["node_id"]] = hash_image(path)
        if missing:
            print(f"  warning: {len(missing)} photo file(s) missing: {', '.join(missing[:5])}")

    def next_id(self, prefix: str) -> str:
        """Keeps the map's existing naming, so new nodes look like the old ones."""
        used = set(self.by_id) | set(self.new_node_ids)
        n = 1
        while f"{prefix}-{n:02d}" in used:
            n += 1
        return f"{prefix}-{n:02d}"

    def match_entrance(self, h):
        best, best_d = None, None
        for nid in self.entrances:
            if nid not in self.hashes:
                continue
            d = h - self.hashes[nid]
            if d <= FIRST_IMAGE_THRESHOLD and (best_d is None or d < best_d):
                best, best_d = nid, d
        return best, best_d

    def match_next(self, current_id, h):
        """Only nodes already reachable from here, same rule as the original build."""
        best, best_d = None, None
        for nid in self.neighbours.get(current_id, ()):
            if nid not in self.hashes:
                continue
            d = h - self.hashes[nid]
            if d <= STEP_THRESHOLD and (best_d is None or d < best_d):
                best, best_d = nid, d
        return best, best_d

    def add_node(self, node_id, label, image_file, node_type):
        node = {
            "node_id": node_id,
            "label": label,
            "image_file": image_file,
            "type": node_type,
        }
        self.nodes.append(node)
        self.by_id[node_id] = node
        self.neighbours.setdefault(node_id, set())
        self.new_node_ids.append(node_id)
        return node

    def add_edge(self, a, b):
        if b in self.neighbours.get(a, set()):
            return False
        self.edges.append({"from_node": a, "to_node": b, "direction_label": ""})
        self.neighbours.setdefault(a, set()).add(b)
        self.neighbours.setdefault(b, set()).add(a)
        return True

    def retype_junctions(self):
        """
        A new corridor node that ends up with three or more connections is a
        junction, not a hallway. Only newly added nodes are touched; the types
        already in the map are never second-guessed.
        """
        changed = []
        for nid in self.new_node_ids:
            node = self.by_id[nid]
            if node["type"] == "hallway" and len(self.neighbours[nid]) >= 3:
                node["type"] = "junction"
                changed.append(nid)
        return changed

    def routed_nodes(self):
        """Every photo that is on the way from the gate to some listed room."""
        alive = set(self.entrances)
        for room in self.rooms:
            seen = {self.entrances[0]}
            queue = [self.entrances[0]]
            target = room["node_id"]
            parent = {}
            found = False
            while queue and not found:
                current = queue.pop(0)
                for nxt in self.neighbours.get(current, ()):
                    if nxt in seen:
                        continue
                    seen.add(nxt)
                    parent[nxt] = current
                    if nxt == target:
                        found = True
                        break
                    queue.append(nxt)
            if found:
                step = target
                while step is not None:
                    alive.add(step)
                    step = parent.get(step)
        return alive

    def drop_room(self, room_name: str, keep_node: str):
        """
        Takes a room off the map along with the photos that only it used.

        A photo is only removed when nothing else needs it: not the gate, not
        the new walk just recorded, and not on the way to any other room. That
        is what makes re-recording a room safe rather than destructive.
        """
        self.rooms[:] = [
            r for r in self.rooms
            if r["room_name"].strip().lower() != room_name.strip().lower()
        ]

        alive = self.routed_nodes() | {keep_node} | set(self.new_node_ids)
        dead = [n["node_id"] for n in self.nodes if n["node_id"] not in alive]

        if not dead:
            return []

        print(f"    replacing the old walk: {len(dead)} photo(s) no longer lead anywhere")
        for node_id in dead:
            print(f"      dropped {node_id}")

        self.nodes[:] = [n for n in self.nodes if n["node_id"] not in dead]
        self.edges[:] = [
            e for e in self.edges
            if e["from_node"] not in dead and e["to_node"] not in dead
        ]
        for node_id in dead:
            self.by_id.pop(node_id, None)
            self.neighbours.pop(node_id, None)
        for links in self.neighbours.values():
            links.difference_update(dead)

        # The image files are left on disk on purpose. Nothing points at them
        # any more, and keeping them means a mistake here is recoverable.
        self.orphan_images = [
            f"{n}.webp" for n in dead
        ]
        return dead

    def save(self):
        SNAPSHOT_DIR.mkdir(parents=True, exist_ok=True)
        stamp = datetime.now().strftime("%Y-%m-%d_%H%M%S")
        shutil.copy2(GRAPH_PATH, SNAPSHOT_DIR / f"{stamp}.json")

        tmp = GRAPH_PATH.with_suffix(".json.tmp")
        tmp.write_text(
            json.dumps(self.graph, indent=2, ensure_ascii=False), encoding="utf-8"
        )
        tmp.replace(GRAPH_PATH)  # atomic, and works on Windows
        return f"{stamp}.json"


def add_walk(m: Map, folder: Path, room_name: str, floor: str, dry_run: bool, replace: bool = False):
    photos = photos_in(folder)
    if not photos:
        print(f"  no photos in {folder}, skipped")
        return False

    existing_names = {r["room_name"].strip().lower() for r in m.rooms}
    if room_name.strip().lower() in existing_names:
        if not replace:
            print(f'  "{room_name}" is already on the map.')
            print(f'  To record it again with more photos, add --replace.')
            return False
        # The old entry is only dropped once the new walk has been accepted,
        # further down, so a failed re-walk cannot lose the existing one.

    print(f'\n  "{room_name}" on {floor}  ({len(photos)} photos)')

    prev_id = None
    pending_images = []   # (node_id, source path) to write only on a real run
    matched = 0
    created = 0

    for i, photo in enumerate(photos):
        is_first = i == 0
        is_last = i == len(photos) - 1
        h = hash_image(photo)

        if is_last:
            # The door is always its own node, even if it looks like somewhere else.
            node_id = f"ROOM-{slugify(room_name)}"
            if node_id in m.by_id:
                node_id = m.next_id(f"ROOM-{slugify(room_name)}")
            image_file = f"{node_id}.webp"
            m.add_node(node_id, room_name, image_file, "room")
            m.hashes[node_id] = h
            pending_images.append((image_file, photo))
            created += 1
            print(f"    {photo.name:<14} -> NEW {node_id}  (the door)")

        elif is_first:
            hit, dist = m.match_entrance(h)
            if hit:
                node_id = hit
                matched += 1
                print(f"    {photo.name:<14} -> {node_id}  (entrance, difference {dist})")
            else:
                print(
                    f"    {photo.name:<14} -> does not match the gate.\n"
                    f"      This walk must start with a photo taken at the same place as GATE.webp.\n"
                    f"      Skipping this room."
                )
                return False

        else:
            hit, dist = m.match_next(prev_id, h)
            if hit:
                node_id = hit
                matched += 1
                print(f"    {photo.name:<14} -> {node_id}  (already in the map, difference {dist})")
            else:
                node_id = m.next_id("HALL")
                image_file = f"{node_id}.webp"
                m.add_node(node_id, room_name, image_file, "hallway")
                # Registered straight away so a second new walk sharing this
                # corridor matches it instead of duplicating the photo.
                m.hashes[node_id] = h
                pending_images.append((image_file, photo))
                created += 1
                print(f"    {photo.name:<14} -> NEW {node_id}")

        if prev_id is not None:
            m.add_edge(prev_id, node_id)
        prev_id = node_id

    if replace:
        m.drop_room(room_name, keep_node=prev_id)

    m.rooms.append({"room_name": room_name, "floor": floor, "node_id": prev_id})

    print(f"    {matched} step(s) already on the map, {created} new")

    if not dry_run:
        for image_file, source in pending_images:
            save_optimized(source, NODES_DIR / image_file)

    return True


def main():
    parser = argparse.ArgumentParser(
        description="Add a room to the campus map without rebuilding it."
    )
    parser.add_argument("folder", help="A walk folder, or a tree of them with --batch")
    parser.add_argument("--room", help="Room name, for a single walk")
    parser.add_argument("--floor", help="Floor name, for a single walk")
    parser.add_argument(
        "--batch",
        action="store_true",
        help="Treat the folder as <FLOOR>/<ROOM>/photos and add every room in it",
    )
    parser.add_argument(
        "--replace",
        action="store_true",
        help="Re-record a room that is already on the map, for example to add "
             "more photos so it connects properly",
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="Show what would change and write nothing",
    )
    args = parser.parse_args()

    folder = Path(args.folder)
    if not folder.is_dir():
        sys.exit(f"Not a folder: {folder}")

    walks = []
    if args.batch:
        for floor_dir in sorted(p for p in folder.iterdir() if p.is_dir()):
            for room_dir in sorted(p for p in floor_dir.iterdir() if p.is_dir()):
                walks.append((room_dir, room_dir.name, floor_dir.name))
        if not walks:
            sys.exit(
                f"No <FLOOR>/<ROOM>/ folders under {folder}.\n"
                "Expected a tree like:  photos/3RD FLOOR ADMIN BUILDING/301B/start.jpg"
            )
    else:
        if not args.room or not args.floor:
            sys.exit("A single walk needs --room and --floor.")
        walks.append((folder, args.room, args.floor))

    m = Map()
    m.load_hashes()

    print(f"\nMap now: {len(m.nodes)} photos, {len(m.edges)} links, {len(m.rooms)} rooms")
    if args.dry_run:
        print("DRY RUN: nothing will be written.")

    added = 0
    for walk_folder, room, floor in walks:
        if add_walk(m, walk_folder, room, floor, args.dry_run, args.replace):
            added += 1

    print(f"\n{'Would add' if args.dry_run else 'Added'} {added} room(s).")
    print(f"Map after: {len(m.nodes)} photos, {len(m.edges)} links, {len(m.rooms)} rooms")

    if added and not args.dry_run:
        upgraded = m.retype_junctions()
        if upgraded:
            print(f"Marked as junctions (3 or more ways out): {', '.join(upgraded)}")
        snapshot = m.save()
        print(f"Saved. Previous version kept as storage/map-snapshots/{snapshot}")
        print("Check it in the staff panel under Rooms, then use Preview walk.")
    elif added:
        print("Run again without --dry-run to apply.")


if __name__ == "__main__":
    main()
