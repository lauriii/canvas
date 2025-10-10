# Drupal Canvas Extensions API

JavaScript library for building
[Drupal Canvas](https://www.drupal.org/project/canvas) extensions. Extensions
are embedded web applications that can access and respond to data inside the
Drupal Canvas app in real-time.

- [Installation](#installation)
- [Getting started](#getting-started)
- [Example](#example)
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
  api_version: 1.0
```

## Example

For a full example, see the
[`canvas_test_extension` test module](https://git.drupalcode.org/project/canvas/-/tree/1.x/tests/modules/canvas_test_extension?ref_type=heads)
in the Drupal Canvas codebase.

## API

### `getPreviewHtml()`

Get the current preview HTML.

**Returns:** `Promise<string>`

```typescript
import { getPreviewHtml } from "@drupal-canvas/extensions";

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
import { subscribeToPreviewHtml } from "@drupal-canvas/extensions";

const unsubscribe = subscribeToPreviewHtml((html) => {
  console.log("Preview HTML updated:", html);
});

// Later, when you want to stop listening:
unsubscribe();
```

### `getSelectedComponentUuid()`

Get the UUID of the currently selected component.

**Returns:** `Promise<string | undefined>`

```typescript
import { getSelectedComponentUuid } from "@drupal-canvas/extensions";

const uuid = await getSelectedComponentUuid();
if (uuid) {
  console.log("Selected component:", uuid);
} else {
  console.log("No component selected");
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
import { subscribeToSelectedComponentUuid } from "@drupal-canvas/extensions";

const unsubscribe = subscribeToSelectedComponentUuid((uuid) => {
  if (uuid) {
    console.log("Component selected:", uuid);
  } else {
    console.log("No component selected");
  }
});

// Later, when you want to stop listening:
unsubscribe();
```
