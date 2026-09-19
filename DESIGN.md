---
name: EduTrack
description: Campus map & room-to-room walkthrough for ACLC College, Mandaue Campus
colors:
  brand-blue: "#0044d6"
  brand-red: "#D3112A"
  white: "#FFFFFF"
  page: "#F8F9FA"
  surface: "#FFFFFF"
  ink: "#1A1A1A"
  muted: "#6B7280"
  hairline: "#E5E7EB"
  card-edge: "#edf2f7"
  card-edge-hover: "#cbd5e1"
  control-edge: "#6B7280"
  field-fill: "#f3f4f6"
  field-placeholder: "#9ca3af"
  danger: "#D3112A"
  gradient-deep: "#001253"
  gradient-mid: "#0033a0"
  gradient-bright: "#0072ff"
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
    fontFamily: "'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif"
    fontSize: "clamp(2.5rem, 7vw, 5rem)"
    fontWeight: 800
    lineHeight: 0.92
    letterSpacing: "-0.045em"
  headline:
    fontFamily: "'Inter', system-ui, sans-serif"
    fontSize: "1.75rem"
    fontWeight: 700
    lineHeight: 1.2
    letterSpacing: "-0.02em"
  title:
    fontFamily: "'Inter', system-ui, sans-serif"
    fontSize: "1.25rem"
    fontWeight: 600
    lineHeight: 1.35
    letterSpacing: "-0.01em"
  body:
    fontFamily: "'Inter', system-ui, sans-serif"
    fontSize: "1rem"
    fontWeight: 400
    lineHeight: 1.5
    letterSpacing: "normal"
  label:
    fontFamily: "'Inter', system-ui, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 600
    lineHeight: 1.4
    letterSpacing: "normal"
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
    letterSpacing: "normal"
  admin-label:
    fontFamily: "'Barlow', sans-serif"
    fontSize: "0.9375rem"
    fontWeight: 600
    lineHeight: 1.4
    letterSpacing: "normal"
  admin-data:
    fontFamily: "'IBM Plex Mono', monospace"
    fontSize: "1rem"
    fontWeight: 500
    lineHeight: 1.4
    letterSpacing: "normal"
rounded:
  panel: "20px"
  control: "14px"
  field: "12px"
  chip: "10px"
  circle: "50%"
spacing:
  s1: "4px"
  s2: "8px"
  s3: "12px"
  s4: "16px"
  s5: "24px"
  s6: "32px"
components:
  button-primary:
    textColor: "{colors.white}"
    typography: "{typography.body}"
    rounded: "{rounded.control}"
    padding: "12px 16px"
    height: "52px"
  button-primary-disabled:
    backgroundColor: "{colors.control-edge}"
    textColor: "{colors.white}"
    rounded: "{rounded.control}"
  button-ghost:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    typography: "{typography.label}"
    rounded: "{rounded.chip}"
    padding: "0 16px"
    height: "44px"
  input-field:
    backgroundColor: "{colors.field-fill}"
    textColor: "{colors.ink}"
    typography: "{typography.body}"
    rounded: "{rounded.field}"
    padding: "0 16px"
    height: "52px"
  input-field-focus:
    backgroundColor: "{colors.white}"
    textColor: "{colors.ink}"
    rounded: "{rounded.field}"
  directory-row:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    typography: "{typography.title}"
    rounded: "{rounded.panel}"
    padding: "24px"
  directory-row-lead:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    typography: "{typography.headline}"
    rounded: "{rounded.panel}"
    padding: "32px 24px"
  identity-field:
    textColor: "{colors.white}"
    typography: "{typography.display}"
    padding: "clamp(24px, 5vw, 48px)"
---

# Design System: EduTrack

## Overview

**Creative North Star: "The Building Directory"**

This is the board bolted to the wall in a lobby, rebuilt for a phone. Someone arrives at the Admin Building gate on enrolment day, does not know where room 302 is, and does not want to read a map — they want the list of places, ranked, and then to be walked there. Every decision in this system answers to that board: destinations are ranked by type size rather than presented as equal peers, the institution's seal sits on a solid field of its own colour, a room name is always paired with its floor beneath it, and the whole thing is set to be read at arm's length in daylight on a cheap screen.

The system is built from two surfaces that never blend. A **blue identity field** carries the seal, the wordmark and the campus name; a **white working surface** carries everything the visitor actually does. On a phone the field is a header band with the surface running beneath it; from 900px they are two full-height columns. Nothing in this product is a small card floating in grey — that is the shape a mobile layout takes when nobody designed the desktop, and it is the specific failure this world replaced.

Restraint is the discipline that makes the boldness work. The identity field and the primary action are loud; everything else is quiet. Red is almost absent by design, so that the two places it does appear — a field you filled in wrong, and the step you are standing on in the walkthrough — carry real weight.

**Key Characteristics:**
- Two surfaces, never blended: blue identity field, white working surface
- Destinations are bordered cards, ranked by type size — the lead at roughly twice the height of its peers, never equal-weight
- One accent used as a field, not as decoration
- Red reserved for error and for "you are here"
- Built for a low-end phone on weak campus Wi-Fi: no photo backgrounds, no blur, no continuous motion

## Colors

An institutional blue-and-white palette taken from the ACLC seal, with red held in reserve as a scalpel.

### Primary
- **Directory Blue** (`#0044d6`): the anchor. Every element a visitor can act on — primary buttons, links, focus rings, active states — and the large identity field itself. Blue is the one hue permitted to occupy a large area.
- **Field Gradient** (`#001253` → `#0033a0` → `#0072ff`, 135°): the identity field and every primary button. A three-stop diagonal, deep navy through mid to bright. It is the only gradient in the system and it is never mixed with red.

### Secondary
- **Signal Red** (`#D3112A`): a scalpel, never a paintbrush. Permitted in exactly three places — inline field errors, the error alert, and the current-step tick on the walkthrough route strip. Never a large background, never a full button, never a heading, and never on top of blue.

### Neutral
- **Paper** (`#FFFFFF`): every working surface — the white column, cards, the room list, field fills at focus.
- **Page** (`#F8F9FA`): the ground behind the working surface, and the press wash under a tapped row.
- **Ink** (`#1A1A1A`): body text and headings on light surfaces. 16.5:1 on Page.
- **Muted** (`#6B7280`): secondary text, helper copy, floor tags, and — importantly — the visible boundary of interactive controls. 4.83:1 on white.
- **Hairline** (`#E5E7EB`) and **Card Edge** (`#edf2f7`): dividers and panel edges. Decorative only.
- **Card Edge Hover** (`#cbd5e1`): the same edge darkened while a row is under the pointer, so the lift is not carried by shadow alone.
- **Field Fill** (`#f3f4f6`) with **Placeholder** (`#9ca3af`): the resting state of a text input.

### Named Rules

**The Scalpel Rule.** Red appears at most once per screen, and only where it means *wrong* or *here*. If a screen has no error and no position marker, it has no red. Most screens have no red.

**The Two-Greys Rule.** `#E5E7EB` draws dividers and panel edges; `#6B7280` draws anything a finger or a keyboard can land on. The split is not cosmetic: a control whose only boundary is `#E5E7EB` sits at 1.24:1 on white and fails WCAG 1.4.11, which is exactly how the underline inputs became invisible once before.

**The No-Red-On-Blue Rule.** Red text or a red mark never sits on a blue surface, and blue never sits on red. On the identity field the location pin is white for this reason.

## Typography

**Display / Body / Label Font:** Inter (with `system-ui`, `-apple-system`, `Segoe UI`, Roboto, Arial fallbacks)

**Character:** One family doing every job, with the personality coming from the treatment rather than the face. Inter was chosen for legibility at small sizes on a low-end screen in daylight, because the content is dominated by short room numbers and names. The display treatment — weight 800 at up to 80px with `-0.045em` tracking — is what separates this from a default Inter page; at normal tracking the same wordmark reads as an unstyled system font.

### Hierarchy
- **Display** (800, `clamp(2.5rem, 7vw, 5rem)`, line-height 0.92, `-0.045em`): the EduTrack wordmark on the identity field. Nowhere else.
- **Headline** (700, `1.75rem`, line-height 1.2, `-0.02em`): surface titles, the lead destination row, the arrival room name.
- **Title** (600–700, `1.25rem`, line-height 1.35, `-0.01em`): secondary destination rows, the walkthrough step label, the identity tagline.
- **Body** (400, `1rem`, line-height 1.5): all reading text, all form input text. Never below 16px in an input.
- **Label** (600, `0.875rem`, line-height 1.4): field labels, helper and error text, floor tags, step counters.

### Named Rules

**The Five-Step Rule.** The ramp is five steps and display is set per page as a clamp so it does not become a sixth. A new size is a design failure, not a design decision.

**The Sentence-Case Rule.** Labels are sentence case at `0.875rem` in Ink. Tracked-out uppercase micro-labels are banned: they are one of the most recognisable generated-UI tells, and 12.8px uppercase in grey is the hardest possible way to read "Confirm password".

**The Sixteen-Pixel Rule.** Input text is never below `1rem`. Below it, iOS zooms the page on focus and the visitor loses their place.

## Layout

Every surface is the same shell. `min-height: 100dvh`, a single grid that is `auto 1fr` rows on a phone and `minmax(360px, 40%) 1fr` columns from 900px. The blue identity field is the first cell, the white working surface the second. Working content is capped at `600–680px`, or `480px` for forms, where a wider measure would separate a label from its own field.

The spacing rhythm is 4 / 8 / 12 / 16 / 24 / 32, with surface padding as `clamp(24px, 5vw, 48px)` so the gutter grows with the viewport instead of stepping at breakpoints.

Two surfaces depart from the shell, both deliberately:

- **The room picker** (`map/select-room.html`) is an app shell: the page itself never scrolls and the room list is the single scrolling region, so the search field and the session footer stay put across 41 rooms. On a phone its identity field collapses to a 64px bar to give the list its height back.
- **The walkthrough** (`map/walkthrough.html`) is full-bleed: the 360° panorama fills the viewport and the UI floats over it as fixed bars.

Touch targets are 44px minimum everywhere, 48–52px for primary actions, at every breakpoint. This is a phone-in-hand product used standing up.

Below 900px the identity field collapses to a band of roughly 100px: seal and wordmark share a line, the tagline wraps beneath, and the locality is dropped because the working surface states the building and room count instead. At its full height the band consumed 48% of a 640px phone and pushed every destination below the fold, which is the failure this rule exists to prevent.

### Named Rules

**The No-Marooned-Card Rule.** No surface is a fixed-width card centred in empty space. A `max-width: 420px` card is a mobile layout shown on a monitor; the shell is full-bleed at every width.

**The One-Scroller Rule.** A screen has one scrolling region. Where a list must scroll, the page around it does not.

## Elevation & Depth

Hybrid, and the two halves do different jobs. The **identity field** has no elevation at all — it is a flat plane of colour, and its authority comes from area. The **working surface** uses low, neutral-black shadows to lift interactive objects a short distance off the page, and those shadows respond to state rather than sitting static.

### Shadow Vocabulary
- **Resting panel** (`0 2px 8px rgba(0,0,0,.04)`): directory rows and the room-list panel at rest.
- **Lifted** (`0 8px 24px rgba(0,0,0,.08)`): the same objects on hover, paired with a 2px rise.
- **Pressed** (`0 1px 3px rgba(0,0,0,.06)`): the object settling back onto the page under a press.
- **Brand lift** (`0 4px 12px rgba(0,68,214,.15), 0 2px 4px rgba(0,68,214,.1)`): primary buttons only. The single exception to neutral shadows, and it exists because the button is a gradient object rather than a paper one.
- **Focus ring** (`0 0 0 4px rgba(0,68,214,.15)`): the focused input, paired with a white fill.
- **Over-photo** (`0 8px 32px rgba(0,0,0,.38)`): the arrival card floating on a panorama.

### Named Rules

**The Earned-Shadow Rule.** Shadows respond to state. A flat page is the resting state; lift is an answer to a pointer, and press is an answer to a finger.

**The No-Blur Rule.** `backdrop-filter` is banned outright. It composites every frame the panorama moves, on exactly the hardware this product targets. Where UI must sit over a photograph, it uses a solid or high-opacity flat bar instead — `rgba(26,26,26,.88)` holds white text at 11:1 even against a white corridor wall.

## Shapes

Generously rounded rectangles for surfaces, a tighter radius for controls, and true circles for anything representing a physical object or a waypoint.

Panels and directory rows take **20px**, primary buttons **14px**, inputs **12px**, small ghost controls **10px**. Circles are reserved for the institutional seal, the forward-action disc on the lead destination row, the arrival checkmark, and the in-scene waypoint marker in the panorama.

Borders are hairlines — 1px, never heavier — except the 2px white ring that separates the in-scene waypoint from whatever is behind it.

On desktop the working surface **overlaps the identity field** rather than meeting it flush: a 32px radius on its two left corners, pulled 20px into the blue with `-12px 0 40px rgba(0,0,0,.08)` cast leftward, so it reads as a panel floating over the colour rather than a seam between two blocks. Its left padding grows to `clamp(40px, 6vw, 80px)` to clear the overlap. Below 900px the two stack and the overlap is dropped entirely.

This replaced an earlier scalloped edge. Be aware that the overlap is the most common desktop split-panel treatment there is; it is correct here, but it is not doing any work to distinguish the product, so the distinction has to come from the type and the colour field.

## Components

### Buttons
- **Shape:** 14px radius, 52px min-height, full width by default, flex-centred so a wrapping label cannot spill out.
- **Primary:** the field gradient, white text at `1rem`/700, `0.02em` tracking, with the brand lift shadow. Hover deepens the shadow and rises 2px. Active is `scale(0.98) translateY(1px)`.
- **Disabled:** flat Muted fill at 45% opacity, no shadow.
- **Ghost / back link:** white fill, 1px Muted border, 10px radius, 44px min-height, Ink text. Hover moves border and text to Directory Blue and nudges the icon 3px in the direction of travel.
- **Motion:** `transform 160ms`, `box-shadow 200ms`, named properties only.

### Cards / Containers
- **Corner Style:** 20px.
- **Background:** Paper on Page.
- **Border:** 1px Card Edge.
- **Shadow Strategy:** resting panel at rest, lifted on hover, pressed on press. See Elevation.
- **Internal Padding:** 24px, 32px for the lead row.

### Inputs / Fields
- **Style:** Field Fill background, 1px transparent border, 12px radius, 52px height, 16px horizontal padding.
- **Focus:** background goes to white, border to Directory Blue, plus the focus ring. **No transform** — a field that lifts on focus walks away from its own label and makes the whole column twitch as you tab down it.
- **Error:** `auth.js` writes `border-color: var(--danger)` directly onto the element, so `--danger` must exist in `:root` under that exact name. Error text is Signal Red at label size, directly beneath the field.

### Navigation
There is no persistent nav bar. Wayfinding is the card-anchored back link on every inner surface, plus a session footer on the room picker that resolves to either "Back to home" (guest) or "Log out" (signed in) once `api/whoami.php` answers.

### Route Strip (signature component)
The walkthrough's progress indicator, and the product's one piece of true wayfinding vocabulary: a 4px strip of ticks across the top of the panorama, one per node on the computed path. Upcoming steps sit at 28% white, completed steps at 70%, and the current step is Signal Red — the "you are here" mark. Position is never carried by colour alone; the step counter states it in words beside the room label.

## The Staff Panel

A related system, no longer an identical one. It covers `admin/*.html` and `assets/css/admin.css`, which are frozen and still run the product's earlier dark editorial world — ink and plate backgrounds, paper text, Signal orange, Barlow Condensed and Barlow, IBM Plex Mono for codes. The student-facing surfaces have since moved to the blue-and-white directory world documented above. Treat the two as deliberately divergent until someone decides to reconcile them; do not apply the rules above to `admin/`, and do not apply the rules below to anything else.

**Who it is for.** The people running this panel are older members of staff at the registrar desk, working during enrolment. That single fact drives every difference below, and it inverts the usual instinct for admin screens: this one is deliberately *less* dense than the student-facing pages, not more.

**What changes, and why:**

- **Type is larger throughout.** Body text is `1.0625rem` (17px), and nothing anywhere in the panel goes below `0.9375rem` (15px). See the `admin-*` entries in the typography block above.
- **Secondary text is lightened** to `#b9bfc9`, which clears AAA contrast on Ink rather than AA. A dimmer grey is fine for a glance at a gate; it is not fine for an hour at a desk.
- **Semantic colours are brightened** (`#5cc48d`, `#ef6b6b`, `#e0a33c`) for the same reason, and a Warning tone is introduced, which the student-facing pages never needed. Status is never carried by colour alone: every pill contains its own word, so the screens read correctly in greyscale.
- **Targets are bigger.** Table rows 60px, buttons and inputs 52px, navigation items 56px, against the 44px minimum the rest of the product uses.
- **Layout uses a 264px left rail** with a content area. One-decision-per-screen is right for someone standing at a gate and wrong for someone comparing forty rows. Below 900px the rail becomes a horizontal strip.
- **No frosted glass anywhere.** There is no campus photography behind these screens, so every surface is a flat plate with a hairline.

### Named Rules

**The Plain Words Rule.** Controls say what will happen in ordinary language: "Turn off", not "Deactivate"; "Ran out of time", not "Expired". No control is icon-only, and every destructive action is confirmed in a dialog that states the consequence in a full sentence.

## Do's and Don'ts

### Do:
- **Do** let Directory Blue occupy large areas — it is the one hue with that permission, and the identity field is the system's signature.
- **Do** rank destinations by type size and height (28px lead at roughly twice the row height, 20px secondary), so the entry that needs no account is unmistakable. Destinations are bordered cards; the ranking, not the container, carries the hierarchy.
- **Do** give every interactive control a boundary of at least 3:1 against its background; use Muted `#6B7280`, not Hairline `#E5E7EB`.
- **Do** keep every touch target at 44px or above at every breakpoint, and 48–52px for primary actions.
- **Do** name the exact properties in every `transition`, and keep UI motion at or under 200ms.
- **Do** pair a room name with its floor on a second line; the two strings are each up to 24 characters and cannot share a 360px row.

### Don't:
- **Don't** use `backdrop-filter` anywhere. See The No-Blur Rule.
- **Don't** put red on blue, or blue on red, and don't let red exceed one small mark per screen.
- **Don't** add a third hue. The palette is blue, red, and neutrals — no green, orange, teal, purple, or yellow, including for success states, which speak in Directory Blue.
- **Don't** centre a fixed-width card in empty space on desktop. See The No-Marooned-Card Rule.
- **Don't** scale any element that contains text on hover. `scale(1.01)` re-rasterises the label at a fractional size and leaves it visibly soft for the whole interaction; lift instead.
- **Don't** use `transition: all`, or any easing curve that overshoots (`cubic-bezier(…, 1.275)`), on a state a visitor triggers repeatedly.
- **Don't** set labels in tracked-out uppercase, and don't introduce a sixth type size.
- **Don't** run continuous or infinite animation. The in-scene waypoint marker is a solid disc with a white ring for exactly this reason.
