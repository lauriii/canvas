# @drupal-canvas/headless-react

Shared React binding for the Drupal Canvas Headless SDK
(`@drupal-canvas/headless`): the `<DraftSession>` client component around the
SDK core's framework-free draft session state machine.

React frameworks differ in routing and data refresh, not in React — so the
framework adapters (`@drupal-canvas/headless-next`,
`@drupal-canvas/headless-tanstack-start`) re-export this component with their
router wiring filled in:

- `path` — the current pathname from the framework's router, reported to the
  embedding host and carried by the renew link.
- `refreshData` — the framework's server-data refresh (`router.refresh()` in
  Next.js). Optional: without it the component re-arms in place from the renew
  endpoint's `{tokenExpiresAt}` answer — the renewed token already lives in the
  session cookie, so no server round trip is required.

Everything else — expiry timing, the origin-checked postMessage renewal protocol
with the embedding Canvas editor, status reporting — lives in the SDK core;
presentation lives in the consumer's render prop. See the adapter packages'
READMEs for the banner pattern.

An app on a React framework without an adapter package can use the component
directly; it has no dependency beyond React and the SDK core.

## Component rendering

`<CanvasComponentTree tree={page.content} components={registry} />` renders a
Custom Elements tree, including the `content` returned by `fetchPage()`.
Registry keys are `component.yml` machine names:

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
HTML. Canvas-only instance metadata is not passed to application components.
