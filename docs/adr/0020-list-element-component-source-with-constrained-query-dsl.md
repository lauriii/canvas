# 20. List element: a component source with a constrained query DSL

Date: 2026-07-16

## Status

Accepted

## Context

Displaying lists of dynamic content (news, products, events) is one of the most common site-building needs. Canvas
had no visual way to build one: every placed component renders static inputs, and dynamic data enters only through
prop expressions against the host entity. Site builders had to hand-code a component or fall back to Views blocks,
both beyond what the Canvas editor audience can handle.

Canvas needs a List element: a placeable component whose rendered output is the result of a content query, with
editor-facing settings for the content source, item display, limit and pagination, filters, sorting, and layout.
The central architectural question is where those query semantics come from: adopt core Views, or build a small
query pipeline owned by Canvas.

Options evaluated:

1. **Adopt core Views: generate a View config entity per placed list.** Views brings battle-tested filters, sorts,
   pagers, caching, access, and future exposed filters for free. But placing a component on a page would create
   config from a content operation. That breaks workspaces, content staging, and config management (a draft page
   mutating deployed config), and orphan cleanup becomes Canvas's problem. The settings panel would be a lossy
   facade over Views handler config, every settings write would need config import and ownership gymnastics, and
   partial preview rendering of a full Views build is heavyweight.
2. **Adopt Views blocks via the existing `block` source (status quo).** Developers can already expose a View as a
   block and place it. Zero work, but entirely developer-driven: none of the marketer-facing settings UX. This
   remains the documented escape hatch for complex lists (relationships, contextual filters, aggregation).
3. **Build: a small declarative query DSL stored in the instance `inputs` blob**, executed through `EntityQuery`
   with a thin service layer. Settings live where every other component's settings live (content-side,
   workspace-safe, translation-safe, revision-safe), validation runs through the existing source-plugin input
   validation, and the DSL surface is exactly the settings panel surface. The cost: Canvas owns filter, sort,
   pager correctness and caching.

A secondary question is how the List element integrates with the component system: as a special-cased node type in
the tree layer, as an SDC, or as a component source plugin.

## Decision

**Build the query pipeline (option 3); do not adopt Views.** The config-in-content mismatch disqualifies Views for
instance-level settings, and the needed DSL is a small, enumerable subset: a per-field-type-family operator matrix,
prioritized sorts, and ranged windows. The DSL stays declarative so a future adapter could compile it to Views or
Search API if exposed filters or fulltext search demand it. Views blocks placed via the `block` source remain
available to developers unchanged.

**The List element is a new `list` ComponentSource plugin** providing exactly one component. This keeps the uniform
architecture: availability gated by a `Component` config entity, versioned settings, source-owned input validation,
and no special cases in tree storage. Special-casing a node type in the tree layer would violate the uniform source
interface, and shipping it as an SDC is impossible: SDC props are static shapes and cannot own query execution,
cache metadata, or the pagination endpoint.

Supporting decisions:

- The instance `inputs` blob holds one validatable settings structure: `source` (entity type and bundle, only
  `node` bundles selectable initially, but storage carries the entity type so other sources are additive),
  `display` (view mode, the built-in "Title (linked)" display, or the item template), `limit`, `pagination`,
  `filters` (conditions plus an and/or conjunction), `sorts` (prioritized), and `layout` (stack, row, grid).
- Condition operators are constrained per the target field's type family, with a fallback to the
  `is set`/`is not set` pair for unknown field types, so custom field types degrade instead of breaking.
- A `ListQueryExecutor` service translates the DSL to an `EntityQuery` with `accessCheck(TRUE)`, a current-content-
  language (plus language-neutral) condition, and a ranged window; more-pages detection fetches `page_size + 1`
  instead of running count queries. Queries are always ranged: "no limit" forces infinite scroll (validated), so
  unbounded result sets are never loaded at once.
- List renders carry the bundle-specific list cache tag (`node_list:<bundle>`), the `languages:language_content`
  and `user.permissions` cache contexts, and cache metadata bubbled from item renders. Tag-based invalidation keeps
  lists fresh without max-age expiry.
- Subsequent pages are served by one Canvas HTTP route that accepts only the list's identity (the entity storing
  the component tree, and the component instance UUID) plus an offset. Every query-shaping setting is read from the
  stored, validated inputs server-side, so the endpoint cannot be coerced into arbitrary queries. Responses are
  server-rendered item markup with full cache metadata.
- Item displays are view modes first (including the built-in "Title (linked)" display so a fresh site gets useful
  output), and a component-built item template second: an `item_template` slot whose subtree renders once per
  result with that result's entity bound as the prop-expression context, reusing the existing shape-matching
  machinery with a swapped context.

## Consequences

- Easier: marketer-grade list building with workspace-, revision-, and translation-safe settings; a settings panel
  that maps 1:1 to a validatable DSL; safe-by-default queries (access checks, language filtering, ranged windows,
  cache tags).
- Harder: Canvas owns the correctness of the operator matrix, sorting, pagination, and cache metadata, which Views
  would have provided; the operator matrix must be maintained as field types evolve (mitigated by the type-family
  mapping with the `is set`/`is not set` fallback).
- Any save of a bundle's content invalidates every list of that bundle (bundle-scoped list cache tag). Render-cached
  items keep the re-render cost to the query plus changed items; keyset caching per page window is the upgrade path
  if profiling demands it.
- Views-parity expectations must be managed in documentation: complex lists (relationships, contextual filters,
  aggregation, multi-source) remain Views blocks placed via the `block` source.
- No visitor-facing filter UI ships with this decision, but the declarative DSL and the cacheable pagination
  endpoint keep exposed filters and keyword search possible later without storage changes.
