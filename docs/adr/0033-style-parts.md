# 33. Components can expose named parts that take styles

Date: 2026-11-22

## Status

Proposed (depends on ADR 0027)

## Context

A style selection maps each style ID to one value, applied to the component's root element. Components with several text elements, like a hero with a heading and an introduction, could only style an inner element through a root style named after it ("Headline size"), read as a custom property. Each new element needed a new style, and one style could not take different values on two elements.

Core's Style API has no concept of parts: an option's `target` names a page-level element (`html`, `body`, a meta tag), not an element inside a component.

## Decision

1. **Components declare named parts** in `thirdPartySettings.canvas.style_parts` (SDCs) or `styleParts` (code components): each part has an ID, a label, and the styles it accepts. Root styles stay in `styles`. The root and each part take the same shape: a list of style IDs, or a map of style ID to a limit (`{folders, colors, options, custom}`), so limits apply per element. The separate `style_limits` authoring key is gone; Canvas still stores root limits apart so components without limits keep their version hash.
2. **Selection keys:** a part's value is stored under `part.style_id`. `.` is outside core's style ID pattern, so part keys never collide with root keys, and the same style can have one value on the root and others on parts.
3. **Rendering:** each part's choices resolve like the root's and reach the template as `{part}_attributes`. Every render of an SDC that declares parts gets them, empty when nothing is set, Canvas or not (a `component` element pre-render), so templates print them without checks: `<h1{{ heading_attributes.addClass('x') }}>`. Code components receive `styleParts`, each part's `className` and `style`; the prop name is reserved like `className` and `style`.
4. **Same rules as root styles:** the brand kit narrows and falls back the same way, and values a part does not accept are kept, skipped at render, and reported.
5. **Editing:** the Style tab lists the component's own styles by category, then one group per part under its label. Hovering a group outlines the part in the preview; clicking a part of the selected component in the preview opens its group. Previews mark parts with `data-canvas-style-part`.
6. **Moving values:** when a component version moves a style between the root and a part, upgrading an instance moves its value if exactly one new place accepts that style and has no value. Otherwise the Style tab offers "Move to {part}".
7. **Canvas-only:** parts sit on top of how Canvas applies styles, with no core changes. They can be proposed upstream once proven.

## Consequences

- Themes define general styles (Text size, Typeface, Line length) once and reuse them on any part, instead of one style per element.
- Component authors choose which elements are styleable; editors can't style arbitrary markup.
- Templates must print part attributes; a part the template does not print has no effect.
- Ambiguous moves (two parts accept the moved style) stay degraded until an editor resolves them.
- Headless output doesn't carry part attributes yet.

## References

- ADR 0027: core Style API in Canvas
- `ComponentStyles::partsFor()`, `ComponentStyles::acceptedKeys()`
