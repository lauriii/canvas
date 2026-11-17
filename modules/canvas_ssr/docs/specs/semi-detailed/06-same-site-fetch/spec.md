# Same-site Fetch

## Purpose

A [component](../../../GLOSSARY.md#component) may call `fetch()` while it
renders on the server, just as it does in the browser. SSR answers with a
[same-site fetch](../../../GLOSSARY.md#same-site-fetch): an allowlisted
same-site GET or HEAD request, run as a Drupal subrequest in the current
request's context. Its result is what the browser's `fetch()` would return, and
its cacheability joins the [render](../../../GLOSSARY.md#render)'s. SSR refuses
everything else.

This spec is for component authors, and for anyone adding a [path
policy](../../../GLOSSARY.md#path-policy).

## Requirements

### Requirement: Only same-site GET and HEAD requests to allowlisted paths are made

A fetch SHALL be made only when its URL is on the current site (a rooted path,
or an absolute URL with the site's scheme, host, port and base path), its method
is GET or HEAD, and a path policy allows its path. Anything else SHALL be
refused before any request is made, as SHALL a request longer than 256 KiB once
marshaled, or with more than 100 headers. No real request is either, and each
would cost PHP many times what it holds to handle. A refused fetch SHALL fail
the render, so the [island](../../../GLOSSARY.md#island) falls back to CSR
instead of rendering without the data.

#### Scenario: A JSON:API client renders its data

- **GIVEN** a component that uses Canvas's JSON:API client to read a collection,
  with a query built by the client's params helper
- **WHEN** it renders
- **THEN** the markup has the collection, in the order the query asked for
- **AND** a client built on the site's own base URL still counts as same-site

#### Scenario: A cross-site URL is refused

- **GIVEN** a component that fetches a URL on another host
- **WHEN** it renders
- **THEN** no request is made, and the island falls back to CSR

#### Scenario: Another port on the site's host is refused

- **GIVEN** a component that fetches a URL on the site's host, on a port the
  site isn't served from
- **WHEN** it renders
- **THEN** no request is made, and the island falls back to CSR

#### Scenario: A path no policy allows is refused, even a real route

- **GIVEN** a component that fetches `/admin/content`, or any path no policy
  claims
- **WHEN** it renders
- **THEN** no request is made, and the island falls back to CSR

### Requirement: The request runs the way the current request would

The subrequest SHALL carry the current request's cookies and session, so the
component reads what the current user may read. It SHALL also forward the
headers the component set, given as `fetch()` takes them: a record, a `Headers`
object or a list of pairs. As in a browser, a name given more than once, in any
case, SHALL keep each value, joined by a comma and a space, and headers given
with the call SHALL replace those of a `Request` it's given. A component can't
set the Cookie, Authorization or Host headers, which come from the current
request. The site's base path and language prefix SHALL be stripped for the
[path policies](../../../GLOSSARY.md#path-policy)' check and kept for routing,
the way Drupal routes them. Checking a path SHALL change neither the current
request nor the subrequest, whatever an inbound path processor does to the
request it's given. The subrequest SHALL negotiate its own languages, and the
current request SHALL keep its own, though Drupal negotiates each language once
for a PHP request. Where the visitor's browser, account or session decided a
subrequest's language, and not its URL, the response SHALL vary by that.

#### Scenario: A fetch reads what the current user may read

- **GIVEN** an unpublished article
- **WHEN** a component that reads the article collection renders, once for a
  user who may see unpublished content and once for one who may not
- **THEN** only the first render has the article

#### Scenario: A subdirectory install

- **GIVEN** a site served at `https://example.com/subdir/`
- **WHEN** a component fetches `https://example.com/subdir/jsonapi/node/article`
- **THEN** the path policies check the path as `/jsonapi/node/article`, and the
  request keeps `/subdir`
- **AND** a fetch of `https://example.com/jsonapi/node/article` is refused,
  because it's outside the install

#### Scenario: A language prefix returns translated results

- **GIVEN** a site that negotiates language from a `/es` path prefix
- **WHEN** a component fetches `/es/jsonapi/node/article`
- **THEN** the path policies check the path without the prefix, the request
  keeps it, and the component gets the Spanish translation
- **AND** it does whether or not the page had negotiated its language before
- **AND** what renders after the island is still in the page's language

#### Scenario: A URL without a prefix gets the browser's language

- **GIVEN** a site that negotiates language from a path prefix and then from
  the browser, and a page in Spanish
- **WHEN** a component on it fetches `/jsonapi/node/article`, for a visitor
  whose browser asks for English and then one whose browser asks for Spanish
- **THEN** each gets the island in their browser's language, since the island
  varies by the `Accept-Language` header
- **AND** an island that fetches `/es/jsonapi/node/article` doesn't vary by it

#### Scenario: An inbound path processor that changes its request

- **GIVEN** an inbound path processor that sets a query parameter on the
  request it's given, as the one for image style URLs does
- **WHEN** a component fetches a URL, allowed or refused
- **THEN** neither the current request nor the subrequest has the parameter

#### Scenario: Request headers are sent as a browser sends them

- **GIVEN** a component that calls `fetch()` with a `Request`-like object
  carrying a header, and headers that give `Accept` twice, in different cases
- **WHEN** the island renders
- **THEN** the subrequest's `Accept` has both values, joined by a comma and a
  space
- **AND** it lacks the `Request`'s header, which the call's headers replaced

### Requirement: The response is what the browser's fetch would return

The component SHALL receive the response's status and status text, its headers,
its body, and its URL. Header names SHALL be lower-cased, and repeated values
joined. A HEAD response SHALL have an empty body. Reading a body that isn't
JSON, such as an empty one, as JSON SHALL reject with a `SyntaxError`, as in a
browser.

#### Scenario: A response comes through as the fields JS reads

- **GIVEN** a 200 response with a content type and two cache-tag headers
- **WHEN** the component reads it
- **THEN** it sees status 200, `OK`, `content-type`, `x-drupal-cache-tags` with
  its values joined by a comma, the body, and the URL

#### Scenario: An empty body read as JSON rejects

- **GIVEN** a 200 response with an empty body
- **WHEN** the component calls `json()` on it
- **THEN** the promise rejects with a `SyntaxError`, as in a browser

### Requirement: What a fetch read is part of the render's cacheability

The island SHALL carry the cache tags and contexts of every response its render
fetched, so it's invalidated when the fetched content changes and varies as the
content varies. Every island SHALL fetch for itself through `useSWR()`: a
response one island cached there isn't served to another, which would carry no
tags for it. A component's own module evaluates once in a realm, and what it
keeps is served to its later islands. So an island SHALL also carry the
cacheability of what the code of each of its component modules fetched earlier
in the request, and what went wrong with it. Whose code made a fetch SHALL be
read from the call stack of the `fetch()` call: the module that made the call,
and any whose function called into it. `swr` and the JSON:API client fetch for
the components that import them, when none of those may be on the stack. So a
fetch whose stack names a module of one of those libraries, or any component,
SHALL also count for the island's components that import one, themselves or
through other modules the import map names. One that imports `drupal-canvas`
SHALL count only if its source mentions `JsonApiClient`, and one that imports
`drupal-canvas/react` only if it mentions `useJsonApiClient`, which gives it
the client SSR made for the island. A fetch that then
counts for none of its island's component modules SHALL count for all of them.

#### Scenario: A fetched node bubbles its own tag and its list's tag

- **WHEN** a component renders an article it fetched from JSON:API
- **THEN** the island's cacheability includes the node's tag and the node list
  tag

#### Scenario: The contexts JSON:API varied by bubble along with the tags

- **WHEN** a component fetches from JSON:API
- **THEN** the island's cacheability includes the contexts the response varied
  by, like the user's permissions

#### Scenario: Every island fetches again, so each carries the tags

- **GIVEN** two islands of a component that reads an article through a
  data-fetching library with a cache
- **WHEN** both render
- **THEN** both carry the article's tags, not only the first

#### Scenario: A later island takes on what a component's module kept

- **GIVEN** a component whose module fetches an article as it evaluates, or
  keeps one a render fetched in a variable of its own
- **WHEN** two islands of it render in one request, then an island of a
  component that imports it, then an island of a component that doesn't
- **THEN** all but the last carry the article's tags

#### Scenario: Islands that only share a button don't affect each other

- **GIVEN** two components that import the same button, one of which makes a
  fetch SSR refuses
- **WHEN** an island of each renders, the refused one first
- **THEN** the other renders on the server, with none of the first's
  cacheability

#### Scenario: A later island takes on what a module it imports fetched

- **GIVEN** a component whose function fetches an article for its caller and
  keeps it, and two components that call it
- **WHEN** an island of each caller renders
- **THEN** both carry the article's tags

#### Scenario: A fetch that no module's code is on the stack of counts for all

- **GIVEN** a component that hands `fetch` itself to a promise to call
- **WHEN** an island of it renders, then an island of a component that shares
  only a button with it
- **THEN** the second carries what the first fetched

#### Scenario: A library's fetch counts for the components that import it

- **GIVEN** two components that import the same button, one of which reads
  through `useSWR()`, or through the JSON:API client without it
- **WHEN** an island of each renders, the reader first
- **THEN** the other carries none of what the reader fetched
- **AND** a later island with a component that kept what `useSWR()` got, from
  a fetcher its parent handed it, carries that

#### Scenario: A library imported through a pushed module counts

- **GIVEN** a pushed `@/lib` module that imports `swr` through a chunk, and a
  component that reads through it and imports a button
- **WHEN** an island of it makes a fetch SSR refuses, then an island of a
  component that shares only the button renders
- **THEN** the second renders on the server

#### Scenario: An island that takes on a refused fetch isn't rendered

- **GIVEN** a component whose island took on a fetch SSR refused, which an
  earlier island of another component made
- **WHEN** it would render
- **THEN** it falls back to CSR without rendering, so it makes no fetch
- **AND** the warning names the component whose island made the fetch
- **AND** a later island of that component is told an earlier island of it
  made the fetch, since it makes none itself
- **AND** its fallback has that component's cache tags, so it ends when the
  component changes
- **AND** its fallback is cached for an hour at most, since what ruled the
  fetch out may have been the earlier island's own, like one of its props

### Requirement: An island a fetch renders falls back to CSR

A fetch's subrequest can render islands, as Drupal's error page for a path it
doesn't route can. Each SHALL fall back to CSR, since the runner is busy with
the island that fetched. Nothing SHALL be resolved for them first: their page
data would be the subrequest's route's, and they aren't taken to have fetched
anything. That island SHALL render as if they weren't there, with the
cacheability of everything it fetched. Such a fallback recurs every
time, so it SHALL be cached as a render would be, not as a transient error. So
that a page that shows the island doesn't get it, every island SHALL vary by
the `canvas_ssr_nested` cache context: whether it's rendered from inside
another island's render.

#### Scenario: An island on a fetched page falls back

- **GIVEN** a component that fetches an article, then a path whose page has an
  island on it
- **WHEN** it renders
- **THEN** the page's island falls back to CSR
- **AND** the component renders on the server, with the article's tag and the
  page's
- **AND** neither is a transient error, and both vary by `canvas_ssr_nested`

#### Scenario: An island on a fetched page leaves the page's own data alone

- **GIVEN** a page whose title an island renders, and a component on it whose
  fetch gets a page with that title island on it, then fetches an article and
  keeps its title
- **WHEN** the component renders, then the page's title island, then the
  component again
- **THEN** the title island has the page's title, not the fetched page's
- **AND** the component's second island has the article's tag

#### Scenario: A page island that bails early leaves the article tag

- **GIVEN** the same, but the page's island has no compiled JS, so it never
  reaches the runner
- **WHEN** the component renders
- **THEN** its island still has the article's tag

### Requirement: A fetch's answer decides how long the island is cached

A response whose cacheability isn't known, as one that isn't cacheable or comes
from a route marked `no_cache`, says nothing of how long markup made from it
holds. The component SHALL still get it, as in the browser, but the island
SHALL fall back to CSR, cached as a render would be, since the endpoint answers
that way every time. A server error is a [transient
error](../../../GLOSSARY.md#transient-error): the island SHALL be cached for at
most a minute, or as the response says if that's shorter, with the
`canvas_ssr_transient_error` tag. So SHALL a fetch that fails other than by
being refused or malformed. A refused fetch, or a request PHP can't read, as
one with a lowercase method, fails the same way every time, so it SHALL be
cached as a render would be.

#### Scenario: A response whose cacheability isn't known falls back to CSR

- **GIVEN** a component that fetches an endpoint answering without cacheability
- **WHEN** it renders
- **THEN** the island falls back to CSR, cached as a render would be, without
  the transient error tag

#### Scenario: A server error is a transient error

- **GIVEN** a component that renders the status of a fetch the site answers
  with a server error
- **WHEN** it renders
- **THEN** it renders on the server, cached for at most a minute, with the
  transient error tag
- **AND** a server error JSON:API gives max-age 0 leaves the island max-age 0

#### Scenario: A refused fetch is cached as a render

- **GIVEN** a component whose fetch is refused, as a cross-site one is
- **WHEN** it renders
- **THEN** the island falls back to CSR, cached as a render would be, without
  the transient error tag

### Requirement: A failed fetch fails the render

When the site answers a fetch with an error, the fetch SHALL resolve to that
response, just as in the browser, and a component that throws on it SHALL fail
the render. A fetch that fails, refused or otherwise, SHALL reject with a
`TypeError`, as the browser's does, rather than throw. SSR refuses fetches the
browser makes, so the island SHALL fall back to CSR however the component
handles the rejection, and a fetch that fails the same way every time SHALL be
warned of, throttled, naming the component. A `fallbackData` given to a
data-fetching library is the browser's placeholder, so it SHALL NOT be rendered
as an answer, unless the library makes no request at all.

#### Scenario: An error answer is a response, as in the browser

- **GIVEN** a component that fetches an allowlisted path, and the site answers
  with 404
- **WHEN** it renders
- **THEN** its fetch resolves to a response whose status is 404 and whose `ok`
  is false
- **AND** a component that throws on that response falls back to CSR

#### Scenario: A failed fetch falls back to CSR, even if it's handled

- **GIVEN** a component that catches its fetch's rejection and renders
  something else, and whose fetch is refused
- **WHEN** it renders
- **THEN** its fetch rejects with a `TypeError`, and the island falls back to
  CSR, cached as a render would be
- **AND** a warning names the component and why the fetch failed

#### Scenario: A failed fetch, even where fallbackData was supplied

- **GIVEN** a component that supplied `fallbackData`, and whose fetch fails
- **WHEN** it renders
- **THEN** the island falls back to CSR, instead of rendering the placeholder

### Requirement: A hydrated island starts in the browser from what it fetched

A [hydrated island](../../../GLOSSARY.md#hydrated-island) SHALL carry its
[hydration data](../../../GLOSSARY.md#hydration-data): what each of its
libraries provided while it rendered on the server, by library name. In the
browser, Canvas's island client renderer SHALL give each library its part
before the island hydrates, so the component's first render there has the
state the server rendered with, and finds the DOM it expects. A
[static island](../../../GLOSSARY.md#static-island) ships no code, so it SHALL
carry none. A library's data SHALL be the island's own: no island carries
what a library provided while another rendered.

Data the browser can't read, because devalue can't decode it, no consumer in
the browser is for its library, or its library's consumer can't take it,
SHALL be left out, with a warning in the console, and
the island SHALL render afresh instead of hydrating, replacing its markup, as
its first render would differ from it.

SWR provides what each of the island's `useSWR()` calls fetched, keyed as SWR
keys it. The server SHALL tell keys apart as SWR does, and fetch once for keys
SWR takes as one, such as objects with their properties in another order. A key
with an object SWR tells apart by its identity, such as a `Map` or an object
that holds itself, SHALL make the island fall back to CSR. While the island
hydrates, each of those keys SHALL give in the browser what it gave on the
server: the data, neither loading nor validating, even if SWR's cache already
has other data for the key. The browser SHALL not fetch those keys when the
island mounts, unless the component or an `SWRConfig` sets `revalidateOnMount`
or an `onSuccess`, which SWR calls only once a fetch the hook started succeeds.
After mount, SWR SHALL revalidate them as it would without SSR, such as on
focus, on reconnect, at a refresh interval or on `mutate()`, without showing a
loading state for a key its cache didn't have. SWR SHALL fetch on mount a key
the server didn't fetch, as it would without SSR. What a component fetches some
other way, and keeps for its render, no library provides, so the browser
fetches it again. As in SWR, a key that's falsy, such as `null` or `""`, or an
empty array, SHALL not be fetched, and SHALL give the `fallbackData`.

Hydration data SHALL be encoded by devalue, from Canvas's import map entry
for `@drupal-canvas/astro-hydration/hydration-data`, and decoded in the
browser by the same copy, so it comes back as the library had
it, or the browser's first render could differ from the server's. That
includes what JSON can't carry, such as a `Date`, a `Map`, `undefined`, a
number like `NaN`, and objects that refer to each other, as a JSON:API client
gives related resources. Where devalue can't encode it, such as a function or
a class instance, the hydrated island SHALL fall back to CSR, and a warning
SHALL name the component.

Hydration data SHALL be at most 128 KiB as escaped for the island's
attribute, since the page and the render cache carry it with the island. A
hydrated island whose libraries provided more SHALL fall back to CSR, and a
warning SHALL name the component.

#### Scenario: A hydrated island renders its data without fetching it

- **GIVEN** a component with `"use client"` that reads the article collection
  through `useSWR()` and the JSON:API client, and shows a loading state until
  it has data
- **WHEN** it renders on the server and hydrates
- **THEN** the island carries the collection as SWR's hydration data, under
  the component's SWR key
- **AND** the browser's first render shows the collection, not the loading
  state, so the page has the component's markup once, and it responds to input
- **AND** the browser doesn't fetch the collection when the island mounts

#### Scenario: A carried key revalidates after mount as without SSR

- **GIVEN** a hydrated island that carries a key
- **WHEN** the window regains focus after SWR's focus throttle, or the
  component changes to another key
- **THEN** SWR fetches, as it would without SSR

#### Scenario: Keys not carried, or that ask, are fetched on mount

- **GIVEN** a hydrated island with a key it doesn't carry, a carried key
  whose component sets `revalidateOnMount: true`, and a carried key whose
  component sets an `onSuccess`
- **WHEN** it hydrates
- **THEN** SWR fetches all three when the island mounts

#### Scenario: A hydrated island reads isLoading

- **GIVEN** a hydrated island whose component renders its loading state while
  `useSWR()` reports `isLoading`
- **WHEN** it hydrates
- **THEN** its first render shows the data, as on the server

#### Scenario: Another island already fetched the same key

- **GIVEN** two hydrated islands that read the same key, whose markup was
  rendered from different data
- **WHEN** the second hydrates after the first
- **THEN** its first render shows the data it was rendered with
- **AND** once mounted, it shows what SWR's cache has

#### Scenario: Keys SWR takes as one are fetched once

- **GIVEN** a hydrated island whose components read the same key, as objects
  with their properties in another order
- **WHEN** it renders
- **THEN** the server fetches once, and both render what it fetched
- **AND** the island carries one key

#### Scenario: A key SWR tells apart by its identity

- **GIVEN** a component that reads a key holding a `Map`
- **WHEN** it renders
- **THEN** the island falls back to CSR

#### Scenario: A static island carries no hydration data

- **GIVEN** a component without a client boundary that reads the article
  collection through `useSWR()`
- **WHEN** it renders
- **THEN** the page has its markup and no hydration data

#### Scenario: Each island carries only what it fetched

- **GIVEN** two hydrated islands that read different keys
- **WHEN** both render
- **THEN** each carries only its own key, and each is given only its own in the
  browser

#### Scenario: Hydration data the browser can't read is left out

- **GIVEN** an island whose hydration data devalue can't decode, has a library
  no consumer in the browser is for, or has SWR data that isn't a list of keys
  and values
- **WHEN** it hydrates
- **THEN** it renders afresh without that data, replacing its markup, and a
  warning is in the console

#### Scenario: Keys SWR doesn't fetch

- **GIVEN** a component with `"use client"` that calls `useSWR()` with `""`, an
  empty array and `0` as keys, and a `fallbackData`
- **WHEN** it renders
- **THEN** it renders the `fallbackData` without fetching, and its island
  carries no hydration data

#### Scenario: Data JSON can't carry hydrates as the server had it

- **GIVEN** a component with `"use client"` whose `useSWR()` fetcher resolves to
  a `Date` and objects that refer to each other
- **WHEN** it renders and hydrates
- **THEN** it renders on the server
- **AND** the browser's first render gets a `Date`, and the same references

#### Scenario: Data devalue can't encode falls back to CSR

- **GIVEN** a component with `"use client"` whose `useSWR()` fetcher resolves to
  an instance of a class
- **WHEN** it renders
- **THEN** the island falls back to CSR, and a warning names the component
- **AND** the same component without `"use client"` renders on the server, since
  its island carries nothing

#### Scenario: Hydration data too large to carry falls back to CSR

- **GIVEN** a component with `"use client"` whose `useSWR()` fetcher resolves to
  more than 128 KiB once escaped for the island's attribute
- **WHEN** it renders
- **THEN** the island falls back to CSR, and a warning names the component
- **AND** the same component without `"use client"` renders on the server
