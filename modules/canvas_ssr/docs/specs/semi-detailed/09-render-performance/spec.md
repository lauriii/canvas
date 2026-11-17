# Render Performance

## Purpose

This spec covers what a [render](../../../GLOSSARY.md#render) costs once the
site is warm, and what the caching behind that must never get wrong. A warm site
has rendered since the last change to its
[components](../../../GLOSSARY.md#component), pushed modules or configuration.
The mechanism is the [base](../../../GLOSSARY.md#base): the modules nearly every
render registers, plus the [preload
modules](../../../GLOSSARY.md#preload-modules) that
[evaluate](../../../GLOSSARY.md#evaluate) the libraries among them, which a
[runner](../../../GLOSSARY.md#runner) builds from once and keeps what it built.
For the Deno runner, that's what Deno compiled, not what evaluated. A render
still evaluates the components on its page, once each per request, and on the
Deno runner the libraries they import too. There's no wall-clock budget for a
render here. How a base reaches a runner, and what a runner may keep from it, is
in [runner-contract](../07-runner-contract/spec.md).

## Requirements

### Requirement: A warm render reads no file

A render on a warm site SHALL NOT read any file. That means no module source, no
component's compiled JS, and nothing for a
[preview](../../../GLOSSARY.md#preview). What a runner reads from its own caches
is up to it; [deno-runner](../10-deno-runner/spec.md) covers the included one.

#### Scenario: A warm request renders without touching a file

- **GIVEN** a later request on the same site, with nothing changed
- **WHEN** an [island](../../../GLOSSARY.md#island) that rendered before renders
  again
- **THEN** no file is read

#### Scenario: A preview with a draft renders without touching a file either

- **GIVEN** a warm site, and a [draft](../../../GLOSSARY.md#draft) of a
  dependency that an editor saved in an earlier request
- **WHEN** an island of the root renders in a preview
- **THEN** no file is read, and the draft renders

### Requirement: A warm render doesn't hand the runner what it already has

On a warm site, a render SHALL give the runner only what the base lacks. For a
component the base covers, that's nothing. For a preview, it's only the draft,
the modules that import it, and the modules built for that render. Within a
request, a module or a base given once SHALL NOT be given again.

#### Scenario: A component the base covers is invoked, not registered

- **GIVEN** a warm site
- **WHEN** an island of an [exposed](../../../GLOSSARY.md#exposed) component
  renders
- **THEN** the runner is asked to [invoke](../../../GLOSSARY.md#invoke) a module
  the base already holds, and no module is registered

### Requirement: A warm render finds what it needs in one cache lookup

Finding the base's id SHALL cost one cache read, with no second read to follow a
redirect. That read SHALL be keyed by everything the id varies with: the site
URL, plus the contexts of the site's import map and [site
data](../../../GLOSSARY.md#site-data).

#### Scenario: A warm read finds the id in one lookup

- **GIVEN** a warm site served under a base path
- **WHEN** the base id is read
- **THEN** it's found at a key that includes the site URL and the theme, and
  nothing is stored at the context-free key

### Requirement: Work every render would repeat is done once

The base SHALL hold every exposed component, with its
[graph](../../../GLOSSARY.md#component-graph), and every [provided
module](../../../GLOSSARY.md#provided-module). Seeding and compiling them
SHALL be paid for when a runner builds from the base, not in each render.
Modules SHALL evaluate once in a realm, and stay evaluated from one island to
the next.

#### Scenario: The base has every exposed component, with its graph

- **GIVEN** an exposed root with a dependency, and a component that isn't
  exposed
- **WHEN** the base is defined
- **THEN** it holds the root, the dependency and every provided module, but not
  the unexposed component
- **AND** it preloads the realm setup module, the site data, the framework
  renderer and each library the components import, then the warm-up, and has
  the components only compiled

#### Scenario: Module scope survives from one island to the next

- **GIVEN** two components that read site data at module scope, and a plain one
  between them
- **WHEN** islands of all three render in turn
- **THEN** the third reads the site data, even though the second island unset it

### Requirement: Caching never changes output

A render on a warm site SHALL produce the same markup as a render on a cold one,
including the hydration decision. A component the base doesn't cover SHALL
render as if there were no base.

#### Scenario: Components the base covers render as they would

- **GIVEN** a hydrating component and a static one, both in the base
- **WHEN** islands of them render on a warm site
- **THEN** both are invoked from the base, the first hydrates and the second
  doesn't

### Requirement: Caching never serves stale code

A saved change to a component, a dependency, a pushed module or a [module
provider](../../../GLOSSARY.md#module-provider) SHALL show in the next render,
whatever the base holds. A base SHALL only ever be handed over with its own id.
Site data read at module scope SHALL be the current request's. Each base path
the site is served under SHALL keep its own base, because [module
URLs](../../../GLOSSARY.md#module-url) differ between them.

#### Scenario: A component whose graph changed after the base renders fresh

- **GIVEN** a warm site whose base holds a root and its dependency
- **WHEN** the dependency is saved with new source and the root renders
- **THEN** the new source is what renders, registered for this render instead of
  taken from the base

#### Scenario: A request that pushes and then renders starts over on the new map

- **GIVEN** an island rendered from a base
- **WHEN** the global asset library is saved and the island renders again in the
  same request
- **THEN** the render uses a new base, and an import map with the pushed entry
- **AND** a third render, with nothing changed, doesn't start anything over

#### Scenario: A later request on another hostname gets its own site data

- **GIVEN** a component that reads the site URL at module scope, rendered on one
  hostname
- **WHEN** a later request on another hostname renders it
- **THEN** it renders a URL with the other hostname

### Requirement: Reading a component's code has a limit

Reading a component's imports and directives from its compiled JS SHALL use no
more than 32 MiB of memory, and take no longer than the medium time limit from
the `canvas_ssr.timeouts` container parameter. A component past either SHALL
count as unparsable, so its islands fall back to CSR, and a warning SHALL name
it. Going over the memory limit depends only on the source, so it SHALL be
remembered like any other parse failure, and the fallback cached as a render
would be. Going over the time limit depends on how busy the host is, and going
over what PHP's `memory_limit` leaves the request depends on what else the
request did. So for either, the next request SHALL try again, and it's a
[transient error](../../../GLOSSARY.md#transient-error). A base left without a
component for such a reason SHALL be kept only as long as a transient error.

#### Scenario: Deeply nested code is given up on, not a fatal error

- **GIVEN** a component whose compiled JS nests brackets 20,000 deep
- **WHEN** it's parsed
- **THEN** it counts as unparsable, PHP's memory grows by less than twice the
  limit, and a warning names the component
- **AND** a later request doesn't parse it again

#### Scenario: A parse past the medium limit is tried again in the next request

- **GIVEN** a medium time limit shorter than a component takes to parse
- **WHEN** it's parsed twice in one request, then in another request
- **THEN** it counts as unparsable each time, and a warning names it once per
  request

### Requirement: A failed warm-up degrades, it doesn't break

When the base can't be defined, islands SHALL fall back to CSR for one minute,
as they do for a [runner failure](../../../GLOSSARY.md#runner-failure), unless
it fails the same way until it changes, as when a provider gives a module that
isn't valid UTF-8. Then the fallback SHALL be cached as a render would be, with
what the base depends on. When the runner can't use the base yet, as while
it's being built, they SHALL fall back to CSR cached with
`canvas_ssr_base_pending` and the base's own tags, and no expiry, so a site's
pages aren't re-rendered while it waits. The tag is invalidated once the runner
builds from the base after a response, and a new base ends them too. When the
runner can't use the base and doesn't say it will, nothing would invalidate the
tag, so they SHALL fall back as for a runner failure. The cost of finding that
out SHALL be paid once per request, not once per island, and the next request
SHALL ask again, even in a PHP process that keeps its services between
requests. A component that can't be [loaded](../../../GLOSSARY.md#load) from
the base SHALL fall back to CSR without being invoked, and one that can't be
put in the base SHALL render on its own.

#### Scenario: A base the runner can't use yet falls back to CSR until it can

- **GIVEN** a runner that can't take the base yet
- **WHEN** two islands render
- **THEN** both fall back to CSR, cached with `canvas_ssr_base_pending` and the
  base's tags, with no expiry, and nothing invoked
- **AND** the base is defined once, not once per island
