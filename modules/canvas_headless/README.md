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
  `@drupal-canvas/headless-nuxt` (Nuxt), and `@drupal-canvas/headless-tanstack-start` (TanStack Start) — with
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

### Content response

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
    "requestUri": "/page/1",
    "params": {
      "canvas_page": "1"
    },
    "managedByCanvas": true,
    "entity": {
      "entityType": "canvas_page",
      "bundle": "canvas_page",
      "id": "1",
      "uuid": "773942c6-3660-4c50-9a8d-e25966a69bff",
      "langcode": "en"
    }
  }
}
```

`content` is one structured root or `null`. Multiple roots use a transparent `renderless-container` with the ordered
roots in its `default` slot. Routes Canvas does not manage and managed routes with empty trees both use `content: null`.
`route.managedByCanvas` distinguishes them and remains `true` for an empty managed tree.

`head` is compatible with the [Unhead](https://unhead.unjs.io/) package. It always contains `title` and may also
contain `meta`, `link`, and `script`. Canonical links are omitted because the frontend owns its public URLs.

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

## Static site generation

A site whose public content is published and anonymous-readable can be built to
static files and served from a Node-free host, while the editor keeps its
request-time preview. The route inventory below is generic enough for any
client that walks the site (sitemap generators, cache warmers, search
indexers), not only static builds.

### Route inventory

`GET /canvas/api/v0/headless/inventory` lists the site-relative paths Canvas
renders: published Canvas pages plus published content entities whose bundle
has an enabled full-view content template, one entry per published translation,
with the front page emitted as an extra `/` entry. Each entry carries the
canonical path, entity type, id, uuid, langcode, and last-changed timestamp.
The endpoint is public, but results reflect the requesting account's entity
access, so an anonymous request sees exactly the anonymous view. Pagination is
a keyset cursor: pass `cursor.next` from a response back as `cursor` until it is
null (`limit` caps a page at 100). The SDK's `fetchRouteInventory()` and
`fetchStaticPaths()` helpers walk it to completion for `getStaticPaths`,
`generateStaticParams`, and `nitro.prerender.routes`.

Keeping a static site fresh after content changes (per-page cacheability
exposure and a publish webhook) is tracked separately as the content
invalidation primitives.

### Two modes in one codebase

Editor preview is inherently request-time and cannot be served from static
files. Next.js can serve both from one deployment (its draft-mode bypass
switches editors to request-time rendering on prerendered routes); Astro and
Nuxt use a second, server-rendered deployment for the editor, with the frontend
list pointing at that deployment while public DNS points at the static
artifact. Media is serialized as absolute URLs on the Drupal origin, so a
static artifact hot-links the backend for files and must be built against the
public site URL. See the adapter READMEs for per-framework build configuration.

## Known limitations

- The rendered-content endpoint serves the default revision: an unpublished entity previews fully, but a published entity's forward
  revision appears only in JSON:API-driven listings (the SDK hydrates working copies), not on pages rendered
  through `fetchPage()`.
- Core JSON:API filtered collections exclude unpublished content regardless of permissions; the example app avoids
  filtered collection queries for draft content.
- Content gated by a view permission not declared preview-safe is invisible in previews until the owning module
  declares it.
- Editors need view access to the entity they preview, not only edit access; without it the preview fails to start.
- The first URL in the site-wide frontend list is used for previews; reorder the list to change the active app.
  Enabling the module replaces the Drupal-rendered preview for every entity editing context. An entity without a
  canonical URL, or one the active app does not serve, shows a preview-start failure.

## Further reading

- A concept-level walkthrough of the auth design — OAuth roles, JWT anatomy, the validation chain, the RFC map:
  [docs/headless-preview-auth.md](docs/headless-preview-auth.md).
- The architectural decisions and their alternatives:
  ADRs [0014](../../docs/adr/0014-headless-draft-preview-user-bound-tokens-via-jwt-assertion-grant.md) (user-bound
  tokens via the assertion grant),
  [0015](../../docs/adr/0015-headless-draft-preview-session-renewal-re-anchored-in-drupal-session.md) (session
  renewal), and
  [0016](../../docs/adr/0016-headless-draft-preview-embedded-draft-state-in-partitioned-cookies.md) (embedded
  cookie transport, including the full browser matrix).
