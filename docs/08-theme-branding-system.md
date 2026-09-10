# Aziv AI — Theme & Branding System

Blueprint §5: *"Each theme uses design tokens rather than hard-coded page colors."* That single
sentence determines the entire design of this subsystem.

## 1. The problem being solved

In a typical application, colours are written into stylesheets and templates. Changing the brand
colour means finding every occurrence — and there are always some you miss, so a button somewhere
stays the old blue forever.

**Design tokens** invert this. Every colour, radius and shadow is a *named variable*. Templates
reference the name; the values live in the database and are injected at page render.

```
❌ Hard-coded:  <button class="bg-blue-600 hover:bg-blue-700">
✅ Tokenised:   <button class="bg-primary hover:bg-primary-hover">
                where --color-primary comes from the database
```

Change `color.primary` in the Admin Panel, and **every** primary button, link, focus ring, active
nav item and progress bar across the whole application changes at once — because they were never
told a colour, only a role.

## 2. How a theme reaches the browser

```
   themes + theme_tokens tables
              │
              ▼
   ThemeService::resolve()          ← cached; one lookup per request
              │
              ▼
   CSS custom properties compiled into a <style> block in the page <head>
              │
              ▼
   :root {
     --color-primary:        #2563eb;
     --color-primary-hover:  #1d4ed8;
     --color-surface:        #ffffff;
     --radius-md:            0.5rem;
     --shadow-lg:            0 10px 15px -3px rgb(0 0 0 / 0.1);
     …
   }
   :root[data-theme="dark"] {
     --color-surface:        #0f172a;   /* only what differs */
     …
   }
              │
              ▼
   Tailwind utilities are configured to read those variables,
   so `bg-surface` resolves to var(--color-surface) automatically
```

The compiled CSS block is cached per theme and per mode, so this costs one cache read per page —
not a database query per colour.

## 3. The token catalogue

Blueprint §5 names the required controls. Each becomes a token group.

### Colour roles (light and dark defined separately)

| Group | Tokens |
|---|---|
| Brand | `primary`, `primary-hover`, `primary-active`, `secondary`, `accent` |
| Surface | `background`, `surface`, `surface-raised`, `card`, `overlay` |
| Line | `border`, `border-strong`, `divider` |
| Text | `text`, `text-muted`, `text-inverse`, `heading` |
| State | `success`, `warning`, `danger`, `info` (each with `-bg` and `-text`) |
| Interactive | `link`, `link-hover`, `hover`, `active`, `focus-ring`, `disabled` |

### Component tokens (§5 requires component-level control)

`sidebar` · `navbar` · `button` (primary/secondary/ghost/danger variants) · `input` ·
`card` · `table` (header, row, stripe, hover) · `badge` · `modal` ·
**`chat-bubble-user`** · **`chat-bubble-assistant`** · **`code-block`** (background, text,
syntax accents)

### Shape and type tokens

| Group | Tokens |
|---|---|
| Radius | `radius-sm`, `radius-md`, `radius-lg`, `radius-full` |
| Shadow | `shadow-sm`, `shadow-md`, `shadow-lg`, plus a global intensity multiplier |
| Spacing | `spacing-scale` (compact / normal / relaxed) |
| Typography | font family (heading + body), base size, scale ratio, weights, line height |

Roughly **95 tokens per mode**, so about 190 values per theme. Editing them one by one would be
unusable, which is why the editor is organised by group with sensible bulk actions and a live
preview.

## 4. The eight built-in themes (§5)

| Theme | Character |
|---|---|
| **Light** | Clean neutral default |
| **Dark** | Standard dark mode |
| **Midnight** | Deep blue-black, high contrast |
| **Professional** | Restrained corporate palette |
| **Minimal** | Near-monochrome, generous whitespace |
| **Glass** | Translucent surfaces, blur, layered depth |
| **Ocean** | Blue-teal palette |
| **Custom** | Empty template for your own brand |

Built-ins ship as seed data (`is_builtin = true`). They cannot be deleted — but they **can** be
duplicated, and the duplicate is fully editable. This means you can never destroy your way back
to a broken state: a known-good theme is always available to fall back to.

## 5. Theme lifecycle

```
CREATE ──► duplicate an existing theme, or start from the Custom template
   │
   ▼
EDIT ────► colour editor, light and dark side by side, grouped by token family
   │
   ▼
PREVIEW ─► YOU see the site with the draft theme applied.
   │       Everyone else still sees the current live theme.
   │       (Session-scoped preview flag, permission-gated.)
   │
   ▼
PUBLISH ─► becomes the active theme for all visitors
   │       The previous active theme is recorded as "last known good"
   │
   ▼
RESTORE ─► one click returns to the previous theme if something looks wrong
```

**Preview before publishing is the key safety feature.** It means you can experiment with your
live production site's appearance without any customer ever seeing a half-finished theme.

## 6. Custom CSS — the one genuinely risky feature

Blueprint §5 asks for *"optional custom CSS field with strict permission and sanitization
controls."* The emphasis is warranted: arbitrary CSS can be used to hide interface elements, or
to fetch remote resources that leak information about your visitors.

Controls applied:

| Control | Detail |
|---|---|
| Permission | A dedicated `themes.custom_css` permission, granted to Super Admin only by default |
| Sanitisation | `@import`, `javascript:` URLs, `expression()`, `behavior` and remote `url()` fetches are stripped |
| Scoping | Injected inside a scoped wrapper so it cannot override admin-panel or security-critical styles |
| Length limit | Bounded, admin-configurable |
| Audit | Every change logged with a full before/after diff |
| Reversible | Cleared instantly from the panel if something breaks |

## 7. Dark mode

Three settings the admin controls (§5, §6):

1. **System** — follows the visitor's operating system preference
2. **Forced light** or **forced dark** — platform-wide
3. **User choice** — a toggle in the customer interface, remembered per account

Because light and dark are separate token sets on the same theme, switching mode swaps values
without reloading the page or re-fetching CSS.

**Accessibility check:** the colour editor computes the WCAG contrast ratio for every text/
background pairing and warns when a combination falls below 4.5:1. This is not in the blueprint,
but shipping a theme editor that lets you build an unreadable site would be a defect — a warning
costs nothing and prevents a real problem.

## 8. Branding assets (§4)

| Asset | Purpose | Handling |
|---|---|---|
| Primary logo | Main header | Original + generated sizes |
| Dark-mode logo | Header on dark backgrounds | Separate upload |
| Light-mode logo | Header on light backgrounds | Separate upload |
| Compact logo | Collapsed sidebar, mobile | Separate upload |
| Login logo | Auth screens | Separate upload |
| Email logo | Transactional email headers | Absolute URL, email-safe format |
| Favicon | Browser tab | Generated at 16/32/48px |
| App icons | Mobile home screen / PWA | Generated at 180/192/512px |
| Default avatar | Users without a photo | Single upload |
| Placeholders | Empty states | Single upload |

All flow through `media_assets` — the media library required by §4, with upload, replace,
preview and **safe deletion** (an asset currently referenced by a theme, page or setting cannot
be deleted; the panel tells you what is using it).

Text branding — company name, short name, site title, tagline, support email and phone, website
URL, social links, footer copyright, legal/company information — lives in `system_settings` under
the `branding.*` group and is available anywhere via `settings('branding.app_name')`.

## 8b. Responsive token layer (Owner Addendum A)

The token system extends across breakpoints so that a single admin change stays coherent on every
device rather than looking right only on desktop.

| Addition | Purpose |
|---|---|
| **Fluid typography** | Type scales continuously via `clamp()` between mobile and desktop instead of jumping at breakpoints |
| **Responsive spacing** | Section spacing and page gutters scale with viewport width |
| `--tap-min` | 44px minimum touch target, applied to every interactive element |
| `--safe-top` / `--safe-bottom` | `env(safe-area-inset-*)`, so nothing sits under a notch or home indicator |
| `--nav-height-mobile` | Bottom navigation height, used to offset page content |
| `--composer-height` | Live chat composer height, used to offset the message list |
| `--content-max` | Reading-width cap, so text never runs to uncomfortable line lengths on wide screens |

The admin's existing controls — spacing scale, radius, shadow intensity, typography — feed these
fluid values. Choosing "compact" spacing therefore produces a coherent compact interface at every
width, not one that only reads correctly at one size.

**PWA connection.** The `theme-color` browser meta tag and the `manifest.webmanifest` are generated
from the active theme's tokens and your branding settings. Changing your brand colour in the panel
also changes how Aziv AI appears when installed on someone's home screen. Detail in
`11-responsive-design-system.md` §10.

## 8c. The Admin Panel is fully themeable (owner decision D-07, changed)

The owner overruled my recommendation that the Admin Panel receive only logo and brand colours.
**It gets the complete practical design system**, on the same token engine as the customer
application.

### How this works with Filament

Filament 5 styles itself through CSS custom properties, so the same mechanism already designed for
the customer site drives the admin panel: an admin-scoped token set is compiled into a `<style>`
block injected via a Filament render hook, mapping Aziv's tokens onto Filament's variable names.
One engine, two token sets (`customer` and `admin`), so a change made once behaves consistently.

**One implementation detail worth naming:** Filament's colour system expects a full shade ramp
(50–950) per colour, not a single hex. So when an admin picks one primary colour, Aziv AI
**generates the ramp** from it in a perceptually uniform colour space, keeping steps evenly spaced
rather than mathematically lightened — which is what stops generated palettes looking muddy at the
extremes. Admins who want precise control can override individual steps.

### What the admin controls

Everything the owner listed: multiple themes · light / dark / system modes · brand colours ·
primary, secondary and accent · backgrounds · surfaces · text colours · borders · buttons · forms ·
cards · sidebar · navigation · chat UI · status colours · typography · font sizes and weights ·
border radius · shadows · spacing and design tokens · logo · favicon · branding · homepage visual
settings.

### Keeping ~190 tokens per panel usable

The owner's own constraint — *"keep the interface organized into categories so the Admin Panel
remains easy to use"* — is the harder half of this requirement. A flat list of 190 colour pickers
is technically complete and practically unusable.

| Measure | Effect |
|---|---|
| **Grouped editor** | Brand · Surfaces · Text · Borders · States · Components · Typography · Shape · Spacing — one group open at a time |
| **Progressive disclosure** | Setting ~8 brand colours derives sensible defaults for the rest; the remaining ~180 are there when wanted, not demanded up front |
| **Search** | Find any token by name or by the element it affects |
| **"Affects" hints** | Each token names what it changes, so nobody edits blind |
| **Live preview** | Sample components render beside the editor, updating as values change |
| **Scope switch** | Toggle between customer and admin token sets in the same editor |
| **Contrast warnings** | Every text/background pair checked as it is edited |
| **Reset per group** | Undo one group without discarding the whole theme |
| Mobile | Tabbed light/dark rather than side-by-side, per Addendum A |

### The honest boundary

The owner's word *"practical"* is doing real work here, and it is the right word.

**Token-level control is complete** — every colour, size, radius, shadow and spacing value in both
panels. **Structural change is not** — Filament's component internals (how a table header is
assembled, where a form label sits) are its own markup. Changing those means overriding Filament's
Blade views, which is possible but creates upgrade work on every Filament release.

So: **any visual property is themeable; the admin panel's layout structure stays Filament's.** If a
specific structural change matters later, it is a scoped piece of work rather than something the
theme system covers.

**Effort: +1 to +2 sessions in Phase 2.**

## 9. The rule that makes all of this work

> **No colour, radius, shadow, spacing value or font may be hard-coded in any Blade template,
> Livewire component or CSS file in the customer-facing application.**
>
> **And no component may ship without a declared mobile, tablet and desktop behaviour** — the
> adaptation matrix in `11-responsive-design-system.md` §4 is the contract.

Every one must reference a token. This is checked in the Phase 2 review and re-checked in Phase 9.
If a single template hard-codes `bg-blue-600`, that element silently stops responding to theme
changes — and it is exactly the kind of defect that is invisible during development and obvious
to a customer.

## 10. UI/UX controls beyond colour (§6)

| Control | Where stored |
|---|---|
| Sidebar menu labels, visibility, ordering | `navigation_menus` / `navigation_items` |
| Dashboard widget selection and order | `system_settings` (`ui.dashboard.*`) |
| Landing page sections | `content_pages` / `content_sections` |
| Banners and announcements | `banners` |
| Pagination defaults, table density | `system_settings` (`ui.*`) |
| Date/time display format | `system_settings` (`ui.datetime.*`) |
| Available UI languages, default locale | `system_settings` (`locale.*`) |
| Timezone, currency defaults | `system_settings` (`system.*`) |
| Maintenance mode, message, page | `system_settings` + `feature_flags` |
| Customer-facing feature visibility | `feature_flags` |
