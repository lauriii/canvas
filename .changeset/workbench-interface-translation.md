---
'@drupal-canvas/workbench': minor
---

Translate previews into the site's interface languages. When the previewed components, or the components they import, use `useTranslation()`, a language selector appears above the preview. Previews start in the language that Drupal negotiates for the site data, and Workbench looks up the translations of the previewed strings in Drupal, without registering them. The translations follow changes to the components. `preview-build` translates the previews it generates too, into the negotiated language or the one that the new `--langcode` option requests.
