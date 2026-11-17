# 6. `fetch()` is a subrequest: allowlisted GET and HEAD, as the current user

Status: Accepted

## Context

In the browser, Canvas [components](../GLOSSARY.md#component) fetch from the
site. They use JSON:API through the `drupal-canvas` client, and menu linksets.
On the server, something has to answer those same calls, and the code making
them was written by the site's editors.

## Decision

`fetch()` during SSR is a [same-site fetch](../GLOSSARY.md#same-site-fetch).
Drupal answers it with a subrequest, in the same PHP process.

- **Same site, allowed paths only.** The URL has the site's scheme, host and
  port. A [path policy](../GLOSSARY.md#path-policy) has to allow the path. Two
  policies ship: JSON:API, when that Drupal module is enabled, and menu
  linksets. The path goes through Drupal's inbound path processors first, so a
  language prefix or an alias doesn't hide an allowed path. The subrequest still
  goes to the original URL, so language negotiation sees the prefix. Drupal
  negotiates each language once for a PHP request, so negotiation is reset
  before the subrequest and after it. Otherwise the subrequest would get the
  page's language, or the rest of the page the subrequest's. A URL without a
  prefix may leave the language to the visitor's browser, account or session.
  The response then varies by that, and so does the island.
- **GET and HEAD only.**
- **As the current user.** The subrequest carries the request's cookies and
  session. It also carries the component's own headers, so `Accept-Language`
  gets the translation the browser would.
- **Cacheability joins the [render](../GLOSSARY.md#render)'s.** So editing
  fetched content invalidates the [island](../GLOSSARY.md#island). It also joins
  that of every later island in the request that has one of the component
  modules whose code made the fetch. A module evaluates once in a realm, so
  those islands read what the module kept without fetching it. Whose code made a
  fetch is read from its call stack: the module that called `fetch()`, and any
  whose function called into that one. A stack that names none of the island's
  component modules, as when a library makes the call after awaiting something,
  counts as naming all of them. Some libraries fetch for the components that
  import them, when none of those is on the stack: `swr` calls its fetcher after
  the component returned, and the JSON:API client awaits before it fetches. So
  when the stack names one of those libraries' modules, or any component, the
  fetch also counts for the island's components that import one. `drupal-canvas`
  exports the client beside much else, so a component that imports it counts
  only if its source mentions `JsonApiClient`. A component may import one
  through other modules the import map names, as a Canvas CLI codebase's own
  `@/lib` modules are once pushed. Those count as importing it too. What went
  wrong with a fetch is taken on the same way.

Anything else is refused. The next ADR covers what that means for the island.

## Alternatives considered

### Loopback HTTP to the site

That's a second request through the web server. The
[runner](../GLOSSARY.md#runner) would need network access, the visitor's
cookies would travel over a socket, and the response's cache metadata would get
lost at the HTTP boundary. A subrequest avoids all of that.

### Fetch as anonymous

One render could then serve everyone. But a logged-in visitor would see the
wrong data, different from what the browser fetches for them. Matching the
browser matters more. The cost is cacheability that varies by user, which
bubbles up like any other.

### Allow every HTTP method

SSR only renders a component's first frame. It's not clear why a component would
POST, PATCH or DELETE in its first frame. Allowing it would also let a render
have side effects, even though renders get repeated and cached. We can revisit
this if a use case turns up.

### No allowlist

Then editor code could reach every route on the site, with the server's own
privileges.

## Consequences

- A fetched entity's cache tags invalidate the island. A response that varies
  per user makes the island vary per user. Both are correct, if not free.
- Fetches run one at a time. `fetch()` is a synchronous binding ([ADR
  5](0005-bindings-are-synchronous-and-carry-strings.md)), and PHP answers
  each call before the next starts. So a component that reads several
  endpoints waits for the sum of them, where the browser waits for the
  slowest. A subrequest stays in the PHP process, without a network round
  trip, which keeps each one cheap.
- An island is invalidated by what the code of its component modules
  fetched earlier in the request, whether or not a module kept it. Islands
  that only share a component that fetches nothing, such as a button, don't
  affect each other.
- The call stack says who fetched, not who kept. These aren't covered, and
  an island that reads what was kept carries none of its cacheability:
  - A component that keeps what its importer fetched and handed to it. That
    includes a shared module whose store one component writes what it
    fetched into, for another to read.
  - A module that keeps what a function it was handed fetched later, after
    an `await`, unless it imports one of those libraries, as a component
    that calls the function through `useSWR()` does.
  - A component that is handed a JSON:API client, by props or context,
    and keeps what it got through it, without importing the client.
- The libraries that fetch for their importers are a fixed list. Another
  library that awaits before it calls `fetch()` leaves nothing on the stack
  to tell whose fetch it is. So its fetches count for every module of the
  island, and islands that share a button with it still affect each other.
- A fetch through a listed library, or by a component, counts for every
  component in the island that imports a listed library, not only the one that
  asked for it. A later island that takes on a refused fetch that way falls back
  to CSR without being rendered, and the warning names the component whose
  island made the fetch. Its fallback has the cache tags of what that island
  depends on, so it ends when that component is fixed.
- An island that takes on what went wrong with an earlier island's fetch is
  cached for an hour at most. The cause may have been the earlier island's
  own, like a URL from one of its props. Fixing the prop saves the earlier
  island's host, which invalidates that island, but the later one may be
  cached apart from that host, as one in a page region is from one in a
  node. An island doesn't know its host, so it can't carry the host's tag. A
  tag invalidated on every entity save would rebuild every page with such a
  fallback after any save. So the fallback only expires. The page isn't made
  to expire with it, as it is for a transient error: an hour of CSR isn't
  worth rebuilding pages for.
- An island that takes on what an earlier island's fetch got, when it worked,
  has the tags of what was fetched, but nothing of the earlier island's. If that
  island's props or code chose what was fetched, as when a shared component
  module fetches the node its importer asks for and keeps it, a change to them
  doesn't invalidate the later island. That's the isolation gap ADR 11 lists for
  state in a shared module, and a fetch doesn't widen it: a value one island
  stores in a library's exported `Map` reaches the next the same way. Unlike a
  taken-on failure, which always costs the later island its markup, a taken-on
  success is rarely stale, and capping it would expire every island that shares
  a module that fetches.
- A library that keeps what it fetched isn't covered. An island of another
  component that reads it from the library carries none of its cacheability.
  So a library shouldn't keep what it fetches, in a cache of its own or
  otherwise, past the call that fetched it. One that keeps it only in a
  store the component hands it is fine, since the store is the component's.
  `swr` is the exception: SSR replaces it with a shim that fetches again for
  every island.
- A library shouldn't call `fetch()` as it evaluates either. The fetch would
  be made for whichever island imports the library first, or fail where a
  runner evaluates libraries ahead of any render, and later islands would
  read the result without its cacheability. It should fetch from a function
  that a component calls.
- Allowing a new endpoint takes one tagged policy, not a change to `fetch()`.
- `canvas_ssr` doesn't depend on `jsonapi`. Without it, JSON:API fetches are
  refused instead of returning 404.
- A component that fetches from another host, or with another method, falls back
  to [CSR](../GLOSSARY.md#csr-fallback) on the server. It then fetches in the
  browser as before.

Specs: [same-site fetch](../specs/semi-detailed/06-same-site-fetch/spec.md).
