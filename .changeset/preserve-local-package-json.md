---
"@drupal-canvas/cli": patch
---

`canvas pull` no longer overwrites an existing local `package.json`. It now
preserves the local file and adds each dependency from the pulled `dependencies`
that is absent from the local `dependencies`, `devDependencies`, and
`peerDependencies` to the local `dependencies`, using the pulled version.
Existing versions, scripts, and other fields are left unchanged.
When no local `package.json` exists, the pulled one is written as before.
