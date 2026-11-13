---
'@drupal-canvas/headless-react': minor
'@drupal-canvas/headless-next': minor
'@drupal-canvas/headless-tanstack-start': minor
---

Export `TranslationsProvider` as a client component, so that server components can render it above `CanvasComponentTree` with the application's own dictionary. It replaces the translations Drupal returns in `page.context` for the components below it.
