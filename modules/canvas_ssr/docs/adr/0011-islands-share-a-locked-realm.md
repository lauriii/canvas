# 11. Islands share one realm, locked before any component runs

Status: Accepted

## Context

Every [island](../GLOSSARY.md#island) a runner renders shares its
[realm](../GLOSSARY.md#realm), so modules [evaluate](../GLOSSARY.md#evaluate)
once. The realm lasts the whole request, and a render's markup is cached and
served on other pages and to other users. So a component that
[mutated](../GLOSSARY.md#mutate) a built-in or a library's exports, or
reassigned a global, would change later islands in the request that have
nothing to do with it, and the cache would serve them long after.

In the browser, a component that does this changes only the page it's on, for
the visitor who loaded it. The threat that matters here is a legitimate
author's mistake, cached and served to others. A deliberately malicious author
is mostly out of scope: their code already runs in every visitor's browser. Such
an author can, for one, import the realm setup module's hooks and call them
itself, to keep a global its island added.

## Decision

The realm is locked before any component's code runs:

- A [realm setup module](../GLOSSARY.md#realm-setup-module), which every [main
  module](../GLOSSARY.md#main-module) imports first and every base preloads
  first, freezes every built-in and the global object's prototype chain, and
  makes every existing global non-writable and non-configurable. An island
  can still give the global object another prototype, so the first one is
  put back before each island, and before a module evaluates for one. That
  works because the global object stays extensible:
  `Object.preventExtensions()`, `Object.seal()` and `Object.freeze()` throw
  for it, as they do for a browser's `window`.
- Freezing covers what a walk from the globals reaches, and that leaves two
  kinds of built-in out. Each is closed by a rule, with a test that searches
  each runner for more, so an engine that adds one fails the test:
  - A prototype only a call hands out, like the one of what
    `URLSearchParams.prototype.entries()` returns. The realm setup module
    lists these, and the test constructs what the realm has, calls its
    methods, and checks the prototypes of what comes back. The two that
    only `Intl.Segmenter.prototype.segment()` hands out are frozen by its
    first call instead, since the first Segmenter a V8 process makes costs
    about 10 ms, and a Deno process lives for one request.
  - An accessor on a constructor or a namespace object, which reads or
    sets what the engine keeps for the whole realm, whatever is frozen:
    `Error.prepareStackTrace` on some engines, the legacy `RegExp.$1` and
    its siblings on others. The realm setup module deletes every such
    accessor, and the test lists any that are left. An accessor on a
    prototype acts on the object it's called on, and stays.
- `console` isn't frozen, so a component may silence a method, as in the
  browser. Its methods and prototype are put back as libraries left them,
  at the same times, and it stays extensible, as the global object does.
- The realm setup module also defines `drupalSettings`, as an accessor no
  island can redefine or delete, so each island can be given its own. A
  component may still assign it, as in the browser, and the next island
  gets its own anyway. For a graph that declares no data it's `undefined`,
  rather than absent: reading a property of it throws a TypeError, not a
  ReferenceError, and `typeof` guards and `?.` work the same.
- A module that first evaluates while an island renders, as one imported
  with `import()` can, gets the island's site data alone, as a module that
  evaluates before the render does. It could otherwise keep page data or
  interface translations, and islands on other pages, or of other graphs,
  would render them without their cacheability. Reading either throws
  instead of giving `undefined`. Code can't be told from the module's own,
  so any read of them while such a module evaluates, or awaits at the top
  level, throws too, and the island falls back to CSR, with a warning,
  rather than render without its data. Once the island ends,
  `drupalSettings` is its site data, as before a render.
- Before each island, the main module deletes the globals earlier islands
  added while rendering, or left promise jobs to add. During SSR, `Drupal`,
  `window` and other globals a browser would have don't exist at first, so
  one an island set would otherwise be what every later island saw. The
  globals a library adds while it evaluates are kept, since it evaluates
  only once and may rely on them later. If a render reassigns or deletes
  one, it's put back as module evaluation left it, with the same object.
  What that object holds isn't put back: copying it would break the
  module's own references to it, and freezing it would break libraries
  that keep a shared registry or cache there. A statement put before every
  module's own code, and the lines appended to it, tell the realm setup
  module when a library has evaluated, and it records the globals as they
  are then. `Object.defineProperty()`, `Object.defineProperties()`
  and `Reflect.defineProperty()` keep a new global, or a new property of
  `console`, configurable, whatever the descriptor says, so it can be
  deleted. A runner defines its bindings with its own copy of
  `Object.defineProperty()`, which isn't affected.
- The globals, `console` and preact's `options` are kept as one record: how
  libraries left them. Every island starts from it, and so does every module
  that evaluates outside a render. Only a library that evaluates changes
  it. So there's one rule for who made a change, and no telling apart by
  when it was made, which modules that await, or leave promise jobs
  running, would blur.
- A component's module may not add, reassign or delete a global, or mutate
  `console` or preact's `options`, as it evaluates. A library may. In the
  browser, a component's module evaluates only on a page it's on, but here
  what it did would reach every later island in the realm, whatever its
  component, and their markup is cached for other pages and users. What it
  changed is put back and the module throws, so islands that import it fall
  back to CSR, with a warning. It may make those changes while it renders.
  A base evaluates no component where a render sees it, so one that's on no
  page changes nothing (see ADR 10).
- Nor may what a component's module leaves running, such as a promise chain
  it didn't await. A runner runs the job queue dry before it calls the main
  module's default export, so all of it has run by then, however long the
  chain. The module has evaluated by then, so the island throws instead,
  when it begins. A later island that imports one of its modules
  wouldn't throw, since nothing evaluates for it, but would render without
  the change. So the renderer remembers the component modules the island's
  invoke evaluated, until the runner is reset, and islands that import one
  fall back too. One that an earlier island evaluated can't be what did
  it, since what it left running had run before that island rendered. Of
  the rest, which one did it can't be told, so a later island's fallback
  also ends when what the island depends on changes, as when its
  component is fixed.
- An `import()` a component doesn't wait for may finish after its island has
  rendered. The Deno runner loads a module on a later turn of its loop, once the
  job queue is empty, so running the queue dry doesn't finish it. What the
  component then does would happen during a later island: a fetch would count as
  that island's, and a change to preact's options would alter its markup.
  `import()` is syntax, so nothing can replace it. Components are parsed
  already, so each call in one is wrapped to hand its promise to the realm setup
  module, whatever its specifier. An island that made one stays open, and the
  renderer invokes the realm setup module until none is pending and none was
  made since the last invoke. Since a runner runs the job queue dry before an
  invoke ends, nothing is then left that could make one. It costs an island that
  calls `import()` at least one more invoke. Loading such modules ahead instead
  would evaluate what the component chose not to evaluate on the server, such as
  a module that reads `document`, imported in an effect.
- The libraries an island's components import by name evaluate before any
  of the components' modules: the renderer invokes a libraries module, which
  imports each in turn, before the main module. That's what a base does, so
  a render is the same with one and without. It also means a library is
  never evaluating while a component's module is, so a change is one's or
  the other's. What a library leaves running has run by the time a
  component's module evaluates, and is kept as the library's. A library a
  component only names in an `import()` evaluates then too, as it does in a
  base. One that fails is left for the component to fail on.
- A library imported by a computed specifier can't be known ahead, so it
  evaluates among the component's modules. What the component's module had
  changed by then is refused, and what the library changes is kept.
- Lines appended to every module canvas_ssr registers freeze its exports once it
  evaluates. A [chunk](../GLOSSARY.md#chunk) leaves that to the next
  [library](../GLOSSARY.md#library) that evaluates, usually one that imports it,
  which may still mutate them. When another library evaluates first, it freezes
  them instead, and islands that import the library that mutates them fall back
  to CSR. Freezing them later would leave some within a component's reach first.
  A component that imports a chunk by a URL it computes would reach them first
  anyway, if no island had imported its library yet. So exports are only held
  while libraries alone evaluate, in a base and before an island's components
  do. A chunk that evaluates at any other time is frozen in a promise job queued
  as it finishes, which runs before a component that imported it gets it. A
  library that imports it evaluates before that job, so it may still mutate
  them, unless it awaits first. preact core's exports wait until the framework
  renderer has loaded preact/compat, and its `options` is never frozen. A
  regular expression's `lastIndex` stays writable, since `test()` and `exec()`
  set it.
- Before freezing, the [tamed properties](../GLOSSARY.md#tamed-property) become
  accessors, so an object can still set what it inherits, as an `Error`
  subclass sets `name`. The defaults are SES's "moderate" list plus what
  Canvas's own components needed, and a site adds to them with
  `canvas_ssr.additional_tamed_properties`.
- Every island renders with preact/compat, which the framework renderer loads.
  Most islands' modules reach it anyway, through drupal-canvas, and loading it
  only for the rest would make an island's markup depend on what rendered before
  it. preact's `options` can't be frozen, since preact-render-to-string sets a
  flag on it for every render. So the realm setup module looks after it as it
  does `console`: each island starts from the options libraries left while they
  evaluated, such as compat's hooks or a signals library's, and the framework
  renderer adds its own vnode hook. So nothing an island does can stop that,
  `options` stays extensible and its properties configurable, as the global
  object's do.
- A runner runs the job queue dry before an invoke returns, so a promise
  chain an island didn't wait for can't run during a later one, unless that
  one settles a promise the chain is waiting on (see Isolation gaps).
- Only one island renders in the realm at a time. One rendered from inside
  another's invoke, as an island on the error page a same-site `fetch()`
  gets can be, falls back to CSR, and the outer island renders as if it
  weren't there.
- Runners keep their own code out of reach. The runner contract requires it,
  so it holds however taming is configured. What the language looks up on its
  own, like the `then()` an `await` reads, the freezing above closes.

A component that writes to something frozen throws. Its island is a [script
failure](../GLOSSARY.md#script-failure): it falls back to CSR, cached as any
script failure is, and its author gets a throttled warning, since the component
works in the browser. A script failure keeps the realm, since the lock means
what it leaves is its own modules' state, as in the browser.

## Alternatives considered

### A realm per island

Complete isolation, but every island would evaluate its modules again: tens
of milliseconds more per island on Deno.

### SES compartments

They'd isolate globals per island, but would need every import rewritten, and
measured about one and a half times slower per island even with one compartment
reused, and about ten times with one per render.

### Vendoring SES's `lockdown()`

It assumes a realm it controls from the start, and tames more than Canvas's
components need. The setup module borrows its lists instead, citing them.

### Retrying a casualty in an isolated realm, or remembering casualties

A component that mutates what islands share may mean to, and rendering it on the
client is what it gets in a browser too. Retrying would cost a new realm per
island of it, and remembering casualties across requests adds state that can go
stale.

### Accumulating the cacheability of earlier islands

An island would depend on every island rendered before it, so a page's cache
would vary with render order, and still not say what changed it.

### Rendering with preact/compat only for islands whose modules reach it

The framework renderer would keep preact's options as they are with and
without compat, and the main module would say which an island gets. Telling
needs the import graph of every provided module, so every dist module and
pushed bundle would be parsed, at a cost that grows with what a site pushes,
for the one SVG difference under SSR/CSR mismatches.

### Loading preact/compat only when the base has it

It would make drafts render differently in previews, and break ADR 10's rule
that the base never changes output.

## Consequences

### Performance

The lock doesn't measurably change per-invoke time. The Deno runner's first
invoke of a process takes a few milliseconds more, for the walk over more than
a thousand built-ins. That's once per realm, so once per request, unless the
runner is reset partway, after a runner failure.

### Memory

The islands of a realm share its memory limit, so a component that keeps a
lot in a module's variable leaves less to the islands after it. One of those
may then run out, though its own component needs little. It can't be told
which earlier island took the memory. So the one that ran out renders once
more in a new realm, where it has the whole limit, and a warning names the
components rendered before it. That costs at most one more render and one
more realm for an island, and only when it ran out after others.

### Isolation gaps

One island can change these, and a later island sees the change:

- State in a component's own module, such as a module-level counter. Every
  island of the component shares it, as islands of one component on one
  page do in the browser, but here it lasts the request, and the markup is
  cached for other pages and users. A module's own variables can't be
  frozen.
- A library's state changed through its own API, such as a module-private
  cache. That's the library's design, and freezing it would break it. A
  library that keeps what it fetched serves it to islands without its
  cacheability, so one shouldn't, nor call `fetch()` as it evaluates (see
  ADR 6).
- An object a library hands out but doesn't export, such as an instance an
  exported function returns, and that instance's class. A component can
  mutate it directly, not only through the API. Freezing reaches only what
  a module exports, and freezing what its functions return would break
  libraries that hand out objects for their callers to mutate.
- The contents of an exported `Map` or `Set`, a typed array's elements, a
  regular expression's `lastIndex`, and an exported `let` reassigned to a
  new object. Freezing can't reach them, or would break them.
- A global a library adds while it evaluates, and what the object it holds
  contains. The global is put back if a render reassigns or deletes it, but a
  mutation of the object isn't, and later islands see it, whatever their
  component. And that it exists at all reaches every island after the library
  evaluated: a library that adds `globalThis.window` changes what a later
  island's `typeof window` check says, whatever its component.
- What changes while a library imported by a computed specifier evaluates,
  that isn't the library's doing. It's kept as the library's when the
  library finishes. While the library is waiting on an `await`, that's
  whatever a component's module left running changed, outside a render, and
  a global the render added, during one. And with no `await`, it's a global
  added by an accessor the render defined, when the library reads it.

- An `import()` a library makes and doesn't wait for, such as of a chunk it
  loads ahead. A library's source isn't parsed, so the call isn't tracked, and
  on the Deno runner what follows it may run during a later island. A fetch it
  then makes counts as that island's. The same goes for an `import()` in code a
  component builds from a string.
- A render an island left suspended when it failed, when a later island
  settles the promise it's waiting on. That takes a promise both can reach,
  such as one a component's module keeps, which islands of the component
  share. The suspended component then renders again while the later island
  does. A fetch it makes counts as that island's, and what it fetches through
  `useSWR()` is carried as that island's hydration data. It's the same user's
  data, in the same request, and the later island's cacheability takes in the
  fetch.

### CSR fallbacks

Later islands are unaffected, but an island falls back to CSR where it would
work in the browser. What a module may not do, by this decision, is in
[ADR 12](0012-ssr-may-refuse-code-that-works-in-the-browser.md): write to a
built-in or to a library's export, change what islands share as it
evaluates, or import by URL. These follow from how that's enforced:

- Making `console` or preact's `options` non-extensible, which throws, as
  it doesn't in the browser.
- A library a component's module imports by a computed specifier as it
  evaluates. The library evaluates among the component's modules, so two
  of its changes can't be told from theirs: what it leaves running
  changes, and what it changed before an `await`, if another module begins
  evaluating meanwhile. The island falls back, the warning names the
  component, and the change is undone, so later islands that use the
  library render without it.
- A component whose every `import()` makes another, without end. The browser
  would go on loading them. Its island is given up on after a hundred rounds
  or two seconds of waiting, and the runner is reset.
- Every later island in the request that imports a module evaluated in
  the invoke of an island whose modules left something running, even if
  that module didn't.
  Which module it was can't be told.
- A read of page data while a module imported during the render evaluates,
  though in the browser the read would get the data. That includes the
  module's own reads at module scope, which would get `undefined` before a
  render. A module that throws never says it's done, so it's every read for
  the rest of that island: a component that catches a failed `import()` and
  renders without the module falls back if it then reads page data.
- A copy of a library bundled into pushed code. What's done for particular
  libraries, like a site's taming config or leaving preact's `options`
  unfrozen, reaches only the copy the import map names. So a bundled copy's
  island may fall back where one using the import map's copy would render on
  the server.

### SSR/CSR mismatches

Later islands are unaffected, but an island renders on the server other than
as it does in the browser:

- `RegExp.$1`, `RegExp.lastMatch` and the other legacy properties that give
  the last match. They're deleted, so they read `undefined`. So are
  `Error.stackTraceLimit` and `Error.prepareStackTrace` where an engine
  has them as accessors.
- A global an earlier island on the page added, reassigned or deleted
  while rendering. In the browser, islands on a page share globals, so a
  later one would see the change. On the server, it's undone first. Islands
  hydrate independently, so relying on that is fragile in the browser too.
- A property of the global object, `console` or preact's `options` that a
  component or a library defines non-configurable, explicitly or by leaving out
  `configurable`. In the browser it's non-configurable, but on the server it
  stays configurable, so it can be deleted.
- What a library that first evaluates while an island renders does to
  `console` or preact's options. It lasts only for that island, though the
  library evaluates only once. Only one imported by a computed specifier
  does.
- What a component's module that first evaluates while an island renders
  changes. It's the render's, and undone before the next island, though the
  module evaluates only once.
- A library that reads page data, or a global a render sets, as it
  evaluates. It evaluates before the island's components do, even if a
  component only names it in an `import()` it calls while rendering, so it
  reads neither. It evaluates whether or not the render reaches that
  `import()`. That's so in a base too.
- What a module left running as it evaluated, when the component renders
  differently once it's done. On the server it has always finished before
  the island renders. In the browser it may not have by the component's
  first render.
- A library that awaits as it evaluates, when another the same island's
  components import depends on evaluating meanwhile. Each evaluates on its
  own, in the order of their specifiers, after the framework renderer.
- A write to something frozen that never throws out of the render: one the
  component catches and ignores, or a `Reflect.set()` or
  `Reflect.defineProperty()` whose `false` it ignores. The island renders on
  the server without the write it gets in the browser, and its author isn't
  warned.
- A camelCase SVG attribute, for a component that doesn't import
  preact/compat. Loading compat for every island departs from ADR 1 here:
  compat's vnode hook changes one thing preact-render-to-string doesn't
  already. A camelCase SVG attribute on an `<svg>` element, like
  `strokeWidth`, gets its hyphenated name. The browser keeps the camelCase
  name, which SVG ignores, so it draws the default. So the server-rendered
  island shows what the author meant, and their CSR preview doesn't. That's
  a component's bug made visible in one place rather than another. Loading
  compat only for islands that import it would instead make such an
  island's markup depend on whether one that loads compat rendered first.

Specs: [realm sharing](../specs/semi-detailed/03-realm-sharing/spec.md).
