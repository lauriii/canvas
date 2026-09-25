# @drupal-canvas/workbench

## 0.13.1

### Patch Changes

- Updated dependencies [73d9fb8]
  - drupal-canvas@0.7.1

## 0.13.0

### Minor Changes

- ed541e3: Support the `drupal-canvas` context hooks in both preview paths: the
  interactive preview and generated preview entries render inside
  `CanvasContextProvider` and `JsonApiClientProvider`, with Workbench page
  defaults and the site data the Vite integration loaded once. Legacy
  `drupalSettings` consumers keep working, and generated previews declare the
  Workbench runtime for the legacy getters and `new JsonApiClient()`.

  Generated previews resolve one site-data snapshot for the legacy
  `drupalSettings` and the hooks: discovered site data, then the static
  settings, then the preview origin.

  The generated entry resolves that snapshot from the same inputs as the
  bootstrap script instead of reading the legacy settings back. Editing a
  component mock refreshes the mounted preview iframe in place (component state
  kept, props and mock data updated) instead of remounting it.

### Patch Changes

- ed541e3: Fetch public Canvas site metadata without credentials, including when
  tooling has an OAuth token configured. Authenticated data requests and write
  operations are unchanged.
- ed541e3: Scan Workbench client modules and host components during initial
  dependency optimization to prevent cold-start previews from mixing React
  optimizer generations. Optional component dependencies are discovered from
  imports rather than required in every project.
- Updated dependencies [ed541e3]
  - drupal-canvas@0.7.0

## 0.12.0

### Minor Changes

- 9eb64a3: Render brand kit colors from the local `canvas.brand-kit.json` in
  previews.
  - Serve a generated `:root` custom property stylesheet from the file's
    `colors` map and load it (in guaranteed cascade order) into the preview
    iframe before the host global CSS.
  - Watch the file so editing a color updates an open preview without a site
    connection.
  - Ship `brand-kit.schema.json` beside the other authored-format schemas,
    covering the whole brand kit file (fonts and colors) for editor tooling and
    `canvas validate`.

### Patch Changes

- Updated dependencies [f65ea20]
  - drupal-canvas@0.6.0

## 0.11.1

### Patch Changes

- Updated dependencies [3ed0539]
  - drupal-canvas@0.5.2

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
