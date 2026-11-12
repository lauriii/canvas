# Inline text editing for component developers

Content authors can edit a component's text directly in the Canvas preview: they select the component, then click its
text (so a double-click goes straight in), or press Enter. This page explains which props support that, and how to make
sure yours do.

The design is recorded in [ADR 25](adr/0025-edit-text-props-in-place-in-the-preview.md). Internals are described in
[react-codebase/inline-text-editing.md](react-codebase/inline-text-editing.md).

## Which props are editable in place

A prop is editable in place when all of these are true:

- **It is text.** Its shape is `type: string` (one line), a string with line breaks (`string_long`), or formatted text
  (`contentMediaType: text/html`) with exactly one allowed text format that uses CKEditor 5.
- **It holds its own value.** A prop linked to an entity field is edited where that field is edited.
- **Canvas can find where it is rendered**, and it is rendered once. See below.
- **What is rendered is the stored value.** `{{ title|upper }}`, truncation, or `text.toUpperCase()` show something
  else than what would be saved, so these props stay sidebar-only. CSS such as `text-transform` is fine.

Every prop keeps working in the sidebar. Clicking text that cannot be edited in place moves focus to its sidebar field.

## How Canvas finds a prop's text

### Twig: nothing to do in most cases

In previews, Canvas marks every print of a component's own variable:

```twig
<h2>{{ heading }}</h2>
<div class="body">{{ text|default('') }}</div>
```

Prints through filters that keep the text as is are marked too: `default`, `raw`, and `e`/`escape` with the default HTML
strategy. Prints in attributes, inside `{% trans %}`, or through other filters are not.

### Values passed to other templates

A value passed to another template with `with_context: false`, or with `only`, is printed by a variable of that
template, which Canvas cannot attribute to your prop:

```twig
{{ include('my_theme:heading', { text: heading_text }, with_context: false) }}
```

Canvas then looks for the one element of your component, outside its child components, whose text is exactly the
stored value. That works when the text is unique within the component. When it is not, for example a heading equal to a
button label, mark the element explicitly.

### Marking an element explicitly

Add `data-canvas-prop` with the prop's name to the element that renders the value, and nothing else:

```twig
<h2 data-canvas-prop="heading_text">{{ heading_text }}</h2>
```

```jsx
// Code component.
<h2 data-canvas-prop="heading">{heading}</h2>
<FormattedText data-canvas-prop="text">{text}</FormattedText>
```

The attribute takes precedence over the other ways of finding a prop. It has no effect outside the Canvas editor.
The element must still show exactly the stored value: with `{heading.toUpperCase()}` the prop stays sidebar-only,
because what the author would edit is not what is saved.

### Opting out

Text inside an element with `data-canvas-inline-edit="false"` stays sidebar-only, for example text that a carousel or
animation re-renders:

```html
<div class="ticker" data-canvas-inline-edit="false">{{ headline }}</div>
```

### Empty props

When a prop's text is empty, the selected component shows an "Add …" placeholder in the element that would hold it, so
it can be clicked. That needs the element to be rendered: a template such as `{% if heading %}<h2>{{ heading }}</h2>{%
endif %}` renders nothing for an empty heading, which then can only be added in the sidebar. Render the element
unconditionally if authors should be able to add the text in place.

## Why is my prop not editable?

Run this in the browser console on the Canvas editor, select the component, and click its text:

```js
localStorage.setItem('canvas.debugInlineEditing', '1');
```

The selected component then outlines editable text in green and marked text that is not editable in red, labeled with
the reason. The console lists every prop of the component that stays sidebar-only, with the reason, such as:

- `is linked (entity-field…)`: the prop is linked to an entity field.
- `renders as "MY TITLE", not its stored value`: the template changes the value on output.
- `is not found in the preview: no markers, and its text is not unique in the component`: add `data-canvas-prop`.
- `is rendered more than once`: mark one of the elements with `data-canvas-prop`.
- `does not have exactly one allowed text format`: formatted text with several allowed formats is edited in the
  sidebar, where the format can be chosen.

Remove the item, or set it to anything but `1`, to stop logging.
