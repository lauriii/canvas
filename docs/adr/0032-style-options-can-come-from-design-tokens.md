# 32. Style options can come from design tokens

Date: 2026-11-21

## Status

Proposed (depends on ADR 0027 and ADR 0031)

## Context

Core style options are fixed strings, so a style that offers brand colors must repeat each color as an option, and a new brand kit color needs a code change. Color props already link to the brand kit: they store `canvas-color:<uuid>`, can be limited to color folders, resolve the reference at render, and are audited so a color in use is not deleted silently.

## Decision

1. **A style can declare a token source** (`type: color`, optional `folders`, a `value` class, and a custom `property`) beside its fixed options. Its effective options include one per brand kit color.
2. **Same storage as color props:** a selection stores `canvas-color:<uuid>`, which cannot collide with an option ID.
3. **Rendering through a custom property:** the class from `value`, plus `{property}: var({color variable}, {color value})` built from the Color entity. The build knows the class; brand kit changes need no CSS rebuild.
4. **Each component picks its colors:** a component's accepted styles can limit a token-backed style to color folders or specific colors, by name so component metadata stays portable. The limit intersects with the style's folders and the brand kit's narrowing, and the same shape narrows fixed options.
5. **Lifecycle shared with props:** the color audit, Find instances, delete protection, and config import validation include style usages.
6. **Declared in Canvas' format until core has one:** style libraries accept it directly; themes use `{provider}.canvas.styles.yml`, because core's schema rejects unknown keys.
7. **Core design tokens later:** `token:<path>` references are accepted beside `canvas-color:` once core's design tokens API lands (ADR 0029).

## Consequences

- New brand kit colors reach styles without code changes, and every use follows edits.
- One reference format, resolver, and audit for props and styles.
- Themes have a second, Canvas-specific declaration file until core adopts the idea.

## References

- OpenSpec change `style-design-tokens` in the canvas-specs repository.
- ADR 0027, ADR 0029, ADR 0031.
