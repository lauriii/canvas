# Drupal Canvas Extensions API

JavaScript library for building
[Drupal Canvas](https://www.drupal.org/project/canvas) extensions. Extensions
are embedded web applications that can access and respond to data inside the
Drupal Canvas app in real-time.

- [Installation](#installation)
- [Getting started](#getting-started)
- [Example](#example)
- [Sandboxing model](#sandboxing-model)
- [API](#api)
  - [`getPreviewHtml()`](#getpreviewhtml)
  - [`subscribeToPreviewHtml(callback)`](#subscribetopreviewhtmlcallback)
  - [`getSelectedComponentUuid()`](#getselectedcomponentuuid)
  - [`subscribeToSelectedComponentUuid(callback)`](#subscribetoselectedcomponentuuidcallback)

## Installation

```bash
npm install @drupal-canvas/extensions
```

## Getting started

To build a Drupal Canvas extension, create a web application with your
technology of choice, and make sure it can be embedded in an iframe.

Then create a Drupal module with a `[your-module-name].canvas_extension.yml`
file to define your extension's metadata:

```yml
canvas_test_extension: # ID of your extension. You can specify multiple extensions in this file.
  name: Example Extension
  description: A brief description of what your example does.
  url: index.html # Path to local HTML file shipped in your module's codebase, or a remote URL.
  icon: icon.svg # Path to local SVG file shipped in your module's codebase.
  type: canvas # Optional. Defaults to canvas. Other options: page, code-editor.
  api_version: 1.0
  permissions: # Optional. The user must have all listed permissions.
    - access content
```

Extension types determine where the extension appears in Canvas:

- `canvas`: Opens from the Extensions panel while editing Canvas content. This
  is the default when `type` is omitted.
- `page`: Opens as a full-page Canvas route at `/canvas/app/{extension_id}` and
  appears as a dedicated side menu link. Page extensions do not appear in the
  Extensions panel.
- `code-editor`: Opens from the code editor.

Permissions are optional. When permissions are listed, users must have all of
those permissions to access the extension. Page extensions without permissions
are available to any user who can access the Canvas UI.

### Page extension routing

Page extensions are hosted at `/canvas/app/{extension_id}`. They can also be
opened with a deeper path, such as `/canvas/app/{extension_id}/reports/weekly`.
Canvas forwards the deeper path to the extension iframe as a hash route:
`{extension_url}#/reports/weekly`.

A page extension can update the parent Canvas URL when its internal route
changes by sending a navigation message to the parent window:

```js
window.parent.postMessage(
  {
    type: 'canvas:navigate',
    subPath: 'reports/weekly',
  },
  window.location.origin,
);
```

Canvas accepts navigation messages only from the active page extension iframe.
The parent URL is updated without adding a new Canvas history entry, so Canvas
back navigation returns to the previous Canvas screen instead of stepping
through the extension's internal routes.

## Example

For a full example, see the
[`canvas_test_extension` test module](https://git.drupalcode.org/project/canvas/-/tree/1.x/tests/modules/canvas_test_extension?ref_type=heads)
in the Drupal Canvas codebase.

## Sandboxing model

Canvas extensions run in iframes with
`sandbox="allow-scripts allow-same-origin"` for dialog extensions and
`sandbox="allow-scripts allow-same-origin allow-downloads"` for page extensions.
Because `allow-same-origin` is enabled, this is not a strong security boundary
for extension code.

Installing an extension currently means installing the Drupal module that
provides it, so site owners should treat extensions as trusted code. This model
may change if Canvas supports registering extensions dynamically from arbitrary
URLs in the future.

## API

### `getPreviewHtml()`

Get the current preview HTML.

**Returns:** `Promise<string>`

```typescript
import { getPreviewHtml } from '@drupal-canvas/extensions';

const html = await getPreviewHtml();
console.log(html);
```

### `subscribeToPreviewHtml(callback)`

Subscribe to preview HTML changes. The callback is called whenever the preview
HTML updates.

**Parameters:**

- `callback: (html: string) => void` - Function called with the updated HTML

**Returns:** `() => void` - Unsubscribe function

```typescript
import { subscribeToPreviewHtml } from '@drupal-canvas/extensions';

const unsubscribe = subscribeToPreviewHtml((html) => {
  console.log('Preview HTML updated:', html);
});

// Later, when you want to stop listening:
unsubscribe();
```

### `getSelectedComponentUuid()`

Get the UUID of the currently selected component.

**Returns:** `Promise<string | undefined>`

```typescript
import { getSelectedComponentUuid } from '@drupal-canvas/extensions';

const uuid = await getSelectedComponentUuid();
if (uuid) {
  console.log('Selected component:', uuid);
} else {
  console.log('No component selected');
}
```

### `subscribeToSelectedComponentUuid(callback)`

Subscribe to selected component UUID changes. The callback is called whenever
the user selects a different component.

**Parameters:**

- `callback: (uuid: string | undefined) => void` - Function called with the
  selected component UUID

**Returns:** `() => void` - Unsubscribe function

```typescript
import { subscribeToSelectedComponentUuid } from '@drupal-canvas/extensions';

const unsubscribe = subscribeToSelectedComponentUuid((uuid) => {
  if (uuid) {
    console.log('Component selected:', uuid);
  } else {
    console.log('No component selected');
  }
});

// Later, when you want to stop listening:
unsubscribe();
```
