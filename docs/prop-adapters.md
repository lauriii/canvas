# Component prop adapters

Adapters are Canvas' no-code transformation layer between structured data and
component props. An adapter accepts one or more named inputs — each of which is
itself a prop source (a static value, an entity field expression, or another
adapter) — and produces a single output value assigned to a component prop.
Evaluation happens server-side through the regular prop source evaluation path
(`PropSource::parse()->evaluate()`), and the result carries the merged
cacheability metadata of every input.

Because adapted prop sources serialize into component tree `inputs`, which live
in exportable config entities (content templates, patterns, pages, page
regions, page variants), adapter configuration deploys through standard
configuration synchronization with no additional steps.

See [ADR 0020](adr/0020-component-prop-adapters.md) for the decision record and
[shape-matching](shape-matching.md) for how prop sources are matched to prop
shapes in general.

## The Phase 1 catalog

| Adapter | ID | Inputs | Output |
|---|---|---|---|
| Is set / not set | `is_set` | `value` (any), `negate` (boolean) | boolean: whether the value is non-empty |
| Date conversion | `format_date` | `date` (datetime string), `format` (string) | text: the formatted date |
| Prefix and suffix | `prefix_suffix` | `value` (any scalar), `prefix`, `suffix` (strings) | text |
| Fallback | `fallback` | `value`, `default` (mirror the prop shape) | the value, or the default when the value is empty |
| Equals | `equals` | `value`, `comparison` (any), `then`, `else` (mirror the prop shape), `negate` (boolean) | `then` when the value equals the comparison, `else` otherwise |
| Contains | `contains` | `text`, `needle` (strings), `position` (`contains`/`starts_with`/`ends_with`), `negate` (boolean), `then`, `else` (mirror the prop shape) | `then` when the text matches, `else` otherwise |
| Mapping | `mapping` | `value` (any), `cases` (JSON object as string), `default` (mirrors the prop shape) | the configured output for the matched case, or the default |
| Combine | `combine` | `text_1` … `text_10` (strings), `separator` (string, defaults to a space) | text: the non-empty inputs joined by the separator |

Notes:

- `format_date`'s `format` input is either the ID of a date format config
  entity (`short`, `medium`, `long`, or a custom one — localizable site
  configuration) or the special value `relative`, which renders phrases such
  as "2 days ago" or "in 3 hours" with time-bounded cacheability. Integer
  timestamps (e.g. a node's created/changed fields) are converted first via
  the pre-existing `unix_to_date` adapter — nested as the `date` input in
  stored trees. (The editor UI's chain steps are drawn from the adapters
  matching the targeted prop, so this particular chain is authored via the
  data model rather than the panel for now.)
- `equals` compares loosely on purpose: the compared value typically comes
  from a typed field (integer `0`) while the comparison is entered as text
  (`"0"`).
- `combine` skips empty inputs together with their separator, so combining a
  first name with an empty last name does not leave a dangling separator.
- `mapping`'s case/output table is stored as a JSON object inside a single
  static string input; the editor UI presents it as case/output rows.

## Parametric adapters

`fallback`, `equals`, `contains`, and `mapping` output "whatever the target
prop needs". Instead of a declared `output` schema, their `Adapter` attribute
declares `outputMirrorsInputs` — the names of the inputs whose shape mirrors
the output. Such adapters match any target prop shape
(`AdapterBase::matchesOutputSchema()` returns TRUE), and the prop-source
suggestion API binds the mirroring input slots to the targeted prop's shape.

An input declared with an empty schema (`[]`) accepts any value; no JSON
Schema validation is applied to it, and its suggested field candidates are the
union of fields matching the primitive shapes (string, integer, number,
boolean).

## Editor UI

In the content template editor, the prop picker (the link icon next to a prop
label) lists adapter suggestions in a "Transform" section after all direct
field matches. Selecting one opens the transform configuration panel:

- Each transform is a step; steps chain linearly (step N's primary input is
  step N-1's output). Composition is deliberately a linear list, not a
  free-form graph.
- Each input slot binds to a field (shape-matched candidates), a literal
  value, or the previous step.
- A live preview shows the evaluated output for the currently selected
  preview entity while configuring, debounced, via
  `POST /canvas/api/v0/ui/content_template/prop-source-preview/{entity_type_id}/{entity}`
  — the exact same evaluation code path used when rendering.

Applying writes an `AdaptedPropSource` into the component tree:

```json
{
  "sourceType": "adapter:equals",
  "adapterInputs": {
    "value": {"sourceType": "entity-field", "expression": "ℹ︎␜entity:node:article␝field_price␞␟value"},
    "comparison": {"sourceType": "static:field_item:string", "expression": "ℹ︎string␟value", "value": "0"},
    "then": {"sourceType": "static:field_item:string", "expression": "ℹ︎string␟value", "value": "Free"},
    "else": {"sourceType": "entity-field", "expression": "ℹ︎␜entity:node:article␝field_price_display␞␟value"}
  }
}
```

The flat `adapter` key on `EntityFieldPropSource` (a single-input shortcut,
e.g. the hard-coded created/changed → `unix_to_date` suggestions) remains
read-supported, but the UI always writes the nested form above.

## Adding an adapter

Create a plugin in `src/Plugin/Adapter/` with the `#[Adapter(...)]` attribute:
declare `inputs` (name → JSON schema; `[]` means any), `requiredInputs`, and
either `output` (a JSON schema) or `outputMirrorsInputs` (parametric). Extend
`AdapterBase` and implement `adapt()`. The plugin is picked up by
`AdapterManager` and offered automatically wherever its output shape matches;
no UI work is needed.
