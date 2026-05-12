# 11. Code components: new "content-entity-reference" prop type to enable "view modes" that combine multiple entities plus static inputs

Date: 2026-04-22

Issue: <https://www.drupal.org/project/canvas/issues/3573831>

## Status

Accepted

## Context

Currently, when creating content that needs to combine one-off information with structured data by referencing existing
entities (such as products, articles, or other content), Content Authors face two suboptimal alternatives:
1. they can use *automated lists* (powered by JSON:API) that pull entity data
   but don't allow manual content curation within Canvas (see [in-progress ADR](https://www.drupal.org/project/canvas/issues/3519737))
2. or they must manually recreate/copy entity information into Canvas components to maintain creative control.

These types of pages are often created to create navigational pages.

Lists solve the data duplication problem but eliminate the flexibility that makes Canvas powerful: the ability to
customize content presentation. Manual recreation preserves Canvas flexibility but creates friction, data inconsistency,
and maintenance overhead.

Ideally, Content Authors would have the best of both worlds: manually select and curate specific entities within Canvas'
code components, and provide one-off static information as needed (e.g. a custom headline, call to action, or
introductory text) while still benefiting from live data connections to those entities.
This would preserve Canvas's core strength of creative control and manual content arrangement while eliminating data
duplication and maintenance overhead. Content Creators would be able to handpick exactly which products, articles, or
other entities to feature, arrange them as desired within their Canvas layout, and ensure that the underlying data
(prices, titles, images, etc.) stay automatically synchronized. The result would be faster content creation, maintained
creative flexibility, improved data accuracy, and reduced long-term maintenance burden.

## Decision

Introduce the "content-entity-reference" prop type for code components, allowing Content Authors to directly reference
existing entities (e.g., products) within Canvas.

This allows:
- Referencing existing product entities directly in a code component (or in the case for a content template for product
  entities: allow the host entity to be linked to a code component's "content-entity-reference" prop) so that price
  updates, new images, and product details automatically reflect in this alternative representation ("liftup") without
  manual updates
- In content templates, displaying an existing "Related Articles" entity reference field using an "Article Card" code
  component which has a "content-entity-reference" prop targeting Articles. ⚠️ This would be similar to a "Card" view mode and
  entity view display, but with the added flexibility of a code component to allow for additional static content (e.g.
  a custom headline, call to action, or introductory text).
- Even _combining_ multiple entity types (e.g. article nodes and users, products and image media …) in a single code
  component, to allow consistent "combined" or "synthesized" presentations of structured data. With of course still the
  same added flexibility.

## For Code Component Developers

1. Each code component can define as many "content-entity-reference" props as desired, and for each, the Code Component
   Developer can select which (content) entity type and bundle, and subsequently which fields, they wish their code
   component to receive.
2. Every "content-entity-reference" prop results in an object-shaped prop with key-value pairs determined by the data that is
   selected by the code component developer. For example: they might choose to reference article nodes, and then pick the
   "title" and "image" fields (perhaps using a different UI, but using the same nested menu structure as how component
   instances in a content template can have their component props linked to structured data).
3. The Code Component Developer MUST account for the fact that they may not receive an object, but null (and for multiple
   cardinality later: an empty array) — either when no entity is referenced yet, OR the referenced entity has been deleted,
   OR on a content template the entity reference field may be optional, OR on a content template the entity reference field
   may be required but nodes were created before it was required.
4. Similarly, the Code Component Developer SHOULD also account for the fact that requested fields on the object may not be
   present (for example when the field values are not accessible or have been deleted), or may not be in the type or shape
   they expect (for example when the field type has been changed).

At runtime, `JavaScriptComponent::toSdcDefinition()` **projects** the concrete target entity type (and bundle, where applicable)
from `dataDependencies.entityFields` into the SDC definition's `props`. The developer-facing `props` stays abstract (no
`x-allowed-entity-type-id` / `x-allowed-bundle`); the projected SDC definition consumed by the renderer carries them. The
persisted JavaScriptComponent config entity is never mutated by the projection.

Note that for SDCs, in principle the same functionality is possible, but that is considered out of scope of this ADR.

## Consequences

1. some code components may not be importable into certain sites, because they now may depend on the site's data model —
   which has both downsides (less reuse) and upsides (richer use)
2. some code components may be importable into a site, because the needed configurable fields exist, but they may have
   been configured _differently_: different storage settings, instance settings or even a different field type
3. code components deviate further from SDCs: after prior additions (dependencies on URLs and `drupalSettings`), this
   adds a third type of superset, and is the first (and only) type of prop that is not supported in SDCs
4. this may reduce the adoption of Drupal view modes and entity view displays: a site may need fewer of these, because
   the same need is fulfilled by code components with "content-entity-reference" props
5. this adds a capability that has long been missing from Drupal: combining multiple entity types into a single "view
   mode": for example, a "Product Card" that combines product entity data with media entity data (e.g. product image)
