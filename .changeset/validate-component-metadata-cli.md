---
"@drupal-canvas/cli": minor
---

Validate authored Code Component metadata against the Canvas contract.

- Validate raw `component.yml` envelopes and directly resolvable prop schemas locally.
- Use the authenticated target site's non-mutating validation operation when available, and warn when target acceptance was not validated.
- Preflight every complete Code Component payload before push mutations when the target supports it.
- Derive content entity reference preview targets from `dataDependencies.entityFields`.
