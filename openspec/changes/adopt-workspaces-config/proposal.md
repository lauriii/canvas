# Adopt Workspaces Config for staged configuration

## Why

Phase 1 (`stage-auto-saves-in-workspace`, MR 1056) stores every config entity auto-save as an opaque JSON payload row. Review of that MR (Ted Bowman, 2026-07-16) surfaced three problems with that choice:

1. **Phase 2 needs real workspace-scoped config.** Full workspace integration must load staged config in workspace context outside Canvas (a content template edited in a workspace must affect a view rendered elsewhere on the site while that workspace is active). Opaque payloads cannot do that; shipping them in Phase 1 means a second storage migration when Phase 2 lands.
2. **Two competing storages for one problem.** The contrib Workspaces Config module (split out of Workspaces Extra, maintained by amateescu and S. Lu, steered at the ecosystem level by catch, run by Tag1 in production on large sites) already stages configuration inside workspaces. A site that needs staging for config Canvas does not manage would run both systems side by side, each with its own storage.
3. **De-risking a wholesale rewrite.** MR 1056 replaces a battle-tested auto-save system whose edge cases (translations, discard-equals-live loops, computed fields) were found over years. Delegating the config half to a module already hardened in production shrinks the surface Canvas reinvents.

## What Changes

- Config entity auto-saves that can be persisted SHALL be staged as workspace-scoped configuration through the Workspaces Config module, tracked in the `canvas_default` workspace, instead of payload rows.
- The Phase 1 payload store becomes the invalid-data store: a separate storage capable of storing invalid data, holding payloads the storage layer rejects (content or config) and entity types neither Workspaces nor Workspaces Config can stage. Canvas clients may load it; non-Canvas consumers (Views, entity display outside Canvas) never do. The resulting storage split is: workspace revisions for content, workspace-scoped config for valid config, the invalid-data store for everything that cannot be persisted.
- Staged config becomes readable both through the existing auto-save read API and as regular config within the active staging workspace context (the property Phase 2 depends on).
- Staging writes stay off the synchronous entity-storage path on preview-critical routes: writing to entity storage is too slow for the hot path, so the deferred write buffer is retained and covers workspace-scoped config writes.
- Publish keeps its per-item Canvas pipeline; config items are validated as typed configuration from their staged source (workspace-scoped config or the invalid-data store) and applied to live configuration. Workspace-level publish stays unused.
- Migration from the legacy key-value store routes valid config rows into workspace-scoped config; that cannot be persisted rows still land in the invalid-data store.
- New hard dependency on the contrib `workspaces_config` module. Accepted risk: it is a dev module without a tagged release.
- The architectural diagram in the canvas module docs is updated to account for Workspaces Config, and these specs ship in the canvas module repository.

## Delivery

Implementation happens on branch `3588540-workspaces-config`, forked from `3588540-stage-canvas-auto-saves` and pushed to issue 3588540. The branch carries these specs in-repo under `openspec/changes/adopt-workspaces-config/`, the amended ADR 0014, and the updated architecture diagram.

## Capabilities

### Modified Capabilities

- `auto-save-staging`: config staging moves from opaque payload rows to workspace-scoped configuration; the fallback store becomes the invalid-data store; read precedence, publish-time validation, migration, and discard are updated accordingly; the invalid-data-only dirty-state edge case raised in review is made explicit.

## Impact

- Canvas module back end: config persist path in the `AutoSave/Workspace` services, publish controller's config handling, install/update hooks (enable `workspaces_config`), key-value migration.
- New composer/info dependency on `drupal/workspaces_config` (dev release only).
- Existing sites: none beyond MR 1056's own update path; Phase 1 has not shipped, so no payload-to-config migration is needed.
- The architectural diagram and ADR 0014 in the canvas module carry the corresponding updates on the implementation branch.
