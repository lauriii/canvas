# 13. A hydrated island carries its libraries' state to the browser

Status: Accepted

## Context

A [hydrated island](../GLOSSARY.md#hydrated-island)'s markup has to be what the
component's first render in the browser produces, or hydration can't reuse it.
Preact's `hydrate()` doesn't recover from a mismatch: it inserts what the
browser rendered and leaves the server's markup in place, without its event
handlers. React 18 and later discard the server's markup up to the nearest
Suspense boundary instead, but Canvas islands hydrate with Preact.

On the server, `useSWR()` waits for its fetch, since SSR renders no loading
state. In the browser, SWR has nothing yet, so the first render was the
loading state, and every hydrated component that fetched through `useSWR()`
showed twice. Any library that renders from state it built up during the
server render, such as a data-fetching cache, has the same problem.

## Decision

A hydrated island carries its [hydration data](../GLOSSARY.md#hydration-data)
to the browser, in a `hydration-data` attribute on the island element: what
each library provided, by the library's name, encoded by devalue.

- On the server, a library provides its data through
  `@/canvas_ssr/hydration-data` while the island renders, again each time it
  changes. The main module collects what each library last provided for a
  hydrated island, and forgets it before and after each island, so no island
  carries what another provided, whichever framework renderer it uses.
- In the browser, Canvas's island client renderer has a consumer per library
  name. Each loads its library through the import map, and wraps the island's
  component in what gives the library its state. Data devalue can't decode,
  that no consumer knows, or that its consumer can't take, is left out with a
  warning in the console, and the island renders afresh instead of hydrating,
  since its first render would differ from its markup. Preact would leave the
  server's markup in place beside it. A library provides only state its
  island rendered from, so no library's data can be safely ignored.
- SWR is the first library. Its shim provides each key it fetched with what the
  fetcher resolved to. It tells keys apart as SWR does, so it fetches once for
  keys SWR in the browser takes as one, such as objects with their properties
  in another order. SWR tells an object apart from others that look the same,
  such as a Map or one that holds itself, by its identity, which is new each
  time the component renders again on the server, and isn't the same in the
  browser. A key with one throws, so the island falls back to CSR. Its consumer
  adds a middleware to the island's SWR, which keys that by SWR's own
  `unstable_serialize()`. While the island hydrates, each of its keys gives
  what the server rendered with: the data, neither loading nor validating. A
  key SWR's cache doesn't have yet is put there as loaded, so SWR doesn't show
  a loading state for it. A key it has, which another island put there, is left
  alone, and the component renders again once mounted, to show it.
- The server has just fetched each key the island carries, so the browser
  doesn't fetch it again when the island mounts. For each of those keys, the
  middleware passes `revalidateOnMount: false` to the hook's first render,
  unless the component or an `SWRConfig` sets `revalidateOnMount` itself, or an
  `onSuccess`. SWR calls `onSuccess` only once a fetch the hook started
  succeeds, so a component that sets state from it would otherwise wait for the
  next revalidation. The server calls no `onSuccess`, and the browser's comes
  after the island has hydrated, so the first render still matches. SWR reads
  that option only when a hook mounts, so everything after mount is as it would
  be without SSR: SWR revalidates the key on focus, on reconnect, at a refresh
  interval, on `mutate()`, and when a hook mounts later with it. A key the
  server didn't fetch is fetched on mount, as always.

[devalue](https://github.com/sveltejs/devalue), which SvelteKit and Nuxt use
for the same job, encodes it. JSON can't carry a `Date`, `undefined` or
objects that refer to each other, and a JSON:API client gives related
resources as just that. devalue carries those, and the browser gets them back
as the server had them. Canvas builds it into its astro-hydration dist, and
its import map has it as `@drupal-canvas/astro-hydration/hydration-data`, a
name of Canvas's own and not one for code components. So the server and the
browser use the same copy, and its format can't differ between them, while a
site whose code imports devalue still bundles its own.

If devalue can't encode the data, such as a function or a class instance, or
it's more than 128 KiB in the page, the island falls back to CSR, with a
warning that names the component. That's the size once escaped for the
attribute, where each of devalue's many quotes takes six bytes. A static
island carries nothing, since it ships no code.

## Alternatives considered

### Give SWR the data as its `fallback`

SWR takes initial data per key as `fallback`, and an earlier version did just
that. But SWR reports a key whose data is only its fallback as loading until
it revalidates, so a component that renders a loading state from `isLoading`
rendered it in the browser where the server had rendered data. And data
already in SWR's cache, such as another island's for the same key, wins over
the fallback, though this island's markup was rendered from its own.

### Let SWR fetch each carried key again when the island mounts

SWR revalidates a key whose hook mounts with data, so the browser would make
the request it made without SSR. That keeps the data current when the page
came from a cache, but it repeats a request the server has just made, for
every hydrated island on every page view.

### Set `revalidateOnMount: false` for the whole island

An `SWRConfig` around the island could set it once, rather than a middleware
setting it per key. But it would apply to every key the island's components
use, so one the server didn't fetch would never be fetched on mount.

### Encode it as JSON

JSON is what the browser parses fastest, and needs no library. But data with
a `Date` or a reference cycle would cost a hydrated island its SSR, and a
JSON:API collection whose resources refer to each other is the common case.
An encoding of our own for cycles and dates would be more code to keep in
step on both sides than devalue is.

### Carry SWR's data alone

An `swr-fallback` attribute, read by the client renderer, would fix SWR. But
the next library to need it would add another attribute, another renderer
hook and another special case on both sides. Naming each library's part keeps
that to providing data on the server and a consumer in the browser.

### Fall back to CSR when a hydrated island fetches

Safe, and no new data on the page. But a hydrated component that fetches is
the common case for interactive code components, so SSR would do nothing for
most of them.

### Render the loading state on the server

The markup would match, but the first paint would have no data, which is what
SSR is for.

### Embed one block of data for the whole page

One `<script>` with every island's data would be smaller where islands share
keys. But an island would see what another fetched, which the [same-site
fetch](../specs/semi-detailed/06-same-site-fetch/spec.md) spec rules out, since
the other's cacheability doesn't cover it. Islands are also cached one by one,
so each must carry its own.

## Consequences

- The page carries each hydrated island's hydration data next to its markup.
  That can be many times the markup: a JSON:API collection carries every
  field of every resource it read, about 25 KB for five articles whose
  markup was under 200 bytes. A sparse fieldset, such as
  `fields[node--article]=title`, keeps it to what the component uses. Above
  128 KiB, the island falls back to CSR. Nothing limits what a page's islands
  carry together. The data is what the current user
  fetched, and the island is cached by what it fetched, so it adds no new
  exposure.
- Data devalue can't encode, like a class instance, costs a hydrated island
  its SSR.
- canvas_ssr needs a Canvas whose import map has
  `@drupal-canvas/astro-hydration/hydration-data`.
- A component that renders only from state its `onSuccess` sets shows that
  state empty in the server's markup, and fills in once the browser has
  fetched, as it does without SSR.
- The browser doesn't fetch a carried key when the island mounts, so it shows
  the data as the page was served until SWR next revalidates the key, such
  as on focus. A page served by Drupal has current data, as an island is
  cached by what it fetched. One served by a cache in front of Drupal, or by
  the browser's, can be as old as the page.
- `swr` in the browser now exports `SWRConfig` and `unstable_serialize` next to
  Canvas's `useSWR()`, which its consumer needs.
- Another library takes part by providing its data in its SSR shim and adding
  a consumer to the island client renderer. One whose state no library
  provides, like a fetch a component keeps itself, still mismatches.

Specs: [same-site fetch](../specs/semi-detailed/06-same-site-fetch/spec.md).
