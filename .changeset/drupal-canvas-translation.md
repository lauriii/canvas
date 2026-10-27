---
"drupal-canvas": minor
---

Add a `useTranslation()` hook to `drupal-canvas/react` for translating interface strings in Code Components.

- Its `t()` function has the same signature as `Drupal.t()`: a source string, placeholder replacements, and an optional `context`. It returns the source string when there is no translation.
- Its `formatPlural()` function has the same signature as `Drupal.formatPlural()`. It picks the plural form with the language's plural formula, and replaces `@count`.
- Both return plain text. All placeholder prefixes insert the value as is; `%` does not add `<em>` markup.
- It reads the translations from the new optional `translations` key of `CanvasContext` (type `Translations`, shaped like Drupal's `drupalTranslations`: `strings` and an optional `pluralFormula`), so the same component works in Drupal-rendered pages, Workbench, and Headless. `CanvasDataV0` includes the new `translations` key that the Drupal adapter turns into it.
