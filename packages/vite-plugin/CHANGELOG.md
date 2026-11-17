# @drupal-canvas/vite-plugin

## 0.4.0

### Minor Changes

- ce07517: Support Vite 8.

### Patch Changes

- d678d4d: Expose the site's interface language as `langcode` in the site data,
  so that `useDateFormatter()` formats dates in the site's language in Workbench
  previews.

## 0.3.0

### Minor Changes

- ed541e3: Load the site-data endpoint once (it is public; a token is sent when
  available) and expose the resulting site data through the
  `virtual:drupal-canvas/site-data` module and the plugin's
  `api.getCanvasSiteData()`, in addition to the existing `drupalSettings`
  injection.

### Patch Changes

- ed541e3: Fetch public Canvas site metadata without credentials, including when
  tooling has an OAuth token configured. Authenticated data requests and write
  operations are unchanged.

## 0.2.0

### Minor Changes

- 761cfbb: Set minimum Node.js requirement: >=22.19.0 <23 || >=24.5.0.
