# 31. Per-site style options live in the brand kit

Date: 2026-11-21

## Status

Proposed (depends on ADR 0027)

## Context

Organizations reuse one component library across brands, one brand per site. Brands differ in which style options they offer, what they call them, and how they look. ADR 0027 reads one site-wide set of styles from the core Style API, which covers one brand.

Core keeps one definition per style ID, discovered from every installed module and theme. A later provider with the same ID replaces the earlier definition without an error, definitions do not depend on the active theme, and themes cannot alter them. A brand theme can therefore only change a style by copying all of it, and Acquia Source sites have no custom theme at all.

## Decision

1. **One brand per site, and the brand kit is the brand.** `canvas.brand_kit.global` gains an optional `styles` mapping. Per style, it holds offered `options`, `label` and `labels` overrides, and `fallbacks` for options the site does not offer. The brand kit already has the owner, permission, drafts, publishing, and CLI file this needs.
2. **Effective definitions.** `ComponentStyles` applies the brand kit (the draft in editor contexts) on top of core's definitions. The Style tab, rendering, headless output, `useStyleOptions()`, and CLI types all use the result.
3. **Fallbacks at render, never in storage.** A stored option the site does not offer renders as its fallback, one step at most, or is skipped and reported. Content moving between brands keeps its values.
4. **A shared vocabulary.** Style IDs and role-named option IDs (`base`, `inverse`, `highlight`) are defined once, in a design system module or base theme. Option CSS references design tokens (ADR 0029), so brands supply values.

## Consequences

- One component library serves every brand without forks, including Source sites.
- Brand-only options need a new style ID (style library) or `hook_style_alter()` in a module until the brand kit can add options.
- Several brands per site would need theme-scoped definitions in core or a Canvas-owned definition store. The `styles` shape carries over as one entry per brand.
- Data health gains findings for brand kit references, unresolved options, and style IDs defined twice. This also fixes a case where a non-default theme's override made a style disappear from Canvas.

## References

- OpenSpec change `brand-style-options` in the canvas-specs repository.
- ADR 0027, ADR 0029.
