# 27. Component styles on the core Style API

Date: 2026-11-21

## Status

Accepted (experimental: behind `canvas.settings:experimental.styles`, and
active when core provides `plugin.manager.style` or the
`canvas_style_polyfill` module is installed)

## Context

Site design owners need a safe way to let editors change how a component
looks, within options they approve. Without one, every component invents its
own enum props (`tone`, `spacing`, `align`), named differently each time,
mixed in with content, and invisible to tools.

Drupal core is adding a Style API (`#3517033`, code in `#3631482` and
`#3631504`). Themes and modules declare mutually exclusive options in
`{provider}.utilities.yml`, and core applies a selection through the `#styles`
render property. UI Styles 2.x is being rebuilt on it, and Display Builder
stores per-node selections in the same shape: a map of style ID to option ID.

## Decision

1. **Adopt the core Style API, and polyfill it until core ships it.**
   Canvas introduces no parallel format: themes write core's
   `{provider}.utilities.yml`. `ComponentStyles` reads definitions through
   `StyleSourceInterface`. `CoreStyleSource`, the only class that uses core's
   Style API types, is registered when core provides `plugin.manager.style`.
   Otherwise the separate `canvas_style_polyfill` module registers a source
   that reads the same files with core's defaults. It never runs beside core:
   once core has the API, the polyfill registers nothing and the status
   report says it can be uninstalled. Deleting it later changes nothing else
   in Canvas and no stored data. It does not polyfill `themes.yml`, modes, or
   `hook_style_alter()` for other modules, which Canvas does not use.
2. **Store a `styles` field prop per instance.** It is the eighth column of
   `component_tree`, in the synchronized `tree` column group, so every
   translation shares one look. It is added by `canvas_update_11201`, a
   `hook_update_N` rather than a post-update, because field tables are built
   from code and any entity load selects the column once the code is
   deployed. Config trees store `styles` as an optional mapping, sorted and
   omitted when empty, so exports do not change for unstyled instances. The
   value shape reserves per-breakpoint objects with a `base` key; validation
   rejects them until a later change.
3. **Which components accept styles.** Component settings `styles` holds a
   list of style IDs or `['*']`. Every component opts in: a missing value
   means none, so editors only see styles the component's author chose, and
   a theme's full utilities list never floods every component. SDCs opt in
   with `thirdPartySettings.canvas.styles`, code components with `styles` in
   `component.yml`. Blocks have no declaration yet and accept none unless a
   site builder sets the Component's settings. Settings equal to the default
   are excluded from the version hash, so enabling styles creates no new
   component versions.
4. **Validate shapes only.** Unavailable or no-longer-accepted entries are
   kept, filtered out before anything renders, reported by data health, and
   recoverable in the editor. A theme switch never blocks saves. This
   deliberately differs from UI Styles' `selected_mapping`.
5. **Rendering.** For SDCs and blocks, Canvas merges each option into
   `#attributes` itself, with core's algorithm
   (`StyleDefinition::applyOrBubbleOnArray()` for local targets), instead of
   setting core's `#styles`. Output is identical with either source, which
   the published markup of the reference design system confirms. Code
   components get React's standard `className` and `style` props, and
   read option IDs with the `useStyleOptions()` hook from `drupal-canvas`.
   It is a hook, not a prop, so a props spread can never leak it into the
   DOM. The selection travels to the island renderer in an internal prop that
   is removed before the component receives its props.
6. **Headless.** Code component elements carry `styles` and `attributes`
   (`class`, `style`) only when the client advertises the `styles` capability
   in `Canvas-Client-Capabilities`, so pinned front ends never see new keys.
   `@drupal-canvas/json-render` accepts unknown element keys before any
   Canvas release can emit them.
7. **Site style libraries.** `canvas.style_library.*` config entities hold
   styles in core's utilities format for sites without a theme (for example
   Acquia Source). They are registered with core through `hook_style_alter()`
   (a deriver cannot be used: derivative IDs contain `:`), published on save,
   and treated as untrusted: options may only set `class`, `style`, `data-*`,
   or `aria-*`, and `library` is rejected. Extensions win collisions.

## Consequences

- Canvas shares one design vocabulary with core, UI Styles, and Display
  Builder; UI Suite themes work in Canvas unchanged.
- The endpoint (`/canvas/api/v0/styles`), the headless keys, and the CLI file
  format are experimental until core commits the Style API; expect one
  rework of `ComponentStyles` if core changes.
- PHP and JavaScript resolve selections identically; shared fixtures in
  `packages/drupal-canvas/src/styles.fixtures.json` keep them from drifting.
- Component-specific variants stay enum props: style IDs are global and
  options cannot be narrowed per component.
- A localized look needs asymmetric translation, which is out of scope.

## References

- OpenSpec change `core-style-api` in the canvas-specs repository.
- `\Drupal\canvas\Style\ComponentStyles`
- `packages/drupal-canvas/src/styles.tsx`
