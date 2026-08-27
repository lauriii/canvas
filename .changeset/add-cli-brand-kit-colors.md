---
"@drupal-canvas/cli": minor
---

Add brand kit color synchronization support.

- Carry brand kit colors in `canvas.brand-kit.json` under a new `colors` key, alongside `fonts`. Each entry is `{name, cssVariable, value}` where `value` is a hex string (`#rrggbb` or `#rrggbbaa`) or a W3C design token object; array order is the palette order.
- Pull colors from the site into the file and push file entries to the site's color endpoints, matching by `cssVariable`. A pull right after a push produces no diff.
- Never delete a site color that is absent from the file by default; report it and offer the explicit `canvas push --prune-colors` opt-in.
- Validate the file locally before any request, naming the offending entry for malformed hex values, invalid or duplicate CSS variable names, and duplicate color names.
