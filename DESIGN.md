---
name: EduTrack
description: Campus map & room-to-room walkthrough for ACLC College, Mandaue Campus
colors:
  directory-blue: "#0044d6"
  blue-deep: "#00339f"
  blue-wash: "#e6edfc"
  signal-red: "#D3112A"
  red-deep: "#8f0b1d"
  red-wash: "#fbe7ea"
  white: "#FFFFFF"
  wall: "#eef2f8"
  surface: "#FFFFFF"
  ink: "#10172a"
  muted: "#5b6474"
  border: "#d8dfeb"
  border-strong: "#b6c0d1"
  control-edge: "#5b6474"
  placeholder: "#6B7280"
  danger: "#D3112A"
  # Staff panel only (assets/css/admin.css). See "The Staff Panel" below.
  on-signal: "#ffffff"
  admin-ink: "#15181d"
  admin-plate: "#1b1e25"
  admin-plate-2: "#21252d"
  admin-plate-3: "#272c35"
  admin-paper: "#f6f4ee"
  admin-secondary: "#b9bfc9"
  admin-signal: "#b3431f"
  admin-signal-deep: "#9e3a1a"
  admin-success: "#5cc48d"
  admin-danger: "#ef6b6b"
  admin-warning: "#e0a33c"
typography:
  display:
    fontFamily: "'Barlow Condensed', 'Barlow', system-ui, sans-serif"
    fontSize: "clamp(3.25rem, 9vw, 5.75rem)"
    fontWeight: 700
    lineHeight: 0.92
  headline:
    fontFamily: "'Barlow Condensed', 'Barlow', system-ui, sans-serif"
    fontSize: "2.25rem"
    fontWeight: 700
    lineHeight: 1.05
  title:
    fontFamily: "'Barlow Condensed', 'Barlow', system-ui, sans-serif"
    fontSize: "1.375rem"
    fontWeight: 600
    lineHeight: 1.1
  subtitle:
    fontFamily: "'Barlow Condensed', 'Barlow', system-ui, sans-serif"
    fontSize: "1.125rem"
    fontWeight: 600
    lineHeight: 1.1
  numeral:
    fontFamily: "'Barlow Condensed', 'Barlow', system-ui, sans-serif"
    fontSize: "1.875rem"
    fontWeight: 700
    lineHeight: 1
  body:
    fontFamily: "'Barlow', system-ui, sans-serif"
    fontSize: "1.0625rem"
    fontWeight: 400
    lineHeight: 1.5
  label:
    fontFamily: "'Barlow', system-ui, sans-serif"
    fontSize: "0.9375rem"
    fontWeight: 600
    lineHeight: 1.4
  # Staff panel ramp, deliberately larger. Nothing here goes below 0.9375rem.
  admin-page:
    fontFamily: "'Barlow Condensed', sans-serif"
    fontSize: "2rem"
    fontWeight: 700
    lineHeight: 1.05
    letterSpacing: "0.01em"
  admin-section:
    fontFamily: "'Barlow Condensed', sans-serif"
    fontSize: "1.375rem"
    fontWeight: 700
    lineHeight: 1.15
    letterSpacing: "0.01em"
  admin-body:
    fontFamily: "'Barlow', sans-serif"
    fontSize: "1.0625rem"
    fontWeight: 400
    lineHeight: 1.55
  admin-label:
    fontFamily: "'Barlow', sans-serif"
    fontSize: "0.9375rem"
    fontWeight: 600
    lineHeight: 1.4
  admin-data:
    fontFamily: "'IBM Plex Mono', monospace"
    fontSize: "1rem"
    fontWeight: 500
    lineHeight: 1.4
rounded:
  sign: "6px"
  circle: "50%"
spacing:
  s1: "4px"
  s2: "8px"
  s3: "12px"
  s4: "16px"
  s5: "24px"
  s6: "32px"
  s7: "48px"
  s8: "64px"
components:
  signbar:
    backgroundColor: "{colors.directory-blue}"
    textColor: "{colors.white}"
    height: "64px"
  button-primary:
    backgroundColor: "{colors.directory-blue}"
    textColor: "{colors.white}"
    typography: "{typography.title}"
    rounded: "{rounded.sign}"
    height: "52px"
  button-primary-pressed:
    backgroundColor: "{colors.blue-deep}"
    textColor: "{colors.white}"
  input-field:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    typography: "{typography.body}"
    rounded: "{rounded.sign}"
    padding: "0 16px"
    height: "52px"
  floor-numeral:
    backgroundColor: "{colors.directory-blue}"
    textColor: "{colors.white}"
    typography: "{typography.numeral}"
    rounded: "{rounded.sign}"
    size: "52px"
  directory-board:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.sign}"
  room-row:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    typography: "{typography.body}"
    height: "56px"
  room-row-hover:
    backgroundColor: "{colors.blue-wash}"
    textColor: "{colors.directory-blue}"
---

# Design System: EduTrack

## Overview

**Creative North Star: "The Elevator Directory"**

The board screwed to the wall beside a lobby lift, rebuilt for a phone. Someone arrives at the Admin Building gate on enrolment day, does not know where room 302 is, and does not want to read a map. A lift directory already solved this: the building is listed floor by floor, top floor first, each floor marked by a big painted numeral, every room under the floor it is on, and a red mark for where you are standing. Every page answers to that board.

Every page carries the same flat blue sign strip across the top, with the seal and the EduTrack wordmark, and the wordmark is always a link home. Below it the content sits on a cool wall-grey page in a single left-aligned column, capped at 1160px. There is no split screen and no floating card centred in empty space. Signs are flat: no gradients, no blur, no photographic backgrounds, small square corners.

This replaced a split-screen world with a blue gradient identity column and soft 20px cards. That look was coherent, but the split panel and the blue gradient are the two most common generated-interface patterns there are, so they did nothing to say what this product is. The floor numeral does.

**Key Characteristics:**
- One flat blue sign strip on every page; the wordmark links home
- The building's floors are the navigation spine, top floor first, everywhere
- The floor numeral on a blue square is the signature mark
- Red appears once at most, and only for an error or "you are here / start here"
- Built for a low-end phone on weak campus Wi-Fi: no gradients, no blur, no photos, no continuous motion

## Colors

The ACLC seal's blue and red, with every neutral tinted slightly toward the blue so the whole page reads as one sign.

### Primary
- **Directory Blue** (`#0044d6`): the sign strip, the floor numerals, primary buttons, links, focus rings, selected states. 7.5:1 against white in both directions. The only hue allowed to cover a large area.
- **Blue Deep** (`#00339f`): hover and pressed state of anything blue.
- **Blue Wash** (`#e6edfc`): the row under the pointer and the selected floor in the rail. Blue text on it is 6.4:1.

### Secondary
- **Signal Red** (`#D3112A`): errors, and the pin marking where every route starts. Never a fill, never a heading, never on blue. 5.4:1 on white.

### Neutral
- **Wall** (`#eef2f8`): the page background the signs hang on, and the pinned floor headers.
- **Surface** (`#FFFFFF`): boards, panels, inputs.
- **Ink** (`#10172a`): all text. 17:1 on white.
- **Muted** (`#5b6474`): secondary text, and the visible edge of every input and outline button. 6.0:1 on white, 5.3:1 on Wall.
- **Border** (`#d8dfeb`) and **Border Strong** (`#b6c0d1`): dividers and panel edges. Decorative only.
- **Placeholder** (`#6B7280`): 4.83:1 on white, still clearly lighter than typed text.
- **Red Deep** (`#8f0b1d`) and **Red Wash** (`#fbe7ea`): red text on light fields, and the one pale red field. Used for route-start notes and alternating announcement tags on the home page.

### Named Rules

**The Flat Sign Rule.** No gradients anywhere. A painted sign is one colour. The old blue gradient was the most recognisable generated-UI tell on the page.

**The Scalpel Rule.** Red is the seal's second ink, used in small marks only: an error, the "start here" pin and gate line, the "1 starting point" figure, the blue-red rule under the home heading, and alternate announcement tags. Never a large field, never body text, never on blue. Form pages have no red until something is wrong.

**The Two-Greys Rule.** Border and Border Strong draw decoration. Anything a finger or keyboard lands on draws its edge in Muted, which clears the 3:1 that WCAG 1.4.11 asks of a control boundary.

## Typography

**Display, headings and numerals:** Barlow Condensed (600, 700)
**Body and labels:** Barlow (400, 500, 600)

**Character:** Barlow was drawn from the lettering on California highway signs, so it is wayfinding type by origin rather than by fashion. The condensed cut carries headings and floor numerals and lets a long room name fit a phone row; the regular cut carries reading. The staff panel already used the same family, so the two sides of the product now share one voice.

### Hierarchy
- **Display** (Condensed 700, `clamp(3.25rem, 9vw, 5.75rem)`, line-height 0.92): the home page headline only.
- **Headline** (Condensed 700, `2.25rem`): page titles.
- **Title** (Condensed 600, `1.375rem`, token `--fs-h2`): board headings, floor names, primary button labels.
- **Subtitle** (Condensed 600, `1.125rem`): floor labels in the rail and the pinned list headers.
- **Numeral** (Condensed 700, `1.875rem`, token `--fs-numeral`; `1.125rem` small): floor numbers on blue squares, the sign-strip wordmark, and the code boxes on the confirm-email page.
- **Body** (Barlow 400, `1.0625rem`): reading text and all input text. Barlow sets small, so 17px reads like 16px in most faces, and inputs never go below 16px.
- **Label** (Barlow 600, `0.9375rem`): field labels, helper text, counts, error text. Nothing in the scale goes below 15px.

### Named Rules

**The Sentence-Case Rule.** Every label, button and heading is sentence case. No tracked-out uppercase labels.

**The No-Orphan Rule.** Titles use `text-wrap: balance`, ledes use `text-wrap: pretty`, so no single word is left alone on a line.

## Layout

Every page is the sign strip (64px) above a `.page` container: max-width 1160px, horizontal gutter `clamp(16px, 4vw, 40px)`, left-aligned. Spacing runs 4 / 8 / 12 / 16 / 24 / 32 / 48 / 64.

- **Home**: from 960px a 5fr / 7fr grid, the headline and single action on the left, the directory board on the right. Below 960px they stack, headline first.
- **Room picker**: an app shell. The page never scrolls; the room list is the one scroller, so search and the session footer stay in reach. From 900px a 220px floor rail sits beside the list; below, the rail becomes three equal buttons above it.
- **Forms** (log in, create an account, confirm email): a 460px column. From 960px, log in and create an account add a 340px aside saying that finding a room needs no account.

Touch targets are 44px minimum everywhere and 52px for primary actions and inputs.

### Named Rules

**The One-Scroller Rule.** A screen has one scrolling region.

**The Top-Floor-First Rule.** Floors are always listed top down, 3 then 2 then 1, on the home board, in the rail and in the room list, the order a lift directory reads.

## Elevation & Depth

Flat. Signs do not float. Structure comes from 1px borders, with the blue carried by the floor numerals and the sign strip rather than by accent edges on boxes. State is shown by colour (Blue Wash, border turning blue), not lift. The only shadow token is a 1px hairline (`0 1px 2px rgba(16,23,42,.06)`), and nothing on the student pages currently needs it.

## Shapes

One radius, **6px**, for every panel, board, button, input and floor numeral: the corner of a printed sign. Circles are reserved for the seal. No box carries a thick accent edge on one side: on a rounded panel it clashes with the corners, and a coloured side-tab is the most recognisable generated-UI tell. The only heavy line is the 4px bar in the step indicator, which is a progress bar, not a box edge.

## Components

### Sign Strip (every page)
Flat Directory Blue, 64px. Seal on a white disc (it needs a white backing on a coloured field) plus the wordmark in Condensed 700, together a link home. "ACLC College, Mandaue Campus" on the right, hidden below 560px.

### Floor Numeral (signature)
A floor number in white Condensed 700 on a 52px Directory Blue square, 32px in list headers and the mobile rail. It marks every floor wherever floors appear.

### Directory Board (home)
White board with a Border Strong edge. Header "Admin Building" with the room count. One row per floor, top floor first: numeral, floor name, up to three named rooms "and N more", chevron. Each row links to that floor in the room picker (`select-room.html#floor-N`). Footer: a red pin and "Every route starts at the main gate." Counts and names are filled from `nodes-edges.json`; the board is written out in full so it works without the request.

### Floor Rail and Room List (room picker)
The rail shows each floor with its numeral and count; the floor in view is marked with Blue Wash and a blue border, tracked with an IntersectionObserver on the list. Counts follow the search, and a floor with no matches is disabled. The list pins each floor's header while its rooms scroll under it. Rows are real links, 56px, readable names ("Room 204", "Guidance Office"), Blue Wash on hover with the chevron moving 3px toward the room.

### Buttons
Primary: flat Directory Blue, white Condensed 600 label, 52px, 6px radius, full width in forms. Hover and press go to Blue Deep; press also scales to 0.98. An arrow inside moves 3px forward on hover. Text actions that send a request (resend the code) are buttons styled as links, not `href="#"` anchors.

### Inputs
White, 1px Muted border, 6px radius, 52px. Focus turns the border blue with a Blue Wash ring. No transform on focus. `auth.js` writes `border-color: var(--danger)` on an invalid field, so `--danger` must exist under that exact name. A hint that the visitor needs before typing sits under the field, and is hidden while that field's error is showing.

### Steps (create an account, confirm email)
Two segments with a 4px top bar: blue for done and current, Border Strong for upcoming. It is a real two-step sequence, so the numbering is information, not decoration.

### Route Strip (walkthrough)
A strip of ticks across the top of the panorama, one per node on the path: upcoming at 28% white, done at 70%, current in Signal Red. The step counter states the position in words too.

## The Staff Panel

A related system, not an identical one. It covers `admin/*.html` and `assets/css/admin.css`, which were not part of the student-side redesign and still run the product's earlier dark editorial world: ink and plate backgrounds, paper text, Signal orange, Barlow Condensed and Barlow, IBM Plex Mono for data. It now shares its type family with the student pages. Do not apply the rules above to `admin/`, and do not apply the rules below to anything else.

**Who it is for.** Older members of staff at the registrar desk, working during enrolment. That inverts the usual instinct for admin screens: this one is deliberately *less* dense than the student pages.

**What changes, and why:**
- **Type is larger throughout.** Body is `1.0625rem`, and nothing goes below `0.9375rem`.
- **Secondary text is lightened** to `#b9bfc9`, which clears AAA on Ink rather than AA.
- **Semantic colours are brightened** (`#5cc48d`, `#ef6b6b`, `#e0a33c`), and a Warning tone exists here only. Status is never carried by colour alone; every pill contains its word.
- **Targets are bigger.** Table rows 60px, buttons and inputs 52px, navigation items 56px.
- **Layout uses a 264px left rail.** Below 900px it becomes a horizontal strip.
- **No frosted glass.** Every surface is a flat plate with a hairline.

### Named Rules

**The Plain Words Rule.** Controls say what will happen in ordinary language: "Turn off", not "Deactivate". No control is icon-only, and every destructive action is confirmed in a dialog that states the consequence in a full sentence.

## Do's and Don'ts

### Do:
- **Do** list floors top down everywhere, and mark every floor with its numeral.
- **Do** keep every control boundary at 3:1 or better against its background, using Muted.
- **Do** keep touch targets at 44px minimum and 52px for primary actions.
- **Do** name the exact properties in every `transition`, and keep UI motion at or under 200ms.
- **Do** make every page's wordmark a link home, so no page is a dead end.

### Don't:
- **Don't** use a gradient, `backdrop-filter`, or a photo background on a student page.
- **Don't** put red on blue, or use red for anything but an error or a "you are here / start here" mark.
- **Don't** add a third hue. Success speaks in Directory Blue.
- **Don't** use a radius other than 6px, or a circle for anything but the seal.
- **Don't** centre a fixed-width card in empty space, and don't return to a split-screen layout.
- **Don't** use an em dash in anything a visitor can see or hear.
- **Don't** give a box a thick coloured edge on one side or the top. Errors and success use the whole border in red or blue.
- **Don't** run continuous or infinite animation.
