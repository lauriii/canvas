# Page variants

A page variant is a named, theme-independent, full-page component tree. It
renders the whole page: the route's main content is injected where a "Page
content" marker is placed in the tree. Pages and content templates select which
variant renders them; anything without a selection uses the site default
(`canvas.settings:default_page_variant`).

The UI calls page variants "page templates"; machine names, config, and the
HTTP API keep `page_variant`.

Page variants replace the previous "global regions" model (one `PageRegion`
config entity per theme region). See ADR 0019.

## Editing

Variants are managed and edited entirely in the editor:

- The "Page templates" section of the Templates panel lists all variants and
  supports create, rename, duplicate, enable/disable, delete, and
  set-as-default. Clicking a
  variant opens its tree in the editor at `/canvas/editor/page_variant/{id}`,
  served by the same layout API as other entities
  (`/canvas/api/v0/layout/page_variant/{id}`).
- In the variant editor, the "Page content" marker renders as a visible
  placeholder. It can be repositioned but never deleted, duplicated, or copied;
  empty slots render as labeled drop targets. Edits auto-save and publish
  through the same review flow as all Canvas changes. Empty regions render only
  here: live pages and the page editor skip them, like core block layout.
- While editing a page, the resolved variant renders the chrome read-only. The
  Layers tree nests the page's content under the variant's layer, and both that
  layer and the topbar chip jump to editing the variant.

## Selecting

- Pages select their variant in the collapsed "Page template" section of the
  "Page data" form (the `page_variant` base field is an options list of the
  existing variants; empty means the site default, shown as "Site default").
  The section also links to editing the currently resolved variant.
- Content templates select theirs from the "Page template" submenu in the
  Templates panel, backed by `pageVariant` on the content template config HTTP
  API.

## Concepts

- **`page_variant` config entity** (`\Drupal\canvas\Entity\PageVariant`): id,
  label, description, and a component tree. Deployed through configuration
  management like any Canvas config entity. Has no `theme` dependency, so a
  variant survives a theme switch.
- **The "Page content" marker**: a component of the dedicated `marker`
  ComponentSource (`marker.page_content`). It is intrinsic to every variant,
  never listed in the component library, and cannot be deleted (only
  repositioned). A variant must contain exactly one; validation enforces this.
  At render time the marker is replaced with the route's main content.
- **Rendering**: when a variant resolves for a request (entity selection, then
  content template selection, then the site default), the `canvas` display
  variant renders the variant's tree through the bare `canvas_page_variant`
  template, replacing the theme's `page.html.twig`. The theme's
  `html.html.twig` (head, body attributes, page top and bottom, for example the
  admin toolbar) still wraps the output. When no variant resolves, enabled
  legacy global regions for the active theme render as a backward-compatibility
  fallback. Core block layout renders the page when no such regions exist.
- **The default variant** is read and set through
  `/canvas/api/v0/settings/default-page-variant` (the generic config entity API
  cannot write simple config). A staged config update can also change
  `canvas.settings:default_page_variant` directly, the same way the homepage is
  set. The default variant cannot be deleted or disabled while it is the
  default, and a disabled variant cannot be made the default.
- **Disabled variants** keep rendering wherever they are already selected (a
  page must always render something), but cannot be selected anew: they are
  omitted from the page's template options and from the content template
  picker.

## The `canvas_page_template_component` module

This optional submodule exposes each installed theme's page template as a
component whose slots are the theme's regions (each wrapped through the theme's
`region` template). Placing it in a page variant reproduces the theme's original
page- and region-level markup. It powers the markup-identical upgrade path, and
sites that want their theme's page markup inside variants can enable and keep it.
It cannot be uninstalled while a variant uses one of its components.

## Upgrading from global regions (BREAKING)

Running database updates on a site that used page regions:

- Installs the `page_variant` entity type, the `page_variant` selection field on
  `canvas_page`, the marker component, and `canvas.settings`.
- Enables `canvas_page_template_component` and converts each theme's page
  regions into one page variant: the theme page template component with the
  marker in its `content` slot and each region's components in the matching
  slot, so the upgraded site renders markup identical to before. The default
  theme's variant becomes the site default.

Review the generated variant afterward: the conversion preserves content and the
theme's markup, but restructuring it into plain Canvas components (dropping the
theme page template component) is how you fully decouple a variant from its
theme.

The upgrade does not force `canvas_page_template_component` to stay enabled:
it is required only while a variant still contains one of its components. To
turn it off, edit each variant that uses a theme page template component,
rebuild its layout with plain components (moving each slot's components out
before deleting the theme page template instance), and publish. Once no
variant uses one, the module can be uninstalled.

Consumers updated for page variants: `canvas_translate` (config translation),
`canvas_oauth` (a `canvas_page_variant` OAuth scope), and `canvas_ai` (variant
descriptions instead of theme-region descriptions).
