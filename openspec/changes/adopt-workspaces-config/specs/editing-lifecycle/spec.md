# editing-lifecycle

## Purpose

Define the draft, preview, and publish model shared by everything edited in Canvas. Canonical detail lives in ADR 0014 in the canvas module.

## ADDED Requirements

### Requirement: Edits are continuously auto-saved as pending changes

The Canvas editor SHALL persist changes continuously without an explicit save action. Auto-saved states are intermediate: they MAY be invalid and SHALL be persisted without passing validation; no auto-save SHALL be refused or dropped because its data is invalid. Pending changes SHALL NOT affect live site output. While an entity has pending Canvas changes, validated entity saves outside the Canvas staging workspace (for example the entity's own edit form or validated API writes) are rejected by core Workspaces' exclusive-edit constraint; publishing or discarding the pending change releases the entity. This lock is an accepted Phase 1 consequence and is revisited when Canvas adopts multiple workspaces.

#### Scenario: Half-finished work survives

- **WHEN** an editor leaves mid-edit with a required prop still empty
- **THEN** the draft persists and is restored on return, and the live page is unchanged

#### Scenario: Outside edit while staged

- **WHEN** a user submits the node edit form for an entity that has pending Canvas changes
- **THEN** the save is rejected with a message identifying the staging workspace, and succeeds again after the pending change is published or discarded

### Requirement: Preview reflects pending changes

Previewing inside the editor SHALL render the pending (auto-saved) state, composed with the pending state of anything else it depends on (for example page regions and code component working copies).

#### Scenario: Cross-entity preview

- **WHEN** a page and a page region both have pending changes
- **THEN** the editor preview shows both together while the live site shows neither

### Requirement: Publishing is explicit, per item, and validated

Publishing SHALL be an explicit step in which the user selects which pending changes to publish. Each selected item SHALL be individually validated and access checked at publish time; only valid items become live.

#### Scenario: Invalid draft cannot be published

- **WHEN** a user attempts to publish a pending change that fails validation
- **THEN** that item is rejected with its validation errors while other selected valid items can still be published

### Requirement: Concurrent edits are detected

Canvas SHALL detect when a pending change no longer matches the state it was based on (another user published or changed the same item) and SHALL surface the conflict instead of silently overwriting.

#### Scenario: Stale draft

- **WHEN** editor B publishes an item while editor A holds a pending change based on the older state
- **THEN** editor A is informed of the conflict before their change can be published

### Requirement: Pending changes are attributed accurately

The pending-changes list SHALL report, for each pending change, the user who made the most recent staged edit and the time of that edit. Attribution SHALL NOT fall back to the content entity's owner or to the current request time, and SHALL survive migration of pending changes between storage backends.

#### Scenario: Editor differs from author

- **WHEN** editor B auto-saves a change to a page authored by user A
- **THEN** the pending-changes list attributes the pending change to editor B with the time B made the edit
