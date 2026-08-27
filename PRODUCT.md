# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

- **Guest / enrolling visitor** — arrives at the Admin Building gate, needs to find their enrollment room without an account. No login required.
- **Registered student** — has a verified account (email OTP), gets full walkthrough access beyond the guest map.
- **Guard** — staffed at the Admin Building ground-floor desk, issues one-time passphrase-gated registration codes to students who need to create an account.
- **Admin / faculty** (planned, not yet built) — a management role is planned in addition to the three above. Scope and permissions are not yet defined; do not invent a UI or workflow for it until confirmed.

## Product Purpose

EduTrack helps people physically navigate a campus building they don't already know, by walking them room-to-room through real 360° photos along a computed path, rather than making them read a static floor map. Success is a visitor or student reaching the correct room without needing to ask staff for directions.

## Positioning

The mechanism a static campus map or directory can't copy: a real photographic walkthrough (Pannellum 360° viewer) driven by a node-graph of the actual building, with BFS pathfinding from a fixed starting point (the gate) to any selected room. It's navigation by simulated walking, not by reading a map.

## Operating Context

- Primary real-world use is a guest/enrollee standing at or near the Admin Building gate, on their own phone, about to walk the building for the first time (enrollment day foot traffic).
- Guard desk issues short-lived, single-use registration codes in person, gating student account creation.
- Student flow: guard-issued code → register → email OTP verification → login → full access.
- Currently scoped to one building (ACLC College, Mandaue Campus — Admin Building). Expansion to additional buildings or other ACLC campuses is a known future direction; data model and navigation should not assume the graph will always stay single-building.

## Capabilities and Constraints

- Stack: plain HTML/CSS/JS frontend (no build tooling), PHP + MySQL backend (PDO — standardize all `api/*.php` on PDO per HANDOFF.md), running under XAMPP/Laragon locally.
- Campus map currently runs off a flat file (`assets/nodes/nodes-edges.json`, ~90 deduplicated 360° images), not the database; a DB-backed node graph is a known future phase, not yet started.
- OTP codes are session-based (`$_SESSION['otp']`), not a DB table — accepted tradeoff, not a gap to silently fix.
- Guard codes are stored in plain text (short-lived, single-use — accepted tradeoff per HANDOFF.md, not to be "fixed" without being asked).
- Admin/faculty role: existence confirmed, scope undecided (see Users).
- Multi-building/multi-campus expansion: direction confirmed, timeline and design not yet started.

## Brand Commitments

- Product name: **EduTrack**. Institution: **ACLC College — Mandaue Campus**.
- An incumbent visual world already exists in `index.html` (dark industrial/editorial: ink/paper/signal-orange palette, Barlow Condensed + Barlow + IBM Plex Mono, rivet/plate motifs, directory-style nav). Treat this as binding incumbent identity for refinement work, not a blank slate.

## Evidence on Hand

No testimonials, case studies, press, or usage data on hand. Do not fabricate any.

## Product Principles

1. Wayfinding by simulated walking (real photos + path), not map-reading — this is the product's core bet and shouldn't be diluted into a generic static map.
2. Guest path stays zero-friction: no account required to find an enrollment room.
3. Physical-world gating (guard-issued codes) is a deliberate trust boundary for account creation — preserve it, don't route around it in UI shortcuts.
4. Design and build for low-end phones on weak/shared campus wifi first; this is real day-one usage, not an edge case.
5. Treat unfinished backend areas (admin role, DB-backed graph, multi-building) as explicitly open, not silently decided.

## Accessibility & Inclusion

Low-end mobile / weak-network use confirmed as a real, primary constraint (kiosk-style use on phones at the gate). No formal accessibility standard (e.g. WCAG level) confirmed yet — do not invent one.
