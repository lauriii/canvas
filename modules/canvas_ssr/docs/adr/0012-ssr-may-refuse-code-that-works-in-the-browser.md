# 12. SSR may refuse code that works in the browser

Status: Accepted

## Context

[ADR 1](0001-components-mean-what-they-mean-in-the-browser.md) says a
component's code means what it means in the browser, and [ADR
2](0002-any-failure-falls-back-to-csr.md) says a render that fails falls back to
CSR. That leaves code that neither fails nor can be rendered safely.

On the server, [islands](../GLOSSARY.md#island) share a
[realm](../GLOSSARY.md#realm), modules [evaluate](../GLOSSARY.md#evaluate)
once, and markup is cached and served on other pages and to other users. A
browser has a realm per page and caches nothing a component renders. So some
code is harmless there and not here: what it does would reach another island,
or SSR can't tell what the markup depends on, and so can't cache it.

## Decision

SSR may refuse code that works in the browser. A refusal is a CSR fallback,
with a warning that names the component and says what to change, so the page
still works and the author can find out why.

A limit is only added when rendering the code could give wrong markup, a
wrong cache entry, or a change that reaches another island, and when there's
another way to write it that renders on the server.

### What a component's module may not do

- **Import a module by its URL**: a specifier that starts with `/`, `./` or
  `../`, or with a scheme. SSR learns what a component uses from the names it
  imports: which libraries to evaluate before it, which of them fetch, and which
  components' modules a fetch may be kept in ([ADR
  6](0006-fetch-is-a-drupal-subrequest.md)). A URL import tells it none of that,
  and whether the module loads at all depends on what rendered earlier. That
  goes for a URL an import map entry points at too. Mapping it back to the
  entry's name would mean resolving it exactly as each runner does, and would
  give an author nothing, since the name always works. A library is imported by
  its name, and another component as `@/components/…`.
- **Add, reassign or delete a global, or [mutate](../GLOSSARY.md#mutate)
  `console` or preact's `options`, as it evaluates**, or leave something running
  that does, as a module that registers itself on `globalThis` does. In the
  browser a component's module evaluates only on a page it's on. Here what it
  did would last for every later island in the realm, whatever its component. It
  may make those changes while it renders, and they're undone after. A library
  bundled into a component's code counts as the component's.
- **Write to a built-in**, like `Array.prototype`, as it evaluates or as it
  renders. Every later island would get the change.
- **Mutate what a library exports.** Every later island that imports the
  library would get the change.

### What a provided module may not do

A [provided module](../GLOSSARY.md#provided-module) is a
[library](../GLOSSARY.md#library) or a [chunk](../GLOSSARY.md#chunk): a
package's code, or a pushed codebase's own shared code.

- **Mutate its own exports after it evaluates**: a `configure()` that sets a
  field of an exported object, say, or a method of an exported singleton that
  sets a property on it. Its exports are frozen once it has evaluated, so the
  write throws, and the island that made the call falls back. A library that
  keeps such state in a module's own variables renders on the server.
- **Await, then mutate what its chunk exports, if it first evaluates among a
  component's modules**, as a library imported by a computed specifier does.
  A component could have imported the chunk itself, by a URL it computed, so
  the chunk's exports are frozen before anything that waited on it runs. The
  library then stays failed in that realm, for later islands too. A library
  that doesn't await first may, and so may any library a component imports
  by name, which evaluates before the component does.

### What a provided module may do that a component's may not

It may import by URL, as a library must to reach its chunks. It may add,
reassign or delete globals, and mutate `console` and preact's `options`, as
it evaluates, and what it leaves running may too. A library may mutate what
its chunks export. A provided module is the same for every island on the
site, evaluates once, and is covered by the import map's cache tags. Unless
a component imports it by a computed specifier, it evaluates before any
component that imports it. So what it does is part of what every island
starts from, and not one island's change reaching another.

[ADR 11](0011-islands-share-a-locked-realm.md) says how the realm enforces
these, and what follows from the way it does.

## Alternatives considered

### Render it anyway

Most components that break one of these do no harm: a URL import of a module
that never changes, a global nothing else reads. But SSR can't tell those
from the ones that do, and a wrong cache entry is served to other users
until something invalidates it.

### Support it

Each limit could be lifted with more machinery: a realm per island, a record of
every global each module changed, URLs mapped back to names. A realm per island
costs every island its libraries' evaluation ([ADR
11](0011-islands-share-a-locked-realm.md)). The rest is code to keep right on
every runner, for something an author can write another way.

### Fall back without saying why

The page would work either way. But the component works in the author's
browser, so nothing else would tell them why it isn't rendered on the server.

## Consequences

- A component written for the browser may need a small change to render on
  the server. The warning says which.
- A component that breaks a limit still works, on the client.
- The limits are on what a module does, and SSR finds most of them out by
  running it. Only the URL import is found by reading the source, so a URL a
  component computes isn't refused.
- A limit that proves too strict can be lifted without changing what any
  component means, since the fallback is what the browser does anyway.

Specs: [realm sharing](../specs/semi-detailed/03-realm-sharing/spec.md),
[module resolution](../specs/semi-detailed/02-module-resolution/spec.md).
