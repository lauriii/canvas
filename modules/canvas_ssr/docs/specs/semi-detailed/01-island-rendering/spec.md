# Island Rendering

## Purpose

An [island](../../../GLOSSARY.md#island) is one placement of a Canvas code
[component](../../../GLOSSARY.md#component) on a page. A
[render](../../../GLOSSARY.md#render) of it ends as a [static
island](../../../GLOSSARY.md#static-island), whose server-rendered markup is
final, a [hydrated island](../../../GLOSSARY.md#hydrated-island), whose markup
hydrates in the browser, or a [CSR fallback](../../../GLOSSARY.md#csr-fallback).
This spec covers when each happens, what SSR does with props and slots, and what
cacheability the island carries.
[module-resolution](../02-module-resolution/spec.md) covers imports,
[realm-sharing](../03-realm-sharing/spec.md) covers what islands may do to the
realm they share,
[site-and-page-data](../04-site-and-page-data/spec.md) covers [site
data](../../../GLOSSARY.md#site-data) and [page
data](../../../GLOSSARY.md#page-data), and
[same-site-fetch](../06-same-site-fetch/spec.md) covers `fetch()`.

## Requirements

### Requirement: An island renders on the server when it can, and falls back to Canvas's own CSR otherwise

SSR SHALL render an island on the server when its component can run there.
Otherwise SSR SHALL fall back to CSR. A CSR fallback SHALL be exactly the island
Canvas renders without SSR, so turning on SSR never makes a page worse than
having none.

#### Scenario: A component that can run renders its markup

- **GIVEN** an [exposed](../../../GLOSSARY.md#exposed) component with compiled
  JS for a supported [framework](../../../GLOSSARY.md#framework)
- **WHEN** an island of it renders
- **THEN** the page carries the markup the component produced
- **AND** the island isn't marked client-only

### Requirement: An island hydrates only when its code asks to

An island SHALL be hydrated when any component in its
[graph](../../../GLOSSARY.md#component-graph) is a [client
boundary](../../../GLOSSARY.md#client-boundary). Otherwise it SHALL be static.
For React and Preact, a component marks itself as a client boundary with a
`"use client"` directive in its compiled JS. A static island SHALL ship no
component code and no island element. When whether a component is a client
boundary can't be told, as when its compiled JS can't be parsed, the island
SHALL fall back to CSR without invoking anything.
[render-performance](../09-render-performance/spec.md) says how long that's
cached.

#### Scenario: A graph that declares nothing renders a static island

- **GIVEN** a component and the components it imports, none of them a client
  boundary
- **WHEN** an island of it renders
- **THEN** the page carries the component's markup alone, with no island element
  and no script

#### Scenario: A component whose code can't be parsed falls back to CSR

- **GIVEN** a component with `"use client"` whose compiled JS nests too deeply
  to parse
- **WHEN** an island of it renders
- **THEN** it falls back to CSR, cached as a render would be, rather than
  rendering markup that wouldn't hydrate

#### Scenario: A dependency declaring it hydrates the whole island

- **GIVEN** a component that imports a component that imports a React or Preact
  one with `"use client"`
- **WHEN** an island of the root renders
- **THEN** the island is marked to hydrate on load, with the whole graph's
  markup inside it

### Requirement: Preconditions are checked before any JavaScript runs

A render SHALL [bail](../../../GLOSSARY.md#bail), falling back to CSR without
invoking the component, when any of these is true:

- No [runner](../../../GLOSSARY.md#runner) is
  [available](../../../GLOSSARY.md#available).
- No [boundary checker](../../../GLOSSARY.md#boundary-checker) or [framework
  renderer](../../../GLOSSARY.md#framework-renderer) is provided for the
  component's framework.
- The component has no compiled JS.
- A key of site or page data the graph declares has no provider.
- The island's [import map](../../../GLOSSARY.md#import-map) isn't the [site
  import map](../../../GLOSSARY.md#site-import-map).
- A component in the graph imports a module by URL, which
  [module-resolution](../02-module-resolution/spec.md) refuses.
- A component in the graph has compiled JS that can't be parsed.

#### Scenario: A framework no renderer is provided for

- **GIVEN** an island that declares a framework SSR has no renderer for
- **WHEN** it renders
- **THEN** it falls back to CSR
- **AND** the island still declares the framework it was given

### Requirement: Markup the browser would parse differently falls back to CSR

In the browser, the content of a `script`, `style`, `xmp`, `iframe`, `noembed`,
`noframes`, `noscript`, `textarea` or `title` is set as text. In
server-rendered HTML, the browser's parser reads it, so it can end the element
early. A render SHALL fail as a [script
failure](../../../GLOSSARY.md#script-failure), before any markup is sent, when
any of these would happen:

- The content set through `dangerouslySetInnerHTML` contains the element's end
  tag, in any case.
- A `script`'s content has `<!--` and then `<script`, which keeps it open past
  its end tag.
- A `plaintext` element is rendered, since it never ends.
- An element is the child of one of these, which the parser would read as text.
- Text in one that doesn't decode character references, which is all but
  `textarea` and `title`, has a quote, `&` or `<`, which server rendering
  escapes as one.

A warning SHALL name the component and say what to change, since the
component works in the browser, and its author may not notice otherwise.
Other elements aren't checked, since content set as HTML is parsed as HTML in
the browser too.

#### Scenario: A JSON-LD script a prop would end early falls back to CSR

- **GIVEN** a component that writes `JSON.stringify(props)` into a JSON-LD
  script
- **WHEN** an island of it renders with a prop containing `</script>`
- **THEN** it falls back to CSR, cached as a render would be, and a warning
  names the component
- **AND** with `<` escaped as `\u003c`, it renders on the server

#### Scenario: Text a style wouldn't decode falls back to CSR

- **GIVEN** a component that renders a font name in quotes as a `style`'s text
- **WHEN** an island of it renders
- **THEN** it falls back to CSR, since the server-rendered quotes would be
  character references

### Requirement: A style object renders as the browser would set it

In the browser, each property of a `style` object is set on its own, and the
browser sets nothing for a name that isn't a property's, or a value that isn't
one declaration's. Server-rendered, the properties are joined into one `style`
attribute. That attribute SHALL have only the declarations the browser would
have set, so it SHALL leave out a property when any of these is so:

- Its name has anything but letters and hyphens in it, and it isn't a custom
  property's.
- Its value has a `;` or a `!` outside of a string, a URL, and any brackets,
  so `!important` is left out too.
- Its value closes a bracket it didn't open, has a newline in a string, or has
  a URL without quotes that can't be one.

A value that leaves a string, comment, URL or bracket open SHALL be rendered
with it closed, as the browser closes it, so that it can't take in the
declarations after it. A backslash at a value's end SHALL be rendered as the
browser reads it, which is as nothing in a string and as U+FFFD elsewhere, so
that it can't escape what follows. Whether a value suits its property is left
to the browser, as it is for a `style` given as a string, which SHALL render
as given.

#### Scenario: A prop can't add a declaration

- **GIVEN** a component that renders `style={{ color: props.color, width:
  '5px' }}`
- **WHEN** an island of it renders with a color of `red;position:fixed`
- **THEN** its `style` attribute has the width and nothing else

#### Scenario: A semicolon in a URL is part of the value

- **GIVEN** the same component
- **WHEN** an island of it renders with a color of
  `url("data:image/png;base64,AA")`
- **THEN** its `style` attribute has that value whole

#### Scenario: A value left open is closed

- **GIVEN** the same component
- **WHEN** an island of it renders with a color of `rgb(1,2,3`
- **THEN** its `style` attribute has `rgb(1,2,3)`, and the width after it

### Requirement: A failure falls back to CSR, cached for as long as it would recur

A [script failure](../../../GLOSSARY.md#script-failure) is a throw while a
module [evaluates](../../../GLOSSARY.md#evaluate) or the component renders, or
an import that can't [load](../../../GLOSSARY.md#load). It recurs with the same
inputs, so its CSR fallback SHALL be cached as a render would be. A [runner
failure](../../../GLOSSARY.md#runner-failure) is anything else that stopped the
[invoke](../../../GLOSSARY.md#invoke), such as a time limit, a crash, or a
[base](../../../GLOSSARY.md#base) that couldn't be built. It may not recur, so
it's a [transient error](../../../GLOSSARY.md#transient-error): its CSR fallback
SHALL be cached for one minute, with the `canvas_ssr_transient_error` tag, and
SSR tried again after that. Neither kind SHALL leave the page without an island.

A page with that tag SHALL expire from every cache with its max-age, though
Drupal's page cache otherwise ignores max-age. With a max-age of 0, it SHALL be
kept out of the page cache, and its headers SHALL say not to cache it, as for a
server error. Otherwise its `Expires` SHALL be its max-age from now, and a
public `Cache-Control` max-age SHALL be lowered to it. A page without the tag
SHALL be cached as Drupal caches it.

A render that goes past a time or memory limit rarely passes, as with an
accidental infinite loop, though the component may work in the browser. So it
SHALL be warned of, throttled, naming the component.

A [realm](../../../GLOSSARY.md#realm)'s memory is shared by the islands
invoked in it. So an island that goes past the memory limit in a realm that
earlier islands were invoked in, whatever came of them, SHALL render once
more, in a new realm. If it renders, a warning, throttled, SHALL name it and
the components of those earlier islands, since one of them may keep more
memory than it needs.

An island that goes past the memory limit with a realm to itself, whether as
the first in its realm or on that second render, would again with the same
inputs. So though it's a runner failure, its CSR fallback SHALL be cached as a
render would be, and not as a transient error. Going past a time limit depends
on how busy the host is, so that stays a transient error.

#### Scenario: A component that throws while rendering

- **GIVEN** a component whose default export throws
- **WHEN** an island of it renders
- **THEN** it falls back to CSR
- **AND** the fallback is cached as a render would be, with the render's cache
  tags

#### Scenario: A render past the time limit is warned of

- **GIVEN** a component whose render never ends
- **WHEN** an island of it renders
- **THEN** it falls back to CSR, as a transient error
- **AND** a warning names the component and the limit

#### Scenario: An island left too little memory renders in a new realm

- **GIVEN** an island that has rendered, and a runner that then runs out of
  memory
- **WHEN** an island of another component renders
- **THEN** it renders on the server, cached as a render
- **AND** a warning names both components
- **AND** had the runner run out twice, it would fall back to CSR, cached as
  a render would be, with a warning that names it and the limit

#### Scenario: An island that runs out of memory alone isn't tried again soon

- **GIVEN** a runner that runs out of memory during the first island invoked
  in its realm
- **WHEN** that island renders
- **THEN** it renders once, and falls back to CSR, cached as a render would
  be, without the transient error tag

#### Scenario: A runner failure falls back to CSR for a while

- **WHEN** the runner fails during an island's invoke
- **THEN** the island falls back to CSR, cached for one minute, with the
  transient error tag
- **AND** once the runner works, the next render carries the markup

#### Scenario: A page with a transient error expires with it

- **GIVEN** a page an anonymous visitor may cache, with an island of a
  transient error, and a page max-age longer than the island's
- **WHEN** the response is sent
- **THEN** its `Expires` is the island's max-age from now, so the page cache
  expires it then
- **AND** its `Cache-Control` max-age is the island's

#### Scenario: A page with a transient error of max-age 0 isn't cached

- **GIVEN** a page with an island of a transient error whose max-age is 0
- **WHEN** the response is sent
- **THEN** the page cache doesn't store it, and its headers say not to cache it

### Requirement: Islands on a page are independent

One island's failure SHALL NOT affect the islands rendered after it, and an
island's markup SHALL NOT depend on which islands rendered before it. Islands
share a [realm](../../../GLOSSARY.md#realm) for the whole request, and their
markup is cached for other pages and users.
[realm-sharing](../03-realm-sharing/spec.md) covers how SSR keeps them apart.

#### Scenario: A failed render leaves the islands after it untouched

- **GIVEN** a component that throws, and a Preact component with `"use client"`
- **WHEN** an island of the first renders, then an island of the second
- **THEN** the second is a hydrated island with its full markup

### Requirement: A render covers the component's whole graph

A render SHALL involve the component and every component its configuration
declares it imports, directly or indirectly. Dependencies SHALL render into the
root's markup, and their data dependencies and cacheability SHALL count as
the root's. A saved change to any component in the graph SHALL show in the next
render.

#### Scenario: An imported dependency renders into the root's markup

- **GIVEN** a root component whose configuration lists a dependency it imports
  and renders
- **WHEN** an island of the root renders
- **THEN** the dependency's markup is inside the root's

### Requirement: Previews render drafts; live renders never do

A [preview](../../../GLOSSARY.md#preview) SHALL render each component's
[draft](../../../GLOSSARY.md#draft), if it has one, instead of the
[published](../../../GLOSSARY.md#published) component, for the root and every
dependency. A render that isn't a preview SHALL use published components only,
because a draft is one editor's unsaved work.

#### Scenario: A live render and a preview of one island differ

- **GIVEN** a root that imports a dependency with an unsaved draft
- **WHEN** an island of the root renders live, then in a preview
- **THEN** the live island carries the saved dependency's markup, and the
  preview carries the draft's

### Requirement: Slots are rendered once and given to the component as props

An island's slots SHALL be rendered to HTML once, before SSR, and the component
SHALL receive each one as a prop with the slot's name. The same HTML SHALL serve
the server-rendered markup and, for a hydrated island, the hydration template. A
string given as a slot or a prop is text, not markup. A preview SHALL print the
slot markers Canvas's editor uses to place slots, and a live render SHALL print
none.

#### Scenario: A string slot is escaped rather than parsed as markup

- **GIVEN** a slot whose value is the string `<img src=x onerror=alert(1)>`
- **WHEN** the island renders
- **THEN** the page carries it escaped, and no such element

#### Scenario: A hydrating island renders its slot once and shows it twice

- **GIVEN** a hydrating component that prints its default slot
- **WHEN** the island renders
- **THEN** the slot's content is rendered once
- **AND** it appears twice: in the server-rendered markup and in the hydration
  template

### Requirement: An island carries the cacheability of everything its render depended on

The island element SHALL carry the cacheability of the site's asset library,
each component in its graph, each slot, each site and page data value, its
interface translations, each `fetch()` response, and the attachments of islands
nested in its slots. Every
outcome, success or fallback, SHALL vary with the asset library, because a push
changes what imports [resolve](../../../GLOSSARY.md#resolve) to.

#### Scenario: Saving any component in the graph invalidates the island

- **GIVEN** a root that imports a component that imports another
- **WHEN** an island of the root renders
- **THEN** its cacheability includes each of the three components'

#### Scenario: The cacheability of a slot SSR absorbed still bubbles

- **GIVEN** a slot with its own cache tags and contexts, on a static island
- **WHEN** the island renders
- **THEN** the island's cacheability includes the slot's, even though the slot
  was printed only inside the server-rendered markup

### Requirement: A component that renders nothing produces an empty island, not a failure

A component that renders to nothing SHALL produce an empty island, not a CSR
fallback.

#### Scenario: A component returning nothing renders nothing

- **GIVEN** a component whose default export returns `undefined` or an empty
  string
- **WHEN** an island of it renders
- **THEN** the island's markup is empty, and it isn't marked client-only
