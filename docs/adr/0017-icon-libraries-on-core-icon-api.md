# 17. Icon libraries built on the core Icon API

Date: 2026-07-18

## Status

Accepted

## Context

Canvas had no first-class icon support: code component authors pasted raw SVG markup into JSX, and content creators
had no way to browse or swap icons. Drupal core ships a stable Icon API: icon packs defined in `*.icons.yml` files of
modules and themes, extractor plugins (`path`, `svg`, `svg_sprite`), the `plugin.manager.icon_pack` service, an
`icon()` Twig function, and an `#type: icon` render element. The `ui_icons` contrib module — the origin of that core
API — additionally offers Form API autocomplete widgets, a field type, and further extractors (font-based packs,
remote packs).

Canvas needs icons in four places: a prop type in the code editor, a visual picker widget in the editor sidebar, a
read-only Brand Kit listing, and CLI push/pull of icon libraries. Canvas widgets are React components wired into the
Redux-integrated Twig-to-JSX pipeline, and the target picker UX is a visual grid, not autocomplete.

Two properties of the core API constrain the design:

- Core discovers icon packs from installed extensions' `*.icons.yml` files only, so a runtime push from the CLI
  cannot create a pack by shipping a module.
- `IconPackManager` supports `hook_icon_pack_alter()`, and its plugin ids must match `[a-z0-9_]+`. Plugin derivers
  are not usable for packs: derivative ids embed a colon, which is both rejected by the manager's id validation and
  colliding with the `pack_id:icon_id` separator of full icon ids.

## Decision

**Build on the core Icon API; do not depend on `ui_icons`.** Any icon pack discovered by `plugin.manager.icon_pack`
is available to Canvas without further configuration. Canvas introduces no parallel icon storage format and builds
only the thin Canvas-specific layer: the prop shape, the picker widget, one listing endpoint, the Brand Kit section,
and CLI sync. `ui_icons` would add a dependency while still requiring the React picker to be built; its extra
extractors can be installed independently and work through the same `plugin.manager.icon_pack` surface.

**An icon prop stores the core full icon id (`pack_id:icon_id`) as a plain string.** Scoping a prop to a subset of
the installed packs is expressed in the prop's JSON schema as a generated `pattern` anchored to the allowed pack ids
(for example `^(phosphor|heroicons):.+$`), so the existing JSON Schema validation enforces the scope server-side with
no custom keyword. Alternatives rejected: an object shape `{pack, icon}` (heavier storage and field mapping for no
gain); a custom `x-icon-packs` keyword (requires a validator extension); an enum of every icon id (packs have
thousands of icons, which bloats config). At preview and render time the stored id is resolved through the pack's
extractor into a renderable value — inline SVG markup or an asset URL — so component authors never embed or manage
SVG sources by hand. A stored id whose pack or icon no longer exists resolves to nothing plus a logged warning, the
same failure mode core accepts for its own icon render element.

**Icons are a shared field type, not a code-component feature.** An icon-shaped prop maps to a dedicated `canvas_icon`
field type (extending core's `string`) paired with the icon picker widget, registered in the shared prop-shape layer
that every JSON-Schema-prop component source uses. Render-time resolution of the stored id lives in the shared
component-source base and is keyed off that field type, so Single-Directory Components and code components alike get
both the picker widget when binding the prop and id-to-SVG resolution when rendering — nothing about icons is specific
to code components. Each source renders the icon the idiomatic way for its technology: SDCs hand the id to core's Icon
API render element (`#type => icon`), which resolves and renders it through the pack's own Twig template — a render
array, which also satisfies core's SDC prop validation (it dismisses type errors for render-array prop values). Code
components run on published pages without Canvas's editor infrastructure, so they cannot resolve the icon client-side;
the id is resolved server-side into the plain `{id, svg|url}` value their runtime `<Icon>` component renders (modeled
on core's icon rendering, and analogous to how `FormattedText` renders server-processed HTML). Validation and storage
are unaffected: the evaluated prop value stays the `pack_id:icon_id` string, so save-time schema validation still sees
a string; only the render-time value is resolved.
Rejected: keeping resolution inside the code-component source (limits icons to code components, and would duplicate
the logic the moment an SDC needed an icon prop); a computed field property that resolves at evaluation time (would
feed the resolved object to schema validation, which expects the stored string).

**CLI push creates Canvas-managed icon libraries as config entities registered through `hook_icon_pack_alter()`.**
An `IconLibrary` config entity holds the library's id, label, template, and references to uploaded SVG asset files
stored under `public://canvas/icons/<library>/`. Canvas implements `hook_icon_pack_alter()` to append one complete
pack definition per entity — sources pointing at the library's public files directory, icons discovered eagerly
through the `svg` extractor, caches invalidated on entity and asset changes — so downstream consumers (picker,
rendering, Twig) see no difference from module-provided packs. A deriver was rejected for the id constraints
described above; the alter hook is the supported extension point that keeps pack ids colon-free. Uploaded SVG assets
are sanitized at the trust boundary: files containing scripts, event handler attributes, or external references are
rejected before anything is stored.

## Consequences

- Module- and theme-provided icon packs work in Canvas with zero Canvas-specific configuration, and improvements to
  the core Icon API (new extractors, caching) accrue to Canvas for free.
- Pack scoping rides on plain JSON Schema, so any spec-compliant validator — server-side or client-side — enforces
  it without Canvas-specific extensions.
- Canvas-managed libraries are ordinary config entities: deployable via configuration management, protected by their
  own permission and OAuth scope, and their assets are ordinary managed files with usage tracking.
- Because config-defined packs are registered in an alter hook that runs after core's own pack processing, Canvas is
  responsible for producing complete definitions (including eager icon discovery) and for invalidating the icon
  plugin and collector caches when libraries or their assets change.
- The `svg` extractor's eager per-icon file reads make the first listing after a cache clear proportionally expensive
  for very large packs; responses are cacheable and subsequent requests are served from cache.
