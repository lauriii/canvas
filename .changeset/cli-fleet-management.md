---
'@drupal-canvas/cli': minor
---

Add fleet management: distribute one component library to many Canvas sites with
`canvas library init`, `canvas fleet init/add/list`, `canvas plan`,
`canvas apply`, and `canvas changeset list/restore`. `push` and `pull` are
unchanged when no fleet files are present. Drift detection is advisory and blast
radius is not reported; see the README for the stated non-guarantees.
