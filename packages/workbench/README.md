# @drupal-canvas/workbench

Canvas Workbench is a local preview and development app for Drupal Canvas Code
Components, inspired by Storybook. It scans your project, lists discovered
components and pages, and renders previews in an isolated frame.

Workbench has no required configuration. If your project uses the default Canvas
layout, you can run it from your project root:

```bash
npx @drupal-canvas/workbench@latest
```

## Installation

Install Workbench:

```bash
npm install @drupal-canvas/workbench
```

Then run it from your project root:

```bash
npx canvas-workbench
```

The `canvas-workbench` binary starts the packaged Workbench Vite runtime in your
current working directory.

## Configuration

Workbench can run without a `canvas.config.json` file, but it is useful to add
one when your project does not use the default Canvas paths, or when you want
Workbench to match your CLI setup.

Create `canvas.config.json` in your project root and set only the options you
need:

```json
{
  "componentDir": "./components",
  "pagesDir": "./pages",
  "aliasBaseDir": "src",
  "globalCssPath": "./src/components/global.css"
}
```

Workbench reads these options:

| Property        | Default                         | Used for                                                                                                                 |
| --------------- | ------------------------------- | ------------------------------------------------------------------------------------------------------------------------ |
| `componentDir`  | `process.cwd()`                 | Root directory Workbench scans for `component.yml`, `*.component.yml`, source files, and mocks.                          |
| `pagesDir`      | `"./pages"`                     | Directory Workbench scans for page specs such as `pages/home.json`.                                                      |
| `aliasBaseDir`  | `"src"`                         | Base directory for resolving `@/` imports inside component source files.                                                 |
| `globalCssPath` | `"./src/components/global.css"` | Global CSS entrypoint loaded into the preview iframe. This is where Workbench picks up shared styles and Tailwind setup. |

If `canvas.config.json` is not present, Workbench uses those defaults.
`outputDir` is part of the wider Canvas config surface, but Workbench does not
use it.

## Preview build command

Workbench can export target-based standalone preview artifacts into the output
directory you pass with `--out-dir`.

```bash
npx canvas-workbench preview-build --component-path components/card/component.yml --out-dir .canvas-preview/card
```

```bash
npx canvas-workbench preview-build --page-path pages/home.json --out-dir .canvas-preview/home
```

`--component-path` must point to a component metadata file discovered inside the
configured `componentDir`. `--page-path` must point to a page JSON file inside
the configured `pagesDir`. Paths elsewhere in the project are not supported,
because preview discovery, mocks, and `@/` module resolution are scoped to those
configured roots. If your files live under a different tree, update
`canvas.config.json` first.

For component targets, output writes:

- `component-default.html`
- `component-mock-01.html`, `component-mock-02.html`, and so on for each mock
- `manifest.json`

For page targets, output writes:

- `page-default.html`
- `manifest.json`

`manifest.json` is target-specific:

- Component manifests include `entries.default` and `entries.mocks`.
- Page manifests include only `entries.default`.
- Each entry includes `path` and `label`.
- Entry `path` values are relative to the export directory, so the bundle stays
  portable when you move or copy it.
- The default entry uses `Default`, and mock entries use each mock name.

The command also prints one JSON payload to stdout with `request`, `target`,
`renderMode` (`interactive`), `outDir`, `manifestPath`, summary counts, plus
warnings and errors.

Each exported HTML file is self-contained. Local asset imports, such as fonts
and images, are bundled inline as data URLs. Binary assets, such as WOFF2 and
PNG files, use base64 encoding in those URLs.

## User docs

For end-user guidance, see:
[https://project.pages.drupalcode.org/canvas/code-components/workbench/](https://project.pages.drupalcode.org/canvas/code-components/workbench/)

## How the Workbench runtime works

### Published output

- `dist/client` contains the packaged browser app sources and static assets,
  including the copied client and shared source trees, and copied public assets.
- `dist/server` contains the packaged Node-side runtime, including the
  `canvas-workbench` binary and the published Vite config entry.

### Packaged runtime

- The published binary resolves Vite from the installed package and launches it
  against `dist/client` with the packaged server config from `dist/server`.
- The server build bundles the internal helper packages
  `@drupal-canvas/discovery` and `@drupal-canvas/vite-compat`.
- The packaged client is served from packaged source files through the Workbench
  Vite runtime, not from a standalone production-built browser bundle.
- The packaged runtime exposes `/__canvas/discovery` and
  `/__canvas/preview-manifest`, serves the shell on `/component/...` and
  `/page/...`, and watches the host project for file changes. Source-only edits
  update the preview via Vite HMR without remounting the iframe.

## Strict preview MVP contract

Workbench preview currently uses a strict compatibility contract.

- Workbench renders previews in an iframe at `/canvas/workbench-preview.html`.
- A discovered component is previewable only when its JS entry exists and has a
  supported extension (`.js`, `.jsx`, `.ts`, or `.tsx`).
- Workbench imports component modules through Vite `@fs` URLs, from the
  Workbench Vite process.
- The preview iframe requires a renderable `default` export from each component
  module.
- Optional component CSS entries are loaded in the iframe document when present.

## Compatibility notes

Workbench does not ingest arbitrary host Vite config/plugins automatically.

- Supported module resolution via `@drupal-canvas/vite-compat` — see
  `[packages/vite-compat/README.md](../vite-compat/README.md)`
- Tailwind entrypoint: Workbench loads the host's global CSS (default
  `src/components/global.css`, configurable via `globalCssPath` in
  `canvas.config.json`) through a virtual Vite CSS module that imports the host
  CSS and includes explicit host `@source` scanning so Tailwind processing is
  applied in Workbench context.

## Current architecture decision

Workbench currently uses one Vite dev server process for both:

- the Workbench UI shell, and
- the preview iframe runtime.

The published package keeps that same model. `dist/server` starts Vite against
the packaged client sources in `dist/client`, rather than serving a separately
built static browser bundle.

### Why this is the current choice

- One startup command and one process simplify local DX while the feature set is
  still evolving.
- Discovery middleware, preview manifest APIs, and host compatibility behavior
  stay in one place.
- Shared dev-server context keeps iteration fast for early prototype work.

### Trade-offs we accept for now

- Compatibility changes for host preview imports can still affect Workbench
  runtime behavior.
- Alias and module-resolution boundaries require explicit guardrails (`@wb/*` vs
  host `@/...`).
- Debugging can span Workbench UI, iframe runtime, host imports, and shared Vite
  config.
- Prebundle and dedupe choices are global to one server process and can
  introduce coupling.

### Triggers to split architecture later

Move to stronger separation when one or more of these become recurring:

- frequent regressions from cross-impact between Workbench UI and host preview
  compatibility,
- the need for materially different plugin stacks between Workbench UI and
  preview runtime,
- recurring React/runtime duplication issues that are hard to contain with
  current guardrails,
- growing demand for clearer ownership boundaries and independent
  deployment/runtime controls.

## Future: static export mode outline

Workbench does not currently support a Storybook-style static export of host
component previews. The current `dist/client` output is packaging-oriented for
the Workbench Vite runtime, not a deployable static export. This section
captures a potential direction for later work.

### Goal

Produce a static directory that can be hosted without a running Vite dev server,
while preserving a useful subset of Workbench preview behavior.

### Current blockers

- Discovery and preview manifest are generated by dev middleware at request
  time.
- Preview module URLs are Vite dev `@fs` URLs, not portable build artifacts.
- Host component transforms currently depend on live Vite pipeline behavior.

### Potential design direction

1. Add a dedicated export command (for example, `canvas-workbench export`).
2. Run discovery once at export time and write a static preview manifest JSON.
3. Bundle each previewable host component into export artifacts and emit stable
   module URLs in the manifest.
4. Emit a static global CSS asset strategy (instead of virtual-module runtime
   import).
5. Build Workbench UI and preview iframe against those static manifest/assets.
