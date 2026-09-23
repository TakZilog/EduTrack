<?php

/**
 * Builds the walkthrough map from folders of photos.
 *
 * Run by the developer once the school is photographed, never by staff. The
 * photos are copied, unrenamed, into one folder per walk:
 *
 *   C:\School Photos\
 *     Main Gate\              one photo: the start of every walk
 *     Main Building\
 *       Fixed Path\
 *         1st Floor\          from the first photo after the gate to the 1st floor point
 *         2nd Floor\
 *       1st Floor\
 *         Cashier\            from the floor point to the Cashier; the last photo is the room
 *       2nd Floor\ ...
 *     Second Building\        the same shape
 *
 * Folder names are what visitors see. Inside a folder, photos are in the order
 * they were taken (EXIF), or by file name when a photo does not say.
 *
 *   php tools/import-photos.php --check "C:\School Photos"    report only, changes nothing
 *   php tools/import-photos.php --build "C:\School Photos"    writes assets/nodes-new/
 *   php tools/import-photos.php --publish                      puts assets/nodes-new live
 *   php tools/import-photos.php --rollback                     puts the previous map back
 *   php tools/import-photos.php --make-sample <empty folder>   a small test tree
 *
 * Shrinking needs PHP's GD extension. Until it is on in php.ini, add
 * -d extension=gd after php.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../api/admin/walk-lib.php';
require __DIR__ . '/../api/admin/graph-lib.php';
require __DIR__ . '/../api/admin/graph-write.php';

const NODES_DIR    = __DIR__ . '/../assets/nodes';
const NEW_DIR      = __DIR__ . '/../assets/nodes-new';
const PREVIOUS_DIR = __DIR__ . '/../storage/map-previous';
const GATE_FOLDER  = 'Main Gate';
const PATH_FOLDER  = 'Fixed Path';
const PHOTO_TYPES  = ['jpg', 'jpeg', 'png', 'webp'];
const IGNORED      = ['thumbs.db', 'desktop.ini', '.ds_store'];

/*
  Where today's rooms belong, from the owner (2026-09-24). These are in the
  Second Building; every other room on today's map is in the Main Building.
  The auditorium becomes one room with the canteen inside it; a folder name
  cannot hold "/", so its folder is "Canteen & Auditorium".
*/
const SECOND_BUILDING_ROOMS = ['106', '107', '108', '110', 'SLAB 3', 'SLAB 1', '214', '215', 'LIBRARY', 'CANTEEN & AUDITORIUM'];
const RENAMED_ROOMS         = ['AUDITORIUM' => 'CANTEEN & AUDITORIUM'];

exit(main(array_slice($argv, 1)));

function main(array $args): int
{
    return match ($args[0] ?? '') {
        '--check'       => check((string) ($args[1] ?? '')),
        '--build'       => build((string) ($args[1] ?? '')),
        '--publish'     => publish(),
        '--rollback'    => rollback(),
        '--make-sample' => make_sample((string) ($args[1] ?? '')),
        default         => usage(),
    };
}

function usage(): int
{
    echo <<<TXT
    Usage:
      php tools/import-photos.php --check "C:\\School Photos"
      php tools/import-photos.php --build "C:\\School Photos"
      php tools/import-photos.php --publish
      php tools/import-photos.php --rollback
      php tools/import-photos.php --make-sample <empty folder>

    TXT;
    return 2;
}

/* --------------------------------------------------------------- reading */

/** @return string[] folder names inside $dir, natural order */
function subfolders(string $dir): array
{
    $names = array_values(array_filter(scandir($dir) ?: [],
        static fn ($n) => $n !== '.' && $n !== '..' && is_dir($dir . DIRECTORY_SEPARATOR . $n)));
    natcasesort($names);

    return array_values($names);
}

/** Files lying where only folders belong are skipped and reported. */
function warn_loose_files(string $dir, string $shown, array &$report): void
{
    foreach (scandir($dir) ?: [] as $name) {
        $path = $dir . DIRECTORY_SEPARATOR . $name;
        if (is_file($path) && !in_array(strtolower($name), IGNORED, true)) {
            $report['warnings'][] = "{$shown}\\{$name}: a file outside a walk folder, skipped.";
        }
    }
}

/**
 * The photos of one walk folder, in walking order. Anything that is not a
 * usable 360 photo is reported.
 *
 * @return string[] full paths
 */
function folder_photos(string $dir, string $shown, array &$report): array
{
    $taken = [];
    foreach (scandir($dir) ?: [] as $name) {
        $path = $dir . DIRECTORY_SEPARATOR . $name;
        if (!is_file($path) || in_array(strtolower($name), IGNORED, true)) {
            continue;
        }
        if (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), PHOTO_TYPES, true)) {
            $report['warnings'][] = "{$shown}\\{$name}: not a photo, skipped.";
            continue;
        }
        $problem = photo_problem($path);
        if ($problem !== null) {
            $report['errors'][] = "{$shown}\\{$name}: {$problem}";
            continue;
        }
        $taken[$name] = taken_time($path);
    }

    if ($taken === []) {
        return [];
    }

    $names = array_map('strval', array_keys($taken));
    if (in_array(null, $taken, true)) {
        // Mixing taken-times with file names would put photos in a random
        // order, so the whole folder goes by name instead.
        natcasesort($names);
        $names = array_values($names);
        $report['warnings'][] = "{$shown}: some photos do not say when they were taken, so the folder is in "
            . 'file-name order. Check the order listed above.';
    } else {
        usort($names, static fn ($a, $b) => strcmp($taken[$a], $taken[$b]) ?: strnatcasecmp($a, $b));
    }

    $report['order'][] = "{$shown}: " . implode(', ', $names);

    return array_map(static fn ($n) => $dir . DIRECTORY_SEPARATOR . $n, $names);
}

/**
 * Reads the whole photo tree. Changes nothing.
 *
 * @return array{gate: ?string, buildings: array, errors: string[], warnings: string[], notes: string[], order: string[]}
 */
function scan_tree(string $root): array
{
    $report = ['gate' => null, 'buildings' => [], 'errors' => [], 'warnings' => [], 'notes' => [], 'order' => []];
    $root   = rtrim($root, '\\/');

    if ($root === '' || !is_dir($root)) {
        $report['errors'][] = "The folder \"{$root}\" does not exist.";
        return $report;
    }

    $gateDir = $root . DIRECTORY_SEPARATOR . GATE_FOLDER;
    if (!is_dir($gateDir)) {
        $report['errors'][] = 'There is no "' . GATE_FOLDER . '" folder. It holds the one photo every walk starts from.';
    } else {
        $gate = folder_photos($gateDir, GATE_FOLDER, $report);
        if (count($gate) === 1) {
            $report['gate'] = $gate[0];
        } else {
            $report['errors'][] = GATE_FOLDER . ': this folder must hold exactly one photo. It holds ' . count($gate) . '.';
        }
    }
    warn_loose_files($root, '', $report);

    $codes = [];
    $rooms = [];   // lower-case name => where it was found
    foreach (subfolders($root) as $buildingName) {
        if (strcasecmp($buildingName, GATE_FOLDER) === 0) {
            continue;
        }

        $code = building_code($buildingName);
        if ($code === '') {
            $report['errors'][] = "{$buildingName}: this cannot be a building name. Use letters, like \"Main Building\".";
            continue;
        }
        if (isset($codes[$code])) {
            $report['errors'][] = "\"{$buildingName}\" and \"{$codes[$code]}\" are too alike to tell apart. Rename one.";
            continue;
        }
        $codes[$code] = $buildingName;

        $buildingDir = $root . DIRECTORY_SEPARATOR . $buildingName;
        $building    = ['name' => $buildingName, 'code' => $code, 'floors' => []];
        $pathFolders = [];   // floor number => folder, to catch two folders for one floor
        $roomFloors  = [];
        warn_loose_files($buildingDir, $buildingName, $report);

        $pathDir = null;
        foreach (subfolders($buildingDir) as $name) {
            if (strcasecmp($name, PATH_FOLDER) === 0) {
                $pathDir = $buildingDir . DIRECTORY_SEPARATOR . $name;
            }
        }
        if ($pathDir === null) {
            $report['errors'][] = "{$buildingName}: there is no \"" . PATH_FOLDER . '" folder.';
        } else {
            $shownPath = $buildingName . '\\' . PATH_FOLDER;
            warn_loose_files($pathDir, $shownPath, $report);
            foreach (subfolders($pathDir) as $floorName) {
                $n     = floor_number($floorName);
                $shown = "{$shownPath}\\{$floorName}";
                if ($n === null) {
                    $report['errors'][] = "{$shown}: this is not a floor name. Use names like \"1st Floor\".";
                    continue;
                }
                // "1st Floor" and "1 Floor" are the same floor: one would quietly replace the other.
                if (isset($pathFolders[$n])) {
                    $report['errors'][] = "{$shown}: this is floor {$n} again, after \"{$pathFolders[$n]}\". Keep one folder per floor.";
                    continue;
                }
                $pathFolders[$n] = $floorName;
                $photos = folder_photos($pathDir . DIRECTORY_SEPARATOR . $floorName, $shown, $report);
                if ($photos === []) {
                    $report['errors'][] = "{$shown}: the folder has no photos.";
                    continue;
                }
                $building['floors'][$n]['path'] = $photos;
            }
        }

        foreach (subfolders($buildingDir) as $floorName) {
            if (strcasecmp($floorName, PATH_FOLDER) === 0) {
                continue;
            }
            $n        = floor_number($floorName);
            $floorDir = $buildingDir . DIRECTORY_SEPARATOR . $floorName;
            $shown    = "{$buildingName}\\{$floorName}";
            if ($n === null) {
                $report['errors'][] = "{$shown}: this is not a floor name. Use names like \"1st Floor\".";
                continue;
            }
            if (isset($roomFloors[$n])) {
                $report['errors'][] = "{$shown}: this is floor {$n} again, after \"{$roomFloors[$n]}\". Keep one folder per floor.";
                continue;
            }
            $roomFloors[$n] = $floorName;
            warn_loose_files($floorDir, $shown, $report);

            foreach (subfolders($floorDir) as $roomFolder) {
                $name      = canonical_room_name($roomFolder);
                $roomShown = "{$shown}\\{$roomFolder}";
                $roomDir   = $floorDir . DIRECTORY_SEPARATOR . $roomFolder;

                if (subfolders($roomDir) !== []) {
                    $report['warnings'][] = "{$roomShown}: folders inside a room folder are skipped.";
                }
                $photos = folder_photos($roomDir, $roomShown, $report);
                if ($photos === []) {
                    $report['errors'][] = "{$roomShown}: the folder has no photos.";
                    continue;
                }

                $key = mb_strtolower($name);
                if (isset($rooms[$key])) {
                    $report['errors'][] = "{$roomShown} and {$rooms[$key]}: two rooms are both called \"{$name}\". "
                        . 'Every room needs a different name.';
                    continue;
                }
                $rooms[$key] = $roomShown;

                $building['floors'][$n]['rooms'][$name] = $photos;
            }

            if (!empty($building['floors'][$n]['rooms']) && empty($building['floors'][$n]['path'])) {
                $report['errors'][] = "{$shown}: this floor has rooms but no fixed path "
                    . "(a \"{$floorName}\" folder inside \"" . PATH_FOLDER . '").';
            }
        }

        ksort($building['floors']);
        $report['buildings'][] = $building;
    }

    if ($report['buildings'] === []) {
        $report['errors'][] = 'There are no building folders, like "Main Building".';
    }

    compare_with_today($report);

    return $report;
}

/**
 * Rooms on today's map that the new one lacks or puts in the other building,
 * rooms that are new, and rooms the enrollment guide needs.
 */
function compare_with_today(array &$report): void
{
    $found = [];   // room name => building folder
    foreach ($report['buildings'] as $building) {
        foreach ($building['floors'] as $floor) {
            foreach (array_keys($floor['rooms'] ?? []) as $name) {
                $found[(string) $name] = $building['name'];
            }
        }
    }

    $today = json_decode((string) @file_get_contents(GRAPH_PATH), true);
    $known = [];
    foreach ($today['rooms'] ?? [] as $room) {
        $name = RENAMED_ROOMS[$room['room_name']] ?? canonical_room_name((string) $room['room_name']);
        $known[$name] = true;
        $expected = in_array($name, SECOND_BUILDING_ROOMS, true) ? 'Second Building' : 'Main Building';

        if (!isset($found[$name])) {
            $report['warnings'][] = "Room \"{$name}\" is on today's map but has no folder. It belongs in the {$expected}.";
        } elseif (strcasecmp($found[$name], $expected) !== 0) {
            $report['warnings'][] = "Room \"{$name}\" is in the {$found[$name]} folder, but it belongs in the {$expected}.";
        }
    }

    foreach ($found as $name => $building) {
        if (!isset($known[$name])) {
            $report['notes'][] = "New room: \"{$name}\" ({$building}).";
        }
    }

    foreach (enrollment_room_names() as $name) {
        if (!isset($found[$name])) {
            $report['warnings'][] = "The enrollment guide sends visitors to \"{$name}\", but no folder has that name. "
                . 'Name a folder that, or change assets/enrollment/enrollment-steps.json.';
        }
    }
}

function print_report(array $report): void
{
    if ($report['order']) {
        echo "Photo order in each walk (check that each one runs forward, the room last):\n";
        foreach ($report['order'] as $line) {
            echo "  {$line}\n";
        }
        echo "\n";
    }
    foreach ($report['errors'] as $line) {
        echo "ERROR    {$line}\n";
    }
    foreach ($report['warnings'] as $line) {
        echo "WARNING  {$line}\n";
    }
    foreach ($report['notes'] as $line) {
        echo "NOTE     {$line}\n";
    }

    $floors = $rooms = $photos = 0;
    foreach ($report['buildings'] as $building) {
        foreach ($building['floors'] as $floor) {
            $floors++;
            $photos += count($floor['path'] ?? []);
            foreach ($floor['rooms'] ?? [] as $walk) {
                $rooms++;
                $photos += count($walk);
            }
        }
    }
    $photos += $report['gate'] !== null ? 1 : 0;

    printf("\n%d building(s), %d floor(s), %d room(s), %d photo(s). %d error(s), %d warning(s).\n",
        count($report['buildings']), $floors, $rooms, $photos, count($report['errors']), count($report['warnings']));
}

/* ------------------------------------------------------------------ modes */

function check(string $root): int
{
    $report = scan_tree($root);
    print_report($report);

    if ($report['errors']) {
        echo "Fix the errors, then run --check again.\n";
        return 1;
    }
    echo "Ready to build: php tools/import-photos.php --build \"{$root}\"\n";

    return 0;
}

function build(string $root): int
{
    if (!gd_ready()) {
        echo "PHP cannot shrink photos: its GD extension is off. Run this with php -d extension=gd, "
            . "or turn on extension=gd in php.ini.\n";
        return 1;
    }

    $report = scan_tree($root);
    if ($report['errors']) {
        print_report($report);
        echo "Nothing was built. Fix the errors first.\n";
        return 1;
    }

    // A 72-megapixel 360 photo takes about 300 MB to open.
    ini_set('memory_limit', '1536M');

    remove_folder(NEW_DIR);
    if (!mkdir(NEW_DIR, 0775, true) || !copy(NODES_DIR . '/.htaccess', NEW_DIR . '/.htaccess')) {
        // Without its .htaccess the photo folder would be open to any browser.
        echo "Could not prepare assets/nodes-new. Nothing was built.\n";
        return 1;
    }

    $total = 1;
    foreach ($report['buildings'] as $building) {
        foreach ($building['floors'] as $floor) {
            $total += count($floor['path'] ?? []) + array_sum(array_map('count', $floor['rooms'] ?? []));
        }
    }
    $done  = 0;
    $write = static function (string $source, string $id) use (&$done, $total): void {
        $done++;
        echo "  [{$done}/{$total}] {$id}\n";
        write_panorama($source, NEW_DIR . '/' . $id . '.webp');
    };

    $graph = ['buildings' => [], 'nodes' => [], 'edges' => [], 'rooms' => []];
    $used  = ['GATE' => true];

    $write($report['gate'], 'GATE');
    $graph['nodes'][] = ['node_id' => 'GATE', 'label' => 'Main Gate', 'image_file' => 'GATE.webp',
        'type' => 'landmark', 'role' => 'gate'];

    foreach ($report['buildings'] as $building) {
        $code  = $building['code'];
        $entry = ['code' => $code, 'name' => $building['name'], 'floors' => []];

        // Every floor's fixed path starts right after the gate.
        foreach ($building['floors'] as $n => $floor) {
            if (empty($floor['path'])) {
                continue;
            }
            $previous = 'GATE';
            foreach ($floor['path'] as $i => $source) {
                $id = sprintf('%s-F%d-PATH-%02d', $code, $n, $i + 1);
                $write($source, $id);
                $graph['nodes'][] = [
                    'node_id'    => $id,
                    'label'      => $building['name'] . ' ' . strtolower(ordinal($n)) . ' floor, fixed path photo ' . ($i + 1),
                    'image_file' => $id . '.webp',
                    'type'       => 'hallway',
                    'building'   => $code,
                    'floor'      => $n,
                    'role'       => 'path',
                ];
                $graph['edges'][] = ['from_node' => $previous, 'to_node' => $id, 'direction_label' => ''];
                $used[$id] = true;
                $previous  = $id;
            }
            $entry['floors'][] = ['n' => $n, 'point' => $previous];
        }
        $graph['buildings'][] = $entry;

        foreach ($building['floors'] as $n => $floor) {
            foreach ($floor['rooms'] ?? [] as $name => $photos) {
                $name = (string) $name;
                $ids  = room_photo_ids($code, $n, $name, count($photos), static fn ($id) => isset($used[$id]));
                foreach ($ids as $i => $id) {
                    $write($photos[$i], $id);
                    $used[$id] = true;
                }
                $graph = add_room_walk($graph, $code, $n, $name, $ids);
                $graph['rooms'][] = ['room_name' => $name, 'floor' => floor_string($n, $building['name']), 'node_id' => end($ids)];
            }
        }
    }

    $problems = validate_graph($graph);
    if ($problems) {
        echo "The new map is not sound, so it was not saved:\n  " . implode("\n  ", $problems) . "\n";
        return 1;
    }

    $json = json_encode($graph, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    file_put_contents(NEW_DIR . '/nodes-edges.json', $json);

    $bytes = array_sum(array_map('filesize', glob(NEW_DIR . '/*.webp') ?: []));
    printf("\nBuilt %d photos, %.1f MB (about %d KB each) in assets/nodes-new. The live map is unchanged.\n"
        . "Next: php tools/import-photos.php --publish\n", $total, $bytes / 1048576, (int) round($bytes / 1024 / $total));
    print_report(['order' => []] + $report);

    return 0;
}

function publish(): int
{
    $graph = json_decode((string) @file_get_contents(NEW_DIR . '/nodes-edges.json'), true);
    if (!is_array($graph)) {
        echo "There is nothing to publish. Run --build first.\n";
        return 1;
    }
    if (validate_graph($graph) || !is_file(NEW_DIR . '/.htaccess')) {
        echo "assets/nodes-new is not a sound map. Run --build again.\n";
        return 1;
    }
    // The public enrollment guide links rooms by name; a missing one would
    // leave a step with no way to walk there.
    $missing = array_values(array_diff(enrollment_room_names(), array_column($graph['rooms'], 'room_name')));
    if ($missing) {
        echo 'Nothing was published. The enrollment guide sends visitors to "' . implode('", "', $missing)
            . "\", but the new map has no room with that name. Name the room folders to match, or change "
            . "assets/enrollment/enrollment-steps.json, then build again.\n";
        return 1;
    }
    if (file_exists(PREVIOUS_DIR)) {
        echo "storage/map-previous still holds an earlier map. Run --rollback, or delete that folder once you are "
            . "happy with the map that is live now.\n";
        return 1;
    }

    @mkdir(dirname(PREVIOUS_DIR), 0775, true);
    if (!@rename(NODES_DIR, PREVIOUS_DIR)) {
        echo "The live map could not be moved aside. Stop Apache in the XAMPP control panel and try again.\n";
        return 1;
    }
    if (!@rename(NEW_DIR, NODES_DIR)) {
        @rename(PREVIOUS_DIR, NODES_DIR);
        echo "The new map could not be put in place, so the old one is back. Stop Apache and try again.\n";
        return 1;
    }
    clear_thumbnails();

    echo "Published. The previous map is in storage/map-previous.\n"
        . "To undo: php tools/import-photos.php --rollback\n";

    return 0;
}

function rollback(): int
{
    if (!is_dir(PREVIOUS_DIR)) {
        echo "There is no earlier map in storage/map-previous.\n";
        return 1;
    }
    if (file_exists(NEW_DIR)) {
        echo "assets/nodes-new already exists. Delete it first; it is not live.\n";
        return 1;
    }
    if (!@rename(NODES_DIR, NEW_DIR)) {
        echo "The live map could not be moved aside. Stop Apache in the XAMPP control panel and try again.\n";
        return 1;
    }
    if (!@rename(PREVIOUS_DIR, NODES_DIR)) {
        @rename(NEW_DIR, NODES_DIR);
        echo "The earlier map could not be put back, so nothing changed. Stop Apache and try again.\n";
        return 1;
    }
    clear_thumbnails();

    echo "Rolled back. The map you had published is in assets/nodes-new; --publish puts it live again.\n";

    return 0;
}

/** Previews are named by photo id, and ids are reused between maps. */
function clear_thumbnails(): void
{
    foreach (glob(THUMBS_DIR . '/*.webp') ?: [] as $file) {
        @unlink($file);
    }
}

/** Deletes a folder this tool made. Only ever called with NEW_DIR. */
function remove_folder(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') {
            $path = $dir . '/' . $name;
            is_dir($path) ? remove_folder($path) : unlink($path);
        }
    }
    rmdir($dir);
}

/* ---------------------------------------------------------------- sample */

/**
 * A small tree for trying the tool: one building, two floors, three rooms,
 * one photo that is not 360, one empty folder, one file that is not a photo,
 * one room whose photos do not say when they were taken, and one whose
 * file names run against the order they were taken in.
 */
function make_sample(string $dir): int
{
    if (!gd_ready()) {
        echo "Making sample photos needs GD. Run this with php -d extension=gd.\n";
        return 1;
    }
    if ($dir === '' || (is_dir($dir) && count(scandir($dir) ?: []) > 2) || is_file($dir)) {
        echo "Give an empty or new folder for the sample.\n";
        return 1;
    }

    $at = static fn (int $s): string => sprintf('2026:09:24 09:%02d:%02d', intdiv($s, 60), $s % 60);
    $b  = 'Main Building';

    sample_photo("{$dir}/Main Gate/gate.jpg", 'GATE', $at(0));
    sample_photo("{$dir}/{$b}/Fixed Path/1st Floor/p1.jpg", 'F1 PATH 1', $at(10));
    sample_photo("{$dir}/{$b}/Fixed Path/1st Floor/p2.jpg", 'F1 PATH 2', $at(11));
    sample_photo("{$dir}/{$b}/Fixed Path/2nd Floor/p1.jpg", 'F2 PATH 1', $at(20));
    sample_photo("{$dir}/{$b}/Fixed Path/2nd Floor/p2.jpg", 'F2 PATH 2', $at(21));
    sample_photo("{$dir}/{$b}/Fixed Path/2nd Floor/p3.jpg", 'F2 PATH 3', $at(22));
    // Taken c3, c2, c1: the file names run backwards.
    sample_photo("{$dir}/{$b}/1st Floor/Cashier/c1.jpg", 'CASHIER', $at(33));
    sample_photo("{$dir}/{$b}/1st Floor/Cashier/c2.jpg", 'CASHIER 2', $at(32));
    sample_photo("{$dir}/{$b}/1st Floor/Cashier/c3.jpg", 'CASHIER 1', $at(31));
    sample_photo("{$dir}/{$b}/1st Floor/Room 107/a.jpg", '107 1', $at(40));
    sample_photo("{$dir}/{$b}/1st Floor/Room 107/b.jpg", 'ROOM 107', $at(41));
    sample_photo("{$dir}/{$b}/1st Floor/Room 107/square.jpg", 'NOT 360', $at(42), 300, 300);
    sample_photo("{$dir}/{$b}/2nd Floor/Library/l1.jpg", 'LIBRARY 1', null);
    sample_photo("{$dir}/{$b}/2nd Floor/Library/l2.jpg", 'LIBRARY', null);
    mkdir("{$dir}/{$b}/2nd Floor/Store Room", 0775, true);
    file_put_contents("{$dir}/{$b}/2nd Floor/readme.txt", "Not a photo.\n");

    echo "Sample made in {$dir}. Try: php -d extension=gd tools/import-photos.php --check \"{$dir}\"\n";

    return 0;
}

/** A 2:1 picture with a big label, so each step is recognisable in the viewer. */
function sample_photo(string $path, string $text, ?string $taken, int $width = 512, int $height = 256): void
{
    @mkdir(dirname($path), 0775, true);

    $small = imagecreatetruecolor(128, 64);
    $hue   = crc32($text);
    imagefill($small, 0, 0, imagecolorallocate($small, 40 + $hue % 120, 60 + ($hue >> 8) % 120, 90 + ($hue >> 16) % 120));
    $white = imagecolorallocate($small, 255, 255, 255);
    imagestring($small, 5, (int) max(0, (128 - 9 * strlen($text)) / 2), 24, $text, $white);

    $big = imagecreatetruecolor($width, $height);
    imagecopyresized($big, $small, 0, 0, 0, 0, $width, $height, 128, 64);
    ob_start();
    imagejpeg($big, null, 85);
    $jpeg = (string) ob_get_clean();

    file_put_contents($path, $taken === null ? $jpeg : jpeg_with_taken_time($jpeg, $taken));
}

/** Adds a minimal EXIF block holding only DateTimeOriginal, the way a camera would. */
function jpeg_with_taken_time(string $jpeg, string $when): string
{
    $tiff = "II*\0" . pack('V', 8)                                                  // little endian, IFD0 at 8
        . pack('v', 1) . pack('vvVV', 0x8769, 4, 1, 26) . pack('V', 0)              // IFD0: pointer to the EXIF IFD
        . pack('v', 1) . pack('vvVV', 0x9003, 2, 20, 44) . pack('V', 0)             // EXIF IFD: DateTimeOriginal
        . $when . "\0";
    $app1 = "Exif\0\0" . $tiff;

    return substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2);
}
