# Icon libraries

Canvas sources icons from Drupal core's Icon API: any icon pack discovered by `plugin.manager.icon_pack` — defined in
a module's or theme's `*.icons.yml` file, or pushed with the Canvas CLI — is available to Canvas components. Canvas
introduces no parallel icon storage format. See
[ADR 0017](adr/0017-icon-libraries-on-core-icon-api.md) for the decision record.

## The `icon` prop type

Code components can declare a prop of type `icon`. The stored value is the core Icon API's full icon id
(`pack_id:icon_id`), held in a plain string field. The prop's JSON schema is:

```json
{
  "type": "string",
  "$ref": "json-schema-definitions://canvas.module/icon"
}
```

When the component author scopes the prop to one or more installed icon packs, a generated `pattern` anchored to the
allowed pack ids is added:

```json
{
  "type": "string",
  "$ref": "json-schema-definitions://canvas.module/icon",
  "pattern": "^(phosphor|heroicons):.+$"
}
```

Server-side JSON Schema validation enforces the scope: a value referencing an icon from a pack that is not allowed
for the prop is rejected. See `\Drupal\canvas\Icon\IconPropShape`.

The shape maps to a plain string field with the `canvas_icon` widget
(`\Drupal\canvas\Plugin\Field\FieldWidget\IconWidget`), which the Canvas editor renders as a visual icon picker: a
searchable grid scoped to the packs allowed for the prop. Outside the Canvas editor the widget degrades to a text
input holding the icon id.

## Rendering contract for code components

At preview and render time, Canvas resolves the stored icon id through the pack's extractor
(`\Drupal\canvas\Icon\IconResolver`) and delivers the component a renderable value instead of the raw id:

- `{ id, svg }` — inline SVG markup, for packs using the `svg` and `svg_sprite` extractors. Inline markup inherits
  `currentColor`, so icons follow the surrounding text color.
- `{ id, url }` — an asset URL, for packs using the `path` extractor.

A component renders it directly, without managing SVG sources:

```jsx
const MyComponent = ({ icon }) => (
  <div>{icon?.svg && <span dangerouslySetInnerHTML={{ __html: icon.svg }} />}</div>
);
```

A stored id whose pack or icon no longer exists resolves to `null` and logs a warning; the component renders nothing
for it.

## Listing endpoint

`GET /canvas/api/v0/icons` returns every installed pack and its icons (id, name, label, and an inline `svg` preview
or asset `url`), with cacheable responses. The icon picker and the Brand Kit "Icon libraries" section both consume
it; search happens client-side.

## Canvas-managed icon libraries (CLI push)

Core only discovers packs from installed extensions, so the CLI push target is the `icon_library` config entity: id,
label, optional description and Twig template, and references to SVG assets uploaded to
`public://canvas/icons/<library>/`. Canvas registers each library with the core Icon API in
`hook_icon_pack_alter()` (`\Drupal\canvas\Hook\IconPackHooks`), so downstream consumers see no difference from
module-provided packs.

Uploaded SVGs pass through `\Drupal\canvas\Icon\SvgSanitizer`, which rejects scripts, event handler attributes, and
external references — this is a trust boundary. Managing icon libraries requires the `administer icon libraries`
permission (OAuth scope `canvas:icon_library`).

Icon libraries are part of the CLI's brand kit workflow and mirror the fonts DX: the same `--include-brand-kit` flag
governs both, and libraries are declared in `canvas.brand-kit.json` under `icons.libraries` (mirroring
`fonts.families`) — each entry naming an id and, optionally, a label, description, Twig template, and a `source`
directory such as `node_modules/lucide-static/icons` so npm-managed icon sets stay out of the repository. A declared
list is authoritative like the fonts list: pushing removes canvas-managed libraries that are no longer in it. As a
zero-config shortcut, any `icons/<library>/` directory of SVG files also pushes without a declaration. Pushes are
incremental: each asset's SHA-256 is stored on the library, so only new or changed files are uploaded. For the
project layout and details, see the [`@drupal-canvas/cli` README](../packages/cli/README.md).
