# Workspace-staged auto-save architecture

High-level view of how Canvas stages auto-saves in Drupal core Workspaces
(see [ADR 0017](../adr/0017-full-workspaces-integration.md), which supersedes
the publish half of [ADR 0014](../adr/0014-stage-autosaves-in-a-dedicated-workspace.md)).
Staging follows the negotiated active workspace: content drafts become
pending revisions and config drafts become workspace-scoped configuration
(via the contrib Workspaces Config module) in whichever workspace is active.
The Main workspace (`canvas_default`) is the permanent default the editor
activates when negotiation yields none; named workspaces are parallel units
of work. The workspace — not the item — is the unit of review and publish:
`CanvasWorkspacePublisher` validates every tracked item up front, stages any
snapshot-held drafts, and calls core `Workspace::publish()` in one database
transaction.

Invariant: for any target entity (per type, ID, langcode, and workspace),
exactly one staging store holds the current draft. Every staging key is
workspace-prefixed (`{workspace}:{type}:{id}[:{langcode}]`), and snapshot
rows carry a workspace column. Reads resolve in the order pending buffer,
then snapshot row, then the primary store: workspace revision for content,
workspace-scoped configuration for config. A successful persist to a
primary store deletes the shadowing snapshot row.

Invalid is not the same as unstorable. Staging never runs entity validation,
so a draft that would fail validation stores in its primary store like any
other draft; the snapshot fallback exists only for drafts the storage layer
refuses to write. Validation runs once, at publish, over every item tracked
in the workspace — and any invalid item aborts the whole publish (all or
nothing).

```mermaid
flowchart TB
    subgraph UI["React editor (ui/)"]
        switcher["WorkspaceSwitcher<br>create / activate workspaces<br>via canvas/api/v0/workspaces"]
        layoutApi["Layout editing<br>PATCH canvas/api/v0/layout/…"]
        pendingApi["pendingChangesApi<br>GET auto-saves/pending<br>POST auto-saves/publish<br>DELETE auto-saves/{type}/{id}"]
    end

    subgraph HTTP["Canvas HTTP API (canvas.api.* routes)"]
        wsCtl["ApiWorkspaceController<br>list, create, delete, activate,<br>review transitions, schedule"]
        layoutCtl["ApiLayoutController<br>(edit + preview)"]
        autoSaveCtl["ApiAutoSaveController<br>(pending list, publish, discard)"]
    end

    subgraph Core["Auto-save core"]
        asm["AutoSaveManager (facade)<br>normalization + hashing,<br>idempotent retries, reset and conflict<br>detection against Live baselines,<br>pending list, translation groups"]
        wsa["WorkspaceAutoSave<br>routes writes per entity type,<br>resolves reads: buffer → snapshot → revision,<br>rejects writes for entities locked<br>by another workspace"]
    end

    subgraph Staging["Staging stores (per active workspace)"]
        buffer["PendingContentAutoSaveBuffer<br>durable key-value buffer, no expiry,<br>workspace-prefixed keys"]
        flusher["DeferredAutoSaveFlusher<br>flushes at kernel terminate,<br>and before any staged read"]
        snapshot["canvas_auto_save_snapshot entity<br>invalid-data store: content and config<br>drafts the storage layer rejected;<br>one row per (workspace, type, ID, langcode)"]
        ws["Active workspace<br>pending revisions via core Workspaces<br>staging never validates: invalid drafts<br>store as ordinary pending revisions<br>AutoSaveRevisionPruner: log-spaced history"]
        wsconfig["Workspaces Config (contrib)<br>config entity drafts staged as<br>workspace-scoped configuration:<br>resolves as regular config inside the<br>active workspace, live outside"]
    end

    subgraph Publish["Workspace publish"]
        publisher["CanvasWorkspacePublisher<br>validate every tracked item,<br>stage snapshot drafts,<br>Workspace::publish() in one transaction"]
        gate["AutoSaveWorkspacePublishSubscriber<br>pre-publish: review-workflow gate +<br>snapshot-draft gate (all surfaces)<br>post-publish: clear staging stores,<br>delete named workspace / reset Main"]
    end

    live["Live<br>default revisions + live configuration"]

    switcher --> wsCtl
    layoutApi --> layoutCtl
    pendingApi --> autoSaveCtl
    layoutCtl -- "saveEntity()" --> asm
    autoSaveCtl --> asm
    asm --> wsa
    wsa -- "content entity,<br>canvas.api.* request" --> buffer
    buffer --> flusher
    flusher -- "pending revision" --> ws
    wsa -- "content entity,<br>other contexts" --> ws
    wsa -- "config entity" --> wsconfig
    wsa -- "storage layer rejected<br>the write, content or config<br>(rejection, not validation)" --> snapshot
    autoSaveCtl == "publish the whole workspace" ==> publisher
    publisher --> gate
    gate == "core promotes every tracked<br>revision; workspace_config applies<br>staged configuration" ==> live
    asm -. "hash baselines loaded<br>outside the workspace" .-> live
```

Publishing completes a workspace: core promotes every tracked revision
(sibling translations and dependent path aliases included), the
`workspace_config` pre-publish subscriber applies staged configuration, and
the post-publish subscriber clears every Canvas staging store for the
workspace — then deletes a named workspace (its content is live) or resets
the Main workspace's review state and schedule. Publishes from any surface
(Canvas API, core Workspaces UI, cron) pass the same pre-publish gates: a
review-required workspace must sit in an approved-for-publishing workflow
state, and a workspace with snapshot-held drafts can only be published by
the Canvas publisher, which stages them first. Discard clears staging per
translation group, and dependent staged entities such as URL aliases follow
their host through both.
