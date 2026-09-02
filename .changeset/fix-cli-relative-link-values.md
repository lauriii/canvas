---
"@drupal-canvas/cli": patch
---

Fix pushing link props whose value is a relative reference without a leading slash.

- `uri-reference` and `iri-reference` values such as `page.html?x=1`, `?x=1` or `#section` are now sent as authored. Previously they were prefixed with `internal:`, which the server rejects because an `internal:` URI requires a leading slash.
- Root-relative values such as `/about` are still sent as `internal:/about`, matching what the Canvas UI stores.
- URI schemes are now detected case-insensitively, so `HTTPS://…` is no longer treated as a relative path.
