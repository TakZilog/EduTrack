#!/usr/bin/env node
/*
 * Caveman mode indicator.
 *
 *   node caveman-status.mjs --hook        (UserPromptSubmit): parse the prompt,
 *                                         persist the active caveman level.
 *   node caveman-status.mjs --statusline  (statusLine): print the indicator.
 *
 * State lives in .claude/.caveman-mode (one word: full|lite|ultra|
 * wenyan-lite|wenyan-full|wenyan-ultra|off). Missing file defaults to "full"
 * because this user runs caveman full every session.
 */
import fs from "node:fs";
import path from "node:path";

const LEVELS = [
  "lite",
  "full",
  "ultra",
  "wenyan-lite",
  "wenyan-full",
  "wenyan-ultra",
  "off",
];
const DEFAULT_LEVEL = "full";

const projectDir = process.env.CLAUDE_PROJECT_DIR || process.cwd();
const stateFile = path.join(projectDir, ".claude", ".caveman-mode");

function readLevel() {
  try {
    const v = fs.readFileSync(stateFile, "utf8").trim().toLowerCase();
    return LEVELS.includes(v) ? v : DEFAULT_LEVEL;
  } catch {
    return DEFAULT_LEVEL;
  }
}

function writeLevel(level) {
  try {
    fs.mkdirSync(path.dirname(stateFile), { recursive: true });
    fs.writeFileSync(stateFile, level + "\n");
  } catch {
    /* non-fatal: indicator just keeps last known state */
  }
}

function readStdin() {
  try {
    return fs.readFileSync(0, "utf8");
  } catch {
    return "";
  }
}

function levelFromPrompt(prompt) {
  const p = prompt.toLowerCase();
  const m = p.match(
    /\/caveman(?:\s+(lite|full|ultra|wenyan-lite|wenyan-full|wenyan-ultra|off))?\b/,
  );
  if (m) return m[1] || "full";
  if (/\b(stop caveman|normal mode)\b/.test(p)) return "off";
  if (/\bcaveman mode\b|\btalk like (?:a )?caveman\b/.test(p)) return "full";
  return null;
}

const mode = process.argv[2];

if (mode === "--hook") {
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
    const next = levelFromPrompt(prompt);
    if (next) writeLevel(next);
    process.exit(0);
  });
} else {
  const level = readLevel();
  let model = "";
  let dir = "";
  try {
    const j = JSON.parse(readStdin());
    model = j?.model?.display_name || "";
    dir = j?.workspace?.current_dir || j?.cwd || "";
  } catch {
    /* statusline still renders without session json */
  }
  const badge = level === "off" ? "caveman off" : `\u{1F9B4} caveman ${level}`;
  const segments = [badge];
  if (model) segments.push(model);
  const base = path.basename(dir || projectDir);
  if (base) segments.push(base);
  process.stdout.write(segments.join("  |  "));
}
