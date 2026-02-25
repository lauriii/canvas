# Canvas Workbench (prototype)

Canvas Workbench is an early prototype for a local developer tool, similar to
Storybook, but focused on Drupal Canvas Code Components and Canvas pages.

## Goal

Provide a complete local Drupal Canvas preview experience for Code Components
and Canvas Pages built with Code Components from source code alone.

## What we are testing

- Run a local Vite + React app that scans your local codebase.
- Discover Drupal Canvas Code Components automatically.
- Show discovered components in a UI.
- Read component inputs from each `component.yml` and let you preview them
  through different example values.
- Discover Canvas pages alongside components.
- Represent pages as [json-render](https://json-render.dev) objects.
- Use json-render to preview Canvas pages locally.
- Support hot module reload (HMR) for both component and page previews.
- Explore an additional story metadata format, possibly compatible with
  Storybook.
- Explore integrating Canvas page json-render objects with popular React
  framework routing.

## Setup and run

Workbench ships a `canvas-workbench` binary that starts a bundled Vite server.

```bash
# In packages/workbench
npm run build
npm link

# Generate a new codebase
npx @drupal-canvas/create@latest
# Run in the root of the new project
npm link @drupal-canvas/workbench
npx canvas-workbench dev
```

## How the Workbench runtime works

### `bin/canvas-workbench.js`

- Resolves Vite from this package installation and runs the Vite CLI through
  Node.
- Ensure that Vite uses the `@drupal-canvas/workbench` package root and
  `vite.config.ts`, while forwarding extra CLI flags.
- Keeps `cwd` as the directory where you run the command, so discovery scans
  your project, not the workbench package.

### `vite.config.ts`

- Sets `root` to the workbench package, but allows Vite file access to both the
  workbench code and the host project directory.
- Registers a custom discovery plugin (from `@drupal-canvas/discovery`) that
  runs `discoverCodeComponents({ scanRoot: process.cwd() })` and caches results.
- Exposes discovery data at `/__canvas/discovery` as JSON for the UI.
- Exposes preview manifest data at `/__canvas/preview-manifest` as JSON for
  preview runtime decisions.
- Uses routes:
  - `/component/<component-id>` for component previews.
  - `/page/<slug>` for discovered pages, where `slug` comes from
    `pages/<slug>.json`.
- Uses `@drupal-canvas/vite-compat` for shared compatibility behavior — see
  [`packages/vite-compat/README.md`](../vite-compat/README.md).
- Watches the host project for relevant file changes (`component.yml`,
  `*.component.yml`, and JS(X)/TS(X)/CSS files), sends targeted HMR update
  events, refreshes discovery when needed, and reloads only the preview iframe
  for source-only changes.

## Strict preview MVP contract

Workbench preview currently uses a strict compatibility contract.

- Workbench renders previews in an iframe at `/__canvas/preview-frame`.
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
  [`packages/vite-compat/README.md`](../vite-compat/README.md)
- Temporary hardcoded Tailwind entrypoint: Workbench expects host CSS at
  `src/components/global.css`. This is loaded through a virtual Vite CSS module
  that imports the host CSS and includes explicit host `@source` scanning so
  Tailwind processing is applied in Workbench context.

## Current architecture decision

Workbench currently uses one Vite dev server process for both:

- the Workbench UI shell, and
- the preview iframe runtime.

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
component previews. This section captures a potential direction for later work.

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
