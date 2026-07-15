# Release note: native prop forms

Draft release note and change record material for the native prop forms change. This repository has no changelog
file; per [the release process](../release-process.md), release notes and change records are published on
Drupal.org at release time — source them from here.

## Summary

Component prop forms now render natively in the editor by default: each prop's configured Drupal field widget id is
resolved against a client widget registry and renders instantly as a typed React widget from cached metadata,
instead of waiting on a server-built Form API form (previously a roughly 300 ms round trip per selection). Props
whose widget id has no registered client counterpart render via a server-built form island scoped to exactly those
props (the escape hatch), and whole server-built forms remain for Blocks, Personalization, Fallback sources, and
content templates.
Formatted text widgets stay on the escape hatch in this release.

Documentation: [Client-side widgets](../client-side-widgets.md),
[Component instance and page data forms](../component-and-entity-forms.md), and
[ADR-0017](../adr/0017-client-side-field-widgets.md).

## Breaking changes (native path only)

- `hook_form_alter()` implementations targeting the component instance form no longer apply to natively rendered
  props. They still apply to escape-hatch props and to whole-form sources.
- Media library contrib integrations (DAM connectors, `media_library_edit`, and similar) no longer apply to
  natively rendered media props, for the same reason.
- Form API `#states` dependencies between props cannot span the boundary between a natively rendered prop and an
  escape-hatch island. Within a single island, or on the whole-form path, `#states` keeps working. The supported
  replacement for cross-prop conditions is the declarative `x-canvas-states` schema key (effects `visible` and
  `enabled`, conditions as JSON Schema over sibling prop values) — see the
  [authoring section](../client-side-widgets.md#conditional-prop-states-x-canvas-states).

The `canvas.transforms` metadata requirement on field widget plugins is unchanged and still required: it keeps the
escape hatch and the kill switch working for every configured widget.

## Bridges: kill switch and per-widget disable

Two new keys in the `canvas.settings` simple configuration, delivered to the editor as
`drupalSettings.canvas.propForms`:

- Site-wide kill switch — force the previous server-built form path for all props, restoring `hook_form_alter()`
  effects and media library contrib integrations in full:

  ```sh
  drush config-set canvas.settings prop_forms.native 0
  ```

- Per-widget disable — send specific widget ids to the escape hatch while all other props stay native (for
  example, `media_library_widget` on sites using DAM integrations):

  ```sh
  drush config-set --input-format=yaml canvas.settings prop_forms.disabled_widgets '["media_library_widget"]'
  ```

## New and changed endpoints

- `PATCH /canvas/api/v0/form/component-instance/{entity_type}/{entity}` — gained an optional
  `form_canvas_props_filter` parameter that scopes the Form API build to the listed props (used by escape-hatch
  islands).
- `GET /canvas/api/v0/autocomplete?component=&version=&prop=&q=` — new scoped entity reference autocomplete; the
  server derives the target entity type and bundles from the prop definition.
- `POST /canvas/api/v0/file/upload` — new file upload endpoint for the native `file_generic` widget.
- `GET /canvas/api/v0/media/{media_type}?search=&page=&ids=` — new media browse endpoint for the native media
  library widget; uploads continue to use the existing `POST /canvas/api/v0/media/{media_type}/upload`.
