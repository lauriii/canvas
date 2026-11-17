# 1. A component's code means what it means in the browser

Status: Accepted

## Context

Canvas compiles each code [component](../GLOSSARY.md#component) to a single
JavaScript module. The browser loads it, and its imports resolve through the
[site import map](../GLOSSARY.md#site-import-map). SSR runs that same code on
the server, in a [runner](../GLOSSARY.md#runner) that isn't a browser. So
something has to decide what the component's imports, asset URLs, `import.meta`
and global APIs mean there.

## Decision

A component's code means exactly what it means in the browser.

- Bare [specifiers](../GLOSSARY.md#specifier) resolve through the site import
  map, as the HTML standard says. Relative and rooted specifiers resolve against
  the importing module's URL, as the URL standard says.
- A module is known by its [module URL](../GLOSSARY.md#module-url): the URL
  the browser loads it from. Same URL, same module. A
  [draft](../GLOSSARY.md#draft) has a different URL from the
  [published](../GLOSSARY.md#published) component. A component's URL changes
  when its compiled JavaScript does.
- Nothing bundles a component's source. Runners [load](../GLOSSARY.md#load)
  modules by URL and [resolve](../GLOSSARY.md#resolve) imports themselves. SSR
  parses a component's source only to learn what it imports and whether it's a
  [client boundary](../GLOSSARY.md#client-boundary). The one import it rewrites
  is `@/components/…`, to the module URL of the component it names, since the
  browser resolves those through the island's scoped import map, which SSR
  doesn't read. A component that imports others is registered under its URL plus
  a hash of what it reaches, since a preview gives the same import a draft. The
  only other additions are the lines [ADR
  11](0011-islands-share-a-locked-realm.md) puts before and after every module,
  which watch what it does as it evaluates and freeze its exports.
- A [provider](../GLOSSARY.md#module-provider) can register a module under a
  specifier the map already has. That's how the swr shim replaces real swr on
  the server. It's the only way a provider can change what an import reaches.
- `import.meta.url` and `import.meta.resolve()` give the browser's answers. So
  an asset URL in the markup is the one the browser would load.
- The supported [web platform APIs](../GLOSSARY.md#web-platform-api) are
  there. Node's APIs aren't. No server-only API stands in for anything.

## Alternatives considered

### Bundle on the server

A bundler adds a step to the pipeline, which is only worth it if something needs
it. Nothing does today. Canvas already stores compiled JavaScript per module,
and Deno handles lots of small modules fine at runtime. A bundle would also be a
second compilation, with its own rules to keep in step with Canvas's.

A runner can still bundle internally if its engine needs it. That might make
sense for Node or celld. Such a runner would have two options. It could bundle
only the [base](../GLOSSARY.md#base), but then the bundle has to re-export
everything a draft might import. Or it could bundle drafts too, but then the
bundler runs during [preview](../GLOSSARY.md#preview)
[renders](../GLOSSARY.md#render).

### Rewrite imports on the server

SSR could parse every module and rewrite each specifier to point at a
[registered module](../GLOSSARY.md#registered-module), refusing any it
doesn't know. But then SSR, not the import map, would decide what an import
reaches. And it would have to redo every rule the browser uses to resolve
imports.

### Let providers invent aliases

An alias the browser doesn't have resolves on the server and 404s in the
browser. That's exactly the SSR/CSR mismatch everything else here tries to
avoid.

### Server-only APIs

Things like database queries, or passing API tokens to third-party services.
They're out of scope for now, and we'll revisit them later. Until then, a
component can't call anything on the server that it couldn't call in the
browser.

### Node compatibility

A component that relied on it would work in SSR and break in the browser: the
same mismatch, from the other side.

## Consequences

- A component that renders on the server renders in the browser from the same
  code. There's no special way to write a component for SSR.
- A URL names one source, so any cache keyed by URL is safe. A runner can keep
  [evaluated](../GLOSSARY.md#evaluate) modules from one render to the next,
  and across requests.
- If an [island](../GLOSSARY.md#island)'s own import map isn't the site's,
  SSR can't resolve it the way the browser would. So it renders on the client.
- Some code that works in the browser is refused on purpose, and renders on
  the client. [ADR 12](0012-ssr-may-refuse-code-that-works-in-the-browser.md)
  lists what, and why.
- Each runner has to resolve the import map itself. The conformance suite checks
  that it does.
- Where the Deno runner can't match the browser exactly, its spec lists the
  difference.

Specs: [module resolution](../specs/semi-detailed/02-module-resolution/spec.md).
