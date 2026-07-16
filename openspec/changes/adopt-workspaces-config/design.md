# Design: adopt-workspaces-config

## Context

Decided in MR 1056 review conversation (Lauri Timmanee, Ted Bowman, Christian Lopez Espinola, 2026-07-16). Phase 1 stages all config auto-saves as opaque JSON payload rows on `canvas_auto-save_snapshot`, which is enough while workspaces are only ever active on Canvas API routes. Phase 2 (full workspace integration) exposes workspaces to users across the whole site, where staged config must behave as real configuration inside the active workspace: Ted's motivating example is a content template for a teaser edited in a workspace that must affect a view rendered outside Canvas. The Phase 2 branch already implements workspace-scoped config loading; this change pulls that storage decision forward into Phase 1 so the storage does not have to migrate twice and so Canvas does not ship a second, competing config-staging storage next to the contrib Workspaces Config module.

Workspaces Config provenance: split out of Workspaces Extra, maintainers amateescu and S. Lu, ecosystem direction steered by catch, used by Tag1 in production on large sites (their edge-case hardening is a large part of the value). Status caveat: dev module, no tagged release.

Delivery: implementation happens on branch `3588540-workspaces-config`, forked from `3588540-stage-canvas-auto-saves` and pushed to issue 3588540. That branch carries these specs in-repo under `openspec/changes/adopt-workspaces-config/` (full target-state specs, not deltas), the amended ADR 0014, and the updated architecture diagram.

## Goals / Non-Goals

**Goals:**

- Valid config auto-saves staged as workspace-scoped configuration in `canvas_default` via Workspaces Config.
- The fallback store becomes an invalid-data store holding only what no primary store can persist; the one-store-per-target invariant preserved.
- No observable editor behavior change: same auto-save read API, same per-item publish, same validation lifecycle, no hot-path latency regression.
- Storage that Phase 2 can adopt unchanged.

**Non-Goals:**

- Exposing workspaces or staged config outside Canvas routes (Phase 2).
- Staging config entity types Canvas does not manage (sites can enable Workspaces Config for those themselves).
- Scheduling, moderation, or any other Phase 2 concern.
- Getting `workspaces_config` a stable release (worth raising upstream, not a blocker here).

## Decisions

### D1: Workspaces Config is the staging store for valid config auto-saves

The config persist path attempts a workspace-scoped config write first. Success means the staged values live as real config attached to `canvas_default`; loading that config with the staging workspace active yields staged values, loading it outside yields live values. The auto-save read API resolves config from this store the same way it resolves content from workspace revisions today.

Rejected alternative: keep Canvas config in the Phase 1 payload store and let sites run Workspaces Config beside it for everything else, configured to ignore Canvas-owned config. Two storages for the same problem, permanent divergence risk, and a guaranteed migration in Phase 2.

### D2: The invalid-data store holds only what no primary store can persist

`canvas_auto-save_snapshot` (the entity backing the invalid-data store) keeps its schema and read integration but narrows to a separate storage capable of storing invalid data: payloads rejected at storage level (invalid intermediate states, for content and config alike) and entity types neither Workspaces nor Workspaces Config can stage. Canvas clients load it through the auto-save read API; non-Canvas consumers (Views, entity display outside Canvas, and similar) never load it. The invariant from Phase 1 carries over with one addition: a successful persist to either primary store (workspace revision or workspace-scoped config) deletes the invalid-data entry for that target. Read precedence stays buffer, then invalid-data store, then primary store.

This resolves Phase 1's open question "should the snapshot entity also serve as the Phase 2 per-workspace config staging store": no, Workspaces Config does.

### D3: Publish and validation keep their Phase 1 shape

Per-item Canvas publish, all-or-nothing per request, no workspace-level publish. The only change is the source: config items are built from workspace-scoped config (or the invalid-data store for invalid entries), validated as typed configuration before any live write, then applied to live configuration. Discard additionally clears the workspace-scoped config for discarded items.

### D4: The invalid-data-only dirty-state edge case becomes explicit spec

From review: when the first and only staged state of a target sits in the invalid-data store (it was never persistable) and later normalized evaluation equals the canonical state, the old auto-save system looped forever on discard ("not exactly the same, keep it"). The baseline requirement "Dirty state is derived, not stored" already implies the right behavior; the spec makes the invalid-data-only path an explicit scenario so it is tested rather than discovered in production again.

### D5: Staging writes stay off the hot path

Writing to entity storage is too slow for the hot path of preview-critical editing requests; the deferred write buffer exists for exactly this reason. Routing valid config auto-saves into workspace-scoped configuration must not put synchronous entity-store or config-store writes back on that hot path: PATCH latency must not regress, and the buffer (or an equally cheap write) covers these writes, flushing into workspace-scoped configuration at kernel terminate the same way content flushes into revisions today.

## Risks / Trade-offs

- [`workspaces_config` is a dev module with no release] → Pin to a vetted commit in composer; review on the implementation branch is the evaluation gate. Tag1 production usage mitigates maturity concerns more than the release status suggests.
- [Behavioral differences between opaque payloads and real config staging (config CRUD events, cache invalidation fire on staged writes)] → Covered by porting the existing config auto-save tests and running the full suite on the implementation branch; any event Canvas must suppress gets an explicit test.
- [Workspace-scoped config writes are slower than key-value writes] → Covered by D5: buffered writes on preview-critical routes; verify PATCH latency before merge (tasks).
- [Invalid config states must never hit the config storage layer] → The persist path checks first whether the payload can be persisted and routes failures to the invalid-data store, mirroring the content revision fallback; write-time validation of data semantics remains prohibited.
- [Module direction is community-owned] → amateescu, S. Lu, and catch drive it; check with Glaman, whose Acquia Source work inspired the Phase 1 config handling, before diverging from module conventions.

## Migration Plan

- No shipped-site migration: Phase 1 (MR 1056) has not merged, so no site holds Phase 1 config payload rows.
- The legacy key-value migration (post-update plus lazy) gains a branch: valid config rows stage into workspace-scoped config; rows that cannot persist stage into the invalid-data store. Attribution and timestamps preserved as in Phase 1.
- Update path additionally enables `workspaces_config`.

## Open Questions

- Does Workspaces Config support every config operation Canvas stages (create, update, delete, rename) for its fixed set of managed config (code components, page regions, templates)?
- Where does per-editor attribution live for workspace-scoped config writes (Workspaces Config may not record an editor per staged config object; Canvas may need a sidecar or to keep invalid-data-store metadata for attribution)?
- Should the exclusive-edit story for config match content (is a staged config object protected against live edits outside Canvas, and is that even desirable in Phase 1)?
