
import sys
import json
import csv
import shutil
from pathlib import Path
from PIL import Image
import imagehash

FIRST_IMAGE_THRESHOLD = 8   # looser: entrance shots from different rooms/floors should still merge
STEP_THRESHOLD = 6          # stricter: only merge with an already-known next step
IMG_EXTS = {".jpg", ".jpeg", ".png", ".webp"}
MAX_WIDTH = 4096
WEBP_QUALITY = 90


def natural_sort_key(path: Path):
    stem = path.stem.lower()
    if "start" in stem:
        return (0, 0)
    try:
        return (1, int(stem))
    except ValueError:
        return (2, stem)


def collect_room_sequences(root: Path):
    sequences = {}
    for floor_dir in sorted(p for p in root.iterdir() if p.is_dir()):
        for room_dir in sorted(p for p in floor_dir.iterdir() if p.is_dir()):
            imgs = [p for p in room_dir.iterdir() if p.suffix.lower() in IMG_EXTS]
            imgs.sort(key=natural_sort_key)
            if imgs:
                sequences[(floor_dir.name, room_dir.name)] = imgs
    return sequences


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


def build_graph(root: Path, out_dir: Path):
    out_dir.mkdir(parents=True, exist_ok=True)
    sequences = collect_room_sequences(root)
    total_photos = sum(len(v) for v in sequences.values())
    print(f"Found {len(sequences)} room folders, {total_photos} total photos. Processing...")

    nodes = {}          # node_id -> {"hash":..., "sample_path":...}
    root_nodes = []      # node_ids that are valid "entrance" candidates (in-degree 0)
    outgoing = {}         # node_id -> {node_id: True}  (existing edges, for local matching)
    edges = []            # [{"from_node","to_node","direction_label"}]
    rooms = []
    node_counter = 1

    def new_node(img_path, is_root=False):
        nonlocal node_counter
        node_id = f"N{node_counter:04d}"
        node_counter += 1
        nodes[node_id] = {"hash": hash_image(img_path), "sample_path": img_path}
        outgoing[node_id] = {}
        if is_root:
            root_nodes.append(node_id)
        return node_id

    def find_local_match(current_node_id, h):
        """Only consider nodes already reachable as a next-step from current_node."""
        candidates = outgoing.get(current_node_id, {})
        best, best_dist = None, None
        for nid in candidates:
            d = h - nodes[nid]["hash"]
            if d <= STEP_THRESHOLD and (best_dist is None or d < best_dist):
                best, best_dist = nid, d
        return best

    def find_root_match(h):
        best, best_dist = None, None
        for nid in root_nodes:
            d = h - nodes[nid]["hash"]
            if d <= FIRST_IMAGE_THRESHOLD and (best_dist is None or d < best_dist):
                best, best_dist = nid, d
        return best

    def add_edge(a, b):
        if b not in outgoing[a]:
            outgoing[a][b] = True
            edges.append({"from_node": a, "to_node": b, "direction_label": ""})

    for (floor, room), imgs in sequences.items():
        prev_node_id = None
        for i, img_path in enumerate(imgs):
            is_first = (i == 0)
            is_last = (i == len(imgs) - 1)
            h = hash_image(img_path)

            if is_last:
                node_id = new_node(img_path)          # always unique - this room's door
            elif is_first:
                match = find_root_match(h)
                node_id = match if match else new_node(img_path, is_root=True)
            else:
                match = find_local_match(prev_node_id, h) if prev_node_id else None
                node_id = match if match else new_node(img_path)

            if prev_node_id is not None:
                add_edge(prev_node_id, node_id)

            prev_node_id = node_id

        rooms.append({"room_name": room, "floor": floor, "node_id": prev_node_id})

    print(f"Reduced to {len(nodes)} unique nodes. Saving optimized images...")

    node_list = []
    for node_id, data in nodes.items():
        dest = out_dir / f"{node_id}.webp"
        save_optimized(data["sample_path"], dest)
        node_list.append({
            "node_id": node_id,
            "label": data["sample_path"].parent.name,
            "image_file": dest.name,
            "type": "unassigned"
        })

    graph = {"nodes": node_list, "edges": edges, "rooms": rooms}
    with open(out_dir / "nodes-edges.json", "w") as f:
        json.dump(graph, f, indent=2)

    with open(out_dir / "review_report.csv", "w", newline="") as f:
        writer = csv.writer(f)
        writer.writerow(["node_id", "first_seen_in_folder", "image_file"])
        for node_id, data in nodes.items():
            writer.writerow([node_id, data["sample_path"].parent.name, f"{node_id}.webp"])

    print("Done.")
    print(f"  {total_photos} original photos -> {len(node_list)} unique node images")
    print(f"  Review list: {out_dir / 'review_report.csv'}")
    print(f"  Graph data:  {out_dir / 'nodes-edges.json'}")


if __name__ == "__main__":
    if len(sys.argv) != 3:
        print('Usage: python build_node_graph.py "<photos_root_folder>" "<output_folder>"')
        sys.exit(1)
    build_graph(Path(sys.argv[1]), Path(sys.argv[2]))
