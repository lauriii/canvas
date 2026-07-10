# Page variants

A page variant is a named, theme-independent, full-page component tree. It
renders the whole page: the route's main content is injected where a "Page
content" marker is placed in the tree. Pages and content templates select which
variant renders them; anything without a selection uses the site default
(`canvas.settings:default_page_variant`).

Page variants replace the previous "global regions" model (one `PageRegion`
config entity per theme region). See ADR 0017.

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
  admin toolbar) still wraps the output. When no variant resolves, core block
  layout renders the page unchanged.
- **The default variant** is read and set through
  `/canvas/api/v0/settings/default-page-variant` (the generic config entity API
  cannot write simple config). The default variant cannot be deleted while it is
  the default.

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

Consumers updated for page variants: `canvas_translate` (config translation),
`canvas_oauth` (a `canvas_page_variant` OAuth scope), and `canvas_ai` (variant
descriptions instead of theme-region descriptions).
