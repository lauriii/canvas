# @drupal-canvas/headless-react

Shared React bindings for the Drupal Canvas Headless SDK.

The framework adapters (`@drupal-canvas/headless-next`,
`@drupal-canvas/headless-tanstack-start`) re-export these components with their
router wiring filled in — use those in an app. An app on a React framework
without an adapter can use this package directly; it depends only on React and
`@drupal-canvas/headless`.

## Installation

```bash
npm install @drupal-canvas/headless-react
```

## Usage

### Draft session

`<DraftSession>` runs the in-editor session renewal protocol and drives your
banner UI through a render prop. It takes the session state gathered on the
server, plus two framework hooks:

- `path` — the current pathname from the router, reported to the embedding
  editor and carried by the renew link.
- `refreshData` — the framework's server-data refresh (`router.refresh()` in
  Next.js). Optional: without it the component resets its expiry timer in place
  from the renew endpoint's response.

### Component rendering

`<CanvasComponentTree>` renders the structured content returned by
`fetchPage()`. Registry keys are `component.yml` machine names:

```tsx
import { CanvasComponentTree } from '@drupal-canvas/headless-react';

import HelloCard from './components/canvas/hello-card';

<CanvasComponentTree
  tree={page.content}
  components={{ 'hello-card': HelloCard }}
/>;
```

Named Canvas slots become React props with rendered `ReactNode` values; a
`default` slot becomes `children`. Drupal markup strings are inserted as trusted
HTML.
