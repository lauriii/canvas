# Release note: native rich text widget

Draft release note and change record material for the native rich text widget change (follow-up to
[native prop forms](native-prop-forms.md)).

## Summary

Formatted text props (`contentMediaType: text/html`; the `text_textfield`, `text_textarea`, and
`text_textarea_with_summary` widgets) now render natively in the editor: CKEditor 5 mounts client-side with the
toolbar, plugins, and settings configured on the prop's text format, with no component-instance form request. The
editor settings and asset libraries for the formats the current user may use are fetched once per session from a
new endpoint (`GET /canvas/api/v0/text-editor-settings`); formats the user cannot use are never exposed. Formats
without an editor render a plain input, and props whose formats use a non-CKEditor-5 editor plugin (contrib
editors) keep rendering via the escape hatch unchanged.

The persisted model is unchanged (`{value, format}` source values), and the server's filter processing remains
authoritative: the editor's raw markup only previews optimistically until the server echoes the processed output.

Documentation: [Client-side widgets](../client-side-widgets.md) (formatted text props section) and
[ADR-0017](../adr/0017-client-side-field-widgets.md).

## Compatibility

- Contrib CKEditor 5 plugins keep working on the native path: plugin builds remain Drupal asset libraries and the
  editor configuration is computed server-side by the editor module.
- Non-CKEditor-5 editor plugins (for example markdown editors) automatically use the escape hatch.
- Sites can send the text widgets back to the escape hatch via `prop_forms.disabled_widgets` (ids `text_textarea`,
  `text_textarea_with_summary`, `text_textfield`), or restore the full server-form path with the
  `prop_forms.native` kill switch.
