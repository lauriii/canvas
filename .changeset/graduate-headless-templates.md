---
"@drupal-canvas/create": minor
---

Make all headless templates available in the interactive template picker without an experimental flag.

- Remove `--experimental-headless` from existing commands; the flag is no longer supported.
- Keep selecting the `default` template in non-interactive runs that omit `--template`.
