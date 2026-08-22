# EduTrack — Current State Handoff

Context for Claude Code: this summarizes everything decided/built so far in a
planning conversation, so you don't need the full chat history. Treat this as
the source of truth over anything currently in the codebase that conflicts
with it.

## Stack
- Frontend: plain HTML/CSS/JS (no build tools)
- Backend: PHP + MySQL via XAMPP
- Project root: C:\xampp\htdocs\EduTrack

## What's confirmed built and working
- Node-graph campus map system in assets/nodes/ (nodes-edges.json, ~90 unique
  360 images deduped from 560 originals via build_node_graph_v2.py)
- Walkthrough viewer: map/select-room.html, map/walkthrough.html,
  map/walkthrough.js — Pannellum-based, BFS pathfinding from gate to any room,
  tested working end-to-end on Room 101
- Frontend pages: index.html, guest-map.html, auth/register.html,
  auth/login.html, auth/verify-otp.html, guard/issue-code.html,
  assets/css/style.css

## Backend status — INCONSISTENT, needs reconciliation before continuing
Two different people (Claude via chat, and Claude Code) designed overlapping
but incompatible backend pieces. Known conflicts as of this handoff:

1. **Database library mismatch**: api/verify-otp.php uses PDO
   ($pdo->prepare()->execute()). An earlier api/register.php draft used
   mysqli (bind_param). Whatever api/db.php currently returns, EVERY api/*.php
   file must use that same style consistently. Standardize on PDO (matches
   what verify-otp.php already has working) unless there's a reason not to.

2. **Missing files**: api/login.php and api/issue-code.php do not exist yet.
   auth/login.html and guard/issue-code.html currently still call the OLD
   mock/client-only logic — they are NOT wired to a backend yet. This is the
   next real work to do.

3. **guard_codes table schema vs. simplified guard UI conflict**: the actual
   DB table requires student_name and student_id (NOT NULL, no default), but
   guard/issue-code.html was deliberately simplified to just a passphrase +
   confirmation checkbox (no name/ID fields). DECISION NEEDED: either add
   name/ID fields back to the guard UI (more traceable, matches current
   table), or make those columns nullable/remove them (simpler, matches
   current UI). Ask the user which they want before building issue-code.php.

4. **Guard codes stored in plain text**: guard_codes.code is a plain
   varchar(10), not hashed. This was flagged as a lower-severity but real
   tradeoff (codes are short-lived + single-use, so risk is lower than
   password exposure, but it's still a knowing tradeoff, not accidental).
   Leave as-is unless the user asks to hash it.

5. **Current actual DB schema** (already created, do not re-import
   sql/schema.sql — that file is now OUT OF DATE vs. what's really in MySQL):

```sql
-- users
id INT PK AUTO_INCREMENT
username VARCHAR(50) UNIQUE NOT NULL
email VARCHAR(255) UNIQUE NOT NULL
password_hash VARCHAR(255) NOT NULL
email_verified TINYINT(1) NOT NULL DEFAULT 0
created_at DATETIME DEFAULT CURRENT_TIMESTAMP

-- guard_codes
id INT PK AUTO_INCREMENT
code VARCHAR(10) UNIQUE NOT NULL          -- plain text, see note #4 above
student_name VARCHAR(150) NOT NULL         -- see note #3 above, conflicts with current UI
student_id VARCHAR(50) NOT NULL            -- see note #3 above, conflicts with current UI
used TINYINT(1) NOT NULL DEFAULT 0
used_by_user_id INT UNSIGNED NULL (FK -> users.id)
created_at DATETIME DEFAULT CURRENT_TIMESTAMP
expires_at DATETIME NOT NULL
used_at DATETIME NULL
```

   NOTE: there is no otp_codes table. OTP is stored server-side in
   $_SESSION['otp'] (email, code, expires_at) instead of the database. This
   works but means OTP state is lost if the PHP session ends — acceptable
   for now, flag to the user if it causes issues later.

   NOTE: nodes/edges/rooms tables (for the campus map) were never created in
   MySQL — the map system currently runs entirely off assets/nodes/nodes-
   edges.json as a flat file, NOT the database. That's fine for now (Phase 6
   in the plan is "connect real images + real graph data" via the DB, still
   not started).

6. GUARD_PASSPHRASE is already set in api/config.php (real value, don't
   need to change it).

## Immediate next steps, in order
1. Confirm/fix db.php consistency (PDO everywhere)
2. Resolve the guard_codes name/ID field conflict (ask user)
3. Build api/login.php (check username+password_hash, require
   email_verified=1, start session)
4. Build api/issue-code.php (require GUARD_PASSPHRASE match, generate 6-char
   code, insert into guard_codes with expires_at = +30 min, return plain
   code once)
5. Wire auth/login.html and guard/issue-code.html to call these real
   endpoints (both currently still have mock/setTimeout fake logic — needs
   replacing with real fetch() calls, same pattern already used in
   auth/register.html and auth/verify-otp.html)
6. Full end-to-end test: issue code -> register -> verify OTP email -> login
7. Only after that works: Phase 6 (real DB-backed node graph) and Phase 8
   (admin panel)

## Known-good reference files (already correct, don't need changes)
- map/walkthrough.js — BFS pathfinding logic, working
- assets/nodes/nodes-edges.json — real campus data, working
- api/mail.php — send_otp_email() extracted here, working
- api/verify-otp.php — working, PDO-based (this is the style to standardize on)
