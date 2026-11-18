# 22. Exposed slots store per-entity content in one `component_tree` field per slot

Date: 2026-07-10

Issue: <https://git.drupalcode.org/project/canvas/-/work_items/3591817>

## Status

Accepted

## Context

Content templates render every entity of a bundle through one component tree owned by the site builder, so content creators cannot add components to an individual entity. ADR 0006 (functional requirement 6) names exposed slots as the solution: the template marks slots where each entity stores its own components. This ADR decides how that per-entity content is stored.

The alternative, storing every slot's content as subtrees in the bundle's single Canvas field, needs a bespoke purge, a soft-delete flag and destructive-confirm UX that the field lifecycle provides for free, and cannot make removing a slot from the template non-destructive.

## Decision

Store each exposed slot's content in its own `component_tree` field on the template's bundle, one value per entity. The field's machine name is the slot's key in `exposed_slots` and its stable identity.

- A content template may expose multiple slots. Components the template places in a slot are its default content. Each `exposed_slots` entry is keyed by a `component_tree` field machine name and stores the attachment point plus presentation (`component_uuid`, `slot_name`, `label`); template validation enforces that every key names such a field on the bundle. The template declares config dependencies on those field configs and reacts to their removal, so deleting a field through Field UI detaches the slot through standard core dependency handling.
- Per-entity content is an ordinary component tree stored in the slot's field (roots carry empty `parent_uuid` and empty `slot`; descendants nest normally). Entity data never references template-internal component UUIDs, so templates can be rebuilt without orphaning entity content. Rendering resolves each slot field to its target component and slot and injects the tree there.
- Template content in an exposed slot is that slot's default: entities render it until they override it, an override replaces it entirely and may be empty, and reverting to the default is an explicit action that clears the field. Editing default content on an entity materializes an entity-owned copy with fresh instance identity (the Pattern insert-by-value precedent). An empty override is stored as a marker row: a shipped, render-nothing, non-placeable component instance as the sole root of the slot field, so the state lives in the field and revisions, auto-save, workspaces, translation sync, and rendering need no special handling.
- Slot lifecycle: the expose dialog creates a new `canvas_slot_`-prefixed field (an explicit user action, with symmetric `translation_sync` at creation) or re-uses an existing unreferenced `component_tree` field on the bundle (moving content between slots with zero data migration). Removing a slot from the template is a **detach** (drop the `exposed_slots` entry only); the field and every entity's content survive, invisible until re-exposed. Permanent deletion happens in Field UI, which shows and deletes existing `component_tree` field instances despite the field type being `no_ui`, so no bespoke purge is needed.
- Editing an entity of a templated bundle is slot-scoped: the Layout API serves one editable node per exposed slot (keyed by the backing field name, containing only that entity's content) plus each slot's template default as data for the override copy, template chrome and global regions render only as inert preview HTML, and writes accept only per-slot subtrees stored straight into the entity's fields. Template-owned components never appear in the editable payload, so per-entity editing cannot address them.
- Templated entities are navigable in Canvas: an entity-level entry point opens the editor, a dedicated Content panel lists templated entities (bundle-grouped, searchable, entity-access-filtered), read-only template chrome offers a jump to editing the template, and the template editor links back to entities using it. Entity creation stays in Drupal's forms.
- Exposed slot definitions are part of the template's client-side contract (editor, CLI, HTTP API) and are managed inside the template editor, including delete protection for components hosting exposed slots (which detaches, preserving content).
- Auto-save, preview, publish, conflict detection, and symmetric translation apply to slot content exactly as to any other `component_tree` field.
- With symmetric translation, the slot field's tree is shared, so whether a slot is overridden, and with which components, is the same for all translations. Per-content editing of a non-default translation may only change inputs; the Layout API rejects structural changes (adding, removing, moving, reverting) with a 403.

## Consequences

- Hybrid structured + unstructured content works: site builders keep control of shared layout while content creators compose freely inside designated areas, per entity, replacing the main remaining reason to reach for Paragraphs.
- Template editing is non-destructive by construction: removing a slot is a pure config detach, and destructive deletion reuses Field UI's standard confirm, `deleted=1` hiding (immediate for every consumer including JSON:API), and batched cron purge.
- Each slot is an idiomatic, individually addressable and PATCHable JSON:API field whose appearance/disappearance is standard per-bundle field semantics that decoupled consumers already handle, and per-slot field access is available if ever needed.
- Accepted costs: field machine names are capped at 32 characters and can never be renamed (slot rename becomes permanently impossible without data migration; the label stays editable); component-instance UUID uniqueness spans the template and all slot fields: entity validation rejects a colliding UUID (so JSON:API returns a 422), and rendering falls back to the slot's default when a write that skips validation introduces one; and loading a component tree permanently spans two physical shapes, since Canvas pages and other entities that are themselves component trees keep their single field.
- Each exposed slot is an ordinary field: each save writes one more table pair, and on Drupal core < 11.4 each cold entity load runs one more query (11.4+ loads field tables in batched queries). The cost applies to every slot field on the bundle, including empty and detached ones, until the field is deleted. The total row count is the same as with one field. Rendering cost is mostly driven by the number of components.
- Template administrators are trusted to create fields: the expose dialog creates `canvas_slot_`-prefixed `component_tree` fields with content template update access alone, without Field UI permissions. Deleting a slot field still requires Field UI permissions.
- Default content brings the classic override trade-off: overrides drift as templates evolve (accepted, same as Patterns; the explicit revert action is the recovery path).
- The empty-override marker is a component of the marker component source (see ADR 0019), next to the "Page content" marker. It must stay invisible outside its role: excluded from the component library, not placeable manually, validated to appear only as the sole root of a component tree field on a content entity that is not itself a component tree (so a Canvas page cannot use it). Validation does not check that the field is an exposed slot, because a detached slot field keeps its content. The marker ships disabled: it is never created from the library or the component list API, only by Canvas as an empty override. Disabled components still render and validate in existing trees, so every status-filtered consumer skips it without knowing about markers.
- Page variants do not use exposed slots: they mark where the route's content renders with the `marker` component source (see ADR 0019). In the editor, a templated entity's exposed slots render as the content inside its page variant.
