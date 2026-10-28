---
'drupal-canvas': patch
---

Fix draft reads of resource types without revisions, such as users, which Drupal rejected with 501 Not Implemented.

- `getResource()` repeats the read once without the resource version when Drupal answers 501 Not Implemented, and remembers the type when Drupal returns the resource without a `working-copy` link.
- Collection reads fetch working copies only for items that link to one.
