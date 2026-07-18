# 17. Editor preview: stateless partial rendering, frozen regions, decoupled auto-save

Date: 2026-07-18

## Status

Accepted

## Context

Every edit in the Canvas editor used to run one pipeline: the client POSTed the entire layout and model, the server
validated the entity plus every editable page region, re-converted the full tree, wrote auto-save entries for the
entity and each region (invalidating the auto-save cache tag), re-hydrated and re-rendered every component and every
global region, and returned a full-page HTML document that the client reloaded wholesale into the preview iframe (full
re-parse, script re-execution, and an Astro re-hydration wait capped at one second). Keystroke-to-paint latency for a
one-component edit was measured in seconds, and the cost was additive across four stages: input debounce, server fixed
overhead, server render, client apply. Notably, the PATCH endpoint already received exactly the delta (one component's
model) and discarded that information by funneling into the same full-page render.

Existing machinery this decision builds on: per-component HTML comment markers (`<!-- canvas-start-{uuid} -->` /
`<!-- canvas-end-{uuid} -->`, preview only) with existing client-side TreeWalker machinery;
`RenderSafeComponentContainer` rendering each component in an isolated render context; and a client that always holds
the full layout and flat prop expressions, so it can bound an edit's blast radius without server help.

## Decision

Narrow the work at every stage to just what changed:

1. **Stateless partial render endpoint** (`POST /canvas/api/v0/layout/{entity_type}/{entity}/render`): renders only
   the requested component instances as subtrees (a slot-bearing component includes its children), from the current
   draft state plus an optional client-supplied model overlay applied to a dangling copy of the tree. It is a pure
   function: no auto-save writes, no cache tag invalidation, no region work. Requests are therefore concurrent and
   abortable; the client uses latest-wins ordering per subtree via an opaque monotonic token the server echoes without
   interpreting. The optional model payload and the opaque token exist so the realtime-collaboration op flow can later
   consume the same contract (render by uuid at a given op-log sequence, no inputs shipped).

2. **Asset deltas via the ajaxPageState pattern**: the endpoint receives the library list the preview document already
   has (initially the document's own compressed ajaxPageState value) and returns only new CSS/JS plus the subtree's
   import map and the cumulative expanded library list. New import-map entries that cannot be added to the live
   document trigger a one-time full reload (rare: the first instance of a new code component type on the page).

3. **Frozen trees, stateless and per request**: while editing content, preview requests declare `frozen: "regions"`
   (and vice versa: `frozen: "content"` while editing a region). The server skips the declared tree's auto-save
   validation, overlay, writes, and rendering; a PATCH targeting a component inside the frozen tree is refused (400).
   There is no server-side freeze state and no thaw transition: the live preview iframe DOM is the snapshot, and the
   client computes the declaration from per-tree edit/persist version counters — a tree with unpersisted edits is
   never frozen, so the fail-safe direction is always a full write and render. A frozen response is never applied as a
   full document. Stale titles/breadcrumbs in a frozen region are an accepted preview trade-off; entering region
   editing or reloading the editor resynchronizes.

4. **Optimistic structural operations**: move, delete, duplicate, and sort do not change any component's markup, so
   the client applies them directly to the preview DOM by relocating, removing, or cloning the marker-delimited node
   ranges — before any network. Insert is persist-then-render: the endpoint renders draft state, so the new component
   is persisted first, then rendered and spliced at its marker position.

5. **Decoupled auto-save**: preview rendering is a pure function of client state; persistence moved to its own
   debounced flow (`render: false` on the existing layout POST returns only the auto-save hashes), flushed on blur,
   pagehide, and tab-hide. The auto-save hash/conflict behavior is unchanged; a persist failure falls back to one full
   render, which surfaces the conflict through the existing 409 flow. All auto-save writes (persists, PATCHes,
   full-document POSTs) run on a single client-side chain in dispatch order, replacing the single-flight queue whose
   superseded callers received promises that never resolved. With no write per render request, the input debounce only
   paces render traffic and drops to 150 ms with superseded renders aborted.

6. **Full-page rendering remains the fallback and resync path** for initial load, undo/redo, slot-structure changes,
   template editing, blast-radius overflows, asset deltas that cannot be applied in place, and persist failures. The partial path is an
   optimization layer with a single always-correct escape hatch, and each client phase sits behind its own flag
   (`drupalSettings.canvas.previewPerformance`) so rollback is flag-flipping, not reverting.

Alternative considered: a `partial: true` flag on the existing PATCH. Rejected because PATCH's contract is entangled
with persistence and the full envelope; a clean pure-render endpoint is simpler to reason about and hands directly to
the collaboration flow. A DOM-diffing library was rejected because the comment markers give exact
subtree boundaries, making diffing unnecessary (see ADR-0005's lean-frontend direction).

## Deprecation direction

The full-document POST preview flow is superseded *for the editor hot path*; it remains supported for initial load,
fallback, resynchronization, template editing, and external consumers such as `canvas_translate`. Drupal.org work item
[3492065](https://www.drupal.org/i/3492065) (concurrent editing: atomic per-operation routes returning a
`PreviewEnvelope`) continues this direction server-side: when it lands, its atomic operation routes become the persist
transport (replacing the debounced full-document `render: false` POST), its per-operation auto-save writes subsume the
client's write chain ordering concerns, and — per the amended realtime-collaboration design — operation responses stop
carrying full-page HTML and consume the partial render endpoint instead. The two changes compose: this one owns how
previews *paint*, 3492065 owns how edits *persist*.

## Consequences

- Keystroke/action-to-paint improves roughly 6-60x depending on the operation (structural operations paint before any
  network; SDC prop edits pay one bounded subtree render of ~300-450 ms instead of ~2.5 s); per-edit server bytes drop
  from a full document to 0-9 KB. Server cost per edit becomes roughly constant in page size.
- Server-Timing headers on the layout endpoints and `canvas:preview:*` performance marks in the client keep every
  stage attributable; regressions can be pinned to input debounce, server fixed overhead, render, or client apply.
- Subtree markup that depends on page context outside the component (CSS sibling selectors, first/last styling) can
  drift cosmetically until the next full render; `RenderSafeComponentContainer` isolation makes server-side coupling
  unlikely by design, and the fallback tier covers the residue.
- Persistence lags the preview by a bounded debounce (~2 s, flushed on exit events), widening the crash-loss window
  from ~0 to at most that debounce — explicit and bounded, versus the previous guarantee that also rode on a
  multi-second request pipeline.
- Frozen regions do not re-render on page-data edits, so region blocks reflecting entity state (title, breadcrumbs)
  can be stale within an editing session. Accepted product trade-off.
- Two render paths exist (partial and full); both reuse the same `toRenderable()` machinery, limiting divergence to
  the envelope. Per-component render caching was evaluated and deliberately not built: instrumentation showed the
  subtree render slice (20-45 ms) is dominated by request bootstrap (60-110 ms), so caching could shave at most ~15%
  of a request whose floor is elsewhere.
