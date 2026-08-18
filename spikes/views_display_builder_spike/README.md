# Views display builder spike (throwaway)

Not a deliverable. Kept for the evidence behind the `pluggable-prop-sources`
OpenSpec change and `VIEWS-DISPLAY-BUILDER-PLAN.md`.

## What it proves

**Phase 1, no module at all.** A view whose row plugin is `entity:node` in a
view mode that has a Canvas `ContentTemplate` renders every row through a Canvas
component tree, with per-row prop values resolved from that row's entity.

`canvas.content_template.node.article.teaser.yml` is the template used: one
`js.heading` whose `text` prop is an `entity-field` prop source bound to
`ℹ︎␜entity:node:article␝title␞␟value`. Paired with a view over `node_field_data`
using `row: {type: 'entity:node', options: {view_mode: teaser}}`, it produced 5
`views-row` elements, each with a Canvas island carrying that row's own title.

This works because Canvas hooks Drupal at the entity view display layer:
`ContentTemplate` implements `EntityViewDisplayInterface` and
`ContentTemplateAwareViewBuilder` substitutes it into `$displays[$bundle]`.
Views calls the entity view builder like anything else.

**Phase 2, a module drives it.** `canvas_views_spike` provides a `canvas_template`
Views row plugin that calls `ContentTemplate::build($row->_entity)` directly,
with the template chosen per display rather than inherited from the view mode.
Same result. `ContentTemplate::build()` is public, so no Canvas change is needed.

## What it proves does not work

- `ContentTemplate::build()` typehints `FieldableEntityInterface`. Passing a
  `ResultRow` is a `TypeError`. The entity is the only supported data root.
- `PropSource::parse(['sourceType' => 'views-field', ...])` throws
  `LogicException: Unknown source type.` at `src/PropSource/PropSource.php:115`.
  A module cannot add a prop source type.
- A tree cannot even *name* a third-party source type: listing one in
  `ComponentTreeMeetRequirements` throws `InvalidArgumentException`, because the
  constraint validates prefixes against `PropSource::cases()`.

## Also verified on the live site

- Cache invalidation: editing the template's tree updated the listing with no
  cache rebuild (5 rows went from 1 to 2 component instances each). The
  template's cache tag is present in the view's build.
- Pager: full pager at 2 per page gave 2 rows per page with non-overlapping
  content and a working pager element.
- Cost: `ContentTemplate::build()` returns correct cache tags but no
  `#cache[keys]`, so the phase 2 path re-hydrates every row on every request.
  The phase 1 path keeps core's per-entity render cache.

## Reproducing

Install `canvas_views_spike`, import the content template yml, create a view
over articles, and set its row plugin.

## POC module: `canvas_views_poc` (second iteration, E2E verified)

The push-back on the first spike was correct: not all views are displayed
through content templates. `canvas_views_poc` covers the rest. It is a Views
row plugin (`canvas_component`) that renders each row as one Canvas component
and binds the component's props to the display's Views fields through an
options form: pick a component, then map each prop to a field. Values are taken
from `StylePluginBase::getField()` (the pre-rendered, render-context-safe field
output) and written over the component's default static prop sources, so the
tree stays valid with no new prop source type and no Canvas change.

Verified end to end in a real browser on the live site:

- `/poc-fields-rows`: a Fields-row view over articles, `js.heading` with
  `text` bound to `Content: Title`. 5 rows, 5 hydrated `<h2>` headings, each
  with that row's own title (`evidence/poc-fields-rows.png`).
- `/poc-no-entity`: a view over `watchdog`, a base table with **no entity
  type**, `text` bound to the log type field. 4 rows, 4 hydrated components.
  Nothing about this view could be expressed by a content template.
- The full site-builder flow through Views UI: row style chooser, the plugin's
  options form (component select plus per-prop binding selects), Apply, live
  preview, Save, front end (`evidence/views-ui-preview.png`).
- `ddev phpcs` and PHPStan level 8 clean (see `canvas_views_poc/phpstan.neon`
  for which globally loaded canvas dead-code rules are ignored and why).

Bugs found and fixed during E2E:

- Calling the field handler's `advancedRender()` directly crashes the Views UI
  live preview with `LogicException: Render context is empty` — the preview
  renders outside a render context. Core's own guard lives in
  `StylePluginBase::renderFields()` (core `StylePluginBase.php:703-707`);
  consuming `getField()` instead inherits it.

Limitations found (POC-level findings, not bugs):

- Views UI's live preview shows unhydrated islands: Canvas code components are
  `client="only"`, and module scripts inside AJAX-injected preview markup do
  not execute. The saved page hydrates fine.
- Binding delivers `strip_tags`-flattened rendered field output, so it only
  fits string-shaped props. Structured shapes (image, link) would need typed
  values per prop — that is precisely the gap the `pluggable-prop-sources`
  change addresses, and this POC sharpens its justification: pluggability is
  not needed to get *a* value into a prop, it is needed to get a *typed,
  shape-matched, cacheable* value in.
- An unsaved `Pattern` config entity is used as the render vehicle because
  rendering a module-assembled tree requires an entity carrying one
  (`ComponentTreeItemList::toRenderable()`); that is finding A4 (a supported
  render entry point) in the design doc.
- A view over a non-entity base table gets no cache-tag invalidation when its
  underlying table changes (nothing fires tags for `watchdog` inserts). Views
  behavior, not Canvas's, but it shapes expectations for phase 3.

Repro: enable `canvas_views_poc`, run `mkview.php` and `mkview2.php` with
`drush scr`.

## Third iteration: the display created in the Canvas editor (E2E)

The module owner's requirement: the user must be able to create the display in
Canvas, the way the commercial prior art authors displays in its own builder
rather than in Views UI. Verified that the today-state already satisfies this
for entity-row views, end to end through the real editor UI:

1. The spike view gained a block display; Canvas auto-discovered it as
   `block.views_block.spike_entity_rows-block_1` and auto-foldered it under
   "Lists (Views)" in the Library.
2. In the Canvas editor on the Blog page: Library > Lists (Views) > Spike
   entity rows > contextual menu > Insert. The editor preview immediately
   showed the view's rows rendered through the content template
   (`evidence/canvas-editor-insert.png`).
3. Review 1 change > select > Publish.
4. The live page, fetched anonymously, renders the view's rows as hydrated
   Canvas components (`evidence/live-blog-published.png`).

So: query authored in Views UI, display placed in Canvas, rows designed in
Canvas (the content template, editable under Templates in the same editor).
The gap to the full requirement is a per-placement row template and the
display chrome; the `canvas-list-builder` change's deferred-slot interface
(`ComponentSourceWithDeferredSlotsInterface`, its design D6 Phase B) is the
committed API for exactly that, and the plan's phase 2 now targets it.
