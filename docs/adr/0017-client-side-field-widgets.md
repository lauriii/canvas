# 17. Client-side field widgets with a Drupal-widget escape hatch

Date: 2026-07-15

## Status

Accepted

## Context

Selecting a component instance in the Canvas editor opens its prop form. That form has always been server-built: the
client sends the instance's client model to a Form API endpoint
(`PATCH /canvas/api/v0/form/component-instance/...`), Drupal builds the configured field widget for every prop, and
the returned HTML is converted into React elements. The round trip costs on the order of 300 ms per selection on a
warm site — paid on *every* selection, even though the information needed to render the common widgets (each prop's
JSON schema, its configured widget id, its options, its cardinality) is already cached client-side from the component
config endpoint (`GET /canvas/api/v0/config/component`, which exposes each prop's configured widget as
`propSources[<prop>].field_widget`, sourced from the component's `prop_field_definitions` settings).

Two properties of the existing design constrain any change:

- **The server chooses the widget.** Every prop's editing UX is a per-prop configured Drupal field widget plugin id.
  Site builders can swap widgets, and contrib and custom modules provide their own. Whatever renders the form must
  respect that configuration, not re-derive widget selection from the schema.
- **The server validates the write.** The editor's write pipeline (model merge, source synchronization, debounced
  auto-save patch) already treats client validation as advisory UX; `clientModelToInput()` and
  `validateComponentInput()` on the server are the authority. That contract must not move.

Additionally, the Drupal widget ecosystem — `hook_form_alter()`, media library integrations (DAM connectors,
`media_library_edit`), custom widgets without any Canvas-specific code — must keep working, and the
selection-to-form path must never regress when custom code is absent, late, or broken.

## Decision

Render component prop forms natively in the editor by default, prop by prop, from cached metadata — with the
server-built Drupal widget as a per-prop escape hatch.

### Key client widgets by Drupal field widget plugin id

The server keeps choosing the widget for every prop, exactly as before. The client maintains a registry mapping
Drupal field widget plugin ids to client widget implementations; a prop whose configured `field_widget` id is
registered renders instantly as a typed React widget from cached metadata, with no form request. Keying on the
*widget id* — not the field type, and not the prop shape — has two consequences that are the point of the design:

- A site that overrides the widget configured for a prop changes the client rendering with it: widget-level
  configuration transfers to the native path automatically.
- A custom or contrib widget id with no registered client counterpart automatically renders through the escape
  hatch. Unknown widgets degrade to exactly the behavior they have today, never to a broken form.

The initial native widget set covers the standard Drupal widgets: `string_textfield`, `string_textarea`, `number`,
`boolean_checkbox`, `options_select` (the prop's `enum` values and `meta:enum` labels are delivered in the cached
metadata), `email_default`, `datetime_default`, `daterange_default`, `link_default`,
`entity_reference_autocomplete`, `image_image`, `file_generic`, and `media_library_widget`. Formatted text
(`text_textfield`, `text_textarea`) initially stays on the escape hatch; a native rich text widget is deliberate
follow-up work, not an oversight.

### Build a thin form layer rather than adopt a schema-driven form library

The native layer is a thin registry plus a per-prop slot over the UI stack the editor already ships: React, the
existing Radix-based input primitives, and ajv for client-side JSON Schema validation. Each client widget is a small
typed component; shared chrome (label, description, required indicator, error presentation) is rendered by the slot.

### The client widget contract

A client widget is registered per widget id as `{component, codec, validate?, isEligible?,
handlesMultipleValues?}`:

- `component` renders the input from the prop's cached metadata.
- `codec` maps between widget values and model values: `toModel` produces `{resolved, source?}` (or `null` for
  "empty, remove the prop"), `fromModel` derives the widget value from the stored model. Codecs replace the
  transforms registry one-for-one on the native path: a codec must persist the same model values the corresponding
  Drupal widget produces through its `canvas.transforms` metadata.
- `validate` adds widget-specific checks beyond the shared ajv pass; `isEligible` lets a widget decline a prop it
  cannot render natively (sending that prop to the hatch); `handlesMultipleValues` marks widgets that own their own
  multi-value UI.

Writes flow through the existing pipeline unchanged — resolved merge, prop source synchronization, the client-side
preview update event, the debounced auto-save patch. The server remains authoritative: `clientModelToInput()` and
`validateComponentInput()` are untouched.

### The scoped-form escape hatch is a permanent compatibility API

The form endpoint accepts an optional `form_canvas_props_filter` parameter that scopes the Form API build to the
listed props. Props whose widget id is unregistered, disabled, or ineligible render as per-prop server-form
"islands" composed in prop order among the native widgets. The whole-form server path remains for component sources
that *are* Form API forms (Blocks, Personalization, Fallback) and for content templates. The hatch is not a
transitional shim to be removed later: it is the compatibility contract that lets every configured Drupal widget
work forever without client-side code.

For the same reason, the `canvas.transforms` metadata requirement on widget plugins is deliberately kept: it is what
keeps the hatch — and the kill switch below — fully functional for every configured widget.

### Fully client-side media, files, and references

Reference-shaped widgets get dedicated scoped endpoints so their common flows never pay for a form build:

- `GET /canvas/api/v0/autocomplete?component=&version=&prop=&q=` — entity reference autocomplete; the server derives
  the target entity type and bundles from the prop definition, so the client never restates them.
- `POST /canvas/api/v0/file/upload` — file uploads for `file_generic`.
- `GET /canvas/api/v0/media/{media_type}?search=&page=&ids=` — media browsing for the native media widget, alongside
  the existing `POST /canvas/api/v0/media/{media_type}/upload`.

### Formatted text: editor UI from the text format configuration

The formatted text widgets (`text_textfield`, `text_textarea`, `text_textarea_with_summary`) are native, with the
editing UI derived entirely from the text format configuration rather than any client-side hardcoding:

- `GET /canvas/api/v0/text-editor-settings` delivers, for every format the current user may use, the same editor
  settings and asset libraries the editor module attaches to a server-built `text_format` element (computed by
  `EditorManager::getAttachments()`, delivered through the `canvas_template` response shape and loaded by the
  existing `processResponseAssets` pipeline, which deduplicates against assets already on the page — including any
  the escape hatch loaded). Fetched once per session; formats the user cannot use are never exposed.
- The lightweight permitted-format list (id, label, editor plugin id) ships with the editor boot settings so the
  native-or-hatch decision stays synchronous at render time.
- CKEditor 5 mounts through a shared host component used by both the native widget and the escape hatch, so the two
  paths cannot double-initialize or race on the `window.CKEditor5` globals. CKEditor 5 plugin builds remain Drupal
  asset libraries: contrib CKEditor 5 plugins keep working without a UI rebuild.
- Boundary: any format configured with a non-CKEditor-5 editor plugin sends the prop to the escape hatch, where that
  editor's attach pipeline works unchanged. Formats without an editor render the plain input natively.
- The raw editor markup is only the optimistic resolved value; the server's filter processing (`processed`) remains
  authoritative on the patch echo, and format use permission stays enforced server-side.

### A public, override-capable registry with a hard non-blocking invariant

Registration is public architecture: registering an id that already has a widget replaces it. After the default
registrations, a DOM `CustomEvent` (`canvas:register-client-widgets`) exposes the registration surface to in-tree
consumers, guaranteed to fire after the defaults so overriding a default id is ordering-safe. Resolution at render
time is a synchronous map lookup.

The invariant every current and future consumer must preserve: **the selection-to-form path never waits on
registration.** Late or absent registration can only send props to the escape hatch; it can never delay or block the
form.

### Kill switch and per-widget disable

Two keys in the `canvas.settings` simple configuration, delivered to the editor as
`drupalSettings.canvas.propForms`:

- `prop_forms.native` — when `FALSE`, every component prop form is server-built site-wide, restoring
  `hook_form_alter()` effects and media library contrib integrations in full.
- `prop_forms.disabled_widgets` — widget plugin ids whose props render through the hatch while all other props stay
  native (for example, `media_library_widget` on sites whose DAM integration alters the media library).

### Declarative prop states (`x-canvas-states`)

A prop's schema may declare `x-canvas-states`: a list of `{effect, when}` rules where `effect` is `visible` or
`enabled` and `when` is a JSON Schema evaluated (with ajv) against the sibling props' resolved values. Rules are
evaluated client-side at the prop slot, uniformly for native widgets and hatch islands. A hidden prop keeps its
model value. Conditional `required` is deliberately deferred: it needs client/server validation agreement.

This is the supported replacement for cross-prop `#states` use cases: a `#states` dependency injected by an alter
cannot span the boundary between a native widget and a hatch island (they are different DOM worlds), while within a
single island — or under the kill switch — `#states` keeps working as before.

### Deferred: contrib delivery of client widgets

Contrib modules cannot yet ship client widgets: the extensions mechanism
([ADR-0009](0009-extensions-api-architecture.md)) sandboxes extensions in iframes, which is the wrong delivery
channel for same-document React components. A follow-up ("contrib-client-widgets") must design that channel — a
shared React runtime and a versioned contract package — and whatever shape it takes, it must preserve the
invariants above: synchronous render-time resolution, hatch fallback for unregistered ids, and a selection path that
never waits on registration.

## Alternatives considered

- **Adopt a schema-driven form generation library (react-jsonschema-form, JSON Forms, uniforms).** These derive both
  widget selection and layout from JSON Schema. Rejected: widget selection is a decision Drupal already makes per
  prop through configuration, and a schema-driven generator would duplicate — and inevitably contradict — it;
  restyling a generator's output to the editor's design system costs more than writing thin widgets against the
  primitives the editor already has; and each brings a dependency tree that outweighs the code it saves.
- **Key client widgets on field type or prop shape instead of widget id.** Rejected: it discards site configuration
  (two props with the same shape may be configured with different widgets for good reasons) and removes the
  automatic hatch routing for custom widget ids, which is what makes the design safe by default.
- **Keep server-built forms and optimize them (caching, prefetching).** Rejected: form builds depend on the
  instance's current input values, so cached HTML goes stale on every edit; prefetching all candidate forms is
  O(components) requests per page; and it does nothing for the media and reference flows, which need scoped data
  endpoints regardless.
- **Drop the `canvas.transforms` requirement for widgets with native counterparts.** Rejected: the kill switch and
  the escape hatch must work for *every* configured widget at any moment, and both depend on transforms. Keeping the
  requirement also preserves a single authoritative definition of each widget's persisted model — the parity oracle
  for codecs.

## Consequences

- Prop forms for components using only standard widgets render with zero form requests. Interactivity is measured
  (`canvas:selection-to-form-interactive`, with `native` versus `server-form` path detail) against the roughly
  300 ms server-form baseline, with a target under 100 ms p95 warm. The deterministic CI guard is the
  zero-form-request assertion for standard-widget components; latency itself is a monitored metric, not a
  per-commit gate.
- **Breaking on the native path:** `hook_form_alter()` implementations targeting the component instance form, and
  media library contrib integrations (DAM connectors, `media_library_edit`), no longer apply to natively rendered
  props. They still apply to escape-hatch props and to whole-form sources. The kill switch restores prior behavior
  site-wide; `prop_forms.disabled_widgets` restores it per widget.
- Cross-prop `#states` spanning the native/hatch boundary cannot work; `x-canvas-states` is the supported
  vocabulary for conditional visibility and enablement.
- Every native widget carries a standing parity obligation: its codec must persist exactly the model the Drupal
  widget persists. The widget-to-transforms map is the checklist and oracle; codec drift is a new bug class that
  needs parity testing (edit natively, edit via the kill-switch server form, diff the persisted model).
- Custom and contrib widgets are never broken — their worst case is today's behavior via the hatch — but they cannot
  yet be made native by contrib. That pressure is deliberate and bounded by the deferred delivery work.
- Two render paths (native and server-form) and their per-prop composition must be maintained and tested together.
