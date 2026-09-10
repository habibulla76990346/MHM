# Aziv AI — Brand Assets

Decision **D-09**, as directed by the owner: **build and retain both presentation variants, and
never alter the master.**

## The master — preserved, never modified

`aziv-ai-logo-master.jpg` · 1536 × 1536 · JPEG

The official Aziv AI artwork, extracted from page 1 of the owner's *FINAL Master Blueprint*. **This
file is the source of truth and is never edited.** Every variant below is derived from it, and any
future variant is derived from it too — never from another derivative.

## The asset set

| File | Use | Notes |
|---|---|---|
| `aziv-ai-logo-master.jpg` | **Master. Do not modify** | 1536², original artwork |
| `logo-dark-bg.png` | Dark themes, dark headers | Master artwork, trimmed to content. Native to dark surfaces |
| `logo-light-bg.png` | Light themes, white pages | Tonal inversion, desaturated to neutral grey |
| `logo-lockup-dark.png` | Email headers, tiles on light pages | Artwork preserved exactly, on a rounded dark panel |
| `mark-compact-dark.png` | Collapsed sidebar, mobile — dark | Head profile only |
| `mark-compact-light.png` | Collapsed sidebar, mobile — light | Head profile, inverted |
| `mark-simplified-interim.png` | Source for small favicons | **Interim — see the known limitation below** |
| `favicon-16/32/48.png` | Browser tab | From the simplified mark |
| `favicon-64.png` | High-DPI tab | Full artwork — holds up at this size |
| `app-icon-180/192/512.png` | Home screen, PWA | Full artwork on a dark ground |
| `app-icon-maskable-512.png` | Android adaptive icon | 20% safe padding |

## Why the light variant is a tonal inversion

The obvious approach — key out the black to transparency — **does not work here**, and this is worth
recording because it is counter-intuitive.

I initially assumed the black was a removable background. It is not. **The artwork is 72% near-black,
and a single horizontal scan crosses 25 alternating light/dark runs**: the black ribbons are
*structural elements of the design*, indistinguishable from the surround. Keying black to
transparency deletes half the artwork — the form survives but loses its depth, contrast and
silhouette weight.

Three approaches were built and compared on a real white background:

| Approach | Result |
|---|---|
| Black keyed to transparency | Degraded — thin and ghostly, depth lost. Not broken, but visibly worse |
| **Tonal inversion, desaturated** | **Chosen.** Form, depth and profile all survive; reads clearly on white and on light grey surfaces |
| Dark lockup panel | Excellent, but a heavy black block inline. Retained for email headers and tiles |

Inverting the cool silver artwork produced a warm sepia cast, so the inversion is desaturated 85%
toward neutral to keep the palette monochrome.

## Known limitation — the 16px favicon needs a designer

**Stated plainly rather than quietly shipped.**

The artwork is built from fine alternating ribbons. At 16–32px those ribbons merge into grey
regardless of how the reduction is done. Three approaches were tested at actual size — direct
downscale, a solid silhouette, and a bold-ribbon reduction — and **none produces a favicon that
reads clearly at 16px.** This is not a technique failure; highly detailed artwork simply does not
survive that reduction, which is why brands maintain a separate simplified mark.

| Size | Status |
|---|---|
| ≥ 64px | ✅ The real artwork works well |
| 32–48px | 🟡 The simplified reduction is acceptable |
| **16px** | 🔴 **Weak.** Legible as a dark shape, not as the Aziv AI mark |

`favicon-16/32/48.png` ship from `mark-simplified-interim.png` so nothing is missing. **The proper
fix is a hand-drawn simplified mark** — most likely the face profile alone, or a three-ribbon
abstraction, drawn as a vector. That is a small, well-defined design job (an hour or two for a
designer), and it can be dropped in at any time through the Admin Panel without touching anything
else.

## Rules for the build

1. **The master is never modified.** All variants derive from it.
2. **The theme system selects the variant** — `logo-dark-bg` on dark themes, `logo-light-bg` on
   light, per the active theme and the viewer's light/dark mode.
3. **Every asset is replaceable from the Admin Panel** (blueprint §4). These are the starting set,
   not a fixed identity.
4. Uploading a replacement runs the same Phase 1 upload-security pipeline as any other file —
   branding is not a weaker path.

## Note on rights

Stated once, neutrally, because it matters for something that becomes a trademark: if this artwork
originated from a third party or a stock source, confirm the licence permits commercial use **and**
use as a brand identity — the two are often licensed separately. If it was created for or by the
owner, nothing further is needed. A business check, not a technical one; it does not affect the build.
