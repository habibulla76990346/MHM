# Aziv AI — Responsive & Device-Adaptive Design System

**Status: OWNER ADDENDUM A** — added to the requirements baseline after the original blueprint,
at the owner's instruction. This is a **binding cross-cutting requirement**, not a phase. It
applies to every user-facing page, every form, every modal, every dashboard, every chat screen
and the complete Admin Panel.

The governing sentence is the owner's own:

> *"Do not simply shrink the desktop UI."*

Everything below exists to make that enforceable rather than aspirational.

---

## 1. Responsive vs. adaptive — the distinction this document turns on

These are different things, and only the second is what was asked for.

| | **Responsive** (not sufficient) | **Adaptive** (the requirement) |
|---|---|---|
| Layout | Same layout, narrower | Layout **re-composed** per breakpoint |
| Navigation | Sidebar squeezed or hidden | Sidebar on desktop → **bottom nav + drawer** on mobile |
| Modals | Centered dialog, smaller | Centered dialog → **bottom sheet** |
| Tables | Table with a scrollbar | Table → **stacked cards** with an actions menu |
| Forms | Same fields, narrower | Single column, larger targets, **sticky save bar** |
| Result | A desktop site on a phone | Something that feels built for the phone |

A shrunk desktop UI is the failure mode this addendum exists to prevent. The test gates in §11
are written to catch it mechanically.

---

## 2. Breakpoint system

Six breakpoints, three device classes. Every component declares its behaviour at each class.

| Token | Range | Class | Primary target |
|---|---|---|---|
| `xs` | 0–479 | **Mobile** | Phone portrait |
| `sm` | 480–767 | **Mobile** | Large phone, phone landscape |
| `md` | 768–1023 | **Tablet** | Tablet portrait |
| `lg` | 1024–1279 | **Desktop** | Tablet landscape, small laptop |
| `xl` | 1280–1535 | **Desktop** | Standard desktop |
| `2xl` | 1536+ | **Desktop** | Wide desktop |

**The two structural switches** — the points where layout is re-composed rather than resized:

- **768px** — mobile ↔ tablet. Navigation model changes. Modals become sheets. Tables become cards.
- **1024px** — tablet ↔ desktop. Persistent sidebar appears. Multi-column workspaces unlock.

### Container queries, not just viewport queries

A card in a narrow sidebar and the same card in a wide main column should lay out differently
even though the viewport is identical. Components therefore adapt to **their container's** width
via CSS container queries, with viewport breakpoints reserved for page-level structure. This is
what keeps a single component correct in every context instead of needing a variant per location.

---

## 3. Navigation architecture

The single most visible difference between "a website on a phone" and "an app on a phone".

### Customer application

| | Mobile (`<768`) | Tablet (`768–1023`) | Desktop (`≥1024`) |
|---|---|---|---|
| Primary nav | **Bottom navigation bar** — 4 destinations + More | Collapsible **icon rail** | **Persistent sidebar**, labelled |
| Secondary nav | **Drawer** from the left | Drawer | In-sidebar sections |
| Chat history | **Bottom sheet** or full-screen drawer | Slide-over panel | **Third column**, always visible |
| Account menu | Sheet from bottom | Dropdown | Dropdown |
| Page actions | **Sticky bottom action bar** | Inline toolbar | Inline toolbar |

Bottom navigation carries: **Chat · Library · Images · Account**, with overflow behind *More*.
It sits above the safe-area inset, hides on scroll-down and returns on scroll-up, and is never
shown at the same time as the chat composer's send row (see §5).

### Admin panel

| | Mobile | Tablet | Desktop |
|---|---|---|---|
| Nav | Drawer + sticky top bar | Icon rail | Persistent sidebar with groups |
| Records | **Card list** | Reduced-column table | Full table + filter bar |
| Filters | **Filter sheet** with applied-count badge | Collapsible panel | Inline filter row |
| Row actions | Overflow menu → sheet | Overflow menu | Inline buttons |
| Bulk actions | Selection mode + sticky bar | Sticky bar | Toolbar |
| Forms | Single column + sticky save | Single column | Two columns + side panel |

---

## 4. Component adaptation matrix

Every recurring component declares its three behaviours. This table is the contract; a component
that has not declared its mobile behaviour is not finished.

| Component | Mobile | Tablet | Desktop |
|---|---|---|---|
| **Data table** | Stacked cards: title, 2–3 key fields, status pill, overflow menu | 4–5 priority columns | Full table, sortable, sticky header |
| **Modal** | **Bottom sheet**, drag handle, snap points, swipe-to-dismiss | Bottom sheet or dialog | Centered dialog |
| **Form** | One column, full-width, sticky save bar | One column, wider | Two columns, grouped |
| **Select / dropdown** | **Native or full-screen sheet picker** with search | Sheet | Inline dropdown |
| **Date picker** | Native input or full-screen picker | Popover | Popover calendar |
| **Tabs** | Horizontally scrollable, snap, edge fade | Scrollable | Full row |
| **Dashboard** | Single column, priority order | 2 columns | 3–4 column grid |
| **Chart** | Simplified: fewer ticks, no legend overlay, tap for value | Standard | Full with legend + hover |
| **Toast** | Top, below safe area, full width | Top-right | Bottom-right |
| **Validation error** | Inline below field + **scroll-to-first-error on submit** | Inline | Inline |
| **Confirm dialog** | Sheet with stacked full-width buttons | Dialog | Dialog |
| **Pagination** | Load-more or infinite scroll | Numbered | Numbered + per-page |
| **Search** | Full-screen overlay on focus | Expanding field | Inline field |
| **Sidebar filters** | Filter sheet | Collapsible drawer | Persistent panel |

---

## 5. The mobile chat screen

This is the hardest surface in the product and the one most responsible for whether Aziv AI feels
like a modern AI app. It gets its own specification.

### Layout

```
┌──────────────────────────────┐
│ ▤  Conversation title     ⋯  │  ← sticky top bar, 56px, safe-area top
├──────────────────────────────┤
│                              │
│   message list               │  ← the only scrolling region
│   overscroll-behavior:contain│
│                              │
├──────────────────────────────┤
│ ＋ │ Message Aziv AI…  │  ↑  │  ← composer, sticky, safe-area bottom
└──────────────────────────────┘
```

### Requirements, each with the failure it prevents

| Requirement | Prevents |
|---|---|
| Use `100dvh` / `100svh`, never `100vh` | Mobile browser chrome cutting off the composer |
| Track `window.visualViewport` and lift the composer above the keyboard | The keyboard covering the input the user is typing into |
| `padding-bottom: env(safe-area-inset-bottom)` | The iPhone home indicator overlapping the send button |
| **Composer font-size ≥ 16px** | iOS Safari auto-zooming the whole page on focus |
| Auto-growing textarea, max ~5 lines then internal scroll | The composer eating the conversation |
| `enterkeyhint="send"`; Enter inserts a newline, send is an explicit button | Sending half-written messages by accident |
| **Auto-scroll only when the user is already at the bottom** | Yanking the view away while someone reads earlier messages |
| `overscroll-behavior: contain` on the message list | Scroll chaining into the page and pull-to-refresh during a stream |
| Stop-generation reachable by thumb during streaming | A runaway response the user cannot stop |
| Bottom navigation hides while the composer is focused | Two competing bars stacked at the bottom |
| Long code blocks scroll **inside their own container** | The page scrolling sideways |
| Attachment picker as a bottom sheet | A desktop file dialog on a phone |

### Streaming and scroll position

While a response streams, the message list grows continuously. The rule:

```
distanceFromBottom < 80px  →  follow the stream
otherwise                  →  hold position, show a "jump to latest" pill
```

Without this, a user scrolling back to read something gets dragged to the bottom on every token.

---

## 6. Touch, input and accessibility

| Rule | Value | Reason |
|---|---|---|
| Minimum touch target | **44 × 44 px** | Below this, taps miss — Apple HIG / WCAG 2.5.5 |
| Minimum gap between targets | 8 px | Prevents mis-taps on adjacent controls |
| Minimum body text | 16 px on mobile | Readability, and prevents iOS focus-zoom on inputs |
| `inputmode` on every numeric/email/tel field | — | Surfaces the right keyboard |
| `autocomplete` on every identity/payment field | — | Fewer keystrokes, better completion rates |
| `enterkeyhint` on every text input | — | The Enter key says what it does |
| Visible focus ring on every interactive element | — | Keyboard and switch-control users |
| `prefers-reduced-motion` respected | — | Sheets and transitions become instant |
| Contrast ≥ 4.5:1 | — | Already enforced by the theme editor's contrast checker |
| No hover-only interactions | — | Touch devices have no hover; every hover action has a tap equivalent |

The last row matters more than it looks: a row-actions menu that only appears on hover is
invisible and unreachable on a phone. Every such pattern needs a persistent affordance.

---

## 7. Responsive design tokens

The theme system in `08-theme-branding-system.md` gains a responsive layer. These are tokens, so
they stay admin-controllable and consistent across breakpoints — the owner's requirement to
*"keep the design system consistent across all breakpoints."*

### Fluid typography

Type scales continuously between mobile and desktop rather than jumping at breakpoints:

```css
--text-base: clamp(1rem, 0.95rem + 0.25vw, 1.0625rem);
--text-lg:   clamp(1.125rem, 1.05rem + 0.4vw, 1.25rem);
--text-2xl:  clamp(1.5rem, 1.3rem + 1vw, 2rem);
```

### Responsive spacing

```css
--space-section: clamp(2rem, 5vw, 4rem);   /* between major sections */
--space-gutter:  clamp(1rem, 4vw, 2rem);   /* page side padding */
```

### New device tokens

| Token | Purpose |
|---|---|
| `--tap-min` | 44px minimum touch target |
| `--safe-top` / `--safe-bottom` | `env(safe-area-inset-*)` |
| `--nav-height-mobile` | Bottom navigation height |
| `--composer-height` | Live composer height, used to offset the message list |
| `--sheet-radius` | Bottom-sheet top corner radius |
| `--content-max` | Reading-width cap on wide screens |

The admin's existing controls — spacing scale (compact/normal/relaxed), radius, shadow intensity,
typography — feed these fluid values, so a single admin change stays coherent across all
breakpoints instead of only looking right on one.

---

## 8. Layout implementation rules

Rules that make horizontal overflow structurally impossible rather than a bug to be found later:

1. **Side gutter set once**, on one page wrapper, using `padding-inline` — never a `padding`
   shorthand that zeroes the sides at some breakpoint.
2. **Flex/grid with `gap`** for sibling spacing. No per-element margins that collapse or double.
3. **Nothing gets a `min-width` wider than the smallest supported viewport** (320px).
4. `max-width: 100%` on every image, video, embed and `aspect-ratio` box.
5. Only **tables, code blocks and diagrams** may exceed the viewport — each inside its own
   `overflow-x: auto` container, so the container scrolls and the page never does.
6. Long unbroken strings (API keys, URLs, model identifiers) get `overflow-wrap: anywhere`.
7. Grid columns use `minmax(0, 1fr)`, never `1fr` alone — `1fr` refuses to shrink below its
   content and is the single most common cause of grid overflow.
8. Reading content capped at `--content-max` so text never runs to uncomfortable line lengths on
   wide screens.

---

## 9. Admin Panel on mobile

The owner's requirement: *"Admin Panel must also be fully mobile responsive."*

Filament 5 is responsive out of the box, but responsive is not the same as adaptive (§1). The
work required per admin resource:

| Work item | Applies to |
|---|---|
| Define mobile card layout: which 2–3 fields appear | Every table resource (~40) |
| Move row actions into an overflow menu | Every table resource |
| Filters into a sheet with an applied-count badge | Resources with filters |
| Forms to single column with sticky save bar | Every form (~50) |
| Charts simplified for narrow widths | Analytics dashboards |
| Bulk selection mode with sticky action bar | Resources with bulk actions |

### Where I will be honest rather than optimistic

Three admin surfaces are genuinely desktop-first tasks, and pretending otherwise would produce a
worse result than adapting them deliberately:

| Surface | Why | What mobile gets instead |
|---|---|---|
| **Theme colour editor** — ~95 tokens × light/dark side by side | The side-by-side comparison *is* the feature | **Tabbed** light/dark, one group at a time, with live preview. Fully usable; comparison is easier on a large screen |
| **Cost/margin analytics** — many columns, cross-filtered | Dense multi-dimensional data | Summary cards + one chart at a time + drill-down. The full grid stays desktop |
| **Custom provider mapping builder** — request/response JSON templates | Editing structured JSON on a phone keyboard | Read and test on mobile; **editing is desktop-first**, clearly signposted rather than silently broken |

Every admin screen will be **usable** on a phone — navigable, readable, no overflow, no unreachable
controls, and every record viewable and editable. For these three, the *comfortable* experience is
on a larger screen, and the panel will say so rather than presenting a cramped grid and letting an
admin discover the problem mid-task.

---

## 10. PWA readiness

The instruction is to **structure for PWA**, not to build a native app now.

### Shipping as part of this plan

| Item | Detail | Phase |
|---|---|---|
| `manifest.webmanifest` | **Generated from admin branding settings** — app name, short name, description, theme colour from the active theme, icons from the media library | 2 |
| Icons | 180 / 192 / 512 px + maskable — already required by blueprint §4 "app icons" | 2 |
| `theme-color` meta | Follows the active theme, including dark mode | 2 |
| `apple-touch-icon`, status bar meta | iOS home-screen behaviour | 2 |
| Correct viewport meta | Including `viewport-fit=cover` for safe areas | 0 |
| `display: standalone` | Launches without browser chrome once installed | 2 |
| Versioned, hashed build assets | Prerequisite for any future cache strategy | 0 |
| Install prompt handling | Admin-controlled toggle for whether to prompt | 2 |

**The manifest being generated from admin settings is deliberate.** It means changing your logo
or brand colour in the panel also updates how Aziv AI appears when installed on someone's home
screen — consistent with the blueprint's core principle that branding is admin-controlled.

### Deliberately deferred to Phase 9 or beyond

| Item | Why |
|---|---|
| Service worker | See the honest note below |
| Offline mode | An AI platform is inherently online |
| Background sync | Needs a service worker first |
| Push notifications | Blueprint §22 already lists push as future-ready |

**Honest note on offline support.** A service worker for an AI chat product is narrower in value
than it first appears: you cannot cache AI responses (they are generated per request), and caching
authenticated data creates real privacy and staleness risks. What a service worker *can* usefully
do here is cache the application shell — logo, CSS, JavaScript, fonts — so the app opens instantly
and shows a proper offline screen instead of a browser error. That is worth doing, and it is
scheduled for Phase 9 when the asset set has stopped changing. Adding it earlier means fighting
cache invalidation for the entire build.

---

## 11. Testing — mechanical, not visual inspection

Chromium and Playwright are confirmed available on the build machine, so the owner's requirement
to *"test important screens at mobile, tablet and desktop widths"* becomes an **automated gate
that runs every phase**, not a manual promise.

### Viewports tested

| Width × Height | Represents |
|---|---|
| 320 × 568 | Smallest supported phone |
| 375 × 812 | iPhone class |
| 390 × 844 | Modern phone |
| 768 × 1024 | Tablet portrait |
| 1024 × 768 | Tablet landscape |
| 1440 × 900 | Desktop |

### Automated assertions on every screen

| Assertion | Catches |
|---|---|
| `documentElement.scrollWidth <= clientWidth` | **Horizontal overflow — the explicit requirement** |
| Every interactive element ≥ 44 × 44 px | Untappable controls |
| No rendered text below 12px | Unreadable copy |
| No element clipped outside its container | Cut-off content |
| Primary action reachable without scrolling | Buried submit buttons |
| Composer visible with a simulated keyboard inset | The single worst mobile chat failure |
| Focus ring visible on tab traversal | Keyboard accessibility |
| Contrast ≥ 4.5:1 on sampled text | Theme regressions |

A screen that fails any assertion at any viewport **fails the phase's test gate.** This turns
"no horizontal overflow" from an intention into a build-breaking condition.

### Screens covered

Home, pricing, register, login, dashboard, chat (empty / active / streaming), conversation list,
image generation, file upload, knowledge base, account, billing, checkout — plus admin: login,
dashboard, a representative table, a representative form, theme editor, provider config, analytics.

---

## 12. Effort impact — stated plainly

This addendum is not free, and the honest number matters more than an encouraging one.

Adaptive layouts mean components carry two or three genuine layouts rather than one flexible one.
The extra work is concentrated where interaction patterns actually change.

| Phase | Was | Now | What was added |
|---|---|---|---|
| 0 — Preparation | 1 | **1** | Breakpoints, viewport meta, hashed assets. Absorbed |
| 1 — Foundation | 2–3 | **3–4** | Responsive token layer, app shell with three nav models, Playwright harness |
| 2 — Admin, branding, themes | 3–4 | **4–6** | Admin mobile adaptation across ~40 resources; theme editor tabbed mode; PWA manifest |
| 3 — AI gateway | 3–4 | **3–4** | Inherits Phase 2 patterns. Absorbed |
| 4 — Chat | 3–4 | **4–6** | Mobile chat: keyboard handling, composer, scroll anchoring, sheets |
| 5 — Routing & cost | 2–3 | **2–3** | Absorbed |
| 6 — Billing | 3–4 | **4–5** | Mobile checkout, plan comparison on narrow screens |
| 7 — More providers | 2–3 | **2–3** | Absorbed |
| 8 — Files, image, voice | 4–5 | **5–6** | Mobile upload, camera capture, gallery, voice recorder |
| 9 — Hardening | 3–4 | **4–5** | Service worker, cross-device QA, real-device testing |
| **Total** | **26–35** | **31–43** | **+5 to +8 sessions** |

Revised milestones: working AI chat at **~15–21 sessions**; revenue-generating at **~22–29**.

**Why this is worth paying.** Most AI platform traffic is mobile. A desktop-only-feeling product
loses those users before they reach a pricing page, and retrofitting adaptive layouts after the
fact costs several times more than building them in — every component would need reopening, and
the design system would fracture along the way. Requiring this now, before any code exists, is
the cheapest moment it will ever be available.
