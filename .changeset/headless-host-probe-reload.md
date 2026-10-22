---
'@drupal-canvas/headless-host': patch
---

Fix the preview iframe staying at a probe height after the app reloads during a height probe.

- The host restores the pre-probe height when the iframe loads a new document.
- Height reports from the new document are applied instead of ignored.
