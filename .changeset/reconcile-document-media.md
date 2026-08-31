---
"@drupal-canvas/cli": minor
---

Reconcile external document media in push flows.

- `reconcile-media` uploads external document URLs (`pdf`, `rtf`, Office, OpenDocument, and iWork formats) referenced by document props as `document` media entities. The document's `title` and `description` are sent along with the file.
- The default OAuth scopes now include `canvas:media:document:create`.
