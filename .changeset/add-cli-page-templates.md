---
"@drupal-canvas/cli": minor
---

Add page template synchronization support.

- Replace global regions with page templates. Projects using the old global region files or configuration must migrate before running sync commands.
- Pull, push, validate, and reconcile media for page templates stored by default in `page-templates/`.
- Allow pages and content templates to select a page template with the `pageVariant` field.
- Let one page template become the site default with `"default": true`.
- Configure page templates with `pageTemplatesDir`, `sync.pageTemplates`, and `--no-page-templates`.
