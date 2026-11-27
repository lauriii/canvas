# 34. Style values can differ per breakpoint

Date: 2026-11-22

## Status

Proposed (depends on ADR 0027, ADR 0032, and ADR 0033). Not implemented: this records the shape so parts, tokens, and custom values don't paint it into a corner.

## Context

Framer, Webflow, and Wix let an editor set a value per breakpoint: a hero's headline is Huge on desktop and Large on phones. Canvas stores one value per selection key, and ADR 0027 reserved per-breakpoint objects with a `base` key (validation rejects them today with "not supported yet").

Parts (ADR 0033), brand kit colors (ADR 0032), and custom values all multiply with breakpoints, so the storage and rendering shape needs settling before more builds on top.

The hard part is rendering, not storage. Core's options are fixed strings: `ts-display--l` has no phone variant unless the theme writes one, and a utility framework spells it differently (`md:text-xl` in Tailwind, `text-md-xl` in Bootstrap).

## Decision

1. **Storage:** a selection value is a string (every breakpoint) or an object keyed by breakpoint, with `base` required: `{"heading.display_size": {"base": "l", "md": "xl"}}`. Values cascade upward from `base`, mobile first (min-width), as Tailwind and Webflow do. Every value form (option ID, `=` custom value, `canvas-color:` reference) is allowed per breakpoint.
2. **Breakpoints come from Canvas viewports**, not the theme's breakpoints file: they are what the editor previews. Each has an ID (`md`), a min-width, and a label. A theme can map them to its own media queries.
3. **A style says how it goes responsive**, in its Canvas extension (the same place as `tokens` and `custom`):
   - `responsive: { class: '{breakpoint}:{value}' }` for utility frameworks, where a breakpoint prefix produces a different class. Canvas emits one class per breakpoint, from the pattern.
   - `responsive: property` for styles whose options set custom properties, the Tessera pattern. Canvas emits the base class, plus `--{property}-{breakpoint}` values in the inline style, and the theme's CSS reads them in its own media queries.
   - Without `responsive`, a style takes only a `base` value. The editor shows it as not responsive.
4. **Editing:** the viewport selector in the toolbar sets the breakpoint being edited, as in Webflow. A row with a value at the current breakpoint shows it set there. A row whose value comes from a smaller breakpoint shows where it comes from. Reset clears the current breakpoint only.
5. **Resolution stays in one place:** `ComponentStyles::resolveWithDefinitions()` and its two client copies resolve per breakpoint against the shared test cases (ADR 0033 follow-up), so the server, the editor, and drupal-canvas agree.

## Consequences

- Responsive styles need theme or framework cooperation, declared once per style. Styles without it keep working, base only.
- Selections get larger. Undo, copy and paste, data health, and the CLI must treat objects as values.
- Brand kit narrowing, fallbacks, and limits apply per breakpoint value, so a fallback can apply to `md` only.

## Open questions

- Should `base` be allowed to be empty (only set from `md` up)? Webflow says no; Tailwind allows it.
- Do states (hover, focus) follow the same object shape (`{"base": "x", "hover": "y"}`), or a separate key?
- How much of this belongs in a core proposal, since core's `#styles` would need the same shape?

## References

- ADR 0027: core Style API in Canvas (the reserved `base` shape)
- ADR 0032: design tokens
- ADR 0033: named parts
