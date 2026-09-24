# Multilingual sites with Canvas Headless

<!-- cspell:words contacto contactanos productos -->

This document outlines language negotiation and routing for multilingual sites with Canvas Headless.

## TL;DR

**Drupal-side domain negotiation is not yet supported** and requires the planned multi-backend support ([#3592072](https://git.drupalcode.org/project/canvas/-/work_items/3592072)). Otherwise, multilingual sites work today, with custom code or workarounds where needed.

- The headless frontend selects the language and passes it to Drupal using Drupal’s configured negotiation method.
- The SDK’s `fetchPage` function retrieves Drupal page content. Include the language in the requested URL as a path prefix or query parameter. Browser-language forwarding requires a workaround until custom request header support is added ([#3592073](https://git.drupalcode.org/project/canvas/-/work_items/3592073)).
- Drupal-generated links need adapting only when the frontend uses different domains, path prefixes, or aliases. Use outbound path processors or frontend rewriting.
- For frontend-only pages, maintain language-specific links in translated menus and content.

---

## Language negotiation

The headless frontend and Drupal negotiate language independently. The frontend selects a language using its own logic, such as a URL path prefix or domain, then sends requests in the format Drupal expects. For example, a frontend using `example.hu` can request `/hu/page/5` from Drupal when Drupal uses path-prefix negotiation.

Drupal core provides these language negotiation methods:

| Method                               | Example                          | Canvas Headless support                                                                                           |
| ------------------------------------ | -------------------------------- | ----------------------------------------------------------------------------------------------------------------- |
| URL path prefix                      | `example.com/hu/page/5`          | Supported                                                                                                         |
| URL domain                           | `example.hu/page/5`              | Not yet supported ([#3592072](https://git.drupalcode.org/project/canvas/-/work_items/3592072))                    |
| Request/session parameter            | `example.com/page/5?language=hu` | Supported via query parameter                                                                                     |
| Logged-in user’s language preference | User account setting             | Not applicable to normal page requests                                                                            |
| Browser’s language                   | `Accept-Language` request header | Requires custom fetch implementation ([#3592073](https://git.drupalcode.org/project/canvas/-/work_items/3592073)) |

When using these methods with Canvas Headless, keep the following in mind:

- Canvas Headless currently requires a single configured Drupal site URL, so Drupal-side domain-based negotiation is not supported. Planned support for **multiple Drupal backends for a single frontend** ([#3592072](https://git.drupalcode.org/project/canvas/-/work_items/3592072)) will enable this.
- Drupal’s logged-in user language preference is not applicable to normal Canvas Headless page requests, which are anonymous. The headless frontend must determine the visitor’s language instead.

### Passing the language to Drupal

Once the headless frontend has selected a language, include it in the `fetchPage` request using Drupal’s configured negotiation method.

These calls run server-side in the headless frontend. In the Next.js template, for example, `app/[[...slug]]/page.tsx` builds a path from the route parameters and passes it to `fetchPage` through a `getPage` helper:

```ts
const getPage = cache((path: string) => fetchPage(path));
```

Adapt the path passed to this helper to include Drupal’s language prefix or query parameter. If the language comes from the incoming request—for example, its domain—resolve it before calling `getPage`, so the language is included in the cache key.

#### URL path prefix

Include Drupal’s language prefix in the requested path:

```ts
await fetchPage("/hu/page/5");
```

The prefix must match Drupal’s configuration. In this example, `hu` is the language code for Hungarian, but Drupal can be configured to use a different URL prefix for that language.

#### Request/session parameter

Include the language in the requested query string:

```ts
await fetchPage("/page/5?language=hu");
```

Use Drupal’s configured parameter name (`language` by default). Send it with each request rather than relying on Drupal session persistence.

#### Browser language (workaround)

The framework helpers do not automatically forward the visitor’s `Accept-Language` header or accept custom headers. Support for custom request headers in the Headless SDK is tracked in [#3592073](https://git.drupalcode.org/project/canvas/-/work_items/3592073). Until then, use the lower-level SDK’s `fetchPage` with a custom fetch implementation:

```ts
import { fetchPage } from "@drupal-canvas/headless/server";

// Inside a server-side request handler; request is the incoming Request.
const acceptLanguage = request.headers.get("Accept-Language");

const fetchWithLanguage: typeof fetch = (input, init) => {
  const headers = new Headers(init?.headers);
  if (acceptLanguage) {
    headers.set("Accept-Language", acceptLanguage);
  }
  return fetch(input, { ...init, headers });
};

const page = await fetchPage("/page/5", {
  baseUrl: "https://drupal.example.com",
  fetchImpl: fetchWithLanguage,
});
```

Drupal must have browser-language negotiation enabled. Keep the custom fetch request-scoped so one visitor’s language cannot leak into another’s request.

This lower-level call does not automatically load the framework’s draft session. Preview integrations must also supply `draftData` to preserve draft access.

## Links and frontend routing

Links managed in Drupal need to point to the correct frontend destination, whether they reference Drupal content or pages that exist only in the headless frontend.

### Translation links

`fetchPage` returns `route.negotiatedLanguage` and `route.translations`. On multilingual sites, the list
includes **every enabled language**, matching `getPageData()` from `drupal-canvas`
(`mainEntity.translations`). It is empty on monolingual sites and routes without a canonical content entity.

Each entry supplies `langcode`, localized `name`, `nativeName`, `translationAvailable`, `current`, and `url`.
Availability is true only if the translation exists and the requester may view it; missing and denied
translations both report false. Authorized preview accounts can see different availability.
`current` follows the requested/negotiated language, not the rendered entity language.

For example, with English and Spanish translations and no French translation, requesting `/fr/page/1`
can render English. The response's `route` contains:

```json
{
  "name": "entity.canvas_page.canonical",
  "requestUri": "/fr/page/1",
  "params": { "canvas_page": "1" },
  "managedByCanvas": true,
  "entity": {
    "entityType": "canvas_page",
    "bundle": "canvas_page",
    "id": "1",
    "uuid": "11111111-1111-4111-8111-111111111111",
    "langcode": "en"
  },
  "negotiatedLanguage": "fr",
  "translations": [
    { "langcode": "en", "name": "English", "nativeName": "English", "url": "/contact", "translationAvailable": true, "current": false, "external": false },
    { "langcode": "fr", "name": "French", "nativeName": "Français", "url": "/fr/page/1", "translationAvailable": false, "current": true, "external": false },
    { "langcode": "es", "name": "Spanish", "nativeName": "Español", "url": "/es/contacto", "translationAvailable": true, "current": false, "external": false }
  ]
}
```

Unavailable entries use `getPageData()`'s fallback URL behavior: generate the supplied rendered
entity's canonical URL using the entry's language. A missing translation can resolve to Drupal's fallback;
**an unavailable URL is not guaranteed to be viewable**, particularly when a translation exists but access is denied.

For an available-only switcher, filter explicitly and use the supplied names:

```ts
const page = await fetchPage("/fr/page/1");
if (page && !("redirect" in page)) {
  const languageLinks = page.route.translations
    .filter((translation) => translation.translationAvailable)
    .map((translation) => ({
      label: translation.nativeName, // Or translation.name for localized names.
      active: translation.current,
      rendered: translation.langcode === page.route.entity?.langcode,
      href: toPublicUrl(translation.langcode, translation.url),
    }));
}
```

In this example, filtering removes the current French entry: neither remaining entry is `active`.
Use `rendered` instead to highlight English, the language identified by `route.entity.langcode`.
For an all-language switcher, omit the filter and use `translationAvailable` to label unavailable choices;
do not imply that every link retrieves viewable content.

`toPublicUrl` is application-owned mapping logic, not an SDK helper. For example, it can map the Spanish
entry `/es/contacto` to `https://example.es/contacto` or `/es-ES/contacto`. Use the original non-external
Drupal URI, not the mapped public URL, when calling `fetchPage` for that translation.

Translation URLs from `fetchPage()` use additional processing compared with those from `getPageData()`:
the Drupal installation base path is removed, language-switch options follow configured negotiation
priority, and query negotiation explicitly selects each language. For example, `/contact?language=en`
selects English regardless of prior browsing or Drupal session state. Preserve that query string.
Editor-only preview settings for view mode, component, page variant, and language are omitted;
ordinary language-selection parameters remain.

Headless entries also add `external`. An `external: true` entry retains an absolute URL, such as one
produced by Drupal domain negotiation. It is **not valid `fetchPage` input** and does not imply SDK
domain-negotiation support. Map it to a public URL instead. Structured translations do not generate
HTML `hreflang` links.

### Mapping Drupal URLs to frontend URLs

Even when Drupal owns the content and its translated aliases, the frontend may use a different URL structure. For example, a page with the English URL path alias `/contact` and the Spanish alias `/contacto` (“contact”) could require these mappings:

- **Language domains:** Drupal emits `/es/contacto`, but the frontend needs `https://example.es/contacto`.
- **Language and region prefixes:** Drupal emits `/es/contacto`, but the frontend uses a region-specific prefix and needs `/es-ES/contacto` (`es-ES` denotes Spanish as used in Spain).
- **Hosting subdirectory:** Drupal emits `/es/contacto`, but the frontend needs `/site/es/contacto`.
- **Different frontend aliases:** Drupal emits `/es/contacto`, but the frontend needs `/es/contactanos`.

Drupal does not automatically know how to map these URLs to the frontend’s public addresses.

If the frontend uses different aliases from Drupal, the rewriting logic also needs a mapping between Drupal’s content paths and their frontend equivalents. Domain or prefix transformations alone are not sufficient.

**Drupal-side processing:** These mappings can be implemented in Drupal with a **custom outbound path processor** to adapt Drupal-generated URLs to the frontend’s URL structure.

**Frontend workaround:** Alternatively, URLs can be rewritten in the headless frontend. The frontend can preprocess known URL fields in API responses, mapping Drupal paths to their frontend equivalents—for example, `/es/contacto` to `https://example.es/contacto`.

Links may also appear inside HTML content, such as a body field. These require HTML-aware processing to find and rewrite link destinations rather than updating a structured URL field. This is more involved, but can be handled with HTML-processing tools. For example, a [rehype](https://github.com/rehypejs/rehype) pipeline can parse HTML, rewrite link destinations, and serialize it before rendering. HTML fetched from Drupal needs this explicit processing step.

### Linking to frontend-owned routes

Some pages exist only in the headless frontend—for example, a product listing backed by Shopify. Drupal has no corresponding entity or route, but editors still need to link to these pages from menus or within content, such as body fields.

A manually entered path such as `/products` does not tell Drupal that the Spanish destination is `/es/productos`. Drupal cannot validate whether the frontend route still exists.

**Current workaround:** Editors can specify each language’s frontend destination explicitly in translated menu links, for example, `/products` in English and `/es/productos` in Spanish. For custom menu links created in Drupal, translation must be enabled for both the menu link content and its Link field to store a different destination per language. Links within translated body fields can likewise point directly to the appropriate frontend destination. Editors must keep these paths up to date when frontend routes change to prevent visitors from reaching a 404 page.

**Potential future improvement:** The Canvas Headless SDK could register frontend-only routes in Drupal as entity stubs or a similar routable representation. This would allow their URLs to pass through Drupal’s outbound path processing, as described above.

This approach would require maintaining a list of frontend-only routes in the frontend codebase. Sites with translated frontend paths may already have a language-to-path mapping that could provide some of this information.
