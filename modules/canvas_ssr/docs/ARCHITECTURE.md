# Architecture

This is a map of the code: where things live, and how a render moves through
them. How canvas_ssr behaves is in the [specs](specs/), why it's built this way
is in the [decision records](adr/), and the words it uses are defined in the
[glossary](GLOSSARY.md). Each class documents its own contract. Start with
[`Renderer`](../src/Renderer.php) and
[`RunnerInterface`](../src/JsRunner/RunnerInterface.php).

## Bird's-eye view

[`Renderer`](../src/Renderer.php) gathers the JavaScript a component needs,
hands it to a runner, and invokes the component's main module. The island
element, [`AstroIslandSsr`](../src/Element/AstroIslandSsr.php), connects it to
Drupal's render pipeline. Everything else serves one of the two.

Runners are pluggable. A runner module depends on `canvas_ssr`, never the
reverse, and tags its runner `canvas_ssr.runner` with a priority. Grep for that
tag to find them. Whatever a runner needs to run, like a PHP extension or a
binary, is its runner module's requirement. Apart from the included Deno runner,
nothing in canvas_ssr names a runner.

## Code map

Paths are under `src/` unless they say otherwise.

- **[`ComponentInfo/`](../src/ComponentInfo/)** works out what a render
  involves. `ComponentGraph` collects the component and every component it
  imports, using drafts in a preview. `ComponentModules` names their modules, so
  a draft never shares a URL with the published component, and builds the
  [main module](GLOSSARY.md#main-module) a render invokes. A [boundary
  checker](GLOSSARY.md#boundary-checker) per framework decides whether the
  markup must hydrate. Specs: [island
  rendering](specs/semi-detailed/01-island-rendering/spec.md).
- **[`JsParser/`](../src/JsParser/)** reads a module's imports and directives,
  once per source. `ParsedModuleCache` keeps the results between requests. A
  parse past its memory or time limit gives up, so deeply nested code can't
  run PHP out of memory. Specs: [render
  performance](specs/semi-detailed/09-render-performance/spec.md).
- **[`JsModules/`](../src/JsModules/)** supplies the modules a component can
  import. Tagged [module providers](GLOSSARY.md#module-provider) supply them,
  and `AllProvidedModules` merges them. `SsrImportMap` adds their specifiers to
  the [site import map](GLOSSARY.md#site-import-map), and runners resolve every
  bare specifier through it. Specs: [module
  resolution](specs/semi-detailed/02-module-resolution/spec.md).
- **[`JsModules/BaseModules`](../src/JsModules/BaseModules.php)** builds the
  [base](GLOSSARY.md#base): every provided module, plus what a render of each
  exposed component needs. A runner builds from it once and keeps the result, so
  a later render registers only what the base lacks, such as a preview's drafts.
  Specs: [render
  performance](specs/semi-detailed/09-render-performance/spec.md).
- **[`DrupalSettings/`](../src/DrupalSettings/)** sets the [site
  data](GLOSSARY.md#site-data) and [page data](GLOSSARY.md#page-data) a
  component declares in `drupalSettings`, where Canvas puts them in the
  browser, and its graph's [interface
  translations](GLOSSARY.md#interface-translations). A declared key that no
  provider gives sends the island to CSR. Specs: [site and page
  data](specs/semi-detailed/04-site-and-page-data/spec.md), [interface
  translations](specs/semi-detailed/05-interface-translations/spec.md).
- **[`Fetch/`](../src/Fetch/)** gives components a `fetch()`. `SameSiteFetch`
  runs allowlisted same-site GET and HEAD requests as Drupal subrequests, and
  `FetchMarshaler` carries them between PHP and JavaScript. Specs: [same-site
  fetch](specs/semi-detailed/06-same-site-fetch/spec.md).
- **[`JsRunner/`](../src/JsRunner/)** holds the extension point,
  `RunnerInterface`. `RunnerSelector` renders on the highest-priority runner
  that's available, and falls back to `UnavailableRunner` when none is.
  `RunnerGuard` stands in for it to every caller, keeps calls from reaching
  the runner mid-invoke, resets it after a runner failure and at the end of
  each request, and skips what the runner already has. Specs: [runner
  contract](specs/semi-detailed/07-runner-contract/spec.md), [runner
  selection](specs/semi-detailed/08-runner-selection/spec.md).
- **[`JsRunner/Deno/`](../src/JsRunner/Deno/)** is the included, default runner.
  It runs components in a Deno process with no permissions. Its
  [README](../src/JsRunner/Deno/README.md) covers installing it, its sandbox and
  its caches. Specs: [Deno runner](specs/semi-detailed/10-deno-runner/spec.md).
- **[`js/`](../js/)**, at canvas_ssr's root, holds JavaScript that SSR provides:
  the [realm setup module](GLOSSARY.md#realm-setup-module), the [framework
  renderer](GLOSSARY.md#framework-renderer), a shim for swr and a module that
  warms tailwind-merge in the base.

## Rendering flow

1. A render array asks for an `astro_island`.
2. [`AstroIslandSsr::preRenderIsland()`](../src/Element/AstroIslandSsr.php)
   loads the component and renders its slots to HTML once. SSR and CSR both use
   that HTML, and the slots' cacheability still bubbles to the island.
3. If the island's import map isn't the site's, the island goes to CSR.
   Otherwise [`Renderer::render()`](../src/Renderer.php) puts the
   [base](../src/JsModules/BaseModules.php) in use, which brings the
   [SSR import map](../src/JsModules/SsrImportMap.php) with it.
4. `Renderer` sends the island to CSR if a precondition the
   [island-rendering spec](specs/semi-detailed/01-island-rendering/spec.md)
   lists isn't met, such as an available runner, or a declared key of site or
   page data that has a
   [provider](../src/DrupalSettings/DrupalSettingsResolver.php).
5. `Renderer` registers what the base lacks, and builds the component's main
   module. The main module imports the [realm setup
   module](../js/realm-setup.js), which locks the realm before any component
   runs, then the drupalSettings module, the [framework
   renderer](../js/renderers/preact.js) and the component. Every registered
   module gets hooks around its evaluation, which freeze its exports and
   keep what a library changed
   ([`ModuleEvaluationHooks`](../src/JsModules/ModuleEvaluationHooks.php)).
   If the realm may not have evaluated a library the island's components
   import, `Renderer` first invokes the libraries module, so they evaluate
   before the components do. ADR 11 says why.
6. The runner invokes the main module, and `Renderer` reads the result as HTML
   or a failure. On success, the island gets the render's cacheability,
   including that of every component in the graph.
7. The island is static if nothing in its graph needs the browser. Otherwise
   it's a `<canvas-island client="load">` that hydrates.
8. On any failure, the island falls back to CSR, as `client="only"`. A
   [transient error](GLOSSARY.md#transient-error) is cached briefly, and
   [`TransientErrorSubscriber`](../src/EventSubscriber/TransientErrorSubscriber.php)
   makes a page with one expire with it, since Drupal's page cache ignores
   max-age.
