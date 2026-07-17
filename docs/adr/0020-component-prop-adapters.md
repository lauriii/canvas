# 20. Component prop adapters: expose the dormant adapter layer via parametric matching and server-evaluated preview

Date: 2026-07-17

Issue: <https://www.drupal.org/project/canvas/issues/3464003>

## Status

Accepted

## Context

Component props bind to structured data only when a field's shape matches the
prop's shape exactly, so any display logic (formatting a date, combining first
and last name, showing "Free" instead of "$0.00") had to be baked into each
component, coupling components to one content model.

Canvas already shipped the complete back-end plumbing for transforms:
`AdaptedPropSource` (multi-input evaluation, chaining, cacheability
propagation, dependency calculation), the `Adapter` plugin system, and config
export of adapted sources inside component tree inputs. But the layer was
dormant: `PropSourceSuggester::structureSuggestionsForResponse()` computed
adapter matches and then dropped them, `AdaptedPropSource` was not
`LinkablePropSourceInterface`, no endpoint evaluated a candidate source for
preview, and only five adapters existed (two date, three image). Adapter
matching required an exact declared output schema, which cannot express
adapters whose output is "whatever the target prop needs" (fallback,
conditionals).

An alternative — a new "formatter" layer or client-side transforms in
JavaScript — was evaluated and rejected: it would duplicate evaluation logic
in the browser, break server rendering parity, and bypass the existing
shape-matching machinery.

## Decision

Build on the existing Adapter plugin system (adopt, not build):

1. **Phase 1 catalog as eight small plugins**: `is_set`, `format_date`,
   `prefix_suffix`, `fallback`, `equals`, `contains`, `mapping`, `combine`.
   `equals`/`contains` model if/then/else (their `then`/`else` inputs are prop
   sources themselves); `mapping` is the multi-case switch; `combine` joins up
   to ten text inputs, skipping empty ones.
2. **Parametric output schemas instead of plugin derivers**: an adapter may
   declare `outputMirrorsInputs: ['then', 'else']` (instead of `output`) in
   its `Adapter` attribute, meaning its output shape mirrors those inputs.
   Such adapters match *any* target prop shape; the suggestion API binds the
   mirroring inputs to the targeted prop's shape. Derivers enumerating one
   plugin variant per prop shape were rejected: they would enumerate an
   open-ended shape space and bloat the plugin list.
3. **"Any"-shaped inputs**: an input declared with an empty (`[]`) schema
   accepts any value (no JSON Schema validation); its field candidates are
   the union of fields matching the primitive shapes (string, integer,
   number, boolean).
4. **Extend the existing suggestions response, no catalog endpoint**: adapter
   suggestions are appended after all direct matches, each carrying id,
   label, and per-input slots (schema, required, mirrorsOutput, field
   candidates, and a `StaticPropSource` template for literal values). The
   client never needs adapters detached from a target prop shape.
5. **Server-evaluated preview endpoint**
   (`POST /canvas/api/v0/ui/content_template/prop-source-preview/{entity_type_id}/{entity}`):
   evaluates a candidate prop source through `PropSource::parse()->evaluate()`
   against a host entity. One source of truth for transform semantics; no
   JavaScript reimplementation.
6. **The UI writes the nested `adapter:<id>` form uniformly**, even for
   single-input transforms that could use the flat `EntityFieldPropSource`
   `adapter` key (which remains read-supported). Multiple transforms on one
   prop are presented as a linear list of steps and serialized as nested
   `AdaptedPropSource`s; arbitrary graphs stay a data-model capability, not a
   UI surface.
7. **`AdaptedPropSource` implements `LinkablePropSourceInterface`**, so an
   adapted prop renders as a "linked" prop in the component instance form,
   with a label contextualized by its first linkable input (e.g. "Date
   conversion: Authored on").
8. **No storage or config-schema structure changes**: adapted prop sources
   already serialize into component tree inputs, which live in exportable
   config entities, so deployability comes for free. One validation change
   was required: the `ComponentTreeMeetRequirements` constraint on content
   template component trees no longer lists `adapter` as a forbidden prop
   source type. Other config-owned trees (patterns, page regions) continue
   to forbid it, because they also forbid the `entity-field` sources that
   adapter inputs may nest.

Resolved design questions:

- Absolute date formats use Drupal date format config entities (already
  config, localizable); relative display is the special `format` value
  `relative`, rendered via `DateFormatterInterface::formatTimeDiffSince()`/
  `formatTimeDiffUntil()` with granularity 1 (e.g. "2 days ago", "in 3
  hours") and carrying the `FormattedDateDiff` cacheability (finite max-age).
- `combine` skips empty inputs together with their separator.
- The preview evaluates against the preview entity already selected in the
  content template editor (the same entity used for the canvas preview).
- The `mapping` adapter's variable-length case/output table is serialized as
  a JSON object inside a single static string input (`cases`); the editor UI
  presents it as rows. This avoids inventing a new prop source kind for
  maps.

## Consequences

- Easier: no-code display logic in templates; several CMS fields feeding one
  prop; conditional then/else values; deployable transforms with zero schema
  work.
- Suggestion noise: parametric adapters match every prop, and text-output
  adapters match every plain-text prop. Mitigated by ranking direct field
  matches first and grouping adapter suggestions behind a separate
  "transform" affordance in the prop picker.
- Suggestion responses grow: each adapter suggestion embeds per-slot field
  candidates. Mitigated by memoizing candidate computation per shape within a
  request.
- The preview endpoint adds server load while configuring. Mitigated by
  client-side debouncing and evaluating only the single prop being edited.
- Rollback caveat: content saved with new adapter IDs fails to parse if the
  module is downgraded; standard module-downgrade caveat, documented.
- `ComponentInputs::getPropSourcesUsingExpressionClass()` now recurses into
  adapter inputs (it previously threw once adapted sources were "actually
  used").
