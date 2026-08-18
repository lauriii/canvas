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
