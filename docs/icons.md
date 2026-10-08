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

Props opted in through contrib picker modules' own markers (for example `x-canvas-icon`) are not icon props to Canvas
and keep working through those modules; a prop carrying both that marker and the icon `$ref` gets Canvas's field type
and picker.

## Restricting the packs a site offers

The `icons.allowed_packs` list in the `canvas.settings` config restricts which installed packs the Canvas editor
offers to content authors — the icon picker, the Brand Kit "Icon libraries" section, and the code editor. An empty
list (the default) offers every installed pack. A prop scoped to a pack outside the allow-list offers no icons: the
intersection fails closed.

This is authoring policy, not validation. Already-stored values keep rendering, and the per-prop `pattern` remains
the validation boundary. Sync clients (the Canvas CLI) request the listing endpoint with `scope=all` and are
unaffected.

## Rendering contract

Each component source renders an icon the idiomatic way for its technology; the shared
`\Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::resolveIconProps()` routes to the
right form at preview and render time (detecting icon props by the `canvas_icon` field type).

**Single-Directory Components** render through Twig, so each stored id is handed to core's Icon API render element
(`#type => icon`), which resolves and renders it via the pack's own template. A stored id whose pack or icon no longer
exists renders nothing — the failure mode core accepts for its own icon element.

**Code components** run on published pages without Canvas's editor infrastructure, so the id is resolved server-side
(`\Drupal\canvas\Icon\IconResolver`) into a value serialized into the component's props:

- `{ id, svg }` — inline SVG markup, for packs using the `svg` and `svg_sprite` extractors. Inline markup inherits
  `currentColor`, so icons follow the surrounding text color.
- `{ id, url }` — an asset URL, for packs using the `path` extractor.

Component authors render it with the `Icon` component from the `drupal-canvas` runtime package (like `FormattedText`
for rich text), without managing SVG sources:

```jsx
import { Icon } from 'drupal-canvas';

const MyComponent = ({ icon }) => (
  <div><Icon icon={icon} /></div>
);
```

`Icon` renders inline SVG or an `<img>` as appropriate, and nothing when the icon is unset or its pack/icon no longer
exists (an unresolvable id logs a warning).

## Listing endpoint

`GET /canvas/api/v0/icons` returns the offered packs and their icons (id, name, label, and an inline `svg` preview
or asset `url`), with cacheable responses. The icon picker and the Brand Kit "Icon libraries" section both consume
it; search happens client-side. The response honors the `icons.allowed_packs` allow-list unless the caller passes
`scope=all` (see above).

## Canvas-managed icon libraries (CLI push)

Core only discovers packs from installed extensions, so the CLI push target is the `icon_library` config entity: id,
label, optional description and Twig template, and references to SVG assets uploaded to
`public://canvas/icons/<library>/`. Canvas registers each library with the core Icon API in
`hook_icon_pack_alter()` (`\Drupal\canvas\Hook\IconPackHooks`), so downstream consumers see no difference from
module-provided packs.

Uploaded SVGs pass through `\Drupal\canvas\Icon\SvgSanitizer`, which rejects scripts, event handler attributes,
external references, and `<style>` elements — this is a trust boundary. Managing icon libraries requires the
`administer brand kit` permission (OAuth scope `canvas:brand_kit`).

`<style>` elements are refused because a resolved icon is inlined into the page and CSS in an inline SVG is not
scoped to it, so an icon's stylesheet would style the whole document. Icon sets exported with a stylesheet (for
example `.cls-1 { fill: … }`) need those declarations moved into presentation attributes on the SVG elements; a
`style` attribute, which only affects its own element, stays allowed.

Icon libraries are part of the CLI's brand kit workflow and mirror the fonts DX: the same `--include-brand-kit` flag
governs both, and libraries are declared in `canvas.brand-kit.json` under `icons.libraries` (mirroring
`fonts.families`) — each entry naming an id, a required human-readable `label`, and optionally a description, Twig template, and a `source`
directory such as `node_modules/lucide-static/icons` so npm-managed icon sets stay out of the repository. A declared
list is authoritative like the fonts list: pushing removes canvas-managed libraries that are no longer in it. Every
library must be declared with at least a human-readable `label` (mirroring how fonts require `name`); undeclared
`icons/<library>/` directories are not pushed. Pushes are incremental: each asset's SHA-256 is stored on the
library, so only new or changed files are uploaded. For the project layout and details, see the
[`@drupal-canvas/cli` README](../packages/cli/README.md).
