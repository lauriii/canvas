# Workspace-staged auto-save architecture

High-level view of how Canvas stages auto-saves in Drupal core Workspaces
(see [ADR 0014](../adr/0014-stage-autosaves-in-a-dedicated-workspace.md),
including its Workspaces Config amendment).
Workspaces is a storage layer only: content drafts become pending revisions
and config drafts become workspace-scoped configuration (via the contrib
Workspaces Config module) in the shared `canvas_default` workspace, while
publishing stays in the Canvas pipeline with per-item validation and
granularity. Core's `Workspace::publish()` is never called.

Invariant: for any target entity (per type, ID, and langcode), exactly one
staging store holds the current draft. Reads resolve in the order pending
buffer, then snapshot row, then the primary store: workspace revision for
content, workspace-scoped configuration for config. A successful persist to
a primary store deletes the shadowing snapshot row.

Invalid is not the same as unstorable. Staging never runs entity validation,
so a draft that would fail validation stores in its primary store like any
other draft; the snapshot fallback exists only for drafts the storage layer
refuses to write. Validation runs once, at publish, per selected item, and
an invalid item is reported as a violation without blocking the other items.

```mermaid
flowchart TB
    subgraph UI["React editor (ui/)"]
        layoutApi["Layout editing<br>PATCH canvas/api/v0/layout/…"]
        pendingApi["pendingChangesApi<br>GET auto-saves/pending<br>POST auto-saves/publish<br>DELETE auto-saves/{type}/{id}"]
    end

    subgraph HTTP["Canvas HTTP API (canvas.api.* routes)"]
        activation["AutoSaveWorkspaceActivationSubscriber<br>activates canvas_default on request,<br>restores Live at terminate"]
        layoutCtl["ApiLayoutController<br>(edit + preview)"]
        autoSaveCtl["ApiAutoSaveController<br>(pending list, publish, discard)"]
    end

    subgraph Core["Auto-save core"]
        asm["AutoSaveManager (facade)<br>normalization + hashing,<br>idempotent retries, reset and conflict<br>detection against Live baselines,<br>pending list, translation groups"]
        wsa["WorkspaceAutoSave<br>routes writes per entity type,<br>resolves reads: buffer → snapshot → revision"]
    end

    subgraph Staging["Staging stores (one holds current state per target)"]
        buffer["PendingContentAutoSaveBuffer<br>durable key-value buffer, no expiry,<br>conflict metadata tombstones"]
        flusher["DeferredAutoSaveFlusher<br>flushes at kernel terminate,<br>and before any staged read"]
        snapshot["canvas_auto_save_snapshot entity<br>invalid-data store: content and config<br>drafts the storage layer rejected<br>(schema limits, unstageable entity types);<br>the JSON payload holds what no entity<br>schema can;<br>one row per (type, ID, langcode),<br>workspace-ignored, updated in place"]
        ws["Workspace canvas_default<br>pending revisions via core Workspaces<br>(all users, all entities)<br>staging never validates: invalid drafts<br>store as ordinary pending revisions<br>CanvasWorkspaceProvider: editors get view only<br>AutoSaveRevisionPruner: log-spaced history"]
        wsconfig["Workspaces Config (contrib)<br>valid config entity drafts staged as<br>workspace-scoped configuration in<br>canvas_default: resolves as regular config<br>inside the active workspace, live outside"]
        legacy["canvas.auto_save key-value<br>still stages config entities without<br>snapshot support, and all drafts while<br>the workspace schema is unavailable;<br>otherwise a migration source<br>(LegacyAutoSaveMigrator, lazy + batched)"]
    end

    live["Live<br>default revisions + live configuration<br>writes via executeOutsideWorkspace()"]

    layoutApi --> layoutCtl
    pendingApi --> autoSaveCtl
    activation -. "staged reads resolve<br>to pending revisions" .- HTTP
    layoutCtl -- "saveEntity()" --> asm
    autoSaveCtl --> asm
    asm --> wsa
    wsa -- "content entity,<br>canvas.api.* request" --> buffer
    buffer --> flusher
    flusher -- "pending revision" --> ws
    wsa -- "content entity,<br>other contexts" --> ws
    wsa -- "config entity" --> wsconfig
    wsa -- "storage layer rejected<br>the write, content or config<br>(rejection, not validation)" --> snapshot
    wsa -- "usesKeyValueStaging():<br>no snapshot support,<br>or workspace not ready" --> legacy
    legacy -. "legacy rows migrate on<br>first read or write" .-> wsa
    autoSaveCtl == "publish, the only validation point:<br>validate all selected items up front,<br>then save each to Live and<br>clear its staging" ==> live
    asm -. "hash baselines loaded<br>outside the workspace" .-> live
```

Publish clears the published item's staging (buffer row, snapshot row,
tracked pending revisions, workspace-scoped configuration, pruner state),
which also releases core's exclusive-edit lock on the entity. Discard does
the same per translation group, and dependent staged entities such as URL
aliases follow their host through both.
