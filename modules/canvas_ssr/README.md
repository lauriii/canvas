# Canvas SSR Module

`canvas_ssr` renders Canvas code components on the server, so their HTML is in
the first paint. When SSR can't render an island, it falls back to Canvas's
usual client-side rendering (CSR).

## How to use

1. Enable `canvas_ssr`. It needs Drupal 11.4 or later.
2. To use the Deno [runner](docs/GLOSSARY.md#runner), install
   [Deno](https://deno.com), or a symlink to it, at `vendor/bin/deno`, in
   Composer's vendor directory.
3. Clear caches (`drush cr`).

Other Drupal modules can supply runners, and the highest-priority one that can
run on the system renders.

A component that needs the browser, for state, effects or event handlers, must
start with `'use client';`, which marks a [client
boundary](docs/GLOSSARY.md#client-boundary). Its server-rendered markup then
hydrates. Without the directive, an island is final markup and ships no code,
so it isn't interactive. The [island rendering
spec](docs/specs/semi-detailed/01-island-rendering/spec.md) has the details.

## Tests

Canvas's `composer run phpunit` runs canvas_ssr's tests with its own. To run
only canvas_ssr's, run them by path from the Composer project root, with
Canvas's `phpunit.xml.dist` and `SIMPLETEST_DB` set:

```bash
vendor/bin/phpunit -c path/to/canvas/phpunit.xml.dist path/to/canvas_ssr
```

If [Paratest](https://github.com/paratestphp/paratest) is installed, you can
use it instead. It runs tests in parallel, so it's faster, but it takes only
one path per run:

```bash
vendor/bin/paratest -c path/to/canvas/phpunit.xml.dist path/to/canvas_ssr
```

The kernel tests are skipped on Drupal before 11.4.

Tests that need a runner are in the `canvas_ssr_requires_runner` group. They
render on the runner whose class the `CANVAS_SSR_TEST_RUNNER_CLASS` environment
variable names, or on Deno's if it's not set. If that runner isn't the one
selected, for example because it can't run here, they're skipped. But
`RunnerSelectedTest` fails, so the run as a whole doesn't pass. Its message
says what to do.

## Where to read next

- [docs/GLOSSARY.md](docs/GLOSSARY.md) defines the words the code and docs
  use. Read it first.
- [docs/specs/](docs/specs/) says how canvas_ssr behaves. Start with the
  [overview](docs/specs/overview/spec.md).
- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) maps the code and walks through
  a render.
- [docs/CONFIGURATION.md](docs/CONFIGURATION.md) lists the settings a site
  can change in its `services.yml`.
- [docs/adr/](docs/adr/) records the decisions behind it, and what else was
  considered.
- [src/JsRunner/Deno/README.md](src/JsRunner/Deno/README.md) covers the included
  Deno runner: installing it, its sandbox and its caches.

