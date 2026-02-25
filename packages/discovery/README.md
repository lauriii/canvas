# @drupal-canvas/discovery

Filesystem discovery for Drupal Canvas Code Components and pages.

## What it does

- Scans a `scanRoot` for `component.yml` and `*.component.yml` files.
- Applies root `.gitignore` rules. Ignores common build and dependency folders
  (`node_modules`, `dist`, `.git`, `.next`, `.turbo`, `coverage`).
- Resolves JavaScript entries in extension priority: `.ts`, `.tsx`, `.js`,
  `.jsx`.
- Attaches optional `.css` entries when present.
- Discovers top-level page specs from `pages/*.json`.

## File conventions

Index-style component:

```text
components/card/
  component.yml
  index.ts
  index.css (optional)
```

Named components in one directory:

```text
components/icons/
  alert.component.yml
  alert.ts
  alert.css (optional)
```

When both `component.yml` and `*.component.yml` exist in the same directory,
named metadata files are used.

## API

```ts
import { discoverCodeComponents } from '@drupal-canvas/discovery';

const result = await discoverCodeComponents({
  scanRoot: '/absolute/path/to/project',
});
```

`result` includes:

- `components`: discovered component records (`id`, `kind`, paths, and optional
  CSS path).
- `pages`: discovered page records (`name`, `slug`, absolute path, and relative
  path).
- `warnings`: discovery warnings.
- `stats`: `{ scannedFiles, ignoredFiles }`.

Warning codes:

- `missing_js_entry`
- `duplicate_definition`
- `conflicting_metadata`

## Scripts

- `npm test`
- `npm run type-check`
