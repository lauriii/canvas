# 17. Page variants replace theme-global regions

Date: 2026-07-10

Issue: TBD (file against canvas 1.x when opening the MR)

## Status

Proposed

## Context

Canvas builds the page around the main content with one component tree per theme region, stored as one config entity per theme and region. The `content` region is reserved for the main content of the matched route. This model is global: a theme has exactly one set of region layouts, shared by every page rendered with that theme.

Site builders need multiple reusable full-page layouts, selectable per page: a marketing layout without navigation, a documentation layout with a sidebar, a default layout. The region model cannot express this. It is also coupled to theme `.info.yml` regions, a concept Canvas otherwise abstracts away from site builders, and it forces the editor to maintain a multi-region layout model with region-scoped focus, overlays, and access checks.

Commercial visual builders (notably Framer) converged on a different model: a page template is itself a page built from components, containing a marker element that designates where each page's own content appears, and every page selects which template renders it.

## Decision

Replace theme-global regions with page variants.

- A page variant is a config entity holding a single named, theme-independent, full-page component tree with a label and description. It has no relationship to theme regions.
- Where the page's content appears is marked by placing a "Page content" element in the variant tree: a purpose-built marker block, placeable only inside page variant trees. Each variant must contain exactly one. At render time the route's main content is injected where the marker sits.
- A Canvas page and a content template can each select the variant that renders them. An unset selection falls back to the site default variant, recorded in the module settings. A selection referencing a missing variant falls back to the default at render time. Deleting a variant removes the selection from config entities that reference it rather than cascade-deleting them; the default variant cannot be deleted while it is the default. If no default is configured, pages render through core block layout.
- At render time, the selected variant's tree renders as the full page with the route's main content injected at the marker. This applies to every non-admin-theme route, including routes that are not Canvas entities. Page title and status message blocks placed in a variant receive the route's title and messages.
- The variant replaces the theme's page-level template: when a variant renders, the page body markup comes entirely from the variant's component tree through a module-provided bare page template, and the theme's page template with its region wrappers does not participate. The theme's html-level template keeps wrapping the output, so head markup, body attributes, and page top and bottom (for example the administration toolbar) are unchanged. When no variant resolves, the theme's page template renders as before.
- Page variants follow the same editing lifecycle as all Canvas edits: continuously auto-saved, previewable composed with other pending changes, published explicitly and validated per item. They are managed entirely in the editor (create, rename, duplicate, delete, set default) with no Drupal admin forms.
- While editing a page, the resolved variant's components are visible but locked, with a single affordance to jump to editing the variant itself.
- An optional companion submodule exposes each theme's page template as a component: the theme's regions become the component's slots, its output renders through the theme's own page template, and each slot's content renders through the theme's region template, preserving both page-level and region-level markup. The component is placeable only in page variants. The module powers the markup-identical upgrade path, and sites that want their theme's page markup inside variants may enable and keep it independently of any upgrade. It cannot be uninstalled while a variant uses one of its components; sites that never need it never enable it.
- Existing region config is migrated: each theme's regions convert into one variant containing that theme's page template component, with each region's tree placed in the matching slot and the content marker in the `content` slot, so the upgraded site renders markup identical to before. The default theme's variant becomes the site default. Component instances and their config translation overrides carry over unchanged. Pending region drafts are preserved: they are converted by the same slot placement (draft tree where a region has one, published tree otherwise) into one auto-saved draft on the new variant, so the live site keeps rendering the published state and the draft remains publishable through the normal flow. Permissions and OAuth scopes are mapped to their variant equivalents. Sites that never used regions are untouched. The region entity type, its HTTP API endpoints, and its admin form are removed.

## Consequences

Easier:

- Multiple page layouts per site, reusable and selectable per page or per content template, deployable through configuration management.
- A simpler editor model: one editable tree per editing context instead of a multi-region layout, removing region focus routing, region overlays, and per-region access checks.
- Theme independence: variants survive theme switches, and page building no longer depends on theme-declared regions.

More difficult or riskier:

- Breaking change: region-based sites must run the migration. The upgrade renders identical markup through the theme page template component, but variants using that component stay coupled to their theme; that coupling is acceptable for sites that choose it and is removed by restructuring with plain components. Draft conversion adds upgrade-path complexity: client-format auto-save data must be transformed and merged, and because drafts may be invalid by design the conversion cannot rely on validation to catch mistakes.
- Every non-admin route flows through the default variant, so variant validation and fallback behavior are load-bearing for the whole site, matching the blast radius regions had.
- The name "page variant" coexists with Drupal core's display variant concept; documentation must keep the two distinct.
- Consumers of the region entity (config translation, OAuth scopes, AI region descriptions, the CLI) must migrate to the variant entity in the same release.
