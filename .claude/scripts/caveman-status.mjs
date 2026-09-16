#!/usr/bin/env node
/*
 * Project status line: caveman badge + live skill indicator.
 *
 *   node caveman-status.mjs --hook        (UserPromptSubmit): parse the prompt,
 *                                         persist the active caveman level.
 *   node caveman-status.mjs --statusline  (statusLine): print the indicator.
 *
 * State and rendering live in ~/.claude/skill-pulse/caveman.js so this project
 * status line, the user status line, and the caveman hooks cannot disagree.
 * The project status line overrides the user one, so it must render the skill
 * segment too — otherwise the skill indicator disappears inside this project.
 */
import fs from "node:fs";
import path from "node:path";
import os from "node:os";
import { createRequire } from "node:module";

const require = createRequire(import.meta.url);
const PULSE = path.join(os.homedir(), ".claude", "skill-pulse");

// The status line must render even if skill-pulse is missing or broken.
function load(mod) {
  try {
    return require(path.join(PULSE, mod));
  } catch {
    return null;
  }
}
const caveman = load("caveman.js");
const lib = load("lib.js");

function readStdin() {
  try {
    return fs.readFileSync(0, "utf8");
  } catch {
    return "";
  }
}

if (process.argv[2] === "--hook") {
  let raw = "";
  process.stdin.setEncoding("utf8");
  process.stdin.on("data", (d) => (raw += d));
  process.stdin.on("end", () => {
    let prompt = "";
    try {
      prompt = JSON.parse(raw).prompt || "";
    } catch {
      /* ignore malformed payload */
    }
    const next = caveman && caveman.levelFromText(prompt);
    if (next) caveman.writeLevel(next);
    process.exit(0);
  });
} else {
  let session = "";
  let model = "";
  let dir = "";
  try {
    const j = JSON.parse(readStdin());
    session = j?.session_id || "";
    model = j?.model?.display_name || "";
    dir = j?.workspace?.current_dir || j?.cwd || "";
  } catch {
    /* status line still renders without session json */
  }
  const segments = [];
  if (caveman) segments.push(caveman.renderSegment(session));
  if (model) segments.push(model);
  const base = path.basename(dir || process.env.CLAUDE_PROJECT_DIR || process.cwd());
  if (base) segments.push(base);
  if (lib) {
    try {
      // caveman has its own badge above; excluded so it is not named twice.
      const seg = lib.renderSegment(session, { exclude: ["caveman"] });
      if (seg) segments.push(seg);
    } catch {
      /* skill segment is optional */
    }
  }
  process.stdout.write(segments.join("  |  "));
}
