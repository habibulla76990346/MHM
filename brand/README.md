# Aziv AI — Brand Assets

## Source asset

`aziv-ai-logo-source.jpg` — the official Aziv AI brand artwork, extracted from page 1 of the
owner's *Aziv AI FINAL Master Blueprint* (the only branding asset supplied with the project).

| Property | Value |
|---|---|
| Format | JPEG |
| Dimensions | 1536 × 1536 |
| Colour mode | RGB, monochrome palette |
| Background | Solid black `#000000`, **no transparency** |
| Subject | Stylised human head in profile, formed from flowing black-and-white ribbon shapes, with smaller related forms dispersing to the right |

**This replaces the placeholder branding proposed in decision D-09.** Per the owner's instruction it
is the initial project branding, and it remains **fully changeable later from the Admin Panel** like
any other brand asset.

## What Phase 2 must derive from it

The source is cover artwork, not a prepared brand set. Four practical constraints, and what is
done about each:

| Constraint | Consequence | Phase 2 action |
|---|---|---|
| **No transparency** — black is baked in | Cannot sit on a light surface without a black box around it | Generate a transparent-background variant. The background is uniform `#000000`, so removal is mechanical and clean |
| **Light-on-dark artwork** | The design reads as white on black; on a white page it needs handling, not just a transparent background | Produce a light-mode treatment — either a dark lockup container or a tonal inversion. A design decision to confirm with the owner |
| **Raster only, no vector** | Will soften when scaled beyond 1536px | Usable at every size the application needs. A vector redraw is worth commissioning eventually, but is not required to ship |
| **Highly detailed** | The fine ribbon structure merges into grey at 16–32px | Derive a **simplified compact mark** — the head silhouette alone — for favicon, collapsed sidebar and mobile |

## The eight assets the platform needs (blueprint §4)

All generated in Phase 2 from the source, then editable in the Admin Panel:

| Asset | Derivation |
|---|---|
| Primary logo | Source, sized for the header |
| Dark-mode logo | Source as-is — it is native to dark surfaces |
| Light-mode logo | Transparent variant with the light-mode treatment |
| Compact logo | Simplified head silhouette |
| Login logo | Source, centred |
| Email logo | Flattened on a solid ground, absolute URL, email-safe |
| Favicon | Simplified mark at 16 / 32 / 48 px |
| App icons | 180 / 192 / 512 px, plus maskable — on a dark ground, where the artwork is strongest |

## Note on rights

Stated once, neutrally, because it matters for a brand that will become a trademark: if this artwork
originated from a third party or a stock source, confirm the licence permits commercial use **and**
use as a brand identity — the two are often licensed separately. If it was created for or by the
owner, nothing further is needed. This is a business check, not a technical one, and it does not
affect the build.
