# @drupal-canvas/height-reader

Shared DOM helpers for measuring rendered content height when viewport-relative
CSS (`vh`, `min-h-screen`, `h-screen`) is in play. Used by the Canvas UI editor
preview and the headless SDK.

Viewport-relative CSS resolves against the current viewport height. Inside a
preview iframe that height is whatever the host last applied, so a naive
measure-and-resize loop makes the iframe grow on every pass. These helpers
detect such elements so callers can confirm and pin their stable pixel height
before repeated measurements drift. See the `@file` comments in `src/` for the
full rationale.

## API

```ts
import { StableHeightReader } from '@drupal-canvas/height-reader';

const reader = new StableHeightReader();
const stableHeight = await reader.measureDocumentHeight(document, {
  baseViewportHeight: window.innerHeight,
  probeController,
});
```

- `StableHeightReader` — stateful measurement/pinning shared by the UI preview
  and the headless SDK. Reuse one instance across passes so confirmed
  viewport-relative elements stay pinned at stable pixel heights. Call `clear()`
  to restore their original styles.
- `isVhMeasurementCandidate(element, effectiveViewportHeight)` — whether one
  element looks viewport-relative. Excludes `html`/`body`.
- `collectViewportRelativeElements(root, effectiveViewportHeight)` — candidates
  in `root`'s subtree (`root` included).
- `collectElementsUnderRoots(roots)` — de-duplicated roots plus descendants,
  useful when mutation-driven remeasurement only needs part of the tree.
- `usesViewportHeightProperty(element)` — whether dropping `height` to `auto`
  changes the rendered box enough that the confirmed stable height should be
  hard-capped.
- `getClassNameString(element)` — className as a string (handles SVG's
  `SVGAnimatedString`).

## What's not shared

The environment-specific viewport change stays outside this package. The UI
still owns iframe resizing, and the headless SDK still owns `postMessage`.
`StableHeightReader` accepts those environment hooks so both implementations can
share the same detection, confirmation, and pinning behavior.

## Scripts

- `npm test`
- `npm run type-check`
