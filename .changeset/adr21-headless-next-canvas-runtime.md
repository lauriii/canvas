---
'@drupal-canvas/headless-next': minor
---

Add the `CanvasRuntime` server component
(`@drupal-canvas/headless-next/CanvasRuntime`). Render it once in the root
layout: it supplies the request's nonsecret JSON:API runtime configuration to
every `CanvasComponentTree` below it, so `useJsonApiClient()` works in
registered components. `CanvasComponentTree` keeps its `'use client'` boundary
and additionally accepts `context` and `jsonApi` props.
