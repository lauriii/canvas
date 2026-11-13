---
"drupal-canvas": minor
---

Add a `useTranslation()` hook to `drupal-canvas/react` for translating interface strings in Code Components.

- Its `t()` and `formatPlural()` functions take the same arguments as `Drupal.t()` and `Drupal.formatPlural()`. Without a translation, `t()` returns the source string, and `formatPlural()` the singular for a count of 1 and the plural otherwise. A translation can have more forms: `formatPlural()` picks one with the language's plural rules, and replaces `@count`.
- Only `@` placeholders, such as `@name`, are supported. Unlike Drupal's functions, which return HTML, they return text for React to render: placeholder values appear exactly as given, and HTML in a string is shown as text.
- It reads the translations from the new optional `translations` key of `CanvasContext` (type `Translations`, shaped like Drupal's `drupalTranslations`: `strings` and an optional `pluralFormula`), so the same component works in Drupal-rendered pages, Workbench, and Headless. `CanvasDataV0` includes the new `translations` key that the Drupal adapter turns into it.
- `TranslationsProvider` replaces the translations for the components below it, e.g. with an application's own dictionary, instead of merging with them; `null` means none. The nearest provider takes precedence over the context's translations.
