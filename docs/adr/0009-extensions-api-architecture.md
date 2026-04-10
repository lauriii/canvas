# 9. Extensions API architecture

Date: 2025-10-03

Issue: <https://www.drupal.org/project/canvas/issues/3514033>

## Status

Accepted

## Context

The [previously developed proof of concept](https://www.drupal.org/project/experience_builder/issues/3485692) successfully validated the business requirements for extending Drupal Canvas. However, the exposed API surface was designed for validation purposes and is not intended as a permanent solution.

This ADR outlines the long-term vision and high-level architecture for the extensions API.

## Decision

### Extensions will run in an iframe

Extensions will run in an iframe inside Canvas pointing at an HTML document hosted in the local codebase or at a remote URL. This opens up the possibility for extension developers to host their own extension, use any tech stack they want, and release updates that users receive automatically.

Note: This approach doesn’t address the use case of different UI widgets showing up at different places of the Canvas UI. We will introduce a separate API for solving that.

### Browser APIs: `window.postMessage()`

Develop an API surface for communicating with the Canvas React app in the browser for real-time changes and data exchange on top of `window.postMessage()`. This will introduce API boundaries, i.e. internal vs. public, and allow cross-origin communication between the iframe and the Canvas UI.

### Server-side APIs: OAuth2

We previously introduced the [Canvas OAuth module](https://git.drupalcode.org/project/canvas/-/tree/1.x/modules/canvas_oauth) to allow OAuth2-based authentication on the routes that the main Canvas module marks with `canvas_external_api: true` in [`canvas.routing.yml`](https://git.drupalcode.org/project/canvas/-/blob/1.x/canvas.routing.yml). This is currently powering authentication for the [Canvas CLI tool](https://www.npmjs.com/package/@drupal-canvas/cli) by opting in routes related to working with Code Components.

Gradually enable more HTTP endpoints to be treated as external APIs. This will allow extensions to perform server-side actions.

### UI

Extensions need to be empowered to make their appearance feel unified with that of Canvas. Our long-term strategy to address this is to publish our own component registry which contains the same components Canvas uses in its own UI codebase. A component registry is different from a library such that a registry provides code to be copied and freely modified, as opposed to a library where maintainers need to design APIs for customization, and users of the library need to learn and work within the boundaries of the customization APIs. The concept of a registry was popularized in the frontend world by [shadcn/ui](https://ui.shadcn.com).

### Extension discovery

Create a Drupal plugin type and a plugin manager that implements a discovery mechanism for YAML files. (See an example of this [in the `simple_oauth_static_scope` module](https://git.drupalcode.org/project/simple_oauth/-/tree/6.0.x/modules/simple_oauth_static_scope/src/Plugin).)

### Extension metadata

```yml
name: Example Extension
description: Do wonders in Drupal Canvas
url: 'https://example.com/index.html' # Fully qualified URL by default
# url: 'dist/index.html' # Local file example
icon: 'relative-path-to/svg-file.svg' # Can be an absolute url too.
type: 'canvas' # Other option for now: 'code-editor'
api_version: '1.0' # Canvas Extension API version
permissions:
  - administer components
  - administer code components
```

We will extend the available types over time and as we design more use cases. Currently two values should be allowed: `canvas` and `code-editor`.  The `canvas` type will allow extensions to be opened while working with the editor frame for pages (and other more generic screens — hence the generic name), while the latter will only apply an extension to the code editor.

## Consequences

- The [previously built proof of concept](https://www.drupal.org/project/experience_builder/issues/3485692) will be deprecated.
- Modules will be able to register extensions in YAML files.
- Extensions will run in an iframe.
- Extension UI in multiple places within the Canvas editor (e.g. sidebar, toolbar, panel) is not supported by this ADR.
- APIs will be provided for browser and server-side actions.
- UI registry will be provided for unified appearance.
