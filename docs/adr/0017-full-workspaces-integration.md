# 17. Full Workspaces integration: the workspace is the unit of publish

Date: 2026-08-04

Issue: <https://www.drupal.org/project/canvas/issues/3588540>

## Status

Proposed

Amends [ADR 14](0014-stage-autosaves-in-a-dedicated-workspace.md): the
publish half of that decision (per-item publish, workspace publish blocked)
is superseded; its staging mechanics are retained per workspace.

## Context

Phase 1 (ADR 14) adopted core Workspaces as a storage layer only: one hidden
workspace (`canvas_default`, labeled "Canvas") staged every editor's
auto-saves, Canvas published selected items one at a time by saving them to
Live outside the workspace, and core workspace publish was blocked in both
access control and the publish operation. That left Canvas unable to do what
Workspaces exists for: parallel streams of work that mix Canvas and
non-Canvas content, are reviewed as a unit, and go live atomically.

The branch this builds on also adopted the contrib `workspace_config` module
for configuration staging, but had not enabled it: the Phase 1 model wrote
Live config on `canvas.api.*` routes while reads happened inside the active
workspace, splitting the two across config cache partitions.

## Decision

Canvas editing becomes fully workspace-scoped, and the workspace becomes the
unit of review and publish.

1. **Staging follows the active workspace.** Auto-save reads and writes
   resolve against core's negotiated active workspace, falling back to the
   Main workspace (`canvas_default`, relabeled from "Canvas"; same machine
   ID, no data migration). Auto-save keys are workspace-prefixed
   (`{workspace}:{type}:{id}[:{langcode}]`), which partitions every staging
   store — snapshot rows (which gain a `workspace` field and a
   workspace-qualified unique key), buffer rows, key-value staging, form
   violations, pruner bookkeeping, and caches — per workspace. Buffer rows
   flush into the workspace recorded in their key even if the user has
   switched since. Route-scoped workspace activation is removed; the editor
   activates the Main workspace (persisting) when negotiation yields none.

2. **The workspace is the unit of publish.** The publish endpoint takes no
   item selection: it validates every item tracked in the active workspace
   (entity validation plus recorded form violations; update access per
   item), stages any snapshot-held drafts into the workspace, and calls core
   `Workspace::publish()` inside one database transaction (core's own
   transaction becomes a savepoint). Core promotes every tracked revision —
   sibling translations and dependent path aliases included, which removes
   Phase 1's grouping and dependent-publish workarounds — and the
   `workspace_config` pre-publish subscriber applies staged configuration.
   A post-publish subscriber clears Canvas's staging stores, consumes any
   schedule, and resets the review state.

3. **Configuration stages into the workspace.** `workspace_config` is now a
   hard dependency and enabled. The Phase 1 Live-write wrappers on
   `canvas.api.config.*` and content create/update/list routes are removed:
   while a workspace is active those writes stage into it, which also
   dissolves the config cache partition split (writes and reads share the
   workspace partition). Content deletion remains a Live operation — core
   has no staged deletion. Snapshot rows remain the store for drafts that
   cannot be persisted (code editor working copies, storage-rejected
   payloads), now per workspace.

4. **Review workflow in Canvas.** Workspaces gain base fields
   `canvas_workspace_status` (draft / in_review / approved),
   `canvas_require_review` (default TRUE for named workspaces, FALSE for
   Main), and scheduling fields. Transitions are a fixed server-side state
   machine gated by two permissions (submit for review; approve, which
   includes sending back). The pre-publish subscriber rejects publishes of
   review-required workspaces not in `approved` — covering the Canvas API,
   core Workspaces UI, and cron alike. Any staged write into an in-review or
   approved workspace demotes it to draft and cancels its schedule;
   publish-time staging suppresses demotion since it is not an editorial
   write. Contrib (wse, workspace_approval, entity_workflow) was evaluated
   and rejected in the spec process; core Workflows was rejected as
   configurability Canvas does not need yet.

5. **Scheduled publishing.** `canvas_scheduled_publish_at/by/error` fields
   on the workspace; scheduling requires publish access and (where review is
   required) the approved state. Cron publishes due workspaces through the
   same validated, gated pipeline, account-switched to the scheduling user.
   A failure cancels the schedule and records the error on the workspace
   instead of retrying.

6. **Cross-workspace locks are surfaced.** Core's one-workspace-per-entity
   semantics apply to named workspaces. The Phase 1 constraint exemption is
   narrowed: only Live saves (no active workspace) of an entity tracked
   solely in the Main workspace remain exempt. Canvas staged writes check
   ownership explicitly (programmatic saves bypass validation) and reject
   foreign-owned entities with a structured 409 naming the owning
   workspace; the editor receives lock and active-workspace context at boot
   so it can warn before the first write.

7. **Management API.** Thin endpoints wrap core: list viewable workspaces
   (with review state, schedule, pending count, access flags), create,
   delete, activate (persisting via core negotiation, so external surfaces
   such as a site dashboard observe the same active workspace), review
   transitions, schedule/unschedule.

8. **Update path.** `canvas_update_11202` enables `workspace_config`,
   installs the new fields, backfills snapshot rows, and re-keys the
   key-value stores; `canvas_post_update_0024_main_workspace` (running
   after the Phase 1 key-value migration) relabels the workspace, moves it
   to the default provider, and maps the provider-granted access onto core
   permissions ("view any workspace" for Canvas-editor roles; "edit any
   workspace" and "create workspace" for publisher roles). The legacy
   `canvas` workspace provider class remains only for the update window.

## Consequences

1. **BREAKING: per-item publish is retired.** Publishing publishes the
   whole active workspace; item-level scoping is achieved by which
   workspace you work in. One invalid item blocks the workspace publish
   with grouped per-item violations; discarding the item unblocks it.
2. Non-Canvas changes (node forms, config edits) made while a workspace is
   active publish with it — by design, and the review manifest lists them
   (content from workspace association with revision-metadata attribution;
   config from `workspace_config` rows presented as the config objects they
   stage).
3. Publish access follows core workspace access, where the publish
   operation maps to the `edit` permissions; granting "edit any workspace"
   to publisher roles is coarser than Phase 1's locked-down model. Sites
   should review the granted permissions.
4. Immediate-Live behaviors change inside workspaces: creating patterns,
   folders, or components while a named workspace is active stages them
   (they take effect for that workspace's preview and go live on publish).
5. The transactional-rollback guarantee for config application is strongest
   on Canvas-triggered paths (API, cron), which wrap `Workspace::publish()`
   in an outer transaction. A publish from the core Workspaces UI applies
   config at the pre-publish event outside any outer transaction; a content
   failure after that point rolls back content but not caches invalidated
   during config application. Accepted as equivalent to workspace_config's
   own operating model.
6. Component-instance form violations remain keyed by component instance
   UUID without a workspace prefix; the same instance staged in two
   workspaces shares them. Accepted as an edge case.
7. Kernel and functional tests that asserted Phase 1's publish blockers and
   per-item flow assert the new semantics instead.
