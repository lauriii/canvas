---
"@drupal-canvas/cli": minor
---

Add brand kit color synchronization support.

- Carry brand kit colors in `canvas.brand-kit.json` under a new `colors` key alongside `fonts`: a map from the CSS custom property name (without `--`) to a CSS color string (`#rrggbb`, `#rrggbbaa`, `rgb()`, `rgba()`, `hsl()`, `hsla()`) or a `{value, name, displayFormat}` wrapper. Display names derive from the key, so `"brand-red": "#cc0000"` is a complete entry.
- Pull colors from the site into the file and push entries to the site's color endpoints, matching by the variable the key names. A pull right after a push produces no diff, and a push right after a pull writes nothing.
- Never delete a site color that is absent from the file by default; report it and offer the explicit `canvas push --prune-colors` opt-in.
- Validate the file at the start of push — before authentication or any request — naming the offending entry for malformed values, invalid keys, two keys naming the same variable, and duplicate display names (the site requires unique color names).
- Validate `canvas.brand-kit.json` in `canvas validate`: JSON syntax, structure against a published JSON Schema, and the semantic rules a schema cannot express (key collisions, ranges inside color strings, font files existing on disk). Files the CLI creates carry a `$schema` reference so editors validate and autocomplete them.
- Brand kit is now included by default: `canvas pull` and `canvas push` sync the brand kit without any flag. Pass `--no-include-brand-kit` to opt out, or set `CANVAS_INCLUDE_BRAND_KIT=false`.
- `canvas:brand_kit` is now part of the default OAuth scope. Existing tokens will request this scope on their next refresh.
- `--include-brand-kit` (positive form) is deprecated and emits a warning. Remove it from scripts; `--no-include-brand-kit` is the supported opt-out.
