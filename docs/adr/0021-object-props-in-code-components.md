# 21. Code components: developer-defined object props ("groups") composed from existing prop shapes

Date: 2026-07-15

## Status

Accepted

## Context

Code component developers cannot define props that group related data: an image with a caption, an address, an
ingredient with amount and unit. Canvas already supports object-shaped props internally, but only for a hardcoded
allow-list of three `$ref` shapes (`image`, `video`, `content-entity-reference` — see
[ADR 0011](0011-content-entity-reference-props-in-code-components.md) for the precedent of a developer-facing object
prop type). Arbitrary `type: object` props were rejected in two places:

1. The config schema: `canvas.json_schema.prop_shape.object` required `$ref` and had no `properties` key, so a
   `js_component` config entity could not even store an inline object prop.
2. The storable shape computation: `JsonSchemaType::computeStorablePropShape()` returned NULL for any object without a
   recognized `$ref`, which made `ComponentMetadataRequirementsChecker` flag the component as unsupported.

Developers were forced to flatten grouped data into many top-level props, which clutters the props form and loses the
semantic grouping in the component's API. The headline use cases — recipe ingredients, carousel slides, author lists —
are inherently *lists* of groups, so multiple values must be expressible too.

Everything downstream already copes with one-level objects: `PropShape::normalizePropSchema()` recursively normalizes
`properties`, `FieldObjectPropsExpression` plus `Coalescer` map multiple entity fields onto one object prop (built for
content-entity-reference props, ADR 0011), `EntityFieldPropSourceMatcher::matchEntityPropsForObjectUsingScalars()`
matches object shapes via their scalar leaves, and the Astro island render path serializes plain object values as is.

## Decision

Allow code components to define custom `type: object` props ("groups") with arbitrary named sub-properties, at most one
level deep, composed from existing prop shapes:

1. **Compose existing shapes; do not invent a compound field type.** An arbitrary object prop is stored and edited as a
   composition of its sub-properties' `StorablePropShape`s: each sub-property resolves through the existing branches of
   `JsonSchemaType::computeStorablePropShape()` (scalar branches for scalars, the object branch for `$ref`
   sub-properties such as image), and a new composite prop source (`ObjectPropsSource`) wraps one conjured
   `StaticPropSource` per sub-property, evaluating to a single JSON object value. Every supported sub-property shape
   already has a field type, widget, client-side transforms, and validation; composition buys the whole widget
   ecosystem for free, including media library widgets for image sub-properties.
   - *Rejected alternative: a single compound field type per shape* (the image model via
     `FieldTypeObjectPropsExpression`): arbitrary shapes have no matching Drupal field type, and generating field types
     at runtime is far heavier than composing sources.
   - *Rejected alternative: a generic JSON/map field*: no usable widget, and it loses per-sub-property validation and
     transforms.
2. **One level of depth.** Sub-properties may use any existing prop shape except another inline object: scalars
   (string, integer, number, boolean, including enums and supported string formats), arrays of those scalars, and the
   well-known `$ref` shapes. String sub-properties are plain strings: `contentMediaType` is not supported inside
   `properties`. The depth limit is enforced in one validation constraint, so lifting it later is localized.
3. **Multi-value groups reuse the array branch.** A group with "allow multiple values" serializes as `type: array` with
   `items: {type: object, properties: ...}`. The existing array branch of `computeStorablePropShape()` resolves the
   item shape; the composite source carries a list of per-item value sets and evaluates to a JSON array of objects,
   preserving item order. Items are stored as explicit per-item structures; there is no cross-field delta alignment to
   corrupt.
4. **Additive config schema widening.** `canvas.json_schema.prop_shape.object` gains an optional `properties` mapping
   (and `required` sequence); `$ref` is required only when `properties` is absent. A validation constraint enforces
   exactly one of `$ref` or `properties`, and rejects inline `type: object` entries and `contentMediaType` inside
   `properties`. The existing three `$ref` shapes are untouched, so all existing exports stay valid.
5. **Field mapping reuses the content-entity-reference machinery.** Mapping multiple Drupal entity fields to the
   sub-properties of one object prop uses the existing `FieldObjectPropsExpression`, `Coalescer`, and
   `EntityFieldPropSourceMatcher::matchEntityPropsForObjectUsingScalars()`.

## Consequences

- Code component developers can define groups in the in-browser code editor (a "Group object" prop type with a
  nested-prop list), without a local toolchain.
- The component instance form renders a single-value group as one labeled section containing one widget per
  sub-property, and a multi-value group as an item list with per-item forms.
- `required` inside the object prop is supported: a required sub-property of an optional group is only enforced when
  any sub-property of that group (or, for multi-value groups, of that item) is populated. A fully empty optional group
  or item is valid.
- Because no existing site can have an arbitrary object prop (the config schema rejected them), this change is purely
  additive: no update path or `CanvasConfigUpdater` entry is needed.
- Out of scope, deliberately: groups inside groups (depth > 1), formatted text sub-properties, object props on SDCs
  beyond what structural matching against well-known shapes already provides, and site-wide reusable shape definitions.
