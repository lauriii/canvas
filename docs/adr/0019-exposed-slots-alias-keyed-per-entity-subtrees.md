# 19. Exposed slots store per-entity trees as alias-keyed subtrees in one Canvas field

Date: 2026-07-10

Issue: TBD (un-postpone the exposed-slots follow-ups of #3541000/#3520487; resolves #3526189 and #3520517)

## Status

Proposed

## Context

Content templates render every entity of a bundle through one site-builder-owned component tree. Content creators cannot add free-form components to an individual entity: per-entity tree editing was removed for 1.0 (#3520487) pending exposed slots, the mechanism named by ADR 0006 (functional requirement 6) for storing one or multiple component trees per content entity, one per exposed slot.

The 1.x back end ships partial scaffolding: `ContentTemplate` stores `exposed_slots` (alias => component UUID, slot name, label), `ValidExposedSlot` validates definitions, and rendering merges the host entity's Canvas field into the single supported slot. Nothing user-facing exists, and two storage contracts for the per-entity rows coexist: the shipped merge parents entity rows to template-internal component UUIDs, while the field schema docblock documents roots with NULL `parent_uuid` carrying the exposed slot alias in `slot`, referencing a validator that was never written. No UI can produce either shape yet, so the contract can still be chosen without migration cost.

A community fork (AlchemizeCanvas) built the feature end to end and validated the editing experience: locked template chrome, drops restricted to exposed slots, per-entity trees merged at render. Its storage model provisions one `component_tree` field per exposed slot, with the slot key doubling as the field machine name by convention, which forces template config saves to write field config, an inert field widget that suppresses validation, and permission-string heuristics.

## Decision

Implement exposed slots with per-entity content stored as alias-keyed subtrees in the host entity's single Canvas field.

- A content template may expose multiple slots, empty or not, each under a stable machine-name alias with a label. Template content in an exposed slot is that slot's default: entities render it until they override it, an override replaces it entirely and may be empty, and reverting to the default is an explicit action that deletes the override. Editing default content on an entity materializes an entity-owned copy with fresh instance identity, the Pattern insert-by-value precedent. Since "no rows for an alias" means inherit, an empty override is stored as a marker row: a shipped, render-nothing, non-placeable component instance at the alias's bonsai root, so the state lives in the field and revisions, auto-save, workspaces, translation sync, and rendering need no special handling. An exposed slot may be disabled, which retains its definition and per-entity content while rendering and editing as if not exposed.
- Per-entity content rows live in the bundle's one Canvas `component_tree` field. Each slot subtree's root carries NULL `parent_uuid` and the alias in `slot`; descendants nest normally. Entity data references only the alias, never template-internal component UUIDs, so templates can be rebuilt without orphaning entity content. Published entity data is validated against the applicable template's declared aliases.
- Exposing the first slot for a bundle ensures the bundle's Canvas field exists, realizing the documented opt-in ("the entity is opted into Drupal Canvas, which means it has a Canvas field"). No per-slot fields, no form widget.
- Rendering resolves each active alias to its target component and slot and injects that subtree; rows for removed aliases are ignored at render and purged on the next entity save; rows for disabled aliases are retained but not rendered.
- Editing an entity of a templated bundle presents the merged tree with server-computed per-component editability: template-owned components are locked, exposed slots accept arbitrary subtrees, and the server rejects writes touching template-owned components while partitioning slot content back into the entity's field.
- Templated entities are navigable in Canvas: an entity-level entry point opens the editor, the content navigator lists templated entities alongside Canvas pages (bundle-grouped, searchable, entity-access-filtered), locked template chrome offers a jump to editing the template, and the template editor links back to entities using it. Entity creation stays in Drupal's forms.
- Exposed slot definitions become part of the template's client-side contract (editor, CLI, HTTP API) and are managed inside the template editor, including delete protection for components hosting exposed slots. The previous slot-must-be-empty validation is dropped for content templates (defaults are legitimate content) and remains an option where emptiness is still required (page variants' content slot).
- Auto-save, preview, publish, conflict detection, and symmetric translation apply to slot content exactly as to any other entity component tree; the template editor renders exposed slots as labeled empty placeholders rather than merging any entity's content.

## Consequences

- Hybrid structured + unstructured content works: site builders keep control of shared layout while content creators compose freely inside designated areas, per entity, replacing the main remaining reason to reach for Paragraphs.
- The single-field model keeps translation, workspaces, revisions, JSON:API exposure, and deployment semantics identical to existing Canvas fields, and avoids the fork's field-per-slot provisioning, widget suppression, and naming-convention coupling.
- The alias indirection adds one resolution step at merge time and requires the previously missing bonsai-root validator, plus cross-config coupling: entity validation on templated bundles consults the template's exposed slots.
- The shipped test-only foreign-parent shape must be migrated (an update hook, trivial in practice) and `injectSubTreeItemList()` reworked for aliases and multiple slots.
- Removing an exposed slot becomes a destructive operation for entity content; the disabled state exists as the reversible path and the editor must confirm removal explicitly.
- Default content brings the classic override trade-off: overrides drift as templates evolve (accepted, same as Patterns; the explicit revert action is the recovery path).
- The empty-override marker component must stay invisible outside its role: excluded from the component library, not placeable manually, validated to appear only as an alias's sole bonsai root.
- Page variants and content templates share the exposed-slots mechanism; changes here (multi-slot, alias resolution) must stay additive so the variant's single content slot keeps working.
