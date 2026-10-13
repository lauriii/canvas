---
name: canvas-code-component-runtime-portability
description:
  Use before adding or changing a shared runtime API for Canvas Code
  Components, such as a `drupal-canvas` export, hook, provider, or page and site
  context data, or any code that reads Drupal settings or globals.
---

# Code Component runtime portability

Code Components share one set of runtime APIs across three frontend modes. ADR
0021 (`docs/adr/0021-code-component-runtime-compatibility-across-frontend-modes.md`)
records the architecture; read it before changing a shared API. Settle the
design before writing code: an API that reads Drupal globals can pass every
Drupal test and still fail silently in Headless.

## The runtimes

Each integration gives components the same context and JSON:API client through
providers:

- **Drupal-rendered pages** render each component as a browser island. Drupal
  attaches only the data that a component's imports need.
- **Workbench** renders interactive and generated previews with a Drupal site's
  site data and default page data.
- **Headless** applications receive page context with the content tree and
  often render components on the server before hydrating them.

## Where new code belongs

- **Portable API**: keep reusable logic independent of runtime globals; React
  hooks can obtain runtime data from context. Never read Drupal settings or
  globals, and never branch on the frontend mode.
- **Integration adapter**: the only place that turns one runtime's data into the
  shared context.

The deprecated legacy APIs keep working where they work today. Preserve their
behavior, but never add or extend APIs there or copy their global reads.

## Context, not globals

- When context is missing, hooks return `null` and warn once. Do not substitute
  defaults; valid empty values are not missing context. Consumers handle `null`.
- Context belongs to the rendered tree and request; never cache it in module
  state.
- Browser globals are fine, but server rendering has no `window` or `document`;
  use them in effects or event handlers.
- Keep components synchronous. Headless applications prefetch server-rendered
  data and pass it as SWR fallback data.
- Keep the page and site context payload serializable: it never carries clients,
  credentials, or preview tokens. Components receive the JSON:API client through
  its own React provider instead.

## Adding context data

- Deliver it in all three runtimes.
- Make it discoverable: Drupal attaches data based on what a component imports,
  so register new APIs with dependency detection. Otherwise their context is
  `null` on Drupal pages.
- Keep types, schemas, and defaults consistent across runtimes, including which
  empty values are valid.
- Add caching metadata for everything a value varies by, such as language,
  route, or configuration. Keep request-specific data out of site context.

## Adding or changing exports

- Give every public export an explicit decision: support all runtimes,
  deprecate it with replacement guidance, or reject unsupported use with an
  actionable error.
- Import React hooks and providers from `drupal-canvas/react`; keep
  framework-independent utilities in `drupal-canvas`.
- Make a new entry point resolve in Drupal's runtime and in component builds,
  document it, and add a changeset.

## Validate

ESLint enforces the portability boundary in `drupal-canvas` sources; if it
reports your code, change the design instead of suppressing it. Lint does not
replace runtime checks, so also confirm that:

- each affected runtime renders the same result from the same data, including
  server rendering and hydration in Headless;
- missing and empty context behave as designed, without unhandled errors;
- Drupal attaches the data for a component that imports only the new API;
- cached responses vary correctly;
- lint, type checks, and existing tests pass.
