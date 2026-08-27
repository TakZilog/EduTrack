---
name: EduTrack
description: Campus map & room-to-room walkthrough for ACLC College, Mandaue Campus
colors:
  ink: "#15181d"
  plate: "#1b1e25"
  paper: "#f6f4ee"
  paper-dim: "rgba(246,244,238,0.58)"
  signal-deep: "#9e3a1a"
  signal: "#b3431f"
  signal-bright: "#e8552a"
  line: "rgba(246,244,238,0.14)"
  muted: "#9aa0ab"
  danger: "#e35d5d"
  success: "#4caf7d"
  # Staff panel only. See "The Staff Panel" section below.
  on-signal: "#ffffff"
  admin-secondary: "#b9bfc9"
  admin-success: "#5cc48d"
  admin-danger: "#ef6b6b"
  admin-warning: "#e0a33c"
  admin-plate-2: "#21252d"
  admin-plate-3: "#272c35"
typography:
  display:
    fontFamily: "'Barlow Condensed', sans-serif"
    fontSize: "clamp(1.7rem, 8vw, 3.2rem)"
    fontWeight: 700
    lineHeight: 1
    letterSpacing: "0.01em"
  body:
    fontFamily: "'Barlow', system-ui, sans-serif"
    fontSize: "0.95rem"
    fontWeight: 400
    lineHeight: 1.4
    letterSpacing: "normal"
  label:
    fontFamily: "'IBM Plex Mono', monospace"
    fontSize: "0.78rem"
    fontWeight: 500
    lineHeight: 1.2
    letterSpacing: "0.1em"
  # Staff panel ramp. Deliberately larger than the student-facing scale above,
  # because the people using it are older. Nothing here goes below 0.9375rem.
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
  admin-wordmark:
    fontFamily: "'Barlow Condensed', sans-serif"
    fontSize: "1.75rem"
    fontWeight: 700
    lineHeight: 1
    letterSpacing: "0.01em"
  admin-signin-wordmark:
    fontFamily: "'Barlow Condensed', sans-serif"
    fontSize: "2.25rem"
    fontWeight: 700
    lineHeight: 1
    letterSpacing: "0.01em"
  admin-eyebrow:
    fontFamily: "'IBM Plex Mono', monospace"
    fontSize: "0.8125rem"
    fontWeight: 500
    lineHeight: 1.2
    letterSpacing: "0.12em"
  admin-badge:
    fontFamily: "'IBM Plex Mono', monospace"
    fontSize: "0.875rem"
    fontWeight: 500
    lineHeight: 1.2
    letterSpacing: "normal"
  admin-stat:
    fontFamily: "'IBM Plex Mono', monospace"
    fontSize: "2.5rem"
    fontWeight: 500
    lineHeight: 1
    letterSpacing: "normal"
  admin-code-display:
    fontFamily: "'IBM Plex Mono', monospace"
    fontSize: "3.25rem"
    fontWeight: 500
    lineHeight: 1.1
    letterSpacing: "0.18em"
  admin-code-inline:
    fontFamily: "'IBM Plex Mono', monospace"
    fontSize: "1.25rem"
    fontWeight: 500
    lineHeight: 1.3
    letterSpacing: "0.1em"
rounded:
  sm: "4px"
  md: "6px"
  lg: "10px"
  pill: "999px"
  circle: "50%"
spacing:
  xs: "6px"
  sm: "12px"
  md: "20px"
  lg: "32px"
  xl: "44px"
components:
  button-primary:
    backgroundColor: "{colors.signal}"
    textColor: "#ffffff"
    typography: "{typography.body}"
    rounded: "{rounded.md}"
    padding: "13px"
    height: "44px"
  button-primary-hover:
    backgroundColor: "{colors.signal-deep}"
    textColor: "#ffffff"
  button-secondary:
    backgroundColor: "transparent"
    textColor: "{colors.paper}"
    typography: "{typography.body}"
    rounded: "{rounded.md}"
    padding: "13px"
    height: "44px"
  card:
    backgroundColor: "{colors.plate}"
    textColor: "{colors.paper}"
    rounded: "{rounded.lg}"
    padding: "34px 32px"
    width: "420px"
  input:
    backgroundColor: "{colors.ink}"
    textColor: "{colors.paper}"
    typography: "{typography.body}"
    rounded: "{rounded.md}"
    padding: "11px 12px"
    height: "44px"
  code-chip:
    backgroundColor: "{colors.ink}"
    textColor: "{colors.signal}"
    typography: "{typography.label}"
    rounded: "{rounded.md}"
    padding: "18px"
---

# Design System: EduTrack

## Overview

**Creative North Star: "The Gate Pass"**

EduTrack looks like the clearance badge it functionally is. The product's entire mechanic runs through a physical checkpoint — a guard at the Admin Building desk hands out a one-time code before anyone can register — and the visual system carries that same institutional, issued-credential feel: riveted plate corners, a mono-spaced institutional eyebrow ("ACLC College — Mandaue Campus"), directory entries tagged with three-letter codes (GST / LOG / REG) like manifest line items, and codes and OTP digits rendered in the same monospace as the labels around them. Nothing about it reads as a consumer app; it reads as signage and paperwork you'd trust at a checkpoint.

The palette is dark-first and photographic: near-black ink with a single warm rust-orange signal color, used sparingly against the campus's own 360° photography rather than illustration. Where the product shows its hero surfaces — the front door, the credential steps, and the walkthrough itself — that photography shows through frosted glass panels. Where it's a quick utility step, the interface goes flat and gets out of the way. Typography is condensed-uppercase for anything that announces itself (headlines, arrival banners, directory titles) and a plain grotesque for anything you read line by line. The system rejects rounded/playful UI cliché and generic SaaS blue/purple; it is utilitarian and editorial rather than friendly.

**Key Characteristics:**
- Dark ink base with one warm accent, used at three deliberate intensities (default / hover / peak emphasis) rather than a broad palette.
- Condensed uppercase display type for anything institutional or declarative; plain body type for instructions and copy.
- Monospace (IBM Plex Mono) reserved for codes, tags, and short machine-readable-feeling labels — never for prose.
- Frosted glass over real campus photography on hero/credential surfaces; flat opaque panels on quick utility steps.
- Riveted-plate and directory-row motifs signal "issued credential," not "web app."
- 44px minimum touch targets everywhere — this is a phone-in-hand, gate-side product first.

## Colors

Restrained: one hue family (warm rust-orange) carries all interactive and emphasis meaning against a near-black/near-white duotone.

### Primary
- **Signal** (`#b3431f`): the default interactive color — primary buttons, focus rings, active nav codes, left-accent borders on code/OTP displays. This is the accent nearly everyone sees.
- **Signal Deep** (`#9e3a1a`): hover/pressed state of Signal. Never used at rest.
- **Signal Bright** (`#e8552a`): reserved for peak-emphasis moments only — the index eyebrow label, the walkthrough route-strip's "current step" tick, focus outlines on the darkest hero surfaces. Deliberately rarer than Signal.

### Neutral
- **Ink** (`#15181d`): base background across the entire product; also the fill for input fields (fields sit a shade "recessed" relative to their card).
- **Plate** (`#1b1e25`): card/panel surface on flat pages; the glass-panel tint (at ~72% opacity with blur) on photographic pages.
- **Paper** (`#f6f4ee`): primary text and iconography on dark.
- **Paper Dim** (`rgba(246,244,238,0.58)`): secondary/tagline text on the darkest hero surfaces (index tagline only).
- **Muted** (`#9aa0ab`): secondary text, field labels, disabled/inactive states, hairline-adjacent icon color.
- **Line** (`rgba(246,244,238,0.14)`): the one hairline/border color used everywhere — card borders, dividers, input borders at rest.

### Semantic
- **Danger** (`#e35d5d`): field errors, error alerts.
- **Success** (`#4caf7d`): success alerts (code generated, etc).

### Named Rules
**The Three-Step Signal Rule.** The accent color only ever appears at one of three fixed intensities — Signal (default), Signal Deep (hover), Signal Bright (peak emphasis). Never introduce a fourth shade or a different hue for interactive/emphasis meaning.

**The One Hue Rule.** All warmth in the system comes from the Signal family. Everything else is neutral ink/paper/muted plus the two semantic colors (danger/success), which are the only non-accent hues allowed.

## Typography

**Display Font:** Barlow Condensed (with system-ui, sans-serif fallback)
**Body Font:** Barlow (with system-ui, sans-serif fallback)
**Label/Mono Font:** IBM Plex Mono (with monospace fallback)

**Character:** A condensed, confident display face for anything that announces or labels a state, paired with a plain, legible grotesque for reading, and a monospace reserved exclusively for codes and tags — so the moment something looks monospaced, the user already knows it's a code to type, copy, or remember.

### Hierarchy
- **Display** (700, `clamp(1.7rem, 8vw, 3.2rem)`, line-height 1, uppercase, 0.01em tracking): page/section headlines — wordmark, card `<h1>`, arrival banner title, picker title.
- **Body** (400–600, 0.95rem, line-height 1.4): form copy, subtitles, alerts, directory descriptions.
- **Label** (500, 0.78–0.82rem, uppercase, 0.02–0.14em tracking, IBM Plex Mono): eyebrow tags, directory three-letter codes, floor tags, step counters, generated codes and OTP digits.

### Named Rules
**The Mono-Means-Code Rule.** IBM Plex Mono never appears in prose. If it's monospaced, it's a code, a tag, or a machine-adjacent label — that association is load-bearing and shouldn't be diluted with decorative mono use.

## Layout

Every surface is a single centered column — one card (max-width 420–440px) vertically and horizontally centered in the viewport (`page-center`: flex, min-height 100vh, center/center). There is no multi-column or dashboard layout anywhere; this is a linear, one-decision-per-screen product by design, matching its one-task-at-a-time physical journey (arrive → find room / register → verify → walkthrough).

Spacing is generous at the card level (32–44px outer padding, 22–24px between grouped elements) and tight within form fields (6px label-to-input, 16px between fields). Mobile is the base case, not a breakpoint: the card collapses to ~90vw with reduced padding under 480px, but touch targets (buttons, inputs, the floating back button) never shrink below 44px at any width — this is non-negotiable per the product's low-end-phone, gate-side usage context.

The walkthrough surface (`map/walkthrough.html`) is the one exception to the centered-card rule: it's full-bleed (the 360° panorama fills the viewport) with fixed-position UI — a top directory strip, a bottom control bar, and a centered arrival banner — floating over the photography rather than containing it.

## Elevation & Depth

Hybrid, and the split is intentional rather than inconsistent: hero and credential surfaces (`index.html`, `auth/login.html`, `auth/register.html`, `map/select-room.html`, `map/walkthrough.html`) sit a frosted glass panel (`background: rgba(27,30,37,0.72)`, `backdrop-filter: blur(14px)`, soft ambient shadow `0 20px 50px rgba(0,0,0,0.35)`) over real campus photography. Quick utility steps (`guest-map.html`, `auth/verify-otp.html`, `guard/login.html`, `guard/issue-code.html`) stay flat — an opaque plate panel with only a 1px hairline border, no blur, no shadow, no photo. The floating back-button pill gets its own smaller ambient shadow (`0 4px 14px rgba(0,0,0,0.3)`) wherever it appears.

### Shadow Vocabulary
- **Glass-card ambient** (`box-shadow: 0 20px 50px rgba(0,0,0,0.35)`): the frosted hero card, floating over photography.
- **Pill-chrome ambient** (`box-shadow: 0 4px 14px rgba(0,0,0,0.3)`): the floating back-button and similar fixed chrome.
- **Inner rim** (`box-shadow: inset 0 1px 0 rgba(246,244,238,0.06)`): a faint top highlight on walkthrough nav buttons, suggesting physical button relief.

### Named Rules
**The Photo-Earns-Glass Rule.** Frosted glass only appears where there's real photography behind it to justify the blur. A flat utility screen never gets a glass card without also getting a photographic background — glass is a response to imagery, not a default card style.

## Shapes

Two form languages coexist deliberately: soft rectangles (4–10px radius) for containers, fields, and buttons — cards at 10px, buttons/inputs at 6px, small chips at 3–4px — and true circles/pills for anything that represents a physical badge or waypoint object: the floating back-button (999px pill), the guard badge-icon and walkthrough hotspot-arrow/arrived-check (50% circle). Nothing in the system uses a sharp 0px corner or a heavy rounded-full button; the radius scale stays modest and architectural, never toy-like.

## Components

### Buttons
- **Shape:** 6px radius, 44px min-height, full-width by default.
- **Primary:** Signal background, white text, 600 weight, 0.92rem, `padding: 13px`. Hover → Signal Deep. Active → `scale(0.98)`. Focus-visible → 2px Signal outline, 2px offset. Disabled → 40% opacity.
- **Secondary/Ghost:** transparent background, Line-colored border, Paper text. Hover → faint paper wash (`rgba(246,244,238,0.04)`) and border lightens to Muted.
- **NavBtn (walkthrough-only variant):** Ink background instead of transparent, Line border, inset top highlight; a `.primary` modifier swaps to Signal Deep fill for the "forward" action, keeping Signal reserved for the one directional action per screen.

### Cards / Containers
- **Corner Style:** 10px radius.
- **Background:** Plate, either opaque (flat pages) or at 72% opacity with 14px blur (glass pages) — see Elevation.
- **Border:** 1px solid Line, always.
- **Internal Padding:** 34px top/28px sides roughly, tightening to 36px/22px under 480px.

### Inputs / Fields
- **Style:** Ink background, 1px Line border, 6px radius, Paper text, Muted placeholder at 70% opacity.
- **Focus:** border shifts to Signal + a 3px soft Signal glow (`box-shadow: 0 0 0 3px rgba(179,67,31,0.2)`). No layout shift.
- **Error:** a Danger-colored helper line appears below the field (`.field-error`); the field border itself does not currently change color on error — noted as-is, not prescribed.
- **OTP digits:** individual 44×52px boxes, centered, IBM Plex Mono, 1.2rem, letter-spaced — same input styling otherwise.

### Code Chip (signature component)
The generated-code / OTP-context display: Ink background, 1px Line border, a 3px Signal left-accent border (the one place a border carries the accent instead of a fill), 18px padding, centered. The code itself renders in IBM Plex Mono at 1.7rem with 5px letter-spacing in Signal — this is the product's most literal "issued credential" moment and should stay visually distinct from ordinary body content.

### Navigation (index directory)
Row-based, not tab/menu-based: each entry is a full-width flex row with a mono 3-letter code chip on the left, a condensed-uppercase title + body description in the middle, and an arrow on the right. Hover/focus shifts the code and arrow to Signal Bright and nudges the arrow 3px right. Rows animate in with a staggered rise-and-fade on load (respects `prefers-reduced-motion`).

### Floating Back Button (signature component)
A pill-shaped (999px) glass chip fixed at top-left on every hero/credential page: `rgba(27,30,37,0.85)` background, 10px blur, Line border, Paper text + arrow icon, ambient pill shadow. Present wherever a page needs an escape hatch without a persistent header/nav bar — this product has no global nav chrome, only this one recurring floating control.

### Route Strip (signature component, walkthrough-only)
A 3px-tall strip of gap-separated ticks spanning the top of the viewport, one tick per step in the current path: unlit (faint paper), done (Muted), current (Signal Bright). This is the walkthrough's only persistent progress indicator and the single place Signal Bright is used as a state fill rather than a hover/focus accent.

## The Staff Panel

A documented extension of this system, not a second design system. It covers `admin/*.html` and `assets/css/admin.css`, and it exists because two of the rules above genuinely cannot hold on an operator console.

**Who it is for.** The people running this panel are older members of staff at the registrar and guard desks, working during enrolment. That single fact drives every difference below, and it inverts the usual instinct for admin screens: this one is deliberately *less* dense than the student-facing pages, not more.

**What stays the same.** Ink and plate backgrounds, paper text, the Signal family as the only accent, Barlow Condensed for headings, Barlow for reading, IBM Plex Mono for codes and data. It reads as the same product.

**What changes, and why:**

- **Type is larger throughout.** Body text is `1.0625rem` (17px) against the student-facing `0.95rem`, and nothing anywhere in the panel goes below `0.9375rem` (15px). See the `admin-*` entries in the typography block above.
- **Secondary text is lightened** from Muted `#9aa0ab` to `#b9bfc9`, which clears AAA contrast on Ink rather than AA. The dimmer grey is fine for a glance at a gate; it is not fine for an hour at a desk.
- **Semantic colours are brightened** (`#5cc48d`, `#ef6b6b`, `#e0a33c`) for the same reason, and a Warning tone is introduced, which the student-facing pages never needed. Status is never carried by colour alone: every pill contains its own word, so the screens read correctly in greyscale.
- **Targets are bigger.** Table rows 60px, buttons and inputs 52px, navigation items 56px, against the 44px minimum the rest of the product uses.
- **Layout departs from the single centred card.** The panel uses a 264px left rail with a content area. The one-decision-per-screen rule is right for someone standing at a gate and wrong for someone comparing forty rows, so this is the one surface where it does not apply. Below 900px the rail becomes a horizontal strip.
- **No frosted glass anywhere.** The Photo-Earns-Glass Rule holds: there is no campus photography behind these screens, so every surface is a flat plate with a hairline. No rivet motif either, which belongs to the public face of the product.

### Named Rules

**The Plain Words Rule.** Controls say what will happen in ordinary language: "Turn off", not "Deactivate"; "Give out a code", not "Issue credential"; "Ran out of time", not "Expired". No control is icon-only, and every destructive action is confirmed in a dialog that states the consequence in a full sentence.

## Do's and Don'ts

### Do:
- **Do** keep the accent to exactly the three defined Signal intensities; never introduce a fourth orange or a second hue for interactive meaning.
- **Do** pair glass cards with real photography only; a flat page stays flat.
- **Do** use IBM Plex Mono exclusively for codes, tags, and short labels — never for prose or long text.
- **Do** keep every interactive target ≥44px, at every breakpoint — this is a phone-in-hand product.
- **Do** use the riveted-plate / directory-row motif language for anything that represents an issued credential or a menu of destinations (it's the product's signature, not a one-off).

### Don't:
- **Don't** add rounded-full "friendly" buttons, pastel colors, or illustration — the system is deliberately institutional/editorial, not a consumer SaaS aesthetic.
- **Don't** add a second accent hue (blue, green-as-accent, purple) for anything other than the two fixed semantic colors (danger/success).
- **Don't** add a persistent top nav bar or hamburger menu; the floating pill back-button is the system's only wayfinding chrome outside the walkthrough itself.
- **Don't** apply frosted glass to a flat utility page just for visual consistency — see The Photo-Earns-Glass Rule.
