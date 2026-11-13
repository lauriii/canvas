---
'@drupal-canvas/cli': minor
---

Send the strings that components translate with `t()` and `formatPlural()` from `useTranslation()` to Drupal, so that they can be translated on the site.

- Strings and their contexts are found when they are written as string literals in the component's own source code. Drupal also provides translations for imported components, using each component’s own strings.
- `build` and `push` fail for a component with a string that Drupal cannot store, such as an empty one, and report its file and line.
