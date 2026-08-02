# 21. An item template's data context is a field item, not only an entity

Date: 2026-07-28

## Status

Accepted

## Context

[ADR 0020](0020-list-element-component-source-with-constrained-query-dsl.md) introduced the List element and its
`item_template` slot: a deferred slot whose subtree the source renders itself, once per item, binding that item as the
data context. Its only source is an entity query, so an item is always a whole content entity, and
`ComponentSourceWithDeferredSlotsInterface::getDeferredSlotContextEntity()` returns a `FieldableEntityInterface`.

Canvas now also needs a multi-value field of the tree's host entity as a source for that same item template: several
images, several tags, several referenced entities. The question is what one iteration binds.

Options evaluated:

1. **Restrict field sources to entity reference fields, and bind the referenced entity.** Every item is an entity, so
   the existing mechanism is reused unchanged. But it is wrong for the most common case: Drupal's `image` field is an
   entity reference to `file`, and `alt`, `title`, `width` and `height` live on the *field item*, not on the `file`
   entity. Binding the `file` would make an image gallery unable to render alt text — an accessibility regression, not
   a missing nicety. It also excludes plain text, link, date and number fields entirely, which are exactly the "several
   tags", "several links", "several dates" cases.
2. **Bind the host entity with a bound delta**, so expressions inside the template resolve `field_x[delta]`.
   Expressions stay entity-rooted and the existing `EntityFieldPropSourceMatcher` is reused. But the bound delta is
   ambient state every entity-rooted expression would have to consult, including expressions targeting *other* fields,
   where a delta is meaningless. It makes the meaning of a stored expression depend on where in the tree it sits, which
   is precisely the property that makes prop expressions debuggable today.
3. **Bind the field item.** The per-item context is a `FieldItemInterface`.

## Decision

Bind the field item.

`ComponentSourceWithDeferredSlotsInterface::getDeferredSlotContextEntity(array $explicit_input)` widens to
`getDeferredSlotContext(array $explicit_input, ?FieldableEntityInterface $host_entity = NULL): FieldableEntityInterface|FieldItemInterface|null`.
A source returning an entity replaces the tree's host entity for its subtree, as the query source already does. A source
returning a field item does *not* replace the host entity: the item sits beside it.

Two prop source types therefore coexist inside one field-sourced item template, and which context each resolves against
is a property of its class, not of its position:

- `EntityFieldPropSource` stores an entity-rooted expression and keeps resolving against the tree's host entity, so a
  card can combine "this image's caption" with "this page's title".
- `ItemPropSource` stores a field-item-rooted expression and resolves against the current item.

A stored item expression never contains a delta. The template does not know which delta it is rendering; the item does.
That is what keeps a template subtree valid as the host entity gains and loses values.

## Consequences

The field item is the correct granularity for every field type, reference or not, and it costs no new evaluation
machinery: `Evaluator::evaluate()` already accepts `EntityInterface|FieldItemInterface|FieldItemListInterface`, and
Canvas already has a field-item-rooted expression family — `FieldTypePropExpression`, `FieldTypeObjectPropsExpression`,
`ReferenceFieldTypePropExpression` — that static prop sources use. Reaching a referenced entity is expressible through
`ReferenceFieldTypePropExpression`, so option 1's capability is a subset of this one.

The costs:

- An interface the tree layer depends on changes shape. It is `@internal` and has exactly one implementer, which the
  same change owns. Widening the return type is a smaller change than adding a second method that callers would have to
  learn when to call.
- Shape matching needs a matcher rooted at a field item (`ItemPropSourceMatcher`), which did not exist:
  `src/ShapeMatcher/` had entity-field, adapted, host-entity and host-entity-URL matchers only. It duplicates a small
  amount of traversal logic from `EntityFieldPropSourceMatcher`; extracting the shared part is the right move only once
  a second matcher has proved the shape.
- Switch-case negotiation gains an ambient context argument
  (`ComponentSourceWithSwitchCasesInterface::isNegotiatedCase()`), because a case placed inside an item template must be
  able to see which item is being rendered. `canvas_personalization` ignores it, so its behavior does not change.
- A field-sourced item template needs no representative sample entity: the host entity is the real one, in the editor
  and on the live site alike. The sample-entity path stays query-source-only.

One level of field iteration only. A field of the entity a reference item points at is out of scope; the expression
family supports one level of reference traversal, and a second iteration level would need a List inside an item
template with a source relative to the item, which the source kinds do not express.
