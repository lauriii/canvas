# 14. Stage auto-saves in a dedicated workspace

Date: 2026-07-04

Issue: <https://www.drupal.org/project/canvas/issues/3588540>

**Also see the [diagram](../diagrams/workspace-autosave.md).**

## Status

Proposed

## Context

Canvas persists editor changes continuously (auto-save) ahead of an explicit
publish step. These pending edits were staged as normalized entity snapshots
in the key-value store, entirely outside the entity system: no revisions, no
entity storage guarantees, no visibility to other modules, and bespoke
conflict detection based on data hashes. Drupal core already ships a staging
system, Workspaces, which stages content entity edits as pending revisions,
tracks them per workspace, previews them in isolation, and publishes them by
promoting the staged revisions to default revisions.

The long-term goal is to adopt Workspaces holistically: user-facing
workspaces that combine Canvas and non-Canvas content, staged and published
through core flows. This decision covers only the first phase: replacing the
key-value store as the auto-save staging backend while keeping every
observable editor behavior the same, including publish granularity.

Several constraints shape this phase:

- Workspaces only supports revisionable and publishable content entity types.
  Most Canvas entities are config entities (code components, asset libraries,
  brand kits, page regions, staged config updates) and cannot be staged as
  workspace revisions.
- Auto-saved states are intermediate and may be invalid. They must be retained
  on the server without passing validation, and no auto-save may be refused or
  dropped, while published states must remain fully validated.
- Workspace revisions are written through entity storage, which can reject
  data the key-value store accepted (storage-level limits, unsupported entity
  types). A retention path must exist for such data.
- The publish API offers per-item publishing: the client selects which pending
  changes to publish and each selected item is validated and access checked
  individually. Core publishes a workspace only in its entirety.
- The editor issues frequent small writes, so write latency and revision
  volume both need mitigation.
- Canvas API saves bypass entity forms, so revision metadata (user, timestamp)
  is not populated by the form layer.
- Existing sites hold key-value auto-save rows that must migrate without losing
  payloads, authorship, or timestamps.

## Decision

Stage Canvas auto-saves in Drupal Workspaces as a storage layer, keeping
publishing in Canvas.

- A single shared workspace with a fixed ID (`canvas_default`) stages all
  Canvas auto-saves, for all users and all entities. It is registered under a
  Canvas-specific workspace provider, core's mechanism for module-managed
  workspaces, so core excludes it from workspace listings and delegates its
  access control to Canvas. In this phase it is internal infrastructure:
  created at install time, viewable by Canvas editors (required to activate
  it), editable and deletable only with the workspaces administration
  permission, and not publishable through core by anyone: publish access is
  denied for every account and a pre-publish subscriber stops programmatic
  `Workspace::publish()` calls, because core's workspace-level publish would
  push all staged revisions live without entity validation. The ID is chosen for the end state:
  later phases retain this same workspace as the default Canvas workspace,
  guaranteeing at least one workspace always exists, and renaming a
  workspace once sites hold staged data would require migrating its tracked
  associations.
- Content entity auto-saves are persisted by saving the entity through its
  normal storage handler while the auto-save workspace is active. Core
  Workspaces turns each save into a pending (non-default) revision tracked in
  the workspace. A presave hook ordered after the Workspaces presave stamps
  revision user and revision timestamp.
- Auto-saves that cannot be workspace revisions are persisted as payload
  snapshots: JSON-normalized values on a dedicated content entity, one row
  per target entity type, ID, and language, updated in place and excluded
  from workspace tracking. This covers config entities, entity types
  Workspaces cannot stage, and payloads the storage layer rejects. Snapshot
  rows are readable for every entity type through the same auto-save read
  path. For a given target, the current staged state lives in exactly one
  store: a successful revision persist removes the snapshot row.
- Publishing keeps the Canvas pipeline and its per-item granularity: each
  selected item is validated and access checked individually, content items
  are saved as new default revisions outside the workspace, config payloads
  are validated and applied to live configuration, and only the published
  items' staging is cleared. The workspace-level publish operation is not
  used in this phase and is blocked outright, in access control and in the
  publish operation itself, so it cannot bypass per-item validation.
- Validation happens only at publish time, and uniformly: staging never
  validates writes, publishing validates every selected item, content and
  config alike, before the first live write, and reports failures as
  per-item violations rather than errors.
- Canvas API requests activate the auto-save workspace through a request
  subscriber, so entity loads and queries during editing resolve to staged
  revisions. Code that activates the workspace outside those routes restores
  the previously active workspace afterward.
- On preview-critical API routes, content entity writes are buffered into a
  key-value collection during the request and flushed into staging at kernel
  terminate. Buffered rows do not expire and are removed only after a
  confirmed flush; reads that return auto-save state flush first.
- Auto-save writes are idempotent: a payload identical to the current staged
  state is a no-op, and reverting to the canonical values clears staging,
  determined against the canonical revision loaded outside the workspace.
- Dirty state ("does this entity have an auto-save") is derived at read time by
  comparing normalized data hashes of the staged and canonical states, per
  translation, not persisted as a separate flag.
- Entities implicitly edited by staging an item, such as the URL alias
  entity written when a page with a changed path is staged, are staged
  alongside their host item, stay off the live site until publish, are not
  listed as separate pending changes, and follow the host through publish
  and discard.
- Staged revision history is bounded by log-spaced snapshot pruning, retaining
  approximately two times log2(n) revisions per entity; the newest staged
  revision is never pruned.
- Legacy key-value auto-save entries migrate into workspace staging lazily on
  first read or write per entity, and eagerly through a batched post-update
  pass, preserving payload, editor, and last-edit time. The key-value store
  then serves as a migration source, with two scoped exceptions where it
  remains the staging store: config entities without snapshot support
  (internal implementation details of other entities, such as staged
  language config overrides), and any draft written while the workspace or
  snapshot schema is not installed yet (before database updates run). A
  single predicate decides where a given entity's draft lives, so
  persisting, loading, and migration always agree.

Later phases build on this storage layer: multiple user-facing workspaces,
staging Canvas and non-Canvas content together, publishing through core
workspace flows, and removing the route-scoped workspace activation.

## Consequences

In order of importance, with the following markers:
- positives (`+`) vs negatives (`-`) vs status quo (`≃`)
- impact types: technical (`T`) vs operational (`O`) vs business (`B`)

1. `+T` **Auto-saves participate in the entity system.** Standard storage, revisions, cache tags, revision authorship metadata, and visibility to other modules replace an opaque key-value payload that only Canvas could interpret.

2. `+TB` **Canvas staging aligns with core rather than duplicating it.** This is the load-bearing consequence for the long-term goal: the storage layer adopted here is the foundation the multi-workspace phases build on, instead of a bespoke system that would later have to be replaced.

3. `+T` **Editing and preview read staged state through core workspace negotiation** instead of ad hoc snapshot merging: entity loads and queries during editing resolve to staged revisions with no Canvas-specific merge code on the read path.

4. `≃OB` **Publish behavior, validation timing, and access checking are unchanged from the key-value era.** Editors observe the same per-item publish granularity, auto-saves are still never refused, and published states are still fully validated, which limits regression risk in this phase.

5. `+TO` **An auto-save history exists.** Multiple staged revisions replace a single latest snapshot, enabling recovery of earlier states, with revision volume bounded by log-spaced pruning.

6. `-OB` **Core's exclusive-edit semantics lock entities with pending auto-saves.** While an entity has staged auto-save revisions, validated saves outside the auto-save workspace are rejected, so a pending Canvas auto-save blocks normal editing of that entity until it is published or discarded. Accepted for this phase and revisited with multi-workspace adoption.

7. `-TB` **Editor surfaces expose all staged revisions, valid or not.** While the auto-save workspace is active, core workspace negotiation resolves every workspace-aware read, including views blocks, entity references, and menus rendered in the preview, to staged revisions of every tracked entity: possibly invalid (staging never validates) and possibly another editor's, since the workspace is shared. In the key-value era auto-saves were invisible to entity queries, so such surfaces showed live data; this is an editor-facing behavior change in a phase that otherwise preserves behavior, and it foreshadows the site-wide staged preview that later phases make explicit. Live traffic never activates the workspace and only ever sees validated default revisions. Renderers must tolerate constraint-violating field values, which Drupal's storage layer has never guaranteed against. This boundary is pinned by a functional test: a workspace-aware surface such as a views block renders staged data inside the editor preview and live data on the live page.

8. `-O` **Sites that use Workspaces for their own staging conflict with Canvas activating the auto-save workspace on its API routes**, since an entity can only be staged in one workspace at a time. Combining them is deferred to the multi-workspace phase.

9. `-T` **Staged state is spread across four stores.** Workspace revisions, snapshot rows, the pending write buffer, and pruning bookkeeping must be kept consistent by every publish, discard, and delete flow, guarded by the single-current-store invariant.

10. `-T` **Publishing one translation requires composition.** Workspace revisions hold all translations of an entity in one revision, while auto-save identity is per translation, so publishing one translation must compose it onto the canonical entity and re-stage the others.

11. `≃T` **Config staging remains a parallel bespoke mechanism** (payload snapshots applied at publish time) until core Workspaces can stage configuration.

12. `-O` **The upgrade path is heavyweight.** It must install the snapshot entity schema, enable the Workspaces module, create the shared workspace, and migrate key-value rows on live sites before serving editor traffic.
