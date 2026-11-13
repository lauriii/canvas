---
'@drupal-canvas/headless': minor
---

Include the strings that components translate with `t()` and `formatPlural()` from `useTranslation()` in the component metadata, so that Drupal synchronizes them, lists them for translation, and returns their translations in `page.context.translations`.

- Strings and their contexts are found when they are written as string literals, in the component's entry file and in those of the components it imports, which it renders itself: with relative imports, or with the `paths` and `baseUrl` of `tsconfig.json` or `jsconfig.json`.
- The build fails for a component with a string that Drupal cannot store, such as an empty one, and reports its file, line, and column.
