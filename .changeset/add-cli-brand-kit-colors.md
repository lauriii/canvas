---
"@drupal-canvas/cli": minor
---

Add brand kit color synchronization support.

- Carry brand kit colors in `canvas.brand-kit.json` under a new `colors` key alongside `fonts`, shaped like a Tailwind theme: a map from the CSS custom property name (without `--`) to a CSS color string (`#rrggbb`, `#rrggbbaa`, `rgb()`, `rgba()`, `hsl()`, `hsla()`), a design token object for exact components, or a `{value, name, displayFormat}` wrapper. Display names derive from the key, so `"brand-red": "#cc0000"` is a complete entry; map order is the palette order.
- Pull colors from the site into the file and push entries to the site's color endpoints, matching by the variable the key names. A pull right after a push produces no diff, and a push right after a pull writes nothing.
- Never delete a site color that is absent from the file by default; report it and offer the explicit `canvas push --prune-colors` opt-in.
- Validate the file at the start of push — before authentication or any request — naming the offending entry for malformed values, invalid keys, and two keys naming the same variable.
