# 18. Use a Canvas-owned endpoint for routed headless content

Date: 2026-08-03

Issue: <https://git.drupalcode.org/project/canvas/-/work_items/3591861>

## Status

Accepted

## Context

Canvas headless frontends need Drupal to resolve each requested path and return the content and page data for the
matched route. Drupal must still handle aliases, route parameters, redirects, and access checks. The response must
give the headless SDK everything it needs to show the page: a component tree managed by Canvas, page metadata, and
route and entity details.

The first prototype of the Canvas headless SDK used the Lupus Decoupled CE API to fetch routed content. Lupus provides
a general-purpose `/ce-api/{path}` endpoint for rendering Drupal routes through Custom Elements. It supports many
kinds of Drupal pages, can return JSON or markup, and includes fields and hooks that are specific to Lupus.

Canvas needs a smaller and more focused API response. It must only return content when it manages the whole component
tree. If Custom Elements built its default tree for other content, that tree might not match the components known to
the headless SDK. The API also needs clear route and entity details, page metadata that works with any frontend
framework, and draft previews that use Canvas entity and content-template auto-save data.

Using the Lupus response as the public Canvas API would make the project depend on choices made for a different use
case. Adding Canvas-only fields, rendering rules, preview behavior, and error responses to Lupus would make its
general-purpose endpoint depend on Canvas. The new endpoint can instead keep using Custom Elements to turn rendered
output into JSON while owning the response used by the SDK.

The two APIs differ as follows:

| Behavior | Lupus `/ce-api/{path}` | Canvas `/canvas/content-api?requestUri={requestUri}` |
| --- | --- | --- |
| Ownership | Provided by `lupus_decoupled_ce_api` and `lupus_ce_renderer` | Provided by Canvas Headless using Custom Elements |
| Request mapping | Removes the `/ce-api` prefix and routes the remaining path through Drupal | Requires `requestUri` to be a path inside the Drupal site with no `#fragment`, then routes it through Drupal |
| Supported routes | Supports Drupal routes that Custom Elements can render | Routes accessible Drupal paths, but returns content only for canonical content entities with a complete Canvas tree or an enabled `full` content template |
| Unsupported routes | Returns a Custom Elements error page, normally with status 406; admin routes may redirect to Drupal | Returns `content: null` with available head and route data when Canvas cannot render the matched route; route and access failures use RFC 9457 Problem Details |
| Content formats | Supports Custom Elements as JSON or markup | Always returns the Custom Elements JSON shape, even when the site's global Custom Elements setting uses another format |
| Content roots | Canonical entity routes return one entity element; other routes may return a list when a top-level `renderless-container` is removed | Returns one element or `null`; multiple roots stay inside a transparent `renderless-container` wrapper |
| Content response | Returns `title`, `breadcrumbs`, `metatags`, `content_format`, `content`, `page_layout`, `local_tasks`, and `messages` | Returns `content`, `head`, and `route` |
| Document metadata | Returns the title and Metatag module data in Lupus-specific response fields | Returns title, meta, non-canonical, non-stylesheet links, and JSON-LD entries in a head object compatible with the [Unhead](https://unhead.unjs.io/) library |
| Route information | Does not include route or entity details in a standard shape | Returns the route name, requested URI, route parameters, and rendered entity details when available |
| Redirects | Returns `redirect` and optional Drupal messages as JSON | Returns the redirect URL, original status code, and external URL flag as JSON |
| Error format | Renders Drupal error routes through the Custom Elements renderer | Returns `application/problem+json` responses |
| Partial responses | Supports `_select=content` | Returns the complete endpoint response, support for include param will be added later |
| Response identification | Adds `X-Drupal-CE: page` or `X-Drupal-CE: redirect` | Uses standard JSON and Problem Details response types |
| Draft previews | By default doesn't support Canvas auto-save data | Selects entity and content-template auto-save data for preview requests and checks entity access again after loading it |
| Extension points | Supports response overrides and `hook_lupus_ce_renderer_response_alter()` | Provides a focused API response owned by Canvas |

## Decision

Canvas Headless will provide `GET /canvas/content-api?requestUri={requestUri}` as its public routed-content endpoint.
The endpoint will check the supplied path and route it through Drupal so aliases, languages, route parameters,
redirects, and access checks keep their normal behavior.

A successful response will contain `content`, `head`, and `route`:

- `content` will contain Custom Elements JSON only when Canvas owns the complete rendered output through a
  component-tree entity or an enabled `full` content template.
- `content` will be `null` for other matched routes. A canonical content entity can still contribute its entity
  details and page metadata; a route without an entity can still contribute its title.
- `head` will use a shape that works with Unhead and other frontend frameworks. The frontend owns its public canonical
  URL, so the response will not include canonical links.
- `route` will describe the routed request and its canonical entity when one exists.

Output with more than one root will keep a transparent `renderless-container` root instead of returning a list.
Redirects will use JSON and include the original redirect status. Route and access failures will use RFC 9457 Problem
Details.

Preview requests will use available Canvas entity and content-template auto-save data before rendering. Other requests
will use stored content.

Custom Elements will still turn Canvas render arrays into JSON. The endpoint will not use the Lupus `/ce-api`
response format or expose Lupus-specific fields, headers, and hooks.

## Alternatives considered

- **Use the Lupus `/ce-api/{path}` response directly.** Rejected because its larger page response, choice of output
  formats, supported content, and hooks do not match the stable input needed by the headless SDK.
- **Add Canvas-specific behavior to the Lupus endpoint.** Rejected because preview state, route details, and
  component-tree ownership are project-specific rules. Adding them to a general-purpose renderer would make each
  project depend on the other's releases and API changes.
- **Use JSON:API as the routed-content endpoint.** Rejected because JSON:API returns entity data instead of a complete
  component tree. Frontends would need to repeat Canvas rendering choices and build a page from several requests.

## Consequences

- Canvas controls the public API response used by its headless SDK and can change both together.
- Frontends receive one predictable JSON shape, no matter which site-wide Custom Elements format is
  selected.
- Content that Canvas does not manage cannot accidentally become a component tree that the SDK cannot render. Its
  route, title, or entity metadata can still help the frontend.
- Canvas previews can load auto-save data without adding those concepts to the Lupus API.
- The routed-content endpoint does not require a frontend to understand Lupus-specific response fields, headers, or
  hooks.
- Canvas must maintain its own URI checks, Drupal routing, redirects, Problem Details, cache data, access checks, and
  tests.
- Sites may expose both endpoints for different frontends. Their response formats and supported content will stay
  different on purpose.
