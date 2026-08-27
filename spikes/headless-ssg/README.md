# Headless SSG spike (scratch branch material)

Companion artifacts for `HEADLESS-SSG-RESEARCH.md` (untracked, repo root) and
the `headless-static-builds` OpenSpec change in the canvas-specs repository.
Everything here is spike evidence, not product code; the only product-shaped
piece on this branch is the route-inventory prototype in
`modules/canvas_headless/`.

## Contents

- `patches/spike-<framework>.patch`: the complete diff each spike applied on
  top of the corresponding `drupal-canvas/headless-templates` template (at
  `a6d4288`) to get a static build against a live Canvas backend. Astro is
  8 files (config variants, `getStaticPaths` enumeration, a prop-shape shim
  for the test site's content); Next.js is 2 files; Nuxt is 1 file.
- `reports/report-next.md`, `reports/report-nuxt.md`: the per-framework
  empirical reports (attempts, verbatim errors, outcomes). The Astro run is
  documented directly in `HEADLESS-SSG-RESEARCH.md`.

## Reproducing

1. Clone `https://github.com/drupal-canvas/headless-templates`, copy the
   framework directory, `npm install`.
2. Apply the matching patch from `patches/`.
3. Set `CANVAS_SITE_URL` to a Drupal site with `canvas_headless` enabled
   (published Canvas pages required; the URL must be resolvable from Node).
4. Build: Astro `npm run build` (swap in `astro.config.pure-static.mjs` for
   the no-server shape), Next `npm run build` (hybrid) or with
   `output: 'export'` per the report, Nuxt `npx nuxi generate`.
