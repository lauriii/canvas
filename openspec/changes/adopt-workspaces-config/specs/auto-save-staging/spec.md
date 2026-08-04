# auto-save-staging

## Purpose

Define how pending Canvas changes are persisted and read back: the dedicated
staging workspace, workspace-scoped staging of content and configuration, the
invalid-data store, the deferred write buffer, staging invariants (exactly one
store holds the current staged state per entity), revision pruning, workspace
isolation, and migration from the legacy key-value store. Canonical detail
lives in ADR 0014 in the canvas module. This is the target state after the
`adopt-workspaces-config` change, merged onto the Phase 1 baseline
(`stage-auto-saves-in-workspace`, MR 1056, issue 3588540).

## ADDED Requirements

### Requirement: A dedicated internal staging workspace backs auto-saves

Canvas SHALL stage auto-saves in a single dedicated workspace (`canvas_default`) created during install or update. The workspace is internal infrastructure: it SHALL NOT appear in the core Workspaces UI (switcher, listings), and users SHALL NOT be able to publish, edit, or delete it through core Workspaces surfaces. If the workspace entity does not exist at write time (deleted, or not yet provisioned), the auto-save SHALL be retained in a fallback staging store and remain readable; it MUST NOT fall through to saving the entity in the Live workspace.

#### Scenario: Workspace is invisible to the Workspaces UI

- **WHEN** a user with Canvas editing permissions opens the core Workspaces switcher or listing
- **THEN** the staging workspace is not offered

#### Scenario: Missing workspace never leaks to Live

- **WHEN** the staging workspace entity has been deleted and an auto-save write arrives
- **THEN** no default (live) revision is created and the draft is still retained and readable in the editor

### Requirement: Content auto-saves are pending revisions

Auto-saves of workspace-supported content entities SHALL be persisted as pending (non-default) revisions of the target entity, tracked in the staging workspace. Each staged revision SHALL record the acting editor as revision user and the edit time as revision timestamp, since Canvas API saves bypass entity forms. The live default revision SHALL remain unchanged.

#### Scenario: Auto-save creates a pending revision

- **WHEN** an editor changes a page in the Canvas editor
- **THEN** a new non-default revision tracked in the staging workspace exists, its revision user is the editor, and the live page output is unchanged

### Requirement: Valid config auto-saves are staged as workspace-scoped configuration

Config entity auto-saves that can be persisted SHALL be staged as workspace-scoped configuration attached to the staging workspace. Live configuration SHALL remain unchanged until publish. Staged config SHALL be readable through the same auto-save read API as every other staged state, and SHALL resolve as regular configuration when loaded with the staging workspace active: reads inside the workspace context return the staged values, reads outside it return the live values.

#### Scenario: Config entity auto-save stages workspace-scoped configuration

- **WHEN** an editor changes a code component (config entity) with a persistable payload
- **THEN** the pending state is stored as workspace-scoped configuration, no invalid-data store entry exists for it, and live configuration is unchanged

#### Scenario: Staged config resolves inside the workspace context

- **WHEN** a page region staged in the staging workspace is loaded while that workspace is active
- **THEN** the staged values are returned, and loading the same configuration outside the workspace returns the live values

### Requirement: An invalid-data store retains every auto-save the primary stores cannot hold

When an auto-save cannot be persisted in a primary staging store (pending revision for content, workspace-scoped configuration for config), Canvas SHALL retain it in a separate storage capable of storing invalid data, keyed by target entity type, ID, and language. This applies to payloads the storage layer rejects (content and config alike) and to entity types workspace staging cannot hold. Valid config entity auto-saves SHALL NOT be stored in the invalid-data store. Canvas clients MAY load invalid-data store entries through the auto-save read API; non-Canvas consumers (for example Views or entity display outside Canvas) MUST NOT load them. An auto-save write SHALL only report success to the client after the data is durably stored in one of the staging stores; failures SHALL surface as errors, never be logged and swallowed.

#### Scenario: Invalid config payload

- **WHEN** an editor's change to a code component produces a payload the storage layer cannot persist
- **THEN** the auto-save is retained in the invalid-data store and the editor continues to see and restore that state

#### Scenario: Storage-rejected content payload

- **WHEN** a content auto-save contains a value the storage layer refuses to store as a revision
- **THEN** the auto-save is retained in the invalid-data store and the editor continues to see and restore that state

#### Scenario: Invalid drafts are invisible outside Canvas

- **WHEN** a draft held in the invalid-data store targets an entity rendered by a view or entity display outside Canvas
- **THEN** the rendered output reflects the live state, not the draft

#### Scenario: All stores fail

- **WHEN** neither a primary staging store nor the invalid-data store can persist the auto-save
- **THEN** the client receives an error response for the auto-save request

### Requirement: Exactly one store holds the current staged state

For a given target entity and language, the current staged state SHALL live in exactly one staging store at any time. Read precedence SHALL be: deferred write buffer, then the invalid-data store, then the primary staging store (pending revision for content, workspace-scoped configuration for config). A successful persist to a primary store SHALL delete any invalid-data store entry for the same target; publish and discard SHALL clear all stores for the target.

#### Scenario: Content recovery from fallback

- **WHEN** a content auto-save previously fell back to the invalid-data store and a later auto-save persists successfully as a pending revision
- **THEN** the invalid-data store entry is removed and reads resolve to the pending revision

#### Scenario: Config recovery from fallback

- **WHEN** a config auto-save previously fell back to the invalid-data store and a later auto-save persists successfully as workspace-scoped configuration
- **THEN** the invalid-data store entry is removed and reads resolve to the workspace-scoped configuration

### Requirement: Deferred auto-save writes are durable

Writing to entity storage is too slow for the hot path of preview-critical editing requests; this constraint applies to every staging write regardless of which store it targets. On preview-critical API routes, Canvas SHALL therefore buffer auto-save writes during the request and flush them into staging at kernel terminate, rather than writing to entity storage synchronously. Buffered rows SHALL survive until flushed: a flush failure SHALL keep the row for retry, any read of an entity's auto-save state SHALL first flush its pending buffer row, and buffered rows MUST NOT expire while unflushed. Endpoints returning auto-save hashes or starting points SHALL flush before responding so returned tokens match durable state.

#### Scenario: Hot path defers entity storage writes

- **WHEN** an editor's change arrives on a preview-critical API route
- **THEN** the response is produced without a synchronous entity storage write, and the change is flushed into staging at kernel terminate

#### Scenario: Terminate never runs

- **WHEN** the process dies after the auto-save response is sent but before the terminate flush
- **THEN** the buffered edit is flushed into staging on the next read of that entity's auto-save state, with no data loss

### Requirement: Auto-save writes are idempotent

Re-sending an auto-save payload identical to the currently staged state SHALL be a no-op: it MUST NOT create a new staged revision and MUST NOT discard existing staged state. Detecting "the editor reverted to the canonical values" SHALL compare against the canonical (live) revision, loaded outside the staging workspace.

#### Scenario: Client retry after timeout

- **WHEN** the client re-sends the same auto-save payload because the first response timed out after being applied
- **THEN** the staged state is unchanged and no staged data is deleted

### Requirement: Dirty state is derived, not stored

Whether an entity has pending changes SHALL be derived at read time by comparing normalized data hashes of the staged state and the canonical revision. Staged state whose normalized data equals the canonical revision SHALL be reported as "no pending changes". This SHALL hold regardless of which staging store holds the state, including state that only ever existed in the invalid-data store.

#### Scenario: Undo back to live values

- **WHEN** an editor manually reverts every change so the staged state matches live
- **THEN** the entity no longer appears in the pending-changes list

#### Scenario: Invalid-data store state equal to live

- **WHEN** a target's only staged state is an invalid-data store entry (no primary-store persist ever succeeded) and its normalized data now equals the canonical state
- **THEN** the entity is reported as having no pending changes and discarding it succeeds without residue

### Requirement: Staged revision history is bounded

Canvas SHALL bound the number of retained staged revisions per entity using log-spaced pruning (approximately 2 * log2(n) retained revisions). Pruning MUST NOT delete the most recent staged revision and MUST NOT touch default (live) revisions.

#### Scenario: Long editing session

- **WHEN** an editor produces hundreds of auto-saves on one entity
- **THEN** the retained staged revisions grow logarithmically and the latest staged state is always intact

#### Scenario: Live revisions are never pruned

- **WHEN** pruning runs for an entity that also has published (default) revisions
- **THEN** no default revision is deleted and the newest staged revision remains intact

### Requirement: Validation happens only at publish time, uniformly

Auto-save staging SHALL NOT validate data semantics at write time; staging stores accept invalid intermediate states by design (routing a payload between a primary store and the invalid-data store based on whether the storage layer can persist it is not validation). At publish time, every selected item SHALL be validated before any live write occurs: content entities through entity validation plus recorded form violations, config entities through typed configuration validation of the staged payload, wherever it is held. Validation failures SHALL be reported as per-item violation responses grouped by entity, for content and config alike; they MUST NOT surface as unhandled server errors. A validation failure in any selected item SHALL prevent all selected items from being written (all-or-nothing per publish request).

#### Scenario: Invalid staged config entity

- **WHEN** a user publishes a selection that includes a staged code component whose payload fails typed configuration validation
- **THEN** the response lists that item's violations in the standard per-item format and no selected item is written to live

#### Scenario: Validation precedes every live write

- **WHEN** a publish request contains one valid content item and one invalid config item
- **THEN** the invalid item's violations are reported and the valid item is not published in that request

### Requirement: Publishing selected items does not publish the workspace

Publishing SHALL use the Canvas publish pipeline: each selected item is validated and access checked individually, content items are saved as new default revisions outside the staging workspace, and config items have their staged values validated and applied to live configuration. The workspace-level publish operation SHALL NOT be used. Publishing SHALL clear every staging store for the published items only, including their workspace-scoped configuration; unselected staged items SHALL remain staged and untouched. Core Workspaces' exclusive-edit validation MUST NOT block Canvas's own publish of a staged entity.

#### Scenario: Subset publish

- **WHEN** two entities have pending changes and the user publishes only the first
- **THEN** the first goes live and loses all staged state, and the second remains pending with its staged state intact

#### Scenario: Publish releases the entity

- **WHEN** a staged entity is published through Canvas
- **THEN** its workspace tracking is removed and the entity can again be saved outside Canvas

#### Scenario: Publishing a staged config item

- **WHEN** a user publishes a staged page region held as workspace-scoped configuration
- **THEN** the staged values are applied to live configuration, the staged copy is removed, and other staged items are untouched

### Requirement: Dependent staged entities follow their host item

Entities implicitly edited by staging a Canvas item (for example the URL alias entity written when a page with a changed path is staged) SHALL be staged in the workspace with it, SHALL NOT leak to the live site before the host item is published, SHALL NOT appear as separate entries in the pending-changes list, and SHALL be published and discarded together with their host item, leaving no tracking behind.

#### Scenario: Page with a changed URL alias

- **WHEN** an editor changes a page's URL alias in Canvas and later publishes the page
- **THEN** the live alias is unchanged until publish, the pending-changes list shows only the page, and after publish neither the page nor its alias remains tracked in the staging workspace

### Requirement: Per-language auto-saves are preserved

Auto-save staging SHALL keep pending changes of different translations of the same entity independently addressable at the storage level: each translation's pending change SHALL be separately keyed, restored, and hash-compared. Because symmetric translations share component-tree structure, the publish and discard unit is the entity's translation group: publishing or discarding an entity SHALL carry every staged translation of it together, and no translation's staged data SHALL ever be dropped without being either published or explicitly discarded.

#### Scenario: Two translations pending

- **WHEN** an editor publishes a page whose English and Finnish translations both hold pending changes
- **THEN** both translations are published together and all of the entity's staging is cleared, with neither translation's edits lost

### Requirement: Legacy key-value auto-saves migrate losslessly

Existing key-value auto-save rows SHALL migrate into workspace staging lazily on first access and eagerly through a post-update pass. Valid config rows SHALL migrate into workspace-scoped configuration; data that no primary store can hold SHALL migrate into the invalid-data store. Migration SHALL preserve the payload, the owning editor, and the last-edit time, and SHALL remove the key-value row only after the staged copy is durable. Any temporary access relaxation needed to switch workspaces during migration SHALL be confined to the update process and MUST NOT be observable by regular site traffic.

#### Scenario: Upgrade with pending work

- **WHEN** a site updates while an editor has an unpublished key-value auto-save
- **THEN** after the update the pending change appears unchanged in the editor and pending-changes list, attributed to the same editor with the original timestamp

#### Scenario: Upgrade with a pending config draft

- **WHEN** a site updates while an editor has an unpublished, valid key-value config auto-save
- **THEN** after the update the pending change is staged as workspace-scoped configuration and appears unchanged in the editor and pending-changes list, attributed to the same editor with the original timestamp

### Requirement: Discarding clears every staging store

Discarding a pending change SHALL remove its workspace-tracked revisions, workspace-scoped configuration, invalid-data store entries, buffer rows, pruning bookkeeping, and caches. Discarding all pending changes SHALL do the same for every staged entity, including entities staged only as workspace revisions or only as workspace-scoped configuration.

#### Scenario: Discard all

- **WHEN** a user discards all pending changes
- **THEN** the pending-changes list is empty and stays empty on reload, and previously staged entities can be edited outside Canvas again

#### Scenario: Discard a staged config item

- **WHEN** a user discards a pending change held as workspace-scoped configuration
- **THEN** the staged configuration is removed, live configuration is unchanged, and the item disappears from the pending-changes list
