# 10. A runner builds from a base once, and using it never changes what a render outputs

Status: Accepted

## Context

Nearly every [render](../GLOSSARY.md#render) on a site registers the same
modules: the [framework](../GLOSSARY.md#framework), the Canvas runtime, the
shared chunks and tailwind-merge. Registering them for each render means
reading every file and handing it to the runner on every request, and the
runner compiling every module again. For the Deno runner, that's seeding each
module into its cache and Deno analyzing and compiling it.

Everything in the earlier ADRs works without a base. The base is what makes it
cheap.

## Decision

`BaseModules` defines a [base](../GLOSSARY.md#base). It holds every [provided
module](../GLOSSARY.md#provided-module), and what a render of each
[exposed](../GLOSSARY.md#exposed) [component](../GLOSSARY.md#component)
registers. [Preload modules](../GLOSSARY.md#preload-modules) evaluate the
libraries those components import: the realm setup module, a module that sets
the [site data](../GLOSSARY.md#site-data), the framework renderer, and one
module for each library. One of them calls tailwind-merge, which builds its
class map the first time it's used. The components' main modules, and their
[libraries modules](../GLOSSARY.md#libraries-module), are [modules to
compile](../GLOSSARY.md#modules-to-compile), which a runner may compile but
never [evaluates](../GLOSSARY.md#evaluate) where a render sees it. A component's
module evaluates only in a request that renders it: what a module does as it
evaluates lasts the realm, and in the browser a component on no page changes
nothing. A render evaluates its island's libraries before its components too,
each on its own, so it's the same whether or not a runner evaluated them from
the base. The base carries the [import map](../GLOSSARY.md#import-map) renders
resolve through, so a runner gets the map only with the base it was evaluated
under. The base's id is a hash of its content, map included, and of the [site
data](../GLOSSARY.md#site-data). So the same id always means the same base.
Drupal caches only the id.

A [runner](../GLOSSARY.md#runner) builds from the base once, under its id, and
keeps what it builds. It keeps it through [`reset()`](../GLOSSARY.md#reset), and
across requests if it can. A render then registers only what the base doesn't
have, like a [preview](../GLOSSARY.md#preview)'s [drafts](../GLOSSARY.md#draft).
If the base already indexes an [island](../GLOSSARY.md#island)'s component,
the island renders straight from it, with no walk and no registering.

A base never changes output. A URL names one source, so a render never registers
different source at a base module's URL. A stale base costs time, never
correctness. The conformance suite checks that every runner renders the same
with or without a base.

Without a [base in use](../GLOSSARY.md#base-in-use), the renderer falls back to
[CSR](../GLOSSARY.md#csr-fallback) instead of rendering without one: for a
minute if the base failed, or until the runner can use it if it isn't built
yet. If a runner finds a component's main module can't
[load](../GLOSSARY.md#load), the component goes to CSR without an
[invoke](../GLOSSARY.md#invoke).

For the Deno runner, building means making a [base
cache](../GLOSSARY.md#base-cache) on disk:

1. The base's modules are [seeded](../GLOSSARY.md#seed) under their own
   [origin](../GLOSSARY.md#origin).
2. A Deno process imports every preload module and module to compile,
   which fills Deno's and V8's caches. The process is then thrown away, so
   what they do as they evaluate reaches no render. So does what they built
   as they evaluated, like tailwind-merge's class map: each request's
   process evaluates what its islands import again.
3. A manifest is written last.

It's built once per base id, under an exclusive lock, per OS user, and only
the most recently used base caches are kept. The [Deno runner
spec](../specs/semi-detailed/10-deno-runner/spec.md) says where, and how many.
Each [runner cache](../GLOSSARY.md#runner-cache) links to a base cache and
copies its cache databases.

## Alternatives considered

### Register everything on every render

Every request would read every file and evaluate every module again. That's what
the base exists to avoid.

### Cache the base's modules in Drupal, not just its id

Runners keep what they build, so a cached copy would only help a runner that
hasn't built from the base yet. That runner builds from it once and keeps the
result anyway.

### Render without the base when it fails

That would mean registering every [provider](../GLOSSARY.md#module-provider)'s
module on a cold runner, for each render, and hiding the failure. Falling back
for a minute makes the failure visible and limits the cost.

### Evaluate every exposed component in the base

The Deno runner's build does evaluate them, but only to fill its caches, in a
process that's thrown away. A runner that kept the realm its build evaluated
would start every realm with every component evaluated, whether or not it's on
the page. A component's module that added a global or
[mutated](../GLOSSARY.md#mutate) preact's options as it evaluated would change
islands on every page, though in the browser it changes nothing until a page
it's on loads. It would also make output depend on the base. So the base only
has components compiled, and each evaluates in the request that renders it, as
it does on the Deno runner anyway.

### Put drafts in the base

Drafts change with every keystroke in the editor, so they're registered on top
of the base, at their own URLs.

### A base cache per site

One per OS user is enough. A base cache is named by its content, so sites run by
the same user can share it. For safety, all that matters is who can write the
directory.

## Consequences

- The first render after a change pays for the build. Renders at the same moment
  wait for the lock, instead of each building their own. For the Deno runner,
  that wait is bounded by its medium time limit, and a build that takes longer
  finishes after the response. Any runner can do that: the contract lets it say
  a base is pending, and canvas_ssr's subscriber asks it to build after the
  response, then invalidates the CSR fallbacks.
- A warm render reads no module source, and finds the base's id in one cache
  lookup.
- A warm render still evaluates the components on its page, once each per
  request. On the Deno runner, it evaluates the libraries they import too,
  since each request starts a new process.
- The base id depends on the site data. So a site served under two
  hostnames has two bases, and each render gets its own hostname.
- Both cache roots are safe to delete. Everything gets rebuilt when needed.
- Each runner decides what "keeps what it builds" means for it: only compiled
  code, as the Deno runner does, or the realm the build evaluated. The
  contract only says a base can't change output. The [runner
  contract](../specs/semi-detailed/07-runner-contract/spec.md) says what each
  choice means.

Specs: [render
performance](../specs/semi-detailed/09-render-performance/spec.md), [Deno
runner](../specs/semi-detailed/10-deno-runner/spec.md).
