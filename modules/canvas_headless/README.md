# Drupal Canvas Headless

Introduces first-class headless frontend app support to [Drupal Canvas](https://www.drupal.org/project/canvas):
the editor embeds your decoupled frontend app, and editors preview their work rendered by the app itself.

The module is experimental (`lifecycle: experimental`): while the Canvas Headless milestone is in progress, its APIs,
hooks, and configuration may change without a deprecation path.

## Requirements

- [Simple OAuth module](https://www.drupal.org/project/simple_oauth) (>=6.1.0), with its RSA keypair configured (see
  `/admin/config/people/simple_oauth`). The same keypair signs preview assertions; no additional keys are needed.
- The `custom_elements` module.
- A frontend app built on the Drupal Canvas Headless SDK. The SDK ships as the workspace package
  `@drupal-canvas/headless` (framework-agnostic core) plus one adapter per framework —
  `@drupal-canvas/headless-next` (Next.js), `@drupal-canvas/headless-astro` (Astro),
  `@drupal-canvas/headless-nuxt` (Nuxt), `@drupal-canvas/headless-tanstack-start` (TanStack Start),
  and `@drupal-canvas/headless-angular` (Angular) — with
  `@drupal-canvas/headless-react` as the shared React binding.

## Setup

1. Install the module. It provisions the OAuth consumer and scope it needs; there is nothing to create manually.
2. Grant `administer canvas headless frontends` to the roles that may manage the site-wide frontend list.
3. Grant `access canvas headless preview` to the editorial roles that should preview through a frontend app. The
   permission lets its holders mint preview credentials for themselves.
4. Open **Headless frontends** in Canvas, and add the frontend app URL, such as `http://localhost:3000`.

Opening an entity in the Canvas editor then loads the first frontend in the list with an active draft session.

In cloned environments, regenerate the Simple OAuth keypair per environment; with shared keys, preview credentials
minted on one clone would redeem on another.

## Canonical route previews

The module embeds the first configured frontend on canonical entity pages
rendered by a Canvas component tree or an enabled `full` content template,
whether the entity is published or not. Any selected page variant must also be
headless-compatible.

Embedding requires a logged-in user with `access canvas headless preview` and
access to the Drupal route. An unpublished entity embeds only for users whom
Drupal already allows to view it. Other visitors and unsupported routes keep
Drupal's normal rendering.

Previews render saved content, templates, and page variants with the current
user's permissions. A canonical route renders the saved default revision,
without Canvas auto-saves. Drupal's toolbar, administration navigation, and
status messages remain visible around the iframe.

The iframe replaces Drupal's rendered entity, including controls that modules
render as part of it, such as Content Moderation's state form. Change the
moderation state from the entity's edit form instead.

Custom frontend Content Security Policies must allow the Drupal origin in
`frame-ancestors`.

### Navigation

Eligible links open outside the iframe: Drupal-resolved paths use their Drupal
URL, while unresolved paths and external links use their original URL.
`target="_blank"` opens a new tab.

### Entity previews and revisions

Entities with enabled Canvas content templates can embed the frontend on their
`entity.{entity_type}.preview`, `entity.{entity_type}.revision`, and
`entity.{entity_type}.latest_version` routes. This includes node form previews,
core revision routes, and Content Moderation's latest-version routes. Embedding
requires the authenticated access described above and an enabled Canvas template
for the selected view mode. Other requests keep Drupal's normal rendering.

Canvas currently enables content templates only for nodes. Other entity types
require a module that adds content template support.

Previews render the selected entity revision or unsaved form values with saved
Canvas templates and page variants. Canvas auto-saves do not replace them. Preview
responses and their rendered content cannot be cached.

The frontend must serve preview and revision paths through its Drupal content
route handler. The SDK catch-all route can handle these paths.

### Building the browser code

Build browser assets with `npm run packages:build` from the repository root.

## Browser support

- Chromium-based browsers: works over HTTPS, and without HTTPS on a plain-http `localhost` dev server.
- Firefox: works over HTTPS; fails over plain http, and under "block all third-party cookies" unless the user adds
  a per-site exception for the Drupal site.
- Safari: follows CHIPS availability (unavailable in 18.5–26.1).

## Declaring preview-safe permissions

A preview token carries the editor's own permissions, capped to those declared safe for a read-only preview. The
module's baseline covers core content viewing; if your module defines view permissions that draft previews need,
declare them:

```php
function my_module_canvas_headless_safe_permissions(): array {
  return ['view my_module widgets'];
}
```

An undeclared permission means a preview shows too little, never too much. See `canvas_headless.api.php` for the
hook documentation, including the site-policy `_alter` hook.

## Canvas content endpoint

`GET /canvas/content-api?requestUri={requestUri}` accepts a site-relative Drupal request URI. Query strings
are supported; fragments are rejected. File URLs in content responses are absolute so they resolve from the
headless frontend rather than from the Drupal origin implicitly.

### Read-only preview language

Canvas's language selector passes the selected language through the embedded
read-only draft session for pages, content templates, and page templates. The
SDK's `fetchPage()` forwards it only while the draft session is live. It does
not change the editable editor iframe or add a public frontend language option.

The content endpoint honors this `language` hint only for preview-scoped tokens.
When negotiation must change, it sends one private, non-cacheable HTTP 302 back
to the same-origin content endpoint with the language-specific `requestUri` and
preview context. `fetchPage()` follows this transport redirect with the same
credential; it is not a frontend navigation result. A fresh request uses the
site's language-switch URL/query negotiation before routing and rendering,
including translation access checks, per-language page auto-saves, and template
translation overrides merged onto draft trees. If negotiation still does not
match after that hop, the endpoint returns 404 rather than redirecting again. A configured language
without a translation retains Drupal's fallback; an unknown language returns 400
when minting an assertion and 404 from the content endpoint.

As with coupled previews, interface and content negotiation are expected to agree.
Cross-domain language negotiation is not supported for this internal preview hint.

## Canvas entity endpoint

`GET /canvas/content-api/entity?type={entityType}&id={id}` renders one
content entity directly. Pass `viewMode={viewMode}` to render a specific
content-template view mode, for example
`/canvas/content-api/entity?type=node&id=1&viewMode=teaser`.

### Entity response

```text
{
  "content": {...},
  "managedByCanvas": true,
  "entity": {
    "entityType": "node",
    "bundle": "article",
    "id": "1",
    "uuid": "773942c6-3660-4c50-9a8d-e25966a69bff",
    "langcode": "en"
  }
}
```

`content` is one structured root or `null`. `managedByCanvas` is `false` when
Canvas does not manage the requested entity and view mode.

### Content response

This example requests French, but renders English because the French translation is unavailable.

```text
{
  "content": {...},
  "head": {
    "title": "Example page",
    "meta": [
      {
        "name": "description",
        "content": "Example description"
      },
      {
        "property": "og:title",
        "content": "Example page"
      }
    ],
    "script": [
      {
        "type": "application/ld+json",
        "textContent": {
          "@context": "https://schema.org",
          "@type": "WebPage",
          "name": "Example page"
        }
      }
    ]
  },
  "route": {
    "name": "entity.canvas_page.canonical",
    "requestUri": "/fr/page/1",
    "params": {
      "canvas_page": "1"
    },
    "managedByCanvas": true,
    "negotiatedLanguage": "fr",
    "translations": [
      { "langcode": "en", "name": "English", "nativeName": "English", "url": "/contact", "translationAvailable": true, "current": false, "external": false },
      { "langcode": "fr", "name": "French", "nativeName": "Français", "url": "/fr/page/1", "translationAvailable": false, "current": true, "external": false },
      { "langcode": "es", "name": "Spanish", "nativeName": "Español", "url": "/es/contact", "translationAvailable": true, "current": false, "external": false }
    ],
    "entity": {
      "entityType": "canvas_page",
      "bundle": "canvas_page",
      "id": "1",
      "uuid": "773942c6-3660-4c50-9a8d-e25966a69bff",
      "langcode": "en"
    }
  },
  "context": {
    "page": {...},
    "site": {...},
    "translations": {
      "strings": {
        "": {
          "Read more": "Lire la suite"
        }
      }
    }
  }
}
```

`content` is one structured root or `null`. Multiple roots use a transparent `renderless-container` with the ordered
roots in its `default` slot. Routes Canvas does not manage and managed routes with empty trees both use `content: null`.
`route.managedByCanvas` distinguishes them and remains `true` for an empty managed tree.

`context` is the page and site context that Code Components read. `context.translations` holds the interface
translations of the strings that the rendered code components translate, in the request's interface language, keyed
by translation context (an empty string when none) and source string. It is absent when none of them is translated,
and includes `pluralFormula` when a plural string is translated and a formula is available.

`head` is compatible with the [Unhead](https://unhead.unjs.io/) package. It always contains `title` and may also
contain `meta`, `link`, and `script`. Canonical links are omitted because the frontend owns its public URLs.

`route.negotiatedLanguage` is the negotiated content-language ID. `route.translations` lists every enabled
language, matching Code Components' `getPageData().mainEntity.translations`: `langcode`, localized `name`,
`nativeName`, `url`, `translationAvailable`, and `current`. Missing and denied translations both report
`translationAvailable: false`, with the established Code Component fallback URL semantics, not a guarantee
of access. `current` follows `route.negotiatedLanguage`; `route.entity.langcode` identifies the rendered
language. Monolingual sites and routes without a canonical content entity return an empty list.

A non-external `url` is a site-relative Drupal request URI, with the installation base path removed and the
language prefix or query preserved. External URLs remain absolute and are **not valid `fetchPage` input**;
this does not add SDK support for domain negotiation. Frontends map entries to their own public URLs.
The headless-only `external` flag and URL processing support Drupal request URIs: configured negotiation
priority and explicit query-language selection are preserved, while editor-only preview settings are omitted.
See [multilingual examples](../../docs/user/src/content/docs/headless/multilingual-sites.mdx#translation-links).

### Redirect response

```json
{
  "redirect": {
    "external": false,
    "url": "/new-path",
    "statusCode": 301
  }
}
```

Redirect results use HTTP 200; `statusCode` is the status the frontend should use for the browser redirect.

### Error response

Errors use RFC 9457 Problem Details and the `application/problem+json` media type:

```json
{
  "type": "about:blank",
  "title": "Bad Request",
  "status": 400,
  "detail": "The requestUri query parameter must be a site-relative URI without a fragment."
}
```

`detail` is included when an additional explanation is available.

## Frontend cache invalidation

Successful content, entity, and redirect responses, and content API client
errors (4xx), include `cacheTags` and `cacheMaxAge`: the response's Drupal cache
tags and max-age (-1: no limit), including the dependencies of its cache
contexts. Frontends cache by path, so they would otherwise miss, for example,
a change to the anonymous role's permissions. Frontends tag their cached copies
with the tags and keep them for at most the max-age.

Cache invalidation is a switch per frontend, and it is off by default. Open
**Headless frontends** in Canvas and turn on **Cache invalidation** in the
frontend's row. The switch needs the `administer canvas headless frontends`
permission. It sets `cache_invalidation` on the frontend's entry in
`canvas_headless.settings`, for example `frontends.0.cache_invalidation`.
Drupal never announces to a frontend whose switch is off. An administrator can
still test it; see below.

The frontend serves the invalidation route at `/api/canvas/revalidate` under
its registered URL. The path is fixed. For the frontend
`https://www.example.com`, Drupal sends announcements to
`https://www.example.com/api/canvas/revalidate`. See
`createCacheInvalidationHandler()` in `@drupal-canvas/headless` and the adapter
READMEs.

Drupal collects the cache tags invalidated during a request. After the
response, it stores them for five minutes under a random ID per frontend, and
sends `POST {"id": "..."}` to each frontend's route. The frontend reads the
tags with `POST /canvas-headless/cache-invalidation` and the same JSON body,
then expires its cached copies. An ID can be read once. No secret is shared:
the ID is unguessable, single-use and expires, and the tags come from Drupal.

A cache rebuild (`drush cache:rebuild`), a router rebuild, or a request
invalidating more than 1,000 tags announces `http_response` instead, which every
response carries: frontends expire everything. Redirects are not followed;
requests time out after five seconds. The Next.js adapter answers at once and
reads the tags afterwards, so an announcement holds a PHP worker only briefly.

### Test, Expire all, and status

A frontend's row shows two actions and a status while its switch is on:

- **Test** sends one announcement and waits up to five seconds for the frontend
  to read it. The announced tag is `canvas_headless:test`, which no response
  carries, so nothing expires. A frontend that missed an invalidation is sent
  `http_response` instead. Turning the switch on runs the test once. Tags
  invalidated while the switch was off were not announced, so that first
  announcement is `http_response`: the frontend expires everything once.
- **Expire all** does the same with `http_response`: the frontend expires
  everything it cached.
- The status is **Waiting for changes** until the frontend reads its first
  announcement, then **Active**. It is **Failing** from a failed announcement
  until the frontend reads an `http_response` announcement made after it, and
  shows the last error and its time. A test the frontend does not read is a
  failure at once.

Both actions show a message with the result; see
[Troubleshooting](#troubleshooting). The site's status report shows a warning
while a frontend is failing. The warning lists each failing frontend with its
last error and time. The status is only returned to users with the
`administer canvas headless frontends` permission: errors can name hosts of
the site's network.

### Missed announcements

An announcement fails when the frontend cannot be reached, or answers with a
redirect or an error. It also fails when the frontend does not read the tags
within 30 seconds. Drupal detects that at the next announcement or cron run. A
failed request is logged to the `canvas_headless` channel.

Drupal records that the frontend may have missed an invalidation. The
frontend's next announcement, from the next request that invalidates tags or
from cron, is `http_response`. The frontend then expires everything it may have
missed. The record is cleared when the frontend reads that announcement.

After a failure, Drupal waits before it contacts the frontend again. The wait
is 10 seconds and doubles with each consecutive failure, up to five minutes.
For an announcement that was not read, the wait starts when the 30 seconds
ran out. A miss noticed after the wait is repaired by the announcement that
notices it; otherwise by the first announcement or cron run after the wait. Tags invalidated during the wait are not announced: the later
`http_response` covers them. Test and Expire all ignore the wait. The wait ends
when the frontend reads an announcement.

The flow, failure behavior, and per-framework support are described in
[docs/headless-cache-invalidation.md](docs/headless-cache-invalidation.md).

### Troubleshooting

Test and Expire all answer with one of these messages:

- `The frontend received the announcement and read the cache tags.` Cache
  invalidation works.
- `The frontend answered HTTP 404.` The route is not served at
  `/api/canvas/revalidate` under the registered frontend URL. Mount the route,
  or correct the registered URL, including any path prefix. A route that reads
  the tags before it answers also gives 404 when `CANVAS_SITE_URL` points at
  another Drupal site.
- `The frontend answered HTTP 301.` (or another 3xx code) Drupal does not
  follow redirects. Register the URL the redirect points to, for example with
  `https` or `www`.
- `The frontend answered HTTP 405.` The path does not accept `POST`. Mount the
  route for the `POST` method.
- `The frontend answered HTTP 500.` The route threw. `CANVAS_SITE_URL` is not
  set on the frontend, or the application's `invalidate` callback failed. Read
  the frontend's log.
- `The frontend answered HTTP 502.` The frontend could not read the tags from
  Drupal. Check that `CANVAS_SITE_URL` on the frontend points at this site, and
  that the frontend's server can reach it.
- `The frontend could not be reached: …` Drupal's server could not connect to
  the frontend, or got no answer within five seconds. Check the registered URL,
  DNS and firewall rules. A `localhost` URL only works when the frontend runs
  on the Drupal server.
- `The frontend accepted the announcement but did not read the cache tags from
  Drupal. Check that CANVAS_SITE_URL on the frontend points at this site.` The
  route answered before it read the tags, and the read failed or never
  happened. Check `CANVAS_SITE_URL`, and that the frontend's server can reach
  this site. Also check that the path is served by the invalidation route, not
  by a page that answers every request.

The **Failing** status shows the same errors without the leading words, for
example `HTTP 404`. `The frontend did not read the announced cache tags.` has
the same causes as the last message above.

### Cache invalidation limitations

- Every content API response depends on `route_match`, `config:core.extension`
  and, with the Redirect module, `redirect_list`. Saving a path alias or a
  redirect, or rebuilding the router, therefore expires every cached page.
- If a reverse proxy or CDN caches the content API in front of Drupal, the
  frontend can refetch its stale copy before that cache is purged, and keep
  it. Exclude the content API from that cache, or purge it before Drupal
  answers.
- Drupal announces once per request, at the end. A long drush command or
  migration announces when it finishes, usually `http_response`.
- `drush cache:rebuild` waits for the announcements, which are sent in
  parallel: up to two seconds when a frontend cannot be reached, and up to
  five seconds when one is slow to answer. A frontend in its wait after a
  failure is not contacted.
- A missed invalidation is repaired by the next request that invalidates tags,
  or by cron, once the wait after the failure has passed. The frontend serves
  the stale pages until then, so run cron often.
- An ID is removed when the frontend reads it. If the frontend answered the
  announcement before reading, and the answer to its read is then lost, for
  example by a timeout, neither side records a miss. The tags are not expired
  until they are invalidated again.
- A forged announcement makes the frontend send Drupal one request, which
  Drupal answers with 404. It expires nothing, also when the frontend answers
  before it reads the tags, or cannot reach Drupal.
- The announcement path is fixed. A frontend cannot receive announcements at
  another path, or on another host than its registered URL.
- Next.js drops tags longer than 256 characters.

## Known limitations

- Canonical routes render the default revision. Use revision or latest-version
  paths with `fetchPage()` to render a published entity's forward revision.
- Core JSON:API filtered collections exclude unpublished content regardless of permissions; the example app avoids
  filtered collection queries for draft content.
- Content gated by a view permission not declared preview-safe is invisible in previews until the owning module
  declares it.
- Editors need view access to the entity they preview, not only edit access; without it the preview fails to start.
- The first URL in the site-wide frontend list is used for previews; reorder the list to change the active app.
  Enabling the module replaces the Drupal-rendered preview for every entity editing context. An entity without a
  canonical URL, or one the active app does not serve, shows a preview-start failure.

## Further reading

- How headless cache invalidation works, its failure behavior, and framework support:
  [docs/headless-cache-invalidation.md](docs/headless-cache-invalidation.md) and
  [ADR-0022](../../docs/adr/0022-announced-cache-tag-invalidation-for-headless-frontends.md).
- A concept-level walkthrough of the auth design — OAuth roles, JWT anatomy, the validation chain, the RFC map:
  [docs/headless-preview-auth.md](docs/headless-preview-auth.md).
- The architectural decisions and their alternatives:
  ADRs [0014](../../docs/adr/0014-headless-draft-preview-user-bound-tokens-via-jwt-assertion-grant.md) (user-bound
  tokens via the assertion grant),
  [0015](../../docs/adr/0015-headless-draft-preview-session-renewal-re-anchored-in-drupal-session.md) (session
  renewal), and
  [0016](../../docs/adr/0016-headless-draft-preview-embedded-draft-state-in-partitioned-cookies.md) (embedded
  cookie transport, including the full browser matrix).
