---
"@drupal-canvas/headless": minor
"@drupal-canvas/headless-react": minor
"@drupal-canvas/headless-astro": minor
"@drupal-canvas/headless-nuxt": minor
"@drupal-canvas/headless-next": minor
"@drupal-canvas/headless-tanstack-start": minor
"@drupal-canvas/headless-angular": minor
---

Add support for json-render format in the Headless SDK.

When upgrading, pass the `isPreview` value returned by `fetchPage()` or
`fetchEntity()` to `CanvasComponentTree`'s `preview` prop. Preview mode is no longer
inferred from the content tree. Use `preview={page.isPreview}` in React and
Astro, `:preview="page.isPreview"` in Nuxt, or `[preview]="page.isPreview"`
in Angular.
