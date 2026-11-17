# Canvas SSR

Server-side rendering of Canvas code components. A render hands a component's
JavaScript to a runner, gets markup back, and falls back to client-side
rendering when it can't. Other Drupal modules can supply runners; this glossary
covers the vocabulary every runner shares, plus the Deno runner that ships with
canvas_ssr.

## Language

<dl>
<dt><a href="#rendering"><b>Rendering</b></a></dt>
<dd>
<a href="#component">Component</a> · <a href="#exposed">Exposed</a> ·
<a href="#draft">Draft</a> · <a href="#published">Published</a> ·
<a href="#preview">Preview</a> · <a href="#island">Island</a> ·
<a href="#render">Render</a> · <a href="#request">Request</a> ·
<a href="#static-island">Static Island</a> ·
<a href="#hydrated-island">Hydrated Island</a> ·
<a href="#csr-fallback">CSR Fallback</a> · <a href="#bail">Bail</a> ·
<a href="#script-failure">Script Failure</a> ·
<a href="#runner-failure">Runner Failure</a> ·
<a href="#transient-error">Transient Error</a> ·
<a href="#component-graph">Component Graph</a> ·
<a href="#client-boundary">Client Boundary</a> ·
<a href="#boundary-checker">Boundary Checker</a> ·
<a href="#data-dependency">Data Dependency</a> ·
<a href="#site-data">Site Data</a> · <a href="#page-data">Page Data</a> ·
<a href="#interface-translations">Interface Translations</a> ·
<a href="#drupalsettings-module">drupalSettings Module</a> ·
<a href="#main-module">Main Module</a> ·
<a href="#libraries-module">Libraries Module</a> ·
<a href="#payload">Payload</a> ·
<a href="#framework">Framework</a> ·
<a href="#framework-renderer">Framework Renderer</a>
</dd>
<dt><a href="#modules"><b>Modules</b></a></dt>
<dd>
<a href="#module-url">Module URL</a> · <a href="#url">URL</a> ·
<a href="#specifier">Specifier</a> ·
<a href="#import-map-entry">Import Map Entry</a> ·
<a href="#blocked">Blocked</a> ·
<a href="#site-import-map">Site Import Map</a> ·
<a href="#ssr-import-map">SSR Import Map</a> · <a href="#resolve">Resolve</a> ·
<a href="#load">Load</a> · <a href="#evaluate">Evaluate</a> ·
<a href="#module-provider">Module Provider</a> ·
<a href="#provided-module">Provided Module</a> ·
<a href="#library">Library</a> · <a href="#chunk">Chunk</a> ·
<a href="#realm-setup-module">Realm Setup Module</a> ·
<a href="#tamed-property">Tamed Property</a> ·
<a href="#mutate">Mutate</a> ·
<a href="#ssr-only-module">SSR-only Module</a> ·
<a href="#web-platform-api">Web Platform API</a>
</dd>
<dt><a href="#runners"><b>Runners</b></a></dt>
<dd>
<a href="#runner">Runner</a> · <a href="#runner-module">Runner Module</a> ·
<a href="#available">Available</a> · <a href="#realm">Realm</a> ·
<a href="#global">Global</a> ·
<a href="#registrations">Registrations</a> ·
<a href="#registered-module">Registered Module</a> ·
<a href="#binding">Binding</a> · <a href="#import-map">Import Map</a> ·
<a href="#base">Base</a> · <a href="#base-in-use">Base in Use</a> ·
<a href="#preload-modules">Preload Modules</a> ·
<a href="#modules-to-compile">Modules to Compile</a> ·
<a href="#unloadable">Unloadable</a> · <a href="#invoke">Invoke</a> ·
<a href="#reset">Reset</a>
</dd>
<dt><a href="#fetch"><b>Fetch</b></a></dt>
<dd>
<a href="#same-site-fetch">Same-site Fetch</a> ·
<a href="#path-policy">Path Policy</a> ·
<a href="#hydration-data">Hydration Data</a>
</dd>
<dt><a href="#deno-runner"><b>Deno runner</b></a></dt>
<dd>
<a href="#deno-import-map">Deno Import Map</a> ·
<a href="#runner-cache">Runner Cache</a> ·
<a href="#base-cache">Base Cache</a> · <a href="#seed">Seed</a> ·
<a href="#cache-url">Cache URL</a> · <a href="#origin">Origin</a> ·
<a href="#os-user">OS User</a> · <a href="#command">Command</a>
</dd>
</dl>

### Rendering

#### Component
A Canvas code component, the `JavaScriptComponent` config entity. canvas_ssr
renders nothing else, so "component" never means an SDC or a block
here; where another Canvas component type comes up at all, it's named by its
own term. Everything SSR derives from the component alone is the
component's: its graph, its modules, its main module, its drupalSettings
module, which site and page data it gets, whether it hydrates.
_Avoid_: code component, JS component

#### Exposed
A component Canvas offers for placement. Only an exposed component can be an
island's component; one that isn't exposed can still be in an exposed
component's graph. The base covers every exposed component.
_Avoid_: published, enabled, active

#### Draft
A component's auto-saved, unpublished state. A draft renders only in a
preview, since auto-saves aren't per editor.
_Avoid_: auto-save, unsaved component

#### Published
Saved to config storage, as opposed to a draft.
_Avoid_: saved, live

#### Preview
A render in which each component's draft, if it has one, stands in for the
published component. Only the Canvas editor requests one.
_Avoid_: draft render, editor render

#### Island
One placement of a component on a page, Canvas's `astro_island` element,
with its own props and slots. A render is of an island; anything the same
for every placement belongs to the component.
_Avoid_: component instance, placement

#### Render
The server-side rendering of one island, from the component entity to its
result, including the bails, the registrations and the invoke.
_Avoid_: SSR (as a verb), invoke (one step of it)

#### Request
One PHP request: everything a PHP process does to answer one HTTP request,
page cache hits included, from bootstrap to the end of the response. Every
render happens in one. A component's `fetch()` also makes requests, which the
same-site fetch runs as Drupal subrequests. Where it isn't clear which is
meant, say "the current request" or "a fetch's request". Nothing else is a
request: what PHP sends a Deno process is a command.
_Avoid_: page load, page view, request (for a command)

#### Static Island
An island whose server-rendered markup is final and ships no component code.
_Avoid_: SSR-only output, non-hydrating island

#### Hydrated Island
An island whose server-rendered markup hydrates in the browser.
_Avoid_: client island, interactive island

#### CSR Fallback
The outcome when SSR produces no markup for an island: it renders in the
browser. Follows a bail or a failure.
_Avoid_: client fallback, degrade

#### Bail
SSR declining an island before invoking its component, because one of the
[preconditions](specs/semi-detailed/01-island-rendering/spec.md) isn't met,
such as an available runner.
_Avoid_: skip, abort

#### Script Failure
The JavaScript itself failed during an invoke: a throw while a module
evaluated or the component rendered, an import that didn't load, or, where
the runner can tell, a promise that never settled. It recurs with the same
inputs, so its CSR fallback is cached as a render would be.
_Avoid_: JS error, component error

#### Runner Failure
Anything else that stops an invoke: a time limit, a crash, a Deno process
that stopped answering, or ended waiting on a promise that never settled, a
base the runner failed to build. It may not recur, so it's a [transient
error](#transient-error). A base still being built isn't one: that fallback
lasts until the base is built. Nor is an island rendered from inside another's
invoke, which the runner always refuses: that fallback is cached as a render.
So is that of an island that went past the memory limit with a realm to
itself. The runner says when it went past its time or memory limit, so SSR can
warn, and render again after running out of memory.
_Avoid_: transient failure, infrastructure error

#### Transient Error
A failure that may not recur: a [runner failure](#runner-failure), a fetch that
failed other than by being refused or malformed, a server error a fetch got, a
parse that ran out of time or of what PHP's `memory_limit` left the request, or
a base that couldn't be defined for a reason that may pass. A failure that
recurs with the same inputs, like a syntax error, isn't one. What it leaves, a
CSR fallback or markup made despite it, is cached for a minute or as the server
error says, with the `canvas_ssr_transient_error` tag. A page with that tag
expires with it from every cache, Drupal's page cache included.
_Avoid_: temporary failure, flaky error

#### Component Graph
A component and every component it depends on, directly or not, as their
config declares. In a preview, drafts stand in for published components.
_Avoid_: dependency tree, import graph

#### Client Boundary
A component that must run in the browser, marked as its framework's
conventions say: for React and Preact, a
[`'use client'`](https://react.dev/reference/rsc/use-client) directive at the
top of its compiled JS. An island whose graph has one is a hydrated island; one
whose graph has none is static.
_Avoid_: client component, interactive component, 'use client' (one
framework's spelling of it)

#### Boundary Checker
Finds, by one framework's conventions, whether a component's graph has a
client boundary, and so whether the island's markup must hydrate.
_Avoid_: hydration detector

#### Data Dependency
What a component's config says it reads from Drupal, as Canvas's
`dataDependencies` records it. The editor works them out from the
component's imports. There are three kinds, and SSR meets each its own way:
- `drupalSettings`: keys of site and page data, such as `v0.branding`. SSR
  sets them in `drupalSettings` before the component runs. Interface
  translations are there too, but aren't declared.
- `urls`: what the component fetches. SSR serves it as a same-site fetch,
  where a path policy allows it.
- `entityFields`: fields read through a content entity reference prop.
  Canvas resolves them into props before SSR sees them.
_Avoid_: requirement, declared data

#### Site Data
Data a component reads that's the same on every page, though it may vary by
host, theme or language: the branding, base URL, theme assets, JSON:API
settings and interface language that `getSiteData()`, `useSiteContext()`,
`JsonApiClient`, `useJsonApiClient()` and `useDateFormatter()` read. Each
value has the key a component declares it by, and lives where Canvas puts it
in the browser:
`v0.branding` is `drupalSettings.canvasData.v0.branding`. It's set while
modules evaluate, so module scope can read it. Site data providers supply it
for SSR, by default from Canvas's own `CodeComponentDataProvider`.
_Avoid_: global variable, site-wide global, canvasData (where it travels, not
what it is)

#### Page Data
Data a component reads that may differ from page to page: the page title,
breadcrumbs and main entity that `getPageData()` and `usePageContext()` read.
Keyed and placed as
site data is, but set only while the island renders, and not even then while
a module imported during the render evaluates. Page data providers supply it
for SSR. A key a page data provider gives is page data, even one Canvas
treats as site data.
_Avoid_: global variable, route data

#### Interface Translations
The translations, into the interface language, of the strings a graph's
components pass to `t()` and `formatPlural()`, which `useTranslation()`
gives them. Canvas records each component's strings in its config, and puts
the translations at `drupalSettings.canvasData.v0.translations`. A component
doesn't declare them. They're neither site nor page data, since they depend
on the graph, not the page. SSR sets them when it sets page data: only while
the island renders.
_Avoid_: translations (which can mean content translations), page data (for
interface translations)

#### drupalSettings Module
The per-component module that, when it evaluates, sets `drupalSettings` to
the site data its graph declares. The main module then sets the island's
site data, page data and interface translations on every invoke.
`drupalSettings` is the only global SSR sets for components, besides the
marker Canvas's functions check for a Drupal page.
_Avoid_: globals module, settings module

#### Main Module
The module an invoke imports and calls the default export of.
ComponentModules builds one per component; it imports the realm setup
module, the drupalSettings module, the framework renderer and the component.
It returns JSON: the markup or what the render threw.
_Avoid_: entry, entry module, entry point

#### Libraries Module
The module the renderer invokes before a main module, when the realm may not
have evaluated a library the island's components import. ComponentModules
builds one per component; it imports each of those libraries in turn, so they
evaluate before the component's modules do, as in a base.

#### Payload
The JSON an invoke passes to the main module: props, rendered slots and the
island's `drupalSettings`.
_Avoid_: input, arguments

#### Framework
The UI library a component is written for, which Canvas records on it:
React, Preact, Vue, Svelte and so on. SSR supports a framework when it has
a framework renderer and a boundary checker for it; an island of any other
framework falls back to CSR. Today it has them for React and Preact only.
_Avoid_: library, UI framework

#### Framework Renderer
The JS module for one framework that the main module hands the component
and payload to, and that returns the markup. "Rendering the component"
always means this step. It gives the component what Canvas's island script
gives it in the browser, such as the values Canvas's hooks return.
_Avoid_: renderer (bare), render function

### Modules

In canvas_ssr, "module" alone always means a JavaScript module. A Drupal module
is always called a "Drupal module" or a "runner module".

#### Module URL
What a module is registered under: the URL the browser loads it from,
either a rooted path, starting with "/", or an absolute URL, as from a CDN,
with or without its scheme ("//host/x.js"). Modules the browser never loads
take paths under `/_canvas_ssr/`. Imports resolve against it as in a
browser.
_Avoid_: module path, module name, name

#### URL
Where the browser loads a module from, as the site import map gives it.
Less its query and fragment, it's the module URL the import map entry
loads.
_Avoid_: address, target

#### Specifier
What an import statement names: a bare name like `preact`, a path, or a
relative reference.
_Avoid_: import name, module id

#### Import Map Entry
One specifier-to-URL mapping in an import map. Always written in full, so
"entry" alone is never used.
_Avoid_: entry, mapping, rule

#### Blocked
An import map entry that matches a specifier but maps it to nothing (a
`null` or invalid value), so resolving the specifier fails rather than
trying a less specific entry. The HTML spec's term.
_Avoid_: null entry, disabled

#### Site Import Map
Canvas's global import map, the one every island ships. SSR compares an
island's against it and renders only islands whose map is the site's.
_Avoid_: the site's map, global imports

#### SSR Import Map
The site import map with the provided modules' specifiers added, winning
over the site's import map entries for them. What runners resolve with.
_Avoid_: the render's map, runner map

#### Resolve
Turn a specifier into a module URL. A "/", "./" or "../" specifier first
resolves against the importing module's URL. A registered URL is its own
result; anything else goes through the SSR import map, less the URL's query.
`import.meta.resolve()` answers with the URL as written, query and all.
Fails when no import map entry matches, or the one that matches is blocked.
_Avoid_: map, look up

#### Load
Resolve an import and compile the source registered under the resulting
URL. Fails when nothing is registered there or the source doesn't compile.
_Avoid_: fetch, import (which means load and evaluate)

#### Evaluate
Run a module's top-level code, once per realm, the first time it's imported
there, producing its exports and module state. A module that throws while
evaluating stays failed in that realm.
_Avoid_: execute, run, initialize

#### Module Provider
A tagged service supplying modules SSR may load, named by the URL the browser
loads them from.
_Avoid_: module source, loader

#### Provided Module
A module a provider supplies: its module URL, how to load its source, and
the specifiers that resolve to it.
_Avoid_: registered module (which is one a runner has been given)

#### Library
A provided module an import map entry names, such as what `preact/hooks`,
`swr` or `@/lib/utils` resolves to. Components import it by its specifier.
It may import other libraries by theirs, and its chunks by URL. Each counts
on its own, so `preact` and `preact/hooks` are two libraries.
_Avoid_: entry module, package (which is the whole npm package)

#### Chunk
A provided module no import map entry names. Libraries import it by URL,
and may share it, as a bundler splits out the code they have in common.
_Avoid_: shared module, internal module

#### Realm Setup Module
The SSR-only module every main module imports first, and the base's first
preload module, so it evaluates before any component in the realm. It tames
the configured properties, freezes every built-in, makes every existing
global non-writable and non-configurable, and keeps the global object
extensible. It defines `drupalSettings`, which no island can redefine or
delete. Before each island, it puts back the global object's prototype and
`console`'s methods, and deletes the globals an island's render added. Lines
added to every other module hand it their exports to freeze, and tell it
which globals the module added while it evaluated, which it keeps, and puts
back if a render reassigns or deletes them.
_Avoid_: lockdown, SES

#### Tamed Property
A built-in's or an export's property that the realm setup module turns into
an accessor before freezing it, so an object that inherits it can still set
its own, as `error.name` on an `Error` subclass. A site can add to them, as
[CONFIGURATION.md](CONFIGURATION.md#tamed-properties) says.
_Avoid_: enablement, override

#### Mutate
To set, redefine or delete a property of an object, or of an object it
reaches, such as an exported class's prototype. A module can mutate what
another module exports, a built-in, `console` or preact's `options`. It
can't make a module export something else. A global is added, reassigned or
deleted, and the object it holds is mutated.
_Avoid_: change (when it could mean either), modify, patch

#### SSR-only Module
A provided module the browser never loads, registered under
`/_canvas_ssr/ssr-only/`: a framework renderer, a shim that replaces a
browser package under SSR, a warm-up.
_Avoid_: server module, shim (one kind of SSR-only module)

#### Web Platform API
An API the web platform defines that every module may use during SSR. Those
supported today are `URL`, `URLSearchParams`, `Headers`, `TextEncoder`,
`TextDecoder`, `atob()`, `btoa()`, `queueMicrotask()`, `DOMException`,
`console` and a `performance` with only `now()` and `timeOrigin`. Renderer
binds `fetch()`, and the runner supplies an `import.meta.resolve()` that
answers from the SSR import map. A realm has no other host API, so no
timers, workers, storage or network. The list can evolve as components
need more.
_Avoid_: web global, polyfill, Node API

### Runners

#### Runner
What a render runs JavaScript on: an implementation of `RunnerInterface`.
A render uses one runner from its first registration to its result.
_Avoid_: runtime, engine, VM, backend

#### Runner Module
A Drupal module that supplies a runner, tagged `canvas_ssr.runner`. It
depends on `canvas_ssr`, never the reverse, and whatever its runner needs
from the server is the runner module's requirement.
_Avoid_: runner plugin, backend module

#### Available
A runner that can run on this system: its binary, extension or directories
are in place. An unavailable runner is skipped; with none available, every
island falls back to CSR.
_Avoid_: enabled, installed, ready

#### Realm
The global object and the modules evaluated in it, which a runner keeps from
one invoke to the next so modules evaluate once. What one island can change
in it for later ones, the realm setup module limits. A script failure keeps
it, as a throw keeps a browser's page. A runner must replace it after a
runner failure, and may at other times for its own reasons; registrations
outlive it.
_Avoid_: runtime, context, session, execution context

#### Global
A property of the global object, `globalThis`: a built-in, a web platform
API, a binding, `drupalSettings`, or one a component adds.
_Avoid_: global variable (for site and page data)

#### Registrations
What a runner resolves against when it builds and runs a realm: the
registered modules, the bindings, and the base in use, which brings the
import map. They last until reset and outlive the realm.
_Avoid_: setup, configuration, state

#### Registered Module
A module a runner has been given under a module URL. Registering the same
source again does nothing; different source under the same URL replaces
it.
_Avoid_: added module, loaded module

#### Binding
A PHP callback installed as a global function in the realm, with JS to turn
the call into a string and the string back into a result. Synchronous, and
only strings cross.
_Avoid_: host function, bridge, callback (the PHP side alone)

#### Import Map
As a registration: the SSR import map of the base in use, in force until
another base is used or reset. With no base in use, it's empty.
_Avoid_: map

#### Base
The modules nearly every render registers, the preload modules that evaluate the
libraries among them, the modules to compile, and the import map in force with
them, under an id that covers them all. Those are its definition. canvas_ssr
defines it, and a runner builds from it and may keep what it builds. A base
never changes output, only what a render costs. "Base path" is Drupal's term and
is never shortened to "base".
_Avoid_: base set, shared modules

#### Base in Use
The base whose modules count as registered on a runner, until another id is
used or reset.
_Avoid_: held base, active base, loaded base

#### Preload Modules
A base's modules to import, in order, when a runner builds from it, so the
libraries renders use are loaded and evaluated the way renders would. A runner
may keep the realm that evaluated them, or only what it compiled, as the Deno
runner does, so each request's process evaluates them again. No component's
module is one.
_Avoid_: entries, warm-up (except for the tailwind-merge module, which is one)

#### Modules to Compile
A base's modules a runner may load and compile when it builds from it, but
never evaluates where a render sees it: the exposed components' main
modules and libraries modules. A component's module evaluates only in a
request that renders it.
_Avoid_: lazy modules, deferred modules

#### Unloadable
A preload module or module to compile that failed to load when the runner
built from the base, so invoking it fails the same way in every realm. A
render of one falls back to CSR without invoking.
_Avoid_: broken entry, failed entry

#### Invoke
One `invoke()` call on a runner: import the main module, which loads and
evaluates it and whatever it imports that the realm hasn't yet, call its
default export with the payload, and settle the result.
_Avoid_: run, call, execute

#### Reset
Return a runner to the state it had when constructed: every registration and
the realm go; bases it keeps and its caches may stay.
_Avoid_: discard, clear, forget

### Fetch

#### Same-site Fetch
The `fetch()` bound for components: allowlisted same-site GET and HEAD
requests, run as Drupal subrequests with the current request's cookies and
session, whose cacheability joins the render's.
_Avoid_: internal fetch, subrequest fetch

#### Path Policy
A tagged service that allowlists paths for the same-site fetch, such as
JSON:API and menu linksets.
_Avoid_: allowlist, fetch rule

#### Hydration Data
What a hydrated island's libraries give its first render in the browser, by
library name: state they rendered from on the server that the browser doesn't
have yet, such as what `useSWR()` fetched. It goes to the browser on the
island, so the first render there matches the server's.
_Avoid_: dehydrated state, SSR cache, preloaded data, SWR fallback

### Deno runner

#### Deno Import Map
The import map the Deno runner derives from the SSR import map and starts
each Deno process with. It locates registered modules in the runner cache
and base cache, and blocks every `node:` builtin. `import.meta.resolve()`
doesn't read it; the SSR import map is sent to the process for that.
_Avoid_: child import map, Deno's map, cache map

#### Runner Cache
A runner's own `DENO_DIR`, made when first needed, seeded with the modules
it registers, linked to the base cache for the base in use, used by every
Deno process the runner starts, and deleted on reset.
_Avoid_: session, cache directory (bare), work path, process cache

#### Base Cache
The Deno runner's cache directory for one base, holding its modules in
Deno's module cache and Deno's caches after preloading them, built once per
base caches directory and never written again.
_Avoid_: frozen base, base build, base snapshot

#### Seed
Write a registered module's source into a runner cache or base cache as the
file Deno reads for the module's cache URL.
_Avoid_: write, upload, inject

#### Cache URL
Where a Deno process loads a module from: its module URL as a path on an
origin, with an absolute URL under `/_url/`, scheme first. The Deno runner
puts module URLs back wherever cache URLs would leave the process.
_Avoid_: seeded URL, child URL

#### Origin
One of the two addresses nothing listens on that seeded modules live under:
one for runner caches, one for base caches. Deno looks a URL up in its
cache and, with `--cached-only`, never tries the network.
_Avoid_: host, server

#### OS User
The operating system account PHP runs as, such as `www-data`. Not a Drupal
user. The Deno runner keeps its caches in a directory per OS user, named
`drupal-<uid>`, which sites run by one OS user share and no other OS user
may write to.
_Avoid_: user, web server user, system user

#### Command
One message PHP sends a Deno process, such as an invoke or a preload, and the
process's reply to it. Each command gets its own time limits. A Deno process
serves the commands of one request, unless it's replaced. While a command
runs, the process may call bindings, which PHP answers before the reply
comes.
_Avoid_: request, message (which also covers replies and binding calls)
