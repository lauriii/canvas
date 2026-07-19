# 21. Content entity field editing generalizes the semi-coupled form pipeline

Date: 2026-07-19

Issue: TBD (content-entity-editing; generalizes the content HTTP API tracked in #3498525)

## Status

Accepted

Builds on ADR 0019 (exposed slots) for per-entity tree editing and the per-content editor mode.

## Context

Canvas edits `canvas_page` field data in the editor sidebar through a semi-coupled pipeline: a real Drupal entity form rendered server-side (`EntityFormController`, `CanvasTemplateRenderer`), hyperscriptified into React widgets, with raw form values replayed through `FormState` on every layout POST (`ClientDataToEntityConverter::setEntityFields`) into auto-save snapshots. Structured content (nodes and other templated entities) still required the standalone Drupal form, breaking auto-save, live preview, and editing context — the Layout Builder era's top usability complaint. The content CRUD/list APIs were hardcoded to `canvas_page`, the per-content editor mode required an exposed slot, and no in-editor affordance existed for creating entities of other types or editing referenced entities.

## Decision

- **One discovery authority.** A type+bundle pair is Canvas-editable when it is `canvas_page` or has an enabled `full` view mode `ContentTemplate` (`EditableContentDiscovery`). Exposed slots are not required: a zero-slot templated bundle opens with a fully locked canvas and editable entity fields only (the "no creative freedom" tier). Route access for the content HTTP API, the content browser, the create flow, and the per-content editor gates all derive from this one service.
- **The sidebar "Content" tab is the existing semi-coupled form pipeline pointed at the opened entity.** The server partitions the entity form (rather than trimming it, as in exposed-slots phase 1): content field widgets are annotated `data-canvas-form-partition="content"`, page-level metadata (label, URL alias, sidebar groups) stays unannotated, and the client renders both tabs as disjoint slices of one mounted form, so react-hook-form state, auto-save, undo, and Drupal AJAX carry over unchanged. We do not build a parallel JSON-driven form engine.
- **Always the default form display.** The pipeline renders and replays the `default` entity form display. A curated `canvas` form mode was rejected: the replay path (`getFilteredEntityData()`, `setEntityFields()`) builds the default-operation form, and rendering one display while replaying another desyncs the two halves of the pipeline; a per-bundle form mode also has to be provisioned and silently drifts (the same reasoning that rejected it for the exposed-slots page-data split).
- **There is exactly one write path for entity field data: `entity_form_fields` form replay.** The layout POST carries it for the open entity; a dedicated `PATCH /canvas/api/v0/content/{entity_type}/{entity}/entity-form-fields` endpoint wraps the same `ClientDataToEntityConverter::applyEntityFormFields()` replay for stacked reference editing (field edits without a layout payload). Both auto-save; neither validates before publish.
- **The `canvas.api.content.*` list/create routes generalize over editable types** (aligned with upstream #3498525): listing gains bundle filtering, sorting, and browser columns (bundle, author, timestamps), with entity access enforced by access-checked queries, never permission-string heuristics; `canvas_page` keeps its historical gates byte-for-byte.
- **Creating content produces a real unpublished entity** with a bundle-label placeholder ("Untitled {bundle label}") and field defaults; constraint validation is deferred to publish (drafts may be invalid, per the editing-lifecycle spec). Auto-save-only phantom drafts were rejected: auto-save, layout routes, previews, and reference fields all key on a real entity ID.
- **Constraint violations map back to form fields in the editor.** Publish keeps per-entity validation inside one atomic transaction (multiple drafts publish together or not at all); the layout GET surfaces stored form violations so reopening a draft restores its errors; the client maps violation property paths to form element names and focuses the owning tab.
- **Referenced entities are edited in a stacked instance of the same form panel** (the form endpoint is already entity-generic), auto-saving as their own pending change; the stack is capped at one level and stacked edits sit outside undo history in v1.
- **Widget coverage grows through the render pipeline, not a parallel engine.** The entity form does not use transforms: its raw values are replayed through the Form API, so any widget whose markup the `canvas_stark` → React pipeline renders works, including `field_group` `details`/`fieldset` containers. Contrib widget adapters that need client behavior (focal point, Linkit) are added per widget via the component-form transform matrix (`ReduxIntegratedFieldWidgetsHooks` + client component map, guarded by `FieldWidgetSupportTest`); multi-value cardinality UX remains tracked upstream (#3499550, #3467870). Unsupported widgets degrade behind the Content tab's persistent "Edit in Drupal form" escape hatch.

## Consequences

- Positive: full widget fidelity (Media Library, CKEditor 5, AJAX) without reimplementation; one validation and auto-save story for pages and structured content; the editor/form split is closed for templated bundles, including ones with no exposed slots.
- Negative: form replay ties Canvas to Form API internals and the `canvas_stark` render path; large forms stress the replay POST (mitigated by queued, diff-gated posts); placeholder drafts are visible to other subsystems until published or deleted; the partition annotation depends on widget wrappers rendering their `#attributes`.
- The `isNew` heuristic (placeholder-label comparison) now also recognizes the bundle-label placeholder; renaming a draft makes it "not new", which is the pre-existing semantics.
