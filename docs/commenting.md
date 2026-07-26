# Commenting and collaboration

Canvas has no way to talk about the thing being built inside the thing being
built. Feedback on a page happens in Slack, in a spreadsheet, or on a
screenshot: always somewhere else, always detached from the component it is
about. Every collaborative tool the target audience already uses (Figma,
Google Docs, Notion, Framer) converged on the same answer: a pin on the
artifact, a threaded conversation attached to it, and a resolve that makes it
go away.

This document describes the commenting system's design and the scope of the
first shipped slice. The full design, spec deltas, and phasing live in the
`canvas-commenting` OpenSpec change.

## What the MVP ships

Threaded comments anchored to a component instance on a Canvas page.

- Two content entity types: `canvas_comment_thread` (the anchor and the
  resolved state) and `canvas_comment` (each message).
- An HTTP API to list threads for a surface, create a thread, reply, resolve,
  and reopen.
- Three permissions: `view canvas comments`, `create canvas comments`,
  `moderate canvas comments`.
- On-canvas pins drawn in the editor's overlay layer, and a threads sidebar
  panel with an open/resolved filter, a composer, replies, and
  resolve/reopen.

## Architecture

### Why content entities, and why not fields on the surface

Comments are per-instance user data created at runtime by editors, with
authors and timestamps, growing to thousands of rows on an active site. That
is a content entity, not configuration: configuration is site-defining data
deployed through config sync, and comments are neither site-defining nor
deployable.

Comments are *not* stored as a field on the surface they comment on. Canvas
has four editable surfaces and three of them (`content_template`,
`page_region`, and `pattern`) are **config** entities, which cannot carry
fields. A field-based design, including Drupal core's Comment module, can
address only one of the four. Core's Comment module is rejected for that
reason and several others: it has no anchor concept, no resolve/reopen, and
an access and rendering model built to show comments to site *visitors*,
which is the opposite requirement.

### The anchor

A thread is addressed by a portable anchor rather than an entity reference,
because no Drupal field type references content and config entities
uniformly:

```
surface_type    'canvas_page'          the entity type ID of the surface
surface_id      '1'                    the entity ID, as a string
component_uuid  '<uuid>' | NULL        the anchored component instance
```

`(surface_type, surface_id)` is exactly the shape
`AutoSaveManager::getAutoSaveKey()` already uses to key drafts across both
content and config entities (`src/AutoSave/AutoSaveManager.php`), so
commenting inherits an addressing convention Canvas has already proven. All
three columns are indexed, because every query is "the threads for this
surface".

A NULL `component_uuid` is a surface-level thread: it is listed in the
sidebar and draws no pin.

### Why anchors are stable

Canvas's document is a tree of stably identified nodes, not a character
stream or a coordinate space, so most of what Figma and Google Docs spend
their anchoring engineering on is already true here.

Component instance UUIDs are generated client-side
(`ui/src/features/layout/layoutModelSlice.ts:621`) and never rewritten on
save: they are round-tripped verbatim by
`ClientServerConversionTrait::doClientComponentToServerTree()`, and config
entities re-key their tree *by* the existing UUID rather than minting new
ones. Because structure (`parent_uuid`, `slot`, delta) is stored separately
from identity (`uuid`), an anchor survives every ordinary edit:

| Editing operation | Effect on a component anchor |
|---|---|
| move to another slot or parent | none |
| reorder among siblings | none |
| edit any prop | none |
| restyle, change breakpoint or viewport | none |
| translate the surface | none |
| auto-save, publish, create a revision | none |
| delete the component | stops resolving, thread orphans |
| duplicate, instantiate a pattern | new UUIDs, so the copy carries no comments |

The last row is correct rather than unfortunate: a copy is not the thing that
was commented on.

### Anchors are structural, never spatial

Figma pins to canvas x/y when nothing is under the cursor. Canvas must not. A
Canvas preview is a reflowing document rendered at several viewport widths at
once, so an x/y pin would land somewhere different in every viewport and move
on every edit. Every anchor resolves to a component instance, and its screen
position is derived per viewport from the geometry the overlay already
measures.

### Pins reuse the existing overlay stack

The pin layer is one more sibling inside the portal that
`ViewportOverlay` already renders into `#canvasPreviewOverlay`
(`ui/src/features/layout/previewOverlay/`). It reads
`usePreviewGeometry().geometryMap.component[uuid].rect` and multiplies by
`selectEditorViewPortScale`, exactly as `RegionOverlay` does. That means no
DOM walking, no new observers, correct behavior under zoom, scroll, reflow
and font load, and multi-viewport support for free.

### Comments cannot leak to the public site

Two independent structural facts, not policy:

1. Pins render in the parent document's overlay portal, never inside the
   preview `<iframe>`, the same discipline `ComponentOverlay` follows.
2. The anchor targets themselves only exist in preview: component UUID
   markers are emitted only when `$isPreview`
   (`src/Plugin/Field/FieldType/ComponentTreeItemList.php`). A live page
   carries no component UUID in its DOM at all.

Comment data is served only from authenticated Canvas API routes. There is
therefore no render path from a comment to an anonymous request.

### Comments are outside the document lifecycle

Posting a comment does not dirty the draft, does not write an auto-save
entry, and does not touch the surface's `changed` timestamp. Comments never
appear in the publish review list and never gate publishing.

Undo can never delete a comment, and this is structural rather than
aspirational: `undoRedoActionIdMiddleware` (`ui/src/app/store.ts`) pushes an
undo entry only for actions whose type begins with `layoutModel/` or
`pageData/`. The `comments/` slice is registered outside the `undoable()`
wrappers, so it is outside undo by construction. A vitest case asserts this,
so that renaming the slice into the timeline fails a test.

For the same reason the comments API uses the plain `baseQuery` rather than
`baseQueryWithAutoSaves`: comment writes must never carry an auto-save hash
and so can never trip the 409 conflict machinery.

### Permissions

Three permissions, deliberately independent of surface edit access in both
directions:

```yaml
view canvas comments:      # see threads and the sidebar
create canvas comments:    # start threads, reply, resolve, reopen
moderate canvas comments:  # delete others' comments
```

Comment API access is authorized by a comment permission **plus view access
to the surface**, never edit access. Editing your own comment is allowed;
editing someone else's is never allowed, for anyone, including moderators. A
moderator can delete; nobody can put words in your mouth.

This split costs three lines of YAML now and is expensive to retrofit. Today,
giving someone feedback rights on a page builder means giving them edit
rights. A comment-only role is the fix, and it is deliberately *not* v1
scope, because it is not really a commenting feature: it needs editor entry
to stop assuming `_entity_access: 'entity.update'` on the boot route, which
deserves its own proposal. What this change owes it is a permission model
that does not have to be unpicked first. A kernel test asserts the
independence in both directions, so the decoupling does not ship untested.

## API

All routes require authentication and a comment permission.

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/canvas/api/v0/comments?surfaceType=&surfaceId=&includeResolved=` | List a surface's threads |
| `POST` | `/canvas/api/v0/comments` | Create a thread |
| `POST` | `/canvas/api/v0/comments/{commentThreadId}/replies` | Reply |
| `PATCH` | `/canvas/api/v0/comments/{commentThreadId}` | Resolve or reopen |

Resolved threads are excluded from the listing by default. Most threads end up
resolved, so this is the single largest saving on a busy surface. They are
therefore absent from the canvas too while the sidebar's "Open" filter is
active; switching to "Resolved" fetches them and draws their pins in a
distinct resolved style. Whether a resolved thread should draw a pin at all is
an open question in the `canvas-commenting` OpenSpec change.

Semantically invalid input returns 422, never 500. Schemas for every path
live in `openapi.yml`, and Canvas validates every request and response on a
`canvas.api.*` route against them during tests.

## Deliberate simplifications in the MVP

- **The anchor is three flat base fields, not a `comment_anchor` field type.**
  The full design specifies a multi-property anchor field covering slot and
  prop text ranges. With only three populated properties, a custom field-type
  plugin plus a constraint validator is a large amount of Drupal boilerplate
  for what three indexed base fields already give. The upgrade path is to add
  base fields (`slot_name`, `prop_name`, `range_start`, `range_end`, `quote`,
  `prefix`, `suffix`) when the phase that needs them lands, and to introduce
  the field type at that point if the property set justifies it.
- **`canvas_page` is the only supported surface.** Any other `surfaceType` is
  rejected with 422. The anchor model already generalizes; only the
  validation allowlist and the editor wiring are page-specific.
- **Orphan detection is not implemented.** A thread whose component is gone
  simply renders no pin and stays in the sidebar. Nothing is deleted and
  nothing is rewritten, which is the important half of the behavior; the
  "Needs re-anchoring" UI and last-known-context capture are phase 2.
- **No polling.** The client fetches on mount and after mutations. Adaptive
  polling reusing the notification intervals is a small follow-up.

## Deferred phases

Each of these has a full design in the `canvas-commenting` OpenSpec change.

| Phase | Scope | Gated on |
|---|---|---|
| Anchor stability and repair | UUID-stability test suite, lazy orphan detection, "Needs re-anchoring" UI, re-anchor and drag-to-re-anchor, pin clustering | nothing |
| Mentions and notifications | `@[user:123]` tokens, permission-scoped autocomplete, `recipient_uid` on notifications, email via `MailManager`, deep links | The notification UI is currently gated behind `canvas_dev_mode` |
| Cross-surface | Content templates, page variants, patterns; owning-surface resolution; inherited count badges; language rules | `page-variants` |
| Text-range anchoring | Prop boundary parsing, range selection, ordered Web Annotation selector resolution, degradation UI | `inline-text-editing` |
| Real-time and presence | Comment events on the transport, live updates, typing indicators, presence avatars | `canvas-realtime-collaboration` |

Two rules keep those phases additive. First, a thread anchors to the surface
whose stored component tree actually contains the UUID. Without that rule, a
comment on a shared template header would appear on every page using it.
Canvas already implements this resolution in
`ApiLayoutController::getEntityWithComponentInstance()`, and the cross-surface
phase adopts it rather than building a second one. Second, degradation is
computed at read time and never written, so an undo that restores a deleted
component silently restores its pin.

## Open product questions

These need a human product decision and are not settled by this
implementation.

- Can anyone resolve anyone else's thread? The MVP says yes, following
  Figma. Review-signoff cultures may want resolve restricted to the thread
  author or a moderator.
- Should a comment be assignable to a person, turning it into tracked work
  with an owner and a done state? Cheap on top of mentions, but it changes
  the feature's identity from discussion to task tracking.
- What is the retention policy for resolved and orphaned threads? This design
  never auto-deletes. Sites with compliance requirements may need the
  opposite, and "comments are records" has legal implications on some sites.
- Do comments survive page duplication or pattern instantiation? This design
  says no, because a copy gets new UUIDs and is a new thing.
- Should comments be readable outside the editor: an admin view, an export,
  an API for external tools? This determines whether the entities need Views
  integration and a stable public API surface.
- Are private or role-restricted threads needed, and when? "Internal feedback
  the client cannot see" is a common agency requirement.
