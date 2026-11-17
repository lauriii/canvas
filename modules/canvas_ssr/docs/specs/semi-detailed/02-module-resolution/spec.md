# Module Resolution

## Purpose

This spec covers what a [component](../../../GLOSSARY.md#component)'s `import`
statements and `import.meta` mean on the server. They mean what they would in
the browser, on the same page. The same module
[loads](../../../GLOSSARY.md#load), and the same URL lands in the markup. A
module is known by its [module URL](../../../GLOSSARY.md#module-url). SSR
[resolves](../../../GLOSSARY.md#resolve) imports with the [SSR import
map](../../../GLOSSARY.md#ssr-import-map): the [site import
map](../../../GLOSSARY.md#site-import-map) every
[island](../../../GLOSSARY.md#island) ships, plus the
[specifiers](../../../GLOSSARY.md#specifier) of modules SSR provides. Those come
from Canvas, a CLI push, or a [module
provider](../../../GLOSSARY.md#module-provider).

## Requirements

### Requirement: Imports resolve as the browser would

SSR SHALL resolve each import specifier as the HTML Standard's [import
maps](https://html.spec.whatwg.org/multipage/webappapis.html#import-maps)
algorithm says, so an import means what it would in the browser. SSR doesn't
consult the [import map](../../../GLOSSARY.md#import-map)'s `scopes`; a later
requirement covers imports between components. Modules MAY import each other
cyclically, as bundlers' shared chunks do.

#### Scenario: A bare specifier loads the module its map entry names

- **GIVEN** an [import map entry](../../../GLOSSARY.md#import-map-entry) `lib` →
  `/lib.js?v=3`, and a module at `/lib.js` with named and default exports
- **WHEN** one module imports `lib`, and another imports `/lib.js`
- **THEN** both get the module's named and default exports
- **AND** both get the same module instance

#### Scenario: A bare specifier no import map entry covers fails the render

- **GIVEN** an import map without `chart.js`
- **WHEN** a component imports `chart.js`
- **THEN** the import fails as it would in the browser, and the island falls
  back to CSR

### Requirement: Modules live at the URLs the browser loads them from

A module's [URL](../../../GLOSSARY.md#url) MAY be a rooted path starting with
`/`, an absolute URL such as a CDN's, or a protocol-relative URL like
`//host/x.js`. A module SHALL resolve relative imports from its own URL.

#### Scenario: An import map entry with a query loads the module at the URL without it

- **GIVEN** an import map entry `cdn` → `https://cdn.example.com/x.js?v=2`, and
  a module at `https://cdn.example.com/x.js`
- **WHEN** a module imports `cdn`
- **THEN** it gets that module
- **AND** `import.meta.resolve("cdn")` still answers
  `https://cdn.example.com/x.js?v=2`

#### Scenario: Modules at absolute URLs import each other as in a browser

- **GIVEN** modules at `https://cdn.example.com/lib/a.js`, `.../lib/b.js` and
  `.../c.js`, where `a.js` imports `./b.js` and `../c.js`, and an import map
  entry `cdn-lib` → `https://cdn.example.com/lib/a.js`
- **WHEN** a component imports `cdn-lib`
- **THEN** `a.js` gets `b.js` and `c.js`

### Requirement: import.meta answers exactly as the browser would

`import.meta.resolve()` SHALL return the URL the browser would, query and
fragment included, because Canvas compiles asset imports to such calls and the
result goes straight into the markup. It SHALL resolve a relative specifier
against the module's URL, then consult the import map, and SHALL throw for a
bare specifier the map doesn't cover. A module SHALL see its own URL in
`import.meta.url`, so code that builds URLs from it gets the browser's answer.

#### Scenario: An asset the import map covers lands in the markup as the browser would load it

- **GIVEN** a pushed image, which reaches the site import map as
  `@/assets/logo.svg` → its URL with a cache-busting query
- **WHEN** a component renders `import.meta.resolve("@/assets/logo.svg")` into
  an `<img src>`
- **THEN** the server-rendered `src` is the map's URL, query and all

#### Scenario: An asset no import map entry claims fails the render

- **GIVEN** no import map entry for `@/assets/missing.svg`
- **WHEN** a component calls `import.meta.resolve("@/assets/missing.svg")`
- **THEN** the call throws, and the island falls back to CSR, so no made-up URL
  reaches the page

#### Scenario: A module at an absolute URL sees that URL in import.meta

- **GIVEN** a module at `https://cdn.example.com/assets/card.js`
- **WHEN** it reads `import.meta.url` and `new URL("./hero.webp",
  import.meta.url).href`
- **THEN** it gets `https://cdn.example.com/assets/card.js` and
  `https://cdn.example.com/assets/hero.webp`

### Requirement: SSR resolves with the site's import map

SSR SHALL resolve with the site import map, so an island SHALL NOT render on the
server if its import map's global imports differ from the site's. Scopes MAY
differ, because Canvas gives each island its own and SSR doesn't read them.
After a push changes the site import map, the next
[render](../../../GLOSSARY.md#render) SHALL resolve with the new one.

#### Scenario: An island with its own scopes still renders on the server

- **GIVEN** an island whose import map is the site's, plus scopes of its own
- **WHEN** it renders
- **THEN** it renders on the server

#### Scenario: An island whose global imports differ renders on the client

- **GIVEN** an island whose import map has a global import the site's lacks
- **WHEN** it renders
- **THEN** it falls back to CSR

### Requirement: Where SSR needs its own implementation, its specifier replaces the site's import map entry

The SSR import map SHALL be the site import map with the [provided
modules](../../../GLOSSARY.md#provided-module)' specifiers added. A provided
specifier SHALL win over the site's import map entry for it, and every other
SHALL stay as it is. This is how SSR swaps a module that can't run on the
server, such as a browser-only data-fetching bundle, for one that can. Modules
the browser never loads SHALL live under a path where Drupal serves nothing.
When two providers claim the same URL or specifier, the higher-priority provider
SHALL win it.

#### Scenario: A provided specifier replaces the site's import map entry

- **GIVEN** a site import map with `preact` and `swr`, and an
  [SSR-only](../../../GLOSSARY.md#ssr-only-module) shim provided for `swr`
- **WHEN** the SSR import map is built
- **THEN** `swr` maps to the shim's URL, and `preact` is unchanged

### Requirement: Every site import map entry loads a module SSR can run, named by its URL

For every import map entry in the site import map, SSR SHALL have a module
[registered](../../../GLOSSARY.md#registered-module) under the import map
entry's URL, so every specifier that resolves in the browser loads a module on
the server too. A module's URL is what `import.meta.url` and stack traces show,
so it SHALL NOT reveal the server's directory layout. Canvas's shipped modules
SHALL render under SSR, including the [chunks](../../../GLOSSARY.md#chunk) they
import relatively.

#### Scenario: Every import map entry resolves to a provided module

- **WHEN** the SSR import map is compared with the provided modules
- **THEN** every import map entry's URL, without its query, names a provided
  module
- **AND** no module's URL contains the server's filesystem path

### Requirement: The modules a CLI push uploaded are provided

SSR SHALL provide every uploaded `.js` module a push lists in the global asset
library, under the URL it's served from, and SHALL skip anything else the
library lists. A push SHALL replace what the previous push provided. A module
the library lists under a site import's specifier SHALL override that import,
as it does in the browser.

#### Scenario: A third-party package and the chunk two of them share

- **GIVEN** a push of two packages that both import a shared chunk relatively,
  with the chunk listed as a shared module
- **WHEN** a component imports both packages
- **THEN** it renders with both, and the chunk loads once

#### Scenario: A re-push replaces what's provided, rather than adding to it

- **GIVEN** a push that provides `@/lib/pushed` from one file
- **WHEN** a second push provides it from another file, and nothing else happens
  in between
- **THEN** the second file resolves, and the first doesn't, though it's still on
  disk

### Requirement: A component imports only the components its configuration declares

An import of `@/components/<name>` SHALL resolve only if the importing
component's configuration lists it, as in the browser, whose scoped import map
Canvas builds from the same list. A live render SHALL resolve it to the
[published](../../../GLOSSARY.md#published) component, and a
[preview](../../../GLOSSARY.md#preview) to the
[draft](../../../GLOSSARY.md#draft). A preview's modules SHALL be distinct from
the published ones, so neither render is served the other's code. Static,
re-export and dynamic imports SHALL all resolve this way, and text that only
looks like an import SHALL be left alone.

A component SHALL import a library by its name and another component as
`@/components/<name>`, and never by URL: a specifier that starts with `/`, `./`
or `../`, or with a scheme. An island whose graph has a component that imports
by URL SHALL fall back to CSR, with a warning that names the component and the
import. An `import()` of a URL a component computes isn't refused.
[ADR 12](../../../adr/0012-ssr-may-refuse-code-that-works-in-the-browser.md)
says why.

#### Scenario: An undeclared import is refused rather than looked up

- **GIVEN** a parent that imports a child its configuration doesn't list
- **WHEN** an island of the parent renders
- **THEN** the island falls back to CSR, because the browser couldn't resolve it
  either

#### Scenario: A component that imports by URL renders on the client

- **GIVEN** a component that imports a module by its URL, whether another
  component's, a library's or another site's, and a parent that imports that
  component
- **WHEN** an island of either renders
- **THEN** the island falls back to CSR without running any of its code
- **AND** a warning names the component with the import, and the import

#### Scenario: A live render and a preview resolve to different code

- **GIVEN** a parent that imports a child with a draft
- **WHEN** an island of the parent renders live, and again in a preview
- **THEN** the live render carries the saved child's markup, and the preview
  carries the draft's
