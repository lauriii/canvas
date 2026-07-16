# Tasks: adopt-workspaces-config

## 1. Branch and dependency

- [x] 1.1 Fork branch `3588540-workspaces-config` from `3588540-stage-canvas-auto-saves` for issue 3588540 (MR 1056 itself is not diverted)
- [ ] 1.2 Add `drupal/workspaces_config` (pinned dev commit) to composer/info dependencies; enable it in install and update paths
- [ ] 1.3 Ship these specs under `openspec/changes/adopt-workspaces-config/` in the canvas module repository on the implementation branch

## 2. Config persist path (D1, D2, D5)

- [ ] 2.1 Route persistable config auto-saves into workspace-scoped configuration in `canvas_default` via Workspaces Config; verify create, update, delete, and rename coverage for Canvas-managed config (code components, page regions, templates)
- [ ] 2.2 Repurpose the fallback as the invalid-data store: config persist failures fall back to it; a successful workspace-scoped config persist deletes any invalid-data entry for the target; only Canvas clients load it, never non-Canvas consumers (Views, entity display outside Canvas)
- [ ] 2.3 Resolve config reads through buffer, then invalid-data store, then workspace-scoped configuration in the auto-save read API
- [ ] 2.4 Confirm staged config resolves as regular configuration when the staging workspace is active and as live configuration outside it
- [ ] 2.5 Decide and implement attribution for workspace-scoped config staging (editor and edit time must survive, per the editing-lifecycle attribution requirement)
- [ ] 2.6 Verify hot-path PATCH latency does not regress: staging writes on preview-critical routes stay buffered (or equally cheap), with no synchronous entity-store or config-store writes (D5)

## 3. Publish, discard, dirty state (D3, D4)

- [ ] 3.1 Publish builds config items from workspace-scoped configuration (or the invalid-data store for invalid entries), validates as typed configuration before any live write, applies to live configuration, and removes the workspace-scoped copy
- [ ] 3.2 Discard (single and all) clears workspace-scoped configuration alongside every other staging store
- [ ] 3.3 Derive dirty state for config from the workspace-scoped values; invalid-data-only state whose normalized data equals canonical reports as no pending changes and discards cleanly

## 4. Migration

- [ ] 4.1 Key-value migration (post-update and lazy) stages valid config rows into workspace-scoped configuration, preserving payload, editor, and timestamp
- [ ] 4.2 Legacy rows that cannot be persisted still migrate into the invalid-data store

## 5. Docs and diagram

- [x] 5.1 Update the architectural diagram in the canvas module docs for the Workspaces Config storage split
- [x] 5.2 Add a revision note to ADR 0014 recording the storage decision change and its rationale

## 6. Tests

- [ ] 6.1 Kernel: valid config auto-save stages workspace-scoped configuration, no invalid-data entry, live config unchanged
- [ ] 6.2 Kernel: invalid config payload falls back to the invalid-data store; a later valid save promotes it and deletes the entry
- [ ] 6.3 Kernel: staged config resolves inside the workspace context and not outside it
- [ ] 6.4 Kernel: publish applies staged config to live and clears the workspace-scoped copy; discard removes it without touching live
- [ ] 6.5 Kernel: invalid-data-only staged state equal to canonical reports no pending changes and discards without residue
- [ ] 6.6 Port the existing config auto-save test coverage from MR 1056 and run the full suite on the implementation branch
