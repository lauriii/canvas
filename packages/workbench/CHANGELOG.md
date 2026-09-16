# @drupal-canvas/workbench

## 0.11.0

### Minor Changes

- da4015e: Add page template discovery and preview support.
  - Replace global region discovery and preview rendering with page templates.
  - Load page templates from the configured directory, `page-templates/` by
    default, and refresh previews when their files change.
  - Use discovered page templates in page and full content template previews.
  - Accept the `pageVariant` field in page and content template specs.

### Patch Changes

- 78e3ff2: Validate authored Code Component metadata against the shared Canvas
  JSON Schema.
  - Surface source-located metadata errors without stopping Workbench discovery
    or file watching.
  - Derive content entity reference preview targets from
    `dataDependencies.entityFields`.

- Updated dependencies [108e9d4]
  - drupal-canvas@0.5.1

## 0.10.0

### Minor Changes

- 761cfbb: Trust system CA certificates to support working with DDEV
  environments over HTTPS.
- 761cfbb: Set minimum Node.js requirement: >=22.19.0 <23 || >=24.5.0.

### Patch Changes

- Updated dependencies [761cfbb]
  - drupal-canvas@0.5.0
