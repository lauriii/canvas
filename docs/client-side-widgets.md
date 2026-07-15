# Client-side widgets

This guide explains how to author, register, and test client-side widgets for the Canvas component prop form: the
native render path introduced by [ADR-0017](adr/0017-client-side-field-widgets.md).

By default, each prop of a selected component renders as a typed React widget resolved from the prop's configured
Drupal field widget plugin id — no server form request involved. Props whose widget id has no registered client
counterpart render via the server-built Drupal widget instead (the escape hatch, documented in
[Redux-integrated field widgets](redux-integrated-field-widgets.md)). See
[Component instance and page data forms](component-and-entity-forms.md) for how the two paths compose.

Key source files:

- [`ui/src/components/form/widgets/types.ts`](../ui/src/components/form/widgets/types.ts) — the typed contract.
- [`ui/src/components/form/widgets/registry.ts`](../ui/src/components/form/widgets/registry.ts) — registration and
  resolution.
- [`ui/src/components/form/widgets/registerDefaultWidgets.ts`](../ui/src/components/form/widgets/registerDefaultWidgets.ts)
  — Canvas's own registrations and the registration event.
- [`ui/src/components/form/widgets/NativePropSlot.tsx`](../ui/src/components/form/widgets/NativePropSlot.tsx) — the
  per-prop slot: shared chrome, validation, and the write pipeline.
- [`ui/src/components/form/widgets/useNativePropWrite.ts`](../ui/src/components/form/widgets/useNativePropWrite.ts)
  — the model write path.
- [`ui/src/components/form/widgets/codecUtils.ts`](../ui/src/components/form/widgets/codecUtils.ts) — shared codec
  building blocks (`scalarCodec`, `castToSchemaType`).

## How a prop picks its widget

1. The component config payload (`GET /canvas/api/v0/config/component`) exposes each prop's configured widget as
   `propSources[<prop>].field_widget`, sourced from the component's `prop_field_definitions` settings.
2. At render time, `resolveNativeWidgetForProp()` checks, in order: the site-wide kill switch
   (`canvas.settings` `prop_forms.native`), the per-widget disable list (`prop_forms.disabled_widgets`), the
   registry, and the widget's own `isEligible()`.
3. A resolved widget renders as a `NativePropSlot`; anything else renders as an escape-hatch island — a server-built
   form scoped to that prop via the form endpoint's `form_canvas_props_filter` parameter.

Resolution is a synchronous map lookup. An unregistered id can only route a prop to the hatch; it can never delay
the form.

## The contract

A client widget is a `ClientWidgetDefinition` registered per Drupal field widget plugin id:

| Key | Required | Purpose |
| --- | --- | --- |
| `component` | Yes | React component rendering the input. The slot renders label, description, required indicator, and errors — the widget renders only the input itself. |
| `codec` | Yes | Maps widget values to model values (`toModel`) and back (`fromModel`). |
| `validate` | No | Widget-specific validation beyond the shared ajv pass; returns an error message or `null`. |
| `isEligible` | No | Per-prop opt-out: decline props the widget cannot render natively (for example, `options_select` without an `enum`), sending them to the hatch. |
| `handlesMultipleValues` | No | Set when the widget renders its own multi-value UI. Multi-value props whose widget does not handle multiple values render via the escape hatch. |

The component receives `ClientWidgetProps`: the read-only `ClientWidgetContext` (prop name, JSON schema, field
storage settings, cardinality, the full metadata entry) plus `value`, `onChange`, `disabled`, `errors`, `inputId`,
`inputName`, and `siblingValues`. Call `onChange` with the new widget value; the slot runs `validate`, the codec,
and the shared ajv validation, then writes the model through the existing pipeline (resolved merge, prop source
sync, client-side preview update, debounced auto-save patch). Discrete inputs (booleans, enums, selections) commit
immediately; free-typing inputs are debounced.

`toModel` returns `{resolved, source?}` or `null`:

- `resolved` feeds the component render and the client-side preview.
- `source` is only needed when the stored source value differs from the resolved value (entity references and media
  store target ids while `resolved` carries the evaluated object). When omitted, the source value is derived from
  `resolved`.
- `null` means "empty": the prop is removed from the model.

## Worked example: a `rating_stars` widget

Scenario: a site has a custom PHP field widget plugin with id `rating_stars`, configured as the widget for an
integer prop. **No new PHP is needed** to make it native: the widget plugin already exists, is already configured
per prop, and — like every Canvas-compatible widget — already declares `canvas.transforms` metadata. That
declaration stays required even for widgets with native counterparts, because it is what keeps the escape hatch and
the kill switch working for every configured widget at any moment.

The widget value here is the string the user picked; `scalarCodec` handles emptiness and casting to the schema's
integer type, matching the `mainProperty` transform semantics of the server widget.

```tsx
// ui/src/components/form/widgets/widgets/RatingStarsWidget.tsx
import { scalarCodec } from '../codecUtils';

import type { ClientWidgetDefinition, ClientWidgetProps } from '../types';

const RatingStarsWidget = ({
  value,
  onChange,
  disabled,
  inputId,
  jsonSchema,
}: ClientWidgetProps) => {
  const max = (jsonSchema.maximum as number | undefined) ?? 5;
  const current = Number(value ?? 0);
  return (
    <div role="radiogroup" id={inputId}>
      {Array.from({ length: max }, (_, i) => i + 1).map((star) => (
        <button
          key={star}
          type="button"
          role="radio"
          aria-checked={current === star}
          disabled={disabled}
          onClick={() => onChange(String(star))}
        >
          {star <= current ? '★' : '☆'}
        </button>
      ))}
    </div>
  );
};

export const ratingStarsWidget: ClientWidgetDefinition = {
  component: RatingStarsWidget,
  codec: scalarCodec,
};
```

Register it at editor boot. Code compiled into the UI bundle can import the registry directly:

```ts
import { registerClientWidget } from '@/components/form/widgets/registry';
import { ratingStarsWidget } from './widgets/RatingStarsWidget';

registerClientWidget('rating_stars', ratingStarsWidget);
```

The UI's Vite dev server hot-reloads widget modules like any other UI code, so iterating on a widget needs no page
reload and no PHP round trip.

## Codec parity rules

The persisted model is a contract: **a codec must produce exactly the model values the corresponding Drupal widget
produces through its `canvas.transforms` metadata.** The server-form path and the native path write to the same
model, and the kill switch can move a site between them at any time — any divergence is data corruption.

The porting checklist is the widget-to-transforms map in
[`src/Hook/ReduxIntegratedFieldWidgetsHooks.php`](../src/Hook/ReduxIntegratedFieldWidgetsHooks.php)
(`fieldWidgetInfoAlter()`): each transform a widget declares corresponds to behavior the codec must reproduce. In
particular:

- `mainProperty` semantics: the widget value becomes the resolved value, cast to the schema type
  (`castToSchemaType`); an empty value removes the prop (`toModel` returns `null`).
- Widgets whose source differs from resolved (entity references, files, images, media) must return an explicit
  `source` (for example, target ids) alongside the evaluated `resolved` value.
- Multi-cardinality props persist arrays, ordered as the user ordered them.

## Parity test recipe

For every native widget, verify the persisted model matches the Drupal widget baseline:

1. With the native path on (the default), select a component instance and edit the prop through the native widget.
   Capture the auto-saved model — the `source` and `resolved` values sent in the layout `PATCH` request (visible in
   the browser's network panel), or the stored auto-save data.
2. Turn on the kill switch: `drush config-set canvas.settings prop_forms.native 0`. Reload the editor and make the
   same edit through the server-built form.
3. Diff the two captured models. `source` and `resolved` must be identical.
4. Re-enable the native path: `drush config-set canvas.settings prop_forms.native 1`.

## Override semantics

`registerClientWidget()` replaces any existing registration for the id — last registration wins. To override one of
Canvas's default widgets, register from the `canvas:register-client-widgets` DOM event, which fires once after the
default registrations and exposes the registration surface in its `detail`:

```ts
import { REGISTER_CLIENT_WIDGETS_EVENT } from '@/components/form/widgets/registerDefaultWidgets';

document.addEventListener(REGISTER_CLIENT_WIDGETS_EVENT, ((
  event: CustomEvent,
) => {
  const { registerClientWidget } = event.detail;
  registerClientWidget('media_library_widget', myDamMediaWidget);
}) as EventListener);
```

The event guarantees ordering after the defaults, so an override registered there can never itself be overwritten
by them. There is no unregister API: to force a widget id onto the escape hatch site-wide, use configuration
instead (`prop_forms.disabled_widgets`).

Delivery of client widgets from contrib modules (outside the UI bundle) is deferred work; see
[ADR-0017](adr/0017-client-side-field-widgets.md).

## Multi-value and composite props

- The prop's cardinality is available as `context.cardinality` (`1`, a fixed N, or `-1` for unlimited).
- Set `handlesMultipleValues: true` when the widget owns its own list UI (the media widget's selection list or a
  multi-select, for example); the codec then receives and produces the whole list.
- A multi-value prop whose widget does not declare `handlesMultipleValues` renders via the escape hatch, keeping
  the server-built widget's add/remove/reorder UX. A shared native multi-value list wrapper is planned follow-up
  work.
- Composite values (props whose schema is an object, or whose source shape differs from the resolved shape) should
  keep client-side ajv validation expectations modest: object-shaped props are validated by the server, matching
  the server-form path's behavior.

## Formatted text props

Formatted text props (`contentMediaType: text/html`, stored as `text`/`text_long` with the `text_textfield`,
`text_textarea`, or `text_textarea_with_summary` widgets) render natively, and their editing UI derives entirely
from the text format configuration — nothing about the editor is hardcoded client-side:

- The permitted-format list (id, label, editor plugin id) ships with the editor boot settings
  (`drupalSettings.canvas.propForms.textFormats`), so eligibility resolves synchronously at render time. Each
  prop's choices are that list intersected with its stored `allowed_formats` instance settings.
- When the active format has a CKEditor 5 editor configured, the shared CKEditor host
  ([`CKEditorHost`](../ui/src/components/form/components/CKEditorHost.tsx), also used by the escape hatch) mounts
  with the format's configured toolbar, plugins, and settings. Those settings and the editor's asset libraries are
  fetched once per session from `GET /canvas/api/v0/text-editor-settings`, which returns exactly what the editor
  module attaches to a server-built `text_format` element, restricted to formats the current user may use.
- A format with no editor renders the plain input. `text_textfield` never mounts an editor (editors attach to
  textareas only, matching `\Drupal\editor\Element::preRenderTextFormat()`).
- A prop whose formats include a non-CKEditor-5 editor plugin (a contrib editor) renders via the escape hatch,
  where that editor's attach pipeline works unchanged.
- The codec writes `{value, format}` as the source value with the raw markup as the optimistic resolved value; the
  server's evaluation of the format's filters is authoritative on the patch echo, like the media widgets.

## Conditional prop states (`x-canvas-states`)

Props can declare conditional visibility and enablement directly in the component schema, evaluated client-side for
native and escape-hatch props alike. See
[`ui/src/components/form/widgets/propStates.ts`](../ui/src/components/form/widgets/propStates.ts).

Each rule is `{effect, when}`: `effect` is `visible` or `enabled`, and `when` is a JSON Schema evaluated (with ajv)
against an object of the component instance's resolved prop values — so conditions address sibling props by name
through schema keywords, never by DOM selector:

```json
{
  "show_icon": {
    "type": "boolean",
    "title": "Show icon"
  },
  "icon": {
    "type": "string",
    "title": "Icon name",
    "x-canvas-states": [
      {
        "effect": "visible",
        "when": {
          "type": "object",
          "properties": { "show_icon": { "const": true } },
          "required": ["show_icon"]
        }
      }
    ]
  }
}
```

Authoring rules:

- Multiple rules with the same effect are ANDed: every `visible` rule must pass for the prop to be visible, and
  likewise for `enabled`.
- A hidden prop keeps its model value; visibility is a UI affordance only.
- Conditional `required` is not supported in v1 (it needs client/server validation agreement).
- Unknown effects and malformed condition schemas are ignored (with a console warning), keeping the vocabulary
  forward-extensible.

`x-canvas-states` replaces cross-prop `#states` use cases: a `#states` dependency injected via `hook_form_alter()`
cannot span the boundary between a native widget and an escape-hatch island. Within a single island, or under the
kill switch's whole-form path, `#states` keeps working as before.
