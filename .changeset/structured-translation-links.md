---
"@drupal-canvas/headless": minor
---

Add `route.negotiatedLanguage` and `route.translations` to the page data returned by `fetchPage()`. Translation entries share the fields and switcher behavior of `mainEntity.translations` returned by `getPageData()` from `drupal-canvas`. Headless entries additionally include `external` and use a different URL form: Drupal request URIs without the installation base path, or absolute external URLs.
