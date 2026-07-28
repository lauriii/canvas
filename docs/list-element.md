# The List element

The List element is a dynamic component: its rendered output is the result of
a stored content query. Editors drag it onto pages, content type templates,
any slot, and global regions, exactly like other Canvas components, and
configure it entirely from the settings panel.

See [ADR 0020](adr/0020-list-element-component-source-with-constrained-query-dsl.md)
for the architectural decision (a `list` component source plus a constrained
query DSL; Views adoption evaluated and rejected for instance-level settings).

## Settings

All settings are stored per instance in the component tree's `inputs` blob,
validated by `\Drupal\canvas\ListBuilder\ListElementSettingsValidator`:

- **Content source**: one control with two groups — a content type (bundle),
  or a multi-value field of the entity the tree is rendered for (see
  [Field sources](#field-sources)). The stored settings record which kind is
  in use, so future source kinds are additive. For a content query the stored
  settings record the entity type as well as the bundle, so sources other than
  nodes can be added later without a storage change. The selected source
  drives which fields the filters and sorts offer and which view modes are
  available.
- **Item display**: a view mode of the source bundle, the built-in
  "Title (linked)" display (the label linked to the entity, so the element
  works with zero site building), or the component-built item template (see
  below). The settings panel links to the manage-display administration for
  creating or editing view modes.
- **Number of items**: a maximum item count, or no limit. Lists without a
  limit always use infinite scroll, so unbounded result sets are never loaded
  at once.
- **Pagination**: none, a load more button (plain markup, targetable by
  global styles), or infinite scroll, with a configurable page size.
- **Filters**: field conditions combined with match-all (AND) or match-any
  (OR). Operators are constrained per the field's type family; unknown field
  types degrade to the is set / is not set pair. Filters are editor-facing
  configuration only.
- **Sorting**: multiple sorts applied in the order listed, with directions
  labeled per field type (dates old-to-new, text A-to-Z, numbers
  low-to-high).
- **Layout**: stack (spacing, distribution, horizontal alignment), row
  (spacing, items per row), or grid (spacing, maximum items per row).

## Query semantics

`\Drupal\canvas\ListBuilder\ListQueryExecutor` translates the settings to an
entity query with access checks enabled and a current-content-language (plus
language-neutral) condition. Queries are always ranged; more-pages detection
fetches one row beyond the window instead of running count queries. List
renders carry the bundle-scoped list cache tag (`node_list:<bundle>`), the
`languages:language_content` and `user.permissions` cache contexts, and the
cache metadata bubbled from item renders, so content changes invalidate lists
through Drupal's cache system without max-age expiry.

Subsequent pages are served by `/canvas/list-element/{entity_type}/{entity}/{component_instance_uuid}`,
which accepts only the list's identity and an offset: every query-shaping
setting is read from the stored, validated inputs, so the endpoint cannot be
coerced into arbitrary queries. Responses are server-rendered item markup
with full cache metadata, cacheable per offset for anonymous visitors.
Behaviors are attached to appended items, but the endpoint does not deliver
incremental asset libraries: later pages render the same view mode or item
template as the first page, which attached the assets with the initial
render. A library that only an item-conditional formatter on a later page
needs is the accepted edge case; it would require the full Drupal AJAX
pipeline on otherwise asset-minimal published pages.

## Component-built item displays (item template)

Switching the item display to "Components (item template)" reveals the
`item_template` slot. Components dragged into it form a template that renders
once per result, with that result's entity bound as the data context: entity
field prop expressions inside the template resolve against the iterated
entity, and the prop linker offers the source bundle's fields (the same shape
matching as content templates). The template subtree appears once in the
layers panel; its components are individually selectable and editable.

Under the hood the `item_template` slot is a *deferred slot*
(`\Drupal\canvas\ComponentSource\ComponentSourceWithDeferredSlotsInterface`):
its subtree is excluded from regular hydration and rendered by the List
source itself, bound per repetition. Validation and the editor's data model
use a representative entity of the source bundle as the context for template
components.

## Field sources

A List's source may be a field of the tree's host entity instead of a content
query: several images, several tags, several referenced entities. Its items
are that field's values, in delta order — the order the content editor
arranged them in. Single-cardinality fields are not offered: one value is not
a list, and mapping the field straight to a component prop already covers it.

A field source needs a host entity that has the field, so it is offered only
where the tree has a bundle-specific entity context — a content template —
and validation rejects it elsewhere, naming the reason.

Filters, sorts, and pagination shape a query and are hidden (not disabled) for
a field source: a field's values are host entity data, and there is nothing to
query. The maximum item count, the layout settings, the item template, and the
empty and misconfigured states behave identically for both source kinds. The
item window is applied to the field's values *before* any item renders, so a
value outside it is never built.

### Two data contexts in one item template

Inside a field-sourced item template two data contexts coexist, and which one
a prop reads from is a property of its prop source class, not of where it sits
in the tree:

- an **item prop source** (`\Drupal\canvas\PropSource\ItemPropSource`) stores a
  field-item-rooted expression and resolves against the value being rendered.
  Shape matching offers the iterated field's own item properties — an image
  field's `alt`, `title`, `width`, `height` — plus, through the reference, the
  fields of the entity a reference item points at.
- an **entity field prop source** keeps resolving against the tree's host
  entity, so a card can combine "this image's caption" with "this page's
  title".

A stored item expression never contains a delta: the template does not know
which delta it is rendering, the item does. That is what keeps a template
subtree valid as the host entity gains and loses values.

The per-item context is the field *item*, not the entity a reference item
points at, because an image field's `alt` lives on the field item and not on
the referenced `file`. See
[ADR 0021](adr/0021-item-template-data-context-is-a-field-item.md).

A field-sourced item template needs no representative sample entity: the host
entity is the real one, in the editor and on the live site alike. An empty
field therefore shows the empty placeholder rather than sample values.

Multi-value support for code components' `content-entity-reference` props is a
different surface and is tracked separately in
[#3589536](https://www.drupal.org/i/3589536); it is not covered here.

## Empty and error states

A list whose query matches nothing — or whose source field holds no values —
renders an empty layout container on the published page and a labeled
placeholder in the editor preview. A misconfigured list (removed content type,
removed filter field, or a source field no longer on the bundle) renders
nothing on the published page and shows a warning state in the preview. When the editor
changes the content source, conditions and sorts referencing fields the new
bundle lacks are dropped, with an inline warning naming what was removed.

## When to use Views instead

The List element intentionally covers the common cases only. Complex lists —
relationships, contextual filters, aggregation, exposed visitor-facing
filters, multi-source listings — remain the domain of Views: expose a View as
a block and place it through the block component source.
