---
'@drupal-canvas/cli': minor
---

Add fleet management: keep one component library in sync across many Canvas
sites. Describe your sites in `canvas.fleet.json`, then use `canvas plan` to see
what would change and `canvas apply` to push to all of them at once. Components
that someone has edited on a site are skipped rather than overwritten, and
`canvas changeset restore` puts a single site back the way it was. `push` and
`pull` are unchanged if you do not add the fleet files. See the README for what
these commands can and cannot tell you.
