# 11. Page-data endpoint for external preview environments

Date: 2026-07-18

Issue: OpenSpec change `workbench-page-data` (canvas-specs repository)

## Status

Accepted

## Context

Code components can call `getPageData()` to read `pageTitle`, `breadcrumbs`, and `mainEntity` (with translation metadata) from `drupalSettings.canvasData.v0`. On a Drupal-rendered page this data is computed by `CodeComponentDataProvider` against the currently matched route and attached via `hook_js_settings_alter()`. External preview environments — starting with Workbench, the standalone dev-server preview app — render code components outside any Drupal page render, so there is no route-matched entity to derive this data from, and `getPageData()` only ever returns empty fallbacks there.

The existing `SiteDataController` (`/canvas/api/v0/site-data`) exposes only the route-independent subset (branding, base URL, theme assets, JSON:API settings). Page-level data is different: it must be computed for an entity named in the request, not for whatever route the API request itself matched.

## Decision

Add `GET /canvas/api/v0/page-data/{entity_type}/{entity}` handled by `PageDataController`, parallel to `SiteDataController`, both delegating field computation to `CodeComponentDataProvider`. The provider methods gain optional parameters for an explicit target instead of duplicating logic:

- `getCanvasDataMainEntityV0()` accepts an explicit entity, skipping its route-parameter scan. The controller passes the entity upcast by its own route.
- `getCanvasDataPageTitleV0()` accepts an explicit entity and returns `$entity->label()` instead of invoking the title resolver, since core's default entity canonical title resolves to the label anyway. Entity types with a custom `_title_callback` diverge; accepted until a concrete need arises.
- `getCanvasDataBreadcrumbsV0()` accepts an explicit route match plus a cacheability sink. The controller constructs a `RouteMatch` for the entity's canonical route (route parameter name equals the entity type ID for core-standard canonical routes). Because path-based breadcrumb builders (core's default) read the current path from `router.request_context` rather than the route match, the controller also points the request context's path info at the entity's canonical path for the duration of the build, restoring it afterwards. Breadcrumb-building failures degrade to an empty list rather than a 500.

Language selection reuses the existing `?canvas_preview_langcode` redirect subscriber (`CanvasRouteOptionsEventSubscriber::redirectCanvasApiToPreviewLanguage()`): the route is added to its allow-list, so the site's own negotiation chain — not new negotiation logic — resolves the requested translation, and the entity param converter upcasts the matching translation.

Access requires Canvas UI access plus `view` access to the target entity. Because external preview environments authenticate with OAuth Bearer tokens through `canvas_oauth`, the route is also added to `CanvasOauthAuthenticationProvider::applies()`'s named-route allow-list — `canvas_external_api: true` alone only shapes the route's `_auth` list, and a route missing from the provider allow-list rejects Bearer tokens outright. The response is a `CacheableJsonResponse` carrying the entity's cacheability, the `languages:language_content` context, the breadcrumb's own cacheable metadata, and the per-translation access-result cacheability — bubbled explicitly because a standalone JSON endpoint has no render pipeline to do it.

## Consequences

- Workbench (and any future external preview surface, e.g. headless frontends) can populate `drupalSettings.canvasData.v0`'s page-level fields per render with live site data, making `getPageData()`-dependent components (like language switchers) previewable outside Drupal.
- The preview render contract in Workbench replaces the page-level fields on every render — absent data resets them — so switching preview targets cannot leak a previous target's page data.
- `pageTitle` may diverge from the rendered page for entity types with custom title callbacks; breadcrumbs may degrade for entity types with non-standard canonical routes. Both are accepted, documented limitations of this iteration.
