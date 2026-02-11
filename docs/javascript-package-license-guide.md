# JavaScript package license guide

Use this practical guide when choosing a license for any new JavaScript package
in the Drupal Canvas monorepo under `packages/`.

**Caution:** This is technical evaluation guidance, not legal advice. The
checklist is not exhaustive. It covers the most obvious criteria, so use it
carefully and document open questions when criteria are unclear.

## How to use this guide

- Start with the "Keep GPL-2.0-or-later" checks.
- If none apply, review the "MIT is usually appropriate" checks.
- If one check points to MIT and another points to GPL-2.0-or-later, use
  criteria 1 and 2 from the "Keep GPL-2.0-or-later" list as the tie-breakers.
- If any MIT check is false or unknown, keep GPL-2.0-or-later.

## Keep GPL-2.0-or-later when any of these are true

1. Drupal or Drupal Canvas coupling

Direct use of Drupal or Drupal Canvas runtime APIs, JS runtime contracts, or
in-browser integration APIs. Example: registering behavior code through
`Drupal.behaviors`. Reading `window.drupalSettings` alone is not sufficient for
this check.

2. Distribution boundary

Distribution as part of the Drupal or Drupal Canvas module runtime artifact
without a clear standalone package boundary. Example: the package is only
shipped inside the module's built JS assets and is not published as an
independent npm package with its own release lifecycle.

3. Derivative work origin

Code copied from, or adapted from, Drupal core, Drupal contrib, Drupal Canvas
internals, or other GPL-only code. Example: a utility starts as a copy of logic
from a Drupal core JavaScript file, then is refactored into a monorepo package.

4. Integration style

Tight, in-process integration with Drupal or Drupal Canvas internals. Example:
the package only works when invoked inside the Canvas runtime bootstrap because
it reads internal service state that is not exposed as a public API.

5. Standalone utility test

The package is only meaningfully useful for Drupal or Drupal Canvas integration.

## MIT is usually appropriate when all of these are true:

- No direct Drupal or Drupal Canvas runtime API coupling.
- No copied or adapted GPL-only source.
- Runtime dependency tree is permissive or compatible.
- Package has a clear standalone boundary and can be distributed independently.
- Package remains useful outside Drupal and Drupal Canvas internals.
- Contributor and inbound rights allow MIT relicensing.
- No unresolved distribution, notice, or mixed-license compliance issues.
