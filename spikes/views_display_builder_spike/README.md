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

## Fourth iteration: `views_list` — the display designed in Canvas per placement

Built on the new core `ComponentSourceWithDeferredSlotsInterface` (branch
`feat/deferred-slot-rendering`, the implemented subset of canvas-list-builder's
D6 Phase B design). `canvas_views_poc` now also provides a `views_list`
component source: one Canvas component per eligible views display, each with an
`item_template` deferred slot the user designs in the Canvas editor, per
placement, plus per-prop bindings to the display's Views fields in the
component's own Settings panel.

Verified end to end in the Canvas editor on the live site:

1. Insert "POC: fields rows: Page" from the Library (auto-discovered, one
   component per views display).
2. The Item template slot appears in Layers and as a drop target in the
   preview; a Heading component placed inside it renders once per result row
   (3 rows in preview, editing annotations on the first repetition only).
3. Selecting the views_list instance shows the bindings form in the editor's
   Settings panel: "Heading: text" with the display's field handlers as
   options. Choosing "Content: Title" re-renders the preview with each row's
   own title (`evidence/views-list-editor-bound.png`).
4. Publish; the anonymous live page renders all rows (no preview cap), each
   with its row's value (`evidence/views-list-live.png`).

Client-side integration facts learned the hard way, recorded for the real
module:

- An instance only appears in the client model when its source
  `requiresExplicitInput()`; without a model entry, selecting the instance
  crashes the editor (`ComponentTreeItemList::buildLayoutAndModel()` gates on
  it).
- Do not return `propSources` from `getClientSideInfo()` for a source whose
  props are not prop-source-backed: the client then routes it through the
  evaluated-model path and reads `model.source`, which does not exist.
- Static props arrive collapsed (bare scalar) or expanded (array with `value`);
  per-row substitution must handle both.
- The editor's preview slot drop-zones come from
  `<!-- canvas-slot-start-{uuid}/{slot} -->` comment markers; emitting them
  around the first repetition (or the empty-slot placeholder div) is all a
  source must do for slot DnD and the layers tree to work.
- The instance form must read the auto-save draft, not the saved entity, to see
  children the user just dragged in.

One automation caveat: moving an already-placed component into the slot was
done by editing the auto-save draft server-side, because dnd-kit did not accept
this harness's synthetic pointer sequences. Inserting, selecting, binding,
previewing, and publishing all happened through the real editor UI; component
drag-and-drop works for human pointers.

## Fifth iteration: the MVP of the final model (canvas_views)

The converged architecture, built and validated end to end:

- **A view is a query.** `canvas_views` adds the query-only `canvas` display
  type (extends core's Embed); the MVP view has no page and no block display.
  Its field handlers declare the fields.
- **The display is a config entity** (`canvas_views_display`): view reference,
  component tree, explicit `mappings` (component uuid -> prop -> views field).
- **Designed in the Canvas editor** through the *generic* editor route with
  **zero client changes**, enabled by the core `PreviewRenderableInterface`
  (lauriii/canvas#102): the entity builds its own preview - the tree rendered
  once per result row, editing annotations on the first repetition. Insert
  from the Library updates all repetitions live; publish goes through the
  standard review panel, where the entity appears grouped under its type.
- **Each display is a component** (`views_display` source, one per entity),
  placed on a page from the Library and published; the anonymous page renders
  all rows with per-row mapped values (`evidence/careers-live.png`,
  `evidence/display-editor.png`, `evidence/display-admin-form.png`).

Core changes needed for all of this (PR #102): one interface, two
`instanceof` branch replacements, an OpenAPI parameter widening, and a
graceful empty content-entity form for non-fieldable entities. Everything
else is the contrib module.

MVP stand-ins, stated: mappings are edited on the entity form (the real
gesture is the props panel, list-builder Phase B); string props only; no
pager; per-row output not render cached; the Templates panel does not yet
list views (navigate via the admin form's "Design in Canvas" link).

## Sixth iteration: field mappings become real prop sources

The owner's correction: mappings must use prop sources. The `mappings`
side-table on the display entity is gone; a mapped prop now stores

    {"sourceType": "list-field", "field": "title"}

in the component's inputs, like every other binding. Core (on the
feat/template-host-mvp branch) adds `ListFieldPropSource` plus the
`ListFieldContext` service: the display pushes each row's declared field
values (its view's rendered field handlers) around each row's render, and
the source resolves through the normal PropSource evaluation pipeline with
the frame's cacheability. Validation treats a list-field prop as carrying
the component's default value, since the per-row value is the renderer's
guarantee.

Verified end to end: stored representation in config, per-row live and
preview resolution, the admin form reading and writing the tree sources
(unmapping restores the component's static default), the client model
carrying {source: {sourceType: list-field}, resolved: null} without errors,
and an editor edit + publish round-trip preserving the source untouched —
the same passthrough entity field sources rely on.

## Seventh iteration: mapping happens in the editor UI

The owner's correction: "I should be able to map using the UI." The admin
form's mappings section is now the secondary surface; the primary gesture
is the props panel's link control, the same one content templates use.

The display entity implements core's new `ListFieldsProviderInterface`,
declaring one field per views field handler (`getDeclaredListFields()`).
Core's component instance form offers those as link suggestions on every
string-shaped prop; picking one writes the list-field source into the
model, unlinking restores the static widget with the component default.
Two content-template assumptions in core had to generalize: the linked/
linkable form machinery keyed off `ContentTemplate` (now keyed off
suggestions being present, with a nullable host data definition), and the
client's `_linkPropToEntityValue` hardcoded the content-template PATCH URL
(now uses the live editor frame context).

Verified in the editor: link, unlink, and relink from the dropdown; the
badge reads "title (per item)"; the preview swaps static defaults for
per-row titles on link; the published tree stores the list-field source;
anonymous render shows per-row values.

Follow-up hardening from review: `list-field` is forbidden by default in
every Canvas tree (content entities, patterns, content templates) and the
display's schema opts in by overriding `ComponentTreeMeetRequirements` —
the same idiom content templates use for `entity-field`. Verified: a
pattern or page storing a list-field source is rejected with "The
'list-field' prop source type must be absent"; the display validates
clean.
