---
"@drupal-canvas/workbench": minor
---

Add page template discovery and preview support.

- Replace global region discovery and preview rendering with page templates.
- Load page templates from the configured directory, `page-templates/` by default, and refresh previews when their files change.
- Use discovered page templates in page and full content template previews.
- Accept the `pageVariant` field in page and content template specs.
