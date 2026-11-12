# 25. Edit text props in place in the preview

Date: 2026-11-06

## Status

Accepted

## Context

Content authors edit component props in a sidebar form, away from the rendered page. For text, which is most of what
authors change, visual page builders let authors type directly into the page. Headless
systems with a visual preview mostly map a click on the preview to a sidebar field instead, because they do not control
the rendering; the ones that do edit in place ask developers to tag elements, and support plain text only.

The Canvas preview is a same-origin document rendered by the server from the real theme and components. Component
developers write Twig templates or code components, and do not annotate them for the editor. Text props are stored as
literal values in the layout, as plain strings or as formatted text in a text format whose editor configuration and
HTML restrictions Drupal defines. The server filters formatted text through its format when evaluating it.

Prop updates are server-first: the server validates the value, auto-saves, and returns the updated model and a new
preview document. Replacing the preview document destroys any caret in it. Undo history records model changes.

## Decision

### Edit the real preview, keep editing chrome outside it

Text is edited in the preview document itself, so it keeps the theme's fonts, measure and reflow. Toolbars, labels and
notices render in the editor's own document, so they neither shift the themed page nor scale with zoom.

### Find a prop's rendered text, or fall back to the sidebar

The location of a prop's text in the preview is taken from, in order:

1. an element the component developer marks with `data-canvas-prop="{prop name}"`;
2. comment markers the server emits in previews around Twig prints of the prop, whether printed bare or through
   filters that do not change the printed text (`default`, `raw`, HTML escaping);
3. the one element of the component, outside its child components, whose text equals the stored value; for formatted
   text, the element whose children are the value's top-level blocks.

A prop is editable in place only if its rendered text equals its stored value, it is rendered once, it is stored in the
layout rather than linked to another source, and it is not inside an element marked `data-canvas-inline-edit="false"`.
Every other prop stays editable in the sidebar, and clicking its text points the author there. Inference reads the
preview; it does not modify it.

### Use the platform's editors

Plain text is edited with the browser's plain-text editing. Formatted text is edited with the same editor and the same
configuration the sidebar uses for the prop's text format, so inline editing cannot produce markup the sidebar could
not. Formats whose editor features need dialogs or pickers bound to the editor's own document stay sidebar-only.

### An edit is a session, committed once

While text is being edited, the preview document is the source of truth: nothing is written to the model, and new
preview documents are held back. When the author leaves the text, the value is committed once, through the same request
the sidebar form sends, so validation, auto-save, translation and history behave as for a sidebar edit. A session is
one undo step. Commits are sent one at a time, each based on the model the previous one produced. Undo and component
shortcuts are disabled while a session or its commit is in progress. A commit is refused if the author has navigated to
another entity since the session started.

### Never lose an edit

If a commit fails, the preview shows the stored value again and the author is offered to retry or copy the text. An edit
that could not be saved because of a conflict or navigation is kept for the browser session and offered again after a
reload. A required prop left empty keeps its previous value. Removing an optional prop's text offers an undo.

### Keep the server as the security boundary

Previews only ever render server-filtered values. While a session ends and before the server's render arrives, the
preview shows the editor's own safe rendering of the content, never its raw data.

### Ship without a feature flag

Inline editing adds a way into the same model the sidebar edits, through the same request, and never removes the
sidebar path. A prop that cannot be edited safely in place stays sidebar-only. So it ships enabled, without a setting:
a flag would hide the feature from the authors it is for, while the sidebar already is the fallback.

### Hold the whole preview, for now

While text is edited, the whole preview document is held, because the preview is replaced as a whole on every update.
Once previews are patched per component, only the edited component needs to be held, and updates from elsewhere, such
as other editors or AI assistance, can keep arriving everywhere else. The hold is to be narrowed then.

## Consequences

- Component developers get inline editing without changes for bare Twig prints. Values passed to other templates or
  rendered by code components are found by their text, or explicitly with `data-canvas-prop`.
- Output that transforms a value, such as truncation or case changes, is not editable in place.
- During a session the preview shows no updates from elsewhere, because it is held.
- Character-level merging of concurrent edits is out of scope; the per-prop write behaves like a sidebar edit.

## Alternatives considered

- **Overlay editor:** an input positioned over the text cannot reproduce line breaking and reflow, and doubles the
  rendering paths.
- **Click to focus the sidebar only:** keeps the gap between seeing and editing that inline editing closes.
- **Invisible-character encoding of field identity in values:** breaks string comparison, attributes and
  filters in component code.
- **A new rich text library:** would define a second set of formatting rules next to the text format's editor.
