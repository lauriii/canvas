---
'@drupal-canvas/workbench': patch
---

Scan Workbench client modules and host components during initial dependency optimization to prevent cold-start previews from mixing React optimizer generations. Optional component dependencies are discovered from imports rather than required in every project.
