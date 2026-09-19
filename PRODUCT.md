# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

- **Guest / enrolling visitor** — arrives at the Admin Building gate, needs to find their enrollment room without an account. No login required, and this path must stay open.
- **Registered student** — has a verified account (email + 6-digit OTP), gets full walkthrough access beyond the guest map.
- **Staff / registrar (prototype)** — a staff panel exists at `admin/*` as scaffolding. It is **not** considered shipped. Seven pages and a dedicated stylesheet are present; treat them as a prototype, not a contract. Scope and permissions remain undecided, and whether faculty is distinct from registrar is undecided. Do not invent a permission model.

A **Guard** role previously existed — an in-person desk that issued one-time registration codes. It has been removed from the product. Do not reinstate it, do not recreate `Guard/*`, `guard_codes`, a `guard_login` scope, or an issue-code flow, and do not treat surviving references to it as a feature to restore.

## Product Purpose

EduTrack helps people physically navigate a campus building they don't already know, by walking them room-to-room through real 360° photos along a computed path, rather than making them read a static floor map. Success is a visitor or student reaching the correct room without needing to ask staff for directions.

## Positioning

The mechanism a static campus map or directory can't copy: a real photographic walkthrough (Pannellum 360° viewer) driven by a node-graph of the actual building, with BFS pathfinding from a fixed starting point (the gate) to any selected room. It's navigation by simulated walking, not by reading a map.

## Operating Context

- Primary real-world use is a guest/enrollee standing at or near the Admin Building gate, on their own phone, about to walk the building for the first time (enrollment day foot traffic).
- **Account creation is open.** Anyone with a working email address can register: full name, email, password, then a 6-digit OTP sent to that address, then login. There is no in-person step and no registration code. This is the confirmed permanent model, not a temporary state.
- Currently scoped to one building (ACLC College, Mandaue Campus — Admin Building), covering 41 rooms across three floors. Expansion to additional buildings or other ACLC campuses is a known future direction; the data model and navigation should not assume the graph will always stay single-building.

## Capabilities and Constraints

- Stack: plain HTML/CSS/JS frontend (no build tooling), PHP + MySQL backend (PDO — standardize all `api/*.php` on PDO per HANDOFF.md), running under XAMPP locally.
- Campus map runs off a flat file (`assets/nodes/nodes-edges.json`, ~90 deduplicated 360° images, 41 rooms), not the database; a DB-backed node graph is a known future phase, not yet started.
- OTP codes are session-based (`$_SESSION['otp']`), not a DB table — accepted tradeoff, not a gap to silently fix.
- Staff panel scope and permissions: undecided (see Users).
- Multi-building/multi-campus expansion: direction confirmed, timeline and design not yet started.
- **Known dead files left by the Guard removal**, confirmed as leftovers rather than features. They are not in use and should be removed when someone is working in those directories: `admin/codes.html`, `api/guard-login.php`, and stale comments in `api/register.php` that refer to "the guard" and to guessing registration codes. The six-digit `code` in `api/register.php` is the email OTP, not a registration code.

## Brand Commitments

- Product name: **EduTrack**. Institution: **ACLC College — Mandaue Campus**.
- The institutional seal is a binding identity asset: `assets/img/aclc-logo.jpg` (192×192, derived from the school's official circular seal). It is blue and red on white and requires a white backing when placed on a coloured field.
- The visual system is owned by `DESIGN.md`, not by this file. Note that the student-facing surfaces and the staff panel currently run **two deliberately divergent visual worlds**; `DESIGN.md` documents both and says which applies where.

## Evidence on Hand

No testimonials, case studies, press, or usage data on hand. Do not fabricate any. The real assets are the 360° node photography in `assets/nodes/` and the institutional seal in `assets/img/`.

## Product Principles

1. Wayfinding by simulated walking (real photos + path), not map-reading — this is the product's core bet and shouldn't be diluted into a generic static map.
2. Guest path stays zero-friction: no account required to find an enrollment room.
3. Design and build for low-end phones on weak/shared campus wifi first; this is real day-one usage, not an edge case.
4. Treat unfinished areas (staff panel scope, DB-backed graph, multi-building) as explicitly open, not silently decided.

## Accessibility & Inclusion

Low-end mobile / weak-network use is confirmed as a real, primary constraint (kiosk-style use on phones at the gate, outdoors, in daylight). **WCAG AA is a confirmed requirement** for the student-facing surfaces: all text must meet AA contrast, and interactive controls must meet the 3:1 non-text contrast rule (WCAG 1.4.11) for their visible boundary. Touch targets are 44px minimum throughout. The staff panel deliberately exceeds this — it targets AAA contrast on secondary text and larger controls, because it is operated for long stretches by older staff rather than glanced at in a queue.
