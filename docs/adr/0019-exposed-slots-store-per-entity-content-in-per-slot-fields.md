# 19. Exposed slots store per-entity content in one `component_tree` field per slot

Date: 2026-07-10 (revised 2026-07-13)

Issue: TBD (un-postpone the exposed-slots follow-ups of #3541000/#3520487; resolves #3526189 and #3520517)

## Status

Accepted

## Context

Content templates render every entity of a bundle through one site-builder-owned component tree. Content creators cannot add free-form components to an individual entity: per-entity tree editing was removed for 1.0 (#3520487) pending exposed slots, the mechanism named by ADR 0006 (functional requirement 6) for storing one or multiple component trees per content entity, one per exposed slot.

The 1.x back end shipped partial scaffolding: `ContentTemplate` stores `exposed_slots`, `ValidExposedSlot` validates definitions, and rendering merges per-entity content into the exposed slot. Nothing user-facing existed and no released data used any per-entity storage shape, so the storage contract could be chosen without migration cost.

A community fork (AlchemizeCanvas) built the feature end to end and validated the editing experience (locked template chrome, drops restricted to exposed slots, per-entity trees merged at render), storing per-entity content as one `component_tree` field per exposed slot. Upstream spike #3526189 asked whether Canvas should adopt that "one field per slot" model.

An earlier revision of this ADR chose the opposite: alias-keyed "bonsai" subtrees inside the bundle's single Canvas field. It was implemented, then reverted (2026-07-13) after re-litigating #3526189, because it needed a bespoke purge whose deleted data stayed visible to JSON:API until purge ran, a `disabled` soft-delete flag, and destructive-confirm UX that the field lifecycle provides for free, and it could not make "remove from template" non-destructive without inventing exactly the detached-field state that per-field storage has natively.

## Decision

Store each exposed slot's per-entity content in its own `component_tree` field on the host bundle. The field's machine name is the slot's key in `exposed_slots` and its stable identity.

- A content template may expose multiple slots, empty or not. Each `exposed_slots` entry is keyed by a `component_tree` field machine name and stores the attachment point plus presentation (`component_uuid`, `slot_name`, `label`); template validation enforces that every key names such a field on the bundle. The template declares config dependencies on those field configs and implements `onDependencyRemoval()`, so deleting a field through Field UI detaches the slot through standard core machinery.
- Per-entity content is an ordinary component tree stored in the slot's field (roots carry empty `parent_uuid` and empty `slot`; descendants nest normally). Entity data never references template-internal component UUIDs, so templates can be rebuilt without orphaning entity content. Rendering resolves each slot field to its target component and slot and injects the tree (`ComponentTreeItemList::injectSlotContent()`).
- Template content in an exposed slot is that slot's default: entities render it until they override it, an override replaces it entirely and may be empty, and reverting to the default is an explicit action that clears the field. Editing default content on an entity materializes an entity-owned copy with fresh instance identity (the Pattern insert-by-value precedent). An empty override is stored as a marker row: a shipped, render-nothing, non-placeable component instance as the sole root of the slot field, so the state lives in the field and revisions, auto-save, workspaces, translation sync, and rendering need no special handling.
- Slot lifecycle: the expose dialog creates a new `canvas_slot_`-prefixed field (an explicit user action, with symmetric `translation_sync` at creation) or re-uses an existing unreferenced `component_tree` field on the bundle (the #3528458 "move", with zero data migration). Removing a slot from the template is a **detach** (drop the `exposed_slots` entry only); the field and every entity's content survive, invisible until re-exposed. Permanent deletion happens in Field UI, which shows and deletes existing `component_tree` field instances despite the field type being `no_ui` (#3520517's bespoke purge becomes moot).
- Editing an entity of a templated bundle is slot-scoped: the Layout API serves one editable node per exposed slot (keyed by the backing field name, containing only that entity's content) plus each slot's template default as data for the unlock fork, template chrome and global regions render only as inert preview HTML, and writes accept only per-slot subtrees stored straight into the entity's fields. Template-owned components never appear in the editable payload, so per-entity editing cannot address them.
- Templated entities are navigable in Canvas: an entity-level entry point opens the editor, a dedicated Content panel lists templated entities (bundle-grouped, searchable, entity-access-filtered), locked template chrome offers a jump to editing the template, and the template editor links back to entities using it. Entity creation stays in Drupal's forms.
- Exposed slot definitions are part of the template's client-side contract (editor, CLI, HTTP API) and are managed inside the template editor, including delete protection for components hosting exposed slots (which detaches, preserving content). The previous slot-must-be-empty validation is dropped for content templates (defaults are legitimate content) and remains an option where emptiness is still required (page variants' content slot).
- Auto-save, preview, publish, conflict detection, and symmetric translation apply to slot content exactly as to any other `component_tree` field.

## Consequences

- Hybrid structured + unstructured content works: site builders keep control of shared layout while content creators compose freely inside designated areas, per entity, replacing the main remaining reason to reach for Paragraphs.
- Template editing is non-destructive by construction: removing a slot is a pure config detach, and destructive deletion reuses Field UI's standard confirm, `deleted=1` hiding (immediate for every consumer including JSON:API), and batched cron purge.
- Each slot is an idiomatic, individually addressable and PATCHable JSON:API field whose appearance/disappearance is standard per-bundle field semantics that decoupled consumers already handle, and per-slot field access is available if ever needed.
- Accepted costs: field machine names are capped at 32 characters and can never be renamed (slot rename becomes permanently impossible without data migration; the label stays editable); entity loads touch one table pair per slot field; component-instance UUID uniqueness now spans the template and all slot fields and is validated at publish; and `ComponentTreeLoader` permanently fronts two physical shapes, since `canvas_page` and other `ComponentTreeEntityInterface` entities keep their single field.
- Default content brings the classic override trade-off: overrides drift as templates evolve (accepted, same as Patterns; the explicit revert action is the recovery path).
- The empty-override marker component must stay invisible outside its role: excluded from the component library, not placeable manually, validated to appear only as the sole root of a slot field.
- Page variants and content templates share the exposed-slots mechanism; the field-backing validation is scoped to content templates and the multi-slot merge subsumes the variant's single content slot, so changes stay additive.

## Amendments
### 2026-07-16
The per-content editing contract changed from a merged tree with per-component editability annotations (and server-side write partitioning plus structural guards) to the slot-scoped contract described above; no storage decisions changed. Prompted by review of [!1359](https://git.drupalcode.org/project/canvas/-/merge_requests/1359): serving template chrome as addressable layout nodes required guarding every mutation class, and a missed guard let per-entity edits alter templates.
